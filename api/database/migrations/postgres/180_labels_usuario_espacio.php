<?php
/**
 * Migration 180 — Corrección de vocabulario en los títulos auto-puestos:
 * "Cajero:" → "Usuario:" y "Mesa:" → "Espacio:" (owner, 2026-08-29).
 *
 * Solo renombra los valores EXACTOS que estamparon las migs 174/178/179 — un
 * título que el comercio editó a mano no coincide con el literal y no se toca.
 * El catálogo del editor (DEFAULT_BLOCK_LABELS) cambia en el mismo commit, así
 * que bloque nuevo y dato viejo dicen lo mismo.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR 180: migrationPdo no disponible\n");
    return;
}

/** [tipo de bloque, título viejo, título nuevo] */
const RENAMES_180 = [
    ['user_name',    'Cajero:', 'Usuario:'],
    ['table_number', 'Mesa:',   'Espacio:'],
];

try {
    $pdo->beginTransaction();

    $rows = $pdo->query("SELECT templateid, config FROM document_template FOR UPDATE")
        ->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare(
        "UPDATE document_template SET config = ?::jsonb, updated_at = now() WHERE templateid = ?"
    );

    $patched = 0;
    foreach ($rows as $row) {
        $config = json_decode((string) ($row['config'] ?? '{}'), true);
        if (!is_array($config)) $config = [];
        $blocks = isset($config['data']) && is_array($config['data']) ? $config['data'] : [];

        $touched = false;
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) continue;
            foreach (RENAMES_180 as [$type, $from, $to]) {
                if (($block['type'] ?? '') === $type && ($block['label'] ?? '') === $from) {
                    $blocks[$idx]['label'] = $to;
                    $touched = true;
                }
            }
        }
        if (!$touched) continue;

        $config['data'] = $blocks;
        $update->execute([
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $row['templateid'],
        ]);
        $patched++;
    }

    $pdo->commit();
    fwrite(STDOUT, "[migrate] 180: $patched plantillas renombradas\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "[migrate] ERROR 180: " . $e->getMessage() . "\n");
    throw $e;
}
