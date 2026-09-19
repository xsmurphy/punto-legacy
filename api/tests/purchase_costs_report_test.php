<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del reporte "Evolución de costos por proveedor"
 * (`Reports\PurchaseCostsService`, Postgres real).
 *
 * Las compras se registran por `Purchases\PurchasesService::create()` —el
 * mismo camino que el panel— para que el costo unitario que lee el reporte sea
 * el que de verdad queda grabado en `itemSold`.
 *
 * Qué protege:
 *   (A) DOS PROVEEDORES: el mismo artículo con costos distintos; el
 *       comparativo da el ÚLTIMO costo de cada uno y marca el más barato.
 *   (B) VARIACIÓN contra la compra ANTERIOR del MISMO proveedor, aunque esa
 *       compra sea anterior al período; la primera compra de un proveedor no
 *       tiene variación (no contra la de otro proveedor).
 *   (C) PACKSIZE: una compra por bulto se lee como costo por UNIDAD
 *       (precio del paquete / unidades por paquete) y cantidad en unidades.
 *   (D) ANULADA EXCLUIDA: una compra anulada no aparece ni mueve el
 *       comparativo.
 *   (E) TENANT: una compra de otra empresa sobre el mismo artículo no se ve, y
 *       esa empresa solo ve la suya.
 *   (F) SIN ARTÍCULO: el listado trae la última compra del período de cada
 *       artículo con su variación, ordenado por la que más subió; el filtro de
 *       proveedor acota el universo.
 *   (G) SUCURSAL: con el alcance en otra sucursal no se ve nada.
 *
 * Uso: bash api/tests/run_purchase_costs_report_test.sh
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Purchases\PurchasesService;
use Punto\Api\Reports\PurchaseCostsService;
use Punto\Api\Reports\Roc;

// ── Tenants fixture (api/lib/Sales/verify_chain/seed.sql) ────────────────────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024'; // Verify PY
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$roleId     = '1';
$companyB   = 'fa8cf679-9003-417e-8726-5b772d3b6e88'; // Verify MX
$outletB    = '6d3cab3a-c040-4428-8090-6790469de3bd';
$userB      = '999986f1-05fe-4d91-841f-156a090e7a15';
require API_APP_DIR . '/data.php';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.01;
}

function uuid(): string
{
    $b    = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function makeItem(string $id, string $name, string $companyId): void
{
    ncmExecute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus,
                           itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind)
         VALUES (?, ?, ?, 10000, 0, 'product', 1, TRUE, TRUE, FALSE, '{}'::jsonb, ?, 'insumo_stock')",
        [$id, $name, 'PC-' . substr($id, 0, 8), $companyId]
    );
}

function makeSupplier(string $id, string $name, string $companyId, string $outletId): void
{
    ncmExecute(
        "INSERT INTO contact (contactId, contactName, contactStatus, type, outletId, companyId)
         VALUES (?, ?, 1, 2, ?, ?)",
        [$id, $name, $outletId, $companyId]
    );
}

/** Registra una compra por el servicio real. `price` es el precio del PAQUETE. */
function buy(PurchasesService $svc, string $supplierId, string $itemId, float $units, float $price, string $at, int $packSize = 1): string
{
    global $companyId, $outletId, $userId;
    return $svc->create($companyId, [
        'outletId'    => $outletId,
        'userId'      => $userId,
        'supplierId'  => $supplierId,
        'condition'   => 'cash',
        'invoiceDate' => $at,
        'items'       => [['itemId' => $itemId, 'units' => $units, 'price' => $price, 'packSize' => $packSize]],
    ]);
}

/** @return array<string,array<string,mixed>> */
function byKey(array $rows, string $key): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r[$key]] = $r;
    }
    return $out;
}

$purchases = new PurchasesService();
$report    = new PurchaseCostsService();
$roc       = Roc::build($companyId);
$from      = '2026-02-01 00:00:00';
$to        = '2026-02-28 23:59:59';

// ── Fixture ──────────────────────────────────────────────────────────────────
$X  = uuid();
$Y  = uuid();
$S1 = uuid();
$S2 = uuid();
makeItem($X, 'PC harina', $companyId);
makeItem($Y, 'PC azúcar', $companyId);
makeSupplier($S1, 'PC Proveedor Uno', $companyId, $outletId);
makeSupplier($S2, 'PC Proveedor Dos', $companyId, $outletId);

buy($purchases, $S1, $X, 10, 1000, '2026-01-05 10:00:00');             // antes del período
$p1 = buy($purchases, $S1, $X, 5, 1100, '2026-02-03 10:00:00');        // +10% vs la de enero
$p2 = buy($purchases, $S2, $X, 4, 900, '2026-02-05 10:00:00');         // primera de S2
$p3 = buy($purchases, $S1, $X, 2, 14400, '2026-02-10 10:00:00', 12);   // bulto de 12 → 1.200/u
$p4 = buy($purchases, $S2, $X, 1, 5000, '2026-02-12 10:00:00');        // se anula
$purchases->void($p4, $companyId, $userId);
buy($purchases, $S2, $Y, 3, 2000, '2026-02-15 10:00:00');
buy($purchases, $S2, $Y, 3, 1800, '2026-02-20 10:00:00');              // -10%

