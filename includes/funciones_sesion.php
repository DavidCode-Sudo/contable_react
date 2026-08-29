<?php
/**
 * Funciones de sesión faltantes
 */

if (!function_exists("getCurrentUser")) {
    function getCurrentUser() {
        if (!isset($_SESSION["usuario_id"])) {
            return null;
        }
        
        try {
            $conn = getConnection();
            $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
            $stmt->execute([$_SESSION["usuario_id"]]);
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists("verificarPermiso")) {
    function verificarPermiso($modulo, $accion) {
        if (!isset($_SESSION["usuario_id"])) {
            return false;
        }
        
        try {
            $conn = getConnection();
            
            // Obtener rol del usuario
            $stmt = $conn->prepare("SELECT rol FROM usuarios WHERE id = ?");
            $stmt->execute([$_SESSION["usuario_id"]]);
            $usuario = $stmt->fetch();
            
            if (!$usuario) {
                return false;
            }
            
            // Si es administrador, tiene todos los permisos
            if ($usuario["rol"] === "administrador") {
                return true;
            }
            
            // Verificar permiso específico
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM roles_permisos rp 
                INNER JOIN permisos p ON rp.permiso_id = p.id 
                WHERE rp.rol = ? AND p.modulo = ? AND p.accion = ?
            ");
            $stmt->execute([$usuario["rol"], $modulo, $accion]);
            $result = $stmt->fetch();
            
            return $result["count"] > 0;
            
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists("verificarPermisoRedirigir")) {
    function verificarPermisoRedirigir($modulo, $accion) {
        if (!verificarPermiso($modulo, $accion)) {
            header("Location: ../error_permisos.php");
            exit;
        }
    }
}
?>