<?php
/**
 * Marca períodos sucios para el reconcile del rollup. Best-effort, nunca lanza.
 */
function rollupMarkDirty(string $companyId, array $domains, string $dateYmd): void
{
    global $db;
    if (!isset($db) || !is_object($db) || $companyId === '') return;
    $day = substr($dateYmd, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return;
    foreach ($domains as $domain) {
        try {
            $db->Execute(
                'INSERT INTO rollup_dirty (companyId, domain, periodDay)
                 VALUES (?, ?, ?::date) ON CONFLICT DO NOTHING',
                [$companyId, $domain, $day]
            );
        } catch (\Throwable $e) {
            error_log('[rollup] markDirty: ' . $e->getMessage());
        }
    }
}
