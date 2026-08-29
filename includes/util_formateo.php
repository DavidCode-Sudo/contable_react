<?php
/**
 * Función helper para formatear cantidades según tipo de unidad
 */

function formatearCantidadSegunUnidad($cantidad, $unidad) {
    $unidades_discretas = ['Unidad', 'Caja', 'Paquete', 'Resma', 'Docena', 'Rollo', 'Botella', 'Bolsa'];
    
    $cantidad_float = (float)$cantidad;
    
    if (in_array($unidad, $unidades_discretas) && $cantidad_float == floor($cantidad_float)) {
        // Para unidades discretas con valores enteros, mostrar sin decimales
        return number_format($cantidad_float, 0);
    } else {
        // Para unidades continuas o valores con decimales, mostrar hasta 3 decimales sin ceros finales
        return rtrim(rtrim(number_format($cantidad_float, 3), '0'), '.');
    }
}

function esUnidadDiscreta($unidad) {
    $unidades_discretas = ['Unidad', 'Caja', 'Paquete', 'Resma', 'Docena', 'Rollo', 'Botella', 'Bolsa'];
    return in_array($unidad, $unidades_discretas);
}

function obtenerStepInput($unidad) {
    return esUnidadDiscreta($unidad) ? '1' : '0.001';
}

function obtenerPlaceholderInput($unidad) {
    return esUnidadDiscreta($unidad) ? '0' : '0.000';
}
?>