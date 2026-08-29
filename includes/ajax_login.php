<?php
// AJAX Login Validation Endpoint
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database/database.php';
require_once __DIR__ . '/../config/session_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit();
}

// Validate content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tipo de contenido inválido']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Datos JSON inválidos']);
    exit();
}

$correo = trim($input['correo'] ?? '');
$password = $input['password'] ?? '';

// Validate required fields
if (empty($correo) || empty($password)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Por favor, complete todos los campos requeridos.',
        'field_errors' => [
            'correo' => empty($correo) ? 'El correo es requerido' : null,
            'password' => empty($password) ? 'La contraseña es requerida' : null
        ]
    ]);
    exit();
}

// Validate email format
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Por favor, ingrese un correo electrónico válido.',
        'field_errors' => [
            'correo' => 'Formato de correo inválido'
        ]
    ]);
    exit();
}

try {
    $conn = getConnection();
    $sql = "SELECT id, nombre_completo, password FROM usuarios WHERE correo = :correo AND estado = 'activo'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':correo' => $correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($password, $usuario['password'])) {
        // Login successful - set session variables
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nombre'] = $usuario['nombre_completo'];

        require_once __DIR__ . '/../includes/funciones_contables.php';
        $_SESSION['usuario_roles'] = obtenerRolesUsuario($usuario['id']);
        $_SESSION['usuario_permisos'] = obtenerPermisosUsuario($usuario['id']);
        
        registrarLogin($usuario['id'], 'Inicio de sesión exitoso');

        // Clean any residual messages
        unset($_SESSION['flash_alert'], $_SESSION['mensaje'], $_SESSION['tipo_mensaje']);

        // Determine redirect destination based on permissions (all implemented modules)
        function destinoPorPermisos(array $permisos): string {
            $map = [
                // Dashboard (prioridad alta)
                'dashboard' => BASE_URL . 'includes/dashboard_principal.php',
                
                // Módulos principales (orden de prioridad)
                'requisiciones' => getModuloUrl('requisiciones') . 'gestion_requisiciones.php',
                'presupuestos' => getModuloUrl('presupuestos') . 'gestion_presupuestos.php',
                'compromisos' => getModuloUrl('presupuestos') . 'gestion_compromisos.php',
                'causados' => getModuloUrl('presupuestos') . 'gestion_causados.php',
                'ordenes_pago' => getModuloUrl('presupuestos') . 'gestion_ordenes_pago.php',
                'pagos' => getModuloUrl('presupuestos') . 'gestion_pagos.php',
                
                // Contabilidad
                'catalogo' => getModuloUrl('contabilidad') . 'catalogo_cuentas.php',
                'asientos' => getModuloUrl('contabilidad') . 'gestion_asientos.php',
                'libros' => getModuloUrl('contabilidad') . 'libros_contables.php',
                'cierre_contable' => getModuloUrl('contabilidad') . 'cierre_contable.php',
                'periodos_contables' => getModuloUrl('contabilidad') . 'periodos_contables.php',
                'conciliacion' => getModuloUrl('conciliacion') . 'gestion_conciliacion.php',
                
                // Inventario y Compras
                'inventario' => getModuloUrl('inventario') . 'gestion_inventario.php',
                'productos' => getModuloUrl('inventario') . 'gestion_productos.php',
                'movimientos' => getModuloUrl('inventario') . 'movimientos_inventario.php',
                
                // Facturación y Ventas
                'facturacion' => getModuloUrl('facturacion') . 'gestion_recibos_pago.php',
                'facturas' => getModuloUrl('facturacion') . 'gestion_recibos_pago.php',
                'ventas' => getModuloUrl('facturacion') . 'gestion_recibos_pago.php',
                
                // Clientes y Proveedores
                'clientes' => getModuloUrl('clientes') . 'gestion_clientes.php',
                'proveedores' => getModuloUrl('proveedores') . 'gestion_proveedores.php',
                'servicios' => getModuloUrl('servicios') . 'gestion_servicios.php',
                
                // Reportes
                'reportes' => getModuloUrl('reportes') . 'reportes_financieros.php',
                'estados_financieros' => getModuloUrl('reportes') . 'estados_financieros.php',
                'cxc' => getModuloUrl('reportes') . 'cuentas_por_cobrar.php',
                'cxp' => getModuloUrl('reportes') . 'cuentas_por_pagar.php',
                
                // Administración
                'usuarios' => getModuloUrl('usuarios') . 'gestion_usuarios.php',
                'roles' => getModuloUrl('usuarios') . 'gestion_roles_permisos.php',
                'auditoria' => getModuloUrl('auditoria') . 'auditoria_sistema.php',
            ];

            // Orden de prioridad para redirección
            $prioridades = [
                'dashboard',
                'requisiciones', 
                'presupuestos',
                'inventario',
                'facturacion',
                'contabilidad',
                'reportes',
                'usuarios'
            ];

            // 1. Buscar dashboard primero (si tiene permisos)
            foreach ($permisos as $p) {
                if (($p['modulo'] ?? '') === 'dashboard' && ($p['accion'] ?? '') === 'ver') {
                    return $map['dashboard'];
                }
            }

            // 2. Buscar por orden de prioridad
            foreach ($prioridades as $modulo_prioritario) {
                foreach ($permisos as $p) {
                    $modulo = $p['modulo'] ?? '';
                    $accion = $p['accion'] ?? '';
                    
                    // Buscar módulos que coincidan con la prioridad y tengan permiso 'ver'
                    if ($accion === 'ver' && 
                        ($modulo === $modulo_prioritario || 
                         ($modulo_prioritario === 'contabilidad' && in_array($modulo, ['catalogo', 'asientos', 'libros', 'cierre_contable', 'periodos_contables'])) ||
                         ($modulo_prioritario === 'facturacion' && in_array($modulo, ['facturacion', 'facturas', 'ventas'])) ||
                         ($modulo_prioritario === 'reportes' && in_array($modulo, ['reportes', 'estados_financieros', 'cxc', 'cxp'])))) {
                        
                        if (isset($map[$modulo])) {
                            return $map[$modulo];
                        }
                    }
                }
            }

            // 3. Buscar cualquier módulo con permiso 'ver'
            foreach ($permisos as $p) {
                if (($p['accion'] ?? '') === 'ver') {
                    $mod = $p['modulo'] ?? '';
                    if (isset($map[$mod])) { 
                        return $map[$mod]; 
                    }
                }
            }

            // 4. Fallback final
            return BASE_URL . 'includes/dashboard_principal.php';
        }

        $destino = destinoPorPermisos($_SESSION['usuario_permisos']);

        echo json_encode([
            'success' => true,
            'message' => 'Inicio de sesión exitoso',
            'redirect' => $destino
        ]);
    } else {
        // Invalid credentials
        echo json_encode([
            'success' => false,
            'message' => 'Credenciales incorrectas. Verifique su correo y contraseña.',
            'field_errors' => [
                'correo' => 'Credenciales inválidas',
                'password' => 'Credenciales inválidas'
            ]
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error del sistema. Por favor, inténtelo de nuevo más tarde.'
    ]);
}
?>
