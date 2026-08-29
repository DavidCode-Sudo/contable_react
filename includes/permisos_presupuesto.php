<?php
/**
 * Funciones para controlar la visibilidad de información presupuestaria
 * según el rol y permisos del usuario
 */

/**
 * Verifica si el usuario actual puede ver montos presupuestarios
 * Solo usuarios con permisos específicos pueden ver información financiera sensible
 */
function puedeVerMontosPresupuestarios() {
    // Verificar si el usuario tiene permisos para ver montos presupuestarios
    if (verificarPermiso('presupuestos', 'ver_montos') || 
        verificarPermiso('presupuestos', 'editar') ||
        verificarPermiso('presupuestos', 'aprobar_nivel_2')) {
        return true;
    }
    
    // Los administradores siempre pueden ver
    if (esAdministrador()) {
        return true;
    }
    
    // Verificar roles específicos que pueden ver montos
    if (isset($_SESSION['usuario_rol'])) {
        $rolesConAcceso = ['admin', 'contador', 'presupuesto'];
        if (in_array($_SESSION['usuario_rol'], $rolesConAcceso)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Verifica si el usuario puede ver información detallada de disponibilidad
 */
function puedeVerDisponibilidadDetallada() {
    return puedeVerMontosPresupuestarios();
}

/**
 * Formatea la información del presupuesto según los permisos del usuario
 */
function formatearInfoPresupuesto($presupuesto, $mostrarMontos = null) {
    if ($mostrarMontos === null) {
        $mostrarMontos = puedeVerMontosPresupuestarios();
    }
    
    if ($mostrarMontos) {
        // Mostrar información completa con montos
        return [
            'descripcion_completa' => $presupuesto['descripcion'] . ' (Disponible: Bs ' . number_format($presupuesto['saldo_disponible'], 2) . ')',
            'mostrar_info_detallada' => true,
            'monto_total' => $presupuesto['monto_total'],
            'saldo_disponible' => $presupuesto['saldo_disponible']
        ];
    } else {
        // Mostrar solo información básica sin montos
        return [
            'descripcion_completa' => $presupuesto['descripcion'],
            'mostrar_info_detallada' => false,
            'monto_total' => $presupuesto['monto_total'], // Para validaciones internas
            'saldo_disponible' => $presupuesto['saldo_disponible'] // Para validaciones internas
        ];
    }
}

/**
 * Genera el HTML para mostrar información del presupuesto según permisos
 */
function generarHtmlInfoPresupuesto($presupuesto) {
    $puedeVerMontos = puedeVerMontosPresupuestarios();
    
    if ($puedeVerMontos) {
        // HTML completo con montos para usuarios autorizados
        return '
        <div class="alert alert-info">
            <h6><i class="fas fa-info-circle me-2"></i>Información del Presupuesto</h6>
            <div class="row">
                <div class="col-6">
                    <small class="text-muted">Monto Total:</small><br>
                    <strong>Bs ' . number_format($presupuesto['monto_total'], 2) . '</strong>
                </div>
                <div class="col-6">
                    <small class="text-muted">Disponible:</small><br>
                    <strong class="' . ($presupuesto['saldo_disponible'] > 0 ? 'text-success' : 'text-danger') . '">
                        Bs ' . number_format($presupuesto['saldo_disponible'], 2) . '
                    </strong>
                </div>
            </div>
        </div>';
    } else {
        // HTML simplificado sin montos para usuarios regulares
        return '
        <div class="alert alert-info">
            <h6><i class="fas fa-info-circle me-2"></i>Presupuesto Seleccionado</h6>
            <p class="mb-2"><strong>' . htmlspecialchars($presupuesto['descripcion']) . '</strong></p>
            <div class="d-flex align-items-center">
                <i class="fas fa-' . ($presupuesto['saldo_disponible'] > 0 ? 'check-circle text-success' : 'exclamation-triangle text-warning') . ' me-2"></i>
                <span class="' . ($presupuesto['saldo_disponible'] > 0 ? 'text-success' : 'text-warning') . '">
                    ' . ($presupuesto['saldo_disponible'] > 0 ? 'Presupuesto disponible para esta partida' : 'Verificar disponibilidad presupuestaria') . '
                </span>
            </div>
        </div>';
    }
}
?>
