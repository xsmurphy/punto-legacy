<?php
// Configuración de base de datos LOCAL
// Este archivo reemplaza db.php para desarrollo local

// SUPRIMIR WARNINGS DE PHP 8.4 con ADOdb
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

// Cargar variables de entorno si existe .env
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!strpos($line, '=')) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// Configuración de base de datos
$servername = $_ENV['DB_HOST'] ?? '127.0.0.1';
$username   = $_ENV['DB_USER'] ?? 'encom';
$password   = $_ENV['DB_PASSWORD'] ?? 'encom123';
$database   = $_ENV['DB_NAME'] ?? 'encomdb';
$port       = $_ENV['DB_PORT'] ?? 3306;

// Incluir ADOdb con ruta relativa
$adodbPath = __DIR__ . '/../libraries/adodb/adodb.inc.php';
if (!file_exists($adodbPath)) {
    die("Error: No se encuentra ADOdb en: $adodbPath");
}
include_once($adodbPath);

// Crear conexión forzando TCP/IP
$db = ADONewConnection('mysqli');

// IMPORTANTE: Configurar host y puerto ANTES de conectar
$db->host = $servername;
$db->port = $port;

try {
    // Usar PConnect para conexión persistente TCP
    $connected = $db->PConnect("$servername:$port", $username, $password, $database);
    
    if (!$connected) {
        die("Error de conexión a MySQL: " . $db->ErrorMsg() . 
            "<br>Host: $servername:$port<br>Database: $database<br>User: $username" .
            "<br><br>Verifica: docker-compose ps");
    }
    
    // Configurar MySQL
    $db->Execute("SET SESSION sql_mode = ''");
    $db->Execute("SET NAMES utf8mb4");
    
} catch (Exception $e) {
    die("Error al conectar: " . $e->getMessage() . 
        "<br>Host: $servername:$port<br>Database: $database");
}

// Configuración de cache
$db->cacheSecs = 3600; // 1 hora
$ADODB_CACHE_DIR = __DIR__ . '/../../cache/adodb';
$ADODB_COUNTRECS = true;

// Crear directorio de cache si no existe
if (!is_dir($ADODB_CACHE_DIR)) {
    mkdir($ADODB_CACHE_DIR, 0755, true);
}

// Función helper
function db_prepare($var) {
    global $db;
    return $db->Prepare($var);
}

// Mensaje de éxito (solo en modo debug)
if (isset($_GET['debug'])) {
    error_log("✅ Conectado a MySQL: $servername:$port/$database");
}
?>