// Otra empresa, MISMO artículo y proveedor: inserción directa (el servicio de
// compras no dejaría usar un artículo ajeno, y eso es justamente lo que se
// quiere simular — un dato que no le pertenece al tenant consultado).
$txB = uuid();
ncmExecute(
    "INSERT INTO transaction (transactionId, transactionDate, transactionTotal, transactionType,
                              transactionStatus, userId, outletId, companyId, supplierId)
     VALUES (?, '2026-02-11 10:00:00', 1, 1, 1, ?, ?, ?, ?)",
    [$txB, $userB, $outletB, $companyB, $S1]
);
ncmExecute(
    "INSERT INTO itemSold (itemSoldTotal, itemSoldUnits, itemSoldDate, itemId, transactionId)
     VALUES (1, 1, '2026-02-11 10:00:00', ?, ?)",
    [$X, $txB]
);

// ── (A)-(D) Con artículo ─────────────────────────────────────────────────────
$r    = $report->costs(['itemId' => $X], $from, $to, $roc, $companyId);
$rows = byKey($r['rows'] ?? [], 'transactionId');

check('modo artículo', ($r['mode'] ?? null) === 'item', json_encode($r['mode'] ?? null), $failures, $checks);
check('nombre del artículo', ($r['item']['itemName'] ?? null) === 'PC harina', json_encode($r['item'] ?? null), $failures, $checks);
check('(D)(E) tres compras en el período: sin la anulada ni la de otra empresa',
    count($r['rows'] ?? []) === 3 && isset($rows[$p1], $rows[$p2], $rows[$p3]),
    json_encode(array_keys($rows)), $failures, $checks);
check('(D) la anulada no aparece', !isset($rows[$p4]), '', $failures, $checks);
check('(E) la compra de otra empresa no aparece', !isset($rows[$txB]), '', $failures, $checks);

check('(B) S1 febrero: 1.100 contra 1.000 de enero = +10%',
    near($rows[$p1]['unitCost'] ?? null, 1100.0) && near($rows[$p1]['variationPct'] ?? null, 10.0),
    json_encode($rows[$p1] ?? null), $failures, $checks);
check('(B) primera compra de S2: sin variación (no se compara contra S1)',
    near($rows[$p2]['unitCost'] ?? null, 900.0) && array_key_exists('variationPct', $rows[$p2] ?? []) && $rows[$p2]['variationPct'] === null,
    json_encode($rows[$p2] ?? null), $failures, $checks);
check('(C) bulto de 12 a 14.400 → 1.200 por unidad, 24 unidades',
    near($rows[$p3]['unitCost'] ?? null, 1200.0) && near($rows[$p3]['units'] ?? null, 24.0),
    json_encode($rows[$p3] ?? null), $failures, $checks);
check('(B)(C) variación del bulto contra la anterior de S1 (1.100) = +9,09%',
    near($rows[$p3]['variationPct'] ?? null, 9.09),
    json_encode($rows[$p3] ?? null), $failures, $checks);
check('nombre del proveedor en la fila', ($rows[$p1]['supplierName'] ?? null) === 'PC Proveedor Uno',
    json_encode($rows[$p1] ?? null), $failures, $checks);

$sup = byKey($r['suppliers'] ?? [], 'supplierId');
check('(A) comparativo con los dos proveedores', count($sup) === 2, json_encode($r['suppliers'] ?? null), $failures, $checks);
check('(A)(D) S2: último costo 900 (la anulada de 5.000 no cuenta) y es el más barato',
    near($sup[$S2]['lastCost'] ?? null, 900.0) && ($sup[$S2]['cheapest'] ?? null) === true,
    json_encode($sup[$S2] ?? null), $failures, $checks);
check('(A) S1: último costo 1.200, no es el más barato',
    near($sup[$S1]['lastCost'] ?? null, 1200.0) && ($sup[$S1]['cheapest'] ?? null) === false,
    json_encode($sup[$S1] ?? null), $failures, $checks);
check('(A) el comparativo viene del más barato al más caro',
    (string) ($r['suppliers'][0]['supplierId'] ?? '') === $S2, json_encode($r['suppliers'] ?? null), $failures, $checks);

$f = $report->costs(['itemId' => $X, 'supplierId' => $S2], $from, $to, $roc, $companyId);
check('filtro de proveedor acota las compras', count($f['rows'] ?? []) === 1 && ($f['rows'][0]['transactionId'] ?? '') === $p2,
    json_encode($f['rows'] ?? null), $failures, $checks);
check('filtro de proveedor no achica el comparativo', count($f['suppliers'] ?? []) === 2,
    json_encode($f['suppliers'] ?? null), $failures, $checks);

