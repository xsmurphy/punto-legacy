<?php
declare(strict_types=1);

/**
 * Test de integración (DB real) de `SaleVoidService::void()` — anulación de
 * ventas (F1+F2, context/40-anulacion-y-nota-credito.md). Mismo patrón que
 * `credit_payment_void_test.php`: reusa el tenant fixture "Verify PY"
 * (`api/lib/Sales/verify_chain/seed.sql`), inserta sus propias ventas por
 * SQL directo (no pasa por SaleService — no hace falta ejercitar el motor de
 * venta completo, solo la anulación), y corre contra Postgres real porque
 * `void()` lockea filas (`FOR UPDATE`) y su corrección depende de
 * `Inventory::manageStock()`/`explodeRecipe()` y `TransactionLinkService`
 * leyendo datos reales.
 *
 * Ítems reusados del fixture (ver seed.sql):
 *   - '7a1c1a9e-...-4e5f' "Verify stock trackeable" (itemTrackInventory=TRUE,
 *     sin receta) → kind='ownStock'.
 *   - 'b4a1e5f2-...-5c6b' "Verify producción directa" (itemTrackInventory=
 *     FALSE, receta 2× 'b4a1e5f2-...-5c6a' insumo) → kind='ingredientReversal'.
 *   - '10223f3b-...' "Verify 10% incluido" (itemTrackInventory=FALSE, sin
 *     receta) → kind='service', nada que reponer ni perder.
 *
 * Casos:
 *   (a) happy path: cajero repone SOLO el ítem con stock propio; el de
 *       producción directa NO se repone (setting apagado por default) →
 *       genera waste_event; el de servicio no genera nada. voidedAt seteado,
 *       transactionType intacto, transactionComplete=TRUE, fin_movement de
 *       origen 'sale' revertido (status=0).
 *   (b) idempotencia: anular una venta ya anulada se rechaza (409).
 *   (c) ventana de 48h: una venta con transactionDate viejo se rechaza (422
 *       VOID_WINDOW_EXPIRED).
 *   (d) HAS_RETURNS: una venta con una devolución vigente vinculada
 *       (transaction_link kind='return') se rechaza (409).
 *   (e) reportes: `Reports\SalesService::salesTotals()` (uno de los
 *       call-sites que suma `AND SaleFilters::notVoidedSql()`) deja de sumar
 *       la venta anulada.
 *   (f) rollup (F4, mig 155): `rollup_recompute_period(companyId,'sales',day)`
 *       recalculado tras la anulación NO incluye la venta anulada — su
 *       cnt/total consolidado del día coincide con la misma query filtrada
 *       en vivo por `voidedat IS NULL` (si el rollup no excluyera lo
 *       anulado, sería mayor que la query en vivo y el caso fallaría).
 *   (g) línea ambigua (P2, code review F1+F2): una venta con 2 líneas del
 *       MISMO itemId + un request que manda `itemId` sin `itemSoldId` se
 *       rechaza (422) en vez de contagiar la misma decisión de reposición a
 *       ambas líneas.
 *
 * (b)/(c)/(d) usan `apiConflict()`/`apiUnprocessable()`, que hacen `exit`
 * directo — corren en subproceso (`_sale_void_once_cli.php`), mismo patrón
 * que `credit_payment_void_test.php` usa para su caso de idempotencia.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_sale_void_test.sh` para levantar todo de cero en Docker):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/sale_void_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\SaleVoidService;
use Punto\Api\Services\TransactionLinkService;
use Punto\Api\Reports\SalesService as ReportsSalesService;
use Punto\Api\Reports\Roc;
use Punto\App\Domain\Inventory;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

// Items del fixture reusados (ver comentario de arriba).
$stockItemId   = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f'; // ownStock
$prodDirectId  = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6b'; // ingredientReversal (receta 2x insumo)
$insumoId      = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6a'; // insumo de la receta de arriba
$serviceItemId = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // service (sin stock, sin receta)

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

/**
 * Inserta una venta contado (type=0) mínima, propia de este test, con las
 * líneas de itemSold que se le pasen. `$dateOverride` permite simular una
 * venta vieja (caso ventana de 48h).
 *
 * @param list<array{itemId:string,units:float,total:float,cogs:float}> $lines
 */
