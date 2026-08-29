<?php
declare(strict_types=1);

/**
 * API RESTful: Detalle de una requisición
 * Endpoint: GET /api/requisiciones/show.php?id=123
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
        'message' => 'Método no permitido. Utilice GET.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID de requisición inválido o no especificado.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getConnection();

    // 1. Consulta de Cabecera con Joins defensivos
    $stmt = $conn->prepare("
        SELECT r.*,
               u.nombre_completo AS solicitante,
               u.email AS solicitante_email,
               p.observaciones AS presupuesto_descripcion,
               p.cuenta_id AS presupuesto_partida_id,
               c.codigo AS partida_codigo,
               c.nombre AS partida_nombre,
               prov.id AS prov_tabla_id,
               COALESCE(NULLIF(prov.nombre, ''), NULLIF(r.proveedor_nombre, '')) AS proveedor_nombre,
               COALESCE(NULLIF(prov.ruc, ''), NULLIF(r.proveedor_rif, '')) AS proveedor_rif,
               COALESCE(NULLIF(prov.telefono, ''), NULLIF(r.proveedor_telefono, '')) AS proveedor_telefono,
               COALESCE(NULLIF(prov.email, ''), NULLIF(r.proveedor_email, '')) AS proveedor_email,
               COALESCE(NULLIF(prov.direccion, ''), NULLIF(r.proveedor_direccion, '')) AS proveedor_direccion,
               u1.nombre_completo AS aprobador_nivel_1_nombre,
               u2.nombre_completo AS aprobador_nivel_2_nombre
        FROM requisiciones r
        LEFT JOIN usuarios u ON u.id = r.solicitante_id
        LEFT JOIN presupuestos p ON r.presupuesto_id = p.id
        LEFT JOIN cuentas c ON p.cuenta_id = c.id
        LEFT JOIN proveedores prov ON r.proveedor_id = prov.id AND r.proveedor_id > 0
        LEFT JOIN usuarios u1 ON r.usuario_aprobacion_1 = u1.id
        LEFT JOIN usuarios u2 ON r.usuario_aprobacion_2 = u2.id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);

    $requisicion = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$requisicion) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "Requisición #{$id} no encontrada.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Auto-curación DML de estado si se encuentra vacío o truncado
    $estadoActualRaw = trim((string)($requisicion['estado'] ?? ''));
    if ($estadoActualRaw === '' || $estadoActualRaw === '0') {
        if (($requisicion['aprobacion_nivel_1'] ?? '') === 'aprobada') {
            $requisicion['estado'] = 'pendiente_presupuesto';
            $conn->exec("UPDATE requisiciones SET estado = 'pendiente_presupuesto' WHERE id = " . (int)$requisicion['id']);
        } else {
            $requisicion['estado'] = 'enviada';
            $conn->exec("UPDATE requisiciones SET estado = 'enviada' WHERE id = " . (int)$requisicion['id']);
        }
    }

    // 2. Consulta de Ítems Detalle
    $itemsStmt = $conn->prepare("
        SELECT id, descripcion, unidad, cantidad, precio, impuesto,
               total_linea, es_producto_catalogo, producto_id,
               solicitar_catalogar, categoria_sugerida
        FROM requisicion_items
        WHERE requisicion_id = ?
        ORDER BY id ASC
    ");
    $itemsStmt->execute([$id]);
    $rawItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rawItems as $it) {
        $items[] = [
            'id' => (int) $it['id'],
            'descripcion' => $it['descripcion'],
            'unidad' => $it['unidad'],
            'cantidad' => (float) $it['cantidad'],
            'precio' => (float) $it['precio'],
            'impuesto' => (float) $it['impuesto'],
            'total_linea' => (float) $it['total_linea'],
            'es_producto_catalogo' => (bool) $it['es_producto_catalogo'],
            'producto_id' => $it['producto_id'] ? (int) $it['producto_id'] : null,
            'solicitar_catalogar' => (bool) $it['solicitar_catalogar'],
            'categoria_sugerida' => $it['categoria_sugerida'],
        ];
    }

    // 3. Consulta de Historial / Trazabilidad (Columna creada_at alias fecha)
    $historialStmt = $conn->prepare("
        SELECT h.id, h.estado_desde, h.estado_hasta, h.comentario, h.created_at AS fecha,
               u.nombre_completo AS usuario
        FROM requisicion_historial h
        LEFT JOIN usuarios u ON u.id = h.usuario_id
        WHERE h.requisicion_id = ?
        ORDER BY h.created_at DESC, h.id DESC
    ");
    $historialStmt->execute([$id]);
    $rawHistorial = $historialStmt->fetchAll(PDO::FETCH_ASSOC);

    $historial = [];
    foreach ($rawHistorial as $h) {
        $historial[] = [
            'id' => (int) $h['id'],
            'estado_desde' => $h['estado_desde'],
            'estado_hasta' => $h['estado_hasta'],
            'comentario' => $h['comentario'],
            'fecha' => $h['fecha'],
            'usuario' => $h['usuario'] ?? 'Sistema',
        ];
    }

    // 4. Consulta de Orden de Pago si existe
    $ordenStmt = $conn->prepare("
        SELECT id, numero_orden, estado, monto, fecha_orden, fecha_pago
        FROM ordenes_pago
        WHERE requisicion_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $ordenStmt->execute([$id]);
    $rawOrden = $ordenStmt->fetch(PDO::FETCH_ASSOC);

    $ordenPago = null;
    if ($rawOrden) {
        $ordenPago = [
            'id' => (int) $rawOrden['id'],
            'numero' => $rawOrden['numero_orden'],
            'estado' => $rawOrden['estado'],
            'monto' => (float) $rawOrden['monto'],
            'fecha_orden' => $rawOrden['fecha_orden'],
            'fecha_pago' => $rawOrden['fecha_pago'],
        ];
    }

    $tasaCambio = (float) obtenerTasaCambioActual();
    if ($tasaCambio <= 0) $tasaCambio = 36.5;

    $totalBs = (float) $requisicion['total'];
    $moneda = $requisicion['moneda'] ?? 'VES';
    $totalUsd = $tasaCambio > 0 ? round($totalBs / $tasaCambio, 2) : $totalBs;

    echo json_encode([
        'success' => true,
        'data' => [
            'requisicion' => [
                'id' => (int) $requisicion['id'],
                'numero' => $requisicion['numero'],
                'estado' => $requisicion['estado'],
                'prioridad' => $requisicion['prioridad'],
                'tipo_requisicion' => $requisicion['tipo_requisicion'] ?? 'compra',
                'fecha_solicitud' => $requisicion['fecha_solicitud'],
                'fecha_requerida' => $requisicion['fecha_requerida'],
                'fecha_aprobacion_1' => $requisicion['fecha_aprobacion_1'],
                'fecha_aprobacion_2' => $requisicion['fecha_aprobacion_2'],
                'justificacion' => $requisicion['justificacion'],
                'observaciones' => $requisicion['observaciones'],
                'observaciones_internas' => $requisicion['observaciones_internas'],
                'moneda' => $moneda,
                'tasa_cambio' => $tasaCambio,
                'monto_total_bs' => $totalBs,
                'monto_total_usd' => $totalUsd,
                'subtotal' => (float) $requisicion['subtotal'],
                'impuestos' => (float) $requisicion['impuestos'],
                'total' => $totalBs,
                'monto_presupuestario' => (float) ($requisicion['monto_presupuestario'] ?? 0.0),
                'validacion_presupuestaria' => $requisicion['validacion_presupuestaria'] ?? 'pendiente',
                'aprobaciones' => [
                    'nivel_1' => $requisicion['aprobacion_nivel_1'] ?? 'pendiente',
                    'nivel_2' => $requisicion['aprobacion_nivel_2'] ?? 'pendiente',
                    'aprobador_nivel_1' => $requisicion['aprobador_nivel_1_nombre'],
                    'aprobador_nivel_2' => $requisicion['aprobador_nivel_2_nombre'],
                ],
            ],
            'solicitante' => [
                'id' => $requisicion['solicitante_id'] ? (int) $requisicion['solicitante_id'] : null,
                'nombre' => $requisicion['solicitante'],
                'email' => $requisicion['solicitante_email'],
            ],
            'proveedor' => [
                'id' => $requisicion['proveedor_id'] ? (int) $requisicion['proveedor_id'] : null,
                'nombre' => $requisicion['proveedor_nombre'],
                'rif' => $requisicion['proveedor_rif'],
                'telefono' => $requisicion['proveedor_telefono'],
                'email' => $requisicion['proveedor_email'],
                'direccion' => $requisicion['proveedor_direccion'],
            ],
            'presupuesto' => [
                'id' => $requisicion['presupuesto_id'] ? (int) $requisicion['presupuesto_id'] : null,
                'descripcion' => $requisicion['presupuesto_descripcion'],
                'partida' => [
                    'id' => $requisicion['presupuesto_partida_id'] ? (int) $requisicion['presupuesto_partida_id'] : null,
                    'codigo' => $requisicion['partida_codigo'],
                    'nombre' => $requisicion['partida_nombre'],
                ],
            ],
            'items' => $items,
            'historial' => $historial,
            'orden_pago' => $ordenPago,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al consultar la requisición: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
