<?php
/**
 * SISTEMA DE MONITOREO DE SEGURIDAD
 * Monitorea y registra intentos de ataques
 */

class SecurityMonitor {
    
    private static $log_file = __DIR__ . '/../logs/security.log';
    private static $alert_file = __DIR__ . '/../logs/security_alerts.log';
    
    /**
     * Registra un evento de seguridad
     */
    public static function logEvent($type, $details, $severity = 'medium') {
        $event = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'severity' => $severity,
            'ip' => self::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'session_id' => session_id(),
            'user_id' => $_SESSION['usuario_id'] ?? 'anonymous',
            'details' => $details
        ];
        
        $log_entry = json_encode($event) . "\n";
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Si es de alta severidad, crear alerta
        if ($severity === 'high' || $severity === 'critical') {
            self::createAlert($event);
        }
    }
    
    /**
     * Crea una alerta de seguridad
     */
    private static function createAlert($event) {
        $alert = [
            'timestamp' => $event['timestamp'],
            'type' => $event['type'],
            'severity' => $event['severity'],
            'ip' => $event['ip'],
            'user_id' => $event['user_id'],
            'details' => $event['details'],
            'action_required' => true
        ];
        
        $alert_entry = json_encode($alert) . "\n";
        file_put_contents(self::$alert_file, $alert_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Obtiene la IP real del cliente
     */
    private static function getClientIP() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Verifica si una IP está en lista negra
     */
    public static function isIPBlacklisted($ip) {
        $blacklist_file = __DIR__ . '/../logs/ip_blacklist.txt';
        
        if (!file_exists($blacklist_file)) {
            return false;
        }
        
        $blacklisted_ips = file($blacklist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return in_array($ip, $blacklisted_ips);
    }
    
    /**
     * Agrega una IP a la lista negra
     */
    public static function blacklistIP($ip, $reason = '') {
        $blacklist_file = __DIR__ . '/../logs/ip_blacklist.txt';
        $entry = $ip . ' # ' . $reason . ' - ' . date('Y-m-d H:i:s') . "\n";
        file_put_contents($blacklist_file, $entry, FILE_APPEND | LOCK_EX);
        
        self::logEvent('IP_BLACKLISTED', "IP $ip agregada a lista negra: $reason", 'high');
    }
    
    /**
     * Verifica intentos de fuerza bruta
     */
    public static function checkBruteForce($identifier, $max_attempts = 5, $time_window = 300) {
        $attempts_file = __DIR__ . '/../logs/brute_force_attempts.json';
        
        if (!file_exists($attempts_file)) {
            file_put_contents($attempts_file, '{}');
        }
        
        $attempts = json_decode(file_get_contents($attempts_file), true);
        $current_time = time();
        $key = $identifier . '_' . self::getClientIP();
        
        // Limpiar intentos antiguos
        if (isset($attempts[$key])) {
            $attempts[$key] = array_filter($attempts[$key], function($timestamp) use ($current_time, $time_window) {
                return ($current_time - $timestamp) < $time_window;
            });
        }
        
        // Agregar intento actual
        if (!isset($attempts[$key])) {
            $attempts[$key] = [];
        }
        $attempts[$key][] = $current_time;
        
        // Verificar si excede el límite
        if (count($attempts[$key]) > $max_attempts) {
            self::logEvent('BRUTE_FORCE_DETECTED', "Demasiados intentos para $identifier", 'high');
            self::blacklistIP(self::getClientIP(), "Fuerza bruta: $identifier");
            return false;
        }
        
        // Guardar intentos
        file_put_contents($attempts_file, json_encode($attempts), LOCK_EX);
        return true;
    }
    
    /**
     * Obtiene estadísticas de seguridad
     */
    public static function getSecurityStats($days = 7) {
        $stats = [
            'total_events' => 0,
            'events_by_type' => [],
            'events_by_severity' => [],
            'top_ips' => [],
            'recent_events' => []
        ];
        
        if (!file_exists(self::$log_file)) {
            return $stats;
        }
        
        $lines = file(self::$log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoff_time = strtotime("-$days days");
        
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!$event) continue;
            
            $event_time = strtotime($event['timestamp']);
            if ($event_time < $cutoff_time) continue;
            
            $stats['total_events']++;
            
            // Contar por tipo
            $type = $event['type'];
            $stats['events_by_type'][$type] = ($stats['events_by_type'][$type] ?? 0) + 1;
            
            // Contar por severidad
            $severity = $event['severity'];
            $stats['events_by_severity'][$severity] = ($stats['events_by_severity'][$severity] ?? 0) + 1;
            
            // Contar IPs
            $ip = $event['ip'];
            $stats['top_ips'][$ip] = ($stats['top_ips'][$ip] ?? 0) + 1;
            
            // Eventos recientes (últimos 10)
            if (count($stats['recent_events']) < 10) {
                $stats['recent_events'][] = $event;
            }
        }
        
        // Ordenar IPs por frecuencia
        arsort($stats['top_ips']);
        $stats['top_ips'] = array_slice($stats['top_ips'], 0, 10, true);
        
        return $stats;
    }
    
    /**
     * Limpia logs antiguos
     */
    public static function cleanOldLogs($days = 30) {
        $cutoff_time = strtotime("-$days days");
        
        // Limpiar security.log
        if (file_exists(self::$log_file)) {
            $lines = file(self::$log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $new_lines = [];
            
            foreach ($lines as $line) {
                $event = json_decode($line, true);
                if ($event && strtotime($event['timestamp']) >= $cutoff_time) {
                    $new_lines[] = $line;
                }
            }
            
            file_put_contents(self::$log_file, implode("\n", $new_lines) . "\n");
        }
        
        // Limpiar security_alerts.log
        if (file_exists(self::$alert_file)) {
            $lines = file(self::$alert_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $new_lines = [];
            
            foreach ($lines as $line) {
                $alert = json_decode($line, true);
                if ($alert && strtotime($alert['timestamp']) >= $cutoff_time) {
                    $new_lines[] = $line;
                }
            }
            
            file_put_contents(self::$alert_file, implode("\n", $new_lines) . "\n");
        }
    }
}

/**
 * Función helper para registrar eventos de seguridad
 */
function registrarEventoSeguridad($type, $details, $severity = 'medium') {
    SecurityMonitor::logEvent($type, $details, $severity);
}

/**
 * Función helper para verificar fuerza bruta
 */
function verificarFuerzaBruta($identifier, $max_attempts = 5) {
    return SecurityMonitor::checkBruteForce($identifier, $max_attempts);
}
?>
