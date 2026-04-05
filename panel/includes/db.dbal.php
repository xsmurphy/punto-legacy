<?php
/**
 * Configuración de Doctrine DBAL
 * Alternativa moderna a ADOdb con mejor soporte para PostgreSQL
 */

require_once __DIR__ . '/../composer/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;

// Cargar variables de entorno
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// Determinar qué base de datos usar
$usePostgres = isset($_ENV['USE_POSTGRES']) && $_ENV['USE_POSTGRES'] === 'true';

if ($usePostgres) {
    // Configuración PostgreSQL
    $connectionParams = [
        'dbname'   => $_ENV['POSTGRES_DB'] ?? 'encomdb',
        'user'     => $_ENV['POSTGRES_USER'] ?? 'encom',
        'password' => $_ENV['POSTGRES_PASSWORD'] ?? 'encom123',
        'host'     => $_ENV['POSTGRES_HOST'] ?? 'localhost',
        'port'     => $_ENV['POSTGRES_PORT'] ?? 5432,
        'driver'   => 'pdo_pgsql',
        'charset'  => 'utf8',
    ];
} else {
    // Configuración MySQL
    $connectionParams = [
        'dbname'   => $_ENV['MYSQL_NAME'] ?? 'encomdb',
        'user'     => $_ENV['MYSQL_USER'] ?? 'encom',
        'password' => $_ENV['MYSQL_PASSWORD'] ?? 'encom123',
        'host'     => $_ENV['MYSQL_HOST'] ?? 'localhost',
        'port'     => $_ENV['MYSQL_PORT'] ?? 3306,
        'driver'   => 'pdo_mysql',
        'charset'  => 'utf8mb4',
    ];
}

try {
    // Crear conexión
    $config = new Configuration();
    $conn = DriverManager::getConnection($connectionParams, $config);
    
    // Verificar conexión
    $conn->connect();
    
    if (isset($_GET['debug'])) {
        $driver = $usePostgres ? 'PostgreSQL' : 'MySQL';
        error_log("✅ Conectado a $driver: {$connectionParams['host']}:{$connectionParams['port']}/{$connectionParams['dbname']}");
    }
    
} catch (\Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Funciones helper para compatibilidad con código existente
function db_execute($sql, $params = []) {
    global $conn;
    try {
        return $conn->executeQuery($sql, $params);
    } catch (\Exception $e) {
        error_log("SQL Error: " . $e->getMessage());
        return false;
    }
}

function db_fetch_all($sql, $params = []) {
    global $conn;
    try {
        return $conn->fetchAllAssociative($sql, $params);
    } catch (\Exception $e) {
        error_log("SQL Error: " . $e->getMessage());
        return [];
    }
}

function db_fetch_one($sql, $params = []) {
    global $conn;
    try {
        return $conn->fetchAssociative($sql, $params);
    } catch (\Exception $e) {
        error_log("SQL Error: " . $e->getMessage());
        return false;
    }
}

function db_insert($table, $data) {
    global $conn;
    try {
        $conn->insert($table, $data);
        return $conn->lastInsertId();
    } catch (\Exception $e) {
        error_log("SQL Error: " . $e->getMessage());
        return false;
    }
}

function db_update($table, $data, $where) {
    global $conn;
    try {
        return $conn->update($table, $data, $where);
    } catch (\Exception $e) {
        error_log("SQL Error: " . $e->getMessage());
        return false;
    }
}

function db_delete($table, $where) {
    global $conn;
    try {
        return $conn->delete($table, $where);
    } catch (\Exception $e) {
        error_log("SQL Error: " . $e->getMessage());
        return false;
    }
}

// Función para preparar valores (escapar)
function db_prepare($var) {
    global $conn;
    
    if (is_array($var)) {
        return array_map('db_prepare', $var);
    }
    
    if (is_null($var)) {
        return null;
    }
    
    if (is_bool($var)) {
        return $var ? 1 : 0;
    }
    
    if (is_numeric($var)) {
        return $var;
    }
    
    // Para strings, DBAL maneja el escape automáticamente con prepared statements
    return $var;
}

// Wrapper para mantener compatibilidad con código que usa $db global
class DBALWrapper {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    public function Execute($sql, $params = []) {
        return db_execute($sql, $params);
    }
    
    public function GetAll($sql, $params = []) {
        return db_fetch_all($sql, $params);
    }
    
    public function GetRow($sql, $params = []) {
        return db_fetch_one($sql, $params);
    }
    
    public function Insert($table, $data) {
        return db_insert($table, $data);
    }
    
    public function Update($table, $data, $where) {
        return db_update($table, $data, $where);
    }
    
    public function Delete($table, $where) {
        return db_delete($table, $where);
    }
    
    public function Insert_ID() {
        return $this->conn->lastInsertId();
    }
    
    public function Affected_Rows() {
        return $this->conn->executeStatement('SELECT ROW_COUNT()');
    }
    
    public function ErrorMsg() {
        return $this->conn->errorInfo()[2] ?? '';
    }
    
    public function qstr($str) {
        return $this->conn->quote($str);
    }
    
    public function Prepare($var) {
        return db_prepare($var);
    }
}

// Crear instancia global para compatibilidad
$db = new DBALWrapper($conn);

// Configurar timezone
if ($usePostgres) {
    $conn->executeStatement("SET TIME ZONE 'America/Asuncion'");
} else {
    $conn->executeStatement("SET time_zone = 'America/Asuncion'");
}
?>
