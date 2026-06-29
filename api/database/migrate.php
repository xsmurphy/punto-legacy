<?php
/**
 * Auto-migration runner — aplica las migraciones pendientes al startup.
 *
 * Corre desde docker-entrypoint.sh ANTES de exec'ear el CMD del container,
 * para que cada deploy aplique automáticamente las migraciones SQL nuevas
 * en `database/migrations/postgres/`. Idempotente — usa la tabla
 * `schema_migrations` para trackear qué filenames ya corrieron.
 *
 * Convención de filenames: `NN_description.sql` (NN = número entero, no
 * zero-padded). El sort es numérico (no lexicográfico) → 13 < 14, no "13" > "14".
 *
 * Bootstrap one-time: si `schema_migrations` está vacía Y la BD tiene schema
 * "viejo" (columna `outletAddress` presente — el marker de pre-migración-14),
 * asumimos que las migraciones 01-13 ya se aplicaron manualmente (era el flujo
 * antiguo) y las marcamos como done sin re-ejecutar. La 14+ corren al ritmo
 * normal del runner desde el primer deploy.
 *
 * Failure: si una migración falla, log al stderr + exit 1 → entrypoint corta y
 * el container no arranca. Mejor fail-fast que servir requests contra un schema
 * a medio migrar.
 *
 * Usa PDO directo (no el wrapper DB del app) porque:
 *   - el wrapper hace `die()` en error de conexión, lo que matarí­a el entrypoint
 *     antes de poder log'ear un mensaje útil
 *   - no expone una API limpia para multi-statement (cada .sql tiene BEGIN/COMMIT)
 *   - PDO::exec() acepta multi-statement nativamente para PG
 */

declare(strict_types=1);

// Cargar .env del repo si está (Coolify suele inyectar via env directos,
// pero en local docker run podemos tener .env).
$repoRoot = dirname(__DIR__);
$envFile  = $repoRoot . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $value = trim($m[2]);
        $len   = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (!isset($_ENV[$m[1]])) {
            $_ENV[$m[1]] = $value;
        }
    }
}

// Soporte DATABASE_URL (estilo Coolify managed).
if (!empty($_ENV['DATABASE_URL'])) {
    $u = parse_url((string) $_ENV['DATABASE_URL']);
    $_ENV['POSTGRES_HOST']     = $u['host'] ?? 'localhost';
    $_ENV['POSTGRES_USER']     = isset($u['user']) ? urldecode($u['user']) : 'punto';
    $_ENV['POSTGRES_PASSWORD'] = isset($u['pass']) ? urldecode($u['pass']) : '';
    $_ENV['POSTGRES_DB']       = isset($u['path']) ? ltrim($u['path'], '/') : 'puntoDB';
    $_ENV['POSTGRES_PORT']     = $u['port'] ?? 5432;
}

$host = $_ENV['POSTGRES_HOST']     ?? 'localhost';
$user = $_ENV['POSTGRES_USER']     ?? 'punto';
$pass = $_ENV['POSTGRES_PASSWORD'] ?? '';
$dbnm = $_ENV['POSTGRES_DB']       ?? 'puntoDB';
$port = (int) ($_ENV['POSTGRES_PORT'] ?? 5432);

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbnm);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "[migrate] PDO connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[migrate] verificá POSTGRES_HOST/USER/PASSWORD/DB env vars\n");
    exit(1);
}

// 1. Tabla de tracking.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   TEXT PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )"
);

// 2. Listado de migrations files sorted numéricamente (SQL + PHP).
$dir      = __DIR__ . '/migrations/postgres';
$sqlFiles = glob($dir . '/*.sql') ?: [];
$phpFiles = glob($dir . '/*.php') ?: [];
$files    = array_merge($sqlFiles, $phpFiles);
usort($files, static function (string $a, string $b): int {
    preg_match('/^(\d+)/', basename($a), $ma);
    preg_match('/^(\d+)/', basename($b), $mb);
    return (int) ($ma[1] ?? 0) - (int) ($mb[1] ?? 0);
});

if (!$files) {
    echo "[migrate] no hay archivos en $dir — nothing to do\n";
    exit(0);
}

// 3. Set de migrations ya aplicadas.
$applied = [];
$stmt = $pdo->query('SELECT filename FROM schema_migrations');
foreach ($stmt as $row) {
    $applied[(string) $row['filename']] = true;
}

// 4. Bootstrap one-time: si la tracking table está vacía y la BD parece "vieja"
// (pre-14), marcamos 01-13 como already-applied. Detección: si existe la columna
// `outletAddress` en la tabla `outlet`, asumimos que sigue pre-migración-14 → las
// migraciones 01-13 ya estaban aplicadas via flujo manual. Si la columna no existe
// pero schema_migrations está vacía, es DB fresca → corremos todo desde 01.
if (!$applied) {
    $check = $pdo->query(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name = 'outlet' AND column_name = 'outletaddress'"
    );
    $isExistingDB = ($check && $check->fetch() !== false);
    if ($isExistingDB) {
        echo "[migrate] bootstrap: detectada BD existente (pre-migración 14)\n";
        echo "[migrate] bootstrap: marcando migraciones 01-13 como already-applied\n";
        $ins = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?) ON CONFLICT DO NOTHING');
        foreach ($files as $file) {
            $name = basename($file);
            preg_match('/^(\d+)/', $name, $m);
            $num = (int) ($m[1] ?? 0);
            if ($num <= 13) {
                $ins->execute([$name]);
                $applied[$name] = true;
            }
        }
    } else {
        echo "[migrate] tracking table vacía y schema parece fresco — corro todas\n";
    }
}

// 5. Aplicar pendientes. PDO::exec() para multi-statement (BEGIN/COMMIT incluidos)
// — funciona para PG cuando emulate_prepares=false y se usa exec, no prepare.
$pending = 0;
$markDone = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    echo "[migrate] aplicando: $name\n";

    try {
        if (str_ends_with($name, '.sql')) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                fwrite(STDERR, "[migrate] ERROR leyendo $file\n");
                exit(1);
            }
            $pdo->exec($sql);
        } elseif (str_ends_with($name, '.php')) {
            $GLOBALS['migrationPdo'] = $pdo;
            require $file;
            unset($GLOBALS['migrationPdo']);
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "[migrate] FAILED: $name\n");
        fwrite(STDERR, "[migrate] PG error: " . $e->getMessage() . "\n");
        exit(1);
    }

    $markDone->execute([$name]);
    echo "[migrate] OK: $name\n";
    $pending++;
}

if ($pending === 0) {
    echo "[migrate] todo al día\n";
} else {
    echo "[migrate] $pending migración(es) aplicada(s)\n";
}

exit(0);
