<?php
// Configuración de PostgreSQL para APP (POS)

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

// Configuración de PostgreSQL
$servername = $_ENV['POSTGRES_HOST'] ?? 'localhost';
$username   = $_ENV['POSTGRES_USER'] ?? 'encom';
$password   = $_ENV['POSTGRES_PASSWORD'] ?? 'encom123';
$database   = $_ENV['POSTGRES_DB'] ?? 'encomdb';
$port       = $_ENV['POSTGRES_PORT'] ?? 5432;

// Incluir ADOdb
include_once("libraries/adodb/adodb.inc.php");

// Crear conexión PostgreSQL
$db = ADONewConnection('postgres9');
$db->port = $port;

try {
    $connected = $db->Connect($servername, $username, $password, $database);
    
    if (!$connected) {
        die("Error de conexión a PostgreSQL: " . $db->ErrorMsg());
    }
    
    $db->Execute("SET TIME ZONE 'America/Asuncion'");
    $db->Execute("SET client_encoding = 'UTF8'");
    $db->Execute("SET standard_conforming_strings = off");
    
} catch (Exception $e) {
    die("Error al conectar con PostgreSQL: " . $e->getMessage());
}

// Configuración de cache
$db->cacheSecs = 3600 * 24;
$ADODB_CACHE_DIR = '../../cache/adodb';
$ADODB_COUNTRECS = true;

function db_prepare($var) {
    global $db;
    $return = $var;
    
    if (defined('COMPANY_ID')) {
        if (COMPANY_ID == 10) {
            if (is_string($var)) {
                // PostgreSQL usa pg_escape_string
                $return = pg_escape_string($db->_connectionID, $var);
            }
        }
    }
    
    return $db->qstr($return);
}

$db->SetFetchMode(ADODB_FETCH_ASSOC);
?>
