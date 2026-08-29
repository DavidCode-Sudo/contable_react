<?php
/**
 * Configuración de datos de la empresa para recibos de retenciones
 */

// Datos del agente de retención (tu empresa)
define('EMPRESA_RIF', 'J-12345678-9');
define('EMPRESA_RAZON_SOCIAL', 'MI EMPRESA C.A.');
define('EMPRESA_DIRECCION', 'Av. Principal #123, Caracas, Venezuela');
define('EMPRESA_TELEFONO', '0212-1234567');
define('EMPRESA_EMAIL', 'contabilidad@miempresa.com');

/**
 * Obtiene los datos de la empresa configurados
 */
function obtenerDatosEmpresa() {
    return [
        'rif' => EMPRESA_RIF,
        'razon_social' => EMPRESA_RAZON_SOCIAL,
        'direccion' => EMPRESA_DIRECCION,
        'telefono' => EMPRESA_TELEFONO,
        'email' => EMPRESA_EMAIL
    ];
}

/**
 * Configuración de retenciones por defecto
 */
function obtenerConfiguracionRetenciones() {
    return [
        'servicios' => [
            'aplica_ret_iva' => true,
            'porcentaje_ret_iva' => 75, // 75% del IVA
            'aplica_ret_islr' => true,
            'porcentaje_ret_islr' => 1, // 1% sobre base imponible
        ],
        'compras' => [
            'aplica_ret_iva' => false,
            'porcentaje_ret_iva' => 0,
            'aplica_ret_islr' => false, // Solo si es persona natural
            'porcentaje_ret_islr' => 1,
        ],
        'iva_porcentaje' => 16 // IVA general en Venezuela
    ];
}
?>
