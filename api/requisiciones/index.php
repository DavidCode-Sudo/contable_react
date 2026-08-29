<?php
/**
 * API REST: Listado de requisiciones
 *
 * Endpoint: GET /api/requisiciones
 * Permite filtrar por búsqueda, estado, prioridad y rango de fechas.
 * Respuesta en formato JSON lista para consumir desde el frontend React.
 */

require_once __DIR__ . '/../../includes/cors_middleware.php';
require_once __DIR__ . '/../../includes/verificar_sesion_api.php';
require_once __DIR__ . '/../../config/database/database.php';
require_once __DIR__ . '/../../includes/funciones_contables.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido',
    ]);
    exit;
}

try {
    $conn = getConnection();

    $limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 500)) : 200;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    $estado = isset($_GET['estado']) ? trim($_GET['estado']) : '';
    $prioridad = isset($_GET['prioridad']) ? trim($_GET['prioridad']) : '';
    $fechaDesde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
    $fechaHasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
    $busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

    $where = [];
    $params = [];

    if ($estado !== '') {
        if ($estado === 'pagada') {
            $where[] = 'op.estado = :estado_op';
            $params[':estado_op'] = 'pagada';
        } else {
            $where[] = 'r.estado = :estado';
            $params[':estado'] = $estado;
        }
    }

    if ($prioridad !== '') {
        $where[] = 'r.prioridad = :prioridad';
        $params[':prioridad'] = $prioridad;
    }

    if ($fechaDesde !== '') {
        $where[] = 'r.fecha_solicitud >= :fecha_desde';
        $params[':fecha_desde'] = $fechaDesde;
    }

    if ($fechaHasta !== '') {
        $where[] = 'r.fecha_solicitud <= :fecha_hasta';
        $params[':fecha_hasta'] = $fechaHasta;
    }

    if ($busqueda !== '') {
        $where[] = '(r.numero LIKE :q_numero
            OR r.justificacion LIKE :q_justificacion
            OR u.nombre_completo LIKE :q_usuario
            OR EXISTS (
                SELECT 1 FROM requisicion_items ri
                WHERE ri.requisicion_id = r.id
                AND (
                    ri.descripcion LIKE :q_items
                    OR ri.producto_id IN (
                        SELECT p.id FROM productos p
                        WHERE p.codigo LIKE :q_prod_codigo OR p.nombre LIKE :q_prod_nombre
                    )
                )
            )
        )';

        $like = '%' . $busqueda . '%';
        $params[':q_numero'] = $like;
        $params[':q_justificacion'] = $like;
        $params[':q_usuario'] = $like;
        $params[':q_items'] = $like;
        $params[':q_prod_codigo'] = $like;
        $params[':q_prod_nombre'] = $like;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT r.*,
               u.nombre_completo AS solicitante,
               p.observaciones AS presupuesto_descripcion,
               c.codigo AS partida_codigo,
               c.nombre AS partida_nombre,
               COALESCE(prov.nombre, r.proveedor_nombre) AS proveedor_nombre,
               COALESCE(prov.ruc, r.proveedor_rif) AS proveedor_rif,
               COALESCE(prov.telefono, r.proveedor_telefono) AS proveedor_telefono,
               COALESCE(prov.email, r.proveedor_email) AS proveedor_email,
               COALESCE(prov.direccion, r.proveedor_direccion) AS proveedor_direccion,
               u1.nombre_completo AS aprobador_nivel_1,
               u2.nombre_completo AS aprobador_nivel_2,
               op.estado AS orden_pago_estado,
               op.monto AS orden_pago_monto
        FROM requisiciones r
        LEFT JOIN usuarios u ON u.id = r.solicitante_id
        LEFT JOIN presupuestos p ON r.presupuesto_id = p.id
        LEFT JOIN cuentas c ON p.cuenta_id = c.id
        LEFT JOIN proveedores prov ON r.proveedor_id = prov.id
        LEFT JOIN usuarios u1 ON r.usuario_aprobacion_1 = u1.id
        LEFT JOIN usuarios u2 ON r.usuario_aprobacion_2 = u2.id
        LEFT JOIN (
            SELECT op1.*
            FROM ordenes_pago op1
            INNER JOIN (
                SELECT requisicion_id, MAX(id) AS max_id
                FROM ordenes_pago
                GROUP BY requisicion_id
            ) op2 ON op1.id = op2.max_id AND op1.requisicion_id = op2.requisicion_id
        ) op ON r.id = op.requisicion_id
        $whereSql
        ORDER BY r.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tasaCambio = (float) obtenerTasaCambioActual();
    $items = [];
    $conteoPendientes = 0;
    $conteoAprobadas = 0;

    foreach ($registros as $row) {
        $tipo = $row['tipo_requisicion'] ?? 'compra';
        $montoPresupuestario = isset($row['monto_presupuestario']) ? (float) $row['monto_presupuestario'] : 0.0;
        $totalUsd = isset($row['total']) ? (float) $row['total'] : 0.0;
        $moneda = $row['moneda'] ?? 'USD';

        $montoTotalBs = $montoPresupuestario > 0
            ? $montoPresupuestario
            : ($moneda === 'USD' ? $totalUsd * $tasaCambio : $totalUsd);

        $items[] = [
            'id' => (int) $row['id'],
            'numero' => $row['numero'] ?? null,
            'fecha_solicitud' => $row['fecha_solicitud'],
            'fecha_requerida' => $row['fecha_requerida'],
            'estado' => $row['estado'],
            'prioridad' => $row['prioridad'],
            'tipo_requisicion' => $tipo,
            'monto_total_bs' => round($montoTotalBs, 2),
            'monto_total_usd' => round($tasaCambio > 0 ? $montoTotalBs / $tasaCambio : $totalUsd, 2),
            'moneda_original' => $moneda,
            'solicitante' => $row['solicitante'],
            'proveedor' => [
                'nombre' => $row['proveedor_nombre'],
                'rif' => $row['proveedor_rif'],
                'telefono' => $row['proveedor_telefono'],
                'email' => $row['proveedor_email'],
            ],
            'presupuesto' => [
                'id' => $row['presupuesto_id'] ? (int) $row['presupuesto_id'] : null,
                'descripcion' => $row['presupuesto_descripcion'],
                'partida' => [
                    'codigo' => $row['partida_codigo'],
                    'nombre' => $row['partida_nombre'],
                ],
            ],
            'aprobaciones' => [
                'nivel_1' => $row['aprobacion_nivel_1'] ?? 'pendiente',
                'nivel_2' => $row['aprobacion_nivel_2'] ?? 'pendiente',
                'validacion_presupuestaria' => $row['validacion_presupuestaria'] ?? 'pendiente',
            ],
            'orden_pago' => [
                'estado' => $row['orden_pago_estado'],
                'monto' => $row['orden_pago_monto'] ? (float) $row['orden_pago_monto'] : null,
            ],
            'observaciones' => [
                'publica' => $row['observaciones'],
                'interna' => $row['observaciones_internas'],
            ],
        ];

        if (in_array($row['estado'], ['borrador', 'enviada', 'pendiente_nivel_2'], true)) {
            $conteoPendientes++;
        }

        if ($row['estado'] === 'aprobada') {
            $conteoAprobadas++;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'pendientes' => $conteoPendientes,
                'aprobadas' => $conteoAprobadas,
            ],
            'tasa_cambio' => $tasaCambio,
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
            ],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    if (function_exists('logMessage')) {
        logMessage('API requisiciones error: ' . $e->getMessage(), 'ERROR', 'api.log');
    }

    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'detail' => DEBUG_MODE ? $e->getMessage() : null,
    ]);
}

