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
 */

declare(strict_types=1);

// Setup mínimo: necesitamos solo la conexión PG, no todo el bootstrap del app.
// Cargamos simple.config.php (env vars + constantes) y db.php (instancia $db
// global). NO cargamos functions.php — son 10k líneas que no necesitamos acá.
$repoRoot = dirname(__DIR__);

require_once $repoRoot . '/app/includes/simple.config.php';
require_once $repoRoot . '/app/includes/db.php';

/** @var \ADOConnection $db */
global $db;

if (!$db || !$db->IsConnected()) {
    fwrite(STDERR, "[migrate] No PG connection — verificá POSTGRES_* env vars\n");
    exit(1);
}

// 1. Tabla de tracking.
$db->Execute(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   TEXT PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )"
);

// 2. Listado de migrations files sorted numéricamente.
$dir = __DIR__ . '/migrations/postgres';
$files = glob($dir . '/*.sql') ?: [];
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
$res = $db->Execute('SELECT filename FROM schema_migrations');
if ($res) {
    while (!$res->EOF) {
        $applied[(string) $res->fields['filename']] = true;
        $res->MoveNext();
    }
}

// 4. Bootstrap one-time: si la tracking table está vacía y la BD parece "vieja"
// (pre-14), marcamos 01-13 como already-applied. Detección: si existe la columna
// `outletAddress` en la tabla `outlet`, asumimos que sigue pre-migración-14 → las
// migraciones 01-13 ya estaban aplicadas via flujo manual. Si la columna no existe
// pero schema_migrations está vacía, es DB fresca → corremos todo desde 01.
if (!$applied) {
    $check = $db->Execute(
        "SELECT 1 FROM information_schema.columns
         WHERE table_name = 'outlet' AND column_name = 'outletaddress'"
    );
    $isExistingDB = ($check && !$check->EOF);
    if ($isExistingDB) {
        echo "[migrate] bootstrap: detectada BD existente (pre-migración 14)\n";
        echo "[migrate] bootstrap: marcando migraciones 01-13 como already-applied\n";
        foreach ($files as $file) {
            $name = basename($file);
            preg_match('/^(\d+)/', $name, $m);
            $num = (int) ($m[1] ?? 0);
            if ($num <= 13) {
                $db->Execute(
                    'INSERT INTO schema_migrations (filename) VALUES (?) ON CONFLICT DO NOTHING',
                    [$name]
                );
                $applied[$name] = true;
            }
        }
    } else {
        echo "[migrate] tracking table vacía y schema parece fresco — corro todas\n";
    }
}

// 5. Aplicar pendientes. Usamos pg_query directo porque ADOdb's Execute no
// maneja bien múltiples statements separados por ; (un .sql con BEGIN; ...; COMMIT;
// se parsea como 1 sentencia pero contiene varias). pg_query SÍ las acepta.
$rawConn = $db->_connectionID;
if (!$rawConn) {
    fwrite(STDERR, "[migrate] No raw PG connection disponible\n");
    exit(1);
}

$pending = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    echo "[migrate] aplicando: $name\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "[migrate] ERROR leyendo $file\n");
        exit(1);
    }

    $result = @pg_query($rawConn, $sql);
    if ($result === false) {
        $err = pg_last_error($rawConn);
        fwrite(STDERR, "[migrate] FAILED: $name\n");
        fwrite(STDERR, "[migrate] PG error: $err\n");
        exit(1);
    }

    $db->Execute('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
    echo "[migrate] OK: $name\n";
    $pending++;
}

if ($pending === 0) {
    echo "[migrate] todo al día\n";
} else {
    echo "[migrate] $pending migración(es) aplicada(s)\n";
}

exit(0);
