<?php
// --- LÓGICA PHP SEGURA Y SIN CAMBIOS ---
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database/database.php';
require_once __DIR__ . '/seguridad_sql.php';
require_once __DIR__ . '/monitor_seguridad.php';
require_once __DIR__ . '/../config/session_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Helper: determinar destino por permisos en sesión
function destinoPorPermisos(array $permisos): string {
    // Mapa de módulo -> ruta por defecto (todos los módulos implementados)
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
        
        // RRHH y Nóminas
        'rrhh' => getModuloUrl('rrhh') . 'gestion_empleados.php',
        'nominas' => getModuloUrl('nominas') . 'gestion_nominas.php',
        
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

if (isset($_SESSION['usuario_id']) && isset($_SESSION['usuario_permisos'])) {
    // Limpiar posibles mensajes residuales
    unset($_SESSION['flash_alert'], $_SESSION['mensaje'], $_SESSION['tipo_mensaje']);
    $destino = destinoPorPermisos($_SESSION['usuario_permisos']);
    header('Location: ' . $destino);
    exit();
}

$error = '';
$mensaje = '';

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'sesion_invalida': $error = 'Su sesión ha expirado o es inválida. Inicie sesión de nuevo.'; break;
        case 'error_sistema': $error = 'Error del sistema. Contacte al administrador.'; break;
        default: $error = 'Ha ocurrido un error desconocido.';
    }
}

