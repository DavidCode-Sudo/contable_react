<?php
declare(strict_types=1);

/**
 * API RESTful: Búsqueda Autocomplete de Proveedores Registrados
 * Endpoint: GET /api/requisiciones/buscar_proveedores.php?q=VILLAMIZAR
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
$limit = isset($_GET['limit']) ? max(1, min((int) $_GET['limit'], 500)) : 100;

try {
    $conn = getConnection();

    if ($detailId > 0) {
        $stmt = $conn->prepare("
            SELECT id,
                   nombre,
                   ruc AS rif,
                   COALESCE(NULLIF(telefono, ''), '') AS telefono,
                   COALESCE(NULLIF(email, ''), '') AS email,
                   COALESCE(NULLIF(direccion, ''), '') AS direccion,
                   estado
            FROM proveedores
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$detailId]);
        $proveedor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proveedor) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Proveedor no encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success' => true,
            'proveedor' => [
                'id' => (int) $proveedor['id'],
                'nombre' => $proveedor['nombre'],
                'rif' => $proveedor['rif'],
                'telefono' => $proveedor['telefono'],
                'email' => $proveedor['email'],
                'direccion' => $proveedor['direccion'],
                'estado' => $proveedor['estado'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        SELECT id,
               nombre,
               ruc AS rif,
               COALESCE(NULLIF(telefono, ''), '') AS telefono,
               COALESCE(NULLIF(email, ''), '') AS email,
               COALESCE(NULLIF(direccion, ''), '') AS direccion
        FROM proveedores
        WHERE estado = 'activo'
    ";
    $params = [];

    if ($query !== '') {
        $sql .= " AND (
            nombre LIKE :term
            OR ruc LIKE :term
            OR telefono LIKE :term
            OR email LIKE :term
        )";
        $params[':term'] = '%' . $query . '%';
    }

    $sql .= " ORDER BY nombre ASC LIMIT " . (int)$limit;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rawProveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $proveedores = [];

    foreach ($rawProveedores as $prov) {
        $proveedores[] = [
            'id' => (int) $prov['id'],
            'nombre' => $prov['nombre'],
            'rif' => $prov['rif'],
            'telefono' => $prov['telefono'],
            'email' => $prov['email'],
            'direccion' => $prov['direccion'],
        ];
    }

    echo json_encode([
        'success' => true,
        'proveedores' => $proveedores,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar proveedores: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
