<?php
/**
 * Middleware de CORS y Preflight para API RESTful
 * Sistema Contable Corporativo
 */

// Desactivar renderizado de errores HTML en peticiones de API
ini_set('display_errors', '0');

// Cabeceras estrictas Anti-Caché (Prevención de navegación atrás bfcache post-logout)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Whitelist estricta de orígenes permitidos (Cargada desde .env o fallback por defecto)
$corsEnv = getenv('CORS_ALLOWED_ORIGINS');
if ($corsEnv !== false && trim($corsEnv) !== '') {
    $allowedOrigins = array_map('trim', explode(',', $corsEnv));
} else {
    $allowedOrigins = [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
    ];
}

$httpOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (!empty($httpOrigin) && in_array($httpOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $httpOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');
    header('Vary: Origin');
}

// Interceptar peticiones Preflight OPTIONS del navegador inmediatamente
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
