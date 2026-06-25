<?php
/**
 * Backfill: computar SHA-256 de los PINs existentes en lockpass.
 *
 * Usa $GLOBALS['migrationPdo']. SELECT users con lockpass IS NOT NULL AND pinhash IS NULL.
 * Para cada uno, computar hash('sha256', $lockpass) (hex 64 chars) y UPDATE pinhash.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate 56] migrationPdo no disponible\n");
    return;
}

try {
    $stmt = $pdo->query('SELECT contactid, lockpass FROM contact WHERE lockpass IS NOT NULL AND pinhash IS NULL');
    $count = 0;
    foreach ($stmt as $row) {
        $hash = hash('sha256', (string) $row['lockpass']);
        $upd  = $pdo->prepare('UPDATE contact SET pinhash = ? WHERE contactid = ?::uuid');
        $upd->execute([$hash, $row['contactid']]);
        $count++;
    }
    echo "[migrate 56] backfill pinhash sha256: $count rows\n";
} catch (Exception $e) {
    fwrite(STDERR, "[migrate 56] error: " . $e->getMessage() . "\n");
}
