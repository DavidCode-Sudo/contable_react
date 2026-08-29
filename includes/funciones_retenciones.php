<?php
/**
 * Funciones para el cálculo y manejo de retenciones
 * Extraídas del módulo de facturación para evitar conflictos de inclusión
 */

// Función para generar número de recibo automático con formato profesional
function generarNumeroRecibo($conn) {
    try {
        $anio_actual = date('Y');
        $prefijo = 'RP';
        
        // Buscar el último número secuencial para recibos de pago del año
        $sql = "SELECT numero FROM recibos_pago 
                WHERE numero LIKE :patron 
                ORDER BY CAST(SUBSTRING(numero, -5) AS UNSIGNED) DESC 
                LIMIT 1";
        
        $patron = $prefijo . '-' . $anio_actual . '-%';
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':patron' => $patron
        ]);
        
        $ultimo_numero = $stmt->fetchColumn();
        
        if ($ultimo_numero) {
            // Extraer el número secuencial (últimos 5 dígitos)
            $ultimo_secuencial = intval(substr($ultimo_numero, -5));
            $nuevo_secuencial = $ultimo_secuencial + 1;
        } else {
            // Primer recibo del año
            $nuevo_secuencial = 1;
        }
        
        // Intentar hasta 20 veces para encontrar un número disponible
        $intentos = 0;
        $max_intentos = 20;
        
        do {
            $numero_a_probar = $nuevo_secuencial + $intentos;
            $numero_generado = $prefijo . '-' . $anio_actual . '-' . str_pad($numero_a_probar, 5, '0', STR_PAD_LEFT);
            
            // Verificar que no exista
            $stmt_check = $conn->prepare("SELECT COUNT(*) FROM recibos_pago WHERE numero = :numero");
            $stmt_check->execute([':numero' => $numero_generado]);
            
            if ($stmt_check->fetchColumn() == 0) {
                // Número disponible encontrado
                return $numero_generado;
            }
            
            $intentos++;
        } while ($intentos < $max_intentos);
        
        // Si no se encontró número disponible, usar timestamp como fallback
        $timestamp = time();
        $numero_fallback = $prefijo . '-' . $anio_actual . '-' . substr($timestamp, -5);
        
        // Verificar que el fallback tampoco exista
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM recibos_pago WHERE numero = :numero");
        $stmt_check->execute([':numero' => $numero_fallback]);
        
        if ($stmt_check->fetchColumn() == 0) {
            return $numero_fallback;
        }
        
        // Último recurso: timestamp + microsegundos
        return $prefijo . '-' . $anio_actual . '-' . substr(str_replace('.', '', microtime(true)), -5);
        
    } catch (Exception $e) {
        error_log('Error generando número de recibo: ' . $e->getMessage());
        // Fallback: generar número básico con timestamp
        $timestamp = time();
        return 'RP' . '-' . date('Y') . '-' . substr($timestamp, -5);
    }
}

