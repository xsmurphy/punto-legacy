<?php

/**
 * migrate_rag.php — runner de migraciones de la base del RAG (pgvector).
 *
 * Separado de `migrate.php` a propósito (context/82 §4): aquel apunta a la
 * base de los TENANTS y lee `migrations/postgres/`. Este apunta a la base
 * pgvector aparte y lee `migrations/rag/`, con su PROPIA tabla de tracking
 * (`rag_schema_migrations`). Ninguno de los dos puede tocar la base del otro
 * ni por error: las credenciales salen de variables distintas
 * (`RAG_*` vs `POSTGRES_*`) y los directorios no se solapan.
 *
 * ── FAIL-SOFT: la diferencia de fondo con migrate.php ──────────────────────
 *
 * `migrate.php` es fail-fast: si una migración falla, el contenedor NO
 * arranca. Ahí es lo correcto — servir contra el schema de los tenants a
 * medio migrar corrompe datos de comercios que están facturando.
 *
 * Acá es al revés y es una decisión de diseño, no un descuido. El RAG es
 * AUXILIAR: alimenta las respuestas de Punto AI sobre cómo se usa el producto.
 * Si su base no responde —todavía no existe en Coolify, está reiniciando, le
 * falta una env var— lo único que se pierde es que el asistente no sepa
 * explicar una pantalla. Tirar abajo la API por eso dejaría sin facturar a
 * todos los comercios para proteger una feature de ayuda. Loguea y sigue.
 *
 * Y la base del RAG se reconstruye entera reindexando (D4: ni siquiera
 * necesita backup), así que un arranque sin ella no deja nada inconsistente.
 *
 * Convención de filenames: `NN_description.sql`, sort numérico, igual que el
 * runner de los tenants.
 *
 * Exit code: SIEMPRE 0. El entrypoint no debe cortar por este script.
 */

declare(strict_types=1);

require_once __DIR__ . '/rag_pdo_connect.php';

$repoRoot = dirname(__DIR__);

try {
    if (!ragIsConfigured($repoRoot)) {
        echo "[migrate-rag] RAG_DATABASE_URL vacía — la base de conocimiento todavía no existe, skip\n";
        exit(0);
    }
    $pdo = ragConnectFromEnv($repoRoot);
} catch (Throwable $e) {
    // Fail-soft: ver docblock. Se avisa fuerte en el log y el boot sigue.
    fwrite(STDERR, "[migrate-rag] no se pudo conectar a la base del RAG: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[migrate-rag] el backend arranca igual — la base de conocimiento queda sin actualizar\n");
    exit(0);
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rag_schema_migrations (
            filename   TEXT PRIMARY KEY,
            applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
        )"
    );

    $dir   = __DIR__ . '/migrations/rag';
    $files = glob($dir . '/*.sql') ?: [];
    usort($files, static function (string $a, string $b): int {
        preg_match('/^(\d+)/', basename($a), $ma);
        preg_match('/^(\d+)/', basename($b), $mb);
        return (int) ($ma[1] ?? 0) - (int) ($mb[1] ?? 0);
    });

    if (!$files) {
        echo "[migrate-rag] no hay archivos en $dir — nothing to do\n";
        exit(0);
    }

    $applied = [];
    foreach ($pdo->query('SELECT filename FROM rag_schema_migrations') as $row) {
        $applied[(string) $row['filename']] = true;
    }

    $pending  = 0;
    $markDone = $pdo->prepare('INSERT INTO rag_schema_migrations (filename) VALUES (?)');
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }

        echo "[migrate-rag] aplicando: $name\n";
        $sql = file_get_contents($file);
        if ($sql === false) {
            fwrite(STDERR, "[migrate-rag] ERROR leyendo $file — corto acá, el resto queda pendiente\n");
            exit(0);
        }
        $pdo->exec($sql);
        $markDone->execute([$name]);
        echo "[migrate-rag] OK: $name\n";
        $pending++;
    }

    echo $pending === 0
        ? "[migrate-rag] todo al día\n"
        : "[migrate-rag] $pending migración(es) aplicada(s)\n";
} catch (Throwable $e) {
    // Una migración del RAG que falla deja su base a medio armar, pero no toca
    // nada de los tenants. Se reporta y se sigue: el índice degradado se nota
    // en /admin (documentos con error de indexado), no en la caja.
    fwrite(STDERR, "[migrate-rag] FALLÓ: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[migrate-rag] el backend arranca igual — revisar la base del RAG\n");
}

exit(0);
