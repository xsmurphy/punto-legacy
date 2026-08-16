<?php

declare(strict_types=1);

/**
 * verify_sync.php — arnés chico que demuestra, sin mockear nada, el sync
 * incremental del POS (context/43-sync-incremental.md, `SyncService`):
 *
 *   1. Modificar un item y pedir el delta desde una fecha ANTERIOR a esa
 *      modificación devuelve EXACTAMENTE ese item (con el dato nuevo) — no
 *      el catálogo completo. Demuestra `COALESCE(updated_at, itemDate) > ?`
 *      + el índice `idx_item_updated`.
 *   2. Archivar + hard-delete un item lo hace aparecer en `deletedIds` del
 *      mismo delta — demuestra el trigger `trg_item_tombstone` (mig 138,
 *      tabla `deleted_row`) sin el cual un borrado real quedaría invisible
 *      para siempre en un `WHERE updated_at > $fecha`.
 *   3. `since = null` y `since` más viejo que la retención de lápidas (90
 *      días, `SyncService::TOMBSTONE_RETENTION_DAYS`) devuelven `full =
 *      true` — el caller debe hacer bootstrap completo, nunca confiar en
 *      cobertura parcial.
 *
 * Requiere el mismo Postgres migrado+seedeado que run_sale_chain.php/
 * verify_realtime.php (seed.sql, items VERIFY-SYNC-MODIFY/VERIFY-SYNC-DELETE).
 * Uso: ver run.sh, que lo invoca como paso 3.6 con las mismas env vars
 * POSTGRES_*. Corre DESPUÉS de verify_realtime.php a propósito: ese paso ya
 * mueve stock (bumpea `updated_at` de los items VERIFY-STOCK-TRACK*), así
 * que capturar el watermark acá adentro (no antes) evita que esos
 * movimientos ajenos contaminen el conteo esperado del delta.
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

use Punto\Api\Items\ItemRepository;
use Punto\Api\Items\ItemService;
use Punto\Api\Sync\SyncService;

require_once dirname(__DIR__, 3) . '/lib/Items/ItemsQuery.php';
require_once dirname(__DIR__, 3) . '/lib/Sync/SyncService.php';

$PY_COMPANY = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET  = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_USER    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$MODIFY_ID  = '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c6d'; // VERIFY-SYNC-MODIFY, ver seed.sql
$DELETE_ID  = '9b2e6a1c-4f3d-4a5b-8c6d-1e2f3a4b5c6e'; // VERIFY-SYNC-DELETE, ver seed.sql

// Reconstruye lo que apiAuthTenant() hace en un request real, sin JWT —
// mismo patrón que verify_realtime.php/run_sale_chain.php. Sin esto, TODAY
// (usada por ItemService::update()/ItemRepository::archive()) no está
// definida y el arnés revienta con "Undefined constant".
$companyId  = $PY_COMPANY;
$outletId   = $PY_OUTLET;
$userId     = $PY_USER;
$registerId = null;
$roleId     = '1';
require API_APP_DIR . '/data.php';

global $db;
$failures = [];

$itemRepo    = new ItemRepository($db);
$itemService = new ItemService($itemRepo);
$sync        = new SyncService($db);

// Watermark ANTES de tocar nada en este arnés — 1 segundo atrás para no
// depender de que la mutación caiga en un segundo estrictamente posterior al
// de este `time()` (TODAY tiene granularidad de segundo, ver context/43).
$since = date('Y-m-d H:i:s', time() - 1);

// ── Caso 1+2: modificar uno, borrar otro ────────────────────────────────────
$okUpdate = $itemService->update($MODIFY_ID, $PY_COMPANY, ['itemPrice' => 9999]);
if (!$okUpdate) {
    $failures[] = 'Setup: no se pudo actualizar VERIFY-SYNC-MODIFY — revisar que el seed haya corrido';
}

$archived = $itemRepo->archive($DELETE_ID, $PY_COMPANY);
if (!$archived) {
    $failures[] = 'Setup: no se pudo archivar VERIFY-SYNC-DELETE';
}
$hardDeleted = $itemRepo->hardDelete($DELETE_ID, $PY_COMPANY);
if ($hardDeleted !== true) {
    $failures[] = 'Setup: hardDelete(VERIFY-SYNC-DELETE) no devolvió true — devolvió ' . var_export($hardDeleted, true);
}

$delta = $sync->itemsDelta($PY_COMPANY, $since);

if ($delta['full'] !== false) {
    $failures[] = 'Caso 1/2: esperaba full=false (delta chico, no cae al umbral de borde) — llegó ' . var_export($delta['full'], true);
} else {
    $ids = array_map(static fn($i) => $i['itemId'] ?? null, $delta['items']);
    if (count($delta['items']) !== 1 || !in_array($MODIFY_ID, $ids, true)) {
        $failures[] = 'Caso 1 (delta trae SOLO lo modificado): esperaba exactamente [' . $MODIFY_ID . '], llegó ' . json_encode($ids);
    } else {
        $modifiedRow = $delta['items'][0];
        if ((float) ($modifiedRow['itemPrice'] ?? 0) !== 9999.0) {
            $failures[] = 'Caso 1: el item modificado volvió con itemPrice viejo — ' . json_encode($modifiedRow['itemPrice'] ?? null);
        } else {
            echo "[verify_sync] OK caso 1: delta desde una fecha anterior trae SOLO el item modificado (no el catálogo completo)\n";
        }
    }

    if (!in_array($DELETE_ID, $delta['deletedIds'], true)) {
        $failures[] = 'Caso 2 (tombstone): esperaba ' . $DELETE_ID . ' en deletedIds, llegó ' . json_encode($delta['deletedIds']);
    } else {
        echo "[verify_sync] OK caso 2: item hard-deleted aparece en deletedIds (trigger trg_item_tombstone + mig 138)\n";
    }
}

// ── Caso 3: full=true cuando no hay watermark o está fuera de retención ────
$deltaNoSince = $sync->itemsDelta($PY_COMPANY, null);
if ($deltaNoSince['full'] !== true || $deltaNoSince['items'] !== [] || $deltaNoSince['deletedIds'] !== []) {
    $failures[] = 'Caso 3a (since=null): esperaba full=true con items/deletedIds vacíos, llegó ' . json_encode($deltaNoSince);
} else {
    echo "[verify_sync] OK caso 3a: since=null (instalación nueva) -> full=true, sin datos parciales\n";
}

$tooOld = date('Y-m-d H:i:s', strtotime('-' . (SyncService::TOMBSTONE_RETENTION_DAYS + 1) . ' days'));
$deltaTooOld = $sync->itemsDelta($PY_COMPANY, $tooOld);
if ($deltaTooOld['full'] !== true) {
    $failures[] = 'Caso 3b (since fuera de la ventana de retención de lápidas): esperaba full=true, llegó ' . json_encode($deltaTooOld['full']);
} else {
    echo "[verify_sync] OK caso 3b: since más viejo que la retención de lápidas -> full=true (no confía en cobertura parcial)\n";
}

if ($failures !== []) {
    fwrite(STDERR, "[verify_sync] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_sync] TODO OK\n";
exit(0);
