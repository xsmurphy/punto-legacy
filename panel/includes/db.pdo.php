<?php
/**
 * Conexión PostgreSQL via PDO (sin ADOdb).
 *
 * Drop-in replacement para db.postgres.php.
 * Para activar la migración, en DB.php reemplazar:
 *
 *   require_once __DIR__ . '/db.postgres.php';
 *
 * por:
 *
 *   require_once __DIR__ . '/db.pdo.php';
 */

// Cargar variables de entorno
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$servername = $_ENV['POSTGRES_HOST']     ?? 'localhost';
$username   = $_ENV['POSTGRES_USER']     ?? 'encom';
$password   = $_ENV['POSTGRES_PASSWORD'] ?? 'encom123';
$database   = $_ENV['POSTGRES_DB']       ?? 'encomdb';
$port       = (int) ($_ENV['POSTGRES_PORT'] ?? 5432);

// Constantes ADOdb requeridas por código legacy antes de cargar DB.php
if (!defined('ADODB_FETCH_DEFAULT')) define('ADODB_FETCH_DEFAULT', 0);
if (!defined('ADODB_FETCH_NUM'))     define('ADODB_FETCH_NUM',     1);
if (!defined('ADODB_FETCH_ASSOC'))   define('ADODB_FETCH_ASSOC',   2);
if (!defined('ADODB_FETCH_BOTH'))    define('ADODB_FETCH_BOTH',    3);

require_once __DIR__ . '/lib/DB.php';

$db       = new DB();
$db->port = $port;

$connected = $db->Connect($servername, $username, $password, $database);

if (!$connected) {
    die('Error de conexión a PostgreSQL: ' . $db->ErrorMsg());
}

// Configuración de sesión PostgreSQL
$db->Execute("SET TIME ZONE 'America/Asuncion'");
$db->Execute("SET client_encoding = 'UTF8'");

// Función helper legacy — con PDO y queries parametrizadas no necesita sanitizar.
// Pass-through para mantener compatibilidad con código que la llama.
if (!function_exists('db_prepare')) {
    function db_prepare($var) {
        return $var;
    }
}
