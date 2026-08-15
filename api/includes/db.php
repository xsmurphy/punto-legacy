<?php
/**
 * Conexión PostgreSQL via PDO.
 * Punto de entrada único para la base de datos del módulo /app.
 */

// Cargar variables de entorno
// Soporta: comentarios con #, valores con o sin quotes ('...' o "..."),
// = dentro del valor (explode con limit=2).
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) continue;

        $value = trim($m[2]);
        $len   = strlen($value);

        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // No pisar env vars ya presentes (mismo criterio que
        // api/database/migrate.php): un caller que exporta POSTGRES_HOST/PORT
        // antes de invocar PHP (ej. api/lib/Sales/verify_chain/run.sh, que
        // apunta a un Postgres descartable en Docker) tiene que ganarle al
        // .env del repo, no al revés.
        if (!isset($_ENV[$m[1]])) {
            $_ENV[$m[1]] = $value;
        }
    }
}

// Soporte para DATABASE_URL (Coolify / Railway / Heroku style)
// postgresql://user:password@host:5432/dbname — tiene prioridad sobre vars individuales
if (!empty($_ENV['DATABASE_URL'])) {
    $u = parse_url($_ENV['DATABASE_URL']);
    $_ENV['POSTGRES_HOST']     = $u['host']                         ?? 'localhost';
    $_ENV['POSTGRES_USER']     = isset($u['user'])     ? urldecode($u['user'])     : 'punto';
    $_ENV['POSTGRES_PASSWORD'] = isset($u['pass'])     ? urldecode($u['pass'])     : '';
    $_ENV['POSTGRES_DB']       = isset($u['path'])     ? ltrim($u['path'], '/')    : 'puntoDB';
    $_ENV['POSTGRES_PORT']     = $u['port']                         ?? 5432;
}

$servername = $_ENV['POSTGRES_HOST']     ?? 'localhost';
$username   = $_ENV['POSTGRES_USER']     ?? 'punto';
$password   = $_ENV['POSTGRES_PASSWORD'] ?? 'punto123';
$database   = $_ENV['POSTGRES_DB']       ?? 'puntoDB';
$port       = (int) ($_ENV['POSTGRES_PORT'] ?? 5432);

// Constantes de fetch mode usadas por el wrapper DB
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

// Helper legacy — con PDO y queries parametrizadas no necesita sanitizar.
// Pass-through para mantener compatibilidad con código que la llama.
if (!function_exists('db_prepare')) {
    function db_prepare($var) {
        return $var;
    }
}
