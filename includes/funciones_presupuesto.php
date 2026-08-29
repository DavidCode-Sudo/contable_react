<?php
/**
 * FUNCIONES PARA CONTROL PRESUPUESTARIO
 * Basado en Estado de Ejecución del Presupuesto
 */

require_once __DIR__ . '/../config/database/database.php';
require_once __DIR__ . '/funciones_contables.php';

/**
 * Obtener disponibilidad presupuestaria para una cuenta en un mes específico
 */
function obtenerDisponibilidadPresupuestaria($cuenta_id, $centro_costo_id, $periodo_id, $mes, $tipo_movimiento = 'gasto') {
    $conn = getConnection();
    
    try {
        // Obtener presupuesto base para el mes
        $sql = "SELECT 
                    CASE :mes
                        WHEN 1 THEN monto_enero
                        WHEN 2 THEN monto_febrero
                        WHEN 3 THEN monto_marzo
                        WHEN 4 THEN monto_abril
                        WHEN 5 THEN monto_mayo
                        WHEN 6 THEN monto_junio
                        WHEN 7 THEN monto_julio
                        WHEN 8 THEN monto_agosto
                        WHEN 9 THEN monto_septiembre
                        WHEN 10 THEN monto_octubre
                        WHEN 11 THEN monto_noviembre
                        WHEN 12 THEN monto_diciembre
                    END as presupuesto_base,
                    id as presupuesto_id
                FROM presupuestos 
                WHERE cuenta_id = :cuenta_id 
                AND periodo_id = :periodo_id 
                AND tipo_movimiento = :tipo_movimiento";
        
        if ($centro_costo_id) {
            $sql .= " AND centro_costo_id = :centro_costo_id";
        } else {
            $sql .= " AND centro_costo_id IS NULL";
        }
        
        $stmt = $conn->prepare($sql);
        $params = [
            ':cuenta_id' => $cuenta_id,
            ':periodo_id' => $periodo_id,
            ':tipo_movimiento' => $tipo_movimiento,
            ':mes' => $mes
        ];
        
        if ($centro_costo_id) {
            $params[':centro_costo_id'] = $centro_costo_id;
        }
        
        $stmt->execute($params);
        $presupuesto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$presupuesto) {
            return [
                'disponible' => false,
                'presupuesto_vigente' => 0,
                'ejecutado' => 0,
                'disponibilidad' => 0,
                'porcentaje_ejecucion' => 0,
                'mensaje' => 'No existe presupuesto para esta cuenta en el período especificado'
            ];
        }
        
        $presupuesto_id = $presupuesto['presupuesto_id'];
        $presupuesto_base = $presupuesto['presupuesto_base'] ?? 0;
        
        // Obtener modificaciones aprobadas para el mes
        $sql_mod = "SELECT COALESCE(SUM(
                        CASE WHEN tipo_modificacion = 'aumento' THEN monto 
                             ELSE -monto 
                        END
                    ), 0) as modificaciones
                    FROM modificaciones_presupuesto 
                    WHERE presupuesto_id = :presupuesto_id 
                    AND mes = :mes 
                    AND estado = 'aprobada'";
        
        $stmt_mod = $conn->prepare($sql_mod);
        $stmt_mod->execute([
            ':presupuesto_id' => $presupuesto_id,
            ':mes' => $mes
        ]);
        $modificaciones = $stmt_mod->fetch(PDO::FETCH_ASSOC)['modificaciones'] ?? 0;
        
        // Calcular presupuesto vigente
        $presupuesto_vigente = $presupuesto_base + $modificaciones;
        
        // Obtener ejecución acumulada para el mes
        $sql_ejec = "SELECT COALESCE(SUM(monto_ejecutado), 0) as ejecutado
                     FROM ejecucion_presupuesto 
                     WHERE presupuesto_id = :presupuesto_id 
                     AND mes = :mes";
        
        $stmt_ejec = $conn->prepare($sql_ejec);
        $stmt_ejec->execute([
            ':presupuesto_id' => $presupuesto_id,
            ':mes' => $mes
        ]);
        $ejecutado = $stmt_ejec->fetch(PDO::FETCH_ASSOC)['ejecutado'] ?? 0;
        
        // Calcular disponibilidad
        $disponibilidad = $presupuesto_vigente - $ejecutado;
        $porcentaje_ejecucion = $presupuesto_vigente > 0 ? ($ejecutado / $presupuesto_vigente) * 100 : 0;
        
        return [
            'disponible' => $disponibilidad > 0,
            'presupuesto_id' => $presupuesto_id,
            'presupuesto_base' => $presupuesto_base,
            'modificaciones' => $modificaciones,
            'presupuesto_vigente' => $presupuesto_vigente,
            'ejecutado' => $ejecutado,
            'disponibilidad' => $disponibilidad,
            'porcentaje_ejecucion' => round($porcentaje_ejecucion, 2),
            'mensaje' => $disponibilidad > 0 ? 'Presupuesto disponible' : 'Presupuesto agotado'
        ];
        
    } catch (PDOException $e) {
        error_log('Error en obtenerDisponibilidadPresupuestaria: ' . $e->getMessage());
            return [
                'disponible' => false,
                'error' => 'Error al consultar disponibilidad presupuestaria'
            ];
    }
}

