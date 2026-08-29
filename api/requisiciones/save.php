<?php
declare(strict_types=1);

/**
 * API RESTful: Crear / Guardar / Enviar Requisición (Versión Blindada ACID)
 * Endpoint: POST /api/requisiciones/save.php
 */

require_once __DIR__ . '/../../includes/cors_middleware.php';
require_once __DIR__ . '/../../includes/verificar_sesion_api.php';
require_once __DIR__ . '/../../includes/funciones_contables.php';
require_once __DIR__ . '/../../includes/util_requisiciones.php';
require_once __DIR__ . '/../../config/database/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido. Utilice POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Estructura JSON inválida o vacía.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = in_array($input['accion'] ?? 'guardar', ['guardar', 'enviar'], true) ? $input['accion'] : 'guardar';
$id = isset($input['id']) ? (int) $input['id'] : null;
$requisicionPayload = $input['requisicion'] ?? [];
$itemsPayload = $input['items'] ?? [];

$solicitanteId = $_SESSION['usuario_id'] ?? null;
if (!$solicitanteId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión de usuario no válida o expirada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. SANITIZACIÓN Y VINCULACIÓN PRESUPUESTARIA
$fechaSolicitud = trim($requisicionPayload['fecha_solicitud'] ?? date('Y-m-d'));
$fechaRequerida = trim($requisicionPayload['fecha_requerida'] ?? '');
$prioridad = in_array($requisicionPayload['prioridad'] ?? 'media', ['baja', 'media', 'alta', 'urgente'], true) ? $requisicionPayload['prioridad'] : 'media';
$moneda = in_array($requisicionPayload['moneda'] ?? 'VES', ['VES', 'USD', 'EUR'], true) ? $requisicionPayload['moneda'] : 'VES';
$tipoRequisicion = in_array($requisicionPayload['tipo_requisicion'] ?? 'compra', ['compra', 'servicio', 'inventario'], true) ? $requisicionPayload['tipo_requisicion'] : 'compra';
$observaciones = trim($requisicionPayload['observaciones'] ?? '');
$observacionesInternas = trim($requisicionPayload['observaciones_internas'] ?? '');

$presupuestoId = isset($requisicionPayload['presupuesto_id']) && (int)$requisicionPayload['presupuesto_id'] > 0
    ? (int) $requisicionPayload['presupuesto_id']
    : null;
$servicioId = isset($requisicionPayload['servicio_id']) && (int)$requisicionPayload['servicio_id'] > 0
    ? (int) $requisicionPayload['servicio_id']
    : null;
$montoPresupuestario = isset($requisicionPayload['monto_presupuestario']) ? (float) $requisicionPayload['monto_presupuestario'] : 0.0;

$proveedorPayload = $requisicionPayload['proveedor'] ?? [];
$proveedorId = isset($proveedorPayload['id']) && (int) $proveedorPayload['id'] > 0
    ? (int) $proveedorPayload['id']
    : (isset($requisicionPayload['proveedor_id']) && (int) $requisicionPayload['proveedor_id'] > 0 ? (int) $requisicionPayload['proveedor_id'] : null);

$proveedorNombre = trim((string) ($proveedorPayload['nombre'] ?? ($requisicionPayload['proveedor_nombre'] ?? '')));
$proveedorRif = trim((string) ($proveedorPayload['rif'] ?? ($requisicionPayload['proveedor_rif'] ?? '')));
$proveedorTelefono = trim((string) ($proveedorPayload['telefono'] ?? ($requisicionPayload['proveedor_telefono'] ?? '')));
$proveedorEmail = trim((string) ($proveedorPayload['email'] ?? ($requisicionPayload['proveedor_email'] ?? '')));
$proveedorDireccion = trim((string) ($proveedorPayload['direccion'] ?? ($requisicionPayload['proveedor_direccion'] ?? '')));

$errores = [];

// VALIDACIÓN DEFENSIVA PARA PROVEEDOR MANUAL Y RIF VENEZOLANO
if (!$proveedorId) {
    if ($proveedorNombre === '') {
        $errores[] = 'Debe indicar la razón social o nombre del proveedor asignado.';
    }
    if ($proveedorRif === '') {
        $errores[] = 'Debe indicar el RIF fiscal del proveedor asignado.';
    } elseif (!preg_match('/^[JVEGP]-?[0-9]{7,9}-?[0-9]$/i', $proveedorRif)) {
        $errores[] = 'El RIF del proveedor no cumple con el formato fiscal válido venezolano (ej: J-12345678-9).';
    }
} else {
    if ($proveedorRif !== '' && !preg_match('/^[JVEGP]-?[0-9]{7,9}-?[0-9]$/i', $proveedorRif)) {
        $errores[] = 'El RIF del proveedor asignado no cumple con el formato fiscal válido (ej: J-12345678-9).';
    }
}

if (!$fechaRequerida || !DateTime::createFromFormat('Y-m-d', $fechaRequerida)) {
    $errores[] = 'La fecha requerida es obligatoria (YYYY-MM-DD).';
} elseif ($fechaRequerida < $fechaSolicitud) {
    $errores[] = 'La fecha requerida no puede ser anterior a la fecha de solicitud.';
}
if ($observaciones === '') {
    $errores[] = 'La justificación u observaciones de la requisición es obligatoria.';
} elseif (mb_strlen($observaciones, 'UTF-8') < 15) {
    $errores[] = 'La justificación debe contener al menos 15 caracteres explicativos del requerimiento operacional.';
} elseif (preg_match('/^(sadasd|asdasd|qwerty|123456|test|prueba)+$/i', $observaciones)) {
    $errores[] = 'Ingrese una justificación operacional válida (evite textos de prueba o teclado aleatorio).';
}

// 2. CÁLCULO CONTABLE DE ALTA PRECISIÓN (Evita flotantes infinitos con round)
$items = [];
foreach ($itemsPayload as $index => $item) {
    $productoId = isset($item['producto_id']) && (int) $item['producto_id'] > 0 ? (int) $item['producto_id'] : null;
    $desc = trim($item['descripcion'] ?? '');
    $unid = trim($item['unidad'] ?? 'UNID');
    $cant = isset($item['cantidad']) ? (float) $item['cantidad'] : 0.0;
    $prec = isset($item['precio_unitario']) ? (float) $item['precio_unitario'] : (isset($item['precio']) ? (float) $item['precio'] : 0.0);
    $imp = isset($item['porcentaje_impuesto']) ? (float) $item['porcentaje_impuesto'] : (isset($item['impuesto']) ? (float) $item['impuesto'] : 16.0);

    if ($desc === '') continue;
    if ($cant <= 0) {
        $errores[] = "Ítem #" . ($index + 1) . ": La cantidad debe ser mayor a 0.";
        break;
    }
    $items[] = [
        'producto_id' => $productoId,
        'descripcion' => $desc,
        'unidad' => $unid,
        'cantidad' => $cant,
        'precio' => $prec,
        'impuesto' => $imp,
    ];
}

if (empty($items)) {
    $errores[] = 'Debe registrar al menos un ítem o servicio válido.';
}

if (!empty($errores)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errores), 'errors' => $errores], JSON_UNESCAPED_UNICODE);
    exit;
}

