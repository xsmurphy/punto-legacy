<?php
// Configuración de base de datos LOCAL para APP
// Este archivo reemplaza db.php para desarrollo local

// Suprimir warnings de PHP 8.x con ADOdb
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

// Cargar variables de entorno si existe .env
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// Configuración de base de datos
$servername = $_ENV['DB_HOST'] ?? 'localhost';
$username   = $_ENV['DB_USER'] ?? 'encom';
$password   = $_ENV['DB_PASSWORD'] ?? 'encom123';
$database   = $_ENV['DB_NAME'] ?? 'encomdb';
$port       = $_ENV['DB_PORT'] ?? 3306;

// Incluir ADOdb
include_once("libraries/adodb/adodb.inc.php");

// Crear conexión
$db = ADONewConnection('mysqli');
$db->port = $port;

try {
    $connected = $db->NConnect($servername, $username, $password, $database);
    
    if (!$connected) {
        die("Error de conexión a MySQL: " . $db->ErrorMsg());
    }
    
    $db->Execute("SET SESSION sql_mode = ''");
    $db->Execute("SET NAMES utf8mb4");
    
} catch (Exception $e) {
    die("Error al conectar con la base de datos: " . $e->getMessage());
}

// Configuración de cache
$db->cacheSecs = 3600 * 24; // 24 horas
$ADODB_CACHE_DIR = '../../cache/adodb';
$ADODB_COUNTRECS = true;

function db_prepare($var) {
    global $db;
    $return = $var;
    
    if (defined('COMPANY_ID')) {
        if (COMPANY_ID == 10) {
            if (is_string($var)) {
                $return = mysqli_real_escape_string($db->_connectionID, $var);
            }
        }
    }
    
    return $db->Prepare($return);
}
?>