/**
 * Validar si una operación puede ejecutarse según el presupuesto
 */
function validarOperacionPresupuestaria($cuenta_id, $centro_costo_id, $periodo_id, $monto, $fecha_operacion, $tipo_movimiento = 'gasto') {
    $mes = date('n', strtotime($fecha_operacion)); // 1-12
    
    $disponibilidad = obtenerDisponibilidadPresupuestaria($cuenta_id, $centro_costo_id, $periodo_id, $mes, $tipo_movimiento);
    
    if (!$disponibilidad['disponible']) {
        return [
            'aprobado' => false,
            'requiere_aprobacion' => true,
            'mensaje' => $disponibilidad['mensaje'],
            'disponibilidad_actual' => $disponibilidad
        ];
    }
    
    if ($monto <= $disponibilidad['disponibilidad']) {
        return [
            'aprobado' => true,
            'requiere_aprobacion' => false,
            'mensaje' => 'Operación dentro del presupuesto disponible',
            'disponibilidad_actual' => $disponibilidad
        ];
    } else {
        $exceso = $monto - $disponibilidad['disponibilidad'];
        return [
            'aprobado' => false,
            'requiere_aprobacion' => true,
            'mensaje' => "Operación excede el presupuesto en " . formatearMoneda($exceso),
            'exceso' => $exceso,
            'disponibilidad_actual' => $disponibilidad
        ];
    }
}

/**
 * Registrar ejecución presupuestaria cuando se confirma un asiento
 */
