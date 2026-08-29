<?php
declare(strict_types=1);

/**
 * API RESTful: Búsqueda Autocomplete de Presupuestos / Partidas Presupuestarias
 * Soporta filtrado por Tipo de Acción ONAPRE: 'todos', 'centralizada', 'proyecto'
 * Endpoint: GET /api/requisiciones/buscar_presupuestos.php?q=4.01&tipo_filtro=centralizada
 */

require_once __DIR__ . '/../../includes/cors_middleware.php';
require_once __DIR__ . '/../../includes/verificar_sesion_api.php';
require_once __DIR__ . '/../../config/database/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido. Utilice GET.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$tipoFiltro = isset($_GET['tipo_filtro']) ? trim((string) $_GET['tipo_filtro']) : 'todos';
$limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 1000)) : 500;

try {
    $conn = getConnection();

    if ($detailId > 0) {
        $stmt = $conn->prepare("
            SELECT p.id,
                   p.cuenta_id,
                   p.tipo_accion,
                   p.proyecto_id,
                   c.codigo AS cuenta_codigo,
                   c.nombre AS cuenta_nombre,
                   py.nombre AS proyecto_nombre,
                   py.codigo AS proyecto_codigo,
                   p.observaciones AS descripcion,
                   p.monto_total,
                   COALESCE(p.saldo_por_comprometer, GREATEST(0, COALESCE(p.credito_vigente, p.monto_total) - COALESCE(p.comprometido, 0))) AS saldo_disponible,
                   pe.nombre AS periodo_nombre
            FROM presupuestos p
            LEFT JOIN cuentas c ON p.cuenta_id = c.id
            LEFT JOIN periodos_contables pe ON p.periodo_id = pe.id
            LEFT JOIN proyectos_presupuestarios py ON p.proyecto_id = py.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$detailId]);
        $presupuesto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$presupuesto) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Presupuesto no encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $tipoAccionLabel = ($presupuesto['tipo_accion'] === 'descentralizada' || !empty($presupuesto['proyecto_id']))
            ? 'proyecto'
            : 'centralizada';

        echo json_encode([
            'success' => true,
            'presupuesto' => [
                'id' => (int) $presupuesto['id'],
                'cuenta_id' => $presupuesto['cuenta_id'] ? (int) $presupuesto['cuenta_id'] : null,
                'cuenta_codigo' => $presupuesto['cuenta_codigo'],
                'cuenta_nombre' => $presupuesto['cuenta_nombre'],
                'tipo_accion' => $tipoAccionLabel,
                'proyecto_nombre' => $presupuesto['proyecto_nombre'],
                'proyecto_codigo' => $presupuesto['proyecto_codigo'],
                'periodo_nombre' => $presupuesto['periodo_nombre'],
                'descripcion' => $presupuesto['descripcion'],
                'saldo_disponible' => (float) $presupuesto['saldo_disponible'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        SELECT p.id,
               p.cuenta_id,
               p.tipo_accion,
               p.proyecto_id,
               c.codigo AS cuenta_codigo,
               c.nombre AS cuenta_nombre,
               py.nombre AS proyecto_nombre,
               py.codigo AS proyecto_codigo,
               pe.nombre AS periodo_nombre,
               p.observaciones AS descripcion,
               COALESCE(p.saldo_por_comprometer, GREATEST(0, COALESCE(p.credito_vigente, p.monto_total) - COALESCE(p.comprometido, 0))) AS saldo_disponible
        FROM presupuestos p
        LEFT JOIN cuentas c ON p.cuenta_id = c.id
        LEFT JOIN periodos_contables pe ON p.periodo_id = pe.id
        LEFT JOIN proyectos_presupuestarios py ON p.proyecto_id = py.id
        WHERE 1=1
    ";

    $params = [];

    // CLASIFICACIÓN PRESUPUESTARIA ESTRUCTURAL ONAPRE
    if ($tipoFiltro === 'centralizada') {
        $sql .= " AND p.tipo_accion = 'centralizada'";
    } elseif ($tipoFiltro === 'proyecto') {
        $sql .= " AND (p.tipo_accion = 'descentralizada' OR p.tipo_accion = 'proyecto' OR (p.proyecto_id IS NOT NULL AND p.proyecto_id > 0))";
    }

    if ($query !== '') {
        $sql .= " AND (
            c.codigo LIKE :term
            OR c.nombre LIKE :term
            OR py.nombre LIKE :term
            OR py.codigo LIKE :term
            OR pe.nombre LIKE :term
            OR p.observaciones LIKE :term
        )";
        $params[':term'] = '%' . $query . '%';
    }

    $sql .= " ORDER BY c.codigo ASC LIMIT " . (int)$limit;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rawPresupuestos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $presupuestos = [];
    foreach ($rawPresupuestos as $p) {
        $isProyecto = ($p['tipo_accion'] === 'descentralizada' || $p['tipo_accion'] === 'proyecto' || (!empty($p['proyecto_id']) && (int)$p['proyecto_id'] > 0));
        $tipoAccionLabel = $isProyecto ? 'proyecto' : 'centralizada';

        $proyectoInfo = !empty($p['proyecto_nombre'])
            ? "PROY [{$p['proyecto_codigo']}]: " . mb_substr((string)$p['proyecto_nombre'], 0, 60) . '...'
            : null;

        $presupuestos[] = [
            'id' => (int) $p['id'],
            'cuenta_id' => $p['cuenta_id'] ? (int) $p['cuenta_id'] : null,
            'cuenta_codigo' => $p['cuenta_codigo'],
            'cuenta_nombre' => $p['cuenta_nombre'],
            'tipo_accion' => $tipoAccionLabel,
            'proyecto_nombre' => $p['proyecto_nombre'],
            'proyecto_codigo' => $p['proyecto_codigo'],
            'proyecto_info' => $proyectoInfo,
            'periodo_nombre' => $p['periodo_nombre'],
            'descripcion' => $p['descripcion'],
            'saldo_disponible' => (float) $p['saldo_disponible'],
        ];
    }

    echo json_encode([
        'success' => true,
        'presupuestos' => $presupuestos,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar presupuestos: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
