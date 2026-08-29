<?php
declare(strict_types=1);

/**
 * API REST: Cierre de Sesión (Logout)
 * Sistema Contable Corporativo
 */

ob_start();
ini_set('display_errors', '0');

// Cargar configuraciones de sesión ANTES de session_start() para usar SESSION_NAME (CONTABLE_SESSION)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/session_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../includes/cors_middleware.php';

if (isset($_SESSION['usuario_id'])) {
    require_once __DIR__ . '/../../config/database/database.php';
    require_once __DIR__ . '/../../includes/funciones_contables.php';
    if (function_exists('registrarLogout')) {
        registrarLogout((int)$_SESSION['usuario_id'], 'Cierre de sesión vía API');
    }
}

// 1. Vaciar datos de sesión
$_SESSION = [];

// 2. Destruir explícitamente las cookies de sesión (CONTABLE_SESSION y PHPSESSID)
$sessionName = defined('SESSION_NAME') ? SESSION_NAME : session_name();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie($sessionName, '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    setcookie('PHPSESSID', '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
}

// 3. Destruir sesión en el servidor PHP
session_destroy();

ob_clean();
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Sesión cerrada exitosamente.'
], JSON_UNESCAPED_UNICODE);
