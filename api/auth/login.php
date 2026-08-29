<?php
declare(strict_types=1);

/**
 * API REST: Autenticación de Usuarios (Login Seguro OWASP)
 * Sistema Contable Corporativo
 */

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/session_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../includes/cors_middleware.php';
require_once __DIR__ . '/../../config/database/database.php';
require_once __DIR__ . '/../../includes/funciones_contables.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método HTTP no permitido. Se requiere POST.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$rawInput = file_get_contents('php://input');
$input = json_decode((string)$rawInput, true);

if (!is_array($input)) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cuerpo de la petición inválido. Se espera formato JSON.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$correo = trim((string)($input['correo'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($correo === '' || $password === '') {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Por favor, ingrese su correo y contraseña.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $conn = getConnection();
    $sql = "SELECT id, nombre_completo, correo, password, estado FROM usuarios WHERE correo = :correo LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // 1. Trazabilidad de Intentos Fallidos y Bloqueo contra Fuerza Bruta
    if (!$usuario || !is_array($usuario) || !password_verify($password, (string)$usuario['password'])) {
        if (function_exists('registrarAuditoria')) {
            $detalles = 'Intento de inicio de sesión fallido para el correo: ' . $correo;
            $userIdToLog = ($usuario && is_array($usuario)) ? (int)$usuario['id'] : null;
            registrarAuditoria('login_fallido', 'autenticacion', $detalles, 'usuarios', $userIdToLog);
        }

        ob_clean();
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Credenciales incorrectas. Verifique su correo y contraseña.',
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (($usuario['estado'] ?? '') !== 'activo') {
        if (function_exists('registrarAuditoria')) {
            registrarAuditoria('login_bloqueado', 'autenticacion', 'Intento de acceso a cuenta inactiva', 'usuarios', (int)$usuario['id']);
        }
        ob_clean();
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Su cuenta se encuentra inactiva. Contacte al administrador del sistema.',
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 2. Prevención contra Fijación de Sesión (Session Fixation)
    session_regenerate_id(true);

    $usuarioId = (int)$usuario['id'];
    $_SESSION['usuario_id'] = $usuarioId;
    $_SESSION['usuario_nombre'] = (string)$usuario['nombre_completo'];

    // 3. Enlace de Seguridad de Red (Session Hijacking Defense)
    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    // 4. Control de Sesiones Concurrentes: Invalida sesiones activas anteriores en la tabla sesiones_usuarios
    $sqlInactivar = "UPDATE sesiones_usuarios SET fecha_fin = NOW(), estado = 'cerrada' WHERE usuario_id = :usuario_id AND estado = 'activa'";
    $stmtInactivar = $conn->prepare($sqlInactivar);
    $stmtInactivar->execute([':usuario_id' => $usuarioId]);

    // 4.b Anti-DoS Account Lockout: Limpia el expediente de intentos fallidos de Sudo acumulados al iniciar sesión legítimamente
    $sqlCleanSudo = "DELETE FROM intentos_seguridad WHERE usuario_id = :usuario_id";
    $stmtCleanSudo = $conn->prepare($sqlCleanSudo);
    $stmtCleanSudo->execute([':usuario_id' => $usuarioId]);

    if (function_exists('registrarInicioSesion')) {
        registrarInicioSesion($usuarioId);
    }

    if (function_exists('obtenerRolesUsuario')) {
        $_SESSION['usuario_roles'] = obtenerRolesUsuario($usuarioId);
    }
    if (function_exists('obtenerPermisosUsuario')) {
        $_SESSION['usuario_permisos'] = obtenerPermisosUsuario($usuarioId);
    }

    // 5. Trazabilidad Auditoría Fiscal: Registro de Login Exitoso
    if (function_exists('registrarLogin')) {
        registrarLogin($usuarioId, 'Inicio de sesión exitoso con control de concurrencia y regeneración de ID');
    }

    unset($_SESSION['flash_alert'], $_SESSION['mensaje'], $_SESSION['tipo_mensaje']);

    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Inicio de sesión exitoso.',
        'data' => [
            'id' => $usuarioId,
            'nombre' => (string)$usuario['nombre_completo'],
            'correo' => (string)$usuario['correo'],
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (function_exists('logMessage')) {
        logMessage("Error interno en login: " . $e->getMessage(), 'ERROR', 'database.log');
    }
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
