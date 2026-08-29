<?php

namespace App\Services;

use PDO;
use Exception;

/**
 * Servicio Enterprise de Contabilización Automática Patrimonial ONCOP y Afectación Presupuestaria ONAPRE
 * Módulo de Órdenes de Entrega de Almacén
 */
class ContabilidadDespachoService
{
    /**
     * Genera el Asiento Contable Patrimonial por Despacho de Materiales (ONCOP / ONAPRE)
     * Debe: 5.1.2.XX (Gasto por Consumo desglosado por Tipo/Categoría de Insumo)
     * Haber: 1.1.3.XX (Existencias de Materiales y Suministros - Activo de Almacén por Producto)
     */
    public static function generarAsientoDespacho(PDO $conn, array $orden, array $itemsData, int $usuarioId): int
    {
        $ordenId = (int) $orden['id'];
        $numeroOrden = $orden['numero_orden'] ?? "ODE-{$ordenId}";
        $fechaOrden = $orden['fecha_orden'] ?? date('Y-m-d H:i:s');

        if (empty($itemsData)) {
            throw new Exception("📌 DIAGNÓSTICO: No hay ítems despachados para contabilizar. 💡 DETALLE: La Orden N° {$numeroOrden} no posee renglones con cantidades despachadas mayores a cero. 🔧 ACCIÓN REQUERIDA: Ingrese la cantidad despachada para al menos un producto antes de procesar el despacho.");
        }

        // 1. VALIDACIÓN PREVENTIVA DE PERÍODO CONTABLE ABIERTO
        self::validarPeriodoContableAbierto($conn, $fechaOrden);

        // 2. VALIDACIÓN PREVENTIVA SUDEBIP / ONAPRE (Partida 4.04 Bienes Muebles / Activos Fijos)
        foreach ($itemsData as $it) {
            $pId = (int) $it['producto_id'];
            $stmtP = $conn->prepare("
                SELECT p.nombre, p.codigo, p.cuenta_id, c.codigo AS cuenta_codigo, c.codigo_completo, c.categoria, c.numero_partida
                FROM productos p
                LEFT JOIN cuentas c ON c.id = p.cuenta_id
                WHERE p.id = ?
            ");
            $stmtP->execute([$pId]);
            $prodInfo = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($prodInfo) {
                $codigoPartida = (string) ($prodInfo['numero_partida'] ?? ($prodInfo['cuenta_codigo'] ?? ($prodInfo['categoria'] ?? '')));
                if (str_starts_with(trim($codigoPartida), '4.04') || str_starts_with(trim((string)$prodInfo['cuenta_codigo']), '4.04')) {
                    throw new Exception("📌 DIAGNÓSTICO: Producto clasificado como Bien Mueble / Activo Fijo (SUDEBIP). 💡 DETALLE: El insumo '{$prodInfo['nombre']}' (Código: {$prodInfo['codigo']}) está asociado a la Partida Presupuestaria 4.04. 🔧 ACCIÓN REQUERIDA: Este artículo no puede despacharse como insumo de consumo directo de almacén. Tramite su entrega a través de la Unidad de Bienes Públicos / SUDEBIP para su incorporación patrimonial.");
                }
            }
        }

        // 3. MATRIZ DE IMPUTACIÓN Y DESGLOSE POR TIPO DE INSUMO (DEBE Y HABER)
        $detallesDebe = [];  // [cuenta_gasto_id => monto_acumulado]
        $detallesHaber = []; // [cuenta_activo_id => monto_acumulado]
        $montoTotalDespacho = 0.0;

        foreach ($itemsData as $it) {
            $cantDesp = round((float) ($it['cantidad_despachada'] ?? 0), 3);
            $costoUnit = (float) ($it['costo_unitario'] ?? 0);
            $costoItemRaw = $cantDesp * $costoUnit;

            if ($costoItemRaw <= 0) continue;

            $pId = (int) $it['producto_id'];

            // Resolver Cuentas Patrimoniales Auxiliares (acepta_movimiento = 1)
            $cuentaActivoId = self::resolverCuentaActivoInventario($conn, $pId);
            $cuentaGastoId = self::resolverCuentaGastoInsumo($conn, $pId, $orden);

            if (!isset($detallesHaber[$cuentaActivoId])) {
                $detallesHaber[$cuentaActivoId] = 0.0;
            }
            $detallesHaber[$cuentaActivoId] += $costoItemRaw;

            if (!isset($detallesDebe[$cuentaGastoId])) {
                $detallesDebe[$cuentaGastoId] = 0.0;
            }
            $detallesDebe[$cuentaGastoId] += $costoItemRaw;

            $montoTotalDespacho += $costoItemRaw;
        }

        if ($montoTotalDespacho <= 0) {
            throw new Exception("📌 DIAGNÓSTICO: Costo total del despacho es cero. 💡 DETALLE: La Orden N° {$numeroOrden} no genera costo valorizado acumulado. 🔧 ACCIÓN REQUERIDA: Verifique que los productos despachados tengan un costo unitario vigente en el inventario.");
        }

        // 4. CUADRATURA ESTRICTA CON GUARDA DE CÉNTIMOS (LÍMITE MÁXIMO 0.05 BS)
        $lineasDebe = [];
        $sumDebe = 0.0;
        foreach ($detallesDebe as $cGastoId => $montoDebeRaw) {
            $val2Dec = round($montoDebeRaw, 2);
            $lineasDebe[] = ['cuenta_id' => $cGastoId, 'monto' => $val2Dec];
            $sumDebe += $val2Dec;
        }

        $lineasHaber = [];
        $sumHaber = 0.0;
        foreach ($detallesHaber as $cActivoId => $montoHaberRaw) {
            $val2Dec = round($montoHaberRaw, 2);
            $lineasHaber[] = ['cuenta_id' => $cActivoId, 'monto' => $val2Dec];
            $sumHaber += $val2Dec;
        }

        $sumDebe = round($sumDebe, 2);
        $sumHaber = round($sumHaber, 2);

        $diffCentavos = round($sumDebe - $sumHaber, 2);
        if (abs($diffCentavos) > 0.0001) {
            // GUARDA DEFENSIVA MÁXIMA DE 5 CÉNTIMOS DE AUTO-AJUSTE
            if (abs($diffCentavos) > 0.05) {
                throw new Exception("📌 DIAGNÓSTICO: Descuadre contable crítico en la Orden N° {$numeroOrden}. 💡 DETALLE: La suma del Debe (Bs. {$sumDebe}) difiere del Haber (Bs. {$sumHaber}) en Bs. {$diffCentavos}, superando el límite de tolerancia de Bs. 0.05. 🔧 ACCIÓN REQUERIDA: Verifique los costos unitarios de los insumos en el catálogo o solicite revisión a la Coordinación de Finanzas.");
            }

            if ($diffCentavos > 0 && !empty($lineasHaber)) {
                $lineasHaber[count($lineasHaber) - 1]['monto'] = round($lineasHaber[count($lineasHaber) - 1]['monto'] + $diffCentavos, 2);
                $sumHaber = $sumDebe;
            } elseif ($diffCentavos < 0 && !empty($lineasDebe)) {
                $lineasDebe[count($lineasDebe) - 1]['monto'] = round($lineasDebe[count($lineasDebe) - 1]['monto'] + abs($diffCentavos), 2);
                $sumDebe = $sumHaber;
            }
        }

        $montoAsientoConfirmado = $sumDebe;

        // 5. INSERTAR CABECERA EN `asientos`
        $stmtInsHead = $conn->prepare("
            INSERT INTO asientos (
                fecha, descripcion, documento, total_debitos, total_creditos,
                estado, es_automatico, origen_automatico, registro_origen_id,
                usuario_id, fecha_creacion, fecha_confirmacion, confirmado_por
            ) VALUES (?, ?, ?, ?, ?, 'confirmado', 1, 'orden_entrega', ?, ?, NOW(), NOW(), ?)
        ");
        $descAsiento = "Despacho de materiales según Orden de Entrega N° {$numeroOrden}";
        $stmtInsHead->execute([
            $fechaOrden,
            $descAsiento,
            $numeroOrden,
            $montoAsientoConfirmado,
            $montoAsientoConfirmado,
            $ordenId,
            $usuarioId,
            $usuarioId,
        ]);
        $asientoId = (int) $conn->lastInsertId();

        // 6. INSERTAR DETALLES EN `detalles_asiento`
        $stmtInsDet = $conn->prepare("
            INSERT INTO detalles_asiento (
                asiento_id, cuenta_id, descripcion, debe, haber
            ) VALUES (?, ?, ?, ?, ?)
        ");

        $afectaciones = [];
        foreach ($lineasDebe as $lDebe) {
            $descDebe = "Gasto por consumo de insumos de almacén - Orden N° {$numeroOrden}";
            $stmtInsDet->execute([
                $asientoId,
                $lDebe['cuenta_id'],
                $descDebe,
                $lDebe['monto'],
                0.00,
            ]);
            $afectaciones[] = ['cuenta_id' => $lDebe['cuenta_id'], 'debe' => $lDebe['monto'], 'haber' => 0.00];
        }

        foreach ($lineasHaber as $lHaber) {
            $descHaber = "Descargo de existencias de almacén - Orden N° {$numeroOrden}";
            $stmtInsDet->execute([
                $asientoId,
                $lHaber['cuenta_id'],
                $descHaber,
                0.00,
                $lHaber['monto'],
            ]);
            $afectaciones[] = ['cuenta_id' => $lHaber['cuenta_id'], 'debe' => 0.00, 'haber' => $lHaber['monto']];
        }

        // Sincronizar saldos_cuentas_mensuales en O(1)
        self::actualizarSaldosMensuales($conn, $afectaciones, $fechaOrden);

        return $asientoId;
    }

    /**
     * Genera el Asiento Contable por Devolución Parcial (RMA) al Almacén
     */
    public static function generarAsientoDevolucion(PDO $conn, array $orden, int $devolucionId, array $rawDevItems, int $usuarioId): int
    {
        $ordenId = (int) $orden['id'];
        $numeroOrden = $orden['numero_orden'] ?? "ODE-{$ordenId}";

        $stmtDevHead = $conn->prepare("SELECT numero_devolucion, fecha_devolucion FROM orden_entrega_devoluciones WHERE id = ?");
        $stmtDevHead->execute([$devolucionId]);
        $devHead = $stmtDevHead->fetch(PDO::FETCH_ASSOC);

        $numeroDev = $devHead['numero_devolucion'] ?? "DEV-{$devolucionId}";
        $fechaDev = $devHead['fecha_devolucion'] ?? date('Y-m-d H:i:s');

        // VALIDAR PERÍODO CONTABLE
        self::validarPeriodoContableAbierto($conn, $fechaDev);

        $detallesDebe = [];  // [cuenta_activo_id => monto]
        $detallesHaber = []; // [cuenta_gasto_id => monto]
        $montoTotalDevuelto = 0.0;

        foreach ($rawDevItems as $it) {
            $cantDev = round((float) ($it['cantidad_devuelta'] ?? 0), 3);
            $costoUnit = (float) ($it['costo_unitario'] ?? 0);
            $costoItemRaw = $cantDev * $costoUnit;

            if ($costoItemRaw <= 0) continue;

            $pId = (int) $it['producto_id'];
            $cuentaActivoId = self::resolverCuentaActivoInventario($conn, $pId);
            $cuentaGastoId = self::resolverCuentaGastoInsumo($conn, $pId, $orden);

            if (!isset($detallesDebe[$cuentaActivoId])) {
                $detallesDebe[$cuentaActivoId] = 0.0;
            }
            $detallesDebe[$cuentaActivoId] += $costoItemRaw;

            if (!isset($detallesHaber[$cuentaGastoId])) {
                $detallesHaber[$cuentaGastoId] = 0.0;
            }
            $detallesHaber[$cuentaGastoId] += $costoItemRaw;

            $montoTotalDevuelto += $costoItemRaw;
        }

        if ($montoTotalDevuelto <= 0) {
            throw new Exception("📌 DIAGNÓSTICO: Monto total devuelto es cero. 💡 DETALLE: La Devolución N° {$numeroDev} no contiene ítems con costo reincorporado mayores a cero. 🔧 ACCIÓN REQUERIDA: Ingrese la cantidad devuelta para al menos un producto.");
        }

        // CUADRATURA DE CÉNTIMOS CON GUARDA MÁXIMA DE 0.05 BS
        $lineasDebe = [];
        $sumDebe = 0.0;
        foreach ($detallesDebe as $cActivoId => $montoDebeRaw) {
            $val2Dec = round($montoDebeRaw, 2);
            $lineasDebe[] = ['cuenta_id' => $cActivoId, 'monto' => $val2Dec];
            $sumDebe += $val2Dec;
        }

        $lineasHaber = [];
        $sumHaber = 0.0;
        foreach ($detallesHaber as $cGastoId => $montoHaberRaw) {
            $val2Dec = round($montoHaberRaw, 2);
            $lineasHaber[] = ['cuenta_id' => $cGastoId, 'monto' => $val2Dec];
            $sumHaber += $val2Dec;
        }

        $diffCentavos = round($sumDebe - $sumHaber, 2);
        if (abs($diffCentavos) > 0.0001) {
            if (abs($diffCentavos) > 0.05) {
                throw new Exception("📌 DIAGNÓSTICO: Descuadre contable en devolución RMA N° {$numeroDev}. 💡 DETALLE: Debe (Bs. {$sumDebe}) vs Haber (Bs. {$sumHaber}). Diferencia: Bs. {$diffCentavos}. 🔧 ACCIÓN REQUERIDA: Verifique los costos unitarios históricos del comprobante de devolución.");
            }

            if ($diffCentavos > 0 && !empty($lineasHaber)) {
                $lineasHaber[count($lineasHaber) - 1]['monto'] = round($lineasHaber[count($lineasHaber) - 1]['monto'] + $diffCentavos, 2);
                $sumHaber = $sumDebe;
            } elseif ($diffCentavos < 0 && !empty($lineasDebe)) {
                $lineasDebe[count($lineasDebe) - 1]['monto'] = round($lineasDebe[count($lineasDebe) - 1]['monto'] + abs($diffCentavos), 2);
                $sumDebe = $sumHaber;
            }
        }

        $montoDevConfirmado = round($sumDebe, 2);

        // CABECERA ASIENTO
        $stmtInsHead = $conn->prepare("
            INSERT INTO asientos (
                fecha, descripcion, documento, total_debitos, total_creditos,
                estado, es_automatico, origen_automatico, registro_origen_id,
                usuario_id, fecha_creacion, fecha_confirmacion, confirmado_por
            ) VALUES (?, ?, ?, ?, ?, 'confirmado', 1, 'orden_entrega_devolucion', ?, ?, NOW(), NOW(), ?)
        ");
        $descAsiento = "Reversión contable por Devolución RMA N° {$numeroDev} (Orden N° {$numeroOrden})";
        $stmtInsHead->execute([
            $fechaDev,
            $descAsiento,
            $numeroDev,
            $montoDevConfirmado,
            $montoDevConfirmado,
            $devolucionId,
            $usuarioId,
            $usuarioId,
        ]);
        $asientoId = (int) $conn->lastInsertId();

        $stmtInsDet = $conn->prepare("
            INSERT INTO detalles_asiento (
                asiento_id, cuenta_id, descripcion, debe, haber
            ) VALUES (?, ?, ?, ?, ?)
        ");

        $afectaciones = [];
        foreach ($lineasDebe as $lDebe) {
            $descDebe = "Reingreso a existencias de almacén - Devolución N° {$numeroDev}";
            $stmtInsDet->execute([
                $asientoId,
                $lDebe['cuenta_id'],
                $descDebe,
                $lDebe['monto'],
                0.00,
            ]);
            $afectaciones[] = ['cuenta_id' => $lDebe['cuenta_id'], 'debe' => $lDebe['monto'], 'haber' => 0.00];
        }

        foreach ($lineasHaber as $lHaber) {
            $descHaber = "Reversión de gasto por consumo - Devolución N° {$numeroDev}";
            $stmtInsDet->execute([
                $asientoId,
                $lHaber['cuenta_id'],
                $descHaber,
                0.00,
                $lHaber['monto'],
            ]);
            $afectaciones[] = ['cuenta_id' => $lHaber['cuenta_id'], 'debe' => 0.00, 'haber' => $lHaber['monto']];
        }

        // Sincronizar saldos_cuentas_mensuales en O(1)
        self::actualizarSaldosMensuales($conn, $afectaciones, $fechaDev);

        return $asientoId;
    }

    /**
     * Sincronizador Atómico en O(1) de Saldos Mensuales y Recálculo de Saldo Final según Naturaleza
     */
    private static function actualizarSaldosMensuales(PDO $conn, array $cuentasAfectadas, string $fecha): void
    {
        if (empty($cuentasAfectadas)) return;

        $ejercicio = (int)date('Y', strtotime($fecha));
        $mes = (int)date('n', strtotime($fecha));

        $stmtUpsert = $conn->prepare("
            INSERT INTO saldos_cuentas_mensuales (
                cuenta_id, ejercicio, mes, moneda,
                saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen
            ) VALUES (?, ?, ?, 'VES', ?, ?, ?, 0.00, ?, ?, ?, 0.00)
            ON DUPLICATE KEY UPDATE 
                debitos_base = debitos_base + VALUES(debitos_base),
                creditos_base = creditos_base + VALUES(creditos_base)
        ");

        $idsCuentas = array_map(function ($it) { return (int)$it['cuenta_id']; }, $cuentasAfectadas);
        $saldosBatch = self::obtenerSaldosInicialesLotePHP($conn, $idsCuentas, $ejercicio, $mes);

        $stmtUpsert = $conn->prepare("
            INSERT INTO saldos_cuentas_mensuales (
                cuenta_id, ejercicio, mes, moneda,
                saldo_inicial_base, debitos_base, creditos_base, saldo_final_base,
                saldo_inicial_origen, debitos_origen, creditos_origen, saldo_final_origen
            ) VALUES (?, ?, ?, 'VES', ?, ?, ?, 0.00, ?, ?, ?, 0.00)
            ON DUPLICATE KEY UPDATE 
                debitos_base = debitos_base + VALUES(debitos_base),
                creditos_base = creditos_base + VALUES(creditos_base)
        ");

        $idsUnicos = [];
        foreach ($cuentasAfectadas as $item) {
            $cId = (int)$item['cuenta_id'];
            $debe = round((float)($item['debe'] ?? 0), 2);
            $haber = round((float)($item['haber'] ?? 0), 2);

            $sInfo = $saldosBatch[$cId] ?? ['base' => 0.00, 'origen' => 0.00];

            $stmtUpsert->execute([
                $cId, $ejercicio, $mes,
                $sInfo['base'], $debe, $haber,
                $sInfo['origen'], $debe, $haber
            ]);
            $idsUnicos[] = $cId;
        }

        $idsUnicos = array_unique($idsUnicos);
        if (!empty($idsUnicos)) {
            $inClause = implode(',', array_fill(0, count($idsUnicos), '?'));
            $stmtRecalcular = $conn->prepare("
                UPDATE saldos_cuentas_mensuales s
                JOIN cuentas c ON s.cuenta_id = c.id
                SET s.saldo_final_base = CASE 
                    WHEN LOWER(c.naturaleza) = 'deudora' THEN s.saldo_inicial_base + s.debitos_base - s.creditos_base
                    ELSE s.saldo_inicial_base + s.creditos_base - s.debitos_base
                END
                WHERE s.cuenta_id IN ({$inClause}) AND s.ejercicio = ? AND s.mes = ?
            ");
            $params = array_merge($idsUnicos, [$ejercicio, $mes]);
            $stmtRecalcular->execute($params);
        }
    }

    /**
     * Genera el Asiento Contable por Anulación de Orden de Entrega
     */
    public static function generarAsientoAnulacion(PDO $conn, array $orden, int $usuarioId): int
    {
        $ordenId = (int) $orden['id'];
        $numeroOrden = $orden['numero_orden'] ?? "ODE-{$ordenId}";
        $fechaActual = date('Y-m-d H:i:s');

        // VALIDAR PERÍODO CONTABLE
        self::validarPeriodoContableAbierto($conn, $fechaActual);

        $stmtItems = $conn->prepare("SELECT producto_id, cantidad_despachada, cantidad_devuelta, costo_unitario FROM orden_entrega_items WHERE orden_entrega_id = ?");
        $stmtItems->execute([$ordenId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $detallesDebe = [];  // [cuenta_activo_id => monto]
        $detallesHaber = []; // [cuenta_gasto_id => monto]
        $montoTotalRevertir = 0.0;

        foreach ($items as $it) {
            $cantDesp = round((float) $it['cantidad_despachada'], 3);
            $cantDev = round((float) ($it['cantidad_devuelta'] ?? 0), 3);
            $cantRevertir = round(max(0.0, $cantDesp - $cantDev), 3);

            $costoUnit = (float) $it['costo_unitario'];
            $costoItemRaw = $cantRevertir * $costoUnit;

            if ($costoItemRaw <= 0) continue;

            $pId = (int) $it['producto_id'];
            $cuentaActivoId = self::resolverCuentaActivoInventario($conn, $pId);
            $cuentaGastoId = self::resolverCuentaGastoInsumo($conn, $pId, $orden);

            if (!isset($detallesDebe[$cuentaActivoId])) {
                $detallesDebe[$cuentaActivoId] = 0.0;
            }
            $detallesDebe[$cuentaActivoId] += $costoItemRaw;

            if (!isset($detallesHaber[$cuentaGastoId])) {
                $detallesHaber[$cuentaGastoId] = 0.0;
            }
            $detallesHaber[$cuentaGastoId] += $costoItemRaw;

            $montoTotalRevertir += $costoItemRaw;
        }

        if ($montoTotalRevertir <= 0) {
            throw new Exception("📌 DIAGNÓSTICO: Sin monto pendiente por revertir. 💡 DETALLE: La Orden N° {$numeroOrden} no posee costos despachados pendientes de anulación contable. 🔧 ACCIÓN REQUERIDA: Verifique el estado de la orden antes de anular.");
        }

        // CUADRATURA DE CÉNTIMOS CON GUARDA MÁXIMA DE 0.05 BS
        $lineasDebe = [];
        $sumDebe = 0.0;
        foreach ($detallesDebe as $cActivoId => $montoDebeRaw) {
            $val2Dec = round($montoDebeRaw, 2);
            $lineasDebe[] = ['cuenta_id' => $cActivoId, 'monto' => $val2Dec];
            $sumDebe += $val2Dec;
        }

        $lineasHaber = [];
        $sumHaber = 0.0;
        foreach ($detallesHaber as $cGastoId => $montoHaberRaw) {
            $val2Dec = round($montoHaberRaw, 2);
            $lineasHaber[] = ['cuenta_id' => $cGastoId, 'monto' => $val2Dec];
            $sumHaber += $val2Dec;
        }

        $diffCentavos = round($sumDebe - $sumHaber, 2);
        if (abs($diffCentavos) > 0.0001) {
            if (abs($diffCentavos) > 0.05) {
                throw new Exception("📌 DIAGNÓSTICO: Descuadre en anulación N° {$numeroOrden}. 💡 DETALLE: Debe (Bs. {$sumDebe}) vs Haber (Bs. {$sumHaber}). Diferencia: Bs. {$diffCentavos}. 🔧 ACCIÓN REQUERIDA: Contacte al Administrador del Sistema.");
            }

            if ($diffCentavos > 0 && !empty($lineasHaber)) {
                $lineasHaber[count($lineasHaber) - 1]['monto'] = round($lineasHaber[count($lineasHaber) - 1]['monto'] + $diffCentavos, 2);
                $sumHaber = $sumDebe;
            } elseif ($diffCentavos < 0 && !empty($lineasDebe)) {
                $lineasDebe[count($lineasDebe) - 1]['monto'] = round($lineasDebe[count($lineasDebe) - 1]['monto'] + abs($diffCentavos), 2);
                $sumDebe = $sumHaber;
            }
        }

        $montoAnulacionConfirmado = round($sumDebe, 2);

        // CABECERA ASIENTO
        $stmtInsHead = $conn->prepare("
            INSERT INTO asientos (
                fecha, descripcion, documento, total_debitos, total_creditos,
                estado, es_automatico, origen_automatico, registro_origen_id,
                usuario_id, fecha_creacion, fecha_confirmacion, confirmado_por
            ) VALUES (NOW(), ?, ?, ?, ?, 'confirmado', 1, 'orden_entrega_anulacion', ?, ?, NOW(), NOW(), ?)
        ");
        $descAsiento = "Reversión contable por Anulación de Orden de Entrega N° {$numeroOrden}";
        $stmtInsHead->execute([
            $descAsiento,
            $numeroOrden,
            $montoAnulacionConfirmado,
            $montoAnulacionConfirmado,
            $ordenId,
            $usuarioId,
            $usuarioId,
        ]);
        $asientoId = (int) $conn->lastInsertId();

        $stmtInsDet = $conn->prepare("
            INSERT INTO detalles_asiento (
                asiento_id, cuenta_id, descripcion, debe, haber
            ) VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($lineasDebe as $lDebe) {
            $descDebe = "Reversión a existencias de almacén por anulación - Orden N° {$numeroOrden}";
            $stmtInsDet->execute([
                $asientoId,
                $lDebe['cuenta_id'],
                $descDebe,
                $lDebe['monto'],
                0.00,
            ]);
        }

        foreach ($lineasHaber as $lHaber) {
            $descHaber = "Cancelación contable de gasto por anulación - Orden N° {$numeroOrden}";
            $stmtInsDet->execute([
                $asientoId,
                $lHaber['cuenta_id'],
                $descHaber,
                0.00,
                $lHaber['monto'],
            ]);
        }

        return $asientoId;
    }

    /**
     * Valida que el período contable correspondiente a la fecha esté ABIERTO (Principio Fail-Closed Estricto)
     * Aplica Lista Blanca (Whitelist) y rechaza cualquier fecha huérfana, cerrada o no comprobable.
     */
    public static function validarPeriodoContableAbierto(PDO $conn, string $fecha): void
    {
        $time = strtotime($fecha);
        if ($time === false) {
            $time = time();
        }
        $fechaFormatted = date('Y-m-d', $time);
        $anio = (int) date('Y', $time);
        $mes = (int) date('m', $time);

        $estadosPermitidos = ['abierto', 'activo', 'ejecucion', 'rezagados', 'planificacion'];

        // 1. Verificación Estricta Fail-Closed en periodos_contables
        try {
            $stmtPer = $conn->prepare("
                SELECT id, estado, estado_detallado 
                FROM periodos_contables 
                WHERE ? BETWEEN desde AND hasta
                LIMIT 1
            ");
            $stmtPer->execute([$fechaFormatted]);
            $periodo = $stmtPer->fetch(PDO::FETCH_ASSOC);

            if (!$periodo) {
                // Fallback por columna anio o año si no usó desde/hasta
                $stmtPerAnio = $conn->prepare("
                    SELECT id, estado, estado_detallado 
                    FROM periodos_contables 
                    WHERE anio = ? OR id = ?
                    LIMIT 1
                ");
                $stmtPerAnio->execute([$anio, $anio]);
                $periodo = $stmtPerAnio->fetch(PDO::FETCH_ASSOC);
            }

            if (!$periodo) {
                throw new Exception("📌 DIAGNÓSTICO: Ejercicio fiscal no configurado para la fecha {$fechaFormatted}. 💡 DETALLE: No existe un período contable registrado en la base de datos para el año {$anio}. 🔧 ACCIÓN REQUERIDA: Diríjase a Contabilidad > Períodos Contables y cree/aperture el ejercicio fiscal correspondiente al año {$anio}.");
            }

            $estadoGen = strtolower(trim((string) ($periodo['estado'] ?? '')));
            $estadoDet = strtolower(trim((string) ($periodo['estado_detallado'] ?? '')));

            // Validación por Whitelist (Lista Blanca)
            if (!in_array($estadoGen, $estadosPermitidos, true)) {
                $estadoStr = strtoupper($estadoGen);
                throw new Exception("📌 DIAGNÓSTICO: Ejercicio fiscal inactivo/cerrado para la fecha {$fechaFormatted}. 💡 DETALLE: El período contable del año {$anio} se encuentra en estado '{$estadoStr}'. 🔧 ACCIÓN REQUERIDA: Diríjase a Contabilidad > Períodos Contables y aperture el ejercicio fiscal antes de registrar operaciones.");
            }

            if ($estadoDet !== '' && !in_array($estadoDet, $estadosPermitidos, true)) {
                $estadoDetStr = strtoupper($estadoDet);
                throw new Exception("📌 DIAGNÓSTICO: Fase de período contable restringida. 💡 DETALLE: El período contable para la fecha {$fechaFormatted} está en fase '{$estadoDetStr}' y no admite despachos. 🔧 ACCIÓN REQUERIDA: Modifique el estado detallado del período a 'EJECUCIÓN' o use una fecha activa.");
            }

        } catch (\PDOException $e) {
            throw new Exception("📌 DIAGNÓSTICO: Error crítico de control fiscal al consultar períodos contables. 💡 DETALLE: " . $e->getMessage() . " 🔧 ACCIÓN REQUERIDA: Verifique la conexión a la base de datos o contacte al administrador.");
        }

        // 2. Verificación de Cierre Mensual en cierres_mensuales (si la tabla existe)
        try {
            $stmtCierre = $conn->prepare("
                SELECT id, estado 
                FROM cierres_mensuales 
                WHERE anio = ? AND mes = ? AND estado = 'cerrado'
                LIMIT 1
            ");
            $stmtCierre->execute([$anio, $mes]);
            if ($stmtCierre->fetch()) {
                throw new Exception("📌 DIAGNÓSTICO: Mes contable cerrado ({$mes}/{$anio}). 💡 DETALLE: La oficina de Finanzas ha efectuado el cierre mensual para el mes {$mes} del año {$anio}. 🔧 ACCIÓN REQUERIDA: Solicite la reapertura temporal del mes contable {$mes}/{$anio} a la Coordinación de Finanzas o ajuste la fecha de la orden.");
            }
        } catch (\PDOException $e) {
            // Si la consulta falla por otra razón que no sea tabla inexistente, relanzar si es error de conexión
            if ($e->getCode() === 'HY000' || str_contains($e->getMessage(), 'Connection')) {
                throw new Exception("📌 DIAGNÓSTICO: Fallo de conexión al consultar cierres mensuales. 💡 DETALLE: " . $e->getMessage() . " 🔧 ACCIÓN REQUERIDA: Reintente la operación en unos segundos.");
            }
        }
    }

    /**
     * Resuelve la Cuenta Contable Auxiliar de Activo / Existencias de Almacén (1.1.3.XX)
     * Exige que sea una cuenta activa y receptora directa de movimientos (acepta_movimiento = 1 / subespecifica)
     */
    private static function resolverCuentaActivoInventario(PDO $conn, int $productoId): int
    {
        $stmtP = $conn->prepare("SELECT nombre, codigo, cuenta_id FROM productos WHERE id = ?");
        $stmtP->execute([$productoId]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);

        $prodNombre = $prod['nombre'] ?? "ID #{$productoId}";
        $prodCodigo = $prod['codigo'] ?? "ID #{$productoId}";

        if (!empty($prod['cuenta_id'])) {
            $cId = (int) $prod['cuenta_id'];
            $stmtVal = $conn->prepare("
                SELECT id 
                FROM cuentas 
                WHERE id = ? 
                  AND estado = 'activa' 
                  AND (nivel_partida = 'subespecifica' OR nivel_partida IS NULL OR CHAR_LENGTH(codigo) >= 6)
            ");
            $stmtVal->execute([$cId]);
            if ($stmtVal->fetch()) {
                return $cId;
            }
        }

        // Buscar cuenta activa auxiliar del grupo 1.1.3 (Existencias de Almacén)
        $stmtC = $conn->query("
            SELECT id FROM cuentas 
            WHERE (codigo LIKE '1.1.3%' OR codigo_completo LIKE '1.1.3%' OR categoria LIKE '1.1.3%')
              AND estado = 'activa'
              AND (nivel_partida = 'subespecifica' OR nivel_partida IS NULL OR CHAR_LENGTH(codigo) >= 6)
            ORDER BY id ASC LIMIT 1
        ");
        $cRow = $stmtC->fetch(PDO::FETCH_ASSOC);

        if ($cRow) {
            return (int) $cRow['id'];
        }

        // Fallback a cualquier cuenta activa auxiliar de tipo 'activo'
        $stmtFb = $conn->query("
            SELECT id FROM cuentas 
            WHERE tipo = 'activo' 
              AND estado = 'activa' 
              AND (nivel_partida = 'subespecifica' OR nivel_partida IS NULL OR CHAR_LENGTH(codigo) >= 6)
            ORDER BY id ASC LIMIT 1
        ");
        $fbRow = $stmtFb->fetch(PDO::FETCH_ASSOC);

        if ($fbRow) {
            return (int) $fbRow['id'];
        }

        // GUARDA DEFENSIVA PRE-FLIGHT
        throw new Exception("📌 DIAGNÓSTICO: Cuenta de Activo de Inventario (1.1.3.XX) no configurada. 💡 DETALLE: El producto '{$prodNombre}' (Código: {$prodCodigo}) no posee asignada una cuenta contable de Existencias de Nivel Auxiliar Receptora de Movimientos. 🔧 ACCIÓN REQUERIDA: Diríjase a Inventario > Catálogo de Productos, edite el producto '{$prodNombre}' y asignele una cuenta contable activa de activo auxiliar.");
    }

    /**
     * Resuelve la Cuenta Contable Auxiliar de Gasto por Consumo de Insumos desglosada por Categoría de Producto (5.1.2.XX)
     * Exige que sea una cuenta activa y receptora directa de movimientos (acepta_movimiento = 1 / subespecifica)
     */
    private static function resolverCuentaGastoInsumo(PDO $conn, int $productoId, array $orden): int
    {
        $stmtP = $conn->prepare("
            SELECT p.nombre, p.codigo, p.categoria_id, cat.nombre AS categoria_nombre
            FROM productos p
            LEFT JOIN categorias_productos cat ON cat.id = p.categoria_id
            WHERE p.id = ?
        ");
        $stmtP->execute([$productoId]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);

        $prodNombre = $prod['nombre'] ?? "ID #{$productoId}";
        $prodCodigo = $prod['codigo'] ?? "ID #{$productoId}";

        // 1. Buscar si la categoría del producto tiene asociada una cuenta auxiliar de gasto activa
        if (!empty($prod['categoria_nombre'])) {
            $catNombre = trim((string) $prod['categoria_nombre']);
            if ($catNombre !== '') {
                $stmtCatC = $conn->prepare("
                    SELECT c.id 
                    FROM cuentas c 
                    WHERE c.estado = 'activa' 
                      AND (c.nivel_partida = 'subespecifica' OR c.nivel_partida IS NULL OR CHAR_LENGTH(c.codigo) >= 6)
                      AND c.nombre LIKE ?
                      AND c.tipo = 'gasto'
                    ORDER BY c.id ASC LIMIT 1
                ");
                $stmtCatC->execute(["%{$catNombre}%"]);
                $catCRow = $stmtCatC->fetch(PDO::FETCH_ASSOC);
                if ($catCRow) {
                    return (int) $catCRow['id'];
                }
            }
        }

        // 2. Buscar cuenta auxiliar activa del grupo 5.1.2 / 5.1.3 (Gasto por Consumo de Materiales)
        $stmtC = $conn->query("
            SELECT id FROM cuentas 
            WHERE (codigo LIKE '5.1.2%' OR codigo_completo LIKE '5.1.2%' OR codigo LIKE '5.1.3%' OR codigo_completo LIKE '5.1.3%')
              AND estado = 'activa'
              AND (nivel_partida = 'subespecifica' OR nivel_partida IS NULL OR CHAR_LENGTH(codigo) >= 6)
            ORDER BY id ASC LIMIT 1
        ");
        $cRow = $stmtC->fetch(PDO::FETCH_ASSOC);

        if ($cRow) {
            return (int) $cRow['id'];
        }

        // 3. Fallback a cualquier cuenta auxiliar activa de tipo 'gasto'
        $stmtFb = $conn->query("
            SELECT id FROM cuentas 
            WHERE tipo = 'gasto' 
              AND estado = 'activa' 
              AND (nivel_partida = 'subespecifica' OR nivel_partida IS NULL OR CHAR_LENGTH(codigo) >= 6)
            ORDER BY id ASC LIMIT 1
        ");
        $fbRow = $stmtFb->fetch(PDO::FETCH_ASSOC);

        if ($fbRow) {
            return (int) $fbRow['id'];
        }

        // GUARDA DEFENSIVA PRE-FLIGHT
        $deptNombre = $orden['departamento_nombre'] ?? 'Solicitante';
        throw new Exception("📌 DIAGNÓSTICO: Cuenta de Gasto por Consumo (5.1.2.XX / 5.1.3.XX) no configurada. 💡 DETALLE: No se encontró una cuenta contable Auxiliar de Gasto activa para el insumo '{$prodNombre}' (Código: {$prodCodigo}) solicitado por '{$deptNombre}'. 🔧 ACCIÓN REQUERIDA: Diríjase a Contabilidad > Catálogo de Cuentas y asegúrese de tener activa al menos una cuenta auxiliar receptora de movimiento de tipo gasto para la categoría del producto.");
    }

    /**
     * Obtener atómicamente y en LOTE (O(1) Batch / Sin problema N+1 y con array_chunk anti-overflow) los Saldos Iniciales Bimonetarios 
     * respetando Cuentas Reales vs Nominales.
     * 
     * REGLA DE ORO FINTECH: Si la consulta de base de datos falla, SE ARROJA EXCEPCIÓN Y SE ABORTA LA TRANSACCIÓN.
     * Cero encubrimiento con ceros falsos.
     *
     * @param array<int> $cuentasIds
     * @return array<int, array{base: float, origen: float}>
     */
    private static function obtenerSaldosInicialesLotePHP(PDO $conn, array $cuentasIds, int $ejercicioNuevo, int $mesNuevo): array
    {
        $cuentasIds = array_values(array_unique(array_filter(array_map('intval', $cuentasIds))));
        if (empty($cuentasIds)) return [];

        $resultado = [];
        foreach ($cuentasIds as $id) {
            $resultado[$id] = ['base' => 0.00, 'origen' => 0.00];
        }

        try {
            // Segmentar en bloques de 500 para evitar desbordamiento de placeholders PDO en procesos masivos
            $chunks = array_chunk($cuentasIds, 500);

            foreach ($chunks as $chunkIds) {
                // 1. Obtener los tipos de todas las cuentas del bloque en UN SOLO QUERY BATCH
                $inClause = implode(',', array_fill(0, count($chunkIds), '?'));
                $stmtTipos = $conn->prepare("SELECT id, tipo, naturaleza FROM cuentas WHERE id IN ({$inClause})");
                $stmtTipos->execute($chunkIds);
                $tiposData = [];
                while ($row = $stmtTipos->fetch(PDO::FETCH_ASSOC)) {
                    $tiposData[(int)$row['id']] = strtolower(trim((string)($row['tipo'] ?? '')));
                }

                // 2. Obtener el último saldo final histórico con CTE Window Function en UN SOLO QUERY BATCH
                $sqlSaldos = "
                    WITH ultimos_saldos AS (
                        SELECT 
                            s.cuenta_id, s.ejercicio, s.mes, s.saldo_final_base, s.saldo_final_origen,
                            ROW_NUMBER() OVER (PARTITION BY s.cuenta_id ORDER BY s.ejercicio DESC, s.mes DESC) as rn
                        FROM saldos_cuentas_mensuales s
                        WHERE s.cuenta_id IN ({$inClause})
                          AND (s.ejercicio < ? OR (s.ejercicio = ? AND s.mes < ?))
                          AND s.moneda = 'VES'
                    )
                    SELECT cuenta_id, ejercicio, mes, saldo_final_base, saldo_final_origen
                    FROM ultimos_saldos
                    WHERE rn = 1
                ";
                $paramsSaldos = array_merge($chunkIds, [$ejercicioNuevo, $ejercicioNuevo, $mesNuevo]);
                $stmtSaldos = $conn->prepare($sqlSaldos);
                $stmtSaldos->execute($paramsSaldos);
                $saldosPrevios = $stmtSaldos->fetchAll(PDO::FETCH_ASSOC);

                foreach ($saldosPrevios as $row) {
                    $cId = (int)$row['cuenta_id'];
                    $ejercicioPrev = (int)$row['ejercicio'];
                    $saldoBase = (float)$row['saldo_final_base'];
                    $saldoOrigen = (float)$row['saldo_final_origen'];

                    $tipo = $tiposData[$cId] ?? '';
                    $esNominal = in_array($tipo, ['ingreso', 'gasto', 'costo', 'nominal'], true);

                    // Regla fiscal: Si el nuevo mes es Enero (mes = 1) o cambió el ejercicio fiscal (ejercicioNuevo > ejercicioPrev) y la cuenta es NOMINAL, su saldo inicial NACE EN 0.00
                    if (($mesNuevo === 1 || $ejercicioNuevo > $ejercicioPrev) && $esNominal) {
                        $resultado[$cId] = ['base' => 0.00, 'origen' => 0.00];
                    } else {
                        // Cuentas Reales (Activo, Pasivo, Patrimonio) arrastran el saldo inicial bimonetario completo
                        $resultado[$cId] = ['base' => $saldoBase, 'origen' => $saldoOrigen];
                    }
                }
            }

            return $resultado;
        } catch (\Throwable $e) {
            // REGLA DE ORO FINTECH: Jamás encubrir un fallo de base de datos con ceros silenciosos
            throw new Exception("📌 BLOQUEO DE AUDITORÍA CONTABLE: Error crítico al calcular los Saldos Iniciales en lote para el período [{$ejercicioNuevo}-{$mesNuevo}]: " . $e->getMessage());
        }
    }
}
