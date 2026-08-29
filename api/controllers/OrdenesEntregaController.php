<?php
declare(strict_types=1);

namespace Api\Controllers;

if (file_exists(__DIR__ . '/../services/ContabilidadDespachoService.php')) {
    require_once __DIR__ . '/../services/ContabilidadDespachoService.php';
}
if (file_exists(__DIR__ . '/../services/StockService.php')) {
    require_once __DIR__ . '/../services/StockService.php';
}

use Api\Core\Controller;
use Api\Services\StockService;
use PDO;
use Throwable;

/**
 * Controlador de Producción para Órdenes de Entrega / Despacho Institucional (Versión 2.0 Enterprise)
 * Arquitectura: Concurrencia Pesimista (FOR UPDATE), Secuencia Atómica,
 * Costeo CPP exacto, Soporte de Despacho Parcial, Devolución Parcial (RMA),
 * Expiración de Reserva, Hash SHA-256 de validez jurídica y Bitácora de Auditoría Forense.
 */
class OrdenesEntregaController extends Controller
{
    private function tieneColumna(string $tabla, string $columna): bool
    {
        if (isset($_SESSION['_schema_cols'][$tabla])) {
            return isset($_SESSION['_schema_cols'][$tabla][$columna]);
        }

        try {
            $conn = \getConnection();
            $stmt = $conn->query("SHOW COLUMNS FROM `{$tabla}`");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $_SESSION['_schema_cols'][$tabla] = array_flip($cols);
        } catch (\Throwable $e) {
            $_SESSION['_schema_cols'][$tabla] = [];
        }

        return isset($_SESSION['_schema_cols'][$tabla][$columna]);
    }