function makeSale(
    string $companyId,
    string $outletId,
    string $registerId,
    string $userId,
    array  $lines,
    ?string $dateOverride = null
): string {
    global $db;

    $total = array_sum(array_column($lines, 'total'));
    $units = array_sum(array_column($lines, 'units'));
    $date  = $dateOverride ?? date('Y-m-d H:i:s');

    $db->AutoExecute('transaction', [
        'transactionTotal'       => $total,
        'transactionDiscount'    => 0,
        'transactionUnitsSold'   => $units,
        'transactionType'        => 0,
        'transactionComplete'    => true,
        'transactionStatus'      => 1,
        'transactionDate'        => $date,
        'transactionPaymentType' => json_encode([['type' => 'cash', 'price' => $total, 'total' => $total]]),
        'invoiceNo'              => random_int(1000000, 9999999),
        'timestamp'              => time(),
        'registerId'             => $registerId,
        'userId'                 => $userId,
        'responsibleId'          => $userId,
        'outletId'               => $outletId,
        'companyId'              => $companyId,
    ], 'INSERT');
    $transactionId = (string) $db->Insert_ID();

    foreach ($lines as $line) {
        $db->AutoExecute('itemSold', [
            'itemId'         => $line['itemId'],
            'transactionId'  => $transactionId,
            'itemSoldUnits'  => $line['units'],
            'itemSoldTotal'  => $line['total'],
            'itemSoldCOGS'   => $line['cogs'],
            'itemSoldDate'   => $date,
            // D4 de context/48-escalamiento-de-datos.md (mig 156).
            'companyId'      => $companyId,
            'outletId'       => $outletId,
            'registerId'     => $registerId,
        ], 'INSERT');
    }

    return $transactionId;
}

function voidedAt(string $transactionId): ?string
{
    $row = ncmExecute('SELECT voidedat FROM transaction WHERE transactionid = ?', [$transactionId]);
    return $row && $row['voidedat'] !== null ? (string) $row['voidedat'] : null;
}

$svc   = new SaleVoidService();
$links = new TransactionLinkService();

// ── (a) happy path ──────────────────────────────────────────────────────
$stockBefore  = Inventory::onHand($stockItemId, $outletId);
$insumoBefore = Inventory::onHand($insumoId, $outletId);

$saleA = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $stockItemId,   'units' => 3, 'total' => 3000,  'cogs' => 500],
    ['itemId' => $prodDirectId,  'units' => 2, 'total' => 18000, 'cogs' => 1000],
    ['itemId' => $serviceItemId, 'units' => 1, 'total' => 11000, 'cogs' => 0],
]);
(new \Punto\Api\Finance\FinanceLedger())->recordSale($companyId, $saleA);

$movBefore = ncmExecute(
    "SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ? AND source = 'sale' AND sourceid = ? AND status = 1",
    [$companyId, $saleA]
);
check('(a) setup: fin_movement de la venta creado y vigente', (int) ($movBefore['n'] ?? 0) > 0, 'n=' . ($movBefore['n'] ?? 0), $failures);

$resultA = $svc->void($companyId, $saleA, $userId, 'motivo de prueba (a)', [
    ['itemId' => $stockItemId, 'restock' => true],
], $registerId, $outletId);

check('(a) void() repone 1 línea (stock propio) y genera 1 waste (producción directa)', $resultA['restocked'] === 1 && $resultA['wasted'] === 1, json_encode($resultA), $failures);

$txA = ncmExecute('SELECT transactiontype, voidreason, voidedby, transactioncomplete FROM transaction WHERE transactionid = ?', [$saleA]);
check('(a) voidedAt seteado', voidedAt($saleA) !== null, 'voidedat=' . voidedAt($saleA), $failures);
check('(a) transactionType SIGUE en 0 (no se pisa a 7)', (int) ($txA['transactiontype'] ?? -1) === 0, 'transactiontype=' . ($txA['transactiontype'] ?? null), $failures);
check('(a) voidReason/voidedBy guardados', ($txA['voidreason'] ?? '') === 'motivo de prueba (a)' && ($txA['voidedby'] ?? '') === $userId, json_encode($txA), $failures);
check('(a) transactionComplete=TRUE tras anular (ya no es deuda pendiente)', !empty($txA['transactioncomplete']), json_encode($txA), $failures);

$stockAfter  = Inventory::onHand($stockItemId, $outletId);
$insumoAfter = Inventory::onHand($insumoId, $outletId);
check('(a) stock propio repuesto (+3)', abs(($stockAfter - $stockBefore) - 3.0) < 0.001, "before=$stockBefore after=$stockAfter", $failures);
check('(a) insumo de producción directa NO repuesto (setting apagado por default)', abs($insumoAfter - $insumoBefore) < 0.001, "before=$insumoBefore after=$insumoAfter", $failures);

