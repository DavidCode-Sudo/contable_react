<?php
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../includes/verificar_sesion.php';
require_once __DIR__ . '/../includes/funciones_contables.php';

// Registrar auditoría de cierre de sesión
if (isset($_SESSION['usuario_id'])) {
    registrarLogout($_SESSION['usuario_id'], 'Cierre de sesión');
}

// Cerrar sesión
cerrarSesion();
?> 