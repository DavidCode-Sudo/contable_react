<?php
require_once __DIR__ . '/../../includes/verificar_sesion.php';
require_once __DIR__ . '/../../config/database/database.php';

header('Content-Type: application/json');

$allowedOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    exit;
}

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

function getCategoriaIcono($categoria_nombre)
{
    if (!$categoria_nombre) {
        return '📦';
    }

    $iconos = [
        'oficina' => '📝',
        'papelería' => '📝',
        'tecnología' => '💻',
        'informática' => '💻',
        'limpieza' => '🧽',
        'mantenimiento' => '🧽',
        'alimentos' => '☕',
        'bebidas' => '☕',
        'mobiliario' => '🪑',
        'equipos' => '🪑',
        'servicios' => '🔧',
        'seguridad' => '🦺',
        'industrial' => '🦺',
        'construcción' => '🔨',
        'materiales' => '🔨',
    ];

    $nombreLower = strtolower($categoria_nombre);
    foreach ($iconos as $palabra => $icono) {
        if (strpos($nombreLower, $palabra) !== false) {
            return $icono;
        }
    }

    return '📦';
}

try {
    $conn = getConnection();

    $source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

    $busqueda = trim($source['q'] ?? '');
    $limite = (int) ($source['limite'] ?? 10);

    if (mb_strlen($busqueda) < 2) {
        echo json_encode(['success' => true, 'productos' => []]);
        exit;
    }

    $limite = max(1, min(50, $limite));

    $sql = "SELECT p.id, p.codigo, p.nombre, p.precio, p.descripcion,
                   c.nombre AS categoria_nombre
            FROM productos p
            LEFT JOIN categorias_productos c ON p.categoria_id = c.id
            WHERE p.estado = 'activo'
              AND (p.codigo LIKE :busqueda OR p.nombre LIKE :busqueda OR p.descripcion LIKE :busqueda)
            ORDER BY c.nombre ASC, p.nombre ASC
            LIMIT :limite";

    $stmt = $conn->prepare($sql);
    $like = '%' . $busqueda . '%';
    $stmt->bindValue(':busqueda', $like);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->execute();

    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $productosFormateados = [];

    foreach ($productos as $producto) {
        $productosFormateados[] = [
            'id' => (int) $producto['id'],
            'codigo' => $producto['codigo'],
            'nombre' => $producto['nombre'],
            'precio' => (float) $producto['precio'],
            'descripcion' => $producto['descripcion'],
            'categoria_nombre' => $producto['categoria_nombre'],
            'categoria_icono' => getCategoriaIcono($producto['categoria_nombre']),
        ];
    }

    echo json_encode(['success' => true, 'productos' => $productosFormateados]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en búsqueda',
        'detail' => DEBUG_MODE ? $e->getMessage() : null,
    ]);
}