if (isset($_GET['mensaje']) && $_GET['mensaje'] === 'sesion_cerrada') {
    $mensaje = 'Sesión cerrada exitosamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo']);
    $password = $_POST['password'];

    if (empty($correo) || empty($password)) {
        $error = 'Por favor, complete todos los campos requeridos.';
    } else {
        $conn = getConnection();
        $sql = "SELECT id, nombre_completo, password FROM usuarios WHERE correo = :correo AND estado = 'activo'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($password, $usuario['password'])) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nombre'] = $usuario['nombre_completo'];

            require_once __DIR__ . '/../includes/funciones_contables.php';
            $_SESSION['usuario_roles'] = obtenerRolesUsuario($usuario['id']);
            $_SESSION['usuario_permisos'] = obtenerPermisosUsuario($usuario['id']);
            
            registrarLogin($usuario['id'], 'Inicio de sesión exitoso');

            // Limpiar posibles mensajes residuales
            unset($_SESSION['flash_alert'], $_SESSION['mensaje'], $_SESSION['tipo_mensaje']);

            // Redirigir al módulo correspondiente según permisos (sin mostrar alertas)
            $destino = destinoPorPermisos($_SESSION['usuario_permisos']);
            header('Location: ' . $destino);
            exit();
        } else {
            $error = 'Credenciales incorrectas. Verifique y vuelva a intentarlo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema Contable - Acceso Corporativo</title>
    
<!-- Fuentes locales -->
<link href="<?php echo BASE_URL; ?>assets/fonts/google/fonts.css" rel="stylesheet">
<link href="<?php echo BASE_URL; ?>assets/css/fontawesome/all.min.css" rel="stylesheet">

<style>
    :root {
        /* Paleta de colores Profesional (Azul y Negro) */
        --primary-color: #0052cc; /* Un azul corporativo más fuerte */
        --primary-hover: #0041a3;
        --accent-color: #0065ff;
        
        /* Fondos y superficies */
        --bg-primary: #0b0f19; /* Fondo principal casi negro con un toque azul */
        --bg-secondary: #121826; /* Panel de login */
        --surface-elevated: #1a2233; /* Inputs y otros elementos */
        --surface-border: #2a3449;
        --surface-border-focus: #0065ff;

        /* Textos */
        --text-primary: #f0f4ff; /* Blanco suave para reducir fatiga visual */
        --text-secondary: #a0aec0; /* Gris azulado para textos secundarios */
        --text-muted: #718096;
        
        /* Estados */
        --success-color: #38a169;
        --success-bg: rgba(56, 161, 105, 0.1);
        --error-color: #e53e3e;
        --error-bg: rgba(229, 62, 62, 0.1);
        
        /* Tipografía */
        --font-primary: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
        --font-mono: 'JetBrains Mono', 'Fira Code', monospace;
        
        /* Espaciado y bordes */
        --radius-md: 8px;
        --radius-lg: 12px;
        
        /* Sombras sutiles */
        --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.25);
        --shadow-lg: 0 10px 30px rgba(0, 0, 0, 0.4);
        
        /* Transiciones rápidas y suaves */
        --transition-fast: 0.2s ease-out;
        --transition-normal: 0.3s ease-out;
    }

    /* --- Reset y Base --- */
    * { box-sizing: border-box; }
    
    html {
         font-family: var(--font-primary);
         font-size: 16px;
    }

    body {
        height: 100vh;
        margin: 0;
        font-family: inherit;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: var(--bg-primary);
        background-image: radial-gradient(circle at top, rgba(0, 82, 204, 0.15) 0%, transparent 40%);
        overflow: hidden;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    /* --- Panel de Acceso --- */
    .login-panel {
        position: relative;
        z-index: 10;
        width: 100%;
        max-width: 440px; /* Reducido para una apariencia más compacta */
        background-color: var(--bg-secondary);
        border: 1px solid var(--surface-border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        padding: 3rem 2.5rem;
        animation: fadeIn 0.8s var(--transition-normal) forwards;
        opacity: 0;
        transform: translateY(20px);
    }

    @keyframes fadeIn {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-6px); }
        20%, 40%, 60%, 80% { transform: translateX(6px); }
    }

    .login-panel.shake {
        animation: shake 0.5s ease-in-out;
    }

    /* --- Encabezado --- */
    .form-header {
        text-align: center;
        margin-bottom: 2.5rem;
    }

    .brand-logo {
        width: 60px; /* Tamaño más discreto */
        height: 60px;
        margin: 0 auto 1.5rem;
        background-color: var(--primary-color);
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(0, 82, 204, 0.3);
    }

    .brand-logo i {
        font-size: 2rem;
        color: white;
    }

    .form-header h1 {
        font-size: 2rem;
        font-weight: 600;
        margin: 0 0 0.5rem 0;
        color: var(--text-primary);
        letter-spacing: -0.5px;
    }

    .form-header p {
        color: var(--text-secondary);
        font-size: 1rem;
        font-weight: 400;
        margin: 0;
    }

    /* --- Alertas de Estado --- */
    .alert { 
        display: flex; 
        align-items: center; 
        padding: 0.9rem 1rem; 
        margin-bottom: 1.5rem; 
        border-radius: var(--radius-md); 
        font-size: 0.9rem; 
        font-weight: 500;
        border: 1px solid transparent;
        animation: slideIn 0.4s ease-out;
    }
    
    .alert.error {
        background-color: var(--error-bg); 
        color: var(--error-color); 
        border-color: var(--error-color);
    }
    
    .alert.success {
        background-color: var(--success-bg); 
        color: var(--success-color); 
        border-color: var(--success-color);
    }
    
    @keyframes slideIn { 
        from { opacity: 0; transform: translateY(-10px); } 
        to { opacity: 1; transform: translateY(0); }
    }
    
    .alert i { 
        margin-right: 0.75rem;
        font-size: 1rem;
    }

    /* --- Campos del Formulario --- */
    .form-group {
        position: relative;
        margin-bottom: 1.75rem;
    }

    .form-input {
        width: 100%;
        height: 52px;
        background-color: var(--surface-elevated);
        border: 1px solid var(--surface-border);
        border-radius: var(--radius-md);
        padding: 1.2rem 1rem 0.5rem; /* espacio superior para label flotante */
        color: var(--text-primary);
        font-size: 0.95rem;
        font-weight: 400;
        font-family: inherit;
        transition: all var(--transition-fast);
        outline: none;
    }

    .form-input::placeholder { /* Se usará label flotante en su lugar */
        color: transparent;
    }

    .form-input:focus {
        border-color: var(--surface-border-focus);
        box-shadow: 0 0 0 3px rgba(0, 101, 255, 0.2);
    }

    .form-label {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        pointer-events: none;
        font-weight: 400;
        font-size: 0.95rem;
        transition: all var(--transition-normal);
        background-color: transparent;
        z-index: 1; /* asegurar que el label quede por encima */
        padding: 0 4px;
    }

    .form-input:focus + .form-label,
    .form-input:not(:placeholder-shown) + .form-label {
        top: -0.55rem; /* que se asiente sobre el borde superior */
        left: 0.75rem; /* separada del borde izquierdo */
        transform: none; /* evitar el translateY */
        font-size: 0.75rem;
        color: var(--accent-color);
        background-color: var(--surface-elevated); /* igual que el input, evita el cuadro negro */
        padding: 0 0.35rem; /* pequeña cápsula */
        border-radius: 4px;
    }

    .form-group.error .form-input {
        border-color: var(--error-color);
    }
    
    .form-group.error .form-input:focus {
         box-shadow: 0 0 0 3px rgba(229, 62, 62, 0.2);
    }

    .form-group.error .form-label {
        color: var(--error-color);
    }
    
    .toggle-password { 
        position: absolute; 
        right: 1rem; 
        top: 50%; 
        transform: translateY(-50%); 
        color: var(--text-muted); 
        cursor: pointer; 
        transition: color var(--transition-fast);
    }
    
    .toggle-password:hover { 
        color: var(--text-primary); 
    }

    /* --- Opciones y Botones --- */
    .form-options { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        margin-bottom: 1.75rem; 
        font-size: 0.875rem; 
    }
    
    .checkbox-group { 
        display: flex; 
        align-items: center; 
        cursor: pointer; 
        user-select: none; 
    }
            
    .checkbox-input { 
        opacity: 0; 
        width: 0; 
        height: 0; 
    }
    
    .checkbox-custom { 
        width: 16px; 
        height: 16px; 
        border: 1px solid var(--surface-border); 
        border-radius: 4px; 
        display: inline-block; 
        position: relative; 
        transition: all var(--transition-fast); 
        margin-right: 0.5rem; 
    }
    
    .checkbox-custom::after { 
        content: ''; 
        position: absolute; 
        top: 50%; 
        left: 50%; 
        width: 5px; 
        height: 9px; 
        border: solid var(--bg-secondary); 
        border-width: 0 2px 2px 0; 
        transform: translate(-50%, -60%) rotate(45deg); 
        opacity: 0;
    }
    
    .checkbox-input:checked + .checkbox-custom { 
        background-color: var(--accent-color); 
        border-color: var(--accent-color); 
    }
    
    .checkbox-input:checked + .checkbox-custom::after { 
        opacity: 1; 
    }
    
    .checkbox-label { 
        color: var(--text-secondary); 
    }
    
    .forgot-link { 
        color: var(--accent-color); 
        text-decoration: none; 
        font-weight: 500;
        transition: color var(--transition-fast);
    }
    
    .forgot-link:hover { 
        color: var(--primary-hover);
        text-decoration: underline;
    }
    
    .btn {
        width: 100%;
        height: 50px;
        border: none;
        border-radius: var(--radius-md);
        font-size: 1rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: all var(--transition-fast);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        text-transform: none;
        letter-spacing: 0.5px;
    }

    .btn-primary {
        background-color: var(--primary-color);
        color: white;
        box-shadow: 0 2px 8px rgba(0, 82, 204, 0.25);
    }

    .btn-primary:hover:not(:disabled) {
        background-color: var(--primary-hover);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 82, 204, 0.3);
    }
    
    .btn:disabled { 
        background: var(--surface-elevated); 
        color: var(--text-muted);
        cursor: not-allowed; 
    }
    
    .btn-spinner { 
        width: 20px; 
        height: 20px; 
        border: 2px solid rgba(255,255,255,0.3); 
        border-top-color: #fff; 
        border-radius: 50%; 
        animation: spin 0.8s linear infinite; 
    }
    
    @keyframes spin { 
        to { transform: rotate(360deg); } 
    }
    
    /* --- Footer --- */
    .login-footer {
        margin-top: 1.75rem;
        padding-top: 1.25rem;
        border-top: 1px solid var(--surface-border);
        text-align: center;
    }

    .footer-text {
        color: var(--text-muted);
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .footer-text i {
        color: var(--success-color);
    }

    /* --- Responsive Design --- */
    @media (max-width: 480px) {
        body {
            align-items: flex-start;
            padding-top: 1rem;
        }

        .login-panel {
            padding: 2.5rem 2rem;
            width: calc(100% - 2rem);
            margin: 0 auto;
        }

        .form-header h1 {
            font-size: 1.75rem;
        }

        .form-header p {
            font-size: 0.9rem;
        }

        .btn {
            height: 48px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation: none !important;
            transition: none !important;
        }
    }
</style>
</head>
<body>
<div class="login-panel" id="loginPanel">
    <header class="form-header">
        <div class="brand-logo">
            <i class="fas fa-calculator"></i>
        </div>
        <h1>Sistema Contable</h1>
        <p>Acceso Corporativo Seguro</p>
    </header>

<?php if (!empty($error)): ?>
    <div class="alert error" role="alert">
        <i class="fas fa-exclamation-triangle"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($mensaje)): ?>
    <div class="alert success" role="alert">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($mensaje); ?></span>
    </div>
