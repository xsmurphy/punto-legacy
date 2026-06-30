<?php
declare(strict_types=1);

/**
 * Seed idempotente del super-admin de PLATAFORMA (tabla admin_user, realm /admin).
 *
 * Corre en CADA boot del container (docker-entrypoint, después de migrate). Lee
 * ADMIN_EMAIL + ADMIN_PASSWORD de env. No-op si faltan o si el admin ya existe.
 *
 * Por qué un script aparte (no una migración tracked): las migraciones corren una
 * sola vez; si el env var se setea DESPUÉS, nunca se sembraría. Esto corre cada
 * deploy y es idempotente (skip si el email ya existe).
 *
 * El password NUNCA vive en git — se setea en Coolify (ADMIN_PASSWORD). bcrypt
 * (password_hash / PASSWORD_DEFAULT), igual que AdminAuth::adminVerifyPassword.
 */

// ── Cargar .env del repo si está (Coolify suele inyectar env directos) ──────────
$repoRoot = dirname(__DIR__); // /var/www/api (database/ vive bajo api/)
$envFile  = $repoRoot . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $value = trim($m[2]);
        $len   = strlen($value);
        if ($len >= 2) {
            $f = $value[0];
            $e = $value[$len - 1];
            if (($f === '"' && $e === '"') || ($f === "'" && $e === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (!isset($_ENV[$m[1]])) {
            $_ENV[$m[1]] = $value;
        }
    }
}

$email = trim((string) ($_ENV['ADMIN_EMAIL'] ?? getenv('ADMIN_EMAIL') ?: ''));
$passw = (string) ($_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: '');

if ($email === '' || $passw === '') {
    fwrite(STDERR, "[seed-admin] ADMIN_EMAIL/ADMIN_PASSWORD no seteados — skip seed admin_user\n");
    exit(0);
}

// ── Conexión (mismo patrón que migrate.php; soporta DATABASE_URL de Coolify) ────
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

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbnm),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // Best-effort: no abortamos el boot por el seed admin.
    fwrite(STDERR, "[seed-admin] conexión falló (ignorado): " . $e->getMessage() . "\n");
    exit(0);
}

try {
    $st = $pdo->prepare('SELECT 1 FROM admin_user WHERE lower(email) = lower(?) LIMIT 1');
    $st->execute([$email]);
    if ($st->fetchColumn()) {
        fwrite(STDERR, "[seed-admin] admin '$email' ya existe — skip\n");
        exit(0);
    }

    $hash = password_hash($passw, PASSWORD_DEFAULT);
    $ins  = $pdo->prepare('INSERT INTO admin_user (email, passwordHash, name, status) VALUES (?, ?, ?, 1)');
    $ins->execute([$email, $hash, 'Super Admin']);
    fwrite(STDERR, "[seed-admin] admin_user '$email' creado (realm /admin)\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "[seed-admin] error (ignorado): " . $e->getMessage() . "\n");
}

exit(0);
