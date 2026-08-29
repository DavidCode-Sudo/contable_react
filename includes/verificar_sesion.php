<?php
// Incluir configuraciones principales
require_once __DIR__ . '/../config/config.php';

// Incluir configuraciones de sesión ANTES de session_start()
require_once __DIR__ . '/../config/session_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Verificar si el usuario está autenticado
function verificarSesionActiva() {
    // Si no hay sesión iniciada, redirigir al login
    if (!isset($_SESSION['usuario_id']) || empty($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . 'includes/autenticacion.php');
        exit();
    }
    
    // Verificar que el usuario existe y está activo en la base de datos
    try {
        require_once __DIR__ . '/../config/database/database.php';
        $conn = getConnection();
        
        $sql = "SELECT id, nombre_completo, estado FROM usuarios WHERE id = :id AND estado = 'activo'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $_SESSION['usuario_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            // Usuario no existe o está inactivo, destruir sesión
            session_destroy();
            header('Location: ' . BASE_URL . 'includes/autenticacion.php?error=sesion_invalida');
            exit();
        }
        
        // Actualizar información de sesión
        $_SESSION['usuario_nombre'] = $usuario['nombre_completo'];
        
        // Cargar roles y permisos del usuario
        require_once __DIR__ . '/../includes/funciones_contables.php';
        $roles = obtenerRolesUsuario($_SESSION['usuario_id']);
        $permisos = obtenerPermisosUsuario($_SESSION['usuario_id']);
        
        // Verificar que el usuario tenga permisos válidos
        if (empty($roles) || empty($permisos)) {
            session_destroy();
            header('Location: ' . BASE_URL . 'includes/autenticacion.php?error=sin_permisos');
            exit();
        }
        
        $_SESSION['usuario_roles'] = $roles;
        $_SESSION['usuario_permisos'] = $permisos;
        
    } catch (Exception $e) {
        // Error de base de datos, redirigir al login
        session_destroy();
        header('Location: ' . BASE_URL . 'includes/autenticacion.php?error=error_sistema');
        exit();
    }
}

// Verificar permisos de administrador (mantener compatibilidad)
function verificarPermisosAdmin() {
    if (!esAdministrador()) {
        header('Location: ' . BASE_URL . 'includes/dashboard_principal.php?error=sin_permisos');
        exit();
    }
}

// Verificar permisos de contador o admin (mantener compatibilidad)
function verificarPermisosContador() {
    if (!esAdministrador() && !esContador()) {
        header('Location: ' . BASE_URL . 'includes/dashboard_principal.php?error=sin_permisos');
        exit();
    }
}

// Nueva función para verificar permisos específicos
function verificarPermiso($modulo, $accion) {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['usuario_permisos'])) {
        return false;
    }
    
    // Verificar directamente en la sesión (más eficiente que consultar BD)
    foreach ($_SESSION['usuario_permisos'] as $permiso) {
        if ($permiso['modulo'] === $modulo && $permiso['accion'] === $accion) {
            return true;
        }
    }
    
    return false;
}

// Función para verificar permisos y redirigir si no tiene
function verificarPermisoRedirigir($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        // Preparar mensaje profesional para mostrar en el destino
        require_once __DIR__ . '/../includes/funciones_contables.php';
        $_SESSION['flash_alert'] = [
            'tipo' => 'warning',
            'mensaje' => "Acceso restringido"
        ];
        // No usar llaves legacy para evitar duplicados en módulos

        // Determinar un destino al que sí tenga acceso (prioridad: dashboard, luego demás módulos con 'ver')
        $destino = null;
        if (verificarPermiso('dashboard', 'ver')) {
            $destino = BASE_URL . 'includes/dashboard_principal.php';
        }
        if (!$destino && isset($_SESSION['usuario_permisos'])) {
            // mapa de módulo -> ruta por defecto
            $map = [
                'catalogo' => getModuloUrl('contabilidad') . 'catalogo_cuentas.php',
                'asientos' => getModuloUrl('contabilidad') . 'gestion_asientos_contables.php',
                'libros' => getModuloUrl('contabilidad') . 'libros_contables.php',
                'cierre_contable' => getModuloUrl('contabilidad') . 'cierre_contable.php',
                'periodos_contables' => getModuloUrl('contabilidad') . 'periodos_contables.php',
                'facturacion' => getModuloUrl('facturacion') . 'gestion_facturacion.php',
                'facturas' => getModuloUrl('facturacion') . 'gestion_facturacion.php',
                'inventario' => getModuloUrl('inventario') . 'gestion_inventario.php',
                'clientes' => getModuloUrl('clientes') . 'gestion_clientes.php',
                'proveedores' => getModuloUrl('proveedores') . 'gestion_proveedores.php',
                'reportes' => getModuloUrl('reportes') . 'reportes_financieros.php',
                'estados_financieros' => getModuloUrl('reportes') . 'estados_financieros.php',
                'cxc' => getModuloUrl('reportes') . 'cuentas_por_cobrar.php',
                'cxp' => getModuloUrl('reportes') . 'cuentas_por_pagar.php',
                'auditoria' => getModuloUrl('auditoria') . 'auditoria_sistema.php',
                'usuarios' => getModuloUrl('usuarios') . 'gestion_usuarios.php',
                'roles' => getModuloUrl('usuarios') . 'gestion_roles_permisos.php',
                'dashboard' => BASE_URL . 'includes/dashboard_principal.php',
            ];
            foreach ($_SESSION['usuario_permisos'] as $perm) {
                if ($perm['accion'] === 'ver') {
                    if (isset($map[$perm['modulo']])) { $destino = $map[$perm['modulo']]; break; }
                }
            }
        }
        if (!$destino) {
            // Fallback al login si no tiene absolutamente nada
            $destino = BASE_URL . 'includes/autenticacion.php?error=sin_permisos';
        }
        header('Location: ' . $destino);
        exit();
    }
}

// Función para verificar si el usuario tiene al menos un permiso de un módulo
function tienePermisoModulo($modulo) {
    if (!isset($_SESSION['usuario_permisos'])) {
        return false;
    }
    
    foreach ($_SESSION['usuario_permisos'] as $permiso) {
        if ($permiso['modulo'] === $modulo) {
            return true;
        }
    }
    
    return false;
}

// Función para cerrar sesión
function cerrarSesion() {
    session_destroy();
    header('Location: ' . BASE_URL . 'includes/autenticacion.php?mensaje=sesion_cerrada');
    exit();
}

// Verificar sesión automáticamente en todas las páginas
verificarSesionActiva();
?>