<?php
/**
 * FUNCIONES PARA MANEJO DE FONDOS RECIBIDOS
 * Incluye lógica para traspasos presupuestarios como fondos recibidos
 */

require_once __DIR__ . '/../config/database/database.php';

/**
 * Actualizar acumulados de fondos recibidos para un presupuesto desde un mes específico
 */
function actualizarAcumuladosFondosRecibidos($presupuesto_id, $anio, $mes_inicio) {
    $conn = getConnection();
    
    try {
        // Actualizar acumulados desde el mes de inicio hasta diciembre
        for ($mes = $mes_inicio; $mes <= 12; $mes++) {
            $query = "UPDATE ejecucion_financiera_mensual efm
                     SET fondos_recibidos_acum = (
                         SELECT COALESCE(SUM(fondos_recibidos_mes), 0)
                         FROM ejecucion_financiera_mensual efm2
                         WHERE efm2.presupuesto_id = efm.presupuesto_id
                           AND efm2.anio = efm.anio
                           AND efm2.mes <= efm.mes
                     )
                     WHERE presupuesto_id = ? AND anio = ? AND mes = ?";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([$presupuesto_id, $anio, $mes]);
        }
        
        return true;
        
    } catch (PDOException $e) {
        error_log('Error actualizando acumulados de fondos recibidos: ' . $e->getMessage());
        return false;
    }
}

/**
 * Registrar traspaso como fondos recibidos
 */
