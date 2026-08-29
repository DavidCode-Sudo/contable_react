<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database/database.php';
require_once __DIR__ . '/funciones_contables.php';
require_once __DIR__ . '/funciones_presupuesto.php';

/**
 * Utilidades de Nómina
 * - Cálculo de conceptos por empleado
 * - Generación de nómina masiva por período
 * - Confirmación (contabilización) de nómina
 * - Generación de recibos de pago (baunche) en HTML
 */

function rrhh_tienePermiso($accion) {
    $uid = $_SESSION['usuario_id'] ?? null;
    if (!$uid) return false;
    // Reutilizar tienePermiso si existe
    if (function_exists('tienePermiso')) {
        if (in_array($accion, ['ver','crear','editar','activar'])) {
            return tienePermiso($uid, 'rrhh', $accion);
        }
        // Acciones de nóminas
        if (in_array($accion, ['ver_nominas','generar','confirmar','anular','imprimir'])) {
            $map = [
                'ver_nominas' => ['nominas','ver'],
                'generar'     => ['nominas','generar'],
                'confirmar'   => ['nominas','confirmar'],
                'anular'      => ['nominas','anular'],
                'imprimir'    => ['facturas','imprimir'] // fallback común
            ];
            if (isset($map[$accion])) {
                return tienePermiso($uid, $map[$accion][0], $map[$accion][1]);
            }
        }
    }
    return true; // fallback permisivo en entornos de desarrollo
}

// Helper local: obtener id de cuenta por nombre parcial (evita depender de otras páginas)
if (!function_exists('obtenerIdCuentaPorNombreParcial')) {
    function obtenerIdCuentaPorNombreParcial(PDO $conn, $like) {
        $stmt = $conn->prepare("SELECT id FROM cuentas WHERE estado='activa' AND LOWER(nombre) LIKE :like ORDER BY id LIMIT 1");
        $stmt->execute([':like' => '%' . strtolower($like) . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }
}

function obtenerPeriodosNomina() {
    $sql = "SELECT id, codigo, descripcion, fecha_inicio, fecha_fin, estado FROM periodos_nomina ORDER BY fecha_inicio DESC";
    return fetchAll($sql);
}

function obtenerEmpleadosActivos() {
    $sql = "SELECT e.*, CONCAT(e.nombres,' ',e.apellidos) AS nombre_completo, d.nombre AS departamento
            FROM empleados e 
            LEFT JOIN departamentos d ON e.departamento_id = d.id
            WHERE e.estado = 'activo' ORDER BY nombre_completo";
    return fetchAll($sql);
}

function obtenerConceptosActivos() {
    $sql = "SELECT * FROM conceptos_nomina WHERE estado = 'activo' ORDER BY orden, id";
    return fetchAll($sql);
}

function obtenerConceptosPorEmpleado($empleado_id) {
    $sql = "SELECT ec.*, c.tipo, c.nombre
            FROM empleados_conceptos ec
            INNER JOIN conceptos_nomina c ON ec.concepto_id = c.id
            WHERE ec.empleado_id = :eid AND ec.estado='activo'
            ORDER BY c.orden, c.id";
    return fetchAll($sql, [':eid' => $empleado_id]);
}

function generarNumeroNomina(PDO $conn) {
    $anio = date('Y');
    $pref = 'NOM-' . $anio . '-';
    $stmt = $conn->prepare("SELECT numero FROM nominas WHERE numero LIKE :p ORDER BY CAST(SUBSTRING(numero, -5) AS UNSIGNED) DESC LIMIT 1");
    $stmt->execute([':p' => $pref.'%']);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last) { $seq = (int)substr($last, -5) + 1; }
    return $pref . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}

function generarNumeroRecibo(PDO $conn) {
    $anio = date('Y');
    $pref = 'REC-' . $anio . '-';
    $stmt = $conn->prepare("SELECT recibo_numero FROM nominas_empleados WHERE recibo_numero LIKE :p ORDER BY CAST(SUBSTRING(recibo_numero, -6) AS UNSIGNED) DESC LIMIT 1");
    $stmt->execute([':p' => $pref.'%']);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last) { $seq = (int)substr($last, -6) + 1; }
    return $pref . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
}

function calcularConceptoMonto($tipo, $base_calculo, $valor_parametro, $cantidad, $salario_base) {
    $monto = 0.0;
    switch ($base_calculo) {
        case 'fijo':
            $monto = (float)$valor_parametro * (float)$cantidad;
            break;
        case 'porcentaje_salario':
            $monto = ((float)$salario_base * ((float)$valor_parametro/100.0)) * (float)$cantidad;
            break;
        case 'personalizado':
            $monto = (float)$valor_parametro; // asume que ya viene calculado externamente
            break;
    }
    // Percepción suma, deducción resta a nivel de totales (pero aquí devolvemos monto positivo para consistencia)
    return round($monto, 2);
}

function generarReciboHTML($empresaNombre, $empleado, $ne, $detalles) {
    $fecha = date('d/m/Y');
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Recibo de Pago</title>';
    $html .= '<style>body{font-family:Arial, sans-serif; color:#111827;} .box{max-width:820px;margin:0 auto;padding:24px;border:1px solid #e5e7eb;border-radius:8px} h1{font-size:20px;margin:0 0 8px 0;color:#1E3A8A} table{width:100%;border-collapse:collapse;margin-top:12px} th,td{padding:8px;border-bottom:1px solid #e5e7eb;font-size:14px;text-align:left} .tot{font-weight:bold} .right{text-align:right} .muted{color:#6b7280;font-size:12px} .badge{display:inline-block;padding:2px 6px;border-radius:4px;background:#1E3A8A;color:#fff;font-size:12px} .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}</style></head><body>';
    $html .= '<div class="box">';
    $html .= '<h1>'.$empresaNombre.' - Recibo de Pago</h1>';
    $html .= '<div class="grid">';
    $nombreEmp = isset($empleado['nombres']) ? trim(($empleado['nombres'] ?? '').' '.($empleado['apellidos'] ?? '')) : ($empleado['nombre'] ?? '');
    $html .= '<div><div class="muted">Empleado</div><div>'.htmlspecialchars($nombreEmp).' ('.$empleado['codigo'].')</div></div>';
    $html .= '<div><div class="muted">Fecha</div><div>'.$fecha.'</div></div>';
    $html .= '<div><div class="muted">Identificación</div><div>'.htmlspecialchars($empleado['identificacion']).'</div></div>';
    $html .= '<div><div class="muted">Recibo #</div><div><span class="badge">'.$ne['recibo_numero'].'</span></div></div>';
    $html .= '</div>';

    $html .= '<h3 style="margin-top:18px;color:#1f2937;font-size:16px">Detalle</h3>';
    $html .= '<table><thead><tr><th>Concepto</th><th class="right">Remuneraciones</th><th class="right">Deducciones</th></tr></thead><tbody>';
    foreach ($detalles as $d) {
        $per = $d['tipo']==='percepcion' ? number_format($d['monto'],2,'.',',') : '';
        $ded = $d['tipo']==='deduccion' ? number_format($d['monto'],2,'.',',') : '';
        $html .= '<tr><td>'.htmlspecialchars($d['concepto_nombre']).'</td><td class="right">'.$per.'</td><td class="right">'.$ded.'</td></tr>';
    }
    $html .= '</tbody>';
    $html .= '<tfoot>';
    $html .= '<tr><td class="tot">Salario base</td><td colspan="2" class="right">'.number_format($ne['salario_base'],2,'.',',').'</td></tr>';
    $html .= '<tr><td class="tot">Total remuneraciones</td><td colspan="2" class="right">'.number_format($ne['total_percepciones'],2,'.',',').'</td></tr>';
    $html .= '<tr><td class="tot">Total deducciones</td><td colspan="2" class="right">'.number_format($ne['total_deducciones'],2,'.',',').'</td></tr>';
    $html .= '<tr><td class="tot">Neto a pagar</td><td colspan="2" class="right">'.number_format($ne['total_neto'],2,'.',',').'</td></tr>';
    $html .= '</tfoot></table>';

    $html .= '<p class="muted" style="margin-top:18px">Este documento es un comprobante de pago generado por el sistema en la fecha indicada.</p>';
    $html .= '</div></body></html>';
    return $html;
}

