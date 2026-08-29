<?php
declare(strict_types=1);

namespace Api\Controllers;

use Api\Core\Controller;
use PDO;
use Throwable;

class LibrosContablesController extends Controller
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function validarRolContable(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuarioId = $_SESSION['usuario_id'] ?? null;
        if (!$usuarioId) {
            $this->jsonResponse(['success' => false, 'message' => "Autenticación requerida. Inicie sesión para consultar los Libros Contables."], 401);
            exit;
        }

        try {
            $stmt = $this->db->prepare("SELECT rol FROM usuarios WHERE id = ?");
            $stmt->execute([$usuarioId]);
            $rawRol = (string)$stmt->fetchColumn();
            $rol = strtolower(trim($rawRol));

            $rolesPermitidos = ['admin', 'administrador', 'contador', 'presidencia', 'directivo', 'director', 'superusuario', 'gerente', 'analista', 'tesorero'];

            if (!empty($rol) && !in_array($rol, $rolesPermitidos, true)) {
                $this->jsonResponse(['success' => false, 'message' => "Acceso denegado. Permisos insuficientes."], 403);
                exit;
            }
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => "Error al verificar los permisos del usuario."], 500);
            exit;
        }

        return (int)$usuarioId;
    }

    public function libroDiario(): void
    {
        $this->validarRolContable();

        try {
            $fechaInicio = $_GET['desde'] ?? date('Y-01-01');
            $fechaFin = $_GET['hasta'] ?? date('Y-12-31');
            $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

            $stmt = $this->db->prepare("
                SELECT a.*, u.nombre_completo as usuario_nombre
                FROM asientos a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                WHERE a.fecha BETWEEN :desde AND :hasta AND a.estado = 'confirmado'
                ORDER BY a.fecha ASC, a.numero ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':desde', $fechaInicio);
            $stmt->bindValue(':hasta', $fechaFin);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $asientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($asientos as &$a) {
                $stmtDet = $this->db->prepare("
                    SELECT d.*, c.codigo as cuenta_codigo, c.nombre as cuenta_nombre
                    FROM detalles_asiento d
                    JOIN cuentas c ON d.cuenta_id = c.id
                    WHERE d.asiento_id = ?
                    ORDER BY d.orden ASC
                ");
                $stmtDet->execute([$a['id']]);
                $a['detalles'] = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
            }

            $this->jsonResponse([
                'success' => true,
                'data' => $asientos
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function libroMayor(): void
    {
        $this->validarRolContable();

        try {
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

            $cuentaId = isset($_GET['cuenta_id']) ? (int)$_GET['cuenta_id'] : 0;
            $ejercicio = (int)($_GET['ejercicio'] ?? date('Y'));
            $moneda = $_GET['moneda'] ?? 'VES';

            $mesDesde = (int)($_GET['mes_desde'] ?? $_GET['mes'] ?? 1);
            $mesHasta = (int)($_GET['mes_hasta'] ?? $_GET['mes'] ?? 12);

            if (isset($_GET['desde']) && $_GET['desde'] !== '') {
                $mesDesde = (int)date('n', strtotime($_GET['desde']));
            }
            if (isset($_GET['hasta']) && $_GET['hasta'] !== '') {
                $mesHasta = (int)date('n', strtotime($_GET['hasta']));
            }

            if ($mesDesde > $mesHasta) {
                $temp = $mesDesde;
                $mesDesde = $mesHasta;
                $mesHasta = $temp;
            }

            // Si no se especifica cuenta_id (o es 0), se devuelve el índice de tarjetas del Libro Mayor (cuentas imputables) en O(1) con rango de meses
            if ($cuentaId <= 0) {
                $sqlMayor = "
                    WITH saldos_ordenados AS (
                        SELECT 
                            s.cuenta_id,
                            s.mes,
                            s.saldo_inicial_base,
                            s.debitos_base,
                            s.creditos_base,
                            s.saldo_final_base,
                            ROW_NUMBER() OVER (
                                PARTITION BY s.cuenta_id 
                                ORDER BY s.mes DESC
                            ) AS rn_ultimo_rango,
                            ROW_NUMBER() OVER (
                                PARTITION BY s.cuenta_id 
                                ORDER BY s.mes ASC
                            ) AS rn_primer_rango
                        FROM saldos_cuentas_mensuales s
                        WHERE s.ejercicio = :ejercicio
                          AND s.mes BETWEEN :mes_desde AND :mes_hasta
                          AND s.moneda = :moneda
                    ),
                    saldo_inicial_previo AS (
                        SELECT 
                            s.cuenta_id,
                            s.saldo_final_base AS saldo_previo,
                            ROW_NUMBER() OVER (
                                PARTITION BY s.cuenta_id 
                                ORDER BY s.mes DESC
                            ) AS rn_previo
                        FROM saldos_cuentas_mensuales s
                        WHERE s.ejercicio = :ejercicio2
                          AND s.mes < :mes_desde2
                          AND s.moneda = :moneda2
                    ),
                    ultimo_saldo_historico AS (
                        SELECT 
                            s.cuenta_id,
                            s.saldo_final_base AS saldo_historico_ultimo,
                            ROW_NUMBER() OVER (
                                PARTITION BY s.cuenta_id 
                                ORDER BY s.mes DESC
                            ) AS rn_historico
                        FROM saldos_cuentas_mensuales s
                        WHERE s.ejercicio = :ejercicio3
                          AND s.mes <= :mes_hasta2
                          AND s.moneda = :moneda3
                    )
                    SELECT 
                        c.id,
                        c.codigo,
                        c.nombre,
                        c.tipo,
                        c.naturaleza,
                        c.imputable,
                        COALESCE(
                            p.saldo_previo,
                            MAX(CASE WHEN r.rn_primer_rango = 1 THEN r.saldo_inicial_base END),
                            0.00
                        ) AS saldo_inicial,
                        COALESCE(SUM(r.debitos_base), 0.00) AS total_debe,
                        COALESCE(SUM(r.creditos_base), 0.00) AS total_haber,
                        COALESCE(
                            MAX(CASE WHEN r.rn_ultimo_rango = 1 THEN r.saldo_final_base END),
                            p.saldo_previo,
                            h.saldo_historico_ultimo,
                            0.00
                        ) AS saldo_neto
                    FROM cuentas c
                    LEFT JOIN saldos_ordenados r ON c.id = r.cuenta_id
                    LEFT JOIN saldo_inicial_previo p ON c.id = p.cuenta_id AND p.rn_previo = 1
                    LEFT JOIN ultimo_saldo_historico h ON c.id = h.cuenta_id AND h.rn_historico = 1
                    WHERE (c.es_partida_presupuestaria = 0 OR c.es_partida_presupuestaria IS NULL)
                      AND c.imputable = 1
                    GROUP BY c.id, c.codigo, c.nombre, c.tipo, c.naturaleza, c.imputable, p.saldo_previo, h.saldo_historico_ultimo
                    HAVING total_debe > 0 OR total_haber > 0 OR saldo_neto <> 0 OR saldo_inicial <> 0
                    ORDER BY c.codigo ASC
                ";

                $stmtMayor = $this->db->prepare($sqlMayor);
                $stmtMayor->execute([
                    ':ejercicio' => $ejercicio,
                    ':ejercicio2' => $ejercicio,
                    ':ejercicio3' => $ejercicio,
                    ':mes_desde' => $mesDesde,
                    ':mes_desde2' => $mesDesde,
                    ':mes_hasta' => $mesHasta,
                    ':mes_hasta2' => $mesHasta,
                    ':moneda' => $moneda,
                    ':moneda2' => $moneda,
                    ':moneda3' => $moneda,
                ]);
                $cuentasMayor = $stmtMayor->fetchAll(PDO::FETCH_ASSOC);

                foreach ($cuentasMayor as &$cm) {
                    $neto = (float)$cm['saldo_neto'];
                    $nat = strtolower(trim((string)$cm['naturaleza']));

                    if ($nat === 'deudora') {
                        if ($neto >= 0) {
                            $cm['saldo_monto'] = $neto;
                            $cm['saldo_naturaleza'] = 'Deudor';
                            $cm['saldo_anomalo'] = false;
                        } else {
                            $cm['saldo_monto'] = abs($neto);
                            $cm['saldo_naturaleza'] = 'Acreedor (ANÓMALO)';
                            $cm['saldo_anomalo'] = true;
                        }
                    } else {
                        if ($neto >= 0) {
                            $cm['saldo_monto'] = $neto;
                            $cm['saldo_naturaleza'] = 'Acreedor';
                            $cm['saldo_anomalo'] = false;
                        } else {
                            $cm['saldo_monto'] = abs($neto);
                            $cm['saldo_naturaleza'] = 'Deudor (ANÓMALO)';
                            $cm['saldo_anomalo'] = true;
                        }
                    }
                }

                $this->jsonResponse([
                    'success' => true,
                    'data' => $cuentasMayor
                ]);
                return;
            }

            // Regla 25 Enterprise: CTE con Window Function ROW_NUMBER() OVER (PARTITION BY cuenta_id, moneda) para eliminar N+1 subconsultas
            $sql = "
                WITH ultimos_saldos AS (
                    SELECT 
                        cuenta_id, moneda, saldo_final_base, saldo_final_origen,
                        ROW_NUMBER() OVER (
                            PARTITION BY cuenta_id, moneda 
                            ORDER BY ejercicio DESC, mes DESC
                        ) AS rn
                    FROM saldos_cuentas_mensuales
                    WHERE cuenta_id = :cuenta_id1 AND moneda = :moneda1
                      AND (ejercicio < :ejercicio1 OR (ejercicio = :ejercicio2 AND mes < :mes1))
                )
                SELECT 
                    c.id as cuenta_id, c.codigo, c.nombre, c.naturaleza,
                    COALESCE(s.saldo_inicial_base, u.saldo_final_base, 0.00) AS saldo_inicial_base,
                    COALESCE(s.debitos_base, 0.00) AS debitos_base,
                    COALESCE(s.creditos_base, 0.00) AS creditos_base,
                    COALESCE(s.saldo_final_base, u.saldo_final_base, 0.00) AS saldo_final_base,
                    COALESCE(s.saldo_inicial_origen, u.saldo_final_origen, 0.00) AS saldo_inicial_origen,
                    COALESCE(s.debitos_origen, 0.00) AS debitos_origen,
                    COALESCE(s.creditos_origen, 0.00) AS creditos_origen,
                    COALESCE(s.saldo_final_origen, u.saldo_final_origen, 0.00) AS saldo_final_origen
                FROM cuentas c
                LEFT JOIN saldos_cuentas_mensuales s 
                    ON s.cuenta_id = c.id AND s.ejercicio = :ejercicio3 AND s.mes = :mes2 AND s.moneda = :moneda2
                LEFT JOIN ultimos_saldos u 
                    ON u.cuenta_id = c.id AND u.moneda = :moneda3 AND u.rn = 1
                WHERE c.id = :cuenta_id2
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':cuenta_id1' => $cuentaId,
                ':cuenta_id2' => $cuentaId,
                ':moneda1' => $moneda,
                ':moneda2' => $moneda,
                ':moneda3' => $moneda,
                ':ejercicio1' => $ejercicio,
                ':ejercicio2' => $ejercicio,
                ':ejercicio3' => $ejercicio,
                ':mes1' => $mes,
                ':mes2' => $mes,
            ]);
            $resumen = $stmt->fetch(PDO::FETCH_ASSOC);

            // Mapeo inverso de Mes Virtual (1..14) para los movimientos de la tarjeta de Mayor
            $stmtMov = $this->db->prepare("
                SELECT d.*, a.numero as asiento_numero, a.fecha as asiento_fecha, a.concepto as asiento_concepto
                FROM detalles_asiento d
                JOIN asientos a ON d.asiento_id = a.id
                WHERE d.cuenta_id = :cuenta_id 
                  AND a.estado = 'confirmado'
                  AND YEAR(a.fecha) = :ejercicio
                  AND (
                    (:mes <= 12 AND MONTH(a.fecha) = :mes) OR
                    (:mes = 13 AND a.tipo = 'ajuste' AND MONTH(a.fecha) = 12) OR
                    (:mes = 14 AND a.tipo = 'cierre')
                  )
                ORDER BY a.fecha ASC, a.id ASC
            ");
            $stmtMov->execute([
                ':cuenta_id' => $cuentaId,
                ':ejercicio' => $ejercicio,
                ':mes' => $mes
            ]);
            $movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

            $this->jsonResponse([
                'success' => true,
                'data' => [
                    'resumen' => $resumen,
                    'movimientos' => $movimientos
                ]
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function balanceComprobacion(): void
    {
        $this->validarRolContable();

        try {
            $ejercicio = (int)($_GET['ejercicio'] ?? date('Y'));
            $moneda = $_GET['moneda'] ?? 'VES';
            $soloImputables = isset($_GET['solo_imputables']) ? (int)$_GET['solo_imputables'] : 1;

            $mesDesde = (int)($_GET['mes_desde'] ?? $_GET['mes'] ?? 1);
            $mesHasta = (int)($_GET['mes_hasta'] ?? $_GET['mes'] ?? 12);

            if (isset($_GET['desde']) && $_GET['desde'] !== '') {
                $mesDesde = (int)date('n', strtotime($_GET['desde']));
            }
            if (isset($_GET['hasta']) && $_GET['hasta'] !== '') {
                $mesHasta = (int)date('n', strtotime($_GET['hasta']));
            }

            if ($mesDesde > $mesHasta) {
                $temp = $mesDesde;
                $mesDesde = $mesHasta;
                $mesHasta = $temp;
            }

            $whereImputable = $soloImputables ? " AND c.imputable = 1 " : "";

            // OPTIMIZACIÓN O(1) RIGUROSA CON RANGO DE MESES & ARRASTRE DE SALDO REAL: Agregación condicional por rango (m1 a mn)
            $sql = "
                WITH saldos_ordenados AS (
                    SELECT 
                        s.cuenta_id,
                        s.mes,
                        s.saldo_inicial_base,
                        s.debitos_base,
                        s.creditos_base,
                        s.saldo_final_base,
                        ROW_NUMBER() OVER (
                            PARTITION BY s.cuenta_id 
                            ORDER BY s.mes DESC
                        ) AS rn_ultimo_rango,
                        ROW_NUMBER() OVER (
                            PARTITION BY s.cuenta_id 
                            ORDER BY s.mes ASC
                        ) AS rn_primer_rango
                    FROM saldos_cuentas_mensuales s
                    WHERE s.ejercicio = :ejercicio
                      AND s.mes BETWEEN :mes_desde AND :mes_hasta
                      AND s.moneda = :moneda
                ),
                saldo_inicial_previo AS (
                    SELECT 
                        s.cuenta_id,
                        s.saldo_final_base AS saldo_previo,
                        ROW_NUMBER() OVER (
                            PARTITION BY s.cuenta_id 
                            ORDER BY s.mes DESC
                        ) AS rn_previo
                    FROM saldos_cuentas_mensuales s
                    WHERE s.ejercicio = :ejercicio2
                      AND s.mes < :mes_desde2
                      AND s.moneda = :moneda2
                ),
                ultimo_saldo_historico AS (
                    SELECT 
                        s.cuenta_id,
                        s.saldo_final_base AS saldo_historico_ultimo,
                        ROW_NUMBER() OVER (
                            PARTITION BY s.cuenta_id 
                            ORDER BY s.mes DESC
                        ) AS rn_historico
                    FROM saldos_cuentas_mensuales s
                    WHERE s.ejercicio = :ejercicio3
                      AND s.mes <= :mes_hasta2
                      AND s.moneda = :moneda3
                )
                SELECT 
                    c.id,
                    c.codigo,
                    c.nombre,
                    c.tipo,
                    c.naturaleza,
                    c.imputable,
                    COALESCE(
                        p.saldo_previo,
                        MAX(CASE WHEN r.rn_primer_rango = 1 THEN r.saldo_inicial_base END),
                        0.00
                    ) AS saldo_inicial,
                    COALESCE(SUM(r.debitos_base), 0.00) AS debe_periodo,
                    COALESCE(SUM(r.creditos_base), 0.00) AS haber_periodo,
                    COALESCE(
                        MAX(CASE WHEN r.rn_ultimo_rango = 1 THEN r.saldo_final_base END),
                        p.saldo_previo,
                        h.saldo_historico_ultimo,
                        0.00
                    ) AS saldo_final
                FROM cuentas c
                LEFT JOIN saldos_ordenados r ON c.id = r.cuenta_id
                LEFT JOIN saldo_inicial_previo p ON c.id = p.cuenta_id AND p.rn_previo = 1
                LEFT JOIN ultimo_saldo_historico h ON c.id = h.cuenta_id AND h.rn_historico = 1
                WHERE (c.es_partida_presupuestaria = 0 OR c.es_partida_presupuestaria IS NULL)
                  {$whereImputable}
                GROUP BY c.id, c.codigo, c.nombre, c.tipo, c.naturaleza, c.imputable, p.saldo_previo, h.saldo_historico_ultimo
                HAVING debe_periodo > 0 OR haber_periodo > 0 OR saldo_final <> 0 OR saldo_inicial <> 0
                ORDER BY c.codigo ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':ejercicio' => $ejercicio,
                ':ejercicio2' => $ejercicio,
                ':ejercicio3' => $ejercicio,
                ':mes_desde' => $mesDesde,
                ':mes_desde2' => $mesDesde,
                ':mes_hasta' => $mesHasta,
                ':mes_hasta2' => $mesHasta,
                ':moneda' => $moneda,
                ':moneda2' => $moneda,
                ':moneda3' => $moneda,
            ]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formatear saldos contables estricto (Deudor / Acreedor) sin números negativos
            foreach ($filas as &$f) {
                $sFin = (float)$f['saldo_final'];
                $nat = strtolower(trim((string)$f['naturaleza']));

                if ($nat === 'deudora') {
                    if ($sFin >= 0) {
                        $f['saldo_deudor'] = $sFin;
                        $f['saldo_acreedor'] = 0.00;
                    } else {
                        $f['saldo_deudor'] = 0.00;
                        $f['saldo_acreedor'] = abs($sFin);
                    }
                } else {
                    if ($sFin >= 0) {
                        $f['saldo_deudor'] = 0.00;
                        $f['saldo_acreedor'] = $sFin;
                    } else {
                        $f['saldo_deudor'] = abs($sFin);
                        $f['saldo_acreedor'] = 0.00;
                    }
                }
            }

            $this->jsonResponse(['success' => true, 'data' => $filas]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
