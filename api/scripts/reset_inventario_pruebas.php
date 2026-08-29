<?php
declare(strict_types=1);

/**
 * Script de utilería para vaciar completamente transacciones, bitácora de auditoría,
 * productos, categorías, movimientos e historial de trazabilidad,
 * reseteando todos los IDs AUTO_INCREMENT a 1 y secuencias a 0.
 */

header('Content-Type: application/json; charset=utf-8');

$dbFiles = [
    __DIR__ . '/../../config/database/database.php',
    __DIR__ . '/../config/database/database.php',
    dirname(__DIR__, 2) . '/config/database/database.php',
];

$conn = null;
foreach ($dbFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
        if (function_exists('getConnection')) {
            $conn = getConnection();
            break;
        }
    }
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Listado de todas las tablas de datos, auditoría, historial y trazabilidad a vaciar
    $tables = [
        'auditoria',
        'orden_entrega_auditoria',
        'orden_entrega_devolucion_items',
        'orden_entrega_devoluciones',
        'orden_entrega_items',
        'ordenes_entrega',
        'solicitud_interna_historial',
        'solicitud_interna_items',
        'solicitudes_internas',
        'necesidades_procura',
        'requisicion_items',
        'requisiciones',
        'movimientos_inventario',
        'productos',
        'categorias_productos',
        'categorias'
    ];

    $cleared = [];
    foreach ($tables as $t) {
        try {
            $conn->exec("TRUNCATE TABLE `{$t}`");
            $conn->exec("ALTER TABLE `{$t}` AUTO_INCREMENT = 1");
            $cleared[] = $t;
        } catch (\Throwable $eTable) {
            try {
                $conn->exec("DELETE FROM `{$t}`");
                $conn->exec("ALTER TABLE `{$t}` AUTO_INCREMENT = 1");
                $cleared[] = $t;
            } catch (\Throwable $eDel) {
                // Si la tabla opcional no existe en la BD, se omite silenciosamente
            }
        }
    }

    // Resetear secuencias de correlativos (SI, ODE, REQ, PRD, DEV)
    try {
        $conn->exec("UPDATE secuencias_documentos SET ultimo_valor = 0");
    } catch (\Throwable $eSeq) {}

    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo json_encode([
        'success' => true,
        'message' => 'Reinicio TOTAL con bitácoras completado exitosamente. Se eliminaron transacciones, bitácoras de auditoría, trazabilidad, productos y categorías. Todos los IDs y correlativos volvieron a 1.',
        'tablas_limpiadas' => $cleared,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Error durante el reinicio: ' . $e->getMessage()
    ]);
}
