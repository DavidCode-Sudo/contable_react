<?php
/**
 * Script para ejecutar y agregar todas las reglas de nómina
 * Basado en el formato de recibo de la Alcaldía de Caracas
 * 
 * Uso: Ejecutar desde navegador o línea de comandos
 * http://localhost/contable/database/scripts/ejecutar_reglas_nomina.php
 */

require_once __DIR__ . '/../../config/database/database.php';

try {
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Agregando Reglas de Nómina...</h2>\n";
    echo "<pre>\n";
    
    $reglas = [
        // REMUNERACIONES
        ['PRIMA_PROF', 'Prima de Profesionalización', 'percepcion', 'porcentaje_salario', 20.0000, 10],
        ['PRIMA_ANT', 'Prima por Antigüedad', 'percepcion', 'porcentaje_salario', 3.0000, 11],
        ['COMPLEMENTOS', 'Otros Complementos', 'percepcion', 'personalizado', 0.0000, 12],
        ['PRIMA_HIJO', 'Prima por Hijo', 'percepcion', 'personalizado', 0.0000, 13],
        ['COMP_SERVICIOS', 'Complemento Servicios', 'percepcion', 'personalizado', 0.0000, 14],
        ['BONO_VACACIONAL', 'Bono Vacacional', 'percepcion', 'personalizado', 0.0000, 15],
        ['CONCIERTOS_CAMARA', 'Conciertos de cámara', 'percepcion', 'personalizado', 0.0000, 16],
        ['AGUINALDOS', 'Aguinaldos', 'percepcion', 'personalizado', 0.0000, 17],
        
        // DEDUCCIONES
        ['RETARDOS', 'Retardos', 'deduccion', 'personalizado', 0.0000, 20],
        ['SSO', 'S.S.O. (Seguro Social Obligatorio)', 'deduccion', 'porcentaje_salario', 4.0000, 21],
        ['PARO_FORZOSO', 'Paro Forzoso', 'deduccion', 'porcentaje_salario', 0.5000, 22],
        ['FAOV', 'FAOV (Fondo de Ahorro Obligatorio para la Vivienda)', 'deduccion', 'porcentaje_salario', 1.0000, 23],
        ['RET_CAJA_AHORROS', 'Retención Caja de Ahorros', 'deduccion', 'porcentaje_salario', 2.0000, 24],
        ['PREST_CAJA_AHORROS', 'Préstamos Caja de Ahorros', 'deduccion', 'personalizado', 0.0000, 25],
        ['RET_SIPRES', 'Ret. SIPRES', 'deduccion', 'personalizado', 0.0000, 26],
        ['PREST_FUNDACION', 'Préstamos Fundación', 'deduccion', 'personalizado', 0.0000, 27],
        ['DIETA_COMITE', 'Dieta Comité', 'deduccion', 'personalizado', 0.0000, 28],
        ['MONTE_PIO', 'Monte Pío', 'deduccion', 'personalizado', 0.0000, 29],
        ['DESC_CAJA_CLAP', 'Otros descuentos (Caja Clap)', 'deduccion', 'personalizado', 0.0000, 30],
        ['OTROS_DESCUENTOS', 'Otros descuentos', 'deduccion', 'personalizado', 0.0000, 31],
    ];
    
    $insertados = 0;
    $existentes = 0;
    $errores = 0;
    
    $stmt = $conn->prepare("
        INSERT INTO conceptos_nomina (codigo, nombre, tipo, base_calculo, valor, orden, estado)
        VALUES (?, ?, ?, ?, ?, ?, 'activo')
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            tipo = VALUES(tipo),
            base_calculo = VALUES(base_calculo),
            valor = VALUES(valor),
            orden = VALUES(orden),
            estado = 'activo'
    ");
    
    foreach ($reglas as $regla) {
        list($codigo, $nombre, $tipo, $base_calculo, $valor, $orden) = $regla;
        
        try {
            // Verificar si ya existe
            $check = $conn->prepare("SELECT id FROM conceptos_nomina WHERE codigo = ?");
            $check->execute([$codigo]);
            $existe = $check->fetch();
            
            if ($existe) {
                // Actualizar si existe
                $update = $conn->prepare("
                    UPDATE conceptos_nomina 
                    SET nombre = ?, tipo = ?, base_calculo = ?, valor = ?, orden = ?, estado = 'activo'
                    WHERE codigo = ?
                ");
                $update->execute([$nombre, $tipo, $base_calculo, $valor, $orden, $codigo]);
                echo "✓ Actualizado: $codigo - $nombre\n";
                $existentes++;
            } else {
                // Insertar nuevo
                $stmt->execute([$codigo, $nombre, $tipo, $base_calculo, $valor, $orden]);
                echo "✓ Creado: $codigo - $nombre\n";
                $insertados++;
            }
        } catch (PDOException $e) {
            echo "✗ Error con $codigo: " . $e->getMessage() . "\n";
            $errores++;
        }
    }
    
    echo "\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "RESUMEN:\n";
    echo "  ✓ Creados: $insertados\n";
    echo "  ✓ Actualizados: $existentes\n";
    echo "  ✗ Errores: $errores\n";
    echo "  Total reglas: " . count($reglas) . "\n";
    echo "═══════════════════════════════════════════════════════════\n";
    
    // Mostrar reglas creadas
    echo "\nREGLAS ACTIVAS:\n";
    $query = $conn->query("
        SELECT codigo, nombre, tipo, base_calculo, valor, orden 
        FROM conceptos_nomina 
        WHERE estado = 'activo' 
        ORDER BY tipo, orden
    ");
    $reglas_activas = $query->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nREMUNERACIONES:\n";
    foreach ($reglas_activas as $r) {
        if ($r['tipo'] === 'percepcion') {
            $valor_display = $r['base_calculo'] === 'porcentaje_salario' 
                ? $r['valor'] . '%' 
                : ($r['valor'] > 0 ? 'Bs. ' . number_format($r['valor'], 2) : 'Personalizado');
            echo "  • {$r['codigo']}: {$r['nombre']} ({$valor_display})\n";
        }
    }
    
    echo "\nDEDUCCIONES:\n";
    foreach ($reglas_activas as $r) {
        if ($r['tipo'] === 'deduccion') {
            $valor_display = $r['base_calculo'] === 'porcentaje_salario' 
                ? $r['valor'] . '%' 
                : ($r['valor'] > 0 ? 'Bs. ' . number_format($r['valor'], 2) : 'Personalizado');
            echo "  • {$r['codigo']}: {$r['nombre']} ({$valor_display})\n";
        }
    }
    
    echo "\n</pre>\n";
    echo "<p style='color: green; font-weight: bold;'>✓ ¡Proceso completado exitosamente!</p>\n";
    echo "<p><a href='../../modulos/rrhh/gestion_conceptos.php'>Ver reglas en el sistema</a></p>\n";
    
} catch (Exception $e) {
    echo "<pre style='color: red;'>\n";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "</pre>\n";
}

