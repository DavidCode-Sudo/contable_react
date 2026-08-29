<?php
declare(strict_types=1);

namespace Api\Controllers;

use Api\Core\Controller;
use PDO;
use Throwable;
use RuntimeException;

require_once __DIR__ . '/../../includes/funciones_contables.php';

class CatalogoCuentasController extends Controller
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getConnection();
        $this->asegurarColumnaImputable();
    }

    private function asegurarColumnaImputable(): void
    {
        try {
            $this->db->exec("ALTER TABLE `cuentas` ADD COLUMN `imputable` TINYINT(1) NOT NULL DEFAULT 1");
        } catch (Throwable $e) {
            // Columna ya existe
        }
    }

    private function validarRolContable(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->errorResponse("Autenticación requerida.", 401);
            exit;
        }

        $stmt = $this->db->prepare("SELECT rol FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['usuario_id']]);
        $rawRol = (string)$stmt->fetchColumn();
        $rol = strtolower(trim($rawRol));

        $rolesPermitidos = ['admin', 'administrador', 'contador', 'presidencia', 'directivo', 'director', 'superusuario', 'gerente', 'analista', 'usuario'];

        if (!empty($rol) && !in_array($rol, $rolesPermitidos, true)) {
            $rolesSesion = [];
            if (!empty($_SESSION['usuario_roles']) && is_array($_SESSION['usuario_roles'])) {
                foreach ($_SESSION['usuario_roles'] as $r) {
                    if (is_string($r)) {
                        $rolesSesion[] = strtolower(trim($r));
                    } elseif (is_array($r)) {
                        foreach ($r as $val) {
                            if (is_string($val)) {
                                $rolesSesion[] = strtolower(trim($val));
                            }
                        }
                    }
                }
            }
            if (!empty($rolesSesion) && !array_intersect($rolesSesion, $rolesPermitidos)) {
                $this->errorResponse("Acceso denegado. Se requieren privilegios contables.", 403);
                exit;
            }
        }
    }

    /**
     * Motor SQL de Jerarquía: Extraído y restaurado 100% del legacy catalogo_cuentas.php
     * Genera los campos calculados de indentación, clasificación, orden jerárquico de 7 niveles y agrupadores UI.
     */
    private function getJerarquiaSqlSelect(): string
    {
        return "
            c.*, cp.nombre as cuenta_padre_nombre,
            
            CASE 
                WHEN c.es_partida_presupuestaria = 1 THEN
                    COALESCE(c.nivel_partida,
                        CASE 
                            WHEN (COALESCE(NULLIF(c.generica, ''), '00') = '00' OR c.generica IS NULL) AND
                                 (COALESCE(NULLIF(c.especifica, ''), '00') = '00' OR c.especifica IS NULL) AND
                                 (COALESCE(NULLIF(c.subespecifica, ''), '00') = '00' OR c.subespecifica IS NULL) THEN 'partida'
                            WHEN (COALESCE(NULLIF(c.especifica, ''), '00') = '00' OR c.especifica IS NULL) AND
                                 (COALESCE(NULLIF(c.subespecifica, ''), '00') = '00' OR c.subespecifica IS NULL) THEN 'generica'
                            WHEN (COALESCE(NULLIF(c.subespecifica, ''), '00') = '00' OR c.subespecifica IS NULL) THEN 'especifica'
                            ELSE 'subespecifica'
                        END
                    )
                ELSE c.nivel_partida
            END as nivel_partida_calculado,

            CASE 
                WHEN c.es_partida_presupuestaria = 1 THEN 
                    COALESCE(c.codigo_completo, COALESCE(c.codigo, 'SIN-CODIGO'))
                ELSE COALESCE(c.codigo, 'SIN-CODIGO')
            END as codigo_display,
            
            CASE 
                WHEN c.es_partida_presupuestaria = 1 THEN
                    CASE COALESCE(c.nivel_partida,
                        CASE 
                            WHEN (COALESCE(NULLIF(c.generica, ''), '00') = '00') AND (COALESCE(NULLIF(c.especifica, ''), '00') = '00') AND (COALESCE(NULLIF(c.subespecifica, ''), '00') = '00') THEN 'partida'
                            WHEN (COALESCE(NULLIF(c.especifica, ''), '00') = '00') AND (COALESCE(NULLIF(c.subespecifica, ''), '00') = '00') THEN 'generica'
                            WHEN (COALESCE(NULLIF(c.subespecifica, ''), '00') = '00') THEN 'especifica'
                            ELSE 'subespecifica'
                        END)
                        WHEN 'partida' THEN 0
                        WHEN 'generica' THEN 1
                        WHEN 'especifica' THEN 2
                        WHEN 'subespecifica' THEN 3
                        ELSE 0
                    END
                ELSE
                    CASE 
                        WHEN c.cuenta_padre_id IS NOT NULL THEN 
                            CASE WHEN c.codigo LIKE '%.%' THEN LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', '')) + 1 ELSE 2 END
                        WHEN c.codigo LIKE '%.%' THEN LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))
                        ELSE 0
                    END
            END as nivel_indentacion,

            CASE 
                WHEN c.es_partida_presupuestaria = 1 THEN c.numero_partida
                ELSE
                    CASE 
                        WHEN c.codigo LIKE '%.%' THEN
                            CASE 
                                WHEN c.codigo REGEXP '^[0-9]+$' THEN c.codigo
                                WHEN c.codigo REGEXP '^[0-9]+\\.[0-9]+$' THEN CONCAT(SUBSTRING_INDEX(c.codigo, '.', 1), '.00.00.00.00')
                                WHEN c.codigo REGEXP '^[0-9]+\\.[0-9]+\\.[0-9]+$' THEN CONCAT(SUBSTRING_INDEX(c.codigo, '.', 2), '.00.00.00')
                                WHEN c.codigo REGEXP '^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$' THEN CONCAT(SUBSTRING_INDEX(c.codigo, '.', 3), '.00.00')
                                ELSE CONCAT(SUBSTRING_INDEX(c.codigo, '.', 4), '.00')
                            END
                        WHEN c.codigo LIKE '%-%' THEN SUBSTRING_INDEX(c.codigo, '-', 1)
                        ELSE LEFT(c.codigo, 1)
                    END
            END as codigo_padre,

            CASE 
                WHEN c.es_partida_presupuestaria = 1 THEN
                    CONCAT(
                        LPAD(CAST(COALESCE(SUBSTRING_INDEX(COALESCE(c.codigo_completo, c.codigo), '.', 1), '0') AS UNSIGNED), 3, '0'), '.',
                        CASE WHEN (LENGTH(COALESCE(c.codigo_completo, c.codigo)) - LENGTH(REPLACE(COALESCE(c.codigo_completo, c.codigo), '.', ''))) >= 1 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.codigo_completo, c.codigo), '.', 2), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                        CASE WHEN (LENGTH(COALESCE(c.codigo_completo, c.codigo)) - LENGTH(REPLACE(COALESCE(c.codigo_completo, c.codigo), '.', ''))) >= 2 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.codigo_completo, c.codigo), '.', 3), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                        CASE WHEN (LENGTH(COALESCE(c.codigo_completo, c.codigo)) - LENGTH(REPLACE(COALESCE(c.codigo_completo, c.codigo), '.', ''))) >= 3 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.codigo_completo, c.codigo), '.', 4), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                        CASE WHEN (LENGTH(COALESCE(c.codigo_completo, c.codigo)) - LENGTH(REPLACE(COALESCE(c.codigo_completo, c.codigo), '.', ''))) >= 4 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.codigo_completo, c.codigo), '.', 5), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                        CASE WHEN (LENGTH(COALESCE(c.codigo_completo, c.codigo)) - LENGTH(REPLACE(COALESCE(c.codigo_completo, c.codigo), '.', ''))) >= 5 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.codigo_completo, c.codigo), '.', 6), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                        CASE WHEN (LENGTH(COALESCE(c.codigo_completo, c.codigo)) - LENGTH(REPLACE(COALESCE(c.codigo_completo, c.codigo), '.', ''))) >= 6 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(c.codigo_completo, c.codigo), '.', 7), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END
                    )
                ELSE 
                    CASE 
                        WHEN c.codigo IS NULL OR c.codigo = '' THEN '999999999999.SIN-CODIGO'
                        WHEN c.codigo LIKE '%-%' THEN CONCAT('999.', SUBSTRING_INDEX(c.codigo, '-', 1), '.000.000.000.000.', LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(c.codigo, '-', -1), ''), '0') AS UNSIGNED), 3, '0'))
                        WHEN c.codigo LIKE '%.%' THEN
                            CONCAT(
                                LPAD(CAST(COALESCE(SUBSTRING_INDEX(c.codigo, '.', 1), '0') AS UNSIGNED), 3, '0'), '.',
                                CASE WHEN (LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))) >= 1 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 2), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                                CASE WHEN (LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))) >= 2 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 3), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                                CASE WHEN (LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))) >= 3 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 4), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                                CASE WHEN (LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))) >= 4 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 5), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                                CASE WHEN (LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))) >= 5 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 6), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END, '.',
                                CASE WHEN (LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))) >= 6 THEN LPAD(CAST(COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 7), '.', -1), ''), '0') AS UNSIGNED), 3, '0') ELSE '000' END
                            )
                        WHEN c.codigo REGEXP '^[0-9]+$' THEN CONCAT(LPAD(CAST(c.codigo AS UNSIGNED), 3, '0'), '.000.000.000.000')
                        ELSE CONCAT('999.', c.codigo)
                    END
            END as orden_jerarquico,

            CASE 
                WHEN c.es_partida_presupuestaria = 1 THEN 'partida_presupuestaria'
                ELSE
                    CASE 
                        WHEN c.codigo REGEXP '^[0-9]+$' OR c.codigo REGEXP '^[0-9]+\\.(00|0)$' THEN 'grupo'
                        WHEN c.codigo REGEXP '^[0-9]+\\.[0-9]+$' OR c.codigo REGEXP '^[0-9]+\\.[0-9]+\\.(00|0)$' THEN 'subgrupo'
                        WHEN c.codigo REGEXP '^[0-9]+\\.[0-9]+\\.[0-9]+$' OR c.codigo REGEXP '^[0-9]+\\.[0-9]+\\.[0-9]+\\.(00|0)$' THEN 'rubro'
                        ELSE 'cuenta'
                    END
            END as tipo_clasificacion,

            CASE 
                WHEN c.es_partida_presupuestaria = 1 THEN CAST(LEFT(c.numero_partida, 1) AS UNSIGNED)
                ELSE CAST(LEFT(c.codigo, 1) AS UNSIGNED)
            END as grupo_clase,

            CASE 
                WHEN c.codigo LIKE '%.%' THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 2), '.', -1) AS UNSIGNED)
                ELSE NULL
            END as subgrupo,

            CASE 
                WHEN (LENGTH(c.codigo) - LENGTH(REPLACE(c.codigo, '.', ''))) >= 2 THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(c.codigo, '.', 3), '.', -1) AS UNSIGNED)
                ELSE NULL
            END as rubro
        ";
    }

    public function index(): void
    {
        $this->validarRolContable();

        try {
            $conn = $this->db;

            $vista = $_GET['vista'] ?? (isset($_GET['imputable']) ? 'todas' : 'presupuestarias');
            $tipo = trim($_GET['tipo'] ?? '');
            $categoria = trim($_GET['categoria'] ?? '');
            $estado = trim($_GET['estado'] ?? '');
            $busqueda = trim($_GET['q'] ?? '');
            $imputable = isset($_GET['imputable']) ? (int)$_GET['imputable'] : null;

            $page = max(1, (int)($_GET['page'] ?? 1));
            $limitRaw = (int)($_GET['limit'] ?? ($imputable !== null ? 2000 : 100));
            $limit = $limitRaw === -1 ? 5000 : max(1, min(5000, $limitRaw));
            $offset = ($page - 1) * $limit;

            $where = [];
            $params = [];

            if ($imputable !== null) {
                $where[] = "c.imputable = ?";
                $params[] = $imputable;
            } elseif ($vista === 'presupuestarias') {
                $where[] = "c.es_partida_presupuestaria = 1";
                $where[] = "(COALESCE(c.codigo, '') NOT LIKE 'c%' AND COALESCE(c.codigo_completo, '') NOT LIKE 'c%')";
                $where[] = "(c.codigo IS NULL OR c.codigo != 'c00190')";
                if ($categoria) { $where[] = "c.categoria = ?"; $params[] = $categoria; }
            } elseif ($vista === 'configuracion') {
                $where[] = "(c.es_partida_presupuestaria = 0 OR c.es_partida_presupuestaria IS NULL)";
                if ($tipo) { $where[] = "c.tipo = ?"; $params[] = $tipo; }
            } else {
                $where[] = "(c.es_partida_presupuestaria = 0 OR c.es_partida_presupuestaria IS NULL)";
                $where[] = "(c.codigo IS NULL OR (c.codigo NOT LIKE 'c%' AND c.codigo != 'c00190'))";
                if ($tipo) { $where[] = "c.tipo = ?"; $params[] = $tipo; }
            }

            if ($estado) {
                $where[] = "c.estado = ?";
                $params[] = $estado;
            }

            if ($busqueda !== '') {
                if (preg_match('/^[\d\.]+$/', $busqueda)) {
                    $where[] = "(c.codigo LIKE ? OR c.codigo_completo LIKE ? OR c.numero_partida LIKE ? OR c.generica LIKE ? OR c.especifica LIKE ? OR c.subespecifica LIKE ? OR c.nombre LIKE ?)";
                    $bLike = "%{$busqueda}%";
                    array_push($params, $bLike, $bLike, $bLike, $bLike, $bLike, $bLike, $bLike);
                } else {
                    $where[] = "(c.codigo LIKE ? OR c.nombre LIKE ? OR c.codigo_completo LIKE ?)";
                    $bLike = "%{$busqueda}%";
                    array_push($params, $bLike, $bLike, $bLike);
                }
            }

            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $stmtCount = $conn->prepare("SELECT COUNT(*) FROM cuentas c {$whereSql}");
            $stmtCount->execute($params);
            $totalItems = (int) $stmtCount->fetchColumn();

            $sqlSelect = $this->getJerarquiaSqlSelect();
            $orderBy = $vista === 'presupuestarias' 
                ? "ORDER BY COALESCE(c.codigo_completo, c.codigo) ASC, c.id ASC" 
                : "ORDER BY c.codigo ASC, c.id ASC";

            $stmt = $conn->prepare("
                SELECT {$sqlSelect}
                FROM cuentas c 
                LEFT JOIN cuentas cp ON c.cuenta_padre_id = cp.id
                {$whereSql} 
                {$orderBy} 
                LIMIT ? OFFSET ?
            ");
            
            $paramPos = 1;
            foreach ($params as $paramVal) {
                $stmt->bindValue($paramPos++, $paramVal, PDO::PARAM_STR);
            }
            $stmt->bindValue($paramPos++, (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue($paramPos++, (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cuentaIds = array_column($cuentas, 'id');
            $mapaSaldos = [];
            
            if (!empty($cuentaIds)) {
                $placeholders = implode(',', array_fill(0, count($cuentaIds), '?'));
                $stmtSaldos = $conn->prepare("
                    SELECT da.cuenta_id, c.naturaleza,
                           COALESCE(SUM(CASE WHEN a.estado = 'confirmado' OR (a.estado = 'anulado' AND a.asiento_anulacion_id IS NOT NULL) THEN da.debe ELSE 0 END), 0) as total_debe,
                           COALESCE(SUM(CASE WHEN a.estado = 'confirmado' OR (a.estado = 'anulado' AND a.asiento_anulacion_id IS NOT NULL) THEN da.haber ELSE 0 END), 0) as total_haber
                    FROM detalles_asiento da
                    INNER JOIN asientos a ON da.asiento_id = a.id
                    INNER JOIN cuentas c ON da.cuenta_id = c.id
                    WHERE da.cuenta_id IN ($placeholders)
                    GROUP BY da.cuenta_id, c.naturaleza
                ");
                $stmtSaldos->execute($cuentaIds);
                
                while ($row = $stmtSaldos->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['naturaleza'] === 'acreedora') {
                        $mapaSaldos[$row['cuenta_id']] = (float)$row['total_haber'] - (float)$row['total_debe'];
                    } else {
                        $mapaSaldos[$row['cuenta_id']] = (float)$row['total_debe'] - (float)$row['total_haber'];
                    }
                }
            }

            foreach ($cuentas as &$cuenta) {
                $cuenta['es_partida_presupuestaria'] = (bool) $cuenta['es_partida_presupuestaria'];
                $cuenta['nivel_indentacion'] = (int) $cuenta['nivel_indentacion'];
                $cuenta['grupo_clase'] = isset($cuenta['grupo_clase']) ? (int)$cuenta['grupo_clase'] : null;
                $cuenta['subgrupo'] = isset($cuenta['subgrupo']) ? (int)$cuenta['subgrupo'] : null;
                $cuenta['rubro'] = isset($cuenta['rubro']) ? (int)$cuenta['rubro'] : null;
                $cuenta['saldo_actual'] = $mapaSaldos[$cuenta['id']] ?? 0.00;
            }

            $stmtStats = $conn->prepare("
                SELECT 
                    COUNT(CASE WHEN (es_partida_presupuestaria = 1 AND COALESCE(codigo, '') NOT LIKE 'c%' AND COALESCE(codigo_completo, '') NOT LIKE 'c%' AND (codigo IS NULL OR codigo != 'c00190')) THEN 1 END) AS total_pres,
                    COUNT(CASE WHEN (es_partida_presupuestaria = 1 AND estado = 'activa' AND COALESCE(codigo, '') NOT LIKE 'c%' AND COALESCE(codigo_completo, '') NOT LIKE 'c%' AND (codigo IS NULL OR codigo != 'c00190')) THEN 1 END) AS activas_pres,
                    COUNT(CASE WHEN ((es_partida_presupuestaria = 0 OR es_partida_presupuestaria IS NULL) AND (codigo IS NULL OR (codigo NOT LIKE 'c%' AND codigo != 'c00190'))) THEN 1 END) AS total_cont,
                    COUNT(CASE WHEN ((es_partida_presupuestaria = 0 OR es_partida_presupuestaria IS NULL) AND estado = 'activa' AND (codigo IS NULL OR (codigo NOT LIKE 'c%' AND codigo != 'c00190'))) THEN 1 END) AS activas_cont
                FROM cuentas
            ");
            $stmtStats->execute();
            $rowStats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

            $totalPres   = (int)($rowStats['total_pres'] ?? 0);
            $activasPres = (int)($rowStats['activas_pres'] ?? 0);
            $totalCont   = (int)($rowStats['total_cont'] ?? 0);
            $activasCont = (int)($rowStats['activas_cont'] ?? 0);
            $this->jsonResponse([
                'success' => true,
                'cuentas' => $cuentas,
                'paginacion' => [
                    'total' => $totalItems,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => max(1, (int) ceil($totalItems / $limit))
                ],
                'estadisticas' => [
                    'total_general' => $total_general,
                    'total_presupuestarias' => $totalPres,
                    'activas_presupuestarias' => $activasPres,
                    'total_contables' => $totalCont,
                    'activas_contables' => $activasCont,
                ]
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function searchPartidas(): void
    {
        $this->validarRolContable();
        $q = trim($_GET['q'] ?? '');
        $limit = max(1, min(2000, (int)($_GET['limit'] ?? 1000)));

        $where = ["c.es_partida_presupuestaria = 1", "c.estado = 'activa'"];
        $params = [];

        if ($q !== '') {
            $where[] = "(c.codigo LIKE ? OR c.codigo_completo LIKE ? OR c.nombre LIKE ?)";
            $like = "%{$q}%";
            array_push($params, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT c.id, c.codigo, c.codigo_completo, c.nombre, c.categoria
            FROM cuentas c
            WHERE {$whereSql}
            ORDER BY COALESCE(c.codigo_completo, c.codigo) ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->jsonResponse(['items' => $items]);
    }

    public function searchContables(): void
    {
        $this->validarRolContable();
        $q = trim($_GET['q'] ?? '');
        $limit = max(1, min(2000, (int)($_GET['limit'] ?? 1000)));

        $where = ["(c.es_partida_presupuestaria = 0 OR c.es_partida_presupuestaria IS NULL)", "c.estado = 'activa'"];
        $params = [];

        if ($q !== '') {
            $where[] = "(c.codigo LIKE ? OR c.nombre LIKE ?)";
            $like = "%{$q}%";
            array_push($params, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT c.id, c.codigo, c.nombre, c.tipo, c.naturaleza
            FROM cuentas c
            WHERE {$whereSql}
            ORDER BY c.codigo ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->jsonResponse(['items' => $items]);
    }

    public function show(string $id): void
    {
        $this->validarRolContable();
        $cuentaId = (int)$id;
        $conn = $this->db;

        $sqlSelect = $this->getJerarquiaSqlSelect();
        $stmt = $conn->prepare("
            SELECT {$sqlSelect}
            FROM cuentas c 
            LEFT JOIN cuentas cp ON c.cuenta_padre_id = cp.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cuentaId]);
        $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cuenta) {
            $this->errorResponse("Cuenta no encontrada", 404);
            return;
        }

        // Calcular saldo contable
        $stmtSaldo = $conn->prepare("
            SELECT da.cuenta_id, c.naturaleza,
                   COALESCE(SUM(CASE WHEN a.estado = 'confirmado' OR (a.estado = 'anulado' AND a.asiento_anulacion_id IS NOT NULL) THEN da.debe ELSE 0 END), 0) as total_debe,
                   COALESCE(SUM(CASE WHEN a.estado = 'confirmado' OR (a.estado = 'anulado' AND a.asiento_anulacion_id IS NOT NULL) THEN da.haber ELSE 0 END), 0) as total_haber
            FROM detalles_asiento da
            INNER JOIN asientos a ON da.asiento_id = a.id
            INNER JOIN cuentas c ON da.cuenta_id = c.id
            WHERE da.cuenta_id = ?
            GROUP BY da.cuenta_id, c.naturaleza
        ");
        $stmtSaldo->execute([$cuentaId]);
        $rowSaldo = $stmtSaldo->fetch(PDO::FETCH_ASSOC);

        $saldo = 0.00;
        if ($rowSaldo) {
            if ($rowSaldo['naturaleza'] === 'acreedora') {
                $saldo = (float)$rowSaldo['total_haber'] - (float)$rowSaldo['total_debe'];
            } else {
                $saldo = (float)$rowSaldo['total_debe'] - (float)$rowSaldo['total_haber'];
            }
        }

        $cuenta['es_partida_presupuestaria'] = (bool) $cuenta['es_partida_presupuestaria'];
        $cuenta['nivel_indentacion'] = (int) $cuenta['nivel_indentacion'];
        $cuenta['grupo_clase'] = isset($cuenta['grupo_clase']) ? (int)$cuenta['grupo_clase'] : null;
        $cuenta['subgrupo'] = isset($cuenta['subgrupo']) ? (int)$cuenta['subgrupo'] : null;
        $cuenta['rubro'] = isset($cuenta['rubro']) ? (int)$cuenta['rubro'] : null;
        $cuenta['saldo_actual'] = $saldo;

        $this->jsonResponse($cuenta);
    }

    public function store(): void
    {
        $this->validarRolContable();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $codigo = trim($data['codigo'] ?? '');
        $nombre = trim($data['nombre'] ?? '');
        $tipo = $data['tipo'] ?? 'activo';
        $naturaleza = $data['naturaleza'] ?? 'deudora';
        $categoria = $data['categoria'] ?? null;
        $cuenta_padre_id = !empty($data['cuenta_padre_id']) ? (int)$data['cuenta_padre_id'] : null;
        $es_partida = !empty($data['es_partida_presupuestaria']) ? 1 : 0;
        $estado = $data['estado'] ?? 'activa';

        if (empty($codigo) || empty($nombre)) {
            $this->errorResponse('El código y el nombre son obligatorios', 422);
        }

        if (!preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñÜü0-9\s.\-\(\)]+$/u', $nombre)) {
            $this->errorResponse('El nombre contiene caracteres no permitidos.', 422);
        }

        $conn = $this->db;

        // Validar unicidad
        $stmtCheck = $conn->prepare("SELECT id FROM cuentas WHERE codigo = ?");
        $stmtCheck->execute([$codigo]);
        if ($stmtCheck->fetchColumn()) {
            $this->errorResponse("El código '{$codigo}' ya se encuentra registrado.", 409);
        }

        // Lógica de Partida Presupuestaria ONAPRE
        $numPartida = null; $gen = '00'; $esp = '00'; $sub = '00';
        $codigoCompleto = $codigo;

        if ($es_partida) {
            $numPartida = trim($data['numero_partida'] ?? '');
            $gen = !empty($data['generica']) ? trim((string)$data['generica']) : '00';
            $esp = !empty($data['especifica']) ? trim((string)$data['especifica']) : '00';
            $sub = !empty($data['subespecifica']) ? trim((string)$data['subespecifica']) : '00';

            if ($numPartida) {
                $partesCod = explode('.', $codigo);
                if (count($partesCod) >= 5) {
                    $sub2 = (int)$partesCod[4];
                    $codigoCompleto = sprintf("%s.%02d.%02d.%02d.%02d", $numPartida, (int)$gen, (int)$esp, (int)$sub, $sub2);
                } else {
                    $codigoCompleto = sprintf("%s.%02d.%02d.%02d.00", $numPartida, (int)$gen, (int)$esp, (int)$sub);
                }
            }

            if (empty($categoria) && function_exists('determinarClasificacionONAPRE')) {
                $categoria = determinarClasificacionONAPRE($codigoCompleto) ?: '4.02.00.00.00';
            }
        }

        $categoria = $categoria ?: ($es_partida ? '4.02.00.00.00' : 'activo');

        try {
            $conn->beginTransaction();

            $sql = "INSERT INTO cuentas (codigo, nombre, tipo, naturaleza, categoria, cuenta_padre_id, estado, es_partida_presupuestaria, numero_partida, generica, especifica, subespecifica, codigo_completo) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([
                $codigo, $nombre, $tipo, $naturaleza, $categoria, $cuenta_padre_id, $estado, 
                $es_partida, $numPartida, $gen, $esp, $sub, $codigoCompleto
            ]);
            
            $newId = (int)$conn->lastInsertId();

            $datosProcesados = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'naturaleza' => $naturaleza,
                'categoria' => $categoria,
                'cuenta_padre_id' => $cuenta_padre_id,
                'estado' => $estado,
                'es_partida_presupuestaria' => $es_partida,
                'numero_partida' => $numPartida,
                'generica' => $gen,
                'especifica' => $esp,
                'subespecifica' => $sub,
                'codigo_completo' => $codigoCompleto
            ];

            if (function_exists('registrarCreacion')) {
                registrarCreacion('catalogo', 'partidas', $newId, $datosProcesados, "Cuenta/Partida creada: $nombre ($codigo)");
            }

            $conn->commit();
            $this->jsonResponse(['id' => $newId, 'mensaje' => 'Cuenta creada con éxito'], 201);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $this->errorResponse("Error al crear cuenta: " . $e->getMessage(), 500);
        }
    }

    /**
     * Valida que la asignación de una cuenta padre no genere ciclos de recursividad.
     * Utiliza un CTE (Common Table Expression) recursivo de MariaDB/MySQL 
     * para hacer la verificación en una sola consulta de altísimo rendimiento.
     */
    private function verificarCicloJerarquico(int $cuentaId, ?int $nuevoPadreId): void
    {
        if ($nuevoPadreId === null || $nuevoPadreId <= 0) {
            return;
        }

        if ($cuentaId === $nuevoPadreId) {
            $this->errorResponse('Una cuenta no puede ser padre de sí misma (Bucle Infinito).', 422);
        }

        // CTE Recursivo: Escala desde el nuevo padre hacia arriba.
        // Si en la ruta hacia la raíz se encuentra a "cuentaId", significa que
        // estamos intentando meter una cuenta PADRE dentro de su propio HIJO.
        $sql = "
            WITH RECURSIVE Ancestros AS (
                SELECT id, cuenta_padre_id FROM cuentas WHERE id = ?
                UNION ALL
                SELECT c.id, c.cuenta_padre_id FROM cuentas c
                INNER JOIN Ancestros a ON c.id = a.cuenta_padre_id
            )
            SELECT id FROM Ancestros WHERE id = ? LIMIT 1;
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$nuevoPadreId, $cuentaId]);

        if ($stmt->fetchColumn()) {
            $this->errorResponse(
                'Relación circular detectada: está intentando asignar como padre a una cuenta que ya es descendiente de la cuenta actual.',
                422
            );
        }
    }

    public function update(string $id): void
    {
        $this->validarRolContable();
        $cuentaId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $codigo = trim($data['codigo'] ?? '');
        $nombre = trim($data['nombre'] ?? '');
        $tipo = $data['tipo'] ?? 'activo';
        $naturaleza = $data['naturaleza'] ?? 'deudora';
        $categoria = $data['categoria'] ?? null;
        $cuenta_padre_id = !empty($data['cuenta_padre_id']) ? (int)$data['cuenta_padre_id'] : null;
        $es_partida = !empty($data['es_partida_presupuestaria']) ? 1 : 0;
        $estado = $data['estado'] ?? 'activa';

        if (empty($codigo) || empty($nombre)) {
            $this->errorResponse('El código y el nombre son obligatorios', 422);
        }

        // Prevenir bucles infinitos y ciclos de N-grados en la jerarquía
        $this->verificarCicloJerarquico($cuentaId, $cuenta_padre_id);

        $conn = $this->db;

        // Validar unicidad ignorando el ID actual
        $stmtCheck = $conn->prepare("SELECT id FROM cuentas WHERE codigo = ? AND id != ?");
        $stmtCheck->execute([$codigo, $cuentaId]);
        if ($stmtCheck->fetchColumn()) {
            $this->errorResponse("El código '{$codigo}' ya pertenece a otra cuenta.", 409);
        }

        // Lógica ONAPRE
        $numPartida = null; $gen = '00'; $esp = '00'; $sub = '00';
        $codigoCompleto = $codigo;

        if ($es_partida) {
            $numPartida = trim($data['numero_partida'] ?? '');
            $gen = !empty($data['generica']) ? trim((string)$data['generica']) : '00';
            $esp = !empty($data['especifica']) ? trim((string)$data['especifica']) : '00';
            $sub = !empty($data['subespecifica']) ? trim((string)$data['subespecifica']) : '00';

            if ($numPartida) {
                $partesCod = explode('.', $codigo);
                if (count($partesCod) >= 5) {
                    $sub2 = (int)$partesCod[4];
                    $codigoCompleto = sprintf("%s.%02d.%02d.%02d.%02d", $numPartida, (int)$gen, (int)$esp, (int)$sub, $sub2);
                } else {
                    $codigoCompleto = sprintf("%s.%02d.%02d.%02d.00", $numPartida, (int)$gen, (int)$esp, (int)$sub);
                }
            }
            if (empty($categoria) && function_exists('determinarClasificacionONAPRE')) {
                $categoria = determinarClasificacionONAPRE($codigoCompleto) ?: '4.02.00.00.00';
            }
        }
        $categoria = $categoria ?: ($es_partida ? '4.02.00.00.00' : 'activo');

        try {
            $conn->beginTransaction();

            $stmtPrev = $conn->prepare("SELECT * FROM cuentas WHERE id = ?");
            $stmtPrev->execute([$cuentaId]);
            $datosAnteriores = $stmtPrev->fetch(PDO::FETCH_ASSOC);

            if (!$datosAnteriores) {
                $this->errorResponse("Cuenta no encontrada", 404);
            }

            // UPDATE directo sobre la tabla cuentas sin afectación ni eliminación de relaciones
            $sql = "UPDATE cuentas SET codigo=?, nombre=?, tipo=?, naturaleza=?, categoria=?, cuenta_padre_id=?, estado=?, es_partida_presupuestaria=?, numero_partida=?, generica=?, especifica=?, subespecifica=?, codigo_completo=? WHERE id=?";
            $conn->prepare($sql)->execute([
                $codigo, $nombre, $tipo, $naturaleza, $categoria, $cuenta_padre_id, $estado, 
                $es_partida, $numPartida, $gen, $esp, $sub, $codigoCompleto, $cuentaId
            ]);

            $datosProcesados = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'naturaleza' => $naturaleza,
                'categoria' => $categoria,
                'cuenta_padre_id' => $cuenta_padre_id,
                'estado' => $estado,
                'es_partida_presupuestaria' => $es_partida,
                'numero_partida' => $numPartida,
                'generica' => $gen,
                'especifica' => $esp,
                'subespecifica' => $sub,
                'codigo_completo' => $codigoCompleto
            ];

            if (function_exists('registrarActualizacion')) {
                registrarActualizacion('catalogo', 'partidas', $cuentaId, $datosAnteriores, $datosProcesados, "Cuenta actualizada: $nombre ($codigo)");
            }

            $conn->commit();
            $this->jsonResponse(['mensaje' => 'Cuenta actualizada con éxito']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $this->errorResponse("Error al actualizar cuenta: " . $e->getMessage(), 500);
        }
    }

    public function toggleEstado(string $id): void
    {
        $this->validarRolContable();
        $cuentaId = (int)$id;
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $nuevoEstado = $data['estado'] ?? '';

        if (!in_array($nuevoEstado, ['activa', 'inactiva'], true)) {
            $this->errorResponse('Estado inválido', 400);
        }

        $conn = $this->db;

        try {
            $conn->beginTransaction();
            
            $stmtPrev = $conn->prepare("SELECT * FROM cuentas WHERE id = ? FOR UPDATE");
            $stmtPrev->execute([$cuentaId]);
            $cuenta = $stmtPrev->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                $this->errorResponse("Cuenta no encontrada", 404);
            }

            // Regla de negocio: Prevenir desactivación si la cuenta posee dependencias registradas (ON DELETE RESTRICT Virtual)
            if ($nuevoEstado === 'inactiva') {
                // 1. Verificar Inventario
                $stmtProd = $conn->prepare("SELECT COUNT(*) FROM productos WHERE cuenta_id = ?");
                $stmtProd->execute([$cuentaId]);
                if ((int)$stmtProd->fetchColumn() > 0) {
                    throw new RuntimeException("No se puede desactivar: la cuenta está vinculada a productos en el inventario.");
                }

                // 2. Verificar Contabilidad
                $stmtAsiento = $conn->prepare("SELECT COUNT(*) FROM detalles_asiento WHERE cuenta_id = ?");
                $stmtAsiento->execute([$cuentaId]);
                if ((int)$stmtAsiento->fetchColumn() > 0) {
                    throw new RuntimeException("No se puede desactivar: la cuenta posee movimientos contables (asientos) registrados.");
                }

                // 3. Verificar Presupuestos Asignados
                $stmtPres = $conn->prepare("SELECT COUNT(*) FROM presupuestos WHERE cuenta_id = ?");
                $stmtPres->execute([$cuentaId]);
                if ((int)$stmtPres->fetchColumn() > 0) {
                    throw new RuntimeException("No se puede desactivar: la partida tiene presupuesto asignado vigente.");
                }

                // 4. Verificar Matriz de Conversión
                $stmtMatriz = $conn->prepare("SELECT COUNT(*) FROM matriz_conversion WHERE partida_presupuestaria_id = ? OR cuenta_contable_debe_id = ? OR cuenta_contable_haber_id = ?");
                $stmtMatriz->execute([$cuentaId, $cuentaId, $cuentaId]);
                if ((int)$stmtMatriz->fetchColumn() > 0) {
                    throw new RuntimeException("No se puede desactivar: la cuenta forma parte de las reglas de la Matriz de Conversión.");
                }
            }

            $conn->prepare("UPDATE cuentas SET estado = ? WHERE id = ?")->execute([$nuevoEstado, $cuentaId]);

            if (function_exists('registrarAuditoria')) {
                $acc = $nuevoEstado === 'activa' ? 'activacion' : 'desactivacion';
                registrarAuditoria($acc, 'catalogo', "Estado cambiado a {$nuevoEstado}: {$cuenta['nombre']}", 'cuentas', $cuentaId, ['estado' => $cuenta['estado']], ['estado' => $nuevoEstado]);
            }

            $conn->commit();
            $this->jsonResponse(['mensaje' => "Cuenta cambiada a {$nuevoEstado}"]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $this->errorResponse($e->getMessage(), $e instanceof RuntimeException ? 409 : 500);
        }
    }

    /**
     * POST /api/catalogo/cuentas/crear-inventario
     * Asistente para la creación automática de la cuenta patrimonial de Inventario (1102)
     */
    public function crearInventario(): void
    {
        $this->validarRolContable();
        $conn = $this->db;

        $stmt = $conn->prepare("SELECT id FROM cuentas WHERE codigo = '1102' LIMIT 1");
        $stmt->execute();
        if ($stmt->fetch()) {
            $this->errorResponse('La partida de Inventario (1102) ya existe en el catálogo.', 409);
            return;
        }

        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO cuentas (codigo, codigo_completo, nombre, tipo, naturaleza, estado, es_partida_presupuestaria) VALUES ('1102', '1102', 'Inventario', 'activo', 'deudora', 'activa', 0)");
            $stmt->execute();
            $cuenta_id = (int)$conn->lastInsertId();

            if (function_exists('registrarCreacion')) {
                registrarCreacion('catalogo', 'cuentas', $cuenta_id, [
                    'codigo' => '1102',
                    'nombre' => 'Inventario',
                    'tipo' => 'activo',
                    'naturaleza' => 'deudora',
                    'estado' => 'activa'
                ], "Partida de inventario creada automáticamente");
            }

            $conn->commit();
            $this->jsonResponse([
                'success' => true, 
                'id' => $cuenta_id, 
                'message' => 'Partida de Inventario (1102) creada exitosamente'
            ], 201);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $this->errorResponse('Error al crear partida de inventario: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/catalogo/cuentas/validar
     * Endpoint para debounce de validación en React (código / nombre)
     */
    public function validarCampo(): void
    {
        $this->validarRolContable();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        
        $campo = $data['campo'] ?? '';
        $valor = trim($data['valor'] ?? '');
        $omitirId = !empty($data['omitir_id']) ? (int)$data['omitir_id'] : 0;

        if (!in_array($campo, ['codigo', 'nombre'], true)) {
            $this->errorResponse("Campo inválido", 400);
        }

        if ($valor === '') {
            $this->jsonResponse(['valido' => false, 'mensaje' => 'El valor no puede estar vacío']);
            return;
        }

        // Validación Regex Nombre
        if ($campo === 'nombre' && !preg_match('/^[A-Za-zÁÉÍÓÚáéíóúÑñÜü0-9\s.\-\(\)]+$/u', $valor)) {
            $this->jsonResponse(['valido' => false, 'mensaje' => 'Contiene caracteres no permitidos.']);
            return;
        }

        // Check Unique DB
        $sql = "SELECT id FROM cuentas WHERE {$campo} = :valor";
        $params = [':valor' => $valor];
        if ($omitirId > 0) {
            $sql .= " AND id != :id";
            $params[':id'] = $omitirId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn()) {
            $this->jsonResponse(['valido' => false, 'mensaje' => "El {$campo} '{$valor}' ya existe en el sistema."]);
        } else {
            $this->jsonResponse(['valido' => true, 'mensaje' => "Disponible"]);
        }
    }

    /**
     * POST /api/catalogo/cuentas/validar-partida
     * Endpoint para validación en tiempo real de código de partida presupuestaria ONAPRE
     */
    public function validarCodigoPartida(): void
    {
        $this->validarRolContable();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $numPartida = trim($data['numero_partida'] ?? '');
        $gen = sprintf("%02d", (int)($data['generica'] ?? 0));
        $esp = sprintf("%02d", (int)($data['especifica'] ?? 0));
        $sub = sprintf("%02d", (int)($data['subespecifica'] ?? 0));
        $omitirId = !empty($data['omitir_id']) ? (int)$data['omitir_id'] : 0;

        if ($numPartida === '') {
            $this->jsonResponse(['valido' => false, 'mensaje' => 'El número de partida es obligatorio']);
            return;
        }

        // CORRECCIÓN: Permitir números y puntos para soportar formatos como "4.01"
        if (!preg_match('/^[0-9\.]+$/', $numPartida)) {
            $this->jsonResponse(['valido' => false, 'mensaje' => 'El número de partida solo puede contener números y puntos']);
            return;
        }

        $codigoCompleto = sprintf("%s.%s.%s.%s.00", $numPartida, $gen, $esp, $sub);

        $sql = "SELECT id FROM cuentas WHERE codigo_completo = :codigo_completo";
        $params = [':codigo_completo' => $codigoCompleto];
        if ($omitirId > 0) {
            $sql .= " AND id != :id";
            $params[':id'] = $omitirId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn()) {
            $this->jsonResponse(['valido' => false, 'mensaje' => "El código de partida {$codigoCompleto} ya existe"]);
        } else {
            $this->jsonResponse(['valido' => true, 'mensaje' => "Código de partida {$codigoCompleto} disponible", 'codigo_completo' => $codigoCompleto]);
        }
    }

    private function asegurarTablaCuentas(): void
    {
        try {
            $checkLoteCol = $this->db->query("
                SHOW COLUMNS FROM `cuentas` LIKE 'lote_importacion'
            ")->fetch();

            if (!$checkLoteCol) {
                $this->db->exec("
                    ALTER TABLE `cuentas` 
                    ADD COLUMN `lote_importacion` VARCHAR(100) DEFAULT NULL,
                    ADD INDEX `idx_lote_importacion` (`lote_importacion`)
                ");
            }

            // Auto-sanear registros legacy donde codigo_completo fue truncado o corrompido con .00 hardcoded
            $this->db->exec("
                UPDATE cuentas 
                SET codigo_completo = codigo 
                WHERE es_partida_presupuestaria = 1 
                  AND (codigo_completo IS NULL OR codigo_completo = '' OR (codigo LIKE '4.%.%.%.%' AND codigo_completo != codigo))
            ");

            // Auto-eliminar duplicados idénticos misclasificados de ONCOP (Protección de Integridad: Solo si NO poseen movimientos ni reglas activas)
            $this->db->exec("
                DELETE c1 FROM cuentas c1
                INNER JOIN cuentas c2 ON c1.codigo = c2.codigo AND c1.id != c2.id
                LEFT JOIN detalles_asiento da ON da.cuenta_id = c1.id
                LEFT JOIN matriz_conversion m ON (m.partida_presupuestaria_id = c1.id OR m.cuenta_contable_debe_id = c1.id OR m.cuenta_contable_haber_id = c1.id)
                WHERE c1.es_partida_presupuestaria = 1 
                  AND c2.es_partida_presupuestaria = 0 
                  AND da.id IS NULL 
                  AND m.id IS NULL
                  AND (c1.codigo LIKE '1.%' OR c1.codigo LIKE '2.%' OR c1.codigo LIKE '5.%' OR c1.codigo LIKE '6.%' OR (c1.codigo LIKE '3.%' AND c1.codigo NOT LIKE '3.0%'))
            ");

            // Auto-sanear filas dummys creadas por encabezados no omitidos previa reparación multibyte
            $this->db->exec("
                DELETE FROM cuentas 
                WHERE LOWER(codigo) IN ('codigo', 'código', 'cod') 
                   OR LOWER(nombre) IN ('denominacion', 'denominación', 'nombre')
            ");

            // Inmunizar cuentas vinculadas a la Matriz de Conversión o Asientos limpiando su lote_importacion
            $this->db->exec("
                UPDATE cuentas c
                INNER JOIN matriz_conversion m ON (m.partida_presupuestaria_id = c.id OR m.cuenta_contable_debe_id = c.id OR m.cuenta_contable_haber_id = c.id)
                SET c.lote_importacion = NULL
            ");
            $this->db->exec("
                UPDATE cuentas c
                INNER JOIN detalles_asiento d ON d.cuenta_id = c.id
                SET c.lote_importacion = NULL
            ");
        } catch (Throwable $e) {
            // Silencioso si ya existe la columna
        }
    }

    /**
     * POST /api/catalogo/cuentas/importar
     * Importación masiva resiliente de Catálogo de Cuentas y Partidas (ONAPRE y ONCOP)
     */
    public function importarMasivo(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaCuentas();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (empty($_FILES['archivo']) && empty($_FILES['archivo_excel']))) {
            $this->errorResponse('No se ha recibido un archivo válido para importar.', 400);
            return;
        }

        $forzarDominio = strtolower(trim((string)($_POST['dominio_objetivo'] ?? $_POST['dominio'] ?? $_POST['tipo'] ?? '')));

        $file = $_FILES['archivo'] ?? $_FILES['archivo_excel'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->errorResponse('Error en la subida del archivo al servidor.', 400);
            return;
        }

        $tmpPath   = $file['tmp_name'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tamanio   = (int)$file['size'];

        $extensionesPermitidas = ['csv', 'xls', 'xlsx'];
        if (!in_array($extension, $extensionesPermitidas, true)) {
            $this->errorResponse('Formato no soportado. Solo se permiten archivos .csv, .xls o .xlsx.', 400);
            return;
        }

        // 🚨 VERIFICACIÓN DE MAGIC BYTES (Seguridad contra Spoofing de Archivos)
        $handleBytes = @fopen($tmpPath, 'rb');
        $bytesHead   = $handleBytes ? fread($handleBytes, 8) : '';
        if ($handleBytes) fclose($handleBytes);

        $isZipPk  = str_starts_with($bytesHead, "\x50\x4B\x03\x04");
        $isOle2   = str_starts_with($bytesHead, "\xD0\xCF\x11\xE0");
        $isXmlDoc = (str_contains(substr($bytesHead, 0, 8), '<?xml') || str_contains(substr($bytesHead, 0, 8), '<Work'));

        $isExcelBinaryOrXml = ($isZipPk || $isOle2 || $isXmlDoc);

        // 🚨 CIRCUIT BREAKER 5MB (Anti-Memory Leak para XML / XLSX sin librería)
        if (($isExcelBinaryOrXml || in_array($extension, ['xls', 'xlsx'], true)) && $tamanio > 5 * 1024 * 1024) {
            $this->errorResponse(
                'El archivo Excel supera el límite táctico de 5MB para procesamiento directo en memoria. ' .
                'Por favor convierta el archivo a formato CSV (delimitado por comas) para procesar volúmenes masivos mediante streaming sin colapsar el servidor.',
                400
            );
            return;
        }

        // 🚨 RECHAZO DE SPOOFING: Si la extensión es .csv pero la firma es un paquete Zip/XLSX o binario OLE2
        if ($extension === 'csv' && ($isZipPk || $isOle2)) {
            $this->errorResponse(
                'Seguridad de Archivos: El archivo posee extensión .csv pero su firma de bytes (Magic Bytes) corresponde a un archivo Excel (.xlsx/.xls) comprimido o binario. Renombrarlo no cambia su formato interno.',
                400
            );
            return;
        }

        // Extracción de datos streaming
        $rows = [];
        if ($extension === 'csv' && !$isExcelBinaryOrXml) {
            $stream = @fopen($tmpPath, 'rb');
            if (!$stream) {
                $this->errorResponse('No se pudo abrir el archivo CSV.', 500);
                return;
            }

            // Auto-detectar delimitador (, o ;)
            $firstLine = fgets($stream);
            $delimiter = (substr_count($firstLine ?: '', ';') > substr_count($firstLine ?: '', ',')) ? ';' : ',';
            rewind($stream);

            while (($data = fgetcsv($stream, 0, $delimiter)) !== false) {
                if (empty($data) || (count($data) === 1 && trim((string)$data[0]) === '')) continue;
                $rows[] = array_map(fn($v) => mb_convert_encoding(trim((string)$v), 'UTF-8', 'UTF-8, ISO-8859-1, WINDOWS-1252'), $data);
            }
            fclose($stream);
        } else if ($isZipPk) {
            // 🚨 SOPORTE NATIVO ENTERPRISE PARA ARCHIVOS .XLSX (Zip Container + OpenXML Streaming + Coordenadas de Celdas)
            if (!class_exists('ZipArchive')) {
                $this->errorResponse('El servidor PHP no posee la extensión ZipArchive habilitada para procesar archivos .xlsx. Por favor utilice formato .CSV o .XLS.', 400);
                return;
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpPath) === true) {
                // 1. Extraer tabla de cadenas compartidas (sharedStrings.xml)
                $sharedStrings = [];
                $ssXml = $zip->getFromName('xl/sharedStrings.xml');
                if ($ssXml !== false) {
                    $ssObj = @simplexml_load_string($ssXml);
                    if ($ssObj && isset($ssObj->si)) {
                        foreach ($ssObj->si as $si) {
                            if (isset($si->t)) {
                                $sharedStrings[] = (string)$si->t;
                            } elseif (isset($si->r)) {
                                $text = '';
                                foreach ($si->r as $r) {
                                    $text .= (string)($r->t ?? '');
                                }
                                $sharedStrings[] = $text;
                            } else {
                                $sharedStrings[] = '';
                            }
                        }
                    }
                }

                // 2. Extraer datos de la primera hoja disponible en la estructura OpenXML
                $sheetPath = 'xl/worksheets/sheet1.xml';
                if ($zip->locateName($sheetPath) === false) {
                    for ($s = 1; $s <= 10; $s++) {
                        if ($zip->locateName("xl/worksheets/sheet{$s}.xml") !== false) {
                            $sheetPath = "xl/worksheets/sheet{$s}.xml";
                            break;
                        }
                    }
                }

                $sheetXml = $zip->getFromName($sheetPath);
                if ($sheetXml !== false) {
                    $sheetObj = @simplexml_load_string($sheetXml);
                    if ($sheetObj && isset($sheetObj->sheetData->row)) {
                        foreach ($sheetObj->sheetData->row as $rObj) {
                            $rowCells = [];
                            foreach ($rObj->c as $cObj) {
                                $cellRef = (string)($cObj['r'] ?? '');
                                preg_match('/([A-Z]+)(\d+)/i', $cellRef, $matches);
                                $colLetters = $matches[1] ?? 'A';
                                
                                $colIdx = 0;
                                for ($i = 0; $i < strlen($colLetters); $i++) {
                                    $colIdx = $colIdx * 26 + (ord(strtoupper($colLetters[$i])) - 64);
                                }
                                $colIdx -= 1;

                                $type = (string)($cObj['t'] ?? '');
                                $val = '';
                                if ($type === 's') {
                                    $val = $sharedStrings[(int)($cObj->v ?? 0)] ?? '';
                                } elseif (isset($cObj->is->r)) {
                                    $val = '';
                                    foreach ($cObj->is->r as $rItem) {
                                        $val .= (string)($rItem->t ?? '');
                                    }
                                } elseif ($type === 'inlineStr' && isset($cObj->is->t)) {
                                    $val = (string)$cObj->is->t;
                                } elseif (isset($cObj->is->t)) {
                                    $val = (string)$cObj->is->t;
                                } elseif (isset($cObj->inlineStr->t)) {
                                    $val = (string)$cObj->inlineStr->t;
                                } else {
                                    $val = (string)($cObj->v ?? '');
                                }
                                
                                $rowCells[$colIdx] = trim($val);
                            }

                            if (!empty($rowCells)) {
                                $maxKey = max(array_keys($rowCells));
                                $normalizedRow = [];
                                for ($i = 0; $i <= $maxKey; $i++) {
                                    $normalizedRow[$i] = $rowCells[$i] ?? '';
                                }
                                if (implode('', $normalizedRow) !== '') {
                                    $rows[] = $normalizedRow;
                                }
                            }
                        }
                    }
                }
                $zip->close();
            } else {
                $this->errorResponse('No se pudo abrir el contenedor de datos del archivo .xlsx.', 400);
                return;
            }
        } else {
            // XML Spreadsheet 2003 (.xls)
            $content = @file_get_contents($tmpPath);
            if ($content === false) {
                $this->errorResponse('No se pudo leer el archivo Excel.', 500);
                return;
            }

            try {
                $xml = @simplexml_load_string($content);
                if ($xml !== false && isset($xml->Worksheet->Table->Row)) {
                    foreach ($xml->Worksheet->Table->Row as $rowXml) {
                        $rowCells = [];
                        foreach ($rowXml->Cell as $cell) {
                            $rowCells[] = trim((string)($cell->Data ?? ''));
                        }
                        if (!empty($rowCells) && implode('', $rowCells) !== '') {
                            $rows[] = $rowCells;
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->errorResponse('No se pudo parsear el archivo XML/XLSX.', 400);
                return;
            }
        }

        if (empty($rows)) {
            $this->errorResponse('El archivo no contiene filas o datos legibles.', 400);
            return;
        }

        $loteId = uniqid('LOTE_');
        $trackingLote = []; // 🚨 TRACKING ANTI-LFO (Anti Last-File-Occurrence): codigo => linea
        $procesados = 0; $insertados = 0; $actualizados = 0; $omitidos = 0; $errores = 0;
        $detallesErrores = [];

        $this->db->beginTransaction();
        try {
            // 🚨 PREPARAR SENTENCIAS UNA SOLA VEZ FUERA DEL BUCLE (Alto Rendimiento PDO)
            $stmtExist   = $this->db->prepare("SELECT id, nombre, es_partida_presupuestaria, estado FROM cuentas WHERE codigo = ? OR codigo_completo = ? LIMIT 1");
            $stmtUpdLote = $this->db->prepare("UPDATE cuentas SET es_partida_presupuestaria = ?, codigo_completo = ? WHERE id = ?");
            $stmtUpd     = $this->db->prepare("UPDATE cuentas SET nombre = ?, es_partida_presupuestaria = ?, codigo_completo = ? WHERE id = ?");
            $stmtIns     = $this->db->prepare("INSERT INTO cuentas (codigo, nombre, tipo, naturaleza, estado, es_partida_presupuestaria, numero_partida, generica, especifica, subespecifica, codigo_completo, lote_importacion) VALUES (?, ?, ?, ?, 'activa', ?, ?, ?, ?, ?, ?, ?)");

            foreach ($rows as $index => $row) {
                $numLinea = $index + 1;
                $c0 = trim((string)($row[0] ?? ''));
                $c1 = trim((string)($row[1] ?? ''));

                // Omitir cualquier encabezado o fila de comentario sin alterar puntos/guiones del código
                if (empty($c0) || str_starts_with($c0, '#')) {
                    continue;
                }

                $c0Lower = mb_strtolower($c0, 'UTF-8');
                if (in_array($c0Lower, ['codigo', 'código', 'cod', 'id', 'cuenta', 'partida', 'denominacion', 'denominación'], true)) {
                    continue;
                }

                $procesados++;
                $codigoRaw = $c0;
                $nombreRaw = !empty($c1) ? $c1 : 'Cuenta Sin Nombre';

                // 🚨 PARCHE 1: CONTEXTO DE DOMINIO EXPLÍCITO U INFERENCIA ESTRICTA
                $cleanCode = trim($codigoRaw);
                $partesCodigo = explode('.', $cleanCode);
                $primerDigito = (int)substr(preg_replace('/[^0-9]/', '', $cleanCode), 0, 1);

                $esPartida = 0;
                if (in_array($forzarDominio, ['presupuestario', 'presupuestarias', 'onapre'], true)) {
                    $esPartida = 1;
                } elseif (in_array($forzarDominio, ['patrimonial', 'patrimoniales', 'contables', 'oncop'], true)) {
                    $esPartida = 0;
                } else {
                    // REGLA DE ORO ONAPRE: Gastos inician en 4. o Ingresos Presupuestarios en 3.0
                    if (str_starts_with($cleanCode, '4.') || str_starts_with($cleanCode, '3.0') || ($primerDigito === 4 && count($partesCodigo) >= 3)) {
                        $esPartida = 1;
                    }
                }

                // Inferencia de Tipo y Naturaleza basada en el primer dígito del código
                $tipo = 'activo';
                $naturaleza = 'deudora';

                switch ($primerDigito) {
                    case 1:
                        $tipo = 'activo';
                        $naturaleza = 'deudora';
                        break;
                    case 2:
                        $tipo = 'pasivo';
                        $naturaleza = 'acreedora';
                        break;
                    case 3:
                        $tipo = $esPartida ? 'ingreso_presupuestario' : 'patrimonio';
                        $naturaleza = 'acreedora';
                        break;
                    case 4:
                        $tipo = 'gasto';
                        $naturaleza = 'deudora';
                        break;
                    case 5:
                        $tipo = 'ingreso';
                        $naturaleza = 'acreedora';
                        break;
                    case 6:
                        $tipo = 'gasto';
                        $naturaleza = 'deudora';
                        break;
                    default:
                        $tipo = $esPartida ? 'gasto' : 'activo';
                        $naturaleza = 'deudora';
                        break;
                }

                // Segmentación ONAPRE (Soporte dinámico para 2 a 5 niveles de clasificación)
                $codigoCompleto = $codigoRaw;
                $numPartida = null; $gen = '00'; $esp = '00'; $sub = '00';

                if ($esPartida) {
                    $numPartida = $partesCodigo[0] ?? '4';
                    $gen = isset($partesCodigo[1]) ? sprintf("%02d", (int)$partesCodigo[1]) : '00';
                    $esp = isset($partesCodigo[2]) ? sprintf("%02d", (int)$partesCodigo[2]) : '00';
                    $sub = isset($partesCodigo[3]) ? sprintf("%02d", (int)$partesCodigo[3]) : '00';
                    $sub2 = isset($partesCodigo[4]) ? sprintf("%02d", (int)$partesCodigo[4]) : null;

                    if ($sub2 !== null) {
                        $codigoCompleto = sprintf("%s.%s.%s.%s.%s", $numPartida, $gen, $esp, $sub, $sub2);
                    } else if (count($partesCodigo) === 4) {
                        $codigoCompleto = sprintf("%s.%s.%s.%s.00", $numPartida, $gen, $esp, $sub);
                    } else if (count($partesCodigo) === 3) {
                        $codigoCompleto = sprintf("%s.%s.%s.00.00", $numPartida, $gen, $esp);
                    } else if (count($partesCodigo) === 2) {
                        $codigoCompleto = sprintf("%s.%s.00.00.00", $numPartida, $gen);
                    } else {
                        $codigoCompleto = $codigoRaw;
                    }
                }

                // 🚨 TRACKING ANTI-LFO (Anti Last-File-Occurrence): Previene colisión en el mismo archivo
                $codigoKey = $esPartida ? $codigoCompleto : $codigoRaw;
                if (isset($trackingLote[$codigoKey])) {
                    $errores++;
                    $detallesErrores[] = "Línea {$numLinea}: Conflicto interno. El código '{$codigoKey}' ya fue procesado en la línea {$trackingLote[$codigoKey]} de este mismo archivo.";
                    continue;
                }
                $trackingLote[$codigoKey] = $numLinea;

                // 🚨 BÚSQUEDA DE EXISTENCIA AGNÓSTICA AL DOMINIO (Auto-sana si antes se guardó en el dominio equivocado)
                try {
                    $stmtExist->execute([$codigoRaw, $codigoCompleto]);
                    $existente = $stmtExist->fetch(PDO::FETCH_ASSOC);

                    if ($existente) {
                        $existId = (int)$existente['id'];
                        $oldNombre = (string)$existente['nombre'];

                        $normOld = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $oldNombre)));
                        $normNew = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nombreRaw)));

                        if ($normOld === $normNew) {
                            // CASO 1: DUPLICADO IDÉNTICO -> OMITIDO (Auto-sana es_partida_presupuestaria y codigo_completo)
                            $stmtUpdLote->execute([$esPartida, $codigoCompleto, $existId]);
                            $omitidos++;
                        } else {
                            // CASO 2: REEMPLAZO DE NOMBRE -> ACTUALIZADO (Auto-sana es_partida_presupuestaria, nombre y codigo_completo)
                            $stmtUpd->execute([$nombreRaw, $esPartida, $codigoCompleto, $existId]);
                            $actualizados++;
                        }
                    } else {
                        // CASO 3: REGISTRO NUEVO -> NUEVA
                        $stmtIns->execute([
                            $codigoRaw, $nombreRaw, $tipo, $naturaleza, $esPartida,
                            $numPartida, $gen, $esp, $sub, $codigoCompleto, $loteId
                        ]);
                        $insertados++;
                    }
                } catch (Throwable $eFila) {
                    $errores++;
                    $detallesErrores[] = "Línea {$numLinea}: " . $eFila->getMessage();
                }
            }

            $this->db->commit();

            $partesMsj = [];
            if ($insertados > 0) $partesMsj[] = "{$insertados} nueva(s)";
            if ($actualizados > 0) $partesMsj[] = "{$actualizados} actualizada(s)";
            if ($omitidos > 0) $partesMsj[] = "{$omitidos} duplicada(s) omitida(s)";
            if ($errores > 0) $partesMsj[] = "{$errores} con error";

            $resumenTexto = !empty($partesMsj) ? implode(', ', $partesMsj) : 'Sin cambios';

            $this->jsonResponse([
                'mensaje' => "Importación procesada exitosamente: {$resumenTexto}.",
                'lote_id' => $loteId,
                'procesados' => $procesados,
                'insertados' => $insertados,
                'actualizados' => $actualizados,
                'omitidos' => $omitidos,
                'errores' => $errores,
                'detalles_errores' => $detallesErrores
            ]);
        } catch (Throwable $eBatch) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorResponse("Error crítico durante la importación masiva: " . $eBatch->getMessage(), 500);
        }
    }

    /**
     * POST /api/catalogo/cuentas/deshacer-ultimo-lote
     * Rollback atómico del último lote de importación masiva con verificación Fail-Closed de dependencias
     */
    public function deshacerUltimoLote(): void
    {
        $this->validarRolContable();
        $this->asegurarTablaCuentas();

        $stmtUltimo = $this->db->query("
            SELECT lote_importacion, COUNT(*) as total
            FROM cuentas
            WHERE lote_importacion IS NOT NULL AND lote_importacion != ''
            GROUP BY lote_importacion
            ORDER BY id DESC
            LIMIT 1
        ");
        $loteInfo = $stmtUltimo->fetch(PDO::FETCH_ASSOC);

        if (!$loteInfo || empty($loteInfo['lote_importacion'])) {
            $this->errorResponse('No se encontró ningún lote de importación previo para revertir.', 404);
            return;
        }

        $loteId = $loteInfo['lote_importacion'];

        // 🚨 VERIFICACIÓN FAIL-CLOSED CON JOINS RELACIONALES (Escalabilidad Infinita)
        // 1. Matriz de Conversión
        $stmtCheckMatriz = $this->db->prepare("
            SELECT COUNT(*) FROM matriz_conversion m
            INNER JOIN cuentas c ON (m.partida_presupuestaria_id = c.id OR m.cuenta_contable_debe_id = c.id OR m.cuenta_contable_haber_id = c.id)
            WHERE c.lote_importacion = ?
        ");
        $stmtCheckMatriz->execute([$loteId]);
        $matrizRefs = (int)$stmtCheckMatriz->fetchColumn();

        if ($matrizRefs > 0) {
            $this->errorResponse(
                "No se puede deshacer el lote '{$loteId}': {$matrizRefs} cuenta(s) forman parte de reglas activas en la Matriz de Conversión.",
                409
            );
            return;
        }

        // 2. Detalles Asiento (Libro Diario)
        $stmtCheckAsiento = $this->db->prepare("
            SELECT COUNT(*) FROM detalles_asiento d
            INNER JOIN cuentas c ON d.cuenta_id = c.id
            WHERE c.lote_importacion = ?
        ");
        $stmtCheckAsiento->execute([$loteId]);
        $asientoRefs = (int)$stmtCheckAsiento->fetchColumn();

        if ($asientoRefs > 0) {
            $this->errorResponse(
                "No se puede deshacer el lote '{$loteId}': {$asientoRefs} cuenta(s) ya registran movimientos en el Libro Diario.",
                409
            );
            return;
        }

        try {
            $this->db->beginTransaction();
            $stmtDel = $this->db->prepare("DELETE FROM cuentas WHERE lote_importacion = ?");
            $stmtDel->execute([$loteId]);
            $eliminados = $stmtDel->rowCount();
            $this->db->commit();

            $this->jsonResponse([
                'mensaje' => "Rollback completado exitosamente. Se eliminaron {$eliminados} registros del lote '{$loteId}'.",
                'eliminados' => $eliminados,
                'lote_id' => $loteId
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorResponse("Error al ejecutar rollback del lote: " . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/catalogo/cuentas/vaciar
     * Elimina quirúrgicamente las cuentas de un subdominio específico (presupuestario u patrimonial) previa verificación Fail-Closed
     */
    public function vaciarCatalogo(): void
    {
        $this->validarRolContable();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $tipoTarget = strtolower(trim((string)($data['tipo'] ?? '')));

        if (!in_array($tipoTarget, ['presupuestario', 'patrimonial'], true)) {
            $this->errorResponse("Debe especificar el subdominio a vaciar ('presupuestario' o 'patrimonial').", 400);
            return;
        }

        $esPartida = ($tipoTarget === 'presupuestario') ? 1 : 0;
        $nombreDominio = ($esPartida === 1) ? 'Partidas Presupuestarias ONAPRE' : 'Cuentas Patrimoniales ONCOP';

        // 🚨 VERIFICACIÓN FAIL-CLOSED DE DEPENDENCIAS EN EL SUBDOMINIO ESPECÍFICO
        if ($esPartida === 1) {
            $stmtCheckMatriz = $this->db->query("
                SELECT COUNT(*) FROM matriz_conversion m
                INNER JOIN cuentas c ON m.partida_presupuestaria_id = c.id
                WHERE c.es_partida_presupuestaria = 1
            ");
            $matrizRefs = (int)$stmtCheckMatriz->fetchColumn();

            if ($matrizRefs > 0) {
                $this->errorResponse(
                    "Operación abortada por seguridad: Existen {$matrizRefs} vinculación(es) activas en la Matriz de Conversión asociadas a Partidas Presupuestarias.",
                    409
                );
                return;
            }
        } else {
            $stmtCheckMatriz = $this->db->query("
                SELECT COUNT(*) FROM matriz_conversion m
                INNER JOIN cuentas c ON (m.cuenta_contable_debe_id = c.id OR m.cuenta_contable_haber_id = c.id)
                WHERE c.es_partida_presupuestaria = 0
            ");
            $matrizRefs = (int)$stmtCheckMatriz->fetchColumn();

            if ($matrizRefs > 0) {
                $this->errorResponse(
                    "Operación abortada por seguridad: Existen {$matrizRefs} vinculación(es) activas en la Matriz de Conversión asociadas a Cuentas Patrimoniales.",
                    409
                );
                return;
            }

            $stmtCheckAsiento = $this->db->query("
                SELECT COUNT(*) FROM detalles_asiento d
                INNER JOIN cuentas c ON d.cuenta_id = c.id
                WHERE c.es_partida_presupuestaria = 0
            ");
            $asientoRefs = (int)$stmtCheckAsiento->fetchColumn();

            if ($asientoRefs > 0) {
                $this->errorResponse(
                    "Operación abortada por trazabilidad: Existen {$asientoRefs} movimientos contables registrados en el Libro Diario asociados a Cuentas Patrimoniales.",
                    409
                );
                return;
            }
        }

        try {
            $this->db->beginTransaction();
            $stmtDel = $this->db->prepare("DELETE FROM cuentas WHERE es_partida_presupuestaria = ?");
            $stmtDel->execute([$esPartida]);
            $eliminados = $stmtDel->rowCount();
            $this->db->commit();

            $this->jsonResponse([
                'mensaje' => "El catálogo de {$nombreDominio} ha sido vaciado exitosamente. Se eliminaron {$eliminados} registros.",
                'eliminados' => $eliminados
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorResponse("Error al vaciar el catálogo: " . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/catalogo/cuentas/exportar
     * Exporta el catálogo completo de Cuentas y Partidas en formato XML Spreadsheet 2003 (.xls)
     */
    public function exportar(): void
    {
        $this->validarRolContable();

        if (ob_get_length()) {
            ob_clean();
        }

        $tipoParam = isset($_GET['tipo']) ? strtolower(trim((string)$_GET['tipo'])) : '';
        
        $sql = "
            SELECT 
                COALESCE(codigo_completo, codigo) as codigo_export,
                nombre,
                tipo,
                naturaleza,
                es_partida_presupuestaria,
                estado
            FROM cuentas
        ";
        $params = [];
        $nombreArchivo = 'catalogo_cuentas_partidas_';

        if (in_array($tipoParam, ['presupuestario', 'presupuestarias'], true)) {
            $sql .= " WHERE es_partida_presupuestaria = 1";
            $nombreArchivo = 'partidas_presupuestarias_onapre_';
        } else if (in_array($tipoParam, ['patrimonial', 'patrimoniales', 'contables'], true)) {
            $sql .= " WHERE (es_partida_presupuestaria = 0 OR es_partida_presupuestaria IS NULL)";
            $nombreArchivo = 'cuentas_patrimoniales_oncop_';
        }

        $sql .= " ORDER BY es_partida_presupuestaria DESC, codigo ASC";

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . date('Ymd_His') . '.xls"');

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#0F172A"/>
  </Style>
  <Style ss:ID="Header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#1E293B" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Center">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="TextLeft">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Catálogo de Cuentas">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="160"/>
   <Column ss:Width="350"/>
   <Column ss:Width="140"/>
   <Column ss:Width="120"/>
   <Column ss:Width="140"/>
   <Column ss:Width="100"/>
   
   <Row ss:Height="24">
    <Cell ss:StyleID="Header"><Data ss:Type="String">CÓDIGO</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">DENOMINACIÓN</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">TIPO / CLASIFICACIÓN</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">NATURALEZA</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">DOMINIO</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">ESTADO</Data></Cell>
   </Row>

   <?php foreach ($cuentas as $c): ?>
   <Row ss:Height="20">
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars((string)($c['codigo_export'] ?? '')) ?></Data></Cell>
    <Cell ss:StyleID="TextLeft"><Data ss:Type="String"><?= htmlspecialchars((string)($c['nombre'] ?? '')) ?></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars((string)($c['tipo'] ?? '')) ?></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars((string)($c['naturaleza'] ?? '')) ?></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= ((int)$c['es_partida_presupuestaria'] === 1) ? 'ONAPRE (Presupuesto)' : 'ONCOP (Patrimonial)' ?></Data></Cell>
    <Cell ss:StyleID="Center"><Data ss:Type="String"><?= htmlspecialchars((string)($c['estado'] ?? 'activa')) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
        <?php
        exit;
    }
}