// ── (G) Serie del gráfico: grano por largo del rango (TimeBuckets) ───────────
$pointOf = static function (array $series, string $bucket, string $sup): ?array {
    foreach ($series['points'] ?? [] as $p) {
        if ($p['bucket'] === $bucket && (string) $p['supplierId'] === $sup) {
            return $p;
        }
    }
    return null;
};
$s = $r['series'] ?? [];
check('(G) febrero (28 días) grafica por día', ($s['granularity'] ?? null) === 'day', json_encode($s['granularity'] ?? null), $failures, $checks);
check('(G) por día: S1 el 03/02 a 1.100', near($pointOf($s, '2026-02-03', $S1)['unitCost'] ?? null, 1100), json_encode($s['points'] ?? null), $failures, $checks);
check('(G) por día: el calendario trae los 28 días', count($s['buckets'] ?? []) === 28, (string) count($s['buckets'] ?? []), $failures, $checks);

$mo = $report->costs(['itemId' => $X], '2025-10-01 00:00:00', $to, $roc, $companyId)['series'] ?? [];
check('(G) cinco meses grafican por mes', ($mo['granularity'] ?? null) === 'month', json_encode($mo['granularity'] ?? null), $failures, $checks);
// 5 u a 1.100 + 24 u a 1.200 = 34.300 / 29 u — no el promedio simple (1.150).
check('(G) por mes: el costo de S1 en febrero es el promedio PONDERADO por unidades',
    near($pointOf($mo, '2026-02-01', $S1)['unitCost'] ?? null, 34300 / 29), json_encode($pointOf($mo, '2026-02-01', $S1)), $failures, $checks);
check('(G) por mes: la compra anulada no entra en el promedio de S2',
    near($pointOf($mo, '2026-02-01', $S2)['unitCost'] ?? null, 900), json_encode($pointOf($mo, '2026-02-01', $S2)), $failures, $checks);
check('(G) por mes: enero tiene su propio punto', near($pointOf($mo, '2026-01-01', $S1)['unitCost'] ?? null, 1000), json_encode($mo['points'] ?? null), $failures, $checks);

// ── (E) La otra empresa ve solo lo suyo ──────────────────────────────────────
$b = $report->costs(['itemId' => $X], $from, $to, Roc::build($companyB), $companyB);
check('(E) la otra empresa ve solo su compra',
    count($b['rows'] ?? []) === 1 && ($b['rows'][0]['transactionId'] ?? '') === $txB && near($b['rows'][0]['unitCost'] ?? null, 1.0),
    json_encode($b['rows'] ?? null), $failures, $checks);
check('(E) y no recibe el nombre de un artículo ajeno', array_key_exists('item', $b) && $b['item'] === null,
    json_encode($b['item'] ?? null), $failures, $checks);

// ── (F) Sin artículo ─────────────────────────────────────────────────────────
$all   = $report->costs([], $from, $to, $roc, $companyId);
$items = byKey($all['items'] ?? [], 'itemId');
check('(F) modo listado', ($all['mode'] ?? null) === 'items', json_encode($all['mode'] ?? null), $failures, $checks);
check('(F) harina: último costo 1.200 de S1, anterior 1.100, +9,09%',
    near($items[$X]['lastCost'] ?? null, 1200.0) && near($items[$X]['previousCost'] ?? null, 1100.0)
        && near($items[$X]['variationPct'] ?? null, 9.09) && ($items[$X]['supplierId'] ?? '') === $S1,
    json_encode($items[$X] ?? null), $failures, $checks);
check('(F) azúcar: 1.800 contra 2.000 = -10%',
    near($items[$Y]['lastCost'] ?? null, 1800.0) && near($items[$Y]['variationPct'] ?? null, -10.0),
    json_encode($items[$Y] ?? null), $failures, $checks);
$ids = array_map(fn($i) => $i['itemId'], $all['items'] ?? []);
check('(F) ordenado por lo que más subió', array_search($X, $ids, true) < array_search($Y, $ids, true),
    json_encode($ids), $failures, $checks);

$onlyS1 = byKey($report->costs(['supplierId' => $S1], $from, $to, $roc, $companyId)['items'] ?? [], 'itemId');
check('(F) filtro de proveedor: el azúcar (solo S2) no aparece', isset($onlyS1[$X]) && !isset($onlyS1[$Y]),
    json_encode(array_keys($onlyS1)), $failures, $checks);

$onlyS2 = byKey($report->costs(['supplierId' => $S2], $from, $to, $roc, $companyId)['items'] ?? [], 'itemId');
check('(F) filtro S2: la harina muestra la última de S2 (900), no la anulada',
    near($onlyS2[$X]['lastCost'] ?? null, 900.0) && array_key_exists('variationPct', $onlyS2[$X] ?? []) && $onlyS2[$X]['variationPct'] === null,
    json_encode($onlyS2[$X] ?? null), $failures, $checks);

// ── (G) Sucursal ─────────────────────────────────────────────────────────────
$otherOutlet = Roc::build($companyId, uuid());
$g = $report->costs([], $from, $to, $otherOutlet, $companyId);
check('(G) con otra sucursal en el alcance no se ve nada', ($g['items'] ?? null) === [],
    json_encode($g['items'] ?? null), $failures, $checks);

echo "\n";
harnessFinish($failures, $checks);
