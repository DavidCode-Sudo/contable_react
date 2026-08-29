<?php
/**
 * Middleware de Autenticación Ligero para API RESTful
 * Sistema Contable Corporativo (Seguridad OWASP)
 */

ini_set('display_errors', '0');

if (file_exists(__DIR__ . '/../config/session_config.php')) {
    require_once __DIR__ . '/../config/session_config.php';
    if (class_exists('SessionManager')) {
        try {
            SessionManager::start();
        } catch (Throwable $e) {}
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 1. Verificar existencia de la sesión en RAM
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['usuario_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sesión no válida o expirada. Por favor inicie sesión nuevamente.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. Defensa contra Secuestro de Sesión (Session Hijacking): Enlace de IP y User-Agent
$currentIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

if (
    (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== $currentIp) ||
    (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $currentUserAgent)
) {
    // Destrucción inmediata de sesión comprometida
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sesión invalidada por detección de cambio anómalo en la red o navegador.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
