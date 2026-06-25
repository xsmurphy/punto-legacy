<?php
/**
 * Backfill one-off: hashea lockPass plano -> lockPassHash bcrypt.
 *
 * Run desde la raiz del proyecto:
 *   php database/migrations/postgres/49_lockpass_hash_backfill.php
 *
 * Require minimo: head.php que trae $db + ncmExecute.
 * NO incluir cors.php ni session -- esto corre en CLI.
 */

// database/migrations/postgres/ esta 3 niveles bajo la raiz del proyecto
define('API_APP_DIR', dirname(__DIR__, 3) . '/app');
chdir(API_APP_DIR);

require_once API_APP_DIR . '/head.php';

$rs = ncmExecute(
    'SELECT "contactId", "lockPass" FROM contact WHERE "lockPass" IS NOT NULL AND "lockPassHash" IS NULL',
    [],
    false,
    true
);

$count = 0;
if ($rs) {
    while (!$rs->EOF) {
        $row = (array) $rs->fields;
        $cid = (string) ($row['contactid'] ?? $row['contactId'] ?? '');
        $pin = (string) ($row['lockpass']  ?? $row['lockPass']  ?? '');
        if ($cid === '' || $pin === '') {
            $rs->MoveNext();
            continue;
        }

        $bcrypt = password_hash($pin, PASSWORD_BCRYPT);
        ncmExecute(
            'UPDATE contact SET "lockPassHash" = ? WHERE "contactId" = ?::uuid',
            [$bcrypt, $cid]
        );
        echo "Hashed contactId={$cid}\n";
        $count++;
        $rs->MoveNext();
    }
}
echo "Done. Total: {$count} rows hashed.\n";