// Función para generar número de cuenta automático (N° DE CUENTA en la planilla oficial)
function generarNumeroReciboInterno($conn) {
    try {
        $anio_actual = date('Y');
        $mes_actual = date('m');
        $prefijo = 'REC';
        
        // Buscar el último número secuencial para recibos internos del mes
        $sql = "SELECT numero_factura FROM recibos_pago 
                WHERE numero_factura LIKE :patron 
                AND estado = 'activo'
                ORDER BY CAST(SUBSTRING(numero_factura, -4) AS UNSIGNED) DESC 
                LIMIT 1";
        
        $patron = $prefijo . '-' . $anio_actual . $mes_actual . '-%';
        $stmt = $conn->prepare($sql);
        $stmt->execute([':patron' => $patron]);
        
        $ultimo_numero = $stmt->fetchColumn();
        
        if ($ultimo_numero) {
            // Extraer el número secuencial (últimos 4 dígitos)
            $ultimo_secuencial = intval(substr($ultimo_numero, -4));
            $nuevo_secuencial = $ultimo_secuencial + 1;
        } else {
            // Primer recibo del mes
            $nuevo_secuencial = 1;
        }
        
        // Generar número con formato REC-YYYYMM-NNNN
        $numero_generado = $prefijo . '-' . $anio_actual . $mes_actual . '-' . str_pad($nuevo_secuencial, 4, '0', STR_PAD_LEFT);
        
        // Verificar unicidad y ajustar si es necesario
        $intentos = 0;
        $max_intentos = 100;
        
        do {
            $numero_a_probar = $prefijo . '-' . $anio_actual . $mes_actual . '-' . str_pad($nuevo_secuencial + $intentos, 4, '0', STR_PAD_LEFT);
            
            $stmt_check = $conn->prepare("SELECT COUNT(*) FROM recibos_pago WHERE numero_factura = :numero AND estado = 'activo'");
            $stmt_check->execute([':numero' => $numero_a_probar]);
            
            if ($stmt_check->fetchColumn() == 0) {
                return $numero_a_probar;
            }
            
            $intentos++;
        } while ($intentos < $max_intentos);
        
        // Fallback con timestamp
        $timestamp = time();
        return $prefijo . '-' . $anio_actual . $mes_actual . '-' . substr($timestamp, -4);
        
    } catch (Exception $e) {
        error_log('Error generando número de recibo interno: ' . $e->getMessage());
        return 'REC-' . date('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}

// Función para generar número de control automático (N° CONTROL DE LA FACTURA en la planilla oficial)
function generarNumeroControl($conn) {
    try {
        $anio_actual = date('Y');
        $prefijo = 'CTRL';
        
        // Buscar el último número secuencial para controles del año
        $sql = "SELECT numero_control FROM recibos_pago 
                WHERE numero_control LIKE :patron 
                AND estado = 'activo'
                ORDER BY CAST(SUBSTRING(numero_control, -6) AS UNSIGNED) DESC 
                LIMIT 1";
        
        $patron = $prefijo . '-' . $anio_actual . '-%';
        $stmt = $conn->prepare($sql);
        $stmt->execute([':patron' => $patron]);
        
        $ultimo_numero = $stmt->fetchColumn();
        
        if ($ultimo_numero) {
            // Extraer el número secuencial (últimos 6 dígitos)
            $ultimo_secuencial = intval(substr($ultimo_numero, -6));
            $nuevo_secuencial = $ultimo_secuencial + 1;
        } else {
            // Primer control del año
            $nuevo_secuencial = 1;
        }
        
        // Generar número con formato CTRL-YYYY-NNNNNN
        $intentos = 0;
        $max_intentos = 100;
        
        do {
            $numero_a_probar = $prefijo . '-' . $anio_actual . '-' . str_pad($nuevo_secuencial + $intentos, 6, '0', STR_PAD_LEFT);
            
            $stmt_check = $conn->prepare("SELECT COUNT(*) FROM recibos_pago WHERE numero_control = :numero AND estado = 'activo'");
            $stmt_check->execute([':numero' => $numero_a_probar]);
            
            if ($stmt_check->fetchColumn() == 0) {
                return $numero_a_probar;
            }
            
            $intentos++;
        } while ($intentos < $max_intentos);
        
        // Fallback con timestamp
        $timestamp = time();
        return $prefijo . '-' . $anio_actual . '-' . substr($timestamp, -6);
        
    } catch (Exception $e) {
        error_log('Error generando número de control: ' . $e->getMessage());
        return 'CTRL-' . date('Y') . '-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
}

// Función simplificada para calcular retenciones en órdenes de pago
function calcularRetencionesOrdenPago($monto_con_iva, $porcentaje_iva = 16) {
    // Calcular base imponible (monto sin IVA)
    $base_imponible = $monto_con_iva / (1 + ($porcentaje_iva / 100));
    
    // Calcular IVA
    $iva_calculado = $monto_con_iva - $base_imponible;
    
    // Retención ISLR (1x1000): 0.1% sobre la base imponible (sin IVA)
    $calculo_1x1000 = $base_imponible * 0.001;
    
    // Retención IVA (1x25): 4% sobre el IVA (1/25 del IVA)
    $calculo_1x25 = $iva_calculado / 25;
    
    $total_retencion = $calculo_1x1000 + $calculo_1x25;
    
    return [
        'base_imponible' => round($base_imponible, 2),
        'iva_calculado' => round($iva_calculado, 2),
        'calculo_1x1000' => round($calculo_1x1000, 2),
        'calculo_1x25' => round($calculo_1x25, 2),
        'total_retencion' => round($total_retencion, 2),
        'monto_neto_pagar' => round($monto_con_iva - $total_retencion, 2)
    ];
}

// Función para calcular retenciones automáticamente según normativa venezolana
function calcularRetenciones($monto_con_iva, $porcentaje_iva = 16) {
    // Calcular base imponible (monto sin IVA)
    $base_imponible = $monto_con_iva / (1 + ($porcentaje_iva / 100));
    
    // Calcular IVA
    $iva_calculado = $monto_con_iva - $base_imponible;
    
    // Retención ISLR (1x1000): 0.1% sobre la base imponible (sin IVA)
    $calculo_1x1000 = $base_imponible * 0.001;
    
    // Retención IVA (1x25): 4% sobre el IVA (1/25 del IVA)
    $calculo_1x25 = $iva_calculado / 25;
    
    return [
        'base_imponible' => round($base_imponible, 2),
        'iva_calculado' => round($iva_calculado, 2),
        'calculo_1x1000' => round($calculo_1x1000, 2),
        'calculo_1x25' => round($calculo_1x25, 2),
        'total_retencion' => round($calculo_1x1000 + $calculo_1x25, 2),
        'monto_neto_pagar' => round($monto_con_iva - ($calculo_1x1000 + $calculo_1x25), 2)
    ];
}

// Función para asegurar que existan las cuentas de retenciones
function asegurarCuentasRetenciones($conn) {
    $cuentas_necesarias = [
        [
            'codigo' => 'RET_MUNICIPAL_1X1000',
            'nombre' => 'Retenciones Municipales 1x1000',
            'tipo' => 'pasivo',
            'naturaleza' => 'acreedora'
        ],
        [
            'codigo' => 'RET_MUNICIPAL_1X25',
            'nombre' => 'Retenciones Municipales 1x25',
            'tipo' => 'pasivo',
            'naturaleza' => 'acreedora'
        ]
    ];
    
    foreach ($cuentas_necesarias as $cuenta) {
        $sql_check = "SELECT COUNT(*) FROM cuentas WHERE codigo = :codigo";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([':codigo' => $cuenta['codigo']]);
        
        if ($stmt_check->fetchColumn() == 0) {
            $sql_insert = "INSERT INTO cuentas (codigo, nombre, tipo, naturaleza, estado) 
                          VALUES (:codigo, :nombre, :tipo, :naturaleza, 'activa')";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->execute([
                ':codigo' => $cuenta['codigo'],
                ':nombre' => $cuenta['nombre'],
                ':tipo' => $cuenta['tipo'],
                ':naturaleza' => $cuenta['naturaleza']
            ]);
        }
    }
}

// Función para obtener ID de cuenta por código
function obtenerIdCuenta($conn, $codigo) {
    $sql = "SELECT id FROM cuentas WHERE codigo = :codigo AND estado = 'activa'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':codigo' => $codigo]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    return $resultado ? $resultado['id'] : null;
}
