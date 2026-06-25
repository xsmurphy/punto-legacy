<?php
// Backfill: hashear lockpass en BCrypt para todos los contactos que aún no tienen lockpasshash.
// Corre via migrate.php; no depende del bootstrap del app.
// Nota: la columna se creó con quotes en migration 49 → "lockPassHash" (camelCase en BD).

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate] ERROR: migrationPdo no disponible\n");
    return;
}

$stmt = $pdo->query('SELECT contactid, lockpass FROM contact WHERE lockpass IS NOT NULL AND "lockPassHash" IS NULL');
$count = 0;
foreach ($stmt as $row) {
    $hash = password_hash((string) $row['lockpass'], PASSWORD_BCRYPT);
    $upd = $pdo->prepare('UPDATE contact SET "lockPassHash" = ? WHERE contactid = ?::uuid');
    $upd->execute([$hash, $row['contactid']]);
    $count++;
}
echo "[migrate] backfill lockpasshash: $count rows hashed\n";