function registrarEjecucionPresupuestaria($asiento_id, $cuenta_id, $centro_costo_id, $monto, $fecha_operacion, $tipo_movimiento = 'gasto') {
    $conn = getConnection();
    
    try {
        // Obtener período contable activo
        $periodo_id = obtenerPeriodoActivo();
        if (!$periodo_id) {
            throw new Exception('No hay período contable activo');
        }
        
        $mes = date('n', strtotime($fecha_operacion));
        
        // Buscar el presupuesto correspondiente
        $sql = "SELECT id FROM presupuestos 
                WHERE cuenta_id = :cuenta_id 
                AND periodo_id = :periodo_id 
                AND tipo_movimiento = :tipo_movimiento";
        
        if ($centro_costo_id) {
            $sql .= " AND centro_costo_id = :centro_costo_id";
        } else {
            $sql .= " AND centro_costo_id IS NULL";
        }
        
        $stmt = $conn->prepare($sql);
        $params = [
            ':cuenta_id' => $cuenta_id,
            ':periodo_id' => $periodo_id,
            ':tipo_movimiento' => $tipo_movimiento
        ];
        
        if ($centro_costo_id) {
            $params[':centro_costo_id'] = $centro_costo_id;
        }
        
        $stmt->execute($params);
        $presupuesto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$presupuesto) {
            // Si no hay presupuesto, crear alerta pero no bloquear
            error_log("ALERTA: Ejecución sin presupuesto - Cuenta: $cuenta_id, Monto: $monto");
            return false;
        }
        
        // Registrar la ejecución
        $sql_insert = "INSERT INTO ejecucion_presupuesto 
                       (presupuesto_id, asiento_id, mes, monto_ejecutado, fecha_ejecucion, creado_por) 
                       VALUES (:presupuesto_id, :asiento_id, :mes, :monto_ejecutado, :fecha_ejecucion, :creado_por)";
        
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->execute([
            ':presupuesto_id' => $presupuesto['id'],
            ':asiento_id' => $asiento_id,
            ':mes' => $mes,
            ':monto_ejecutado' => $monto,
            ':fecha_ejecucion' => $fecha_operacion,
            ':creado_por' => $_SESSION['usuario_id']
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log('Error en registrarEjecucionPresupuestaria: ' . $e->getMessage());
        return false;
    }
}

/**
 * Obtener estado de ejecución del presupuesto (como el documento que mostraste)
 */
function obtenerEstadoEjecucionPresupuesto($periodo_id, $mes = null, $centro_costo_id = null) {
    $conn = getConnection();
    
    try {
        $sql = "SELECT * FROM vista_estado_ejecucion_financiera WHERE 1=1";
        $params = [];
        
        if ($periodo_id) {
            $sql .= " AND periodo_id = :periodo_id";
            $params[':periodo_id'] = $periodo_id;
        }
        
        if ($centro_costo_id) {
            $sql .= " AND centro_costo_id = :centro_costo_id";
            $params[':centro_costo_id'] = $centro_costo_id;
        }

        if ($mes) {
            $sql .= " AND mes = :mes";
            $params[':mes'] = $mes;
        }
        
        $sql .= " ORDER BY partida_codigo, anio, mes";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log('Error en obtenerEstadoEjecucionPresupuesto: ' . $e->getMessage());
        return [];
    }
}

/**
 * Crear modificación presupuestaria
 */
function crearModificacionPresupuestaria($presupuesto_id, $tipo_modificacion, $monto, $mes, $justificacion, $documento_soporte = null) {
    $conn = getConnection();
    
    try {
        $sql = "INSERT INTO modificaciones_presupuesto 
                (presupuesto_id, tipo_modificacion, monto, mes, justificacion, documento_soporte, solicitado_por) 
                VALUES (:presupuesto_id, :tipo_modificacion, :monto, :mes, :justificacion, :documento_soporte, :solicitado_por)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':presupuesto_id' => $presupuesto_id,
            ':tipo_modificacion' => $tipo_modificacion,
            ':monto' => $monto,
            ':mes' => $mes,
            ':justificacion' => $justificacion,
            ':documento_soporte' => $documento_soporte,
            ':solicitado_por' => $_SESSION['usuario_id']
        ]);
        
        return $conn->lastInsertId();
        
    } catch (PDOException $e) {
        error_log('Error en crearModificacionPresupuestaria: ' . $e->getMessage());
        return false;
    }
}

/**
 * Aprobar/Rechazar modificación presupuestaria
 */
function procesarModificacionPresupuestaria($modificacion_id, $accion, $observaciones = null) {
    $conn = getConnection();
    
    try {
        $estado = ($accion === 'aprobar') ? 'aprobada' : 'rechazada';
        
        $sql = "UPDATE modificaciones_presupuesto 
                SET estado = :estado, 
                    aprobado_por = :aprobado_por, 
                    fecha_aprobacion = NOW(),
                    observaciones_aprobacion = :observaciones
                WHERE id = :modificacion_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':estado' => $estado,
            ':aprobado_por' => $_SESSION['usuario_id'],
            ':observaciones' => $observaciones,
            ':modificacion_id' => $modificacion_id
        ]);
        
        return true;
        
    } catch (PDOException $e) {
        error_log('Error en procesarModificacionPresupuestaria: ' . $e->getMessage());
        return false;
    }
}

/**
 * Obtener alertas presupuestarias
 */
