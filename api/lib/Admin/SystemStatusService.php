<?php

/**
 * SystemStatusService.php — estado del sistema para admin/system (F6 §4,
 * context/34-admin-saas-plan.md). Solo lectura, solo rol 'owner'.
 *
 * Fuentes:
 *   - Versión: APP_VERSION de api/app_version.php + mtime del archivo (proxy
 *     de "cuándo se deployó" — no hay commit hash expuesto por el backend hoy).
 *   - Migraciones: últimas 10 filas de `schema_migrations` (mig.php las trackea).
 *   - Counts: tenants (company), usuarios (contact type=0, todos los tenants),
 *     transacciones de HOY (transaction.transactionDate = hoy).
 *   - Sentry: solo si SENTRY_DSN está seteado en el server — expone un link
 *     genérico a sentry.io, NUNCA llama a la API de Sentry (fuera de alcance F6).
 */
final class SystemStatusService
{
    public function status(): array
    {
        return [
            'version'      => $this->version(),
            'migrations'   => $this->recentMigrations(),
            'counts'       => $this->counts(),
            'sentry'       => $this->sentry(),
        ];
    }

    private function version(): array
    {
        $file = dirname(__DIR__, 2) . '/app_version.php';
        $version = 'desconocida';
        $deployedAt = null;

        if (is_file($file)) {
            // No se puede require() dos veces sin proteger la constante — pero
            // app_version.php ya se requiere en bootstrap.php en el request
            // normal. Leemos el valor de la constante si ya está definida;
            // si no (ej. CLI/test), lo parseamos del archivo sin ejecutar código.
            if (defined('APP_VERSION')) {
                $version = APP_VERSION;
            } else {
                $contents = (string) file_get_contents($file);
                if (preg_match("/define\\('APP_VERSION'\\s*,\\s*'([^']+)'\\)/", $contents, $m)) {
                    $version = $m[1];
                }
            }
            $mtime = filemtime($file);
            $deployedAt = $mtime !== false ? date('c', $mtime) : null;
        }

        return ['appVersion' => $version, 'deployedAt' => $deployedAt];
    }

    private function recentMigrations(): array
    {
        global $db;
        $out = [];
        try {
            $r = $db->Execute('SELECT filename, applied_at FROM schema_migrations ORDER BY applied_at DESC, filename DESC LIMIT 10');
            if ($r) {
                while (!$r->EOF) {
                    $out[] = [
                        'filename'  => (string) $r->fields['filename'],
                        'appliedAt' => $r->fields['applied_at'] ?? null,
                    ];
                    $r->MoveNext();
                }
            }
        } catch (\Throwable $e) {
            error_log('[SystemStatusService] recentMigrations falló: ' . $e->getMessage());
        }
        return $out;
    }

    private function counts(): array
    {
        global $db;
        $tenants = 0;
        $users   = 0;
        $txToday = 0;
        try {
            $r = $db->Execute('SELECT count(*) AS n FROM company');
            $tenants = ($r && !$r->EOF) ? (int) $r->fields['n'] : 0;

            $r = $db->Execute('SELECT count(*) AS n FROM contact WHERE type = 0');
            $users = ($r && !$r->EOF) ? (int) $r->fields['n'] : 0;

            $r = $db->Execute("SELECT count(*) AS n FROM transaction WHERE transactionDate::date = CURRENT_DATE");
            $txToday = ($r && !$r->EOF) ? (int) $r->fields['n'] : 0;
        } catch (\Throwable $e) {
            error_log('[SystemStatusService] counts falló: ' . $e->getMessage());
        }
        return ['tenants' => $tenants, 'users' => $users, 'transactionsToday' => $txToday];
    }

    private function sentry(): array
    {
        $dsn = getenv('SENTRY_DSN') ?: ($_ENV['SENTRY_DSN'] ?? '');
        return [
            'configured' => $dsn !== '',
            // Link genérico — NUNCA se llama a la API de Sentry desde acá (fuera de alcance F6).
            'link'       => $dsn !== '' ? 'https://sentry.io/' : null,
        ];
    }
}
