<?php
// api/index.php - Enrutador Principal RESTful con Inyección de Dependencias (Versión Enterprise 2.5)

// 1. Inicialización Estricta de Middleware CORS
if (file_exists(__DIR__ . '/../includes/cors_middleware.php')) {
    require_once __DIR__ . '/../includes/cors_middleware.php';
} else {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// 2. Inicialización de Sesión Única Institucional (SessionManager OWASP)
if (file_exists(__DIR__ . '/../config/session_config.php')) {
    require_once __DIR__ . '/../config/session_config.php';
    if (class_exists('SessionManager')) {
        try {
            SessionManager::start();
        } catch (\Throwable $e) {}
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Liberar bloqueo de archivo de sesión para peticiones GET concurrentes en React (elimina el retardo de 3s)
if (session_status() === PHP_SESSION_ACTIVE && $_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
}

require_once __DIR__ . '/../config/database/database.php';
require_once __DIR__ . '/core/Controller.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$parsedUrl = parse_url($requestUri, PHP_URL_PATH);
$rawPath = trim((string) $parsedUrl, '/');

// Normalizar la ruta eliminando prefijos de subdirectorio (ej. 'contable_react/')
if (preg_match('#(?:^|/)api/(.+)$#i', $rawPath, $matches)) {
    $path = 'api/' . $matches[1];
} else {
    $path = $rawPath;
}

$method = $_SERVER['REQUEST_METHOD'];

// 1. ENDPOINTS DE SEEDERS / RECONSTRUCCIÓN HISTÓRICA CON ARRASTRE DE CONTINUIDAD PATRIMONIAL
if ($path === 'api/seeder/reconstruir-saldos') {
    try {
        $db = \getConnection();

        // 1. Asegurar estructura de la tabla materializada saldos_cuentas_mensuales
        $db->exec("
            CREATE TABLE IF NOT EXISTS `saldos_cuentas_mensuales` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `cuenta_id` INT NOT NULL,
                `ejercicio` INT NOT NULL,
                `mes` INT NOT NULL,
                `moneda` VARCHAR(5) NOT NULL DEFAULT 'VES',
                `saldo_inicial_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `debitos_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `creditos_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `saldo_final_base` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `saldo_inicial_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `debitos_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `creditos_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `saldo_final_origen` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `actualizado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_cuenta_ejercicio_mes_moneda` (`cuenta_id`, `ejercicio`, `mes`, `moneda`),
                INDEX `idx_cuenta_moneda` (`cuenta_id`, `moneda`),
                INDEX `idx_ejercicio_mes` (`ejercicio`, `mes`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 2. Obtener la lista ordenada cronológicamente de ejercicios y meses con asientos confirmados
        $stmtPeriodos = $db->query("
            SELECT DISTINCT YEAR(a.fecha) AS ejercicio, MONTH(a.fecha) AS mes
            FROM asientos a
            WHERE LOWER(COALESCE(a.estado, 'confirmado')) != 'anulado'
            ORDER BY ejercicio ASC, mes ASC
        ");
        $periodos = $stmtPeriodos->fetchAll(\PDO::FETCH_ASSOC);

        // Cuentas con movimientos o registradas
        $stmtCuentas = $db->query("SELECT id, codigo, naturaleza, tipo FROM cuentas WHERE imputable = 1 ORDER BY codigo ASC");
        $cuentas = $stmtCuentas->fetchAll(\PDO::FETCH_ASSOC);

        $registrosProcesados = 0;

        foreach ($cuentas as $c) {
            $cId = (int)$c['id'];
            $nat = strtolower(trim((string)$c['naturaleza']));
            $esAcreedora = ($nat === 'acreedora');
            $esCuentaReal = in_array(substr((string)($c['codigo'] ?? '1'), 0, 1), ['1', '2', '3'], true);

            $saldoAcumuladoPrevio = 0.00;
            $ejercicioAnterior = null;

            foreach ($periodos as $p) {
                $ej = (int)$p['ejercicio'];
                $m = (int)$p['mes'];

                // Sumar débitos y créditos del mes en curso
                $stmtMov = $db->prepare("
                    SELECT 
                        COALESCE(SUM(da.debe), 0) AS deb,
                        COALESCE(SUM(da.haber), 0) AS cred
                    FROM detalles_asiento da
                    JOIN asientos a ON da.asiento_id = a.id
                    WHERE da.cuenta_id = ?
                      AND YEAR(a.fecha) = ?
                      AND MONTH(a.fecha) = ?
                      AND LOWER(COALESCE(a.estado, 'confirmado')) != 'anulado'
                ");
                $stmtMov->execute([$cId, $ej, $m]);
                $mov = $stmtMov->fetch(\PDO::FETCH_ASSOC);

                $deb = (float)($mov['deb'] ?? 0);
                $cred = (float)($mov['cred'] ?? 0);

                if ($deb == 0 && $cred == 0 && $saldoAcumuladoPrevio == 0) {
                    continue;
                }

                $saldoInicial = $saldoAcumuladoPrevio;

                if ($esAcreedora) {
                    $saldoFinal = $saldoInicial + ($cred - $deb);
                } else {
                    $saldoFinal = $saldoInicial + ($deb - $cred);
                }

                $stmtUpsert = $db->prepare("
                    INSERT INTO saldos_cuentas_mensuales (
                        cuenta_id, ejercicio, mes, moneda,
                        saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                        saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen
                    ) VALUES (?, ?, ?, 'VES', ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        saldo_inicial_base = VALUES(saldo_inicial_base),
                        debitos_base = VALUES(debitos_base),
                        creditos_base = VALUES(creditos_base),
                        saldo_final_base = VALUES(saldo_final_base)
                ");
                $stmtUpsert->execute([
                    $cId, $ej, $m,
                    $saldoInicial, $deb, $cred, $saldoFinal,
                    $saldoInicial, $deb, $cred, $saldoFinal
                ]);

                $saldoAcumuladoPrevio = $saldoFinal;
                $registrosProcesados++;
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Saldos mensuales reconstruidos con Arrastre Secuencial de Continuidad Patrimonial.',
            'periodos_procesados' => count($periodos),
            'registros_poblados' => $registrosProcesados
        ]);
        exit;
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// 1. ENDPOINTS DE SOLICITUDES INTERNAS
if (strpos($path, 'api/inventario/solicitudes-internas') !== false) {
    require_once __DIR__ . '/controllers/SolicitudesInternasController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\SolicitudesInternasController($conn);

    $subPath = str_replace('api/inventario/solicitudes-internas', '', $path);
    $subPath = trim($subPath, '/');

    if ($subPath === 'catalogo' && $method === 'GET') {
        $controller->catalogo();
        exit;
    }

    if ($subPath === 'necesidades-compras' && $method === 'GET') {
        $controller->necesidadesCompras();
        exit;
    }

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^([\w-]+)\/update$/', $subPath, $matches) && $method === 'POST') {
        $controller->update($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/enviar$/', $subPath, $matches) && $method === 'POST') {
        $controller->enviar($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/retractar$/', $subPath, $matches) && $method === 'POST') {
        $controller->retractar($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/aprobar$/', $subPath, $matches) && $method === 'POST') {
        $controller->aprobar($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/rechazar$/', $subPath, $matches) && $method === 'POST') {
        $controller->rechazar($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/anular$/', $subPath, $matches) && $method === 'POST') {
        $controller->anular($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)$/', $subPath, $matches) && $method === 'GET') {
        $controller->show($matches[1]);
        exit;
    }
}

// 2. ENDPOINTS DE ÓRDENES DE ENTREGA / DESPACHO DE ALMACÉN
if (strpos($path, 'api/inventario/ordenes-entrega') !== false) {
    require_once __DIR__ . '/controllers/OrdenesEntregaController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\OrdenesEntregaController($conn);

    $subPath = str_replace('api/inventario/ordenes-entrega', '', $path);
    $subPath = trim($subPath, '/');

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    // Rutas específicas con sufijo PRIMERO (evitan colisión con show)
    if (preg_match('/^([\w-]+)\/pdf$/', $subPath, $matches) && $method === 'GET') {
        $controller->pdf($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/despachar$/', $subPath, $matches) && $method === 'POST') {
        $controller->despachar($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/devolucion$/', $subPath, $matches) && $method === 'POST') {
        $controller->devolucion($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/cancelar-reserva$/', $subPath, $matches) && $method === 'POST') {
        $controller->cancelarReserva($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/anular$/', $subPath, $matches) && $method === 'POST') {
        $controller->anular($matches[1]);
        exit;
    }

    // Rutas genéricas por identificador
    if (preg_match('/^([\w-]+)$/', $subPath, $matches) && $method === 'GET') {
        $controller->show($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)$/', $subPath, $matches) && in_array($method, ['POST', 'PUT'], true)) {
        $controller->update($matches[1]);
        exit;
    }
}

// 3. ENDPOINTS DE INVENTARIO Y CATÁLOGO
if (strpos($path, 'api/inventario') !== false) {
    require_once __DIR__ . '/controllers/InventarioController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\InventarioController($conn);

    $subPath = str_replace('api/inventario', '', $path);
    $subPath = trim($subPath, '/');

    if ($subPath === 'productos' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === 'productos' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^productos\/([\w-]+)$/', $subPath, $matches) && $method === 'GET') {
        $controller->show($matches[1]);
        exit;
    }

    if ($subPath === 'ajustes' && $method === 'POST') {
        $controller->ajustarStock();
        exit;
    }

    if ($subPath === 'categorias' && $method === 'GET') {
        $controller->categorias();
        exit;
    }

    if ($subPath === 'categorias' && $method === 'POST') {
        $controller->guardarCategoria();
        exit;
    }

    if ($subPath === 'movimientos' && $method === 'GET') {
        $controller->movimientos();
        exit;
    }

    if ($subPath === 'departamentos' && $method === 'GET') {
        $controller->departamentos();
        exit;
    }
}

// 4. ENDPOINTS DE REQUISICIONES
if (strpos($path, 'api/requisiciones') !== false) {
    require_once __DIR__ . '/controllers/RequisicionesController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\RequisicionesController($conn);

    $subPath = str_replace('api/requisiciones', '', $path);
    $subPath = trim($subPath, '/');

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^([\w-]+)\/pdf$/', $subPath, $matches) && $method === 'GET') {
        $controller->pdf($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/estado$/', $subPath, $matches) && $method === 'POST') {
        $controller->cambiarEstado($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)\/recibir$/', $subPath, $matches) && $method === 'POST') {
        $controller->recibir($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)$/', $subPath, $matches) && $method === 'DELETE') {
        $controller->destroy($matches[1]);
        exit;
    }

    if (preg_match('/^([\w-]+)$/', $subPath, $matches) && $method === 'GET') {
        $controller->show($matches[1]);
        exit;
    }
}

// 5. ENDPOINTS DE CATÁLOGO DE CUENTAS CONTABLES (FASE 1)
if (strpos($path, 'api/catalogo/cuentas') !== false) {
    require_once __DIR__ . '/controllers/CatalogoCuentasController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\CatalogoCuentasController($conn);

    $subPath = str_replace('api/catalogo/cuentas', '', $path);
    $subPath = trim($subPath, '/');

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if ($subPath === 'search-partidas' && $method === 'GET') {
        $controller->searchPartidas();
        exit;
    }

    if ($subPath === 'search-contables' && $method === 'GET') {
        $controller->searchContables();
        exit;
    }

    if ($subPath === 'validar' && $method === 'POST') {
        $controller->validarCampo();
        exit;
    }

    if ($subPath === 'validar-partida' && $method === 'POST') {
        $controller->validarCodigoPartida();
        exit;
    }

    if ($subPath === 'crear-inventario' && $method === 'POST') {
        $controller->crearInventario();
        exit;
    }

    if ($subPath === 'importar' && $method === 'POST') {
        $controller->importarMasivo();
        exit;
    }

    if ($subPath === 'deshacer-ultimo-lote' && $method === 'POST') {
        $controller->deshacerUltimoLote();
        exit;
    }

    if ($subPath === 'vaciar' && $method === 'POST') {
        $controller->vaciarCatalogo();
        exit;
    }

    if ($subPath === 'exportar' && $method === 'GET') {
        $controller->exportar();
        exit;
    }

    if (preg_match('/^(\d+)\/estado$/', $subPath, $matches) && $method === 'PATCH') {
        $controller->toggleEstado($matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && $method === 'PUT') {
        $controller->update($matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && $method === 'GET') {
        $controller->show($matches[1]);
        exit;
    }
}

// 5.5. ENDPOINTS DE CATÁLOGO DE CUENTAS
if (strpos($path, 'api/catalogo/cuentas') !== false || strpos($path, 'api/contabilidad/cuentas') !== false) {
    require_once __DIR__ . '/controllers/CatalogoCuentasController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\CatalogoCuentasController($conn);

    $subPath = preg_replace('#^api/(?:catalogo|contabilidad)/cuentas#i', '', $path);
    $subPath = trim((string)$subPath, '/');

    if ($subPath === 'search-partidas' && $method === 'GET') {
        $controller->searchPartidas();
        exit;
    }

    if ($subPath === 'search-contables' && $method === 'GET') {
        $controller->searchContables();
        exit;
    }

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && in_array($method, ['PUT', 'POST'], true)) {
        $controller->update((int)$matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && $method === 'GET') {
        $controller->show((int)$matches[1]);
        exit;
    }
}

// 6. ENDPOINTS DE MATRIZ DE CONVERSIÓN
if (strpos($path, 'api/catalogo/matriz') !== false || strpos($path, 'api/contabilidad/matriz') !== false) {
    require_once __DIR__ . '/controllers/MatrizConversionController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\MatrizConversionController($conn);

    $subPath = preg_replace('#^api/(?:catalogo|contabilidad)/matriz#i', '', $path);
    $subPath = trim((string)$subPath, '/');

    if ($subPath === 'plantilla' && $method === 'GET') {
        $controller->descargarPlantilla();
        exit;
    }

    if ($subPath === 'exportar' && $method === 'GET') {
        $controller->exportarMatriz();
        exit;
    }

    if ($subPath === 'importar' && $method === 'POST') {
        $controller->importarMasivo();
        exit;
    }

    if ($subPath === 'vaciar' && $method === 'POST') {
        $controller->vaciarMatriz();
        exit;
    }

    if ($subPath === 'deshacer-ultimo-lote' && $method === 'POST') {
        $controller->deshacerUltimoLote();
        exit;
    }

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^(\d+)\/estado$/', $subPath, $matches) && $method === 'PATCH') {
        $controller->toggleEstado($matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && in_array($method, ['PUT', 'POST'], true)) {
        $controller->update($matches[1]);
        exit;
    }
}

// 7. ENDPOINTS DE CONFIGURACIÓN DE CUENTAS DEL SISTEMA
if (strpos($path, 'api/contabilidad/configuracion-cuentas') !== false) {
    require_once __DIR__ . '/controllers/ConfiguracionCuentasController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\ConfiguracionCuentasController($conn);

    $subPath = str_replace('api/contabilidad/configuracion-cuentas', '', $path);
    $subPath = trim($subPath, '/');

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === 'crear-faltantes' && $method === 'POST') {
        $controller->crearFaltantes();
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && in_array($method, ['PUT', 'POST'], true)) {
        $controller->update($matches[1]);
        exit;
    }
}

// 8. ENDPOINTS DE TESORERÍA - CUENTAS BANCARIAS
if (strpos($path, 'api/tesoreria/cuentas-bancarias') !== false) {
    require_once __DIR__ . '/services/CuentaBancariaService.php';
    require_once __DIR__ . '/controllers/CuentasBancariasController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\CuentasBancariasController($conn);

    $subPath = str_replace('api/tesoreria/cuentas-bancarias', '', $path);
    $subPath = trim((string)$subPath, '/');

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^(\d+)\/saldo-inicial$/', $subPath, $matches) && $method === 'POST') {
        $controller->establecerSaldoInicial((int)$matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)\/estado$/', $subPath, $matches) && in_array($method, ['POST', 'PATCH'], true)) {
        $controller->cambiarEstado((int)$matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && in_array($method, ['PUT', 'POST'], true)) {
        $controller->update((int)$matches[1]);
        exit;
    }
}

// 9. ENDPOINTS DE TESORERÍA - TRANSFERENCIAS BANCARIAS
if (strpos($path, 'api/tesoreria/transferencias') !== false) {
    require_once __DIR__ . '/services/CuentaBancariaService.php';
    require_once __DIR__ . '/controllers/TransferenciasController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\TransferenciasController($conn);

    $subPath = str_replace('api/tesoreria/transferencias', '', $path);
    $subPath = trim((string)$subPath, '/');

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^(\d+)\/cancelar$/', $subPath, $matches) && $method === 'POST') {
        $controller->cancelar((int)$matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)\/adjuntos$/', $subPath, $matches) && $method === 'POST') {
        $controller->adjuntarArchivos((int)$matches[1]);
        exit;
    }
}

// 10. ENDPOINTS DE CONTABILIDAD - ASIENTOS CONTABLES
if (strpos($path, 'api/contabilidad/asientos') !== false) {
    require_once __DIR__ . '/controllers/AsientosContablesController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\AsientosContablesController($conn);

    $subPath = str_replace('api/contabilidad/asientos', '', $path);
    $subPath = trim((string)$subPath, '/');

    if ($subPath === 'correlativo-ingreso' && $method === 'GET') {
        $controller->obtenerCorrelativoIngreso();
        exit;
    }

    if ($subPath === '' && $method === 'GET') {
        $controller->index();
        exit;
    }

    if ($subPath === '' && $method === 'POST') {
        $controller->store();
        exit;
    }

    if (preg_match('/^(\d+)\/confirmar$/', $subPath, $matches) && $method === 'POST') {
        $controller->confirmar((int)$matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)\/anular$/', $subPath, $matches) && $method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $fechaReversion = $data['fecha_reversion'] ?? null;
        $controller->anular((int)$matches[1], $fechaReversion);
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && in_array($method, ['PUT', 'POST'], true)) {
        $controller->update((int)$matches[1]);
        exit;
    }

    if (preg_match('/^(\d+)$/', $subPath, $matches) && $method === 'GET') {
        $controller->show((int)$matches[1]);
        exit;
    }
}

// 11. ENDPOINTS DE CONTABILIDAD - LIBROS CONTABLES (LIBRO DIARIO, LIBRO MAYOR, BALANCE DE COMPROBACIÓN)
if (strpos($path, 'api/contabilidad/libros') !== false) {
    require_once __DIR__ . '/controllers/LibrosContablesController.php';
    $conn = \getConnection();
    $controller = new \Api\Controllers\LibrosContablesController($conn);

    $subPath = str_replace('api/contabilidad/libros', '', $path);
    $subPath = trim((string)$subPath, '/');

    if ($subPath === 'diario' && $method === 'GET') {
        $controller->libroDiario();
        exit;
    }

    if ($subPath === 'mayor' && $method === 'GET') {
        $controller->libroMayor();
        exit;
    }

    if ($subPath === 'balance-comprobacion' && $method === 'GET') {
        $controller->balanceComprobacion();
        exit;
    }
}

// 12. ENDPOINT SEEDER: Reconstrucción Histórica Secuencial de Saldos Cuentas Mensuales en PHP (Arrastre Patrimonial Puro M-1 -> M)
if (strpos($path, 'api/seeder/reconstruir-saldos') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = \getConnection();

        // 1. Vaciar saldos anteriores
        $db->exec("TRUNCATE TABLE saldos_cuentas_mensuales");

        // 2. Obtener la lista de todos los ejercicios y meses con asientos confirmados
        $stmtPeriodos = $db->query("
            SELECT DISTINCT YEAR(fecha) as ejercicio, MONTH(fecha) as mes 
            FROM asientos 
            WHERE estado = 'confirmado' 
            ORDER BY ejercicio ASC, mes ASC
        ");
        $periodos = $stmtPeriodos->fetchAll(PDO::FETCH_ASSOC);

        $totalRegistros = 0;
        $saldosAcumulados = []; // [cuenta_id => ['base' => 0.0, 'origen' => 0.0]]

        foreach ($periodos as $p) {
            $ejercicio = (int)$p['ejercicio'];
            $mes = (int)$p['mes'];

            // Agrupar movimientos del mes por cuenta
            $stmtMovs = $db->prepare("
                SELECT 
                    da.cuenta_id,
                    SUM(da.debe) as debitos_base,
                    SUM(da.haber) as creditos_base,
                    SUM(CASE WHEN da.debe > 0 THEN COALESCE(da.monto_origen, da.debe) ELSE 0 END) as debitos_origen,
                    SUM(CASE WHEN da.haber > 0 THEN COALESCE(da.monto_origen, da.haber) ELSE 0 END) as creditos_origen
                FROM detalles_asiento da
                JOIN asientos a ON da.asiento_id = a.id
                WHERE a.estado = 'confirmado'
                  AND YEAR(a.fecha) = :ejercicio
                  AND MONTH(a.fecha) = :mes
                GROUP BY da.cuenta_id
            ");
            $stmtMovs->execute([':ejercicio' => $ejercicio, ':mes' => $mes]);
            $movsMes = $stmtMovs->fetchAll(PDO::FETCH_ASSOC);

            // Cuentas con movimientos en este mes
            $cuentasMov = [];
            foreach ($movsMes as $m) {
                $cId = (int)$m['cuenta_id'];
                $cuentasMov[$cId] = $m;
            }

            // Unir todas las cuentas activas (con saldo previo o con movimiento)
            $todasCuentas = array_unique(array_merge(array_keys($saldosAcumulados), array_keys($cuentasMov)));

            $stmtInsert = $db->prepare("
                INSERT INTO saldos_cuentas_mensuales (
                    cuenta_id, ejercicio, mes, moneda,
                    saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                    saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen
                ) VALUES (?, ?, ?, 'VES', ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($todasCuentas as $cId) {
                $stmtNat = $db->prepare("SELECT naturaleza FROM cuentas WHERE id = ?");
                $stmtNat->execute([$cId]);
                $nat = strtolower((string)$stmtNat->fetchColumn());

                $sIniBase = $saldosAcumulados[$cId]['base'] ?? 0.00;
                $sIniOrig = $saldosAcumulados[$cId]['origen'] ?? 0.00;

                $debBase = (float)($cuentasMov[$cId]['debitos_base'] ?? 0.00);
                $credBase = (float)($cuentasMov[$cId]['creditos_base'] ?? 0.00);
                $debOrig = (float)($cuentasMov[$cId]['debitos_origen'] ?? 0.00);
                $credOrig = (float)($cuentasMov[$cId]['creditos_origen'] ?? 0.00);

                if ($nat === 'deudora') {
                    $sFinBase = $sIniBase + $debBase - $credBase;
                    $sFinOrig = $sIniOrig + $debOrig - $credOrig;
                } else {
                    $sFinBase = $sIniBase + $credBase - $debBase;
                    $sFinOrig = $sIniOrig + $credOrig - $debOrig;
                }

                $stmtInsert->execute([
                    $cId, $ejercicio, $mes,
                    $sIniBase, $debBase, $credBase, $sFinBase,
                    $sIniOrig, $debOrig, $credOrig, $sFinOrig
                ]);

                // Arrastre puro secuencial M - 1 -> M sin reseteos
                $saldosAcumulados[$cId]['base'] = $sFinBase;
                $saldosAcumulados[$cId]['origen'] = $sFinOrig;
                $totalRegistros++;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Seeder ejecutado con éxito. Reconstruidos {$totalRegistros} saldos mensuales con arrastre de continuidad patrimonial puro.",
            'total_registros' => $totalRegistros
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Fallback Anti-Error: Responde siempre JSON válido ante cualquier ruta no registrada
header('Content-Type: application/json; charset=utf-8');
http_response_code(404);
echo json_encode([
    'success' => false,
    'message' => "Endpoint API no encontrado: {$_SERVER['REQUEST_METHOD']} /{$path}",
], JSON_UNESCAPED_UNICODE);
exit;
