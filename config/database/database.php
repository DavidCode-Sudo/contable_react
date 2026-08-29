<?php
/**
 * Configuración y manejo de la base de datos
 * Sistema Contable Corporativo
 */

// Prevenir acceso directo
if (!defined('SISTEMA_CONTABLE')) {
    define('SISTEMA_CONTABLE', true);
}

// Incluir configuración principal si no está incluida
if (!defined('DB_HOST')) {
    require_once dirname(__DIR__) . '/config.php';
}

/**
 * Clase para manejo de conexión a base de datos
 */
class DatabaseConnection {
    private static $instance = null;
    private $connection = null;
    
    private function __construct() {
        $this->connect();
    }
    
    /**
     * Obtiene la instancia singleton de la conexión
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establece la conexión a la base de datos
     */
    private function connect() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci; SET CHARACTER SET utf8mb4; SET character_set_connection=utf8mb4; SET character_set_client=utf8mb4; SET character_set_results=utf8mb4; SET collation_connection=utf8mb4_general_ci; SET collation_database=utf8mb4_general_ci;",
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 30
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Configurar zona horaria de MySQL igual a APP_TIMEZONE
            try {
                $tz = new DateTimeZone(APP_TIMEZONE);
                $nowTz = new DateTime('now', $tz);
                $offsetSeconds = $tz->getOffset($nowTz);
                $sign = $offsetSeconds >= 0 ? '+' : '-';
                $abs = abs($offsetSeconds);
                $hours = str_pad((string)floor($abs / 3600), 2, '0', STR_PAD_LEFT);
                $minutes = str_pad((string)floor(($abs % 3600) / 60), 2, '0', STR_PAD_LEFT);
                $mysqlOffset = sprintf("%s%s:%s", $sign, $hours, $minutes);
                $this->connection->exec("SET time_zone = " . $this->connection->quote($mysqlOffset));
            } catch (Exception $e) {
                // Fallback: si algo falla, no interrumpir la conexión
                if (function_exists('logMessage')) {
                    logMessage('No se pudo ajustar MySQL time_zone: ' . $e->getMessage(), 'WARNING', 'database.log');
                }
            }
            
            // Log de conexión exitosa
            if (function_exists('logMessage')) {
                logMessage("Conexión a base de datos establecida exitosamente", 'INFO');
            }
            
        } catch (PDOException $e) {
            $errorMsg = "Error de conexión a base de datos: " . $e->getMessage();
            
            if (function_exists('logMessage')) {
                logMessage($errorMsg, 'ERROR', 'database.log');
            }
            
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                throw new Exception($errorMsg);
            } else {
                throw new Exception("Error de conexión a la base de datos. Contacte al administrador.");
            }
        }
    }
    
    /**
     * Obtiene la conexión PDO
     */
    public function getConnection() {
        // Verificar si la conexión sigue activa
        if ($this->connection === null) {
            $this->connect();
        }
        
        try {
            $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            // Reconectar si la conexión se perdió
            $this->connect();
        }
        
        return $this->connection;
    }
    
    /**
     * Cierra la conexión
     */
    public function closeConnection() {
        $this->connection = null;
    }
    
    /**
     * Prevenir clonación
     */
    private function __clone() {}
    
    /**
     * Prevenir deserialización
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Función helper para obtener la conexión a la base de datos
 */
function getConnection() {
    return DatabaseConnection::getInstance()->getConnection();
}

/**
 * Función helper para ejecutar consultas preparadas de forma segura
 */
function executeQuery($sql, $params = []) {
    try {
        $conn = getConnection();
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        if (function_exists('logMessage')) {
            logMessage("Error en consulta SQL: " . $e->getMessage() . " | SQL: " . $sql, 'ERROR', 'database.log');
        }
        throw $e;
    }
}

/**
 * Función helper para obtener un solo registro
 */
function fetchOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Función helper para obtener múltiples registros
 */
function fetchAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Función helper para insertar registros y obtener el ID
 */
function insertAndGetId($sql, $params = []) {
    $conn = getConnection();
    $stmt = executeQuery($sql, $params);
    return $conn->lastInsertId();
}

/**
 * Función helper para iniciar transacción
 */
function beginTransaction() {
    return getConnection()->beginTransaction();
}

/**
 * Función helper para confirmar transacción
 */
function commitTransaction() {
    return getConnection()->commit();
}

/**
 * Función helper para revertir transacción
 */
function rollbackTransaction() {
    return getConnection()->rollBack();
}

/**
 * Función helper para verificar si estamos en una transacción
 */
function inTransaction() {
    return getConnection()->inTransaction();
}

/**
 * Función para verificar la conexión a la base de datos
 */
function testDatabaseConnection() {
    try {
        $conn = getConnection();
        $stmt = $conn->query("SELECT 1 as test");
        $result = $stmt->fetch();
        return $result['test'] === 1;
    } catch (Exception $e) {
        if (function_exists('logMessage')) {
            logMessage("Test de conexión falló: " . $e->getMessage(), 'ERROR', 'database.log');
        }
        return false;
    }
}

/**
 * Función para obtener información de la base de datos
 */
function getDatabaseInfo() {
    try {
        $conn = getConnection();
        $info = [
            'server_version' => $conn->getAttribute(PDO::ATTR_SERVER_VERSION),
            'client_version' => $conn->getAttribute(PDO::ATTR_CLIENT_VERSION),
            'connection_status' => $conn->getAttribute(PDO::ATTR_CONNECTION_STATUS),
            'server_info' => $conn->getAttribute(PDO::ATTR_SERVER_INFO)
        ];
        return $info;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Función para limpiar y sanitizar datos de entrada
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    
    // Remover espacios en blanco al inicio y final
    $data = trim($data);
    
    // Convertir caracteres especiales a entidades HTML
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    
    return $data;
}

/**
 * Función para validar y escapar datos para SQL
 */
function escapeForSql($data) {
    $conn = getConnection();
    return $conn->quote($data);
}

// Verificar conexión al cargar el archivo
try {
    $testConnection = testDatabaseConnection();
    if (!$testConnection && function_exists('logMessage')) {
        logMessage("Advertencia: No se pudo establecer conexión con la base de datos al cargar database.php", 'WARNING', 'database.log');
    }
} catch (Exception $e) {
    if (function_exists('logMessage')) {
        logMessage("Error al verificar conexión inicial: " . $e->getMessage(), 'ERROR', 'database.log');
    }
}