function obtenerAlertasPresupuestarias($periodo_id) {
    $conn = getConnection();
    
    try {
        $sql = "SELECT 
                    p.id,
                    c.codigo,
                    c.nombre as cuenta_nombre,
                    cc.nombre as centro_costo,
                    v.presupuesto_actualizado,
                    v.disponibilidad_fondo,
                    v.pagos_acumulado,
                    CASE 
                        WHEN v.disponibilidad_fondo < 0 THEN 'sobregiro'
                        WHEN v.pagos_acumulado > 0 AND v.presupuesto_actualizado > 0 
                             AND (v.pagos_acumulado / v.presupuesto_actualizado) >= 0.9 THEN 'critico'
                        WHEN v.pagos_acumulado > 0 AND v.presupuesto_actualizado > 0 
                             AND (v.pagos_acumulado / v.presupuesto_actualizado) >= 0.75 THEN 'advertencia'
                        ELSE 'normal'
                    END as nivel_alerta
                FROM presupuestos p
                JOIN vista_estado_ejecucion_financiera v ON p.id = v.presupuesto_id
                JOIN cuentas c ON p.cuenta_id = c.id
                LEFT JOIN centros_costo cc ON p.centro_costo_id = cc.id
                WHERE p.periodo_id = :periodo_id
                AND (v.disponibilidad_fondo < 0 
                     OR (v.presupuesto_actualizado > 0 AND v.pagos_acumulado / v.presupuesto_actualizado >= 0.75))
                ORDER BY v.pagos_acumulado DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':periodo_id' => $periodo_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log('Error en obtenerAlertasPresupuestarias: ' . $e->getMessage());
        return [];
    }
}

/**
 * Obtener período contable activo
 */
function obtenerPeriodoActivo() {
    $conn = getConnection();
    
    try {
        $sql = "SELECT id FROM periodos_contables WHERE estado = 'abierto' ORDER BY desde DESC LIMIT 1";
        $stmt = $conn->query($sql);
        $periodo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $periodo ? $periodo['id'] : null;
        
    } catch (PDOException $e) {
        error_log('Error en obtenerPeriodoActivo: ' . $e->getMessage());
        return null;
    }
}

/**
 * Generar semáforo de control presupuestario
 */
function generarSemaforoPresupuestario($porcentaje_ejecucion, $disponibilidad) {
    if ($disponibilidad < 0) {
        return ['color' => 'danger', 'icono' => 'fas fa-exclamation-triangle', 'estado' => 'Sobregiro'];
    } elseif ($porcentaje_ejecucion >= 90) {
        return ['color' => 'danger', 'icono' => 'fas fa-fire', 'estado' => 'Crítico'];
    } elseif ($porcentaje_ejecucion >= 75) {
        return ['color' => 'warning', 'icono' => 'fas fa-exclamation-circle', 'estado' => 'Advertencia'];
    } elseif ($porcentaje_ejecucion >= 50) {
        return ['color' => 'info', 'icono' => 'fas fa-info-circle', 'estado' => 'Moderado'];
    } else {
        return ['color' => 'success', 'icono' => 'fas fa-check-circle', 'estado' => 'Óptimo'];
    }
}

/**
 * Obtener presupuesto consolidado de una partida superior (PART, GEN, ESP)
 * Calcula automáticamente sumando los presupuestos de todas las partidas subespecíficas hijas
 * 
 * @param int $cuenta_id ID de la cuenta (partida superior)
 * @param int $periodo_id ID del período contable
 * @return array Valores consolidados del presupuesto
 */