<?php endif; ?>

<div id="ajaxErrorContainer"></div>
<form method="POST" id="loginForm" novalidate autocomplete="off">
    <div class="form-group" id="group-correo">
        <input type="email" class="form-input" id="correo" name="correo" required placeholder=" " value="<?php echo isset($_POST['correo']) ? htmlspecialchars($_POST['correo']) : ''; ?>">
        <label for="correo" class="form-label">Correo Electrónico</label>
    </div>
    
    <div class="form-group" id="group-password">
        <input type="password" class="form-input" id="password" name="password" required placeholder=" ">
        <label for="password" class="form-label">Contraseña</label>
        <span class="toggle-password" id="togglePassword" title="Mostrar/Ocultar contraseña">
            <i class="fas fa-eye"></i>
        </span>
    </div>
    
    <button type="submit" class="btn btn-primary" id="btnLogin" disabled>
        <i class="fas fa-sign-in-alt"></i>
        <span>Acceder</span>
    </button>
    
    <div class="login-footer">
        <p class="footer-text">
            <i class="fas fa-shield-alt"></i>
            <span>Conexión segura y cifrada</span>
        </p>
    </div>
</form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- Lógica para mostrar/ocultar contraseña ---
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            const icon = togglePassword.querySelector('i');
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });
    }

    // --- Validación de formulario y estado de carga ---
    const loginForm = document.getElementById('loginForm');
    const btnLogin = document.getElementById('btnLogin');
    const emailGroup = document.getElementById('group-correo');
    const passGroup = document.getElementById('group-password');
    const emailInput = document.getElementById('correo');
    const loginPanel = document.getElementById('loginPanel');

    // Comprobación inicial para mantener label flotante si hay un valor precargado (p. ej. por un error de POST)
    document.querySelectorAll('.form-input').forEach(input => {
        if(input.value) {
           input.setAttribute('placeholder', ' ');
        }
    });

    // --- Habilitar/Deshabilitar botón según datos ---
    const updateButtonState = () => {
        const emailVal = emailInput.value.trim();
        const passVal = passwordInput.value.trim();
        const hasData = emailVal !== '' && passVal !== '';
        btnLogin.disabled = !hasData;
    };

    emailInput.addEventListener('input', updateButtonState);
    passwordInput.addEventListener('input', updateButtonState);
    updateButtonState();

    // --- Función para mostrar mensajes de error ---
    const showErrorMessage = (message, fieldErrors = {}) => {
        const errorContainer = document.getElementById('ajaxErrorContainer');
        errorContainer.innerHTML = `
            <div class="alert error" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <span>${message}</span>
            </div>
        `;
        
        // Limpiar errores de campos previos
        emailGroup.classList.remove('error');
        passGroup.classList.remove('error');
        
        // Aplicar errores específicos de campos
        if (fieldErrors.correo) {
            emailGroup.classList.add('error');
        }
        if (fieldErrors.password) {
            passGroup.classList.add('error');
        }
        
        // Scroll suave al mensaje de error
        errorContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
    
    // --- Función para limpiar mensajes de error ---
    const clearErrorMessage = () => {
        const errorContainer = document.getElementById('ajaxErrorContainer');
        errorContainer.innerHTML = '';
        emailGroup.classList.remove('error');
        passGroup.classList.remove('error');
    };
    
    // --- Función para realizar login via AJAX ---
    const performAjaxLogin = async (correo, password) => {
        try {
            const response = await fetch('ajax_login.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    correo: correo,
                    password: password
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Login exitoso - redirigir
                window.location.href = data.redirect;
            } else {
                // Mostrar error sin efectos visuales
                showErrorMessage(data.message, data.field_errors || {});
                
                // Restaurar botón
                btnLogin.disabled = false;
                btnLogin.innerHTML = `<i class="fas fa-sign-in-alt"></i><span>Acceder</span>`;
            }
        } catch (error) {showErrorMessage('Error de conexión. Por favor, inténtelo de nuevo.');
            
            // Restaurar botón
            btnLogin.disabled = false;
            btnLogin.innerHTML = `<i class="fas fa-sign-in-alt"></i><span>Acceder</span>`;
        }
    };

    if (loginForm && btnLogin) {
        loginForm.addEventListener('submit', e => {
            e.preventDefault(); // Siempre prevenir envío normal
            
            // Limpiar errores previos
            clearErrorMessage();
            
            let isValid = true;
            const emailVal = emailInput.value.trim();
            const passVal = passwordInput.value.trim();
            
            // Validación de email
            if (emailVal === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                emailGroup.classList.add('error');
                isValid = false;
            }
            
            // Validación de contraseña
            if (passVal === '') {
                passGroup.classList.add('error');
                isValid = false;
            }

            if (!isValid) {
                showErrorMessage('Por favor, complete todos los campos correctamente.');
                return;
            }
            
            // Mostrar spinner y deshabilitar botón
            btnLogin.disabled = true;
            btnLogin.innerHTML = `<span class="btn-spinner"></span><span>Verificando...</span>`;
            
            // Realizar login AJAX
            performAjaxLogin(emailVal, passVal);
        });
    }
    
    // --- Limpiar errores al escribir ---
    emailInput.addEventListener('input', () => {
        if (emailGroup.classList.contains('error')) {
            clearErrorMessage();
        }
        updateButtonState();
    });
    
    passwordInput.addEventListener('input', () => {
        if (passGroup.classList.contains('error')) {
            clearErrorMessage();
        }
        updateButtonState();
    });
});
</script>
</body>
</html>