$waste = ncmExecute(
    "SELECT qty, cost FROM waste_event WHERE companyid = ? AND itemid = ? AND note LIKE 'Anulación de venta%' ORDER BY created_at DESC LIMIT 1",
    [$companyId, $prodDirectId]
);
check('(a) waste_event generado para la línea no repuesta, con costo correcto', $waste && abs((float) $waste['qty'] - 2.0) < 0.001 && abs((float) $waste['cost'] - 2000.0) < 0.01, json_encode($waste), $failures);

$movAfter = ncmExecute(
    "SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ? AND source = 'sale' AND sourceid = ? AND status = 1",
    [$companyId, $saleA]
);
check('(a) fin_movement de la venta revertido (status=0, ya no hay vigente)', (int) ($movAfter['n'] ?? 0) === 0, 'n=' . ($movAfter['n'] ?? 0), $failures);

// ── (b) idempotencia: anular una venta ya anulada se rechaza ──────────────
$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/_sale_void_once_cli.php')
    . ' ' . escapeshellarg($companyId) . ' ' . escapeshellarg($saleA) . ' ' . escapeshellarg($userId)
    . ' ' . escapeshellarg($registerId) . ' ' . escapeshellarg($outletId) . ' ' . escapeshellarg('motivo repetido') . ' 2>&1';
$output = shell_exec($cmd) ?? '';
check(
    '(b) anular una venta YA anulada se rechaza (409 ALREADY_VOIDED)',
    str_contains($output, '"ok":false') && str_contains($output, 'ALREADY_VOIDED'),
    "salida del subproceso: $output",
    $failures
);

// ── (c) ventana de 48h ─────────────────────────────────────────────────────
$saleOld = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $serviceItemId, 'units' => 1, 'total' => 5000, 'cogs' => 0],
], date('Y-m-d H:i:s', strtotime('-49 hours')));

$cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/_sale_void_once_cli.php')
    . ' ' . escapeshellarg($companyId) . ' ' . escapeshellarg($saleOld) . ' ' . escapeshellarg($userId)
    . ' ' . escapeshellarg($registerId) . ' ' . escapeshellarg($outletId) . ' ' . escapeshellarg('motivo tardío') . ' 2>&1';
$output = shell_exec($cmd) ?? '';
check(
    '(c) venta de hace 49hs se rechaza (422 VOID_WINDOW_EXPIRED)',
    str_contains($output, '"ok":false') && str_contains($output, 'VOID_WINDOW_EXPIRED'),
    "salida del subproceso: $output",
    $failures
);

// ── (d) HAS_RETURNS: venta con devolución vigente vinculada ────────────────
$saleWithReturn = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $serviceItemId, 'units' => 1, 'total' => 4000, 'cogs' => 0],
]);
$fakeReturnId = (string) $db->GetOne('SELECT gen_random_uuid()');
$db->Execute(
    'INSERT INTO transaction (
        transactionid, transactiontotal, transactiondiscount, transactiontype,
        transactioncomplete, transactionstatus, transactiondate, invoiceno,
        registerid, userid, outletid, companyid
    ) VALUES (?, ?, ?, 6, TRUE, 1, ?, ?, ?, ?, ?, ?)',
    [
        $fakeReturnId, -4000, 0, date('Y-m-d H:i:s'), random_int(1000000, 9999999),
        $registerId, $userId, $outletId, $companyId,
    ]
);
$links->link($companyId, $saleWithReturn, $fakeReturnId, 'return');

$cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/_sale_void_once_cli.php')
    . ' ' . escapeshellarg($companyId) . ' ' . escapeshellarg($saleWithReturn) . ' ' . escapeshellarg($userId)
    . ' ' . escapeshellarg($registerId) . ' ' . escapeshellarg($outletId) . ' ' . escapeshellarg('con devolución') . ' 2>&1';
$output = shell_exec($cmd) ?? '';
check(
    '(d) venta con devolución vigente se rechaza (409 HAS_RETURNS)',
    str_contains($output, '"ok":false') && str_contains($output, 'HAS_RETURNS'),
    "salida del subproceso: $output",
    $failures
);

// ── (e) reportes: la venta anulada deja de sumar en SalesService::salesTotals() ──
$roc = Roc::build($companyId, $outletId);
$from = date('Y-m-d H:i:s', strtotime('-1 hour'));
$to   = date('Y-m-d H:i:s', strtotime('+1 hour'));

