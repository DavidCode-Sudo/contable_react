<?php
declare(strict_types=1);

/**
 * API REST: Datos del Usuario Autenticado
 * Sistema Contable Corporativo
 */

require_once __DIR__ . '/../../includes/cors_middleware.php';
require_once __DIR__ . '/../../includes/verificar_sesion_api.php';

header('Content-Type: application/json; charset=utf-8');

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$usuarioNombre = (string)($_SESSION['usuario_nombre'] ?? '');

http_response_code(200);
echo json_encode([
    'success' => true,
    'data' => [
        'id' => $usuarioId,
        'nombre' => $usuarioNombre,
        'roles' => $_SESSION['usuario_roles'] ?? [],
    ]
], JSON_UNESCAPED_UNICODE);
