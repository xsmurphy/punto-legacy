<?php
declare(strict_types=1);

/**
 * Test de humo (DB real) del "row movement" del particionado mensual de
 * `transaction`/`itemSold` (E1, mig 156 —
 * `api/database/migrations/postgres/156_partition_transaction_itemsold.sql`).
 * Mismo patrón que `sale_void_test.php`: reusa el tenant fixture "Verify PY"
 * (`api/lib/Sales/verify_chain/seed.sql`), inserta sus propias filas por SQL
 * directo, y corre contra Postgres real porque lo que se verifica es
 * comportamiento del motor (particiones + FK + triggers de sync), no lógica
 * de aplicación.
 *
 * Qué prueba (D3 de context/48-escalamiento-de-datos.md, verificación
 * pendiente de la mig 156):
 *   1. Insertar una transacción con 1 itemSold y 1 vPayments.
 *   2. `UPDATE transaction SET transactiondate = transactiondate + interval
 *      '1 month'` — cruce de mes, dispara "row movement" entre particiones.
 *   3. La fila de `transaction_registry` (unicidad global post mig 156)
 *      sigue existiendo con la fecha nueva (el trigger
 *      `trg_transaction_registry_sync_update` la sincronizó).
 *   4. `itemSold`/`vPayments` siguen apuntando a la misma `transactionId`
 *      (la FK hacia `transaction_registry` no se rompió con el movimiento).
 *   5. La fila de `transaction` vive ahora en la partición del MES
 *      SIGUIENTE (`tableoid::regclass` cambia de `..._yYYYYmMM` a la del
 *      mes de destino) — confirma que Postgres trata el UPDATE que cruza de
 *      rango como movimiento real de partición, no un no-op.
 *
 * Uso (necesita Postgres migrado + seed.sql de verify_chain cargado — ver
 * `run_partition_row_movement_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/partition_row_movement_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$stockItemId = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f'; // "Verify stock trackeable"

$failures = 0;

function check(string $label, bool $ok, string $detail, int &$failures): void
{
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/** Nombre de partición mensual que le corresponde a una fecha, según la
 *  convención de `ensure_month_partitions()` (`<tabla>_yYYYYmMM`). */
function monthPartitionName(string $bareTable, string $dateStr): string
{
    $ts = strtotime($dateStr);
    return $bareTable . '_y' . date('Y', $ts) . 'm' . date('m', $ts);
}

global $db;

// ── 1. Insertar transacción + itemSold + vPayments ──────────────────────
$originalDate = date('Y-m-d H:i:s');
$transactionUid = 'verify-partition-row-move-' . bin2hex(random_bytes(8));

$db->AutoExecute('transaction', [
    'transactionTotal'       => 1000,
    'transactionDiscount'    => 0,
    'transactionUnitsSold'   => 1,
    'transactionType'        => 0,
    'transactionComplete'    => true,
    'transactionStatus'      => 1,
    'transactionDate'        => $originalDate,
    'transactionUID'         => $transactionUid,
    'transactionPaymentType' => json_encode([['type' => 'cash', 'price' => 1000, 'total' => 1000]]),
    'invoiceNo'              => random_int(1000000, 9999999),
    'timestamp'              => time(),
    'registerId'             => $registerId,
    'userId'                 => $userId,
    'responsibleId'          => $userId,
    'outletId'               => $outletId,
    'companyId'              => $companyId,
], 'INSERT');
$transactionId = (string) $db->Insert_ID();

check('setup: transacción insertada', $transactionId !== '', 'transactionId=' . $transactionId, $failures);

$db->AutoExecute('itemSold', [
    'itemId'        => $stockItemId,
    'transactionId' => $transactionId,
    'itemSoldUnits' => 1,
    'itemSoldTotal' => 1000,
    'itemSoldCOGS'  => 100,
    'itemSoldDate'  => $originalDate,
    // D4 de context/48-escalamiento-de-datos.md (mig 156).
    'companyId'     => $companyId,
    'outletId'      => $outletId,
    'registerId'    => $registerId,
], 'INSERT');
$itemSoldId = (string) $db->Insert_ID();

$db->AutoExecute('vPayments', [
    'date'          => $originalDate,
    'amount'        => 1000,
    'orderNo'       => 'verify-partition-' . bin2hex(random_bytes(4)),
    'status'        => 'approved',
    'source'        => 'verify_partition_row_movement_test',
    'transactionId' => $transactionId,
    'outletId'      => $outletId,
    'companyId'     => $companyId,
], 'INSERT');
$vPaymentsId = (string) $db->Insert_ID();

