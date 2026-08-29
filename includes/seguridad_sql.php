<?php
/**
 * SISTEMA DE SEGURIDAD AVANZADA CONTRA INYECCIÓN SQL
 * Valida y sanitiza datos antes de ejecutar consultas
 */

// Patrones de inyección SQL para detectar
class SQLSecurityValidator {
    
    private static $sql_patterns = [
        // Patrones básicos de inyección
        '/(\bunion\b.*\bselect\b)/i',
        '/(\bselect\b.*\bfrom\b)/i',
        '/(\binsert\b.*\binto\b)/i',
        '/(\bupdate\b.*\bset\b)/i',
        '/(\bdelete\b.*\bfrom\b)/i',
        '/(\bdrop\b.*\btable\b)/i',
        '/(\btruncate\b.*\btable\b)/i',
        '/(\balter\b.*\btable\b)/i',
        '/(\bcreate\b.*\btable\b)/i',
        '/(\bgrant\b.*\bto\b)/i',
        
        // Patrones de funciones peligrosas
        '/(\bexec\s*\()/i',
        '/(\bexecute\s*\()/i',
        '/(\bsp_executesql\b)/i',
        '/(\bxp_cmdshell\b)/i',
        '/(\bwaitfor\s+delay\b)/i',
        '/(\bbenchmark\s*\()/i',
        '/(\bsleep\s*\()/i',
        '/(\bload_file\s*\()/i',
        '/(\binto\s+outfile\b)/i',
        '/(\binto\s+dumpfile\b)/i',
        
        // Patrones de información del sistema
        '/(\binformation_schema\b)/i',
        '/(\bmysql\.user\b)/i',
        '/(\bsys\.databases\b)/i',
        '/(\b@@version\b)/i',
        '/(\b@@hostname\b)/i',
        '/(\b@@datadir\b)/i',
        '/(\bversion\s*\()/i',
        '/(\bdatabase\s*\()/i',
        '/(\buser\s*\()/i',
        '/(\bsystem_user\b)/i',
        '/(\bcurrent_user\b)/i',
        '/(\bsession_user\b)/i',
        
        // Patrones de codificación
        '/(0x[0-9a-fA-F]+)/i',
        '/(\bchar\s*\()/i',
        '/(\bascii\s*\()/i',
        '/(\bhex\s*\()/i',
        '/(\bconcat\s*\()/i',
        '/(\bgroup_concat\s*\()/i',
        '/(\bsubstr\s*\()/i',
        '/(\bsubstring\s*\()/i',
        '/(\bmid\s*\()/i',
        
        // Patrones de operadores
        '/(\border\s+by\b)/i',
        '/(\bgroup\s+by\b)/i',
        '/(\bhaving\s+)/i',
        '/(\bwhere\s+.*\s+like\b)/i',
        '/(\bwhere\s+.*\s+in\s*\()/i',
        '/(\bif\s*\()/i',
        '/(\bcase\s+when\b)/i',
        '/(\bcast\s*\()/i',
        
        // Patrones de comentarios
        '/(--\s)/i',
        '/(\/\*.*\*\/)/i',
        '/(#\s)/i',
        
        // Patrones de escape
        '/(\\\x[0-9a-fA-F]{2})/i',
        '/(\\\u[0-9a-fA-F]{4})/i',
        
        // Patrones de inyección por tiempo
        '/(\band\s+.*\s+sleep\s*\()/i',
        '/(\band\s+.*\s+benchmark\s*\()/i',
        '/(\band\s+.*\s+waitfor\s+delay)/i',
        
        // Patrones de inyección booleana
        '/(\band\s+.*\s*=\s*.*\s*and\b)/i',
        '/(\bor\s+.*\s*=\s*.*\s*or\b)/i',
        '/(\band\s+.*\s*like\s*.*\s*and\b)/i',
        '/(\bor\s+.*\s*like\s*.*\s*or\b)/i',
    ];
    
    private static $xss_patterns = [
        '/(<script[^>]*>.*?<\/script>)/is',
        '/(<iframe[^>]*>.*?<\/iframe>)/is',
        '/(<object[^>]*>.*?<\/object>)/is',
        '/(<embed[^>]*>.*?<\/embed>)/is',
        '/(javascript\s*:)/i',
        '/(vbscript\s*:)/i',
        '/(onload\s*=)/i',
        '/(onerror\s*=)/i',
        '/(onclick\s*=)/i',
        '/(onmouseover\s*=)/i',
    ];
    