function registrarTraspasoComoFondosRecibidos($modificacion_id, $presupuesto_destino_id, $presupuesto_origen_id, $monto, $fecha_modificacion) {
    $conn = getConnection();
    
    try {
        $conn->beginTransaction();
        
        $mes = date('n', strtotime($fecha_modificacion));
        $anio = date('Y', strtotime($fecha_modificacion));
        
        // Obtener fondos acumulados del mes anterior para destino
        $mes_anterior = $mes - 1;
        $anio_anterior = $anio;
        if ($mes_anterior == 0) {
            $mes_anterior = 12;
            $anio_anterior = $anio - 1;
        }
        
        $query_acum = "SELECT COALESCE(fondos_recibidos_acum, 0) as acum_anterior 
                       FROM ejecucion_financiera_mensual 
                       WHERE presupuesto_id = ? AND anio = ? AND mes = ?";
        $stmt_acum = $conn->prepare($query_acum);
        
        // Fondos acumulados para destino
        $stmt_acum->execute([$presupuesto_destino_id, $anio_anterior, $mes_anterior]);
        $acum_anterior_destino = $stmt_acum->fetch(PDO::FETCH_ASSOC)['acum_anterior'] ?? 0;
        
        // Fondos acumulados para origen
        $stmt_acum->execute([$presupuesto_origen_id, $anio_anterior, $mes_anterior]);
        $acum_anterior_origen = $stmt_acum->fetch(PDO::FETCH_ASSOC)['acum_anterior'] ?? 0;
        
        // Insertar/actualizar fondos recibidos para partida destino (entrada positiva)
        $query_fondos = "INSERT INTO ejecucion_financiera_mensual 
                         (presupuesto_id, anio, mes, fondos_recibidos_mes, fondos_recibidos_acum, fecha_actualizacion)
                         VALUES (?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE
                         fondos_recibidos_mes = fondos_recibidos_mes + VALUES(fondos_recibidos_mes),
                         fondos_recibidos_acum = VALUES(fondos_recibidos_acum),
                         fecha_actualizacion = NOW()";
        
        $stmt_fondos = $conn->prepare($query_fondos);
        
        // Registrar entrada en destino
        $stmt_fondos->execute([
            $presupuesto_destino_id, 
            $anio, 
            $mes, 
            $monto, 
            $acum_anterior_destino + $monto
        ]);
        
        // Registrar salida en origen (negativo)
        $stmt_fondos->execute([
            $presupuesto_origen_id, 
            $anio, 
            $mes, 
            -$monto, 
            $acum_anterior_origen - $monto
        ]);
        
        // Actualizar acumulados para meses siguientes
        actualizarAcumuladosFondosRecibidos($presupuesto_destino_id, $anio, $mes + 1);
        actualizarAcumuladosFondosRecibidos($presupuesto_origen_id, $anio, $mes + 1);
        
        // Registrar en auditoría
        $query_auditoria = "INSERT INTO auditoria_fondos_recibidos 
                           (modificacion_id, presupuesto_destino_id, presupuesto_origen_id, 
                            monto, mes, anio, tipo_movimiento)
                           VALUES 
                           (?, ?, ?, ?, ?, ?, 'traspaso_entrada'),
                           (?, ?, ?, ?, ?, ?, 'traspaso_salida')";
        
        $stmt_auditoria = $conn->prepare($query_auditoria);
        $stmt_auditoria->execute([
            $modificacion_id, $presupuesto_destino_id, $presupuesto_origen_id,
            $monto, $mes, $anio,
            $modificacion_id, $presupuesto_destino_id, $presupuesto_origen_id,
            $monto, $mes, $anio
        ]);
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Error registrando traspaso como fondos recibidos: ' . $e->getMessage());
        return false;
    }
}

/**
 * Obtener historial de fondos recibidos por traspasos
 */
function obtenerHistorialFondosRecibidos($presupuesto_id, $anio = null, $mes = null) {
    $conn = getConnection();
    
    try {
        $query = "SELECT 
                    afr.*,
                    m.resolucion,
                    m.fecha_modificacion,
                    m.motivo,
                    pd.codigo as codigo_destino,
                    cd.nombre as nombre_destino,
                    po.codigo as codigo_origen,
                    co.nombre as nombre_origen
                  FROM auditoria_fondos_recibidos afr
                  INNER JOIN modificaciones_presupuestarias m ON afr.modificacion_id = m.id
                  INNER JOIN presupuestos pd ON afr.presupuesto_destino_id = pd.id
                  INNER JOIN cuentas cd ON pd.cuenta_id = cd.id
                  INNER JOIN presupuestos po ON afr.presupuesto_origen_id = po.id
                  INNER JOIN cuentas co ON po.cuenta_id = co.id
                  WHERE afr.presupuesto_destino_id = ? OR afr.presupuesto_origen_id = ?";
        
        $params = [$presupuesto_id, $presupuesto_id];
        
        if ($anio) {
            $query .= " AND afr.anio = ?";
            $params[] = $anio;
        }
        
        if ($mes) {
            $query .= " AND afr.mes = ?";
            $params[] = $mes;
        }
        
        $query .= " ORDER BY afr.fecha_aplicacion DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log('Error obteniendo historial de fondos recibidos: ' . $e->getMessage());
        return [];
    }
}

/**
 * Recalcular todos los acumulados de fondos recibidos para un presupuesto
 */
function recalcularAcumuladosFondosRecibidos($presupuesto_id, $anio) {
    $conn = getConnection();
    
    try {
        $conn->beginTransaction();
        
        // Obtener todos los registros del año ordenados por mes
        $query = "SELECT mes, fondos_recibidos_mes 
                  FROM ejecucion_financiera_mensual 
                  WHERE presupuesto_id = ? AND anio = ?
                  ORDER BY mes";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([$presupuesto_id, $anio]);
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $acumulado = 0;
        
        foreach ($registros as $registro) {
            $acumulado += $registro['fondos_recibidos_mes'];
            
            $query_update = "UPDATE ejecucion_financiera_mensual 
                            SET fondos_recibidos_acum = ?, fecha_actualizacion = NOW()
                            WHERE presupuesto_id = ? AND anio = ? AND mes = ?";
            
            $stmt_update = $conn->prepare($query_update);
            $stmt_update->execute([$acumulado, $presupuesto_id, $anio, $registro['mes']]);
        }
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Error recalculando acumulados de fondos recibidos: ' . $e->getMessage());
        return false;
    }
}
?>
