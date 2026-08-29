<?php

namespace App\Reports;

use PDO;

/**
 * Reporte de Conciliación Analítica Dual: Kardex (Submayor) vs Mayor General (1.1.3.XX)
 * Soporta Modo Saldo Acumulado a Fecha de Corte (Balance) y Modo Flujo del Período
 */
class ConciliacionKardexMayor
{
    /**
     * Genera la conciliación analítica dual.
     * 
     * @param PDO $conn
     * @param string $fechaCorte O fecha final del rango ('Y-m-d H:i:s' o 'Y-m-d')
     * @param string|null $fechaDesde Si se pasa, evalúa el modo 'flujo' del período. Si es null, evalúa 'saldo' acumulado a fecha de corte.
     * @param string $modo 'saldo' (acumulado a fecha de corte) | 'flujo' (rotación en el período)
     * @return array
     */
    public static function generarReporteConciliacion(
        PDO $conn,
        string $fechaCorte,
        ?string $fechaDesde = null,
        string $modo = 'saldo'
    ): array {
        $modo = strtolower(trim($modo));
        if ($modo === 'flujo' && !empty($fechaDesde)) {
            // MODO FLUJO / ROTACIÓN DEL PERÍODO (BETWEEN fechaDesde AND fechaCorte)
            $condicionKardex = "m.fecha BETWEEN ? AND ?";
            $paramsKardex = [$fechaDesde, $fechaCorte];

            $condicionMayor = "a.id IS NOT NULL AND a.fecha BETWEEN ? AND ? AND a.estado = 'confirmado'";
            $paramsMayor = [$fechaDesde, $fechaCorte];
            $tituloReporte = "Conciliación de Movimientos / Flujo del Período ({$fechaDesde} a {$fechaCorte})";
        } else {
            // MODO SALDO ACUMULADO A FECHA DE CORTE (BALANCE GENERAL: <= fechaCorte)
            $condicionKardex = "m.fecha <= ?";
            $paramsKardex = [$fechaCorte];

            $condicionMayor = "a.id IS NOT NULL AND a.fecha <= ? AND a.estado = 'confirmado'";
            $paramsMayor = [$fechaCorte];
            $tituloReporte = "Conciliación de Saldo Acumulado de Existencias a Fecha de Corte ({$fechaCorte})";
        }

        // 1. Kardex Submayor Map (movimientos_inventario)
        $stmtKardex = $conn->prepare("
            SELECT 
                COALESCE(c.codigo, '1.1.3.01') AS cuenta_codigo,
                COALESCE(SUM(CASE WHEN m.tipo = 'entrada' THEN m.valor_total ELSE -m.valor_total END), 0.00) AS saldo_kardex
            FROM productos p
            LEFT JOIN movimientos_inventario m ON m.producto_id = p.id AND {$condicionKardex}
            LEFT JOIN cuentas c ON c.id = p.cuenta_id
            GROUP BY c.codigo
        ");
        $stmtKardex->execute($paramsKardex);
        $kardexMap = [];
        while ($row = $stmtKardex->fetch(PDO::FETCH_ASSOC)) {
            $kardexMap[$row['cuenta_codigo']] = (float) $row['saldo_kardex'];
        }

        // 2. Mayor General Map (asientos + detalles_asiento para cuentas 1.1.3.XX)
        $stmtMayor = $conn->prepare("
            SELECT 
                c.codigo AS cuenta_codigo,
                c.nombre AS cuenta_nombre,
                COALESCE(SUM(CASE WHEN {$condicionMayor} THEN (da.debe - da.haber) ELSE 0 END), 0.00) AS saldo_mayor
            FROM cuentas c
            LEFT JOIN detalles_asiento da ON da.cuenta_id = c.id
            LEFT JOIN asientos a ON a.id = da.asiento_id
            WHERE (c.codigo LIKE '1.1.3%' OR c.codigo_completo LIKE '1.1.3%')
            GROUP BY c.id, c.codigo, c.nombre
        ");
        $stmtMayor->execute($paramsMayor);
        $mayorMap = [];
        $nombresMap = [];
        while ($row = $stmtMayor->fetch(PDO::FETCH_ASSOC)) {
            $cCod = $row['cuenta_codigo'];
            $mayorMap[$cCod] = (float) $row['saldo_mayor'];
            $nombresMap[$cCod] = $row['cuenta_nombre'];
        }

        // 3. FULL OUTER KEY MERGE (Unión Completa de Claves Únicas)
        $todasLasCuentas = array_unique(array_merge(
            array_keys($kardexMap),
            array_keys($mayorMap)
        ));
        sort($todasLasCuentas);

        $resumenCuentas = [];
        $totalKardexGlobal = 0.0;
        $totalMayorGlobal = 0.0;

        foreach ($todasLasCuentas as $cCod) {
            $sKardex = round($kardexMap[$cCod] ?? 0.0, 2);
            $sMayor = round($mayorMap[$cCod] ?? 0.0, 2);
            $diff = round($sKardex - $sMayor, 2);

            $totalKardexGlobal += $sKardex;
            $totalMayorGlobal += $sMayor;

            $resumenCuentas[] = [
                'cuenta_codigo' => $cCod,
                'cuenta_nombre' => $nombresMap[$cCod] ?? "Existencias de Almacén ({$cCod})",
                'total_kardex' => $sKardex,
                'total_mayor' => $sMayor,
                'diferencia' => $diff,
                'conciliado' => abs($diff) <= 0.05,
            ];
        }

        $diffGlobal = round($totalKardexGlobal - $totalMayorGlobal, 2);

        return [
            'titulo_reporte' => $tituloReporte,
            'modo' => $modo,
            'fecha_generacion' => date('Y-m-d H:i:s'),
            'fecha_corte' => $fechaCorte,
            'fecha_desde' => $fechaDesde,
            'total_kardex_global' => round($totalKardexGlobal, 2),
            'total_mayor_global' => round($totalMayorGlobal, 2),
            'diferencia_global' => $diffGlobal,
            'conciliacion_global' => abs($diffGlobal) <= 0.05,
            'detalle_cuentas' => $resumenCuentas,
        ];
    }
}