    /**
     * Valida si una cadena contiene patrones de inyección SQL
     */
    public static function validateSQLInjection($input) {
        if (empty($input)) {
            return ['valid' => true, 'message' => ''];
        }
        
        foreach (self::$sql_patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                self::logSecurityAttempt('SQL_INJECTION', $input, $pattern);
                return [
                    'valid' => false, 
                    'message' => 'Patrón de inyección SQL detectado: ' . $pattern
                ];
            }
        }
        
        return ['valid' => true, 'message' => ''];
    }
    
    /**
     * Valida si una cadena contiene patrones de XSS
     */
    public static function validateXSS($input) {
        if (empty($input)) {
            return ['valid' => true, 'message' => ''];
        }
        
        foreach (self::$xss_patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                self::logSecurityAttempt('XSS', $input, $pattern);
                return [
                    'valid' => false, 
                    'message' => 'Patrón XSS detectado: ' . $pattern
                ];
                break;
            }
        }
        
        return ['valid' => true, 'message' => ''];
    }
    
    /**
     * Sanitiza una cadena para uso seguro en consultas
     */
    public static function sanitizeInput($input) {
        if (empty($input)) {
            return $input;
        }
        
        // Validar primero
        $sql_validation = self::validateSQLInjection($input);
        $xss_validation = self::validateXSS($input);
        
        if (!$sql_validation['valid'] || !$xss_validation['valid']) {
            throw new SecurityException('Entrada no válida detectada');
        }
        
        // Sanitizar
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        return $input;
    }
    
    /**
     * Valida parámetros de consulta preparada
     */
    public static function validatePreparedParams($params) {
        $errors = [];
        
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $sql_validation = self::validateSQLInjection($value);
                $xss_validation = self::validateXSS($value);
                
                if (!$sql_validation['valid']) {
                    $errors[] = "Parámetro '{$key}': " . $sql_validation['message'];
                }
                
                if (!$xss_validation['valid']) {
                    $errors[] = "Parámetro '{$key}': " . $xss_validation['message'];
                }
            }
        }
        
        if (!empty($errors)) {
            throw new SecurityException('Parámetros no válidos: ' . implode(', ', $errors));
        }
        
        return true;
    }
    
    /**
     * Registra intentos de seguridad
     */
    private static function logSecurityAttempt($type, $input, $pattern) {
        $log_data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'pattern' => $pattern,
            'input' => substr($input, 0, 500), // Limitar longitud
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
        ];
        
        $log_entry = json_encode($log_data) . "\n";
        file_put_contents(
            __DIR__ . '/../logs/security.log', 
            $log_entry, 
            FILE_APPEND | LOCK_EX
        );
    }
    
    /**
     * Valida una consulta SQL completa
     */
    public static function validateSQLQuery($query) {
        if (empty($query)) {
            return ['valid' => true, 'message' => ''];
        }
        
        // Normalizar la consulta
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        // Verificar que solo contenga operaciones permitidas
        $allowed_operations = [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP'
        ];
        
        $query_upper = strtoupper($query);
        $has_allowed_operation = false;
        
        foreach ($allowed_operations as $operation) {
            if (strpos($query_upper, $operation) === 0) {
                $has_allowed_operation = true;
                break;
            }
        }
        
        if (!$has_allowed_operation) {
            return [
                'valid' => false, 
                'message' => 'Operación SQL no permitida'
            ];
        }
        
        // Validar contra patrones de inyección
        return self::validateSQLInjection($query);
    }
}

/**
 * Excepción personalizada para errores de seguridad
 */
class SecurityException extends Exception {
    public function __construct($message = "", $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Función helper para validar datos de entrada
 */
function validarEntradaSegura($input, $tipo = 'string') {
    $validator = new SQLSecurityValidator();
    
    switch ($tipo) {
        case 'string':
            return $validator::sanitizeInput($input);
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT);
        case 'float':
            return filter_var($input, FILTER_VALIDATE_FLOAT);
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_VALIDATE_URL);
        default:
            return $validator::sanitizeInput($input);
    }
}

/**
 * Función helper para validar parámetros de consulta
 */
function validarParametrosConsulta($params) {
    $validator = new SQLSecurityValidator();
    return $validator::validatePreparedParams($params);
}

/**
 * Función helper para ejecutar consultas seguras
 */
function ejecutarConsultaSegura($pdo, $query, $params = []) {
    $validator = new SQLSecurityValidator();
    
    // Validar consulta
    $query_validation = $validator::validateSQLQuery($query);
    if (!$query_validation['valid']) {
        throw new SecurityException('Consulta no válida: ' . $query_validation['message']);
    }
    
    // Validar parámetros
    if (!empty($params)) {
        $validator::validatePreparedParams($params);
    }
    
    // Ejecutar consulta
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    return $stmt;
}
?>