$subtotal = 0.0;
$impuestos = 0.0;
foreach ($items as &$it) {
    $st = round($it['cantidad'] * $it['precio'], 2);
    $im = round($st * ($it['impuesto'] / 100), 2);
    $it['total_linea'] = round($st + $im, 2);

    $subtotal += $st;
    $impuestos += $im;
}
unset($it);

$subtotal = round($subtotal, 2);
$impuestos = round($impuestos, 2);
$total = round($subtotal + $impuestos, 2);

$conn = getConnection();

try {
    // 3. TRANSACCIÓN ACID
    $conn->beginTransaction();

    $estadoFinal = 'borrador';
    $numeroRequisicion = null;
    $existente = null;

    if ($id) {
        $stmtCheck = $conn->prepare('SELECT estado, numero FROM requisiciones WHERE id = ? FOR UPDATE');
        $stmtCheck->execute([$id]);
        $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$existente) throw new Exception("La requisición con ID {$id} no existe.");
        if (in_array($existente['estado'], ['aprobada', 'anulada'], true)) {
            throw new Exception("No es posible modificar una requisición en estado '{$existente['estado']}'.");
        }

        $estadoFinal = $existente['estado'];
        $numeroRequisicion = $existente['numero'];
    }

    // 4. CONTROL DE MÁQUINA DE ESTADOS (Bloqueo de doble envío)
    if ($accion === 'enviar' && $existente && !in_array($existente['estado'], ['borrador', 'rechazada'], true)) {
        throw new Exception("Solo se pueden enviar a aprobación requisiciones en estado 'borrador' o 'rechazada'.");
    }

    if ($id) {
        $stmtUpd = $conn->prepare("
            UPDATE requisiciones SET
                fecha_solicitud = ?, fecha_requerida = ?, prioridad = ?, justificacion = ?,
                subtotal = ?, impuestos = ?, total = ?, moneda = ?, presupuesto_id = ?, servicio_id = ?, tipo_requisicion = ?,
                monto_presupuestario = ?, proveedor_id = ?, proveedor_nombre = ?, proveedor_rif = ?,
                proveedor_telefono = ?, proveedor_email = ?, proveedor_direccion = ?, observaciones = ?, observaciones_internas = ?
            WHERE id = ?
        ");
        $stmtUpd->execute([
            $fechaSolicitud, $fechaRequerida, $prioridad, $observaciones,
            $subtotal, $impuestos, $total, $moneda, $presupuestoId, $servicioId, $tipoRequisicion,
            $montoPresupuestario, $proveedorId, $proveedorNombre, $proveedorRif,
            $proveedorTelefono, $proveedorEmail, $proveedorDireccion, $observaciones, $observacionesInternas, $id
        ]);

        $conn->prepare('DELETE FROM requisicion_items WHERE requisicion_id = ?')->execute([$id]);
    } else {
        $stmtIns = $conn->prepare("
            INSERT INTO requisiciones (
                fecha_solicitud, fecha_requerida, solicitante_id, prioridad, justificacion,
                estado, subtotal, impuestos, total, moneda, presupuesto_id, servicio_id, tipo_requisicion,
                monto_presupuestario, proveedor_id, proveedor_nombre, proveedor_rif, proveedor_telefono,
                proveedor_email, proveedor_direccion, observaciones, observaciones_internas
            ) VALUES (?, ?, ?, ?, ?, 'borrador', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([
            $fechaSolicitud, $fechaRequerida, $solicitanteId, $prioridad, $observaciones,
            $subtotal, $impuestos, $total, $moneda, $presupuestoId, $servicioId, $tipoRequisicion,
            $montoPresupuestario, $proveedorId, $proveedorNombre, $proveedorRif, $proveedorTelefono,
            $proveedorEmail, $proveedorDireccion, $observaciones, $observacionesInternas
        ]);
        $id = (int) $conn->lastInsertId();
    }

    // Insertar detalle pre-calculado
    $stmtItem = $conn->prepare("
        INSERT INTO requisicion_items (requisicion_id, producto_id, descripcion, unidad, cantidad, precio, impuesto, total_linea)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $it) {
        $stmtItem->execute([$id, $it['producto_id'], $it['descripcion'], $it['unidad'], $it['cantidad'], $it['precio'], $it['impuesto'], $it['total_linea']]);
    }

    // Transición a 'enviada'
    if ($accion === 'enviar') {
        if (!$numeroRequisicion) $numeroRequisicion = generarNumeroRequisicion($conn);
        $estadoFinal = 'enviada';

        $stmtEnv = $conn->prepare("UPDATE requisiciones SET numero = ?, estado = 'enviada' WHERE id = ?");
        $stmtEnv->execute([$numeroRequisicion, $id]);

        $stmtHist = $conn->prepare("INSERT INTO requisicion_historial (requisicion_id, estado_desde, estado_hasta, comentario, usuario_id) VALUES (?, ?, 'enviada', 'Requisición emitida y enviada a aprobación de Dirección Ejecutiva', ?)");
        $stmtHist->execute([$id, $existente['estado'] ?? 'borrador', $solicitanteId]);
    }

    // 5. REGISTRO DE AUDITORÍA REAL DE PROXY Y NOMBRE DE COLUMNA CORREGIDO (ip_address)
    $ipReal = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (str_contains($ipReal, ',')) {
        $ipReal = trim(explode(',', $ipReal)[0]);
    }

    $stmtAudit = $conn->prepare("
        INSERT INTO auditoria (
            usuario_id, modulo, accion, detalles, ip_address
        ) VALUES (?, 'requisiciones', ?, ?, ?)
    ");
    $auditAccion = $id ? ($accion === 'enviar' ? 'enviar_requisicion' : 'actualizar_requisicion') : 'crear_requisicion';
    $auditDetalles = json_encode([
        'requisicion_id' => $id,
        'numero' => $numeroRequisicion,
        'estado' => $estadoFinal,
        'total' => $total,
        'moneda' => $moneda,
    ], JSON_UNESCAPED_UNICODE);
    $stmtAudit->execute([$solicitanteId, $auditAccion, $auditDetalles, $ipReal]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'data' => ['id' => $id, 'numero' => $numeroRequisicion, 'estado' => $estadoFinal, 'total' => $total],
        'message' => $accion === 'enviar' ? 'Requisición enviada a aprobación con éxito.' : 'Requisición guardada como borrador correctamente.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al procesar la requisición: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