function obtenerPresupuestoConsolidadoPartida($cuenta_id, $periodo_id = null) {
    $conn = getConnection();
    
    // Si no se proporciona período, usar el activo
    if (!$periodo_id) {
        $periodo_id = obtenerPeriodoActivo();
    }
    
    if (!$periodo_id) {
        return [
            'monto_total' => 0,
            'comprometido' => 0,
            'causado' => 0,
            'pagado' => 0,
            'disponible' => 0,
            'mensaje' => 'No hay período contable activo'
        ];
    }
    
    try {
        // Obtener información de la partida
        $stmt_partida = $conn->prepare("
            SELECT id, numero_partida, generica, especifica, subespecifica, nivel_partida, codigo, nombre
            FROM cuentas 
            WHERE id = :cuenta_id 
            AND es_partida_presupuestaria = 1
            AND nivel_partida IN ('partida', 'generica', 'especifica')
        ");
        $stmt_partida->execute([':cuenta_id' => $cuenta_id]);
        $partida = $stmt_partida->fetch(PDO::FETCH_ASSOC);
        
        if (!$partida) {
            return [
                'monto_total' => 0,
                'comprometido' => 0,
                'causado' => 0,
                'pagado' => 0,
                'disponible' => 0,
                'mensaje' => 'Partida no encontrada o no es una partida superior'
            ];
        }
        
        // Construir condición WHERE para buscar partidas hijas según el nivel
        $where_conditions = [];
        $params = [':periodo_id' => $periodo_id];
        
        if ($partida['nivel_partida'] === 'partida') {
            // Para partida principal: buscar todas las subespecíficas que empiecen con el mismo número_partida
            $where_conditions[] = "c.numero_partida = :numero_partida";
            $where_conditions[] = "c.nivel_partida = 'subespecifica'";
            $params[':numero_partida'] = $partida['numero_partida'];
        } elseif ($partida['nivel_partida'] === 'generica') {
            // Para genérica: buscar subespecíficas con mismo número_partida y generica
            $where_conditions[] = "c.numero_partida = :numero_partida";
            $where_conditions[] = "c.generica = :generica";
            $where_conditions[] = "c.nivel_partida = 'subespecifica'";
            $params[':numero_partida'] = $partida['numero_partida'];
            $params[':generica'] = $partida['generica'];
        } elseif ($partida['nivel_partida'] === 'especifica') {
            // Para específica: buscar subespecíficas con mismo número_partida, generica y especifica
            $where_conditions[] = "c.numero_partida = :numero_partida";
            $where_conditions[] = "c.generica = :generica";
            $where_conditions[] = "c.especifica = :especifica";
            $where_conditions[] = "c.nivel_partida = 'subespecifica'";
            $params[':numero_partida'] = $partida['numero_partida'];
            $params[':generica'] = $partida['generica'];
            $params[':especifica'] = $partida['especifica'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Sumar presupuestos de todas las partidas subespecíficas hijas
        $sql = "
            SELECT 
                COALESCE(SUM(
                    p.monto_enero + p.monto_febrero + p.monto_marzo + p.monto_abril + 
                    p.monto_mayo + p.monto_junio + p.monto_julio + p.monto_agosto + 
                    p.monto_septiembre + p.monto_octubre + p.monto_noviembre + p.monto_diciembre
                ), 0) as monto_total,
                COALESCE(SUM(p.comprometido), 0) as comprometido,
                COALESCE(SUM(p.causado), 0) as causado,
                COALESCE(SUM(p.pagado), 0) as pagado,
                COALESCE(SUM(p.credito_vigente), 0) as credito_vigente
            FROM presupuestos p
            INNER JOIN cuentas c ON p.cuenta_id = c.id
            WHERE p.periodo_id = :periodo_id
            AND c.estado = 'activa'
            AND $where_clause
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $monto_total = floatval($resultado['monto_total'] ?? 0);
        $comprometido = floatval($resultado['comprometido'] ?? 0);
        $causado = floatval($resultado['causado'] ?? 0);
        $pagado = floatval($resultado['pagado'] ?? 0);
        $credito_vigente = floatval($resultado['credito_vigente'] ?? 0);
        $disponible = $credito_vigente - $comprometido - $pagado;
        
        return [
            'monto_total' => $monto_total,
            'credito_vigente' => $credito_vigente,
            'comprometido' => $comprometido,
            'causado' => $causado,
            'pagado' => $pagado,
            'disponible' => $disponible,
            'partida' => $partida,
            'mensaje' => 'Valores consolidados calculados desde partidas subespecíficas hijas'
        ];
        
    } catch (PDOException $e) {
        error_log('Error en obtenerPresupuestoConsolidadoPartida: ' . $e->getMessage());
        return [
            'monto_total' => 0,
            'comprometido' => 0,
            'causado' => 0,
            'pagado' => 0,
            'disponible' => 0,
            'error' => $e->getMessage()
        ];
    }
}
?>
