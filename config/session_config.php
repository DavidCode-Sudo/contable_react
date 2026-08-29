<?php
/**
 * Configuración de sesiones del Sistema Contable
 * Manejo seguro de sesiones con configuraciones optimizadas OWASP
 */

if (!defined('SISTEMA_CONTABLE')) {
    define('SISTEMA_CONTABLE', true);
}

if (!defined('SESSION_NAME')) {
    require_once __DIR__ . '/config.php';
}

class SessionManager {
    private static $initialized = false;
    
    public static function initialize() {
        if (self::$initialized) {
            return;
        }
        
        ini_set('session.name', SESSION_NAME);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        
        // Endurecimiento de Cookies según estándares OWASP
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => SESSION_SECURE,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
        
        self::$initialized = true;
    }
    
    public static function start() {
        self::initialize();
        
        if (session_status() === PHP_SESSION_NONE) {
            if (!session_start()) {
                throw new Exception("No se pudo iniciar la sesión");
            }
            
            $_SESSION['last_activity'] = time();
        }
    }
    
    public static function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            
            session_destroy();
        }
    }
}

// Inicializar automáticamente al cargar el archivo
SessionManager::initialize();
