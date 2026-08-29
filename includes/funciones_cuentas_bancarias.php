<?php
/**
 * Funciones para gestión de cuentas bancarias
 * Incluye sincronización automática con presupuestos
 */

/**
 * Actualiza el saldo de todas las cuentas bancarias basado en los presupuestos
 * Esta función recalcula el saldo total institucional desde los presupuestos disponibles
 * 
 * @param PDO $conn Conexión a la base de datos
 * @return array Resultado de la operación
 */
function actualizarSaldosBancariosDesdePresupuestos($conn) {
    try {
        // Obtener saldo total institucional desde presupuestos
        // Solo las partidas subespecíficas tienen presupuesto asignado
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(
                    p.monto_total - COALESCE(p.comprometido, 0) - COALESCE(p.pagado, 0)
                ), 0) as saldo_total_disponible
            FROM presupuestos p
            INNER JOIN cuentas c ON p.cuenta_id = c.id
            WHERE c.estado = 'activa' 
            AND c.es_partida_presupuestaria = 1
            AND c.nivel_partida = 'subespecifica'
        ");
        $stmt->execute();
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $saldo_total_disponible = floatval($resultado['saldo_total_disponible']);
        
        // Obtener todas las cuentas bancarias activas
        $stmt_cuentas = $conn->prepare("
            SELECT id, institucion, numero_cuenta, banco_nombre, moneda, saldo_inicial
            FROM cuentas_bancarias 
            WHERE estado = 'activa'
        ");
        $stmt_cuentas->execute();
        $cuentas_bancarias = $stmt_cuentas->fetchAll(PDO::FETCH_ASSOC);
        
        $cuentas_actualizadas = 0;
        $errores = [];
        
        foreach ($cuentas_bancarias as $cuenta) {
            try {
                // Para el modelo institucional, cada cuenta bancaria representa
                // el saldo total institucional disponible
                $nuevo_saldo = $saldo_total_disponible;
                
                // Actualizar el saldo de la cuenta bancaria
                $stmt_update = $conn->prepare("
                    UPDATE cuentas_bancarias 
                    SET saldo_inicial = ? 
                    WHERE id = ?
                ");
                $stmt_update->execute([$nuevo_saldo, $cuenta['id']]);
                
                $cuentas_actualizadas++;
                
                // Registrar auditoría
                try {
                    registrarActualizacion(
                        'cuentas_bancarias', 
                        'cuentas_bancarias', 
                        $cuenta['id'], 
                        ['saldo_inicial' => $cuenta['saldo_inicial']], 
                        ['saldo_inicial' => $nuevo_saldo], 
                        "Saldo actualizado automáticamente desde presupuestos. Nuevo saldo: " . number_format($nuevo_saldo, 2)
                    );
                } catch (Exception $e) {
                    // Auditoría silenciosa
                }
                
            } catch (Exception $e) {
                $errores[] = "Error actualizando cuenta {$cuenta['numero_cuenta']}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => true,
            'saldo_total_disponible' => $saldo_total_disponible,
            'cuentas_actualizadas' => $cuentas_actualizadas,
            'total_cuentas' => count($cuentas_bancarias),
            'errores' => $errores,
            'mensaje' => "Se actualizaron {$cuentas_actualizadas} cuentas bancarias con el saldo institucional total de " . number_format($saldo_total_disponible, 2)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'mensaje' => 'Error al actualizar saldos bancarios desde presupuestos'
        ];
    }
}

/**
 * Actualiza el saldo de una cuenta bancaria específica basado en presupuestos
 * 
 * @param PDO $conn Conexión a la base de datos
 * @param int $cuenta_bancaria_id ID de la cuenta bancaria
 * @return array Resultado de la operación
 */
function actualizarSaldoBancarioEspecifica($conn, $cuenta_bancaria_id) {
    try {
        // Obtener información de la cuenta bancaria
        $stmt_cuenta = $conn->prepare("
            SELECT id, institucion, numero_cuenta, banco_nombre, moneda, saldo_inicial
            FROM cuentas_bancarias 
            WHERE id = ? AND estado = 'activa'
        ");
        $stmt_cuenta->execute([$cuenta_bancaria_id]);
        $cuenta = $stmt_cuenta->fetch(PDO::FETCH_ASSOC);
        
        if (!$cuenta) {
            throw new Exception('Cuenta bancaria no encontrada o inactiva');
        }
        
        // Obtener saldo total institucional desde presupuestos
        // Solo las partidas subespecíficas tienen presupuesto asignado
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(
                    p.monto_total - COALESCE(p.comprometido, 0) - COALESCE(p.pagado, 0)
                ), 0) as saldo_total_disponible
            FROM presupuestos p
            INNER JOIN cuentas c ON p.cuenta_id = c.id
            WHERE c.estado = 'activa' 
            AND c.es_partida_presupuestaria = 1
            AND c.nivel_partida = 'subespecifica'
        ");
        $stmt->execute();
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $saldo_total_disponible = floatval($resultado['saldo_total_disponible']);
        
        // Actualizar el saldo de la cuenta bancaria
        $stmt_update = $conn->prepare("
            UPDATE cuentas_bancarias 
            SET saldo_inicial = ? 
            WHERE id = ?
        ");
        $stmt_update->execute([$saldo_total_disponible, $cuenta_bancaria_id]);
        
        // Registrar auditoría
        try {
            registrarActualizacion(
                'cuentas_bancarias', 
                'cuentas_bancarias', 
                $cuenta_bancaria_id, 
                ['saldo_inicial' => $cuenta['saldo_inicial']], 
                ['saldo_inicial' => $saldo_total_disponible], 
                "Saldo actualizado automáticamente desde presupuestos. Cuenta: {$cuenta['numero_cuenta']}"
            );
        } catch (Exception $e) {
            // Auditoría silenciosa
        }
        
        return [
            'success' => true,
            'cuenta_bancaria_id' => $cuenta_bancaria_id,
            'numero_cuenta' => $cuenta['numero_cuenta'],
            'banco_nombre' => $cuenta['banco_nombre'],
            'saldo_anterior' => $cuenta['saldo_inicial'],
            'saldo_nuevo' => $saldo_total_disponible,
            'mensaje' => "Cuenta {$cuenta['numero_cuenta']} actualizada con saldo institucional de " . number_format($saldo_total_disponible, 2)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'mensaje' => 'Error al actualizar saldo bancario específico'
        ];
    }
}

/**
 * Obtiene el resumen de saldos bancarios vs presupuestos
 * 
 * @param PDO $conn Conexión a la base de datos
 * @return array Resumen comparativo
 */
function obtenerResumenSaldosBancariosVsPresupuestos($conn) {
    try {
        // Saldo total desde presupuestos
        // Solo las partidas subespecíficas tienen presupuesto asignado
        $stmt_presupuestos = $conn->prepare("
            SELECT 
                COALESCE(SUM(
                    p.monto_total - COALESCE(p.comprometido, 0) - COALESCE(p.pagado, 0)
                ), 0) as saldo_total_disponible,
                COUNT(DISTINCT p.id) as total_presupuestos,
                COUNT(DISTINCT c.id) as total_partidas
            FROM presupuestos p
            INNER JOIN cuentas c ON p.cuenta_id = c.id
            WHERE c.estado = 'activa' 
            AND c.es_partida_presupuestaria = 1
            AND c.nivel_partida = 'subespecifica'
        ");
        $stmt_presupuestos->execute();
        $presupuestos = $stmt_presupuestos->fetch(PDO::FETCH_ASSOC);
        
        // Saldo total desde cuentas bancarias
        $stmt_bancos = $conn->prepare("
            SELECT 
                COALESCE(SUM(saldo_inicial), 0) as saldo_total_bancos,
                COUNT(*) as total_cuentas_activas
            FROM cuentas_bancarias 
            WHERE estado = 'activa'
        ");
        $stmt_bancos->execute();
        $bancos = $stmt_bancos->fetch(PDO::FETCH_ASSOC);
        
        $diferencia = floatval($presupuestos['saldo_total_disponible']) - floatval($bancos['saldo_total_bancos']);
        $porcentaje_diferencia = $presupuestos['saldo_total_disponible'] > 0 ? 
            round(($diferencia / $presupuestos['saldo_total_disponible']) * 100, 2) : 0;
        
        return [
            'success' => true,
            'presupuestos' => [
                'saldo_total_disponible' => floatval($presupuestos['saldo_total_disponible']),
                'total_presupuestos' => intval($presupuestos['total_presupuestos']),
                'total_partidas' => intval($presupuestos['total_partidas'])
            ],
            'cuentas_bancarias' => [
                'saldo_total_bancos' => floatval($bancos['saldo_total_bancos']),
                'total_cuentas_activas' => intval($bancos['total_cuentas_activas'])
            ],
            'comparacion' => [
                'diferencia' => $diferencia,
                'porcentaje_diferencia' => $porcentaje_diferencia,
                'estan_sincronizados' => abs($diferencia) < 0.01 // Tolerancia de 1 centavo
            ],
            'mensaje' => $diferencia == 0 ? 
                'Los saldos están perfectamente sincronizados' : 
                'Hay una diferencia de ' . number_format($diferencia, 2) . ' (' . $porcentaje_diferencia . '%)'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'mensaje' => 'Error al obtener resumen de saldos'
        ];
    }
}
?>
