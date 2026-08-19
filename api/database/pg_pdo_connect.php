<?php

declare(strict_types=1);

/**
 * pg_pdo_connect.php — conexión PDO directa a Postgres a partir de las env
 * vars POSTGRES_HOST/PORT/DB/USER/PASSWORD (o DATABASE_URL estilo Coolify
 * managed), sin pasar por el wrapper de la app (que hace die() en error de
 * conexión — inútil para un script que necesita loguear y salir con código
 * de error controlado).
 *
 * Extraído de migrate.php (el runner de migraciones) cuando
 * verify_pg_identifier_catalog.php necesitó la MISMA lógica de conexión —
 * dos call-sites duplicando esto es la señal de atacar el wrapper
 * compartido, no de copiar-pegar un tercero (regla del proyecto).
 */

/** Carga .env del repo en $_ENV si existe (no pisa env vars ya seteadas). */
function pgLoadEnvFile(string $repoRoot): void
{
    $envFile = $repoRoot . '/.env';
    if (!is_file($envFile)) {
        return;
    }
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

/** Si DATABASE_URL está seteada, la descompone en POSTGRES_* dentro de $_ENV. */
function pgExpandDatabaseUrl(): void
{
    if (empty($_ENV['DATABASE_URL'])) {
        return;
    }
    $u = parse_url((string) $_ENV['DATABASE_URL']);
    $_ENV['POSTGRES_HOST']     = $u['host'] ?? 'localhost';
    $_ENV['POSTGRES_USER']     = isset($u['user']) ? urldecode($u['user']) : 'punto';
    $_ENV['POSTGRES_PASSWORD'] = isset($u['pass']) ? urldecode($u['pass']) : '';
    $_ENV['POSTGRES_DB']       = isset($u['path']) ? ltrim($u['path'], '/') : 'puntoDB';
    $_ENV['POSTGRES_PORT']     = $u['port'] ?? 5432;
}

/**
 * Carga .env + expande DATABASE_URL + conecta. Lanza PDOException si la
 * conexión falla — el caller decide cómo reportar (migrate.php sale 1 sin
 * arrancar el container; el verificador sale 1 con su propio mensaje).
 */
function pgConnectFromEnv(string $repoRoot): PDO
{
    pgLoadEnvFile($repoRoot);
    pgExpandDatabaseUrl();

    $host = $_ENV['POSTGRES_HOST']     ?? 'localhost';
    $user = $_ENV['POSTGRES_USER']     ?? 'punto';
    $pass = $_ENV['POSTGRES_PASSWORD'] ?? '';
    $dbnm = $_ENV['POSTGRES_DB']       ?? 'puntoDB';
    $port = (int) ($_ENV['POSTGRES_PORT'] ?? 5432);

    return new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbnm),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}
