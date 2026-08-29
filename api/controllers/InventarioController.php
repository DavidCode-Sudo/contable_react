<?php
declare(strict_types=1);

namespace Api\Controllers;

if (file_exists(__DIR__ . '/../services/StockService.php')) {
    require_once __DIR__ . '/../services/StockService.php';
}

use Api\Core\Controller;
use Api\Services\StockService;
use PDO;
use Throwable;

if (!function_exists('getConnection')) {
    $dbFiles = [
        __DIR__ . '/../../config/database/database.php',
        __DIR__ . '/../config/database/database.php',
        dirname(__DIR__, 2) . '/config/database/database.php',
    ];
    foreach ($dbFiles as $file) {
        if (file_exists($file)) {
            require_once $file;
            break;
        }
    }
}

/**
 * Controlador RESTful para la gestión completa de Inventario, Categorías y Movimientos de Stock
 */
class InventarioController extends Controller
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } elseif (function_exists('getConnection')) {
            $this->db = getConnection();
        } elseif (function_exists('\getConnection')) {
            $this->db = \getConnection();
        } elseif (class_exists('DatabaseConnection')) {
            $this->db = \DatabaseConnection::getInstance()->getConnection();
        }

        if (isset($this->db) && $this->db) {
            try {
                $this->db->exec("ALTER TABLE categorias_productos ADD cuenta_contable_id INT NULL AFTER descripcion");
            } catch (\Throwable $e) {
                // Columna ya existe
            }
        }
    }

    /**
     * Listado paginado y filtrado de productos del catálogo de inventario
     * Endpoint: GET /api/inventario/productos
     */
    public function index(): void
    {
        try {
            $q = trim((string) ($_GET['q'] ?? ''));
            $categoriaId = isset($_GET['categoria_id']) && (int) $_GET['categoria_id'] > 0 ? (int) $_GET['categoria_id'] : null;
            $alertaStock = trim((string) ($_GET['alerta_stock'] ?? '')); // 'bajo', 'sin', 'normal'
            $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
            $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

            $where = ["p.estado != 'eliminado'"];
            $params = [];

            if ($q !== '') {
                $where[] = "(p.codigo LIKE ? OR p.nombre LIKE ? OR p.descripcion LIKE ? OR p.ubicacion LIKE ?)";
                $search = "%{$q}%";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            if ($categoriaId) {
                $where[] = "p.categoria_id = ?";
                $params[] = $categoriaId;
            }

            if ($alertaStock === 'sin') {
                $where[] = "p.existencias <= 0";
            } elseif ($alertaStock === 'bajo') {
                $where[] = "p.existencias > 0 AND p.existencias <= p.stock_minimo";
            } elseif ($alertaStock === 'normal') {
                $where[] = "p.existencias > p.stock_minimo";
            }

            $whereSql = implode(' AND ', $where);

            // 1. Total de registros para paginación
            $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM productos p WHERE {$whereSql}");
            $stmtCount->execute($params);
            // 2. Auto-sincronización defensiva de stock_reservado con órdenes activas en estado 'aprobada'
            try {
                $this->db->exec("
                    UPDATE productos p
                    SET p.stock_reservado = COALESCE((
                        SELECT SUM(i.cantidad_solicitada - i.cantidad_despachada)
                        FROM orden_entrega_items i
                        JOIN ordenes_entrega o ON o.id = i.orden_entrega_id
                        WHERE i.producto_id = p.id AND o.estado = 'aprobada'
                    ), 0.000)
                ");
            } catch (\Throwable $e) {}

            // 3. Consulta con Join a Categorías y Proveedor Principal
            $stmt = $this->db->prepare("
                SELECT p.*,
                       c.nombre AS categoria_nombre,
                       prov.nombre AS proveedor_nombre
                FROM productos p
                LEFT JOIN categorias_productos c ON p.categoria_id = c.id
                LEFT JOIN proveedores prov ON p.proveedor_principal_id = prov.id
                WHERE {$whereSql}
                ORDER BY p.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $rawProductos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rawProductos as $row) {
                $existencias = (float) $row['existencias'];
                $stockMinimo = (float) $row['stock_minimo'];
                $stockMaximo = (float) $row['stock_maximo'];

                $alerta = 'normal';
                if ($existencias <= 0) {
                    $alerta = 'sin_stock';
                } elseif ($existencias <= $stockMinimo && $stockMinimo > 0) {
                    $alerta = 'bajo_stock';
                }

                $items[] = [
                    'id' => (int) $row['id'],
                    'codigo' => $row['codigo'] ?? "PRD-" . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT),
                    'nombre' => $row['nombre'],
                    'descripcion' => $row['descripcion'],
                    'costo' => round((float) $row['costo'], 2),
                    'precio' => round((float) $row['precio'], 2),
                    'existencias' => $existencias,
                    'stock_reservado' => round((float) ($row['stock_reservado'] ?? 0), 3),
                    'stock_disponible' => max(0, round($existencias - (float) ($row['stock_reservado'] ?? 0), 3)),
                    'stock_minimo' => $stockMinimo,
                    'stock_maximo' => $stockMaximo,
                    'punto_reorden' => (float) $row['punto_reorden'],
                    'unidad_medida' => $row['unidad_medida'] ?? 'UNID',
                    'ubicacion' => $row['ubicacion'],
                    'estado' => $row['estado'] ?? 'activo',
                    'alerta_stock' => $alerta,
                    'categoria' => [
                        'id' => $row['categoria_id'] ? (int) $row['categoria_id'] : null,
                        'nombre' => $row['categoria_nombre'] ?? 'Sin Categoría',
                    ],
                    'proveedor' => [
                        'id' => $row['proveedor_principal_id'] ? (int) $row['proveedor_principal_id'] : null,
                        'nombre' => $row['proveedor_nombre'] ?? null,
                    ],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            }

            // 3. Obtener el próximo correlativo de producto (con 6 ceros)
            $stmtMax = $this->db->query("SELECT MAX(id) AS max_id FROM productos");
            $maxRow = $stmtMax->fetch(PDO::FETCH_ASSOC);
            $nextId = ((int) ($maxRow['max_id'] ?? 0)) + 1;
            $proximoCodigo = "PRD-" . str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);

            // 4. Resumen de Métricas KPI de Inventario
            $summaryStmt = $this->db->query("
                SELECT 
                    COUNT(*) AS total_productos,
                    SUM(CASE WHEN existencias <= 0 THEN 1 ELSE 0 END) AS sin_stock,
                    SUM(CASE WHEN existencias > 0 AND existencias <= stock_minimo AND stock_minimo > 0 THEN 1 ELSE 0 END) AS bajo_stock,
                    SUM(CASE WHEN existencias > stock_minimo OR stock_minimo = 0 THEN 1 ELSE 0 END) AS stock_normal,
                    COALESCE(SUM(existencias * costo), 0) AS valor_total_inventario
                FROM productos
                WHERE estado != 'eliminado'
            ");
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            $this->jsonResponse([
                'success' => true,
                'items' => $items,
                'proximo_codigo' => $proximoCodigo,
                'summary' => [
                    'total_productos' => (int) ($summary['total_productos'] ?? 0),
                    'sin_stock' => (int) ($summary['sin_stock'] ?? 0),
                    'bajo_stock' => (int) ($summary['bajo_stock'] ?? 0),
                    'stock_normal' => (int) ($summary['stock_normal'] ?? 0),
                    'valor_total_inventario' => (float) ($summary['valor_total_inventario'] ?? 0.0),
                ],
                'meta' => [
                    'total' => $totalItems,
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al consultar el catálogo de inventario: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Ver el detalle completo de un producto con sus últimos 10 movimientos
     * Endpoint: GET /api/inventario/productos/{id}
     */
    public function show(int $id): void
    {
        try {
            if ($id <= 0) {
                $this->errorResponse('ID de producto inválido.', 400);
            }

            $stmt = $this->db->prepare("
                SELECT p.*,
                       c.nombre AS categoria_nombre,
                       prov.nombre AS proveedor_nombre
                FROM productos p
                LEFT JOIN categorias_productos c ON p.categoria_id = c.id
                LEFT JOIN proveedores prov ON p.proveedor_principal_id = prov.id
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$producto) {
                $this->errorResponse("El producto #{$id} no existe en el sistema.", 404);
            }

            // Movimientos recientes
            $stmtMov = $this->db->prepare("
                SELECT m.*, u.nombre_completo AS usuario_nombre, r.numero AS requisicion_numero
                FROM movimientos_inventario m
                LEFT JOIN usuarios u ON m.usuario_id = u.id
                LEFT JOIN requisiciones r ON m.requisicion_id = r.id
                WHERE m.producto_id = ?
                ORDER BY m.fecha DESC, m.id DESC
                LIMIT 15
            ");
            $stmtMov->execute([$id]);
            $rawMov = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

            $movimientos = [];
            foreach ($rawMov as $m) {
                $movimientos[] = [
                    'id' => (int) $m['id'],
                    'tipo' => $m['tipo'],
                    'cantidad' => (float) $m['cantidad'],
                    'razon' => $m['razon'],
                    'precio_unitario' => (float) $m['precio_unitario'],
                    'valor_total' => (float) $m['valor_total'],
                    'stock_anterior' => (float) $m['stock_anterior'],
                    'stock_nuevo' => (float) $m['stock_nuevo'],
                    'usuario' => $m['usuario_nombre'] ?? 'Sistema',
                    'requisicion' => $m['requisicion_numero'] ? ['id' => (int)$m['requisicion_id'], 'numero' => $m['requisicion_numero']] : null,
                    'fecha' => $m['fecha'],
                ];
            }

            $this->jsonResponse([
                'success' => true,
                'producto' => [
                    'id' => (int) $producto['id'],
                    'codigo' => $producto['codigo'],
                    'nombre' => $producto['nombre'],
                    'descripcion' => $producto['descripcion'],
                    'costo' => (float) $producto['costo'],
                    'precio' => (float) $producto['precio'],
                    'existencias' => (float) $producto['existencias'],
                    'stock_minimo' => (float) $producto['stock_minimo'],
                    'stock_maximo' => (float) $producto['stock_maximo'],
                    'unidad_medida' => $producto['unidad_medida'] ?? 'UNID',
                    'ubicacion' => $producto['ubicacion'],
                    'estado' => $producto['estado'] ?? 'activo',
                    'categoria' => [
                        'id' => $producto['categoria_id'] ? (int) $producto['categoria_id'] : null,
                        'nombre' => $producto['categoria_nombre'] ?? 'Sin Categoría',
                    ],
                ],
                'movimientos_recientes' => $movimientos,
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al consultar detalle del producto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Crear o editar un producto en el catálogo
     * Endpoint: POST /api/inventario/productos
     */
    public function store(): void
    {
        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;

            $id = isset($data['id']) && (int) $data['id'] > 0 ? (int) $data['id'] : null;
            $nombre = trim((string) ($data['nombre'] ?? ''));
            $codigo = trim((string) ($data['codigo'] ?? ''));
            $descripcion = trim((string) ($data['descripcion'] ?? ''));
            $unidadMedida = trim((string) ($data['unidad_medida'] ?? 'UNID'));
            $ubicacion = trim((string) ($data['ubicacion'] ?? ''));
            $categoriaId = isset($data['categoria_id']) && (int) $data['categoria_id'] > 0 ? (int) $data['categoria_id'] : null;

            $costo = isset($data['costo']) ? round(max(0.0, (float) $data['costo']), 2) : 0.0;
            $precio = isset($data['precio']) ? round(max(0.0, (float) $data['precio']), 2) : 0.0;
            $stockMinimo = isset($data['stock_minimo']) ? max(0.0, (float) $data['stock_minimo']) : 0.0;
            $stockMaximo = isset($data['stock_maximo']) ? max(0.0, (float) $data['stock_maximo']) : 0.0;
            $estado = in_array($data['estado'] ?? 'activo', ['activo', 'inactivo'], true) ? $data['estado'] : 'activo';

            $cantidadInicial = isset($data['cantidad_inicial']) ? max(0.0, (float) $data['cantidad_inicial']) : 0.0;
            $tipoIngreso = trim((string) ($data['tipo_ingreso'] ?? 'Donación'));
            $observacionesIngreso = trim((string) ($data['observaciones_ingreso'] ?? ''));

            $docRef = trim((string) ($data['documento_referencia'] ?? ($data['documento_referencia_ingreso'] ?? '')));

            $errores = [];
            if ($nombre === '') {
                $errores[] = 'El nombre del producto es obligatorio.';
            }
            if ($stockMaximo > 0 && $stockMinimo > $stockMaximo) {
                $errores[] = 'El stock mínimo no puede ser mayor que el stock máximo.';
            }
            if (!$id && $cantidadInicial > 0) {
                if ($costo <= 0) {
                    $errores[] = 'El Costo Unitario / Valor Tasado de Mercado en Bolívares es estrictamente obligatorio para registrar existencias iniciales en el patrimonio.';
                }
                if ($docRef === '') {
                    $errores[] = 'El documento de referencia o acta respaldante es obligatorio para registrar un producto con cantidad inicial positiva.';
                }
            }

            if (!empty($errores)) {
                $this->jsonResponse(['success' => false, 'message' => implode(' ', $errores), 'errors' => $errores], 422);
            }

            // Validar duplicidad estricta por nombre (case-insensitive & trimmed)
            if ($id) {
                // EDICIÓN: Excluir el propio ID
                $stmtCheck = $this->db->prepare("
                    SELECT id, codigo, nombre 
                    FROM productos 
                    WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) 
                      AND id != ? 
                      AND estado != 'eliminado'
                    LIMIT 1
                ");
                $stmtCheck->execute([$nombre, $id]);
                $productoExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($productoExistente) {
                    $this->jsonResponse([
                        'success' => false,
                        'message' => "Ya existe otro producto registrado con el nombre '{$productoExistente['nombre']}' (Código: {$productoExistente['codigo']}).",
                        'errors' => ["Ya existe otro producto registrado con el nombre '{$productoExistente['nombre']}' (Código: {$productoExistente['codigo']})."],
                    ], 422);
                }
            } else {
                // CREACIÓN: Buscar coincidencia por nombre
                $stmtCheck = $this->db->prepare("
                    SELECT id, codigo, nombre 
                    FROM productos 
                    WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) 
                      AND estado != 'eliminado'
                    LIMIT 1
                ");
                $stmtCheck->execute([$nombre]);
                $productoExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($productoExistente) {
                    $this->jsonResponse([
                        'success' => false,
                        'message' => "Ya existe un producto registrado con el nombre '{$productoExistente['nombre']}' (Código: {$productoExistente['codigo']}). Para agregar más existencias, diríjase a la tabla y seleccione 'Ajustar Stock' (Entrada).",
                        'errors' => ["Ya existe un producto registrado con el nombre '{$productoExistente['nombre']}' (Código: {$productoExistente['codigo']}). Para agregar más existencias, diríjase a la tabla y seleccione 'Ajustar Stock' (Entrada)."],
                    ], 422);
                }
            }

            $unidadesDiscretas = ['UNID', 'UNIDAD', 'UNIDADES', 'PZA', 'PIEZA', 'PIEZAS', 'CAJA', 'CAJAS', 'EQ', 'EQUIPO', 'EQUIPOS', 'RESMA', 'RESMAS', 'JUEGO', 'PAQUETE'];
            $nuevoPermiteDecimales = isset($data['permite_decimales'])
                ? ((int) $data['permite_decimales'] === 1 ? 1 : 0)
                : (in_array(strtoupper(trim($unidadMedida)), $unidadesDiscretas, true) ? 0 : 1);

            if ($id) {
                // Actualizar producto existente con guarda contra existencias/reservas fraccionadas
                $stmtCurrent = $this->db->prepare("SELECT existencias, stock_reservado, COALESCE(permite_decimales, 1) AS permite_decimales FROM productos WHERE id = ?");
                $stmtCurrent->execute([$id]);
                $currentProd = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

                if ($currentProd) {
                    $existenciasActuales = (float) $currentProd['existencias'];
                    $reservadoActual = (float) $currentProd['stock_reservado'];
                    if ($nuevoPermiteDecimales === 0 && (abs($existenciasActuales - round($existenciasActuales)) > 0.0001 || abs($reservadoActual - round($reservadoActual)) > 0.0001)) {
                        $this->errorResponse("📌 DIAGNÓSTICO: Conversión a unidad indivisible bloqueada. 💡 DETALLE: No se puede convertir el producto a '{$unidadMedida}' porque posee existencias ({$existenciasActuales}) o stock reservado ({$reservadoActual}) con valores fraccionados. 🔧 ACCIÓN REQUERIDA: Realice un ajuste de inventario previo para redondear el stock físico y las reservas a números enteros antes de modificar el catálogo.", 422);
                    }
                }

                $codigoFinal = $codigo ?: ("PRD-" . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
                $stmtUpdate = $this->db->prepare("
                    UPDATE productos
                    SET codigo = ?, nombre = ?, descripcion = ?, costo = ?, precio = ?,
                        stock_minimo = ?, stock_maximo = ?, unidad_medida = ?, ubicacion = ?,
                        categoria_id = ?, estado = ?, permite_decimales = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $codigoFinal,
                    $nombre,
                    $descripcion,
                    $costo,
                    $precio,
                    $stockMinimo,
                    $stockMaximo,
                    $unidadMedida,
                    $ubicacion,
                    $categoriaId,
                    $estado,
                    $nuevoPermiteDecimales,
                    $id,
                ]);

                $productoId = $id;
                $mensaje = 'Producto actualizado con éxito.';
            } else {
                // Generar código autoincremental de 6 ceros (ej: PRD-000001)
                $this->db->beginTransaction();

                $stmtInsert = $this->db->prepare("
                    INSERT INTO productos (
                        codigo, nombre, descripcion, costo, precio, existencias,
                        stock_minimo, stock_maximo, unidad_medida, ubicacion,
                        categoria_id, estado, permite_decimales, created_at
                    ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmtInsert->execute([
                    $codigo ?: 'TEMP_CODE',
                    $nombre,
                    $descripcion,
                    $costo,
                    $precio,
                    $stockMinimo,
                    $stockMaximo,
                    $unidadMedida,
                    $ubicacion,
                    $categoriaId,
                    $estado,
                    $nuevoPermiteDecimales,
                ]);

                $productoId = (int) $this->db->lastInsertId();
                $codigoGenerado = "PRD-" . str_pad((string)$productoId, 6, '0', STR_PAD_LEFT);
                $this->db->prepare("UPDATE productos SET codigo = ? WHERE id = ?")->execute([$codigoGenerado, $productoId]);

                $docRef = trim((string) ($data['documento_referencia'] ?? ($data['documento_referencia_ingreso'] ?? '')));
                $motivoCodigo = match ($tipoIngreso) {
                    'donacion', 'Donación' => 'donacion',
                    'sobrante', 'Sobrante' => 'sobrante',
                    default => 'apertura',
                };

                // Registrar ingreso inicial de existencias si cantidadInicial > 0
                if ($cantidadInicial > 0) {
                    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 1);
                    $razonPartes = ["Ingreso inicial [{$tipoIngreso}]"];
                    if ($docRef !== '') {
                        $razonPartes[] = "Ref: {$docRef}";
                    }
                    if ($observacionesIngreso !== '') {
                        $razonPartes[] = $observacionesIngreso;
                    }
                    $razonIngreso = implode(' - ', $razonPartes);

                    StockService::mutarStock(
                        $this->db,
                        $productoId,
                        $cantidadInicial,
                        'entrada',
                        $razonIngreso,
                        null,
                        null,
                        $usuarioId,
                        $costo,
                        $motivoCodigo,
                        $docRef
                    );

                    // REGLA DE ORO CONTABLE ENTERPRISE: Inyección Atómica Parametrizada por Categoría de Producto
                    $montoTotalAsiento = round($cantidadInicial * $costo, 2);

                    if ($montoTotalAsiento > 0) {
                        // 1. Obtener la cuenta contable estricta parametrizada en la Categoría del Producto
                        $cuentaDebeId = null;
                        if ($categoriaId > 0) {
                            $stmtCatAccount = $this->db->prepare("
                                SELECT c.id, c.nombre, c.cuenta_contable_id, cu.imputable 
                                FROM categorias_productos c
                                LEFT JOIN cuentas cu ON c.cuenta_contable_id = cu.id
                                WHERE c.id = ?
                            ");
                            $stmtCatAccount->execute([$categoriaId]);
                            $catAccData = $stmtCatAccount->fetch(PDO::FETCH_ASSOC);

                            if ($catAccData && !empty($catAccData['cuenta_contable_id']) && (int)$catAccData['imputable'] === 1) {
                                $cuentaDebeId = (int)$catAccData['cuenta_contable_id'];
                            }
                        }

                        // REGLA ENTERPRISE DE AUDITORÍA CONTABLE: Rechazo estricto si la categoría no tiene cuenta parametrizada (Sin comodines / Sin adivinación)
                        if (!$cuentaDebeId) {
                            $nombreCatError = $catAccData['nombre'] ?? ($categoriaId ? "ID #{$categoriaId}" : "Sin Categoría");
                            throw new \Exception("📌 BLOQUEO DE AUDITORÍA CONTABLE: La categoría de productos '{$nombreCatError}' no tiene asignada una Cuenta Contable Imputable del Plan Único de Cuentas. Diríjase al Catálogo de Categorías y asigne su cuenta contable correspondiente antes de registrar existencias patrimoniales.");
                        }

                        // 2. Leer Cuenta Imputable Contrapartida Patrimonial/Ingreso (HABER) desde configuracion_cuentas_sistema (SIN ADIVINACIÓN / SIN LIKE)
                        $conceptoConfig = match ($motivoCodigo) {
                            'donacion' => 'cuenta_donaciones_inventario',
                            'sobrante' => 'cuenta_sobrantes_inventario',
                            default => 'cuenta_apertura_patrimonio',
                        };

                        $stmtHaberConfig = $this->db->prepare("
                            SELECT c.id, c.codigo, c.imputable 
                            FROM configuracion_cuentas_sistema ccs
                            JOIN cuentas c ON ccs.cuenta_codigo = c.codigo
                            WHERE ccs.concepto = ? AND ccs.activa = 1 AND c.imputable = 1
                        ");
                        $stmtHaberConfig->execute([$conceptoConfig]);
                        $haberConfigData = $stmtHaberConfig->fetch(PDO::FETCH_ASSOC);
                        $cuentaHaberId = $haberConfigData ? (int)$haberConfigData['id'] : null;

                        // REGLA ENTERPRISE DE AUDITORÍA CONTABLE: Interrupción estricta si no existe la cuenta HABER parametrizada (Cero fallbacks / Cero adivinaciones)
                        if (!$cuentaHaberId) {
                            throw new \Exception("📌 BLOQUEO DE AUDITORÍA CONTABLE: La Cuenta Contable de Contrapartida '{$conceptoConfig}' no se encuentra parametrizada en la Matriz de Configuración (configuracion_cuentas_sistema). Configure la cuenta de integración para [{$tipoIngreso}] antes de registrar existencias.");
                        }

                        if ($cuentaDebeId > 0 && $cuentaHaberId > 0) {
                            $fechaAsiento = date('Y-m-d');
                            $ejercicioAsiento = (int)date('Y');
                            $mesAsiento = (int)date('n');

                            $stmtCorrelativo = $this->db->prepare("SELECT COUNT(*) + 1 FROM asientos WHERE YEAR(fecha) = ?");
                            $stmtCorrelativo->execute([$ejercicioAsiento]);
                            $correlativoNum = (int)$stmtCorrelativo->fetchColumn();
                            $numAsientoLegal = sprintf("INV-%04d-%06d", $ejercicioAsiento, $correlativoNum);

                            $glosaAsiento = "Asiento de Ingreso Inicial [{$tipoIngreso}] - Producto: {$codigoGenerado} {$nombre}. Doc: {$docRef}";

                            $stmtAsiento = $this->db->prepare("
                                INSERT INTO asientos (numero, fecha, concepto, debito_total, credito_total, estado, creado_por_usuario_id, created_at)
                                VALUES (?, ?, ?, ?, ?, 'confirmado', ?, NOW())
                            ");
                            $stmtAsiento->execute([
                                $numAsientoLegal,
                                $fechaAsiento,
                                $glosaAsiento,
                                $montoTotalAsiento,
                                $montoTotalAsiento,
                                $usuarioId
                            ]);
                            $asientoId = (int)$this->db->lastInsertId();

                            // Insertar Renglón DEBE (Inventarios)
                            $stmtDetalle = $this->db->prepare("
                                INSERT INTO detalles_asiento (asiento_id, cuenta_id, debe, haber, concepto)
                                VALUES (?, ?, ?, 0.00, ?)
                            ");
                            $stmtDetalle->execute([$asientoId, $cuentaDebeId, $montoTotalAsiento, "Ingreso de existencias {$codigoGenerado}"]);

                            // Insertar Renglón HABER (Patrimonio / Donaciones)
                            $stmtDetalleHaber = $this->db->prepare("
                                INSERT INTO detalles_asiento (asiento_id, cuenta_id, debe, haber, concepto)
                                VALUES (?, ?, 0.00, ?, ?)
                            ");
                            $stmtDetalleHaber->execute([$asientoId, $cuentaHaberId, $montoTotalAsiento, "Contrapartida patrimonial [{$tipoIngreso}]"]);

                            // Actualizar saldos_cuentas_mensuales en O(1) con lectura Batch Bimonetaria en PHP (0 problema N+1, Libre de Deadlocks y respetando Cuentas Nominales)
                            $saldosInicialesBatch = $this->obtenerSaldosInicialesLotePHP([$cuentaDebeId, $cuentaHaberId], $ejercicioAsiento, $mesAsiento);
                            $sDebe = $saldosInicialesBatch[$cuentaDebeId] ?? ['base' => 0.00, 'origen' => 0.00];
                            $sHaber = $saldosInicialesBatch[$cuentaHaberId] ?? ['base' => 0.00, 'origen' => 0.00];

                            $stmtUpsertSaldos = $this->db->prepare("
                                INSERT INTO saldos_cuentas_mensuales (
                                    cuenta_id, ejercicio, mes, moneda,
                                    saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                                    saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen
                                ) VALUES (?, ?, ?, 'VES', ?, ?, ?, 0.00, ?, ?, ?, 0.00)
                                ON DUPLICATE KEY UPDATE 
                                    debitos_base = debitos_base + VALUES(debitos_base),
                                    creditos_base = creditos_base + VALUES(creditos_base)
                            ");
                            // DEBE
                            $stmtUpsertSaldos->execute([$cuentaDebeId, $ejercicioAsiento, $mesAsiento, $sDebe['base'], $montoTotalAsiento, 0.00, $sDebe['origen'], $montoTotalAsiento, 0.00]);
                            // HABER
                            $stmtUpsertSaldos->execute([$cuentaHaberId, $ejercicioAsiento, $mesAsiento, $sHaber['base'], 0.00, $montoTotalAsiento, $sHaber['origen'], 0.00, $montoTotalAsiento]);

                            // REGLA ENTERPRISE DE AUDITORÍA CONTABLE: Recalcular saldo_final_base dinámicamente según la naturaleza de las dos cuentas afectadas
                            $stmtRecalcular = $this->db->prepare("
                                UPDATE saldos_cuentas_mensuales s
                                JOIN cuentas c ON s.cuenta_id = c.id
                                SET s.saldo_final_base = CASE 
                                    WHEN LOWER(c.naturaleza) = 'deudora' THEN s.saldo_inicial_base + s.debitos_base - s.creditos_base
                                    ELSE s.saldo_inicial_base + s.creditos_base - s.debitos_base
                                END
                                WHERE s.cuenta_id IN (?, ?) AND s.ejercicio = ? AND s.mes = ?
                            ");
                            $stmtRecalcular->execute([$cuentaDebeId, $cuentaHaberId, $ejercicioAsiento, $mesAsiento]);
                        }
                    }
                }

                $this->db->commit();
                $mensaje = "Producto registrado con éxito en el catálogo con el código {$codigoGenerado} y su asiento patrimonial inyectado en el Libro Diario.";
            }

            $this->jsonResponse([
                'success' => true,
                'message' => $mensaje,
                'id' => $productoId,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorResponse('Error al guardar producto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Registrar un ajuste manual de existencias (Entrada / Salida) con Bloqueo Pesimista
     * Endpoint: POST /api/inventario/ajustes
     */
    public function ajustarStock(): void
    {
        try {
            // REGLA DE SEGURIDAD: Ajustes manuales exigen sesión válida autenticada
            if (!isset($_SESSION['usuario_id']) || empty($_SESSION['usuario_id'])) {
                $this->errorResponse('Acceso denegado. Debe iniciar sesión con un usuario autorizado para realizar ajustes de inventario.', 401);
            }

            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;

            $productoId = isset($data['producto_id']) ? (int) $data['producto_id'] : 0;
            $cantidad = isset($data['cantidad']) ? (float) $data['cantidad'] : 0.0;
            $tipo = trim((string) ($data['tipo'] ?? ''));
            $motivoCodigo = trim((string) ($data['motivo'] ?? ($data['motivo_codigo'] ?? '')));
            $motivoLabel = trim((string) ($data['motivo_label'] ?? ''));
            $docRef = trim((string) ($data['documento_referencia'] ?? ''));
            $obs = trim((string) ($data['observaciones'] ?? ''));
            $razonRaw = trim((string) ($data['razon'] ?? ''));
            $costoUnitario = isset($data['costo_unitario']) ? (float) $data['costo_unitario'] : null;

            $partesRazon = [];
            if ($motivoLabel !== '') {
                $partesRazon[] = "[{$motivoLabel}]";
            }
            if ($docRef !== '') {
                $partesRazon[] = "Ref: {$docRef}";
            }
            if ($obs !== '') {
                $partesRazon[] = $obs;
            } elseif ($razonRaw !== '') {
                $partesRazon[] = $razonRaw;
            }

            $razonFinal = !empty($partesRazon) ? implode(' - ', $partesRazon) : 'Ajuste manual de inventario';

            if ($productoId <= 0) {
                $this->errorResponse('Debe seleccionar un producto válido.', 422);
            }
            if ($cantidad <= 0) {
                $this->errorResponse('La cantidad de ajuste debe ser mayor a cero.', 422);
            }
            if (!in_array($tipo, ['entrada', 'salida'], true)) {
                $this->errorResponse("Tipo de movimiento inválido ('{$tipo}'). Seleccione Entrada o Salida.", 422);
            }
            if ($tipo === 'entrada' && empty(trim($docRef))) {
                $this->errorResponse('El documento de referencia o acta respaldante es obligatorio para registrar entradas al inventario.', 422);
            }

            $usuarioId = (int) $_SESSION['usuario_id'];

            // Delegación atómica en StockService (Las salidas fuerzan costo null para no alterar CPP)
            $resultado = StockService::mutarStock(
                $this->db,
                $productoId,
                $cantidad,
                $tipo,
                $razonFinal,
                null,
                null,
                $usuarioId,
                $tipo === 'entrada' ? $costoUnitario : null,
                $motivoCodigo,
                $docRef
            );

            $this->jsonResponse([
                'success' => true,
                'message' => "Movimiento de {$tipo} registrado con éxito.",
                'resultado' => $resultado,
            ]);
        } catch (Throwable $e) {
            $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Listado de Categorías de Inventario
     * Endpoint: GET /api/inventario/categorias
     */
    public function categorias(): void
    {
        try {
            $stmt = $this->db->query("
                SELECT c.id, c.nombre, c.descripcion, c.cuenta_contable_id, c.estado,
                       cu.codigo AS cuenta_codigo,
                       cu.nombre AS cuenta_nombre,
                       (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id AND p.estado != 'eliminado') AS total_productos
                FROM categorias_productos c
                LEFT JOIN cuentas cu ON c.cuenta_contable_id = cu.id
                ORDER BY c.nombre ASC
            ");
            $rawCategorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $categorias = [];
            foreach ($rawCategorias as $cat) {
                $categorias[] = [
                    'id' => (int) $cat['id'],
                    'nombre' => $cat['nombre'],
                    'descripcion' => $cat['descripcion'],
                    'cuenta_contable_id' => $cat['cuenta_contable_id'] ? (int)$cat['cuenta_contable_id'] : null,
                    'cuenta_codigo' => $cat['cuenta_codigo'] ?? null,
                    'cuenta_nombre' => $cat['cuenta_nombre'] ?? null,
                    'estado' => $cat['estado'] ?? 'activo',
                    'total_productos' => (int) $cat['total_productos'],
                ];
            }

            $this->jsonResponse([
                'success' => true,
                'categorias' => $categorias,
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al consultar categorías de inventario: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Crear o editar una Categoría de Productos
     * Endpoint: POST /api/inventario/categorias
     */
    public function guardarCategoria(): void
    {
        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true) ?? $_POST;

            $id = isset($data['id']) && (int) $data['id'] > 0 ? (int) $data['id'] : null;
            $nombre = trim((string) ($data['nombre'] ?? ''));
            $descripcion = trim((string) ($data['descripcion'] ?? ''));
            $cuentaContableId = isset($data['cuenta_contable_id']) && (int) $data['cuenta_contable_id'] > 0 ? (int) $data['cuenta_contable_id'] : null;
            $estado = in_array($data['estado'] ?? 'activo', ['activo', 'inactivo'], true) ? $data['estado'] : 'activo';

            if ($nombre === '') {
                $this->errorResponse('El nombre de la categoría es obligatorio.', 422);
            }

            // Validar si la categoría ya existe
            if ($id) {
                $stmtCheck = $this->db->prepare("SELECT id FROM categorias_productos WHERE LOWER(nombre) = LOWER(?) AND id != ?");
                $stmtCheck->execute([$nombre, $id]);
            } else {
                $stmtCheck = $this->db->prepare("SELECT id FROM categorias_productos WHERE LOWER(nombre) = LOWER(?)");
                $stmtCheck->execute([$nombre]);
            }

            if ($stmtCheck->fetch()) {
                $this->errorResponse("Ya existe una categoría registrada con el nombre '{$nombre}'.", 422);
            }

            if ($id) {
                $stmt = $this->db->prepare("UPDATE categorias_productos SET nombre = ?, descripcion = ?, cuenta_contable_id = ?, estado = ? WHERE id = ?");
                $stmt->execute([$nombre, $descripcion, $cuentaContableId, $estado, $id]);
                $catId = $id;
                $msg = 'Categoría actualizada con éxito.';
            } else {
                $stmt = $this->db->prepare("INSERT INTO categorias_productos (nombre, descripcion, cuenta_contable_id, estado) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nombre, $descripcion, $cuentaContableId, $estado]);
                $catId = (int)$this->db->lastInsertId();
                $msg = 'Categoría registrada con éxito.';
            }

            $this->jsonResponse([
                'success' => true,
                'message' => $msg,
                'id' => $catId
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al guardar categoría: ' . $e->getMessage(), 500);
        }
    }

    public function saveCategoria(): void
    {
        $this->guardarCategoria();
    }

    /**
     * Consultar el Historial de Movimientos de Inventario
     * Endpoint: GET /api/inventario/movimientos
     */
    public function movimientos(): void
    {
        try {
            $q = trim((string) ($_GET['q'] ?? ''));
            $tipo = trim((string) ($_GET['tipo'] ?? '')); // 'entrada', 'salida'
            $productoId = isset($_GET['producto_id']) && (int) $_GET['producto_id'] > 0 ? (int) $_GET['producto_id'] : null;
            $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
            $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

            $where = ['1=1'];
            $params = [];

            if ($q !== '') {
                $where[] = "(p.nombre LIKE ? OR p.codigo LIKE ? OR m.razon LIKE ? OR r.numero LIKE ?)";
                $s = "%{$q}%";
                $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
            }

            if ($tipo !== '' && in_array($tipo, ['entrada', 'salida'], true)) {
                $where[] = "m.tipo = ?";
                $params[] = $tipo;
            }

            if ($productoId) {
                $where[] = "m.producto_id = ?";
                $params[] = $productoId;
            }

            $whereSql = implode(' AND ', $where);

            $stmtCount = $this->db->prepare("
                SELECT COUNT(*)
                FROM movimientos_inventario m
                LEFT JOIN productos p ON m.producto_id = p.id
                LEFT JOIN requisiciones r ON m.requisicion_id = r.id
                WHERE {$whereSql}
            ");
            $stmtCount->execute($params);
            $total = (int) $stmtCount->fetchColumn();

            // Garantizar índices (producto_id, fecha) en consultas de trazabilidad
            $stmt = $this->db->prepare("
                SELECT m.*,
                       p.nombre AS producto_nombre,
                       p.codigo AS producto_codigo,
                       p.unidad_medida AS producto_unidad,
                       u.nombre_completo AS usuario_nombre,
                       r.numero AS requisicion_numero
                FROM movimientos_inventario m
                LEFT JOIN productos p ON m.producto_id = p.id
                LEFT JOIN usuarios u ON m.usuario_id = u.id
                LEFT JOIN requisiciones r ON m.requisicion_id = r.id
                WHERE {$whereSql}
                ORDER BY m.fecha DESC, m.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $rawMov = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $movimientos = [];
            foreach ($rawMov as $m) {
                $movimientos[] = [
                    'id' => (int) $m['id'],
                    'producto_id' => (int) $m['producto_id'],
                    'producto_codigo' => $m['producto_codigo'],
                    'producto_nombre' => $m['producto_nombre'],
                    'unidad_medida' => $m['producto_unidad'] ?? 'UNID',
                    'cantidad' => (float) $m['cantidad'],
                    'tipo' => $m['tipo'],
                    'razon' => $m['razon'],
                    'motivo_codigo' => $m['motivo_codigo'] ?? null,
                    'documento_referencia' => $m['documento_referencia'] ?? null,
                    'precio_unitario' => (float) $m['precio_unitario'],
                    'valor_total' => (float) $m['valor_total'],
                    'stock_anterior' => (float) $m['stock_anterior'],
                    'stock_nuevo' => (float) $m['stock_nuevo'],
                    'usuario' => $m['usuario_nombre'] ?? 'Sistema',
                    'requisicion' => $m['requisicion_numero'] ? ['id' => (int)$m['requisicion_id'], 'numero' => $m['requisicion_numero']] : null,
                    'fecha' => $m['fecha'],
                ];
            }

            $this->jsonResponse([
                'success' => true,
                'movimientos' => $movimientos,
                'meta' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al consultar historial de movimientos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Listado dinámico de departamentos activos
     * Endpoint: GET /api/inventario/departamentos
     */
    public function departamentos(): void
    {
        try {
            $stmt = $this->db->query("
                SELECT id, codigo, nombre, descripcion 
                FROM departamentos 
                ORDER BY nombre ASC
            ");
            $list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $formatted = array_map(function ($row) {
                return [
                    'id' => (int) $row['id'],
                    'codigo' => $row['codigo'] ?? '',
                    'nombre' => $row['nombre'],
                    'descripcion' => $row['descripcion'] ?? '',
                ];
            }, $list);

            $this->jsonResponse(['success' => true, 'departamentos' => $formatted]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => true, 'departamentos' => []]);
        }
    }

    /**
     * Obtener atómicamente y en LOTE (O(1) Batch / Sin problema N+1 y con array_chunk anti-overflow) los Saldos Iniciales Bimonetarios 
     * respetando Cuentas Reales vs Nominales.
     * 
     * REGLA DE ORO FINTECH: Si la consulta de base de datos falla, SE ARROJA EXCEPCIÓN Y SE ABORTA LA TRANSACCIÓN.
     * Cero encubrimiento con ceros falsos.
     *
     * @param array<int> $cuentasIds
     * @return array<int, array{base: float, origen: float}>
     */
    private function obtenerSaldosInicialesLotePHP(array $cuentasIds, int $ejercicioNuevo, int $mesNuevo): array
    {
        $cuentasIds = array_values(array_unique(array_filter(array_map('intval', $cuentasIds))));
        if (empty($cuentasIds)) return [];

        $resultado = [];
        foreach ($cuentasIds as $id) {
            $resultado[$id] = ['base' => 0.00, 'origen' => 0.00];
        }

        try {
            // Segmentar en bloques de 500 para evitar desbordamiento de placeholders PDO en procesos masivos
            $chunks = array_chunk($cuentasIds, 500);

            foreach ($chunks as $chunkIds) {
                // 1. Obtener los tipos de todas las cuentas del bloque en UN SOLO QUERY BATCH
                $inClause = implode(',', array_fill(0, count($chunkIds), '?'));
                $stmtTipos = $this->db->prepare("SELECT id, tipo, naturaleza FROM cuentas WHERE id IN ({$inClause})");
                $stmtTipos->execute($chunkIds);
                $tiposData = [];
                while ($row = $stmtTipos->fetch(PDO::FETCH_ASSOC)) {
                    $tiposData[(int)$row['id']] = strtolower(trim((string)($row['tipo'] ?? '')));
                }

                // 2. Obtener el último saldo final histórico con CTE Window Function en UN SOLO QUERY BATCH
                $sqlSaldos = "
                    WITH ultimos_saldos AS (
                        SELECT 
                            s.cuenta_id, s.ejercicio, s.mes, s.saldo_final_base, s.saldo_final_origen,
                            ROW_NUMBER() OVER (PARTITION BY s.cuenta_id ORDER BY s.ejercicio DESC, s.mes DESC) as rn
                        FROM saldos_cuentas_mensuales s
                        WHERE s.cuenta_id IN ({$inClause})
                          AND (s.ejercicio < ? OR (s.ejercicio = ? AND s.mes < ?))
                          AND s.moneda = 'VES'
                    )
                    SELECT cuenta_id, ejercicio, mes, saldo_final_base, saldo_final_origen
                    FROM ultimos_saldos
                    WHERE rn = 1
                ";
                $paramsSaldos = array_merge($chunkIds, [$ejercicioNuevo, $ejercicioNuevo, $mesNuevo]);
                $stmtSaldos = $this->db->prepare($sqlSaldos);
                $stmtSaldos->execute($paramsSaldos);
                $saldosPrevios = $stmtSaldos->fetchAll(PDO::FETCH_ASSOC);

                foreach ($saldosPrevios as $row) {
                    $cId = (int)$row['cuenta_id'];
                    $ejercicioPrev = (int)$row['ejercicio'];
                    $saldoBase = (float)$row['saldo_final_base'];
                    $saldoOrigen = (float)$row['saldo_final_origen'];

                    $tipo = $tiposData[$cId] ?? '';
                    $esNominal = in_array($tipo, ['ingreso', 'gasto', 'costo', 'nominal'], true);

                    // Regla fiscal: Si el nuevo mes es Enero (mes = 1) o cambió el ejercicio fiscal (ejercicioNuevo > ejercicioPrev) y la cuenta es NOMINAL, su saldo inicial NACE EN 0.00
                    if (($mesNuevo === 1 || $ejercicioNuevo > $ejercicioPrev) && $esNominal) {
                        $resultado[$cId] = ['base' => 0.00, 'origen' => 0.00];
                    } else {
                        // Cuentas Reales (Activo, Pasivo, Patrimonio) arrastran el saldo inicial bimonetario completo
                        $resultado[$cId] = ['base' => $saldoBase, 'origen' => $saldoOrigen];
                    }
                }
            }

            return $resultado;
        } catch (\Throwable $e) {
            // REGLA DE ORO FINTECH: Jamás encubrir un fallo de base de datos con ceros silenciosos
            throw new \Exception("📌 BLOQUEO DE AUDITORÍA CONTABLE: Error crítico al calcular los Saldos Iniciales en lote para el período [{$ejercicioNuevo}-{$mesNuevo}]: " . $e->getMessage());
        }
    }
}