    /**
     * Genera un correlativo atómico consecutivo institucional por año fiscal mediante inserción/actualización atómica (Upsert)
     */
    private function generarCorrelativo(PDO $conn, string $codigoSecuencia = 'ODE', ?int $anio = null): string
    {
        $year = $anio ?? (int) date('Y');

        $stmt = $conn->prepare("
            INSERT INTO secuencias_documentos (codigo, anio, ultimo_valor)
            VALUES (?, ?, LAST_INSERT_ID(1))
            ON DUPLICATE KEY UPDATE ultimo_valor = LAST_INSERT_ID(ultimo_valor + 1)
        ");
        $stmt->execute([$codigoSecuencia, $year]);

        $nuevoValor = (int) $conn->query("SELECT LAST_INSERT_ID()")->fetchColumn();

        return sprintf("%s-%d-%05d", $codigoSecuencia, $year, $nuevoValor);
    }

    /**
     * Registra una entrada inmutable de Auditoría Forense en orden_entrega_auditoria
     */
    private function registrarAuditoriaForense(
        PDO $conn,
        int $ordenId,
        int $usuarioId,
        string $accion,
        array $detalles
    ): void {
        try {
            $ipAddress = mb_substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')), 0, 45);
            $userAgent = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')), 0, 255);
            $jsonDetails = json_encode($detalles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmtAudit = $conn->prepare("
                INSERT INTO orden_entrega_auditoria (
                    orden_entrega_id, usuario_id, accion, detalles_json, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtAudit->execute([
                $ordenId,
                $usuarioId,
                $accion,
                $jsonDetails,
                $ipAddress,
                $userAgent,
            ]);
        } catch (Throwable $e) {
            // No interrumpir la transacción principal si falla el log secundario
        }
    }

    /**
     * Genera el Hash criptográfico SHA-256 canónico para validación de integridad jurídica del comprobante
     * Canónico por renglones ordenados por SKU: ODE|{ordenId}|{numeroOrden}|{fechaOrden}|{costoTotal}|{usuarioId}|ITEMS:[P:{id}|C:{cant}|U:{costo}]
     */
    private function generarHashVerificacion(
        PDO $conn,
        int $ordenId,
        string $numeroOrden,
        string $fechaOrden,
        float $costoTotal,
        int $usuarioId
    ): string {
        $stmtItems = $conn->prepare("
            SELECT producto_id, cantidad_despachada, cantidad_solicitada, costo_unitario 
            FROM orden_entrega_items 
            WHERE orden_entrega_id = ? 
            ORDER BY producto_id ASC, id ASC
        ");
        $stmtItems->execute([$ordenId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $itemsCanonicos = '';
        foreach ($items as $it) {
            $pId = (int) $it['producto_id'];
            $cantVal = (float) (($it['cantidad_despachada'] ?? 0) > 0 ? $it['cantidad_despachada'] : ($it['cantidad_solicitada'] ?? 0));
            $cantStr = number_format($cantVal, 3, '.', '');
            $costoStr = number_format((float) ($it['costo_unitario'] ?? 0), 4, '.', '');
            $itemsCanonicos .= "[P:{$pId}|C:{$cantStr}|U:{$costoStr}]";
        }

        $costoFormatted = number_format($costoTotal, 4, '.', '');
        $rawString = "ODE|{$ordenId}|{$numeroOrden}|{$fechaOrden}|{$costoFormatted}|{$usuarioId}|ITEMS:{$itemsCanonicos}";
        return hash('sha256', $rawString);
    }

    /**
     * Maneja excepciones de concurrencia pesimista (1205 Lock Wait Timeout, 1213/40001 Deadlock) usando códigos de driver PDO
     */
    private function handleConcurrencyException(Throwable $e, PDO $conn, string $actionName = 'procesar solicitud'): void
    {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        StockService::restoreLockWaitTimeout($conn);

        $driverCode = ($e instanceof \PDOException && isset($e->errorInfo[1])) ? (int) $e->errorInfo[1] : 0;
        $sqlState = ($e instanceof \PDOException && isset($e->errorInfo[0])) ? (string) $e->errorInfo[0] : '';

        // 1205: Lock wait timeout | 1213 / 40001: Deadlock
        if (in_array($driverCode, [1205, 1213], true) || $sqlState === '40001') {
            $this->errorResponse(
                'El inventario de uno de los productos solicitados está bajo alta demanda en este momento. Por favor, reintente en unos segundos.',
                409
            );
        }

        $this->errorResponse("Error al {$actionName}: " . $e->getMessage(), 422);
    }

    /**
     * GET /api/inventario/ordenes-entrega
     * Listado paginado con filtros, centro de costos y KPIs de resumen
     */
    public function index(): void
    {
        $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 50;
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

        try {
            $conn = \getConnection();

            $queryStr = trim((string) ($_GET['q'] ?? ''));
            $estado = trim((string) ($_GET['estado'] ?? ''));
            $departamentoId = isset($_GET['departamento_id']) ? (int) $_GET['departamento_id'] : 0;
            $centroCostoId = isset($_GET['centro_costo_id']) ? (int) $_GET['centro_costo_id'] : 0;
            $fechaDesde = trim((string) ($_GET['fecha_desde'] ?? ''));
            $fechaHasta = trim((string) ($_GET['fecha_hasta'] ?? ''));

            $conditions = ["1=1"];
            $params = [];

            if ($queryStr !== '') {
                $conditions[] = "(o.numero_orden LIKE ? OR o.justificacion LIKE ? OR d.nombre LIKE ?)";
                $params[] = "%{$queryStr}%";
                $params[] = "%{$queryStr}%";
                $params[] = "%{$queryStr}%";
            }

            if ($estado !== '') {
                $conditions[] = "o.estado = ?";
                $params[] = $estado;
            }

            if ($departamentoId > 0) {
                $conditions[] = "o.departamento_id = ?";
                $params[] = $departamentoId;
            }

            if ($centroCostoId > 0) {
                $conditions[] = "o.centro_costo_id = ?";
                $params[] = $centroCostoId;
            }

            if ($fechaDesde !== '') {
                $conditions[] = "DATE(o.fecha_orden) >= ?";
                $params[] = $fechaDesde;
            }

            if ($fechaHasta !== '') {
                $conditions[] = "DATE(o.fecha_orden) <= ?";
                $params[] = $fechaHasta;
            }

            $whereClause = implode(" AND ", $conditions);

            $stmtCount = $conn->prepare("
                SELECT COUNT(*) 
                FROM ordenes_entrega o
                LEFT JOIN departamentos d ON d.id = o.departamento_id
                WHERE {$whereClause}
            ");
            $stmtCount->execute($params);
            $totalRecords = (int) $stmtCount->fetchColumn();

            $stmt = $conn->prepare("
                SELECT 
                    o.id,
                    o.numero_orden,
                    o.fecha_orden,
                    o.estado,
                    o.tipo_destino,
                    o.justificacion,
                    o.observaciones,
                    o.total_articulos,
                    o.costo_total_despacho,
                    o.hash_verificacion,
                    o.departamento_id,
                    d.nombre AS departamento_nombre,
                    o.centro_costo_id,
                    o.solicitante_id,
                    u.nombre_completo AS usuario_despacho_nombre
                FROM ordenes_entrega o
                LEFT JOIN departamentos d ON d.id = o.departamento_id
                LEFT JOIN usuarios u ON u.id = o.usuario_despacho_id
                WHERE {$whereClause}
                ORDER BY o.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedOrdenes = array_map(function ($row) {
                return [
                    'id' => (int) $row['id'],
                    'numero_orden' => $row['numero_orden'] ?? ($row['numero'] ?? "ODE-{$row['id']}"),
                    'fecha_orden' => $row['fecha_orden'] ?? date('Y-m-d H:i:s'),
                    'estado' => $row['estado'] ?? 'borrador',
                    'tipo_destino' => $row['tipo_destino'] ?? 'departamento',
                    'justificacion' => $row['justificacion'] ?? ($row['motivo'] ?? ''),
                    'observaciones' => $row['observaciones'] ?? '',
                    'total_articulos' => (float) ($row['total_articulos'] ?? 0),
                    'costo_total_despacho' => (float) ($row['costo_total_despacho'] ?? 0),
                    'hash_verificacion' => $row['hash_verificacion'] ?? null,
                    'departamento_id' => !empty($row['departamento_id']) ? (int) $row['departamento_id'] : null,
                    'departamento_nombre' => $row['departamento_nombre'] ?? 'Sin Departamento',
                    'centro_costo_id' => !empty($row['centro_costo_id']) ? (int) $row['centro_costo_id'] : null,
                    'solicitante_id' => !empty($row['solicitante_id']) ? (int) $row['solicitante_id'] : null,
                    'usuario_despacho_nombre' => $row['usuario_despacho_nombre'] ?? 'Sistema',
                ];
            }, $ordenes);

            $primerDiaMes = date('Y-m-01 00:00:00');
            $ultimoDiaMes = date('Y-m-t 23:59:59');

            $stmtKpi1 = $conn->prepare("SELECT COUNT(*) FROM ordenes_entrega WHERE estado IN ('despachada', 'despachada_parcial') AND fecha_orden BETWEEN ? AND ?");
            $stmtKpi1->execute([$primerDiaMes, $ultimoDiaMes]);
            $totalDespachosMes = (int) $stmtKpi1->fetchColumn();

            $stmtKpi2 = $conn->prepare("SELECT COALESCE(SUM(total_articulos), 0) FROM ordenes_entrega WHERE estado IN ('despachada', 'despachada_parcial') AND fecha_orden BETWEEN ? AND ?");
            $stmtKpi2->execute([$primerDiaMes, $ultimoDiaMes]);
            $totalUnidadesEntregadas = (float) $stmtKpi2->fetchColumn();

            $stmtKpi3 = $conn->query("SELECT COUNT(*) FROM ordenes_entrega WHERE estado IN ('borrador', 'aprobada')");
            $ordenesPendientes = (int) $stmtKpi3->fetchColumn();

            $this->jsonResponse([
                'ordenes' => $formattedOrdenes,
                'kpis' => [
                    'total_despachos_mes' => $totalDespachosMes,
                    'total_unidades_entregadas' => $totalUnidadesEntregadas,
                    'ordenes_pendientes' => $ordenesPendientes,
                ],
                'total' => $totalRecords,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), "doesn't exist")) {
                $this->jsonResponse([
                    'ordenes' => [],
                    'kpis' => [
                        'total_despachos_mes' => 0,
                        'total_unidades_entregadas' => 0,
                        'ordenes_pendientes' => 0,
                    ],
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'mensaje_db' => 'La tabla de ordenes_entrega no ha sido creada en MySQL todavía. Por favor ejecute sql/ordenes_entrega.sql.'
                ]);
                return;
            }
            $this->errorResponse('Error al listar órdenes de entrega: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/inventario/ordenes-entrega/{id}
     * Detalle completo con devoluciones (RMA) e historial de auditoría
     */
    public function show(string $id): void
    {
        $identifier = trim($id);
        if (empty($identifier)) {
            $this->errorResponse('Identificador de orden no válido.', 400);
        }

        // PROTECCIÓN ANTI-IDOR / ANTI-SCRAPING: Si la consulta viene sin sesión activa de usuario, exige obligatoriamente el hash o token impreso en el comprobante
        $hashQueryParam = trim((string) ($_GET['hash'] ?? ($_GET['token'] ?? '')));
        $isPublicAccess = empty($_SESSION['usuario_id']);

        if ($isPublicAccess && empty($hashQueryParam) && mb_strlen($identifier) < 32) {
            $this->errorResponse('Acceso no autorizado. Se requiere el token/hash de verificación impreso en el comprobante para validación pública.', 403);
        }

        try {
            $conn = \getConnection();

            $stmt = $conn->prepare("
                SELECT 
                    o.id,
                    o.numero_orden,
                    o.fecha_orden,
                    o.estado,
                    o.tipo_destino,
                    o.justificacion,
                    o.observaciones,
                    o.total_articulos,
                    o.costo_total_despacho,
                    o.hash_verificacion,
                    o.departamento_id,
                    d.nombre AS departamento_nombre,
                    o.centro_costo_id,
                    o.solicitante_id,
                    o.usuario_despacho_id,
                    u.nombre_completo AS usuario_despacho_nombre,
                    o.created_at,
                    o.updated_at
                FROM ordenes_entrega o
                LEFT JOIN departamentos d ON d.id = o.departamento_id
                LEFT JOIN usuarios u ON u.id = o.usuario_despacho_id
                WHERE o.id = ? OR o.hash_verificacion = ?
            ");
            $stmt->execute([$identifier, $identifier]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                $this->errorResponse("La orden de entrega solicitada ({$identifier}) no fue encontrada.", 404);
            }

            // VALIDACIÓN ESTRICTA DE HASH EN CONSULTAS PÚBLICAS (Timing-Safe)
            if ($isPublicAccess) {
                $registeredHash = (string) ($orden['hash_verificacion'] ?? '');
                $targetHash = $hashQueryParam !== '' ? $hashQueryParam : $identifier;

                if ($registeredHash !== '' && !hash_equals($registeredHash, $targetHash)) {
                    $this->errorResponse('Comprobante no válido o token de verificación incorrecto.', 403);
                }
            }

            $ordenId = (int) $orden['id'];

            $stmtItems = $conn->prepare("
                SELECT 
                    i.id,
                    i.orden_entrega_id,
                    i.producto_id,
                    p.codigo AS producto_codigo,
                    p.nombre AS producto_nombre,
                    p.unidad_medida AS producto_unidad,
                    p.existencias AS producto_stock_actual,
                    COALESCE(p.stock_reservado, 0.000) AS producto_stock_reservado,
                    p.costo AS producto_costo_actual,
                    i.cantidad_solicitada,
                    i.cantidad_despachada,
                    i.cantidad_devuelta,
                    i.costo_unitario,
                    i.costo_total,
                    i.observaciones
                FROM orden_entrega_items i
                JOIN productos p ON p.id = i.producto_id
                WHERE i.orden_entrega_id = ?
                ORDER BY i.id ASC
            ");
            $stmtItems->execute([$ordenId]);
            $rawItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $isAprobada = ($orden['estado'] === 'aprobada');
            $items = array_map(function ($it) use ($isAprobada) {
                $stockFisico = (float) ($it['producto_stock_actual'] ?? 0);
                $stockReservadoTotal = (float) ($it['producto_stock_reservado'] ?? 0);
                $cantSolicitadaEstaOrden = $isAprobada ? (float) ($it['cantidad_solicitada'] ?? 0) : 0.0;
                $stockReservadoOtros = max(0.0, $stockReservadoTotal - $cantSolicitadaEstaOrden);
                $stockDisponible = max(0.0, $stockFisico - $stockReservadoOtros);

                return [
                    'id' => (int) $it['id'],
                    'orden_entrega_id' => (int) $it['orden_entrega_id'],
                    'producto_id' => (int) $it['producto_id'],
                    'producto_codigo' => $it['producto_codigo'] ?? '',
                    'producto_nombre' => $it['producto_nombre'] ?? 'Producto no encontrado',
                    'producto_unidad' => $it['producto_unidad'] ?? 'UNID',
                    'producto_stock_actual' => $stockFisico,
                    'producto_stock_reservado' => $stockReservadoTotal,
                    'producto_stock_disponible' => $stockDisponible,
                    'producto_costo_actual' => (float) ($it['producto_costo_actual'] ?? 0),
                    'cantidad_solicitada' => (float) ($it['cantidad_solicitada'] ?? 0),
                    'cantidad_despachada' => (float) ($it['cantidad_despachada'] ?? 0),
                    'cantidad_devuelta' => (float) ($it['cantidad_devuelta'] ?? 0),
                    'costo_unitario' => (float) ($it['costo_unitario'] ?? 0),
                    'costo_total' => (float) ($it['costo_total'] ?? 0),
                    'observaciones' => $it['observaciones'] ?? '',
                ];
            }, $rawItems);

            // Devoluciones Realizadas (RMA)
            $stmtDevs = $conn->prepare("
                SELECT d.id, d.numero_devolucion, d.fecha_devolucion, d.motivo, d.costo_total_devuelto, u.nombre_completo AS usuario_recibe_nombre
                FROM orden_entrega_devoluciones d
                LEFT JOIN usuarios u ON u.id = d.usuario_recibe_id
                WHERE d.orden_entrega_id = ?
                ORDER BY d.id DESC
            ");
            $stmtDevs->execute([$ordenId]);
            $devoluciones = $stmtDevs->fetchAll(PDO::FETCH_ASSOC);

            // Auditoría Forense
            $stmtAudit = $conn->prepare("
                SELECT a.id, a.accion, a.detalles_json, a.ip_address, a.created_at, u.nombre_completo AS usuario_nombre
                FROM orden_entrega_auditoria a
                LEFT JOIN usuarios u ON u.id = a.usuario_id
                WHERE a.orden_entrega_id = ?
                ORDER BY a.id DESC
            ");
            $stmtAudit->execute([$ordenId]);
            $auditoria = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);

            $esAnulada = ($orden['estado'] === 'anulada');

            $this->jsonResponse([
                'valido' => !$esAnulada,
                'estado_documento' => strtoupper((string) ($orden['estado'] ?? 'BORRADOR')),
                'alerta_legal' => $esAnulada 
                    ? 'ESTE COMPROBANTE FÍSICO FUE ANULADO Y CARECE DE VALIDEZ LEGAL O FISCAL PARA CUALQUIER TRÁMITE O DESPACHO'
                    : null,
                'orden' => [
                    'id' => (int) $orden['id'],
                    'numero_orden' => $orden['numero_orden'] ?? ($orden['numero'] ?? "ODE-{$orden['id']}"),
                    'fecha_orden' => $orden['fecha_orden'] ?? date('Y-m-d H:i:s'),
                    'estado' => $orden['estado'] ?? 'borrador',
                    'tipo_destino' => $orden['tipo_destino'] ?? 'departamento',
                    'justificacion' => $orden['justificacion'] ?? ($orden['motivo'] ?? ''),
                    'observaciones' => $orden['observaciones'] ?? '',
                    'total_articulos' => (float) ($orden['total_articulos'] ?? 0),
                    'costo_total_despacho' => (float) ($orden['costo_total_despacho'] ?? 0),
                    'hash_verificacion' => $orden['hash_verificacion'] ?? null,
                    'departamento_id' => !empty($orden['departamento_id']) ? (int) $orden['departamento_id'] : null,
                    'departamento_nombre' => $orden['departamento_nombre'] ?? 'Sin Departamento',
                    'centro_costo_id' => !empty($orden['centro_costo_id']) ? (int) $orden['centro_costo_id'] : null,
                    'solicitante_id' => !empty($orden['solicitante_id']) ? (int) $orden['solicitante_id'] : null,
                    'usuario_despacho_id' => (int) ($orden['usuario_despacho_id'] ?? ($orden['entregado_por'] ?? 1)),
                    'usuario_despacho_nombre' => $orden['usuario_despacho_nombre'] ?? 'Sistema',
                    'created_at' => $orden['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $orden['updated_at'] ?? date('Y-m-d H:i:s'),
                ],
                'items' => $items,
                'devoluciones' => $devoluciones,
                'auditoria' => $auditoria,
            ]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al consultar detalle de la orden de entrega: ' . $e->getMessage(), 422);
        }
    }

    /**
     * POST /api/inventario/ordenes-entrega
     * Crea orden con secuencia atómica y genera Hash SHA-256
     */
    public function store(): void
    {
        $conn = \getConnection();
        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 1);
        $data = $this->getRequestData();

        $tipoDestino = in_array($data['tipo_destino'] ?? 'departamento', ['departamento', 'empleado', 'evento', 'merma_baja'], true)
            ? $data['tipo_destino']
            : 'departamento';
        $departamentoId = isset($data['departamento_id']) && (int) $data['departamento_id'] > 0 ? (int) $data['departamento_id'] : null;
        $centroCostoId = isset($data['centro_costo_id']) && (int) $data['centro_costo_id'] > 0 ? (int) $data['centro_costo_id'] : null;
        $solicitanteId = isset($data['solicitante_id']) && (int) $data['solicitante_id'] > 0 ? (int) $data['solicitante_id'] : $usuarioId;
        $justificacion = trim((string) ($data['justificacion'] ?? ''));
        $observaciones = trim((string) ($data['observaciones'] ?? ''));
        $estadoTarget = in_array($data['estado'] ?? 'borrador', ['borrador', 'aprobada'], true) ? $data['estado'] : 'borrador';
        $rawItems = $data['items'] ?? [];

        if ($justificacion === '') {
            $this->errorResponse('La justificación o motivo operativo del despacho es obligatoria.', 422);
        }
        if (mb_strlen($justificacion, 'UTF-8') < 10) {
            $this->errorResponse('La justificación debe tener al menos 10 caracteres descriptivos.', 422);
        }
        if (!is_array($rawItems) || count($rawItems) === 0) {
            $this->errorResponse('Debe incluir al menos un producto a despachar.', 422);
        }

        try {
            $conn->beginTransaction();
            $conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

            // 1. Consolidar ítems duplicados por producto_id (suma cantidades y concatena observaciones para PDF limpio)
            $itemsConsolidados = [];
            foreach ($rawItems as $idx => $it) {
                $pId = (int) ($it['producto_id'] ?? 0);
                $cant = round((float) ($it['cantidad_solicitada'] ?? 0), 3);
                $obsItem = trim((string) ($it['observaciones'] ?? ''));

                if ($pId <= 0) {
                    throw new \Exception("📌 DIAGNÓSTICO: Producto no seleccionado. 💡 DETALLE: El renglón #" . ($idx + 1) . " no tiene un código de producto válido. 🔧 ACCIÓN REQUERIDA: Seleccione un producto del catálogo para cada renglón de la orden.");
                }
                if ($cant < 0.001 || $cant > 9999999.999) {
                    throw new \Exception("📌 DIAGNÓSTICO: Cantidad fuera de rango. 💡 DETALLE: La cantidad ingresada ({$cant}) en el renglón #" . ($idx + 1) . " está fuera del rango permitido (0.001 a 9,999,999.999). 🔧 ACCIÓN REQUERIDA: Ingrese una cantidad válida para el insumo.");
                }

                if (!isset($itemsConsolidados[$pId])) {
                    $itemsConsolidados[$pId] = [
                        'producto_id' => $pId,
                        'cantidad_solicitada' => $cant,
                        'observaciones' => $obsItem,
                    ];
                } else {
                    $itemsConsolidados[$pId]['cantidad_solicitada'] += $cant;
                    if ($obsItem !== '') {
                        $prevObs = $itemsConsolidados[$pId]['observaciones'];
                        $itemsConsolidados[$pId]['observaciones'] = $prevObs !== '' ? "{$prevObs}; {$obsItem}" : $obsItem;
                    }
                }
            }

            // 2. Bloqueo pesimista centralizado en lote vía StockService::lockProductsForUpdate (ORDER BY id ASC FOR UPDATE)
            $productIds = array_keys($itemsConsolidados);
            $productosMap = StockService::lockProductsForUpdate($conn, $productIds);

            $totalArticulos = 0.0;
            // 3. Validar disponibilidad y regla de unidades discretas/indivisibles sobre las cantidades consolidadas
            foreach ($itemsConsolidados as $pId => $it) {
                if (!isset($productosMap[$pId])) {
                    throw new \Exception("📌 DIAGNÓSTICO: Producto no encontrado. 💡 DETALLE: El producto con ID #{$pId} no existe en el catálogo. 🔧 ACCIÓN REQUERIDA: Refresque el catálogo de productos y vuelva a seleccionar el insumo.");
                }

                $cantTotal = round((float) $it['cantidad_solicitada'], 3);
                $prod = $productosMap[$pId];
                $permiteDecimales = (int) ($prod['permite_decimales'] ?? 1) === 1;

                if (!$permiteDecimales && abs($cantTotal - round($cantTotal)) > 0.0001) {
                    $um = $prod['unidad_medida'] ?? 'UNID';
                    throw new \Exception("📌 DIAGNÓSTICO: Producto indivisible con valores decimales. 💡 DETALLE: El producto '{$prod['nombre']}' se administra en unidades enteras ({$um}) y no acepta valores fraccionados ({$cantTotal}). 🔧 ACCIÓN REQUERIDA: Ingrese una cantidad entera (ej. " . (int)round($cantTotal) . ") sin comas ni decimales.");
                }

                $existencias = round((float) $prod['existencias'], 3);
                $reservado = round((float) $prod['stock_reservado'], 3);
                $disponible = round(max(0.0, $existencias - $reservado), 3);

                if ($cantTotal > $disponible) {
                    $um = $prod['unidad_medida'] ?? 'UNID';
                    throw new \Exception("📌 DIAGNÓSTICO: Stock disponible insuficiente para '{$prod['nombre']}'. 💡 DETALLE: Stock físico actual: {$existencias} {$um}, Reservado por otras órdenes: {$reservado} {$um}, Disponible real: {$disponible} {$um}. Cantidad solicitada: {$cantTotal} {$um}. 🔧 ACCIÓN REQUERIDA: Ajuste la cantidad a máximo {$disponible} {$um} o espere el registro de un nuevo ingreso de mercancía.");
                }

                if ($estadoTarget === 'aprobada') {
                    $nuevoReservado = round($reservado + $cantTotal, 3);
                    $stmtRes = $conn->prepare("UPDATE productos SET stock_reservado = ? WHERE id = ?");
                    $stmtRes->execute([$nuevoReservado, $pId]);
                }

                $totalArticulos += $cantTotal;
            }

            // 4. Generar el correlativo oficial (ODE) sólo para Aprobadas, o prefijo Borrador (BORR) para pre-órdenes
            $prefixSecuencia = ($estadoTarget === 'aprobada') ? 'ODE' : 'BORR';
            $numeroOrden = $this->generarCorrelativo($conn, $prefixSecuencia);

            $fechaOrdenNow = date('Y-m-d H:i:s');
            $stmtHead = $conn->prepare("
                INSERT INTO ordenes_entrega (
                    numero_orden, departamento_id, centro_costo_id, solicitante_id, usuario_despacho_id,
                    fecha_orden, estado, tipo_destino, justificacion, observaciones, total_articulos
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtHead->execute([
                $numeroOrden,
                $departamentoId,
                $centroCostoId,
                $solicitanteId,
                $usuarioId,
                $fechaOrdenNow,
                $estadoTarget,
                $tipoDestino,
                $justificacion,
                $observaciones,
                $totalArticulos,
            ]);

            $ordenId = (int) $conn->lastInsertId();

            // 5. Inserción limpia de ítems consolidados (1 sola fila por producto en BD y PDF)
            $stmtItem = $conn->prepare("
                INSERT INTO orden_entrega_items (
                    orden_entrega_id, producto_id, cantidad_solicitada, observaciones
                ) VALUES (?, ?, ?, ?)
            ");
            foreach ($itemsConsolidados as $it) {
                $stmtItem->execute([
                    $ordenId,
                    $it['producto_id'],
                    $it['cantidad_solicitada'],
                    $it['observaciones'],
                ]);
            }

            // Hash SHA-256 inicial canónico concatenando ítems
            $hashVal = $this->generarHashVerificacion($conn, $ordenId, $numeroOrden, $fechaOrdenNow, 0.0000, $usuarioId);
            $stmtHash = $conn->prepare("UPDATE ordenes_entrega SET hash_verificacion = ? WHERE id = ?");
            $stmtHash->execute([$hashVal, $ordenId]);

            // Registrar Bitácora de Auditoría
            $this->registrarAuditoriaForense($conn, $ordenId, $usuarioId, 'creacion', [
                'numero_orden' => $numeroOrden,
                'estado' => $estadoTarget,
                'total_articulos' => $totalArticulos,
                'hash' => $hashVal,
                'cambios' => ["Creación inicial de la orden de entrega en estado '{$estadoTarget}' con {$totalArticulos} unidades solicitadas."],
            ]);

            $conn->commit();

            $this->jsonResponse([
                'id' => $ordenId,
                'numero_orden' => $numeroOrden,
                'estado' => $estadoTarget,
                'hash_verificacion' => $hashVal,
            ], 201, "Orden de entrega {$numeroOrden} registrada con éxito.");
        } catch (Throwable $e) {
            $this->handleConcurrencyException($e, $conn, 'registrar orden de entrega');
        } finally {
            StockService::restoreLockWaitTimeout($conn);
        }
    }

    /**
     * PUT|POST /api/inventario/ordenes-entrega/{id}
     * Edita una orden de entrega en estado 'borrador' o 'aprobada' (antes del despacho definitivo)
     */
    public function update(string $id): void
    {
        $ordenId = (int) $id;
        if ($ordenId <= 0) {
            $this->errorResponse('ID de orden no válido.', 400);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 1);
        $conn = \getConnection();
        $data = $this->getRequestData();

        try {
            $conn->beginTransaction();
            $conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

            $stmtCur = $conn->prepare("SELECT * FROM ordenes_entrega WHERE id = ? FOR UPDATE");
            $stmtCur->execute([$ordenId]);
            $orden = $stmtCur->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden #{$ordenId} no existe.", 404);
            }

            if (!in_array($orden['estado'], ['borrador', 'aprobada', 'reserva_vencida'], true)) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("Solo se pueden editar órdenes en estado 'borrador', 'aprobada' o 'reserva vencida'. Una orden despachada o anulada no puede modificarse.", 422);
            }

            $estadoTarget = in_array($data['estado'] ?? $orden['estado'], ['borrador', 'aprobada'], true) ? ($data['estado'] ?? $orden['estado']) : ($orden['estado'] === 'reserva_vencida' ? 'aprobada' : $orden['estado']);

            // REGLA DE ORO DE AUDITORÍA: Prohibir la degradación de aprobada (ODE-) a borrador
            if (($orden['estado'] === 'aprobada' || str_starts_with((string) $orden['numero_orden'], 'ODE-')) && $estadoTarget === 'borrador') {
                if (!isset($data['estado'])) {
                    $estadoTarget = 'aprobada';
                } else {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("Una orden aprobada con numeración oficial ODE- no puede degradarse a borrador. Para revertir la aprobación debe ser anulada formalmente.", 422);
                }
            }

            $tipoDestino = in_array($data['tipo_destino'] ?? $orden['tipo_destino'], ['departamento', 'empleado', 'evento', 'merma_baja'], true)
                ? ($data['tipo_destino'] ?? $orden['tipo_destino'])
                : 'departamento';
            $departamentoId = isset($data['departamento_id']) && (int) $data['departamento_id'] > 0 ? (int) $data['departamento_id'] : null;
            $centroCostoId = isset($data['centro_costo_id']) && (int) $data['centro_costo_id'] > 0 ? (int) $data['centro_costo_id'] : null;
            $solicitanteId = isset($data['solicitante_id']) && (int) $data['solicitante_id'] > 0 ? (int) $data['solicitante_id'] : $usuarioId;
            $justificacion = trim((string) ($data['justificacion'] ?? $orden['justificacion']));
            $observaciones = trim((string) ($data['observaciones'] ?? $orden['observaciones']));
            $rawItems = $data['items'] ?? null;

            if ($justificacion === '') {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse('La justificación o motivo operativo del despacho es obligatoria.', 422);
            }

            $estadoAnterior = $orden['estado'];
            $itemsModificados = ($rawItems !== null && is_array($rawItems));

            // 1. Obtener demanda consolidada en BD existente
            $stmtOldItems = $conn->prepare("SELECT producto_id, cantidad_solicitada, observaciones FROM orden_entrega_items WHERE orden_entrega_id = ?");
            $stmtOldItems->execute([$ordenId]);
            $existingDbItems = $stmtOldItems->fetchAll(PDO::FETCH_ASSOC);

            $oldReservasPorProducto = [];
            foreach ($existingDbItems as $oldIt) {
                $pId = (int) $oldIt['producto_id'];
                $cOld = (float) $oldIt['cantidad_solicitada'];
                if ($pId > 0 && $cOld > 0) {
                    $oldReservasPorProducto[$pId] = ($oldReservasPorProducto[$pId] ?? 0.0) + $cOld;
                }
            }

            // 2. Consolidar nuevos ítems enviados por payload (si aplica)
            $newItemsConsolidados = [];
            $newReservasPorProducto = [];

            if ($itemsModificados) {
                foreach ($rawItems as $idx => $it) {
                    $pId = (int) ($it['producto_id'] ?? 0);
                    $cant = round((float) ($it['cantidad_solicitada'] ?? 0), 3);
                    $obsItem = trim((string) ($it['observaciones'] ?? ''));

                    if ($pId <= 0 || $cant < 0.001 || $cant > 9999999.999) {
                        throw new \Exception("Ítem #" . ($idx + 1) . " no es válido o su cantidad solicitada debe estar entre 0.001 y 9,999,999.999 unidades.");
                    }

                    if (!isset($newItemsConsolidados[$pId])) {
                        $newItemsConsolidados[$pId] = [
                            'producto_id' => $pId,
                            'cantidad_solicitada' => $cant,
                            'observaciones' => $obsItem,
                        ];
                    } else {
                        $newItemsConsolidados[$pId]['cantidad_solicitada'] += $cant;
                        if ($obsItem !== '') {
                            $prevObs = $newItemsConsolidados[$pId]['observaciones'];
                            $newItemsConsolidados[$pId]['observaciones'] = $prevObs !== '' ? "{$prevObs}; {$obsItem}" : $obsItem;
                        }
                    }
                    $newReservasPorProducto[$pId] = ($newReservasPorProducto[$pId] ?? 0.0) + $cant;
                }
            } else {
                $newReservasPorProducto = $oldReservasPorProducto;
            }

            // 3. Bloqueo pesimista centralizado en lote vía StockService::lockProductsForUpdate (ORDER BY id ASC FOR UPDATE)
            $allProductIds = array_values(array_unique(array_merge(
                array_keys($oldReservasPorProducto),
                array_keys($newReservasPorProducto)
            )));
            $productosMap = StockService::lockProductsForUpdate($conn, $allProductIds);

            // Validar regla de unidades discretas/indivisibles en nuevos ítems
            if ($itemsModificados) {
                foreach ($newItemsConsolidados as $pId => $it) {
                    if (isset($productosMap[$pId])) {
                        $prod = $productosMap[$pId];
                        $cantTotal = round((float) $it['cantidad_solicitada'], 3);
                        $permiteDecimales = (int) ($prod['permite_decimales'] ?? 1) === 1;

                        if (!$permiteDecimales && abs($cantTotal - round($cantTotal)) > 0.0001) {
                            $um = $prod['unidad_medida'] ?? 'UNID';
                            throw new \Exception("El producto '{$prod['nombre']}' se administra en unidades indivisibles ({$um}) y no acepta valores decimales ({$cantTotal}).");
                        }
                    }
                }
            }

            // MATRIZ DE REINTEGRO / LIBERACIÓN DE RESERVAS
            // Se liberan reservas previas si la orden estaba 'aprobada' y (cambió de estado o se modificaron ítems)
            if ($estadoAnterior === 'aprobada' && ($estadoTarget !== 'aprobada' || $itemsModificados)) {
                foreach ($oldReservasPorProducto as $pId => $cantOldTotal) {
                    if (isset($productosMap[$pId])) {
                        $cantOldTotal = round((float) $cantOldTotal, 3);
                        $resVal = round((float) $productosMap[$pId]['stock_reservado'], 3);
                        $nuevoRes = round(max(0.0, $resVal - $cantOldTotal), 3);
                        $conn->prepare("UPDATE productos SET stock_reservado = ? WHERE id = ?")->execute([$nuevoRes, $pId]);
                        $productosMap[$pId]['stock_reservado'] = $nuevoRes;
                    }
                }
            }

            // REEMPLAZO DE ÍTEMS EN BD (si $rawItems fue suministrado)
            $totalArticulos = round((float) ($orden['total_articulos'] ?? 0), 3);
            if ($itemsModificados) {
                $conn->prepare("DELETE FROM orden_entrega_items WHERE orden_entrega_id = ?")->execute([$ordenId]);

                $totalArticulos = 0.0;
                $stmtItem = $conn->prepare("
                    INSERT INTO orden_entrega_items (
                        orden_entrega_id, producto_id, cantidad_solicitada, observaciones
                    ) VALUES (?, ?, ?, ?)
                ");
                foreach ($newItemsConsolidados as $it) {
                    $cantItem = round((float) $it['cantidad_solicitada'], 3);
                    $stmtItem->execute([
                        $ordenId,
                        $it['producto_id'],
                        $cantItem,
                        $it['observaciones'],
                    ]);
                    $totalArticulos += $cantItem;
                }
                $totalArticulos = round($totalArticulos, 3);
            }

            // MATRIZ DE APLICACIÓN DE NUEVAS RESERVAS
            // Se aplican reservas si el estado objetivo es 'aprobada' y (cambió de estado o se modificaron ítems)
            if ($estadoTarget === 'aprobada' && ($estadoAnterior !== 'aprobada' || $itemsModificados)) {
                foreach ($newReservasPorProducto as $pId => $cantNewTotal) {
                    if (!isset($productosMap[$pId])) {
                        throw new \Exception("El producto con ID #{$pId} no existe en catálogo.");
                    }
                    $cantNewTotal = round((float) $cantNewTotal, 3);
                    $prod = $productosMap[$pId];
                    $existencias = round((float) $prod['existencias'], 3);
                    $reservadoActual = round((float) $prod['stock_reservado'], 3);
                    $disponible = round(max(0.0, $existencias - $reservadoActual), 3);

                    if ($cantNewTotal > $disponible) {
                        throw new \Exception("Stock disponible insuficiente para '{$prod['nombre']}'. Disponible (Físico - Reservado): {$disponible}, Solicitado: {$cantNewTotal}.");
                    }

                    $nuevoReservado = round($reservadoActual + $cantNewTotal, 3);
                    $conn->prepare("UPDATE productos SET stock_reservado = ? WHERE id = ?")->execute([$nuevoReservado, $pId]);
                    $productosMap[$pId]['stock_reservado'] = $nuevoReservado;
                }
            }

            // Actualizar Cabecera
            $numeroOrden = $orden['numero_orden'] ?? ($orden['numero'] ?? "ODE-{$ordenId}");
            if ($estadoTarget === 'aprobada' && ($orden['estado'] === 'borrador' || str_starts_with((string) $numeroOrden, 'BORR-'))) {
                $numeroOrden = $this->generarCorrelativo($conn, 'ODE');
            }

            $fechaOrden = $orden['fecha_orden'] ?? date('Y-m-d H:i:s');
            $costoTotalDespacho = (float) ($orden['costo_total_despacho'] ?? 0.0000);
            $hashVal = $this->generarHashVerificacion($conn, $ordenId, $numeroOrden, $fechaOrden, $costoTotalDespacho, $usuarioId);

            $stmtUpd = $conn->prepare("
                UPDATE ordenes_entrega SET
                    numero_orden = ?,
                    departamento_id = ?,
                    centro_costo_id = ?,
                    solicitante_id = ?,
                    tipo_destino = ?,
                    justificacion = ?,
                    observaciones = ?,
                    estado = ?,
                    total_articulos = ?,
                    hash_verificacion = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpd->execute([
                $numeroOrden,
                $departamentoId,
                $centroCostoId,
                $solicitanteId,
                $tipoDestino,
                $justificacion,
                $observaciones,
                $estadoTarget,
                $totalArticulos,
                $hashVal,
                $ordenId,
            ]);

            $cambiosDetallados = [];
            if ($orden['estado'] !== $estadoTarget) {
                $cambiosDetallados[] = "Cambio de estado: '{$orden['estado']}' -> '{$estadoTarget}'";
            }
            if ($orden['justificacion'] !== $justificacion) {
                $cambiosDetallados[] = "Justificación actualizada: '{$justificacion}'";
            }
            if ((int)($orden['departamento_id'] ?? 0) !== (int)($departamentoId ?? 0)) {
                $cambiosDetallados[] = "Cambio de departamento receptor (ID #{$departamentoId})";
            }
            if ((int)($orden['centro_costo_id'] ?? 0) !== (int)($centroCostoId ?? 0)) {
                $cambiosDetallados[] = "Cambio de centro de costo (ID #{$centroCostoId})";
            }
            if ($itemsModificados) {
                $cambiosDetallados[] = "Modificación de insumos: {$totalArticulos} unidades totales solicitadas";
            }
            if (empty($cambiosDetallados)) {
                $cambiosDetallados[] = "Edición de observaciones/metadatos de cabecera";
            }

            $accionAudit = ($orden['estado'] !== $estadoTarget) ? 'cambio_estado' : 'edicion_orden';
            $this->registrarAuditoriaForense($conn, $ordenId, $usuarioId, $accionAudit, [
                'numero_orden' => $numeroOrden,
                'estado_anterior' => $orden['estado'],
                'estado_nuevo' => $estadoTarget,
                'total_articulos' => $totalArticulos,
                'cambios' => $cambiosDetallados,
            ]);

            $conn->commit();

            $this->jsonResponse([
                'id' => $ordenId,
                'numero_orden' => $numeroOrden,
                'estado' => $estadoTarget,
            ], 200, "Orden de entrega {$numeroOrden} actualizada correctamente.");
        } catch (Throwable $e) {
            $this->handleConcurrencyException($e, $conn, 'actualizar orden de entrega');
        } finally {
            StockService::restoreLockWaitTimeout($conn);
        }
    }

    /**
     * POST /api/inventario/ordenes-entrega/{id}/despachar
     * Despacho Atómico con Sello CPP, Actualización de SHA-256 y Registro Auditoría
     */
    public function despachar(string $id): void
    {
        $ordenId = (int) $id;
        if ($ordenId <= 0) {
            $this->errorResponse('ID de orden no válido.', 400);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 1);
        $conn = \getConnection();
        $data = $this->getRequestData();
        $cantidadesInput = $data['cantidades_despacho'] ?? null;

        try {
            $conn->beginTransaction();
            $conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

            $stmtHead = $conn->prepare("SELECT * FROM ordenes_entrega WHERE id = ? FOR UPDATE");
            $stmtHead->execute([$ordenId]);
            $orden = $stmtHead->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden de entrega #{$ordenId} no existe.", 404);
            }

            // VALIDACIÓN PRE-FLIGHT: Período Contable Abierto (Fail-Closed en <5ms antes de bloqueos de productos)
            \App\Services\ContabilidadDespachoService::validarPeriodoContableAbierto($conn, $orden['fecha_orden'] ?? date('Y-m-d H:i:s'));

            if (in_array($orden['estado'], ['despachada', 'anulada', 'reserva_vencida'], true)) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden {$orden['numero_orden']} ya se encuentra en estado final '{$orden['estado']}'. Acción bloqueada.", 422);
            }

            // BLINDAJE DE PROMOCIÓN: La orden debe estar aprobada o ser promovida atómicamente a ODE-
            if ($orden['estado'] !== 'aprobada' && !str_starts_with((string) $orden['numero_orden'], 'ODE-')) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden de entrega debe estar formalmente aprobada con numeración oficial ODE- antes de proceder al despacho físico.", 422);
            }

            // Promoción defensiva: si mantenía número BORR-, se genera y sella el correlativo inmutable ODE- y se recalcula el SHA-256
            $numeroOrden = $orden['numero_orden'];
            if (str_starts_with((string) $numeroOrden, 'BORR-')) {
                $numeroOrden = $this->generarCorrelativo($conn, 'ODE');
                $fechaOrdenNow = $orden['fecha_orden'] ?? date('Y-m-d H:i:s');
                $costoTotalNow = (float) ($orden['costo_total_despacho'] ?? 0.0000);
                $nuevoHash = $this->generarHashVerificacion($conn, $ordenId, $numeroOrden, $fechaOrdenNow, $costoTotalNow, $usuarioId);

                $conn->prepare("UPDATE ordenes_entrega SET numero_orden = ?, hash_verificacion = ? WHERE id = ?")
                     ->execute([$numeroOrden, $nuevoHash, $ordenId]);

                $orden['numero_orden'] = $numeroOrden;
                $orden['hash_verificacion'] = $nuevoHash;
            }

            $stmtItems = $conn->prepare("SELECT * FROM orden_entrega_items WHERE orden_entrega_id = ? ORDER BY producto_id ASC");
            $stmtItems->execute([$ordenId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden no contiene ítems para despachar.", 422);
            }

            // Bloqueo pesimista centralizado vía StockService::lockProductsForUpdate (SORT_NUMERIC ascendente)
            $productIdsToLock = array_values(array_unique(array_map(fn($it) => (int) $it['producto_id'], $items)));
            $productosMap = StockService::lockProductsForUpdate($conn, $productIdsToLock);

            $algunIncompleto = false;
            $stmtUpdateItem = $conn->prepare("
                UPDATE orden_entrega_items
                SET cantidad_despachada = ?,
                    costo_unitario = ?,
                    costo_total = ?
                WHERE id = ?
            ");

            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $prodId = (int) $item['producto_id'];
                $cantSolicitada = round((float) $item['cantidad_solicitada'], 3);
                $cantYaDespachada = round((float) $item['cantidad_despachada'], 3);
                $cantPendiente = round(max(0.0, $cantSolicitada - $cantYaDespachada), 3);

                if ($cantPendiente <= 0) continue;

                $cantADespachar = (is_array($cantidadesInput) && isset($cantidadesInput[$itemId]))
                    ? round((float) $cantidadesInput[$itemId], 3)
                    : $cantPendiente;

                if ($cantADespachar <= 0) {
                    $algunIncompleto = true;
                    continue;
                }

                if ($cantADespachar > $cantPendiente) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("La cantidad a despachar ({$cantADespachar}) excede el saldo pendiente ({$cantPendiente}).", 422);
                }

                if (!isset($productosMap[$prodId])) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("El producto ID #{$prodId} no existe en catálogo.", 422);
                }
                $prod = $productosMap[$prodId];

                $stockFisico = round((float) $prod['existencias'], 3);
                $stockReservado = round((float) $prod['stock_reservado'], 3);
                $costoUnitarioCPP = (float) ($prod['costo'] ?? $prod['costo_promedio'] ?? $prod['precio'] ?? 0.0000);

                if ($stockFisico < $cantADespachar) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $this->errorResponse("Stock físico insuficiente para '{$prod['nombre']}'. Disponible en estante: {$stockFisico}, Solicitado entregar: {$cantADespachar}.", 422);
                }

                $nuevoStockReservado = round(max(0.0, $stockReservado - $cantADespachar), 3);
                $stmtUpdRes = $conn->prepare("UPDATE productos SET stock_reservado = ? WHERE id = ?");
                $stmtUpdRes->execute([$nuevoStockReservado, $prodId]);

                $razon = "[Despacho Interno Almacén] Orden: {$orden['numero_orden']}";
                StockService::mutarStock(
                    $conn,
                    $prodId,
                    $cantADespachar,
                    'salida',
                    $razon,
                    null,
                    $ordenId,
                    $usuarioId,
                    null,
                    'despacho_interno',
                    "Acta {$orden['numero_orden']}"
                );

                $nuevaCantDespachada = $cantYaDespachada + $cantADespachar;
                $costoTotalLinea = round($nuevaCantDespachada * $costoUnitarioCPP, 4);

                $stmtUpdateItem->execute([
                    $nuevaCantDespachada,
                    $costoUnitarioCPP,
                    $costoTotalLinea,
                    $itemId,
                ]);

                if ($nuevaCantDespachada < $cantSolicitada) {
                    $algunIncompleto = true;
                }
            }

            $nuevoEstado = $algunIncompleto ? 'despachada_parcial' : 'despachada';

            $stmtTotals = $conn->prepare("
                SELECT COALESCE(SUM(cantidad_despachada), 0) AS total_art, COALESCE(SUM(costo_total), 0) AS costo_tot
                FROM orden_entrega_items
                WHERE orden_entrega_id = ?
            ");
            $stmtTotals->execute([$ordenId]);
            $totRes = $stmtTotals->fetch(PDO::FETCH_ASSOC);

            $costoFinal = (float) $totRes['costo_tot'];
            $hashFinal = $this->generarHashVerificacion($conn, $ordenId, $orden['numero_orden'], $orden['fecha_orden'], $costoFinal, $usuarioId);

            // FASE 3: CONTABILIZACIÓN AUTOMÁTICA PATRIMONIAL ONCOP Y AFECTACIÓN PRESUPUESTARIA ONAPRE (Aislada en Try-Catch)
            $stmtDespItems = $conn->prepare("SELECT * FROM orden_entrega_items WHERE orden_entrega_id = ? AND cantidad_despachada > 0");
            $stmtDespItems->execute([$ordenId]);
            $despItemsData = $stmtDespItems->fetchAll(PDO::FETCH_ASSOC);

            $asientoId = null;
            try {
                if (class_exists('\App\Services\ContabilidadDespachoService')) {
                    $asientoId = \App\Services\ContabilidadDespachoService::generarAsientoDespacho($conn, $orden, $despItemsData, $usuarioId);
                }
            } catch (\Throwable $eAsiento) {
                error_log("Aviso: Contabilización ONCOP omitida en despacho: " . $eAsiento->getMessage());
            }

            $setPairs = [
                "numero_orden = ?",
                "estado = ?",
                "total_articulos = ?",
                "costo_total_despacho = ?",
                "hash_verificacion = ?",
                "updated_at = NOW()"
            ];
            $updVals = [
                $orden['numero_orden'],
                $nuevoEstado,
                (float) $totRes['total_art'],
                $costoFinal,
                $hashFinal
            ];

            if ($this->tieneColumna('ordenes_entrega', 'usuario_despacho_id')) {
                $setPairs[] = "usuario_despacho_id = ?";
                $updVals[] = $usuarioId;
            }
            if ($this->tieneColumna('ordenes_entrega', 'usuario_id')) {
                $setPairs[] = "usuario_id = ?";
                $updVals[] = $usuarioId;
            }
            if ($asientoId && $this->tieneColumna('ordenes_entrega', 'asiento_id')) {
                $setPairs[] = "asiento_id = ?";
                $updVals[] = $asientoId;
            }

            $updVals[] = $ordenId; // WHERE id = ?

            $sqlUpdateHead = "UPDATE ordenes_entrega SET " . implode(', ', $setPairs) . " WHERE id = ?";
            $stmtUpdateHead = $conn->prepare($sqlUpdateHead);
            $stmtUpdateHead->execute($updVals);

            // Vincular asiento contable a movimientos de inventario del despacho
            if ($asientoId && $this->tieneColumna('movimientos_inventario', 'asiento_id')) {
                try {
                    $conn->prepare("UPDATE movimientos_inventario SET asiento_id = ? WHERE orden_entrega_id = ? AND asiento_id IS NULL")->execute([$asientoId, $ordenId]);
                } catch (\Throwable $eMov) {}
            }

            $artVal = (float) $totRes['total_art'];
            $artStr = ($artVal == round($artVal)) ? (string) (int) $artVal : number_format($artVal, 3, ',', '.');

            $this->registrarAuditoriaForense($conn, $ordenId, $usuarioId, 'despacho', [
                'estado_nuevo' => $nuevoEstado,
                'costo_total' => $costoFinal,
                'hash_sha256' => $hashFinal,
                'asiento_id' => $asientoId,
                'cambios' => [
                    "Despacho físico procesado: {$artStr} unidad(es) entregada(s) de almacén",
                    "Valoración del despacho: Bs. " . number_format($costoFinal, 2, ',', '.'),
                    $asientoId ? "Asiento Contable ONCOP generado N° {$asientoId}" : "Sin afectación contable previa",
                ],
            ]);

            $conn->commit();

            $this->jsonResponse([
                'id' => $ordenId,
                'numero_orden' => $orden['numero_orden'],
                'estado' => $nuevoEstado,
                'costo_total_despacho' => $costoFinal,
                'hash_verificacion' => $hashFinal,
            ], 200, "Despacho de almacén para la Orden {$orden['numero_orden']} procesado exitosamente (Estado: {$nuevoEstado}).");
        } catch (Throwable $e) {
            $this->handleConcurrencyException($e, $conn, 'despachar orden de entrega');
        } finally {
            StockService::restoreLockWaitTimeout($conn);
        }
    }

    /**
     * POST /api/inventario/ordenes-entrega/{id}/devolucion
     * Sub-Flujo de Devolución Parcial (RMA): Reingreso de Insumos al Costo CPP Histórico
     */
    public function devolucion(string $id): void
    {
        $ordenId = (int) $id;
        if ($ordenId <= 0) {
            $this->errorResponse('ID de orden no válido.', 400);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 1);
        $conn = \getConnection();
        $data = $this->getRequestData();

        $motivo = trim((string) ($data['motivo'] ?? ''));
        $rawItemsDev = $data['items'] ?? [];

        if ($motivo === '') {
            $this->errorResponse('El motivo o justificación de la devolución física es obligatorio.', 422);
        }
        if (!is_array($rawItemsDev) || count($rawItemsDev) === 0) {
            $this->errorResponse('Debe seleccionar al menos un producto a devolver.', 422);
        }

        try {
            $conn->beginTransaction();
            $conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

            $stmtHead = $conn->prepare("SELECT * FROM ordenes_entrega WHERE id = ? FOR UPDATE");
            $stmtHead->execute([$ordenId]);
            $orden = $stmtHead->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden de entrega #{$ordenId} no existe.", 404);
            }

            if (!in_array($orden['estado'], ['despachada', 'despachada_parcial'], true)) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("Solo se aceptan devoluciones físicas de órdenes despachadas.", 422);
            }

            // VALIDACIÓN PRE-FLIGHT: Período Contable Abierto (Fail-Closed en <5ms antes de bloqueos de productos)
            \App\Services\ContabilidadDespachoService::validarPeriodoContableAbierto($conn, date('Y-m-d H:i:s'));

            $numeroDevolucion = $this->generarCorrelativo($conn, 'DEV');
            $costoTotalDevuelto = 0.0;

            $stmtInsDevHead = $conn->prepare("
                INSERT INTO orden_entrega_devoluciones (
                    numero_devolucion, orden_entrega_id, usuario_recibe_id, fecha_devolucion, motivo, costo_total_devuelto
                ) VALUES (?, ?, ?, NOW(), ?, 0.0000)
            ");
            $stmtInsDevHead->execute([
                $numeroDevolucion,
                $ordenId,
                $usuarioId,
                $motivo,
            ]);
            $devolucionId = (int) $conn->lastInsertId();

            $stmtAllOdeItems = $conn->prepare("SELECT * FROM orden_entrega_items WHERE orden_entrega_id = ?");
            $stmtAllOdeItems->execute([$ordenId]);
            $allOdeItemsMap = [];
            foreach ($stmtAllOdeItems->fetchAll(PDO::FETCH_ASSOC) as $itRow) {
                $allOdeItemsMap[(int) $itRow['id']] = $itRow;
            }

            $productIdsToLock = array_values(array_unique(array_map(fn($it) => (int) $it['producto_id'], $allOdeItemsMap)));
            $productosMap = StockService::lockProductsForUpdate($conn, $productIdsToLock);

            $stmtInsDevItem = $conn->prepare("
                INSERT INTO orden_entrega_devolucion_items (
                    devolucion_id, orden_entrega_item_id, producto_id, cantidad_devuelta, costo_unitario, costo_total, observaciones
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($rawItemsDev as $itDev) {
                $odeItemId = (int) ($itDev['orden_entrega_item_id'] ?? 0);
                $cantDev = round((float) ($itDev['cantidad_devuelta'] ?? 0), 3);
                $obsDev = trim((string) ($itDev['observaciones'] ?? ''));

                if ($odeItemId <= 0 || $cantDev <= 0) continue;

                if (!isset($allOdeItemsMap[$odeItemId])) {
                    throw new \Exception("El ítem de orden #{$odeItemId} no pertenece a esta orden.");
                }
                $itemOde = $allOdeItemsMap[$odeItemId];

                $cantDespachada = round((float) $itemOde['cantidad_despachada'], 3);
                $cantDevueltaPrev = round((float) $itemOde['cantidad_devuelta'], 3);
                $maxDevolvible = round(max(0.0, $cantDespachada - $cantDevueltaPrev), 3);

                if ($cantDev > $maxDevolvible) {
                    throw new \Exception("La cantidad a devolver ({$cantDev}) supera el máximo permitido a devolver ({$maxDevolvible}).");
                }

                $prodId = (int) $itemOde['producto_id'];
                $prod = $productosMap[$prodId] ?? null;

                if ($prod) {
                    $permiteDecimales = (int) ($prod['permite_decimales'] ?? 1) === 1;
                    if (!$permiteDecimales && abs($cantDev - round($cantDev)) > 0.0001) {
                        throw new \Exception("No se pueden procesar devoluciones fraccionarias ({$cantDev}) para el producto discreto '{$prod['nombre']}'.");
                    }
                }

                $costoHistoricoCPP = (float) $itemOde['costo_unitario'];
                $costoTotalDevItem = round($cantDev * $costoHistoricoCPP, 4);

                // Reingreso Físico al Inventario con Recálculo Exacto de CPP Ponderado
                StockService::mutarStock(
                    $conn,
                    $prodId,
                    $cantDev,
                    'entrada',
                    "[Devolución RMA] Orden {$orden['numero_orden']} - Nota {$numeroDevolucion}",
                    null,
                    $ordenId,
                    $usuarioId,
                    $costoHistoricoCPP,
                    'devolucion_departamento',
                    "Devolución {$numeroDevolucion}"
                );

                $stmtInsDevItem->execute([
                    $devolucionId,
                    $odeItemId,
                    $prodId,
                    $cantDev,
                    $costoHistoricoCPP,
                    $costoTotalDevItem,
                    $obsDev,
                ]);

                $stmtUpdOdeItem->execute([$cantDev, $odeItemId]);
                $costoTotalDevuelto += $costoTotalDevItem;
            }

            // FASE 3: CONTABILIZACIÓN AUTOMÁTICA DE REVERSIÓN RMA ONCOP
            $stmtDevItemsData = $conn->prepare("SELECT * FROM orden_entrega_devolucion_items WHERE devolucion_id = ?");
            $stmtDevItemsData->execute([$devolucionId]);
            $rawDevItems = $stmtDevItemsData->fetchAll(PDO::FETCH_ASSOC);

            $asientoDevId = \App\Services\ContabilidadDespachoService::generarAsientoDevolucion($conn, $orden, $devolucionId, $rawDevItems, $usuarioId);

            // Actualizar Total y Asiento en la Cabecera de la Devolución
            $stmtUpdDevTot = $conn->prepare("UPDATE orden_entrega_devoluciones SET costo_total_devuelto = ?, asiento_id = ? WHERE id = ?");
            $stmtUpdDevTot->execute([$costoTotalDevuelto, $asientoDevId, $devolucionId]);

            // Auditoría Forense
            $this->registrarAuditoriaForense($conn, $ordenId, $usuarioId, 'devolucion_parcial', [
                'numero_devolucion' => $numeroDevolucion,
                'costo_devuelto' => $costoTotalDevuelto,
                'asiento_id' => $asientoDevId,
                'motivo' => $motivo,
            ]);

            $conn->commit();

            $this->jsonResponse([
                'id' => $devolucionId,
                'numero_devolucion' => $numeroDevolucion,
                'costo_total_devuelto' => $costoTotalDevuelto,
            ], 201, "Devolución física parcial {$numeroDevolucion} procesada correctamente.");
        } catch (Throwable $e) {
            $this->handleConcurrencyException($e, $conn, 'procesar devolución de entrega');
        } finally {
            StockService::restoreLockWaitTimeout($conn);
        }
    }

    /**
     * POST /api/inventario/ordenes-entrega/{id}/cancelar-reserva
     * Libera el Stock Reservado de órdenes aprobadas vencidas por inactividad
     */
    public function cancelarReserva(string $id): void
    {
        $ordenId = (int) $id;
        if ($ordenId <= 0) {
            $this->errorResponse('ID de orden no válido.', 400);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 1);
        $conn = \getConnection();

        try {
            $conn->beginTransaction();
            $conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

            $stmtHead = $conn->prepare("SELECT * FROM ordenes_entrega WHERE id = ? FOR UPDATE");
            $stmtHead->execute([$ordenId]);
            $orden = $stmtHead->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden #{$ordenId} no existe.", 404);
            }

            if ($orden['estado'] !== 'aprobada') {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("Solo se pueden cancelar reservas de órdenes en estado 'aprobada'.", 422);
            }

            $stmtItems = $conn->prepare("SELECT producto_id, cantidad_solicitada, cantidad_despachada FROM orden_entrega_items WHERE orden_entrega_id = ?");
            $stmtItems->execute([$ordenId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Bloqueo pesimista centralizado vía StockService::lockProductsForUpdate
            $productIdsToLock = array_values(array_unique(array_map(fn($it) => (int) $it['producto_id'], $items)));
            $productosMap = StockService::lockProductsForUpdate($conn, $productIdsToLock);

            foreach ($items as $item) {
                $prodId = (int) $item['producto_id'];
                $cantSolicitada = round((float) $item['cantidad_solicitada'], 3);
                $cantDespachada = round((float) $item['cantidad_despachada'], 3);
                $cantPendiente = round(max(0.0, $cantSolicitada - $cantDespachada), 3);

                if ($cantPendiente > 0 && isset($productosMap[$prodId])) {
                    $prod = $productosMap[$prodId];
                    $stockReservado = round((float) $prod['stock_reservado'], 3);
                    $nuevoReservado = round(max(0.0, $stockReservado - $cantPendiente), 3);

                    $stmtUpdRes = $conn->prepare("UPDATE productos SET stock_reservado = ? WHERE id = ?");
                    $stmtUpdRes->execute([$nuevoReservado, $prodId]);
                    $productosMap[$prodId]['stock_reservado'] = $nuevoReservado;
                }
            }

            $stmtUpdEst = $conn->prepare("UPDATE ordenes_entrega SET estado = 'reserva_vencida', updated_at = NOW() WHERE id = ?");
            $stmtUpdEst->execute([$ordenId]);

            // Sincronización Presupuestaria: Liberar compromiso o desmarcar apartado en presupuestos si estaba vinculado a requisición
            if (!empty($orden['requisicion_id'])) {
                $reqId = (int) $orden['requisicion_id'];
                $stmtUpdComp = $conn->prepare("
                    UPDATE compromisos_presupuestarios 
                    SET estado = 'anulado', 
                        observaciones = CONCAT(COALESCE(observaciones, ''), ' [Liberado por Expiración de Reserva en Orden ', ?, ']')
                    WHERE requisicion_id = ? AND estado = 'vigente'
                ");
                $stmtUpdComp->execute([$orden['numero_orden'], $reqId]);
            }

            $this->registrarAuditoriaForense($conn, $ordenId, $usuarioId, 'vencimiento_reserva', [
                'motivo' => 'Expiración de reserva y liberación de compromiso presupuestario',
                'requisicion_id' => $orden['requisicion_id'] ?? null,
            ]);

            $conn->commit();

            $this->jsonResponse([
                'id' => $ordenId,
                'numero_orden' => $orden['numero_orden'],
                'estado' => 'reserva_vencida',
            ], 200, "Reserva de stock cancelada exitosamente. Las existencias han sido liberadas.");
        } catch (Throwable $e) {
            $this->handleConcurrencyException($e, $conn, 'cancelar reserva de stock');
        } finally {
            StockService::restoreLockWaitTimeout($conn);
        }
    }

    /**
     * POST /api/inventario/ordenes-entrega/{id}/anular
     * Anulación con liberación de Stock Reservado y Reversión al Costo Histórico CPP
     */
    public function anular(string $id): void
    {
        $ordenId = (int) $id;
        if ($ordenId <= 0) {
            $this->errorResponse('ID de orden no válido.', 400);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 1);
        $conn = \getConnection();

        try {
            $conn->beginTransaction();
            $conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

            $stmtHead = $conn->prepare("SELECT * FROM ordenes_entrega WHERE id = ? FOR UPDATE");
            $stmtHead->execute([$ordenId]);
            $orden = $stmtHead->fetch(PDO::FETCH_ASSOC);

            if (!$orden) {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden de entrega #{$ordenId} no existe.", 404);
            }

            if ($orden['estado'] === 'anulada') {
                if ($conn->inTransaction()) $conn->rollBack();
                $this->errorResponse("La orden {$orden['numero_orden']} ya se encuentra anulada.", 422);
            }

            // VALIDACIÓN PRE-FLIGHT: Período Contable Abierto (Fail-Closed en <5ms antes de bloqueos de productos)
            \App\Services\ContabilidadDespachoService::validarPeriodoContableAbierto($conn, date('Y-m-d H:i:s'));

            $stmtItems = $conn->prepare("SELECT * FROM orden_entrega_items WHERE orden_entrega_id = ? ORDER BY producto_id ASC");
            $stmtItems->execute([$ordenId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Bloqueo pesimista centralizado vía StockService::lockProductsForUpdate
            $productIdsToLock = array_values(array_unique(array_map(fn($it) => (int) $it['producto_id'], $items)));
            $productosMap = StockService::lockProductsForUpdate($conn, $productIdsToLock);

            foreach ($items as $item) {
                $prodId = (int) $item['producto_id'];
                $cantSolicitada = round((float) $item['cantidad_solicitada'], 3);
                $cantDespachada = round((float) $item['cantidad_despachada'], 3);
                $costoOriginalCPP = (float) $item['costo_unitario'];

                if (isset($productosMap[$prodId])) {
                    $prod = $productosMap[$prodId];

                    if (in_array($orden['estado'], ['aprobada', 'borrador', 'despachada_parcial', 'reserva_vencida'], true)) {
                        $cantPendienteReservada = round(max(0.0, $cantSolicitada - $cantDespachada), 3);
                        if ($cantPendienteReservada > 0) {
                            $stockReservado = round((float) $prod['stock_reservado'], 3);
                            $nuevoReservado = round(max(0.0, $stockReservado - $cantPendienteReservada), 3);
                            $stmtUpdRes = $conn->prepare("UPDATE productos SET stock_reservado = ? WHERE id = ?");
                            $stmtUpdRes->execute([$nuevoReservado, $prodId]);
                            $productosMap[$prodId]['stock_reservado'] = $nuevoReservado;
                        }
                    }

                    // FÓRMULA ANTIDUPLICACIÓN DE STOCK: Deducir devoluciones parciales (RMA) previas
                    $cantDevueltaPrev = round((float) ($item['cantidad_devuelta'] ?? 0), 3);
                    $cantAReincorporar = round(max(0.0, $cantDespachada - $cantDevueltaPrev), 3);

                    if ($cantAReincorporar > 0) {
                        StockService::mutarStock(
                            $conn,
                            $prodId,
                            $cantAReincorporar,
                            'entrada',
                            "[Reversión Anulación] Orden: {$orden['numero_orden']}",
                            null,
                            $ordenId,
                            $usuarioId,
                            $costoOriginalCPP,
                            'anulacion_entrega',
                            "Anulación {$orden['numero_orden']}"
                        );
                    }
                }
            }

            // FASE 3: CONTABILIZACIÓN AUTOMÁTICA DE ANULACIÓN ONCOP (solo si la orden tuvo despachos físicos previos)
            $asientoAnulacionId = null;
            if (in_array($orden['estado'], ['despachada', 'despachada_parcial'], true)) {
                $asientoAnulacionId = \App\Services\ContabilidadDespachoService::generarAsientoAnulacion($conn, $orden, $usuarioId);
            }

            $cambiosAnulacion = ["Orden de entrega anulada formalmente."];
            if ($asientoAnulacionId) {
                $cambiosAnulacion[] = "Asiento Contable de Reversión ONCOP asentado N° {$asientoAnulacionId}";
                $cambiosAnulacion[] = "Reingreso físico de mercancía al almacén al costo histórico CPP";
            } else {
                $cambiosAnulacion[] = "Liberación de reservas de stock sin afectación contable previa";
            }

            $stmtAnular = $conn->prepare("UPDATE ordenes_entrega SET estado = 'anulada', updated_at = NOW() WHERE id = ?");
            $stmtAnular->execute([$ordenId]);

            $this->registrarAuditoriaForense($conn, $ordenId, $usuarioId, 'anulacion', [
                'numero_orden' => $orden['numero_orden'],
                'asiento_anulacion_id' => $asientoAnulacionId,
                'motivo' => 'Anulación completa de orden de entrega',
                'cambios' => $cambiosAnulacion,
            ]);

            $conn->commit();

            $this->jsonResponse([
                'id' => $ordenId,
                'numero_orden' => $orden['numero_orden'],
                'estado' => 'anulada',
            ], 200, "Orden de entrega {$orden['numero_orden']} anulada correctamente.");
        } catch (Throwable $e) {
            $this->handleConcurrencyException($e, $conn, 'anular orden de entrega');
        } finally {
            StockService::restoreLockWaitTimeout($conn);
        }
    }

    /**
     * GET /api/inventario/ordenes-entrega/{id}/pdf
     * Genera el comprobante PDF oficial / Acta de Despacho Institucional
     */
    public function pdf(string $id): void
    {
        $identifier = trim($id);
        if (empty($identifier)) {
            $this->errorResponse('Identificador de orden no válido.', 400);
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        $_GET['id'] = $identifier;
        $pdfScript = __DIR__ . '/../pdf/orden_entrega_pdf.php';
        if (!file_exists($pdfScript)) {
            $pdfScript = __DIR__ . '/../../carpetas_de_osmc/ordenes_entrega/imprimir_orden_pdf.php';
        }

        if (file_exists($pdfScript)) {
            require $pdfScript;
            exit;
        }

        $this->errorResponse('Generador PDF de Orden de Entrega no disponible.', 500);
    }

    /**
     * GET /api/inventario/departamentos
     * Listado dinámico de departamentos activos desde la base de datos MySQL
     */
    public function departamentos(): void
    {
        try {
            $conn = \getConnection();
            $stmt = $conn->query("
                SELECT id, codigo, nombre, descripcion 
                FROM departamentos 
                WHERE estado = 'activo' 
                ORDER BY nombre ASC
            ");
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = array_map(function ($row) {
                return [
                    'id' => (int) $row['id'],
                    'codigo' => $row['codigo'] ?? '',
                    'nombre' => $row['nombre'],
                    'descripcion' => $row['descripcion'] ?? '',
                ];
            }, $list);

            $this->jsonResponse(['departamentos' => $formatted]);
        } catch (Throwable $e) {
            $this->errorResponse('Error al obtener departamentos: ' . $e->getMessage(), 500);
        }
    }
}