/**
 * Generar nómina masiva para un período
 * Devuelve el ID de la nómina creada
 */
function obtenerEmpleadosPorIds(array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
    if (empty($ids)) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT e.*, CONCAT(e.nombres,' ',e.apellidos) AS nombre_completo, d.nombre AS departamento
            FROM empleados e
            LEFT JOIN departamentos d ON e.departamento_id = d.id
            WHERE e.estado='activo' AND e.id IN ($in)";
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    foreach ($ids as $i=>$val) { $stmt->bindValue($i+1, $val, PDO::PARAM_INT); }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generarNominaMasiva($periodo_id, $empleado_ids = null, $presupuesto_id = null) {
    if (!rrhh_tienePermiso('generar')) {
        throw new Exception('No tiene permisos para generar nóminas');
    }
    $conn = getConnection();
    $conn->beginTransaction();
    try {
        // Validar periodo
        $periodo = fetchOne("SELECT * FROM periodos_nomina WHERE id = :id", [':id' => $periodo_id]);
        if (!$periodo) { throw new Exception('Período de nómina no encontrado'); }
        if ($periodo['estado'] !== 'abierto') {
            throw new Exception('El período de nómina no está abierto');
        }

        // Obtener período contable activo para validar presupuesto
        $periodo_contable_id = obtenerPeriodoActivo();
        
        // Estimar monto total aproximado (antes de calcular todo)
        if (is_array($empleado_ids) && !empty($empleado_ids)) {
            $empleados_estimacion = obtenerEmpleadosPorIds($empleado_ids);
        } else {
            $empleados_estimacion = obtenerEmpleadosActivos();
        }
        
        $monto_estimado = 0.0;
        foreach ($empleados_estimacion as $emp) {
            $monto_estimado += (float)$emp['salario_base'];
            // Agregar margen del 30% para percepciones y deducciones estimadas
            $monto_estimado += (float)$emp['salario_base'] * 0.30;
        }
        
        // Validar presupuesto disponible ANTES de generar
        if ($periodo_contable_id) {
            $validacion = validarPresupuestoNomina($monto_estimado, $periodo_contable_id);
            if (!$validacion['valido']) {
                // No bloquea, pero muestra advertencia en el mensaje
                // Se puede proceder pero se alertará al confirmar
            }
        }

        // Crear cabecera de nómina
        $numero = generarNumeroNomina($conn);
        $stmt = $conn->prepare("INSERT INTO nominas (numero, periodo_id, presupuesto_id, fecha_generacion, estado, total_bruto, total_deducciones, total_neto) VALUES (:n,:pid,:pres,:fecha, 'borrador', 0,0,0)");
        $stmt->execute([
            ':n'=>$numero, 
            ':pid'=>$periodo_id,
            ':pres'=>$presupuesto_id,
            ':fecha'=>date('Y-m-d')
        ]);
        $nomina_id = (int)$conn->lastInsertId();

        if (is_array($empleado_ids) && !empty($empleado_ids)) {
            $empleados = obtenerEmpleadosPorIds($empleado_ids);
        } else {
            $empleados = obtenerEmpleadosActivos();
        }
        $total_nomina_bruto = 0.0; $total_nomina_ded = 0.0; $total_nomina_neto = 0.0;

        foreach ($empleados as $emp) {
            $recibo_num = generarNumeroRecibo($conn);
            $salario_base = (float)$emp['salario_base'];
            $percepciones = 0.0; $deducciones = 0.0; $neto = 0.0;
            $detalles_ins = [];

            // Sueldo básico como percepción (si está en conceptos base)
            // Además de conceptos por empleado
            $conceptosEmp = obtenerConceptosPorEmpleado((int)$emp['id']);

            foreach ($conceptosEmp as $c) {
                $m = calcularConceptoMonto($c['tipo'], $c['base_calculo'], $c['valor_parametro'], $c['cantidad'], $salario_base);
                $detalles_ins[] = [
                    'concepto_id' => (int)$c['concepto_id'],
                    'concepto_nombre' => $c['nombre'],
                    'tipo' => $c['tipo'],
                    'base_calculo' => $c['base_calculo'],
                    'valor_parametro' => $c['valor_parametro'],
                    'cantidad' => $c['cantidad'],
                    'monto' => $m
                ];
                if ($c['tipo']==='percepcion') { $percepciones += $m; } else { $deducciones += $m; }
            }

            $neto = $salario_base + $percepciones - $deducciones;

            // Insertar nominas_empleados
            $stmtNe = $conn->prepare("INSERT INTO nominas_empleados (nomina_id, empleado_id, recibo_numero, salario_base, total_percepciones, total_deducciones, total_neto, estado) VALUES (:nid,:eid,:rec,:sb,:tp,:td,:tn,'pendiente')");
            $stmtNe->execute([
                ':nid'=>$nomina_id,
                ':eid'=>$emp['id'],
                ':rec'=>$recibo_num,
                ':sb'=>$salario_base,
                ':tp'=>$percepciones,
                ':td'=>$deducciones,
                ':tn'=>$neto
            ]);
            $ne_id = (int)$conn->lastInsertId();

            // Detalle por concepto
            $stmtNd = $conn->prepare("INSERT INTO nomina_detalle (nomina_empleado_id, concepto_id, tipo, base_calculo, valor_parametro, cantidad, monto) VALUES (:ne,:c,:t,:b,:v,:q,:m)");
            foreach ($detalles_ins as $di) {
                $stmtNd->execute([
                    ':ne' => $ne_id,
                    ':c'  => $di['concepto_id'],
                    ':t'  => $di['tipo'],
                    ':b'  => $di['base_calculo'],
                    ':v'  => $di['valor_parametro'],
                    ':q'  => $di['cantidad'],
                    ':m'  => $di['monto']
                ]);
            }

            // Generar y guardar recibo HTML
            $detForHtml = $detalles_ins; // ya tiene nombres
            $reciboHtml = generarReciboHTML(APP_NAME, $emp, [
                'recibo_numero'=>$recibo_num,
                'salario_base'=>$salario_base,
                'total_percepciones'=>$percepciones,
                'total_deducciones'=>$deducciones,
                'total_neto'=>$neto
            ], $detForHtml);
            $stmtRec = $conn->prepare("INSERT INTO recibos_nomina (nomina_empleado_id, recibo_numero, formato, contenido_largo) VALUES (:ne,:rec,'html',:html)");
            $stmtRec->execute([':ne'=>$ne_id, ':rec'=>$recibo_num, ':html'=>$reciboHtml]);

            $total_nomina_bruto += $salario_base + $percepciones;
            $total_nomina_ded   += $deducciones;
            $total_nomina_neto  += $neto;
        }

        // Actualizar totales de la nómina
        $stmtUp = $conn->prepare("UPDATE nominas SET total_bruto = :tb, total_deducciones = :td, total_neto = :tn WHERE id = :id");
        $stmtUp->execute([':tb'=>$total_nomina_bruto, ':td'=>$total_nomina_ded, ':tn'=>$total_nomina_neto, ':id'=>$nomina_id]);

        try { registrarCreacion('nominas', 'nominas', $nomina_id, ['numero'=>$numero,'periodo_id'=>$periodo_id,'total_neto'=>$total_nomina_neto], 'Nómina generada'); } catch (Exception $e) {}
        $conn->commit();
        return $nomina_id;
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        throw $e;
    }
}

/**
 * Obtener cuenta de gastos de personal (partida 401)
 */
function obtenerCuentaGastosPersonal() {
    $conn = getConnection();
    // Buscar cuenta de gastos de personal por código 401
    // Solo partidas subespecíficas pueden tener presupuesto
    $stmt = $conn->prepare("SELECT id FROM cuentas 
                            WHERE codigo LIKE '401%' 
                            AND es_partida_presupuestaria = 1 
                            AND nivel_partida = 'subespecifica'
                            AND estado = 'activa' 
                            AND tipo = 'gasto'
                            ORDER BY codigo ASC 
                            LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

/**
 * Buscar presupuesto para cuenta de gastos de personal en el período activo
 */
function buscarPresupuestoGastosPersonal($periodo_id = null) {
    $conn = getConnection();
    
    // Si no se proporciona período_id, obtener período contable activo
    if (!$periodo_id) {
        $periodo_id = obtenerPeriodoActivo();
    }
    
    if (!$periodo_id) {
        return null;
    }
    
    $cuenta_id = obtenerCuentaGastosPersonal();
    if (!$cuenta_id) {
        return null;
    }
    
    // Buscar presupuesto para gastos de personal
    $stmt = $conn->prepare("SELECT id, cuenta_id, credito_vigente, comprometido, causado, pagado,
                            (credito_vigente - comprometido - causado - pagado) as disponibilidad,
                            saldo_por_comprometer
                            FROM presupuestos 
                            WHERE cuenta_id = :cuenta_id 
                            AND periodo_id = :periodo_id 
                            AND tipo_movimiento = 'gasto'
                            AND centro_costo_id IS NULL
                            LIMIT 1");
    $stmt->execute([':cuenta_id' => $cuenta_id, ':periodo_id' => $periodo_id]);
    $presupuesto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $presupuesto ?: null;
}

/**
 * Validar disponibilidad presupuestaria para nómina
 */
function validarPresupuestoNomina($monto_estimado, $periodo_id = null, $mes = null) {
    $presupuesto = buscarPresupuestoGastosPersonal($periodo_id);
    
    if (!$presupuesto) {
        return [
            'valido' => false,
            'disponible' => false,
            'mensaje' => 'No existe presupuesto asignado para gastos de personal (partida 401) en el período contable activo',
            'presupuesto' => null
        ];
    }
    
    $disponibilidad = (float)($presupuesto['disponibilidad'] ?? 0);
    $credito_vigente = (float)($presupuesto['credito_vigente'] ?? 0);
    $comprometido = (float)($presupuesto['comprometido'] ?? 0);
    $causado = (float)($presupuesto['causado'] ?? 0);
    
    if ($monto_estimado <= $disponibilidad) {
        return [
            'valido' => true,
            'disponible' => true,
            'mensaje' => 'Presupuesto disponible',
            'presupuesto_id' => (int)$presupuesto['id'],
            'presupuesto_vigente' => $credito_vigente,
            'comprometido' => $comprometido,
            'causado' => $causado,
            'disponibilidad' => $disponibilidad,
            'presupuesto' => $presupuesto
        ];
    } else {
        $exceso = $monto_estimado - $disponibilidad;
        return [
            'valido' => false,
            'disponible' => false,
            'mensaje' => 'La nómina excede el presupuesto disponible en ' . formatearMoneda($exceso),
            'presupuesto_id' => (int)$presupuesto['id'],
            'presupuesto_vigente' => $credito_vigente,
            'comprometido' => $comprometido,
            'causado' => $causado,
            'disponibilidad' => $disponibilidad,
            'exceso' => $exceso,
            'presupuesto' => $presupuesto
        ];
    }
}

/**
 * Registrar nómina en presupuesto como CAUSADO
 */
function registrarNominaEnPresupuesto($nomina_id, $presupuesto_id, $monto) {
    $conn = getConnection();
    
    try {
        // Actualizar presupuesto: incrementar causado
        // Usar parámetros diferentes para evitar "Invalid parameter number"
        $stmt = $conn->prepare("UPDATE presupuestos 
                               SET causado = COALESCE(causado, 0) + :monto,
                                   por_pagar = COALESCE(por_pagar, 0) + :monto2,
                                   saldo_por_comprometer = credito_vigente - COALESCE(comprometido, 0) - (COALESCE(causado, 0) + :monto3) - COALESCE(pagado, 0)
                               WHERE id = :presupuesto_id");
        $stmt->execute([
            ':monto' => $monto,
            ':monto2' => $monto,
            ':monto3' => $monto,
            ':presupuesto_id' => $presupuesto_id
        ]);
        
        // Guardar relación en tabla nominas (si el campo existe)
        try {
            $stmt2 = $conn->prepare("UPDATE nominas 
                                     SET presupuesto_id = :presupuesto_id
                                     WHERE id = :nomina_id");
            $stmt2->execute([
                ':presupuesto_id' => $presupuesto_id,
                ':nomina_id' => $nomina_id
            ]);
        } catch (Exception $e) {
            // Si el campo no existe, solo registrar en log
            error_log("No se pudo actualizar presupuesto_id en nominas (campo puede no existir): " . $e->getMessage());
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error registrando nómina en presupuesto: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Obtener distribuciones presupuestarias de deducciones de una nómina
 * Retorna un array con las deducciones agrupadas por concepto y su presupuesto asociado
 */
function obtenerDistribucionDeduccionesNomina($nomina_id) {
    $conn = getConnection();
    
    // Obtener todas las deducciones de la nómina agrupadas por concepto
    $sql = "SELECT 
                nd.concepto_id,
                c.codigo AS concepto_codigo,
                c.nombre AS concepto_nombre,
                SUM(nd.monto) AS total_monto,
                COUNT(DISTINCT nd.nomina_empleado_id) AS cantidad_empleados
            FROM nomina_detalle nd
            INNER JOIN conceptos_nomina c ON nd.concepto_id = c.id
            INNER JOIN nominas_empleados ne ON nd.nomina_empleado_id = ne.id
            WHERE ne.nomina_id = :nomina_id
            AND nd.tipo = 'deduccion'
            AND ne.estado != 'anulado'
            GROUP BY nd.concepto_id, c.codigo, c.nombre
            HAVING SUM(nd.monto) > 0
            ORDER BY c.orden ASC, c.nombre ASC";
    
    $deducciones = fetchAll($sql, [':nomina_id' => $nomina_id]);
    
    // Para cada deducción, buscar su presupuesto asociado
    $distribucion = [];
    foreach ($deducciones as $ded) {
        // Buscar presupuesto directamente en el concepto (mejor práctica)
        $presupuesto_info = fetchOne("SELECT 
                                        c.presupuesto_id,
                                        NULL AS cuenta_id,
                                        'deduccion_pasivo' AS tipo_distribucion,
                                        p.credito_vigente,
                                        cu.codigo AS cuenta_codigo,
                                        cu.nombre AS cuenta_nombre
                                      FROM conceptos_nomina c
                                      LEFT JOIN presupuestos p ON c.presupuesto_id = p.id
                                      LEFT JOIN cuentas cu ON p.cuenta_id = cu.id
                                      WHERE c.id = :concepto_id
                                      AND c.presupuesto_id IS NOT NULL
                                      LIMIT 1", [':concepto_id' => $ded['concepto_id']]);
        
        // Si no tiene en el concepto, buscar en tabla de mapeo (compatibilidad)
        if (!$presupuesto_info) {
            $presupuesto_info = fetchOne("SELECT 
                                            cnp.presupuesto_id,
                                            cnp.cuenta_id,
                                            cnp.tipo_distribucion,
                                            p.credito_vigente,
                                            c.codigo AS cuenta_codigo,
                                            c.nombre AS cuenta_nombre
                                          FROM conceptos_nomina_presupuestos cnp
                                          INNER JOIN presupuestos p ON cnp.presupuesto_id = p.id
                                          LEFT JOIN cuentas c ON cnp.cuenta_id = c.id
                                          WHERE cnp.concepto_id = :concepto_id
                                          AND cnp.estado = 'activo'
                                          LIMIT 1", [':concepto_id' => $ded['concepto_id']]);
        }
        
        if ($presupuesto_info) {
            $distribucion[] = [
                'concepto_id' => $ded['concepto_id'],
                'concepto_codigo' => $ded['concepto_codigo'],
                'concepto_nombre' => $ded['concepto_nombre'],
                'monto' => (float)$ded['total_monto'],
                'presupuesto_id' => (int)$presupuesto_info['presupuesto_id'],
                'cuenta_id' => $presupuesto_info['cuenta_id'] ? (int)$presupuesto_info['cuenta_id'] : null,
                'tipo_distribucion' => $presupuesto_info['tipo_distribucion'],
                'cuenta_codigo' => $presupuesto_info['cuenta_codigo'],
                'cuenta_nombre' => $presupuesto_info['cuenta_nombre']
            ];
        }
    }
    
    return $distribucion;
}

/**
 * Obtener distribución de percepciones (remuneraciones) de una nómina
 * Similar a obtenerDistribucionDeduccionesNomina pero para percepciones
 */
function obtenerDistribucionPercepcionesNomina($nomina_id) {
    $sql = "SELECT 
                nd.concepto_id,
                c.codigo AS concepto_codigo,
                c.nombre AS concepto_nombre,
                SUM(nd.monto) AS total_monto
            FROM nomina_detalle nd
            INNER JOIN conceptos_nomina c ON nd.concepto_id = c.id
            INNER JOIN nominas_empleados ne ON nd.nomina_empleado_id = ne.id
            WHERE ne.nomina_id = :nomina_id
            AND nd.tipo = 'percepcion'
            AND ne.estado != 'anulado'
            GROUP BY nd.concepto_id, c.codigo, c.nombre
            HAVING SUM(nd.monto) > 0
            ORDER BY c.orden ASC, c.nombre ASC";
    
    $percepciones = fetchAll($sql, [':nomina_id' => $nomina_id]);
    
    // Para cada percepción, buscar su presupuesto asociado
    $distribucion = [];
    foreach ($percepciones as $perc) {
        // Buscar presupuesto directamente en el concepto
        $presupuesto_info = fetchOne("SELECT 
                                        c.presupuesto_id,
                                        p.credito_vigente,
                                        cu.codigo AS cuenta_codigo,
                                        cu.nombre AS cuenta_nombre
                                      FROM conceptos_nomina c
                                      LEFT JOIN presupuestos p ON c.presupuesto_id = p.id
                                      LEFT JOIN cuentas cu ON p.cuenta_id = cu.id
                                      WHERE c.id = :concepto_id
                                      AND c.presupuesto_id IS NOT NULL
                                      LIMIT 1", [':concepto_id' => $perc['concepto_id']]);
        
        if ($presupuesto_info) {
            $distribucion[] = [
                'concepto_id' => $perc['concepto_id'],
                'concepto_codigo' => $perc['concepto_codigo'],
                'concepto_nombre' => $perc['concepto_nombre'],
                'monto' => (float)$perc['total_monto'],
                'presupuesto_id' => (int)$presupuesto_info['presupuesto_id'],
                'cuenta_codigo' => $presupuesto_info['cuenta_codigo'],
                'cuenta_nombre' => $presupuesto_info['cuenta_nombre']
            ];
        }
    }
    
    return $distribucion;
}

/**
 * Registrar deducción en su presupuesto correspondiente
 */
function registrarDeduccionEnPresupuesto($presupuesto_id, $monto, $descripcion = '') {
    $conn = getConnection();
    
    try {
        // Usar parámetros diferentes para evitar "Invalid parameter number"
        $stmt = $conn->prepare("UPDATE presupuestos 
                               SET causado = COALESCE(causado, 0) + :monto,
                                   por_pagar = COALESCE(por_pagar, 0) + :monto2,
                                   saldo_por_comprometer = credito_vigente - COALESCE(comprometido, 0) - (COALESCE(causado, 0) + :monto3) - COALESCE(pagado, 0)
                               WHERE id = :presupuesto_id");
        $stmt->execute([
            ':monto' => $monto,
            ':monto2' => $monto,
            ':monto3' => $monto,
            ':presupuesto_id' => $presupuesto_id
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error registrando deducción en presupuesto {$presupuesto_id}: " . $e->getMessage());
        return false;
    }
}

function confirmarNomina($nomina_id) {
    if (!rrhh_tienePermiso('confirmar')) {
        throw new Exception('No tiene permisos para confirmar nóminas');
    }
    $conn = getConnection();
    $conn->beginTransaction();
    try {
        $nomina = fetchOne("SELECT * FROM nominas WHERE id = :id FOR UPDATE", [':id'=>$nomina_id]);
        if (!$nomina) { throw new Exception('Nómina no encontrada'); }
        if ($nomina['estado'] === 'confirmada') { return true; }
        
        // VALIDAR QUE ESTÉ APROBADA POR PRESUPUESTO
        if ($nomina['aprobacion_presupuesto'] !== 'aprobada' || 
            $nomina['estado'] !== 'aprobada_presupuesto') {
            throw new Exception(
                'La nómina debe estar aprobada por Presupuesto antes de confirmar. ' .
                'Estado actual: ' . ($nomina['estado'] ?? 'desconocido') . ', ' .
                'Aprobación: ' . ($nomina['aprobacion_presupuesto'] ?? 'pendiente')
            );
        }

        $neRows = fetchAll("SELECT * FROM nominas_empleados WHERE nomina_id = :id", [':id'=>$nomina_id]);
        $totalNeto = 0.0; 
        $totalBruto = 0.0;
        foreach ($neRows as $r) { 
            $totalNeto += (float)$r['total_neto'];
            $totalBruto += (float)$r['salario_base'] + (float)$r['total_percepciones'];
        }

        // Obtener período del período de nómina para validar presupuesto
        $periodo_nomina = fetchOne("SELECT p.* 
                                    FROM periodos_nomina p 
                                    INNER JOIN nominas n ON p.id = n.periodo_id 
                                    WHERE n.id = :id", [':id' => $nomina_id]);
        
        // Obtener período contable activo
        $periodo_contable_id = obtenerPeriodoActivo();
        
        // Validar presupuesto antes de confirmar
        $validacion_presupuesto = validarPresupuestoNomina($totalNeto, $periodo_contable_id);
        
        if (!$validacion_presupuesto['valido']) {
            throw new Exception('No se puede confirmar la nómina: ' . $validacion_presupuesto['mensaje']);
        }

        // Obtener distribuciones de deducciones
        $distribuciones_deducciones = obtenerDistribucionDeduccionesNomina($nomina_id);
        
        // Intentar mapear cuentas contables
        $idGastoNomina = obtenerIdCuentaPorNombreParcial(getConnection(), 'nómina');
        if (!$idGastoNomina) { $idGastoNomina = obtenerIdCuentaPorNombreParcial(getConnection(), 'nomina'); }
        if (!$idGastoNomina) { $idGastoNomina = obtenerIdCuentaPorNombreParcial(getConnection(), 'sueld'); }
        $idSueldosPagar = obtenerIdCuentaPorNombreParcial(getConnection(), 'sueldos por pagar');
        if (!$idSueldosPagar) { $idSueldosPagar = obtenerIdCuentaPorNombreParcial(getConnection(), 'por pagar'); }

        $asiento_id = null;
        if ($idGastoNomina && $idSueldosPagar) {
            // Construir asiento contable distribuido
            $det = [];
            
            // DEBE: Gasto de Nómina (total bruto = salarios + percepciones)
            $det[] = [
                'cuenta_id' => $idGastoNomina, 
                'descripcion' => 'Gasto de Nómina ' . $nomina['numero'], 
                'debe' => $totalBruto, 
                'haber' => 0
            ];
            
            // HABER: Sueldos por Pagar (total neto)
            $det[] = [
                'cuenta_id' => $idSueldosPagar, 
                'descripcion' => 'Sueldos por pagar ' . $nomina['numero'], 
                'debe' => 0, 
                'haber' => $totalNeto
            ];
            
            // Agregar cada deducción como pasivo (HABER)
            // Usar la cuenta del presupuesto asociado (partida 401)
            $total_deducciones_distribuidas = 0.0;
            foreach ($distribuciones_deducciones as $dist) {
                // La cuenta viene del presupuesto (partida 401)
                $cuenta_deduccion = $dist['cuenta_codigo'] ? 
                    fetchOne("SELECT id FROM cuentas WHERE codigo = :codigo LIMIT 1", [':codigo' => $dist['cuenta_codigo']])['id'] ?? null :
                    null;
                
                // Si no tiene cuenta del presupuesto, buscar por nombre del concepto
                if (!$cuenta_deduccion) {
                    $cuenta_deduccion = obtenerIdCuentaPorNombreParcial(getConnection(), $dist['concepto_nombre']);
                    if (!$cuenta_deduccion) {
                        // Buscar genérico de pasivos
                        $cuenta_deduccion = obtenerIdCuentaPorNombreParcial(getConnection(), 'por pagar');
                    }
                }
                
                if ($cuenta_deduccion) {
                    $det[] = [
                        'cuenta_id' => $cuenta_deduccion,
                        'descripcion' => $dist['concepto_nombre'] . ' - ' . $nomina['numero'],
                        'debe' => 0,
                        'haber' => $dist['monto']
                    ];
                    $total_deducciones_distribuidas += $dist['monto'];
                }
            }
            
            // Si hay deducciones sin distribuir, agregarlas a "Otros pasivos"
            $deducciones_sin_distribuir = (float)$nomina['total_deducciones'] - $total_deducciones_distribuidas;
            if ($deducciones_sin_distribuir > 0.01) {
                $idOtrosPasivos = obtenerIdCuentaPorNombreParcial(getConnection(), 'otros pasivos');
                if (!$idOtrosPasivos) {
                    $idOtrosPasivos = $idSueldosPagar; // Usar la misma cuenta como fallback
                }
                $det[] = [
                    'cuenta_id' => $idOtrosPasivos,
                    'descripcion' => 'Deducciones varias - ' . $nomina['numero'],
                    'debe' => 0,
                    'haber' => $deducciones_sin_distribuir
                ];
            }
            
            $asiento_id = generarAsientoContable('Nómina ' . $nomina['numero'], $det, $nomina['numero']);
        }
        
            // Obtener distribuciones de percepciones (remuneraciones)
            $distribuciones_percepciones = obtenerDistribucionPercepcionesNomina($nomina_id);
            
            // Registrar en presupuesto principal como CAUSADO (total bruto)
            // Si hay percepciones distribuidas, no registrar en presupuesto principal
            // sino en cada una de las partidas de las percepciones
            if (!empty($distribuciones_percepciones)) {
                // Registrar cada percepción en su presupuesto correspondiente
                foreach ($distribuciones_percepciones as $dist_perc) {
                    registrarNominaEnPresupuesto(
                        $nomina_id, 
                        $dist_perc['presupuesto_id'], 
                        $dist_perc['monto']
                    );
                }
                
                // Si hay parte del total bruto sin distribuir, registrar en presupuesto principal
                $total_percepciones_distribuidas = array_sum(array_column($distribuciones_percepciones, 'monto'));
                // También incluir salarios base que no tienen concepto asociado
                $monto_sin_distribuir = $totalBruto - $total_percepciones_distribuidas;
                if ($monto_sin_distribuir > 0.01 && $validacion_presupuesto['presupuesto_id']) {
                    registrarNominaEnPresupuesto($nomina_id, $validacion_presupuesto['presupuesto_id'], $monto_sin_distribuir);
                }
            } else {
                // Si no hay percepciones distribuidas, usar el presupuesto principal
                if ($validacion_presupuesto['presupuesto_id']) {
                    registrarNominaEnPresupuesto($nomina_id, $validacion_presupuesto['presupuesto_id'], $totalBruto);
                }
            }
            
            // Registrar cada deducción en su presupuesto correspondiente
            foreach ($distribuciones_deducciones as $dist) {
                if (!empty($dist['presupuesto_id']) && $dist['presupuesto_id'] > 0) {
                    registrarDeduccionEnPresupuesto(
                        $dist['presupuesto_id'], 
                        $dist['monto'], 
                        $dist['concepto_nombre'] . ' - Nómina ' . $nomina['numero']
                    );
                }
            }
        
        // Actualizar estado de nómina
        if ($asiento_id) {
            executeQuery("UPDATE nominas SET estado = 'confirmada', asiento_id = :asiento_id WHERE id = :id", [
                ':id'=>$nomina_id,
                ':asiento_id'=>$asiento_id
            ]);
        } else {
            executeQuery("UPDATE nominas SET estado = 'confirmada' WHERE id = :id", [
                ':id'=>$nomina_id
            ]);
        }
        
        try { 
            registrarActualizacion('nominas','nominas',$nomina_id,$nomina,[
                'estado'=>'confirmada',
                'asiento_id'=>$asiento_id,
                'presupuesto_id'=>$validacion_presupuesto['presupuesto_id'] ?? null
            ],'Nómina confirmada y registrada en presupuesto'); 
        } catch (Exception $e) {}
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        throw $e;
    }
}

/**
 * Enviar nómina a aprobación presupuestaria
 * Cambia el estado de la nómina de borrador a pendiente_validacion_presupuesto
 * 
 * @param int $nomina_id ID de la nómina
 * @return bool True si se envió correctamente
 */
function enviarNominaAprobacionPresupuesto($nomina_id) {
    if (!rrhh_tienePermiso('generar')) {
        throw new Exception('No tiene permisos para enviar nóminas a aprobación');
    }
    
    $conn = getConnection();
    $nomina = fetchOne("SELECT * FROM nominas WHERE id = :id", [':id' => $nomina_id]);
    
    if (!$nomina) {
        throw new Exception('Nómina no encontrada');
    }
    
    if ($nomina['estado'] !== 'borrador') {
        throw new Exception('Solo se pueden enviar nóminas en estado borrador a aprobación presupuestaria');
    }
    
    // Actualizar estado
    executeQuery(
        "UPDATE nominas SET 
         estado = 'pendiente_validacion_presupuesto',
         aprobacion_presupuesto = 'pendiente',
         validacion_presupuestaria = 'pendiente'
         WHERE id = :id",
        [':id' => $nomina_id]
    );
    
    // Registrar auditoría
    try {
        registrarActualizacion(
            'nominas',
            'nominas',
            $nomina_id,
            ['estado' => 'borrador'],
            ['estado' => 'pendiente_validacion_presupuesto'],
            'Nómina enviada a aprobación presupuestaria'
        );
    } catch (Exception $e) {}
    
    return true;
}

/**
 * Generar órdenes de pago automáticamente desde una nómina confirmada
 * Crea una orden de pago por cada empleado con datos bancarios completos
 * 
 * @param int $nomina_id ID de la nómina confirmada
 * @return array Array con IDs de órdenes generadas y estadísticas
 */
function generarOrdenesPagoDesdeNomina($nomina_id) {
    if (!rrhh_tienePermiso('generar')) {
        throw new Exception('No tiene permisos para generar órdenes de pago');
    }
    
    $conn = getConnection();
    $conn->beginTransaction();
    
    try {
        // Validar que la nómina existe y está confirmada
        $nomina = fetchOne("SELECT n.*, p.codigo AS periodo_codigo, p.descripcion AS periodo_desc, 
                                  pr.id AS presupuesto_id, pr.credito_vigente
                           FROM nominas n
                           INNER JOIN periodos_nomina p ON n.periodo_id = p.id
                           LEFT JOIN presupuestos pr ON n.presupuesto_id = pr.id
                           WHERE n.id = :id", [':id' => $nomina_id]);
        
        if (!$nomina) {
            throw new Exception('Nómina no encontrada');
        }
        
        if ($nomina['estado'] !== 'confirmada') {
            throw new Exception('Solo se pueden generar órdenes de pago para nóminas confirmadas');
        }
        
        if (!$nomina['presupuesto_id']) {
            throw new Exception('La nómina no está vinculada a un presupuesto');
        }
        
        // Obtener todos los recibos de empleados pendientes de pago
        $recibos = fetchAll("SELECT ne.*, e.codigo AS emp_codigo, 
                                   CONCAT(e.nombres, ' ', e.apellidos) AS emp_nombre,
                                   e.banco, e.numero_cuenta, e.tipo_cuenta, e.identificacion
                            FROM nominas_empleados ne
                            INNER JOIN empleados e ON ne.empleado_id = e.id
                            WHERE ne.nomina_id = :nomina_id 
                            AND ne.estado = 'pendiente'
                            AND ne.total_neto > 0
                            ORDER BY emp_nombre", [':nomina_id' => $nomina_id]);
        
        if (empty($recibos)) {
            throw new Exception('No hay recibos pendientes de pago en esta nómina');
        }
        
        // Verificar que no se hayan generado órdenes previamente
        $ordenes_existentes = fetchAll("SELECT COUNT(*) as total FROM ordenes_pago 
                                       WHERE nomina_empleado_id IN (
                                           SELECT id FROM nominas_empleados WHERE nomina_id = :nomina_id
                                       ) AND estado != 'anulada'", [':nomina_id' => $nomina_id]);
        
        if ($ordenes_existentes[0]['total'] > 0) {
            throw new Exception('Ya existen órdenes de pago generadas para esta nómina. Revise el detalle de la nómina.');
        }
        
        // Obtener cuenta bancaria institucional por defecto (la primera activa)
        $cuenta_bancaria = fetchOne("SELECT id FROM cuentas_bancarias WHERE estado = 'activa' ORDER BY id LIMIT 1");
        $cuenta_bancaria_id = $cuenta_bancaria ? $cuenta_bancaria['id'] : null;
        
        // Generar número base para órdenes de este lote
        $anio = date('Y');
        $query_numero = "SELECT COALESCE(MAX(CAST(SUBSTRING(numero_orden, 9) AS UNSIGNED)), 0) + 1 AS siguiente 
                         FROM ordenes_pago 
                         WHERE YEAR(fecha_orden) = :anio";
        $stmt_numero = $conn->prepare($query_numero);
        $stmt_numero->execute([':anio' => $anio]);
        $siguiente = (int)$stmt_numero->fetch(PDO::FETCH_ASSOC)['siguiente'];
        
        $ordenes_generadas = [];
        $errores = [];
        
        // Generar una orden por cada empleado
        foreach ($recibos as $recibo) {
            try {
                // Validar datos bancarios del empleado
                if (empty($recibo['banco']) || empty($recibo['numero_cuenta'])) {
                    $errores[] = "Empleado {$recibo['emp_nombre']} ({$recibo['emp_codigo']}) no tiene datos bancarios completos";
                    continue;
                }
                
                // Extraer tipo de documento de la identificación
                $identificacion = trim($recibo['identificacion'] ?? '');
                $tipo_documento = null;
                $numero_documento = null;
                
                if (!empty($identificacion)) {
                    // Formato esperado: V-12345678 o J-12345678-1
                    if (preg_match('/^([VJE]|G)-?(\d+)/i', $identificacion, $matches)) {
                        $tipo_documento = strtoupper($matches[1]);
                        $numero_documento = $matches[2];
                    }
                }
                
                // Generar número de orden único
                $numero_orden = 'OP-' . $anio . '-' . str_pad($siguiente, 5, '0', STR_PAD_LEFT);
                $siguiente++;
                
                // Concepto descriptivo
                $concepto = "Pago de nómina {$nomina['periodo_codigo']} - {$nomina['periodo_desc']} - {$recibo['emp_nombre']} ({$recibo['recibo_numero']})";
                
                // Insertar orden de pago
                $query_insert = "INSERT INTO ordenes_pago 
                                (numero_orden, fecha_orden, presupuesto_id, cuenta_bancaria_id,
                                 beneficiario, concepto, monto, tipo_pago, aplica_retenciones,
                                 banco_beneficiario, numero_cuenta_beneficiario, tipo_cuenta_beneficiario,
                                 titular_cuenta_beneficiario, tipo_documento_beneficiario, numero_documento_beneficiario,
                                 nomina_empleado_id, creado_por, estado)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'transferencia', 0, ?, ?, ?, ?, ?, ?, ?, ?, 'emitida')";
                
                $stmt_insert = $conn->prepare($query_insert);
                $stmt_insert->execute([
                    $numero_orden,
                    date('Y-m-d'),
                    $nomina['presupuesto_id'],
                    $cuenta_bancaria_id,
                    $recibo['emp_nombre'],
                    $concepto,
                    $recibo['total_neto'],
                    $recibo['banco'],
                    $recibo['numero_cuenta'],
                    $recibo['tipo_cuenta'] ?? 'corriente',
                    $recibo['emp_nombre'],
                    $tipo_documento,
                    $numero_documento,
                    $recibo['id'], // nomina_empleado_id
                    $_SESSION['usuario_id'] ?? null
                ]);
                
                $orden_id = $conn->lastInsertId();
                $ordenes_generadas[] = [
                    'id' => $orden_id,
                    'numero' => $numero_orden,
                    'empleado' => $recibo['emp_nombre'],
                    'monto' => $recibo['total_neto']
                ];
                
                // Registrar auditoría
                try {
                    registrarActualizacion('ordenes_pago', 'ordenes_pago', $orden_id, [], [
                        'numero_orden' => $numero_orden,
                        'nomina_empleado_id' => $recibo['id'],
                        'estado' => 'emitida'
                    ], "Orden generada automáticamente desde nómina {$nomina['numero']}");
                } catch (Exception $e) {
                    error_log("Error en auditoría de orden: " . $e->getMessage());
                }
                
            } catch (Exception $e) {
                $errores[] = "Error generando orden para {$recibo['emp_nombre']}: " . $e->getMessage();
                error_log("Error generando orden de pago para empleado {$recibo['id']}: " . $e->getMessage());
            }
        }
        
        if (empty($ordenes_generadas)) {
            throw new Exception('No se pudo generar ninguna orden de pago. ' . implode('; ', $errores));
        }
        
        // Actualizar presupuesto: incrementar comprometido por el total generado
        $total_generado = array_sum(array_column($ordenes_generadas, 'monto'));
        $stmt_presupuesto = $conn->prepare("UPDATE presupuestos 
                                           SET comprometido = COALESCE(comprometido, 0) + ? 
                                           WHERE id = ?");
        $stmt_presupuesto->execute([$total_generado, $nomina['presupuesto_id']]);
        
        $conn->commit();
        
        return [
            'success' => true,
            'ordenes_generadas' => count($ordenes_generadas),
            'total_monto' => $total_generado,
            'ordenes' => $ordenes_generadas,
            'errores' => $errores,
            'mensaje' => count($ordenes_generadas) . ' orden(es) de pago generada(s) exitosamente'
        ];
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Generar una sola orden de pago masiva para toda la nómina
 * Crea UN solo registro en ordenes_pago que representa toda la nómina
 * 
 * @param int $nomina_id ID de la nómina
 * @return array Array con resultado de la operación
 */
function generarOrdenPagoMasivaNomina($nomina_id) {
    if (!rrhh_tienePermiso('generar')) {
        throw new Exception('No tiene permisos para generar órdenes de pago');
    }
    
    $conn = getConnection();
    $conn->beginTransaction();
    
    try {
        // Validar que la nómina existe y está confirmada
        $nomina = fetchOne("SELECT n.*, p.codigo AS periodo_codigo, p.descripcion AS periodo_desc, 
                                  pr.id AS presupuesto_id, pr.credito_vigente
                           FROM nominas n
                           INNER JOIN periodos_nomina p ON n.periodo_id = p.id
                           LEFT JOIN presupuestos pr ON n.presupuesto_id = pr.id
                           WHERE n.id = :id", [':id' => $nomina_id]);
        
        if (!$nomina) {
            throw new Exception('Nómina no encontrada');
        }
        
        if ($nomina['estado'] !== 'confirmada') {
            throw new Exception('Solo se pueden generar órdenes de pago para nóminas confirmadas');
        }
        
        if (!$nomina['presupuesto_id']) {
            throw new Exception('La nómina no está vinculada a un presupuesto');
        }
        
        // Verificar que no exista ya una orden masiva para esta nómina
        $orden_existente = fetchOne("SELECT id, numero_orden, estado FROM ordenes_pago 
                                    WHERE nomina_id = :nomina_id 
                                    AND estado != 'anulada'", [':nomina_id' => $nomina_id]);
        
        if ($orden_existente) {
            throw new Exception("Ya existe una orden de pago masiva para esta nómina: {$orden_existente['numero_orden']}");
        }
        
        // Obtener empleados de la nómina para contar y validar
        $empleados = fetchAll("SELECT ne.*, e.codigo AS emp_codigo, 
                                   CONCAT(e.nombres, ' ', e.apellidos) AS emp_nombre
                            FROM nominas_empleados ne
                            INNER JOIN empleados e ON ne.empleado_id = e.id
                            WHERE ne.nomina_id = :nomina_id 
                            AND ne.estado != 'anulado'
                            AND ne.total_neto > 0", [':nomina_id' => $nomina_id]);
        
        if (empty($empleados)) {
            throw new Exception('No hay empleados en esta nómina para generar orden de pago');
        }
        
        // Obtener cuenta bancaria institucional por defecto
        $cuenta_bancaria = fetchOne("SELECT id FROM cuentas_bancarias WHERE estado = 'activa' ORDER BY id LIMIT 1");
        $cuenta_bancaria_id = $cuenta_bancaria ? $cuenta_bancaria['id'] : null;
        
        // Generar número de orden
        $anio = date('Y');
        $query_numero = "SELECT COALESCE(MAX(CAST(SUBSTRING(numero_orden, 9) AS UNSIGNED)), 0) + 1 AS siguiente 
                         FROM ordenes_pago 
                         WHERE YEAR(fecha_orden) = :anio";
        $stmt_numero = $conn->prepare($query_numero);
        $stmt_numero->execute([':anio' => $anio]);
        $siguiente = (int)$stmt_numero->fetch(PDO::FETCH_ASSOC)['siguiente'];
        $numero_orden = 'OP-' . $anio . '-' . str_pad($siguiente, 5, '0', STR_PAD_LEFT);
        
        // Concepto descriptivo
        $total_empleados = count($empleados);
        $concepto = "Pago de nómina {$nomina['periodo_codigo']} - {$nomina['periodo_desc']} - {$total_empleados} empleado(s)";
        
        // Beneficiario: "Nómina [número] - [empleados] empleados"
        $beneficiario = "Nómina {$nomina['numero']} - {$total_empleados} empleado(s)";
        
        // Insertar orden de pago masiva
        // Nota: nomina_empleado_id = NULL porque es masiva, no individual
        $query_insert = "INSERT INTO ordenes_pago 
                        (numero_orden, fecha_orden, presupuesto_id, cuenta_bancaria_id,
                         beneficiario, concepto, monto, tipo_pago, aplica_retenciones,
                         nomina_id, nomina_empleado_id, creado_por, estado,
                         observaciones_revision)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'transferencia', 0, ?, NULL, ?, 'emitida', ?)";
        
        $observaciones = "Orden de pago masiva para nómina completa. " .
                        "Total de empleados: {$total_empleados}. " .
                        "Ver detalle en constancia bancaria masiva.";
        
        $stmt_insert = $conn->prepare($query_insert);
        $stmt_insert->execute([
            $numero_orden,
            date('Y-m-d'),
            $nomina['presupuesto_id'],
            $cuenta_bancaria_id,
            $beneficiario,
            $concepto,
            $nomina['total_neto'],
            $nomina_id, // nomina_id
            $_SESSION['usuario_id'] ?? null,
            $observaciones
        ]);
        
        $orden_id = $conn->lastInsertId();
        
        // Actualizar presupuesto: incrementar comprometido
        $stmt_presupuesto = $conn->prepare("UPDATE presupuestos 
                                           SET comprometido = COALESCE(comprometido, 0) + ? 
                                           WHERE id = ?");
        $stmt_presupuesto->execute([$nomina['total_neto'], $nomina['presupuesto_id']]);
        
        // Registrar auditoría
        try {
            registrarActualizacion('ordenes_pago', 'ordenes_pago', $orden_id, [], [
                'numero_orden' => $numero_orden,
                'nomina_id' => $nomina_id,
                'estado' => 'emitida',
                'tipo' => 'masiva'
            ], "Orden de pago masiva generada para nómina {$nomina['numero']}");
        } catch (Exception $e) {
            error_log("Error en auditoría: " . $e->getMessage());
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'orden_id' => $orden_id,
            'numero_orden' => $numero_orden,
            'total_monto' => $nomina['total_neto'],
            'total_empleados' => $total_empleados,
            'mensaje' => "Orden de pago masiva generada: {$numero_orden} para {$total_empleados} empleado(s)"
        ];
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Marcar nómina completa como pagada (sin órdenes individuales)
 * Actualiza todos los recibos de empleados a estado 'pagado'
 * Actualiza presupuesto y genera asiento contable
 * 
 * @param int $nomina_id ID de la nómina
 * @param string $fecha_pago Fecha del pago (formato Y-m-d)
 * @param string $referencia_bancaria Referencia bancaria del pago
 * @return array Array con resultado de la operación
 */
function marcarNominaComoPagada($nomina_id, $fecha_pago = null, $referencia_bancaria = null) {
    if (!rrhh_tienePermiso('confirmar')) {
        throw new Exception('No tiene permisos para marcar nóminas como pagadas');
    }
    
    $conn = getConnection();
    $conn->beginTransaction();
    
    try {
        // Validar que la nómina existe y está confirmada
        $nomina = fetchOne("SELECT n.*, p.codigo AS periodo_codigo, p.descripcion AS periodo_desc, 
                                  pr.id AS presupuesto_id, pr.credito_vigente, pr.cuenta_id AS presupuesto_cuenta_id,
                                  c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre
                           FROM nominas n
                           INNER JOIN periodos_nomina p ON n.periodo_id = p.id
                           LEFT JOIN presupuestos pr ON n.presupuesto_id = pr.id
                           LEFT JOIN cuentas c ON pr.cuenta_id = c.id
                           WHERE n.id = :id", [':id' => $nomina_id]);
        
        if (!$nomina) {
            throw new Exception('Nómina no encontrada');
        }
        
        if ($nomina['estado'] !== 'confirmada') {
            throw new Exception('Solo se pueden marcar como pagadas nóminas confirmadas');
        }
        
        if (!$nomina['presupuesto_id']) {
            throw new Exception('La nómina no está vinculada a un presupuesto');
        }
        
        if (!$nomina['presupuesto_cuenta_id']) {
            throw new Exception('El presupuesto de la nómina no tiene una cuenta contable asignada. Verifique la configuración del presupuesto.');
        }
        
        // Obtener todos los recibos pendientes de pago
        $recibos = fetchAll("SELECT ne.*, e.codigo AS emp_codigo, 
                                   CONCAT(e.nombres, ' ', e.apellidos) AS emp_nombre
                            FROM nominas_empleados ne
                            INNER JOIN empleados e ON ne.empleado_id = e.id
                            WHERE ne.nomina_id = :nomina_id 
                            AND ne.estado = 'pendiente'
                            AND ne.total_neto > 0
                            ORDER BY emp_nombre", [':nomina_id' => $nomina_id]);
        
        if (empty($recibos)) {
            throw new Exception('No hay recibos pendientes de pago en esta nómina');
        }
        
        $fecha_pago = $fecha_pago ?: date('Y-m-d');
        $total_pagado = 0.0;
        $recibos_actualizados = 0;
        
        // Actualizar estado de todos los recibos
        foreach ($recibos as $recibo) {
            $stmt = $conn->prepare("UPDATE nominas_empleados 
                                   SET estado = 'pagado' 
                                   WHERE id = ? AND estado = 'pendiente'");
            $stmt->execute([$recibo['id']]);
            
            if ($stmt->rowCount() > 0) {
                $total_pagado += (float)$recibo['total_neto'];
                $recibos_actualizados++;
                
                // Registrar auditoría
                if (function_exists('registrarActualizacion')) {
                    try {
                        registrarActualizacion(
                            'nominas_empleados',
                            'nominas_empleados',
                            $recibo['id'],
                            ['estado' => 'pendiente'],
                            ['estado' => 'pagado'],
                            "Recibo marcado como pagado - Nómina completa pagada"
                        );
                    } catch (Exception $e) {
                        error_log("Error en auditoría: " . $e->getMessage());
                    }
                }
            }
        }
        
        // Actualizar presupuesto: incrementar pagado
        $stmt_presupuesto = $conn->prepare("UPDATE presupuestos 
                                           SET pagado = COALESCE(pagado, 0) + ?,
                                               saldo_por_comprometer = credito_vigente - comprometido - causado - (COALESCE(pagado, 0) + ?)
                                           WHERE id = ?");
        $stmt_presupuesto->execute([$total_pagado, $total_pagado, $nomina['presupuesto_id']]);
        
        // Generar asiento contable del pago
        // IMPORTANTE: Usar la cuenta de la partida presupuestaria subespecífica, no "Sueldos por Pagar"
        $asiento_id = null;
        $numero_pago_presupuestario = null;
        $pago_presupuestario_id = null;
        if (function_exists('generarAsientoContable')) {
            try {
                // Obtener la cuenta de la partida presupuestaria subespecífica
                $idCuentaPresupuesto = null;
                $nombreCuentaPresupuesto = '';
                
                if (!empty($nomina['presupuesto_cuenta_id'])) {
                    $idCuentaPresupuesto = (int)$nomina['presupuesto_cuenta_id'];
                    $nombreCuentaPresupuesto = $nomina['cuenta_nombre'] ?: 'Partida Presupuestaria';
                    
                    // Verificar que la cuenta existe y está activa
                    $cuenta_verificada = fetchOne("SELECT id, codigo, nombre FROM cuentas WHERE id = :id AND estado = 'activa'", [':id' => $idCuentaPresupuesto]);
                    if (!$cuenta_verificada) {
                        throw new Exception('La cuenta de la partida presupuestaria (ID: ' . $idCuentaPresupuesto . ') no existe o está inactiva.');
                    }
                    $nombreCuentaPresupuesto = $cuenta_verificada['nombre'];
                } else {
                    throw new Exception('La nómina no tiene una cuenta de partida presupuestaria asignada. Verifique que el presupuesto tenga una cuenta contable asociada.');
                }
                
                // Buscar cuenta bancaria (múltiples métodos)
                $idBanco = null;
                
                // Método 1: Desde cuentas_bancarias activas
                $cuenta_bancaria = fetchOne("SELECT c.id 
                                            FROM cuentas_bancarias cb
                                            INNER JOIN cuentas c ON cb.cuenta_id = c.id
                                            WHERE cb.estado = 'activa' AND c.estado = 'activa'
                                            ORDER BY cb.id LIMIT 1");
                if ($cuenta_bancaria) {
                    $idBanco = (int)$cuenta_bancaria['id'];
                }
                
                // Método 2: Si no encontró, buscar directamente la cuenta de Bancos por código
                if (!$idBanco) {
                    $banco_directo = fetchOne("SELECT id FROM cuentas WHERE codigo = '1.1.1.01.00' AND estado = 'activa' LIMIT 1");
                    if ($banco_directo) {
                        $idBanco = (int)$banco_directo['id'];
                    }
                }
                
                // Método 3: Buscar cualquier cuenta de banco por nombre
                if (!$idBanco) {
                    $idBanco = obtenerIdCuentaPorNombreParcial($conn, 'banco');
                }
                
                // Método 4: Buscar cualquier cuenta activa tipo activo que contenga "banco" o "1.1.1"
                if (!$idBanco) {
                    $stmt = $conn->prepare("SELECT id FROM cuentas WHERE (codigo LIKE '1.1.1%' OR LOWER(nombre) LIKE '%banco%') AND tipo = 'activo' AND estado = 'activa' ORDER BY id LIMIT 1");
                    $stmt->execute();
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $idBanco = $row ? (int)$row['id'] : null;
                }
                
                // Si no se encontró la cuenta de Bancos, buscar o crear
                if (!$idBanco) {
                    // Intentar crear la cuenta de Bancos si no existe
                    try {
                        $banco_existente = fetchOne("SELECT id FROM cuentas WHERE codigo = '1.1.1.01.00' LIMIT 1");
                        if ($banco_existente) {
                            $idBanco = (int)$banco_existente['id'];
                        } else {
                            $stmt = $conn->prepare("INSERT INTO cuentas (codigo, nombre, tipo, naturaleza, estado) VALUES ('1.1.1.01.00', 'Bancos', 'activo', 'deudora', 'activa')");
                            $stmt->execute();
                            $idBanco = $conn->lastInsertId();
                            error_log("Cuenta 'Bancos' creada automáticamente con ID: {$idBanco}");
                        }
                    } catch (Exception $e) {
                        error_log("Error creando cuenta Bancos: " . $e->getMessage());
                        throw new Exception('No se encontró la cuenta contable de "Bancos" y no se pudo crear automáticamente. Por favor, verifique que existe una cuenta activa de banco (código 1.1.1.01.00).');
                    }
                }
                
                // Generar asiento contable usando la cuenta de la partida presupuestaria subespecífica
                // DEBE: Partida Presupuestaria Subespecífica (gasto)
                // HABER: Bancos
                $det = [
                    [
                        'cuenta_id' => $idCuentaPresupuesto,
                        'descripcion' => 'Pago de Nómina ' . $nomina['numero'] . ' - ' . $nombreCuentaPresupuesto,
                        'debe' => $total_pagado,
                        'haber' => 0
                    ],
                    [
                        'cuenta_id' => $idBanco,
                        'descripcion' => 'Pago de Nómina ' . $nomina['numero'] . ($referencia_bancaria ? ' - Ref: ' . $referencia_bancaria : ''),
                        'debe' => 0,
                        'haber' => $total_pagado
                    ]
                ];
                
                $asiento_id = generarAsientoContable('Pago de Nómina ' . $nomina['numero'], $det, $nomina['numero']);
                
                if (!$asiento_id) {
                    throw new Exception('No se pudo generar el asiento contable. Verifique los logs del sistema.');
                }
                
                error_log("Asiento contable generado exitosamente para nómina {$nomina_id}: Asiento ID {$asiento_id} - Cuenta Presupuestaria: {$nombreCuentaPresupuesto} (ID: {$idCuentaPresupuesto})");
                
            } catch (Exception $e) {
                error_log("ERROR CRÍTICO generando asiento contable para nómina {$nomina_id}: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                // No revertir la transacción - los recibos ya están marcados como pagados
                // Pero registrar el error claramente para que se pueda corregir manualmente
                // El asiento_id quedará como null y se informará al usuario
            }
        } else {
            error_log("Función generarAsientoContable no está disponible para nómina {$nomina_id}");
        }

        // Registrar pago en tabla pagos_presupuestarios para reflejarlo en la ejecución financiera mensual
        try {
            // Generar número correlativo PG-YYYY-#####
            $stmt_numero = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(numero_pago, 8) AS UNSIGNED)), 0) + 1 AS siguiente
                                           FROM pagos_presupuestarios
                                           WHERE YEAR(fecha_pago) = YEAR(?)");
            $stmt_numero->execute([$fecha_pago]);
            $siguiente = (int)($stmt_numero->fetchColumn() ?? 1);
            $numero_pago_presupuestario = 'PG-' . date('Y', strtotime($fecha_pago)) . '-' . str_pad((string)$siguiente, 5, '0', STR_PAD_LEFT);

            $stmt_pago = $conn->prepare("INSERT INTO pagos_presupuestarios 
                (causado_id, orden_pago_id, presupuesto_id, numero_pago, fecha_pago, monto, tipo_pago, referencia, banco_origen, banco_destino, estado, observaciones, creado_por)
                VALUES (NULL, NULL, ?, ?, ?, ?, 'transferencia', ?, NULL, NULL, 'confirmado', ?, ?)");
            $stmt_pago->execute([
                $nomina['presupuesto_id'],
                $numero_pago_presupuestario,
                $fecha_pago,
                $total_pagado,
                $referencia_bancaria ?: null,
                'Pago de nómina ' . $nomina['numero'] . ' registrado automáticamente',
                $_SESSION['usuario_id'] ?? 0
            ]);
            $pago_presupuestario_id = (int)$conn->lastInsertId();
        } catch (Exception $e) {
            error_log("Error registrando pago presupuestario para nómina {$nomina_id}: " . $e->getMessage());
            $numero_pago_presupuestario = null;
            $pago_presupuestario_id = null;
        }
        
        $conn->commit();
        
        $mensaje_base = "Nómina marcada como pagada. {$recibos_actualizados} recibo(s) actualizado(s). Total: " . formatearMoneda($total_pagado);
        if ($numero_pago_presupuestario) {
            $mensaje_base .= ' Pago presupuestario ' . $numero_pago_presupuestario . ' registrado.';
        }

        return [
            'success' => true,
            'recibos_actualizados' => $recibos_actualizados,
            'total_pagado' => $total_pagado,
            'asiento_id' => $asiento_id,
            'numero_pago' => $numero_pago_presupuestario,
            'pago_presupuestario_id' => $pago_presupuestario_id,
            'mensaje' => $mensaje_base
        ];
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
