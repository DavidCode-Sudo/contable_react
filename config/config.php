<?php
/**
 * Configuración principal del Sistema Contable
 * Este archivo contiene las constantes y configuraciones globales del sistema
 */

// Prevenir acceso directo
if (!defined('SISTEMA_CONTABLE')) {
    define('SISTEMA_CONTABLE', true);
}

// --- CONFIGURACIÓN DE ZONA HORARIA ---
// Configurar zona horaria de Venezuela (Caracas)
date_default_timezone_set('America/Caracas');

// --- CONFIGURACIÓN DE RUTAS ---
// URL base del sistema (ajustar según tu instalación)
define('BASE_URL', '/contable/');

// Rutas del sistema de archivos
define('ROOT_PATH', dirname(__DIR__) . '/');
define('INCLUDES_PATH', ROOT_PATH . 'includes/');
define('MODULOS_PATH', ROOT_PATH . 'modulos/');
define('ASSETS_PATH', ROOT_PATH . 'assets/');
define('LOGS_PATH', ROOT_PATH . 'logs/');

// Cargar archivo .env en la raíz del proyecto si existe
$envFile = ROOT_PATH . '.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// --- CONFIGURACIÓN DE BASE DE DATOS ---
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'sistema_contable');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// --- CONFIGURACIÓN DE SESIONES ---
define('SESSION_NAME', getenv('SESSION_NAME') ?: 'CONTABLE_SESSION');
define('SESSION_LIFETIME', 3600); // 1 hora en segundos
define('SESSION_SECURE', getenv('SESSION_SECURE') === 'true'); // Cambiar a true en producción con HTTPS
define('SESSION_HTTPONLY', true);
define('SESSION_SAMESITE', 'Strict');

// --- CONFIGURACIÓN DE SEGURIDAD ---
define('HASH_ALGORITHM', 'sha256');
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: 'tu_clave_secreta_aqui_cambiar_en_produccion');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutos

// --- CONFIGURACIÓN DE LOGS ---
define('LOG_ERRORS', true);
define('LOG_SECURITY', true);
define('LOG_LEVEL', 'INFO');

// --- CONFIGURACIÓN DE MONEDAS ---
define('MONEDA_PRINCIPAL', 'BS'); // Bolívar Soberano
define('MONEDA_SECUNDARIA', 'USD'); // Dólar Americano
define('MONEDA_PRINCIPAL_NOMBRE', 'Bolívar Soberano');
define('MONEDA_SECUNDARIA_NOMBRE', 'Dólar Americano');
define('CONVERSION_AUTOMATICA', true); // Conversión automática a moneda principal
// --- CONFIGURACIÓN DE LA APLICACIÓN ---
define('APP_NAME', 'Sistema Contable Corporativo');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'America/Caracas');
define('APP_LOCALE', 'es_GT');

// --- CONFIGURACIÓN DE MONEDA ---
define('DEFAULT_CURRENCY', 'GTQ');
define('CURRENCY_SYMBOL', 'Q');
define('DECIMAL_PLACES', 2);

// --- CONFIGURACIÓN DE ARCHIVOS ---
define('MAX_UPLOAD_SIZE', 5242880); // 5MB en bytes
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx']);

// --- CONFIGURACIÓN DE EMAIL (si se implementa) ---
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('SMTP_ENCRYPTION', 'tls');
define('FROM_EMAIL', 'noreply@sistemacontable.com');
define('FROM_NAME', 'Sistema Contable');

// --- CONFIGURACIÓN DE DESARROLLO ---
define('DEBUG_MODE', true); // Cambiar a false en producción
define('SHOW_ERRORS', true); // Cambiar a false en producción

// Configurar zona horaria
date_default_timezone_set(APP_TIMEZONE);

// Configurar locale para formateo de números y fechas
if (function_exists('setlocale')) {
    setlocale(LC_ALL, APP_LOCALE);
}

// --- FUNCIONES AUXILIARES ---

/**
 * Obtiene la URL completa de un módulo
 */
function getModuloUrl($modulo) {
    return BASE_URL . 'modulos/' . $modulo . '/';
}

/**
 * Obtiene la ruta completa de un archivo de módulo
 */
function getModuloPath($modulo) {
    return MODULOS_PATH . $modulo . '/';
}

/**
 * Obtiene la URL de un asset
 */
function getAssetUrl($asset) {
    return BASE_URL . 'assets/' . $asset;
}

/**
 * Verifica si estamos en modo debug
 */
function isDebugMode() {
    return defined('DEBUG_MODE') && DEBUG_MODE === true;
}

/**
 * Registra un mensaje en el log
 */
function logMessage($message, $level = 'INFO', $file = 'system.log') {
    if (!LOG_ERRORS) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    $logFile = LOGS_PATH . $file;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// --- CONFIGURACIÓN DE ERRORES ---
if (isDebugMode()) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}

// Configurar manejo de errores personalizado
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    $errorMsg = "Error: {$message} in {$file} on line {$line}";
    logMessage($errorMsg, 'ERROR', 'error.log');
    
    if (isDebugMode()) {
        echo "<div style='background: #ffebee; color: #c62828; padding: 10px; margin: 10px; border-left: 4px solid #c62828;'>";
        echo "<strong>Error:</strong> {$message}<br>";
        echo "<strong>File:</strong> {$file}<br>";
        echo "<strong>Line:</strong> {$line}";
        echo "</div>";
    }
    
    return true;
});

// Configurar manejo de excepciones no capturadas
set_exception_handler(function($exception) {
    $errorMsg = "Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine();
    logMessage($errorMsg, 'ERROR', 'error.log');
    
    if (isDebugMode()) {
        echo "<div style='background: #ffebee; color: #c62828; padding: 10px; margin: 10px; border-left: 4px solid #c62828;'>";
        echo "<strong>Uncaught Exception:</strong> " . $exception->getMessage() . "<br>";
        echo "<strong>File:</strong> " . $exception->getFile() . "<br>";
        echo "<strong>Line:</strong> " . $exception->getLine() . "<br>";
        echo "<strong>Stack Trace:</strong><pre>" . $exception->getTraceAsString() . "</pre>";
        echo "</div>";
    } else {
        echo "<h1>Error del Sistema</h1><p>Ha ocurrido un error interno. Por favor, contacte al administrador.</p>";
    }
});

// Log de inicio del sistema
logMessage("Sistema iniciado - Configuración cargada", 'INFO');