check('setup: itemSold insertado', $itemSoldId !== '', 'itemSoldId=' . $itemSoldId, $failures);
check('setup: vPayments insertado', $vPaymentsId !== '', 'vPaymentsId=' . $vPaymentsId, $failures);

// ── 2. Estado ANTES del cruce de mes ─────────────────────────────────────
$registryBefore = ncmExecute(
    'SELECT transactiondate FROM transaction_registry WHERE transactionid = ?',
    [$transactionId]
);
check(
    'antes: transaction_registry tiene la fila con la fecha original',
    $registryBefore !== false && $registryBefore['transactiondate'] !== null,
    json_encode($registryBefore),
    $failures
);

$partitionBefore = ncmExecute(
    "SELECT tableoid::regclass::text AS part FROM transaction WHERE transactionid = ?",
    [$transactionId]
);
$expectedPartBefore = monthPartitionName('transaction', $originalDate);
check(
    'antes: la fila vive en la partición del mes original (' . $expectedPartBefore . ')',
    $partitionBefore !== false && str_contains((string) ($partitionBefore['part'] ?? ''), $expectedPartBefore),
    json_encode($partitionBefore),
    $failures
);

// ── 3. Cruce de mes: UPDATE que mueve la fila de partición ──────────────
$newDate = date('Y-m-d H:i:s', strtotime($originalDate . ' +1 month'));
$updateResult = $db->Execute('UPDATE transaction SET transactiondate = ? WHERE transactionid = ?', [$newDate, $transactionId]);
check('UPDATE transactiondate +1 mes ejecutado sin error', $updateResult !== false, (string) $db->ErrorMsg(), $failures);

// ── 4. Estado DESPUÉS: registry sigue vivo, con la fecha nueva ──────────
$registryAfter = ncmExecute(
    'SELECT transactiondate FROM transaction_registry WHERE transactionid = ?',
    [$transactionId]
);
check(
    'después: transaction_registry SIGUE existiendo (FK sobrevivió al row movement)',
    $registryAfter !== false,
    json_encode($registryAfter),
    $failures
);
check(
    'después: transaction_registry.transactiondate se sincronizó a la fecha nueva',
    $registryAfter !== false
        && substr((string) $registryAfter['transactiondate'], 0, 10) === substr($newDate, 0, 10),
    'registry=' . json_encode($registryAfter) . ' esperado=' . $newDate,
    $failures
);

// ── 5. itemSold / vPayments siguen apuntando a la misma transacción ────
$itemSoldAfter = ncmExecute('SELECT transactionid FROM itemSold WHERE itemsoldid = ?', [$itemSoldId]);
check(
    'después: itemSold sigue apuntando al mismo transactionId',
    $itemSoldAfter !== false && (string) $itemSoldAfter['transactionid'] === $transactionId,
    json_encode($itemSoldAfter),
    $failures
);

$vPaymentsAfter = ncmExecute('SELECT transactionid FROM vPayments WHERE id = ?', [$vPaymentsId]);
check(
    'después: vPayments sigue apuntando al mismo transactionId',
    $vPaymentsAfter !== false && (string) $vPaymentsAfter['transactionid'] === $transactionId,
    json_encode($vPaymentsAfter),
    $failures
);

// ── 6. La fila de transaction vive ahora en la partición del MES SIGUIENTE ──
$partitionAfter = ncmExecute(
    "SELECT tableoid::regclass::text AS part FROM transaction WHERE transactionid = ?",
    [$transactionId]
);
$expectedPartAfter = monthPartitionName('transaction', $newDate);
check(
    'después: la fila se movió a la partición del mes siguiente (' . $expectedPartAfter . ')',
    $partitionAfter !== false && str_contains((string) ($partitionAfter['part'] ?? ''), $expectedPartAfter),
    json_encode($partitionAfter),
    $failures
);
check(
    'después: la partición cambió respecto de la original (no es un no-op)',
    ($partitionBefore['part'] ?? null) !== ($partitionAfter['part'] ?? null),
    'antes=' . json_encode($partitionBefore) . ' después=' . json_encode($partitionAfter),
    $failures
);

// ── Limpieza: dejar el fixture como estaba ──────────────────────────────
$db->Execute('DELETE FROM vPayments WHERE id = ?', [$vPaymentsId]);
$db->Execute('DELETE FROM itemSold  WHERE itemsoldid = ?', [$itemSoldId]);
$db->Execute('DELETE FROM transaction WHERE transactionid = ?', [$transactionId]);

if ($failures > 0) {
    echo "\n$failures caso(s) fallido(s).\n";
    exit(1);
}
echo "\nTodos los casos OK.\n";
exit(0);
