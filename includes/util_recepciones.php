<?php
/**
 * Funciones helper para el sistema de recepciones
 */

/**
 * Obtiene el estado de recepción de una requisición
 */
function obtenerEstadoRecepcion($requisicion_id) {
    $conn = getConnection();
    
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN estado_recepcion = 'completo' THEN 1 ELSE 0 END) as items_completos,
            SUM(CASE WHEN estado_recepcion = 'parcial' THEN 1 ELSE 0 END) as items_parciales,
            SUM(CASE WHEN estado_recepcion = 'pendiente' THEN 1 ELSE 0 END) as items_pendientes
        FROM v_recepciones_detalle 
        WHERE requisicion_id = ?
    ");
    $stmt->execute([$requisicion_id]);
    $resultado = $stmt->fetch();
    
    if ($resultado['items_pendientes'] == $resultado['total_items']) {
        return 'pendiente';
    } elseif ($resultado['items_completos'] == $resultado['total_items']) {
        return 'completo';
    } else {
        return 'parcial';
    }
}

/**
 * Registra la recepción de items
 */
function registrarRecepcion($requisicion_id, $items_recibidos, $usuario_id) {
    $conn = getConnection();
    
    try {
        $conn->beginTransaction();
        
        $total_procesados = 0;
        
        foreach ($items_recibidos as $item_id => $cantidad_recibida) {
            if ($cantidad_recibida <= 0) continue;
            
            // Insertar registro de recepción
            $stmt = $conn->prepare("
                INSERT INTO recepciones_items 
                (requisicion_id, requisicion_item_id, cantidad_recibida, recibido_por) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$requisicion_id, $item_id, $cantidad_recibida, $usuario_id]);
            
            $total_procesados++;
        }
        
        // Actualizar estado de requisición si está completa
        $estado_recepcion = obtenerEstadoRecepcion($requisicion_id);
        if ($estado_recepcion === 'completo') {
            $stmt = $conn->prepare("UPDATE requisiciones SET estado = 'recibida' WHERE id = ?");
            $stmt->execute([$requisicion_id]);
        }
        
        $conn->commit();
        return $total_procesados;
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}
?>