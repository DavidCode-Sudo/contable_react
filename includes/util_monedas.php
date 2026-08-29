<?php
/**
 * Utilidades para manejo de monedas
 * Sistema principal: Bolívares (Bs)
 * Referencia informativa: Dólares (USD)
 */

// NOTA: La función obtenerTasaCambioActual() ya existe en funciones_contables.php

/**
 * Formatea un monto en Bolívares con referencia opcional en USD
 */
function formatearMontoConReferencia($monto_bs, $mostrar_referencia_usd = true, $tasa_cambio = null) {
    if ($tasa_cambio === null) {
        $tasa_cambio = obtenerTasaCambioActual();
    }
    
    $monto_bs = (float)$monto_bs;
    $formato_bs = 'Bs. ' . number_format($monto_bs, 2);
    
    if ($mostrar_referencia_usd && $tasa_cambio > 0) {
        $monto_usd = $monto_bs / $tasa_cambio;
        $formato_bs .= ' <small class="text-muted">(≈ $' . number_format($monto_usd, 2) . ' USD)</small>';
    }
    
    return $formato_bs;
}

// NOTA: La función formatearMonedaBs() ya existe en funciones_contables.php

/**
 * Convierte USD a Bolívares usando tasa actual
 */
function convertirUsdABs($monto_usd, $tasa_cambio = null) {
    if ($tasa_cambio === null) {
        $tasa_cambio = obtenerTasaCambioActual();
    }
    return (float)$monto_usd * $tasa_cambio;
}

/**
 * Convierte Bolívares a USD usando tasa actual (solo para referencia)
 */
function convertirBsAUsd($monto_bs, $tasa_cambio = null) {
    if ($tasa_cambio === null) {
        $tasa_cambio = obtenerTasaCambioActual();
    }
    return $tasa_cambio > 0 ? ((float)$monto_bs / $tasa_cambio) : 0;
}

/**
 * Valida que un monto esté en el rango correcto para Bolívares
 */
function validarMontoBs($monto) {
    $monto = (float)$monto;
    
    // Si el monto es menor a 1000, probablemente está en USD y necesita conversión
    if ($monto > 0 && $monto < 1000) {
        return [
            'valido' => false,
            'mensaje' => 'El monto parece estar en USD. Debe convertirse a Bolívares.',
            'sugerencia' => convertirUsdABs($monto)
        ];
    }
    
    return [
        'valido' => true,
        'mensaje' => 'Monto válido en Bolívares',
        'monto_bs' => $monto
    ];
}

/**
 * Formatea monto para mostrar en tablas con clase CSS
 */
function formatearMontoTabla($monto_bs, $clase_css = 'text-success fw-bold') {
    return '<span class="' . $clase_css . '">' . formatearMonedaBs($monto_bs) . '</span>';
}

/**
 * Formatea monto con referencia para mostrar en formularios
 */
function formatearMontoFormulario($monto_bs, $id_campo = '') {
    $tasa = obtenerTasaCambioActual();
    $monto_usd = convertirBsAUsd($monto_bs, $tasa);
    
    $html = '<div class="input-group">';
    $html .= '<span class="input-group-text">Bs.</span>';
    $html .= '<input type="number" step="0.01" class="form-control" value="' . number_format($monto_bs, 2, '.', '') . '"' . ($id_campo ? ' id="' . $id_campo . '"' : '') . '>';
    $html .= '<span class="input-group-text text-muted small">≈ $' . number_format($monto_usd, 2) . '</span>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Actualiza la tasa de cambio en la base de datos (para futuras implementaciones)
 */
function actualizarTasaCambio($nueva_tasa, $usuario_id = null) {
    try {
        $conn = getConnection();
        
        // Crear tabla de tasas si no existe
        $conn->exec("
            CREATE TABLE IF NOT EXISTS tasas_cambio (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tasa DECIMAL(10,4) NOT NULL,
                fecha_vigencia DATE NOT NULL,
                usuario_id INT,
                activa BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fecha (fecha_vigencia),
                INDEX idx_activa (activa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Desactivar tasas anteriores
        $conn->prepare("UPDATE tasas_cambio SET activa = FALSE WHERE activa = TRUE")->execute();
        
        // Insertar nueva tasa
        $conn->prepare("
            INSERT INTO tasas_cambio (tasa, fecha_vigencia, usuario_id, activa) 
            VALUES (?, CURDATE(), ?, TRUE)
        ")->execute([$nueva_tasa, $usuario_id]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error actualizando tasa de cambio: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene la tasa de cambio desde la base de datos
 */
function obtenerTasaCambioDB() {
    try {
        $conn = getConnection();
        $stmt = $conn->prepare("
            SELECT tasa FROM tasas_cambio 
            WHERE activa = TRUE 
            ORDER BY fecha_vigencia DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $resultado ? (float)$resultado['tasa'] : obtenerTasaCambioActual();
        
    } catch (Exception $e) {
        // Si hay error, usar tasa por defecto
        return obtenerTasaCambioActual();
    }
}
?>
