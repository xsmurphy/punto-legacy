<?php
// Configuración de PostgreSQL para el sistema actual PHP
// Reemplaza db.php para usar PostgreSQL en lugar de MySQL

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
$adodbPath = __DIR__ . '/../libraries/adodb/adodb.inc.php';
if (!file_exists($adodbPath)) {
    die("Error: No se encuentra ADOdb en: $adodbPath");
}
include_once($adodbPath);

// Crear conexión PostgreSQL
$db = ADONewConnection('postgres9'); // Driver de PostgreSQL
$db->port = $port;

try {
    // Conectar
    $connected = $db->Connect($servername, $username, $password, $database);
    
    if (!$connected) {
        die("Error de conexión a PostgreSQL: " . $db->ErrorMsg());
    }
    
    // Configurar PostgreSQL
    $db->Execute("SET TIME ZONE 'America/Asuncion'");
    $db->Execute("SET client_encoding = 'UTF8'");
    
    // Configurar para compatibilidad con MySQL
    $db->Execute("SET standard_conforming_strings = off"); // Para escapar strings como MySQL
    
} catch (Exception $e) {
    die("Error al conectar con PostgreSQL: " . $e->getMessage() . 
        "<br>Host: $servername:$port<br>Database: $database<br>User: $username");
}

// Configuración de cache
$db->cacheSecs = 3600; // 1 hora
$ADODB_CACHE_DIR = __DIR__ . '/../../cache/adodb';
$ADODB_COUNTRECS = true;

// Crear directorio de cache si no existe
if (!is_dir($ADODB_CACHE_DIR)) {
    mkdir($ADODB_CACHE_DIR, 0755, true);
}

// Función helper compatible con PostgreSQL
function db_prepare($var) {
    global $db;
    
    // PostgreSQL usa $1, $2, etc. en lugar de ?
    // ADOdb maneja esto automáticamente con Prepare()
    return $db->qstr($var); // Escapa y agrega comillas
}

// Mensaje de éxito (solo en modo debug)
if (isset($_GET['debug'])) {
    error_log("✅ Conectado a PostgreSQL: $servername:$port/$database");
}

// IMPORTANTE: Configurar modo de compatibilidad
// PostgreSQL es más estricto que MySQL
$db->SetFetchMode(ADODB_FETCH_ASSOC); // Mismo comportamiento que MySQL
?>