$saleE = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $serviceItemId, 'units' => 1, 'total' => 7777, 'cogs' => 0],
]);

$reportsSvc = new ReportsSalesService();
$before = $reportsSvc->salesTotals($from, $to, $roc);
check('(e) setup: la venta aparece en salesTotals antes de anular', ($before['count'] ?? 0) >= 1, json_encode($before), $failures);

$svc->void($companyId, $saleE, $userId, 'motivo (e)', [], $registerId, $outletId);

$after = $reportsSvc->salesTotals($from, $to, $roc);
check(
    '(e) salesTotals: count baja en 1 y total baja en 7777 tras anular',
    ((int) ($before['count'] ?? 0) - (int) ($after['count'] ?? 0)) === 1
        && abs(((float) ($before['total'] ?? 0) - (float) ($after['total'] ?? 0)) - 7777.0) < 0.01,
    'before=' . json_encode($before) . ' after=' . json_encode($after),
    $failures
);

// ── (f) rollup: status=vigente excluye la venta anulada (D8, mig 159) ─────
// D8 de context/48-escalamiento-de-datos.md (mig 159): domain='sales' migró
// de report_rollup (grano genérico, "anuladas excluidas" del F4/mig 155) a
// rollup_sales_day (grano día tipado, "anuladas" ahora SON una fila propia
// vía status='anulada' en vez de estar afuera del rollup) — el check pasa a
// filtrar status='vigente' en vez de confiar en que la fila no exista.
$todayDay = date('Y-m-d');
$db->Execute('SELECT rollup_recompute_period(?::uuid, ?, ?::date)', [$companyId, 'sales', $todayDay]);

$rollupRow = ncmExecute(
    "SELECT COALESCE(SUM(cnt), 0) AS cnt, COALESCE(SUM(net), 0) AS total
     FROM rollup_sales_day
     WHERE companyid = ? AND day = ?::date AND kind IN ('contado', 'credito') AND status = 'vigente'",
    [$companyId, $todayDay]
);
$liveRow = ncmExecute(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(transactiontotal),0) AS total FROM transaction
     WHERE companyid = ? AND transactiontype IN (0,3) AND transactiondate::date = ?::date AND voidedat IS NULL",
    [$companyId, $todayDay]
);
check(
    '(f) rollup_recompute_period("sales"): cnt/total del día (status=vigente) coinciden con la query en vivo filtrada por voidedat (venta anulada excluida del rollup)',
    $rollupRow && $liveRow
        && (int) $rollupRow['cnt'] === (int) $liveRow['cnt']
        && abs((float) $rollupRow['total'] - (float) $liveRow['total']) < 0.01,
    'rollup=' . json_encode($rollupRow) . ' live(voidedat IS NULL)=' . json_encode($liveRow),
    $failures
);

// ── (g) línea ambigua: 2 líneas del mismo itemId + request sin itemSoldId ──
$saleG = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $stockItemId, 'units' => 1, 'total' => 1000, 'cogs' => 100],
    ['itemId' => $stockItemId, 'units' => 2, 'total' => 2000, 'cogs' => 200],
]);
$linesJson = json_encode([['itemId' => $stockItemId, 'restock' => true]]);

$cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/_sale_void_once_cli.php')
    . ' ' . escapeshellarg($companyId) . ' ' . escapeshellarg($saleG) . ' ' . escapeshellarg($userId)
    . ' ' . escapeshellarg($registerId) . ' ' . escapeshellarg($outletId) . ' ' . escapeshellarg('línea ambigua')
    . ' ' . escapeshellarg($linesJson) . ' 2>&1';
$output = shell_exec($cmd) ?? '';
check(
    '(g) 2 líneas del mismo itemId + request sin itemSoldId se rechaza (422, no contagia la decisión)',
    str_contains($output, '"ok":false') && str_contains($output, '422') && str_contains($output, 'itemSoldId'),
    "salida del subproceso: $output",
    $failures
);
check(
    '(g) rollback limpio: la venta NO quedó anulada tras el 422 (el UPDATE voidedAt de paso 1 se revirtió)',
    voidedAt($saleG) === null,
    'voidedat=' . voidedAt($saleG),
    $failures
);

if ($failures > 0) {
    echo "\n$failures caso(s) fallido(s).\n";
    exit(1);
}
echo "\nTodos los casos OK.\n";
exit(0);
