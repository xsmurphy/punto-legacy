<?php
// Test de conexión a base de datos
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

echo "<h1>Test de Conexión</h1>";

// 1. Verificar .env
echo "<h2>1. Variables de entorno</h2>";
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    echo "✅ Archivo .env existe<br>";
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!strpos($line, '=')) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        if (strpos($key, 'DB_') === 0) {
            echo "- " . trim($key) . " = " . trim($value) . "<br>";
        }
    }
} else {
    echo "❌ Archivo .env NO existe<br>";
}

// 2. Incluir ADOdb
echo "<h2>2. ADOdb</h2>";
$adodbPath = __DIR__ . '/libraries/adodb/adodb.inc.php';
if (file_exists($adodbPath)) {
    echo "✅ ADOdb encontrado<br>";
    include_once($adodbPath);
} else {
    die("❌ ADOdb NO encontrado en: $adodbPath");
}

// 3. Conectar
echo "<h2>3. Conexión MySQL</h2>";
$servername = $_ENV['DB_HOST'] ?? '127.0.0.1';
$username   = $_ENV['DB_USER'] ?? 'encom';
$password   = $_ENV['DB_PASSWORD'] ?? 'encom123';
$database   = $_ENV['DB_NAME'] ?? 'encomdb';
$port       = $_ENV['DB_PORT'] ?? 3306;

echo "Host: $servername:$port<br>";
echo "Database: $database<br>";
echo "User: $username<br>";

$db = ADONewConnection('mysqli');
$db->host = $servername;
$db->port = $port;

try {
    $connected = $db->PConnect("$servername:$port", $username, $password, $database);
    
    if ($connected) {
        echo "✅ <strong>Conexión exitosa!</strong><br>";
        
        // 4. Probar query
        echo "<h2>4. Test de Query</h2>";
        $result = $db->Execute("SELECT COUNT(*) as total FROM company");
        if ($result && !$result->EOF) {
            $count = $result->fields['total'];
            echo "✅ Empresas en BD: $count<br>";
        }
        
        $result = $db->Execute("SELECT COUNT(*) as total FROM contact WHERE type = 0");
        if ($result && !$result->EOF) {
            $count = $result->fields['total'];
            echo "✅ Usuarios en BD: $count<br>";
        }
        
        $result = $db->Execute("SELECT * FROM contact WHERE type = 0 LIMIT 1");
        if ($result && !$result->EOF) {
            echo "✅ Usuario de prueba:<br>";
            echo "- Email: " . $result->fields['contactEmail'] . "<br>";
            echo "- Nombre: " . $result->fields['contactName'] . "<br>";
        } else {
            echo "⚠️ No hay usuarios en la BD<br>";
        }
        
    } else {
        echo "❌ Error de conexión: " . $db->ErrorMsg() . "<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Excepción: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h2>Siguiente paso</h2>";
echo "<p>Si todo está ✅, el problema está en el código de login/sesiones.</p>";
echo "<p><a href='login.php'>Ir a login.php</a></p>";
?>
