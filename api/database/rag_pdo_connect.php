<?php

declare(strict_types=1);

/**
 * rag_pdo_connect.php — conexión PDO a la base del RAG (pgvector), que es una
 * base APARTE de la de los tenants. Ver context/82 D4.
 *
 * ── Por qué NO reusa `pg_pdo_connect.php` ni el wrapper `$db` ──────────────
 *
 * `pgConnectFromEnv()` lee `POSTGRES_*` / `DATABASE_URL`: esas variables
 * apuntan a la base de los TENANTS. Reusarla acá significaría, con la
 * configuración del RAG a medio cargar, escribir `help_document` en la base de
 * producción de los comercios — que es exactamente lo que D4 decidió evitar.
 * El aislamiento tiene que estar en el ORIGEN de las credenciales, no en la
 * disciplina de quien llama.
 *
 * Por el mismo motivo `$db` (el wrapper de la app) queda afuera: está atado a
 * la conexión del tenant y además hace `die()` al fallar, que es lo contrario
 * de lo que el RAG necesita (ver el fail-soft de `migrate_rag.php`).
 *
 * ── Configuración: UNA sola variable ───────────────────────────────────────
 *
 * `RAG_DATABASE_URL`, la url completa tal como la entrega Coolify
 * (`postgres://usuario:clave@host:5432/base`). No hay juego host/puerto/
 * usuario/clave por separado: son cinco formas de quedar a medio configurar
 * donde alcanza con una.
 *
 * Vacía o ausente = la base todavía no existe. NO es un error: quien pregunta
 * degrada y lo dice en pantalla.
 */

require_once __DIR__ . '/pg_pdo_connect.php';

/**
 * Url de conexión del RAG.
 *
 * Constante si el caller cargó `includes/simple.config.php`, y si no el
 * entorno. El fallback no es defensivo de más: los endpoints de /admin NO
 * pasan por `bootstrap.php`, así que ahí `defined()` da false aunque la
 * variable esté presente en el contenedor. Exactamente eso hizo que el botón
 * "Probar" de /admin/ai reportara "no configurada" con la clave puesta
 * (2026-09-08). El arreglo de raíz es que el resolvedor no dependa de qué
 * archivo cargó quien lo llama.
 */
function ragDatabaseUrl(?string $repoRoot = null): string
{
    if ($repoRoot !== null) {
        pgLoadEnvFile($repoRoot);
    }
    if (defined('RAG_DATABASE_URL') && RAG_DATABASE_URL !== '') {
        return (string) RAG_DATABASE_URL;
    }
    return trim((string) ($_ENV['RAG_DATABASE_URL'] ?? getenv('RAG_DATABASE_URL') ?: ''));
}

/**
 * Modelo de embeddings configurado. FIJO por índice — ver D5.
 * Mismo criterio de resolución que la url.
 */
function ragEmbeddingModel(): string
{
    if (defined('RAG_EMBEDDING_MODEL') && RAG_EMBEDDING_MODEL !== '') {
        return (string) RAG_EMBEDDING_MODEL;
    }
    $fromEnv = trim((string) ($_ENV['RAG_EMBEDDING_MODEL'] ?? getenv('RAG_EMBEDDING_MODEL') ?: ''));
    return $fromEnv !== '' ? $fromEnv : 'openai/text-embedding-3-small';
}

/**
 * true si hay base del RAG configurada. Que devuelva false NO es un error: es
 * "esta instalación todavía no tiene base de conocimiento", y quien pregunta
 * lo muestra como tal.
 */
function ragIsConfigured(?string $repoRoot = null): bool
{
    return ragDatabaseUrl($repoRoot) !== '';
}

/**
 * Conecta a la base del RAG.
 *
 * @throws RuntimeException si no está configurada.
 * @throws PDOException     si está configurada pero no responde.
 *
 * `$repoRoot` es la raíz de `api/`, para leer el `.env` local en desarrollo.
 */
function ragConnectFromEnv(string $repoRoot, int $timeoutSeconds = 5): PDO
{
    $url = ragDatabaseUrl($repoRoot);
    if ($url === '') {
        throw new RuntimeException('RAG_NOT_CONFIGURED');
    }

    $u = parse_url($url);
    if (!is_array($u) || empty($u['host'])) {
        throw new RuntimeException('RAG_DATABASE_URL malformada');
    }

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
            (string) $u['host'],
            (int) ($u['port'] ?? 5432),
            isset($u['path']) ? ltrim((string) $u['path'], '/') : 'puntorag',
            $timeoutSeconds
        ),
        isset($u['user']) ? urldecode((string) $u['user']) : 'punto',
        isset($u['pass']) ? urldecode((string) $u['pass']) : '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}
