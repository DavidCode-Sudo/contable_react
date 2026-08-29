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

function servicioCategoriaIcono($categoria_nombre)
{
    if (!$categoria_nombre) {
        return '🔧';
    }

    $iconos = [
        'servicio' => '🔧',
        'mantenimiento' => '🛠️',
        'soporte' => '🖥️',
        'consultoría' => '👔',
        'consultoria' => '👔',
        'limpieza' => '🧽',
        'seguridad' => '🛡️',
        'transporte' => '🚚',
        'salud' => '🏥',
        'educación' => '🎓',
        'educacion' => '🎓',
    ];

    $nombreLower = strtolower($categoria_nombre);
    foreach ($iconos as $palabra => $icono) {
        if (strpos($nombreLower, $palabra) !== false) {
            return $icono;
        }
    }

    return '🔧';
}

try {
    $conn = getConnection();
    $source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

    $busqueda = trim($source['q'] ?? '');
    $limite = (int) ($source['limite'] ?? 10);

    if (mb_strlen($busqueda) < 2) {
        echo json_encode(['success' => true, 'servicios' => []]);
        exit;
    }

    $limite = max(1, min(50, $limite));

    $servicios = [];

    try {
        $sql = "SELECT id, codigo, nombre, descripcion, precio, impuesto_porcentaje, categoria_ruta AS categoria_nombre
                FROM vista_servicios_con_categoria
                WHERE estado = 'activo'
                  AND (codigo LIKE :busqueda OR nombre LIKE :busqueda OR descripcion LIKE :busqueda)
                ORDER BY categoria_codigo ASC, nombre ASC
                LIMIT :limite";
        $stmt = $conn->prepare($sql);
        $like = '%' . $busqueda . '%';
        $stmt->bindValue(':busqueda', $like);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $sql = "SELECT id, codigo, nombre, descripcion, precio, impuesto_porcentaje
                FROM servicios
                WHERE estado = 'activo'
                  AND (codigo LIKE :busqueda OR nombre LIKE :busqueda OR descripcion LIKE :busqueda)
                ORDER BY nombre ASC
                LIMIT :limite";
        $stmt = $conn->prepare($sql);
        $like = '%' . $busqueda . '%';
        $stmt->bindValue(':busqueda', $like);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($servicios as &$servicio) {
            if (!isset($servicio['categoria_nombre'])) {
                $servicio['categoria_nombre'] = null;
            }
        }
    }

    $serviciosFormateados = [];
    foreach ($servicios as $servicio) {
        $serviciosFormateados[] = [
            'id' => (int) $servicio['id'],
            'codigo' => $servicio['codigo'] ?? null,
            'nombre' => $servicio['nombre'],
            'descripcion' => $servicio['descripcion'] ?? null,
            'precio' => isset($servicio['precio']) ? (float) $servicio['precio'] : 0,
            'impuesto_porcentaje' => isset($servicio['impuesto_porcentaje'])
                ? (float) $servicio['impuesto_porcentaje']
                : null,
            'categoria_nombre' => $servicio['categoria_nombre'] ?? null,
            'categoria_icono' => servicioCategoriaIcono($servicio['categoria_nombre'] ?? ''),
        ];
    }

    echo json_encode(['success' => true, 'servicios' => $serviciosFormateados]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error en búsqueda',
        'detail' => DEBUG_MODE ? $e->getMessage() : null,
    ]);
}

