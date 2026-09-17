<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la NECESIDAD DE REPOSICIÓN (context/70 §B.5, D6-D8, mig 228).
 *
 * Contra Postgres real, sin mocks. El stock se mueve por
 * `Inventory::manageStock()` —el embudo único del ledger, donde vive el
 * disparo— dentro de una transacción, que es exactamente como lo llama la
 * venta. Los conteos corren por `InventoryCountService` (panel y caja), y la
 * cobertura por `ProductionService` y `StockTransferService` reales.
 *
 *   (A) Un movimiento que cruza el mínimo abre UNA necesidad por la cantidad
 *       fija del ítem; el siguiente debajo del mínimo NO duplica.
 *   (B) Ítem sin cantidad a reponer: no dispara.
 *   (C) Rollback de la venta: no queda necesidad (ni movimiento).
 *   (D) Fallo del disparo (INSERT que revienta): la venta commitea igual y la
 *       transacción no queda envenenada.
 *   (E) Dos cajas a la vez, cada una con su conexión: UNA necesidad.
 *   (F) Conteo del panel y conteo de la caja disparan, con origen y
 *       referencia al conteo — también cuando el conteo no movió stock.
 *   (G) Producir: borrador vinculado; parcial queda abierta; completar lo
 *       que falta la cubre; una orden cancelada deja de contar.
 *   (H) Transferir: cubre en el momento; cancelar la transferencia la reabre
 *       sin abrir un duplicado.
 *   (I) Cierre manual exige motivo (servicio Y base).
 *   (J) Aislamiento por tenant y alcance por sucursal en el listado.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Production/ProductionService.php';
require_once dirname(__DIR__) . '/lib/services/InventoryCountService.php';
require_once dirname(__DIR__) . '/lib/services/StockTransferService.php';
require_once dirname(__DIR__) . '/lib/services/ReplenishmentService.php';
require_once dirname(__DIR__) . '/lib/Settings/StockCountSettings.php';

use Punto\Api\Outlets\OutletScope;
use Punto\Api\Production\ProductionService;
use Punto\Api\Services\InventoryCountService;
use Punto\Api\Services\ReplenishmentService;
use Punto\Api\Services\StockTransferService;
use Punto\Api\Settings\StockCountSettings;
use Punto\App\Domain\Inventory;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

const MX_COMPANY = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
const MX_OUTLET  = '6d3cab3a-c040-4428-8090-6790469de3bd';

// Segunda sucursal del tenant, propia del arnés (transferencias y alcance).
const OUTLET_B = 'e9e10000-0000-4000-8000-00000000000b';

// Ítems propios — prefijo `e9e1…`.
const IT_VENTA    = 'e9e10001-0000-4000-8000-000000000001'; // (A)
const IT_SIN_REP  = 'e9e10001-0000-4000-8000-000000000002'; // (B)
const IT_ROLLBACK = 'e9e10001-0000-4000-8000-000000000003'; // (C)
const IT_FALLO    = 'e9e10001-0000-4000-8000-000000000004'; // (D)
const IT_CARRERA  = 'e9e10001-0000-4000-8000-000000000005'; // (E)
const IT_CONTEO_P = 'e9e10001-0000-4000-8000-000000000006'; // (F) panel
const IT_CONTEO_C = 'e9e10001-0000-4000-8000-000000000007'; // (F) caja
const IT_PLATO    = 'e9e10001-0000-4000-8000-000000000008'; // (G) producible
const IT_INSUMO   = 'e9e10001-0000-4000-8000-000000000009'; // (G) insumo del plato
const IT_TRANSF   = 'e9e10001-0000-4000-8000-00000000000a'; // (H)
const IT_MX       = 'e9e10001-0000-4000-8000-00000000000b'; // (J) otro tenant
const LISTA_ID    = 'lista-reposicion-test';

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

function near(float $a, float $b): bool
{
    return abs($a - $b) < 1e-6;
}

function upsertItem(string $id, string $name, string $companyId, ?float $min, ?float $replenish, bool $production = false): void
{
    ncmExecute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus,
                           itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind,
                           itemminstock, itemreplenishqty)
         VALUES (?, ?, ?, 0, 100, 'product', 1, TRUE, TRUE, ?, '{}'::jsonb, ?, 'producto', ?, ?)
         ON CONFLICT (itemid) DO UPDATE SET
            itemname = EXCLUDED.itemname, itemtrackinventory = TRUE, itemstatus = 1,
            itemproduction = EXCLUDED.itemproduction,
            itemminstock = EXCLUDED.itemminstock, itemreplenishqty = EXCLUDED.itemreplenishqty",
        [$id, $name, 'REPO-' . substr($id, -4), $production, $companyId, $min, $replenish]
    );
}

function move(string $companyId, string $outletId, string $itemId, float $qty, string $type, bool $inTx = true, bool $fail = false): void
{
    global $db;
    if ($inTx) {
        $db->StartTrans();
    }
    Inventory::manageStock([
        'itemId'        => $itemId,
        'outletId'      => $outletId,
        'date'          => date('Y-m-d H:i:s'),
        'locationId'    => null,
        'count'         => $qty,
        'type'          => $type,
        'source'        => $type === '-' ? 'sale' : 'adjustment',
        'transactionId' => null,
        'userId'        => USER_ID,
        'companyId'     => $companyId,
    ]);
    if ($inTx) {
        if ($fail) {
            $db->FailTrans();
        }
        $db->CompleteTrans();
    }
}

/** @return list<array<string,mixed>> */
function needsFor(string $companyId, string $outletId, string $itemId): array
{
    $rs = ncmExecute(
        'SELECT needid, quantity, origin, sourceid, status, onhandat FROM replenishment_need
          WHERE companyid = ? AND outletid = ? AND itemid = ? ORDER BY createdat',
        [$companyId, $outletId, $itemId],
        false,
        true
    );
    $out = [];
    if ($rs) {
        while (!$rs->EOF) {
            $out[] = $rs->fields->toArray();
            $rs->MoveNext();
        }
    }
    return $out;
}

function movementsFor(string $outletId, string $itemId): int
{
    $r = ncmExecute('SELECT COUNT(*) AS n FROM stock WHERE outletid = ? AND itemid = ?', [$outletId, $itemId]);
    return (int) ($r['n'] ?? 0);
}

// ── Limpieza (el arnés puede correr dos veces contra la misma base) ─────────
$allItems = [IT_VENTA, IT_SIN_REP, IT_ROLLBACK, IT_FALLO, IT_CARRERA, IT_CONTEO_P, IT_CONTEO_C, IT_PLATO, IT_INSUMO, IT_TRANSF, IT_MX];
$ph = implode(',', array_fill(0, count($allItems), '?'));
ncmExecute("DELETE FROM replenishment_need WHERE itemid IN ($ph)", $allItems);
ncmExecute("DELETE FROM stock WHERE itemid IN ($ph)", $allItems);
ncmExecute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
     ON CONFLICT (outletId) DO NOTHING',
    [OUTLET_B, 'Reposición B', $companyId]
);
ncmExecute('DELETE FROM contact_outlet WHERE contactid = ? AND companyid = ?', [$adminId, $companyId]);

$svc = new ReplenishmentService();

try {
    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (A) cruzar el mínimo abre UNA necesidad ===\n";
    upsertItem(IT_VENTA, 'Repo venta', $companyId, 10, 50);
    move($companyId, $outletId, IT_VENTA, 15, '+');
    check('(A0) sobre el mínimo no abre nada', needsFor($companyId, $outletId, IT_VENTA) === [],
        'se abrió una necesidad con saldo 15 y mínimo 10', $failures, $checks);

    move($companyId, $outletId, IT_VENTA, 5, '-'); // saldo 10 = mínimo
    $n = needsFor($companyId, $outletId, IT_VENTA);
    check('(A1) llegar AL mínimo abre una necesidad', count($n) === 1, 'necesidades: ' . json_encode($n), $failures, $checks);
    check('(A2) por la cantidad fija del ítem y con origen min_stock',
        isset($n[0]) && near((float) $n[0]['quantity'], 50) && $n[0]['origin'] === 'min_stock' && $n[0]['status'] === 'open'
        && near((float) $n[0]['onhandat'], 10),
        json_encode($n), $failures, $checks);

    move($companyId, $outletId, IT_VENTA, 3, '-');
    move($companyId, $outletId, IT_VENTA, 1, '-', inTx: false);
    check('(A3) otra venta debajo del mínimo NO duplica', count(needsFor($companyId, $outletId, IT_VENTA)) === 1,
        json_encode(needsFor($companyId, $outletId, IT_VENTA)), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (B) sin cantidad a reponer no dispara ===\n";
    upsertItem(IT_SIN_REP, 'Repo sin cantidad', $companyId, 10, null);
    move($companyId, $outletId, IT_SIN_REP, 2, '-');
    check('(B1) mínimo sin cantidad a reponer: sin necesidad', needsFor($companyId, $outletId, IT_SIN_REP) === [],
        json_encode(needsFor($companyId, $outletId, IT_SIN_REP)), $failures, $checks);
    $threw = false;
    try {
        ncmExecute('UPDATE item SET itemreplenishqty = 0 WHERE itemid = ?', [IT_SIN_REP]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    check('(B2) la base rechaza cantidad a reponer 0', $threw, 'el UPDATE a 0 pasó', $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (C) rollback de la venta ===\n";
    upsertItem(IT_ROLLBACK, 'Repo rollback', $companyId, 10, 20);
    move($companyId, $outletId, IT_ROLLBACK, 12, '+');
    $antes = movementsFor($outletId, IT_ROLLBACK);
    move($companyId, $outletId, IT_ROLLBACK, 5, '-', inTx: true, fail: true);
    check('(C1) venta revertida: no queda necesidad', needsFor($companyId, $outletId, IT_ROLLBACK) === [],
        json_encode(needsFor($companyId, $outletId, IT_ROLLBACK)), $failures, $checks);
    check('(C2) ni el movimiento', movementsFor($outletId, IT_ROLLBACK) === $antes,
        'movimientos antes ' . $antes . ', después ' . movementsFor($outletId, IT_ROLLBACK), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (D) fallo del disparo no rompe la venta ===\n";
    upsertItem(IT_FALLO, 'Repo fallo', $companyId, 10, 20);
    move($companyId, $outletId, IT_FALLO, 12, '+');
    $antes = movementsFor($outletId, IT_FALLO);
    $db->Execute("CREATE OR REPLACE FUNCTION repo_test_boom() RETURNS trigger LANGUAGE plpgsql AS \$\$
                  BEGIN RAISE EXCEPTION 'disparo roto a propósito'; END \$\$");
    $db->Execute('DROP TRIGGER IF EXISTS repo_test_boom ON replenishment_need');
    $db->Execute('CREATE TRIGGER repo_test_boom BEFORE INSERT ON replenishment_need FOR EACH ROW EXECUTE FUNCTION repo_test_boom()');

    $db->StartTrans();
    Inventory::manageStock([
        'itemId' => IT_FALLO, 'outletId' => $outletId, 'date' => date('Y-m-d H:i:s'), 'locationId' => null,
        'count' => 5, 'type' => '-', 'source' => 'sale', 'transactionId' => null, 'userId' => USER_ID, 'companyId' => $companyId,
    ]);
    // Una escritura DESPUÉS del disparo fallido: si la transacción hubiera
    // quedado envenenada (25P02), esto lanza.
    $segundaOk = true;
    try {
        move($companyId, $outletId, IT_FALLO, 1, '-', inTx: false);
    } catch (\Throwable $e) {
        $segundaOk = false;
    }
    $commit = $db->CompleteTrans();
    $db->Execute('DROP TRIGGER IF EXISTS repo_test_boom ON replenishment_need');

    check('(D1) la transacción sigue viva después del disparo fallido', $segundaOk, 'la escritura siguiente lanzó', $failures, $checks);
    check('(D2) la venta commitea', $commit === true, 'CompleteTrans devolvió false', $failures, $checks);
    check('(D3) y los dos movimientos quedaron', movementsFor($outletId, IT_FALLO) === $antes + 2,
        'movimientos antes ' . $antes . ', después ' . movementsFor($outletId, IT_FALLO), $failures, $checks);
    check('(D4) sin necesidad (el disparo falló)', needsFor($companyId, $outletId, IT_FALLO) === [],
        json_encode(needsFor($companyId, $outletId, IT_FALLO)), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (E) dos cajas a la vez ===\n";
    upsertItem(IT_CARRERA, 'Repo carrera', $companyId, 10, 30);
    move($companyId, $outletId, IT_CARRERA, 12, '+');

    $cli  = __DIR__ . '/_replenishment_move_once_cli.php';
    $spawn = static function (int $hold) use ($cli, $companyId, $outletId, $adminId) {
        $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING',
                $cli, $companyId, $outletId, $adminId, IT_CARRERA, '5', (string) $hold];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        return [$proc, $pipes];
    };
    [$p1, $pipes1] = $spawn(3);
    usleep(1_500_000);
    [$p2, $pipes2] = $spawn(0);
    $out1 = stream_get_contents($pipes1[1]) . stream_get_contents($pipes1[2]);
    $out2 = stream_get_contents($pipes2[1]) . stream_get_contents($pipes2[2]);
    proc_close($p1);
    proc_close($p2);

    check('(E1) las dos ventas commitean', str_contains($out1, 'committed') && str_contains($out2, 'committed'),
        "caja 1: $out1 | caja 2: $out2", $failures, $checks);
    $n = needsFor($companyId, $outletId, IT_CARRERA);
    check('(E2) una sola necesidad abierta', count($n) === 1, json_encode($n), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (F) los dos conteos disparan ===\n";
    // Settings: el conteo ajusta stock y la caja puede contar.
    $db->Execute(
        "UPDATE company SET config = jsonb_set(config, '{settingObj}',
            to_jsonb((COALESCE(NULLIF(config->>'settingObj', ''), '{}')::jsonb
              || jsonb_build_object('stockCountFromRegister', true, 'stockCountRecordOnly', false))::text), true)
          WHERE companyid = ?",
        [$companyId]
    );

    // Panel: stock 8 cargado SIN mínimo (no dispara), después se define el
    // mínimo. El conteo encuentra 8 — sin diferencia, sin movimiento — y aun
    // así la necesidad se abre porque el saldo está debajo del mínimo.
    upsertItem(IT_CONTEO_P, 'Repo conteo panel', $companyId, null, null);
    move($companyId, $outletId, IT_CONTEO_P, 8, '+');
    upsertItem(IT_CONTEO_P, 'Repo conteo panel', $companyId, 10, 40);

    $counts = new InventoryCountService();
    $creado = $counts->create($companyId, $outletId, null, $adminId, 'arnés reposición', [], true);
    $countId = (string) $creado['id'];
    $counts->setCountedQty($countId, IT_CONTEO_P, 8, $adminId, $companyId);
    $counts->finish($countId, $companyId, $adminId);
    $n = needsFor($companyId, $outletId, IT_CONTEO_P);
    check('(F1) conteo del panel abre la necesidad aunque no haya diferencia', count($n) === 1, json_encode($n), $failures, $checks);
    check('(F2) con origen count_panel y referencia al conteo',
        isset($n[0]) && $n[0]['origin'] === 'count_panel' && $n[0]['sourceid'] === $countId && near((float) $n[0]['quantity'], 40),
        json_encode($n) . ' countId=' . $countId, $failures, $checks);

    // Caja (a ciegas): el saldo es 25, cuenta 4 → ajuste a 4 ≤ 10.
    upsertItem(IT_CONTEO_C, 'Repo conteo caja', $companyId, 10, 60);
    move($companyId, $outletId, IT_CONTEO_C, 25, '+');
    $db->Execute(
        "UPDATE company SET config = config || jsonb_build_object('stockCountLists', ?::text) WHERE companyid = ?",
        [json_encode([['id' => LISTA_ID, 'name' => 'Reposición', 'itemIds' => [IT_CONTEO_C]]]), $companyId]
    );
    StockCountSettings::forget($companyId);
    $opId = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
    $res = $counts->submitFromRegister(
        $companyId, $outletId, $adminId, $opId, LISTA_ID, 'Reposición',
        [IT_CONTEO_C => 4], null, $registerId, date('Y-m-d H:i:s'), null
    );
    $n = needsFor($companyId, $outletId, IT_CONTEO_C);
    check('(F3) conteo de la caja abre UNA necesidad (el ajuste no duplica por mínimo)', count($n) === 1, json_encode($n), $failures, $checks);
    check('(F4) con origen count_register y referencia al conteo',
        isset($n[0]) && $n[0]['origin'] === 'count_register' && $n[0]['sourceid'] === ($res['id'] ?? null)
        && near((float) $n[0]['onhandat'], 4),
        json_encode($n) . ' res=' . json_encode($res), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (G) producir ===\n";
    upsertItem(IT_INSUMO, 'Repo insumo', $companyId, null, null);
    upsertItem(IT_PLATO, 'Repo plato', $companyId, 5, 50, production: true);
    ncmExecute(
        'INSERT INTO item_compound (parentItemId, childItemId, quantity, sort, companyId) VALUES (?, ?, 1, 0, ?)
         ON CONFLICT (parentItemId, childItemId) DO NOTHING',
        [IT_PLATO, IT_INSUMO, $companyId]
    );
    move($companyId, $outletId, IT_INSUMO, 500, '+');
    move($companyId, $outletId, IT_PLATO, 3, '+'); // 3 ≤ 5 → necesidad de 50
    $need = needsFor($companyId, $outletId, IT_PLATO)[0] ?? null;
    check('(G0) el plato bajo mínimo tiene necesidad', $need !== null, 'sin necesidad', $failures, $checks);
    $needId = (string) ($need['needid'] ?? '');

    $prod = new ProductionService($db);
    $r1 = $svc->produce($companyId, $adminId, $needId, 30, null);
    $order1 = $prod->find($companyId, $r1['orderId']);
    check('(G1) crea una orden en BORRADOR por la cantidad pedida',
        $order1 !== null && $order1['status'] === 'draft' && near((float) $order1['qtyPlanned'], 30),
        json_encode($order1), $failures, $checks);
    $v = $svc->get($companyId, $needId, null);
    check('(G2) vinculada; la necesidad sigue abierta con 20 pendientes',
        $v !== null && $v['status'] === 'open' && count($v['coverages']) === 1 && near($v['pending'], 20) && near($v['covered'], 0),
        json_encode($v), $failures, $checks);

    $prod->complete($companyId, $adminId, $r1['orderId'], ['qtyProduced' => 30]);
    $v = $svc->get($companyId, $needId, null);
    check('(G3) orden completada por 30 de 50: cubierta en parte, sigue abierta',
        $v !== null && $v['status'] === 'open' && near($v['covered'], 30) && near($v['pending'], 20),
        json_encode($v), $failures, $checks);

    // Una orden cancelada deja de contar.
    $r2 = $svc->produce($companyId, $adminId, $needId, null, null);
    $prod->cancel($companyId, $r2['orderId']);
    $v = $svc->get($companyId, $needId, null);
    check('(G4) orden cancelada: no cubre ni queda en curso, vuelven los 20 pendientes',
        $v !== null && $v['status'] === 'open' && near($v['covered'], 30) && near($v['inFlight'], 0) && near($v['pending'], 20),
        json_encode($v), $failures, $checks);

    $r3 = $svc->produce($companyId, $adminId, $needId, null, null);
    $prod->complete($companyId, $adminId, $r3['orderId'], ['qtyProduced' => 20]);
    $v = $svc->get($companyId, $needId, null);
    check('(G5) completar lo que faltaba la cubre', $v !== null && $v['status'] === 'covered' && near($v['covered'], 50),
        json_encode($v), $failures, $checks);

    $threw = false;
    try {
        $svc->produce($companyId, $adminId, $needId, 5, null);
    } catch (\RuntimeException $e) {
        $threw = (int) $e->getCode() === 409;
    }
    check('(G6) una necesidad cubierta no acepta otra orden', $threw, 'produce() no rechazó con 409', $failures, $checks);

    $threw = false;
    $noRecipeNeed = needsFor($companyId, $outletId, IT_VENTA)[0]['needid'] ?? '';
    try {
        $svc->produce($companyId, $adminId, (string) $noRecipeNeed, null, null);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    check('(G7) un ítem sin receta no se puede producir', $threw, 'produce() aceptó un ítem sin receta', $failures, $checks);
    $v = $svc->get($companyId, (string) $noRecipeNeed, null);
    check('(G8) y no quedó vínculo a medias', $v !== null && $v['coverages'] === [], json_encode($v), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (H) transferir ===\n";
    upsertItem(IT_TRANSF, 'Repo transferencia', $companyId, 10, 25);
    move($companyId, $outletId, IT_TRANSF, 100, '+');
    move($companyId, OUTLET_B, IT_TRANSF, 2, '+'); // 2 ≤ 10 en la sucursal B
    $needB = needsFor($companyId, OUTLET_B, IT_TRANSF)[0] ?? null;
    check('(H0) el mínimo se compara contra el saldo de CADA sucursal',
        $needB !== null && needsFor($companyId, $outletId, IT_TRANSF) === [],
        'B: ' . json_encode($needB) . ' A: ' . json_encode(needsFor($companyId, $outletId, IT_TRANSF)), $failures, $checks);
    $needBId = (string) ($needB['needid'] ?? '');

    $t = $svc->transfer($companyId, $adminId, $needBId, $outletId, null, null, null, null);
    $v = $svc->get($companyId, $needBId, null);
    check('(H1) la transferencia cubre en el momento', $t['status'] === 'covered' && $v['status'] === 'covered' && near($v['covered'], 25),
        json_encode($t) . ' ' . json_encode($v), $failures, $checks);
    check('(H2) el stock se movió de verdad', near(Inventory::onHand(IT_TRANSF, OUTLET_B), 27) && near(Inventory::onHand(IT_TRANSF, $outletId), 75),
        'B=' . Inventory::onHand(IT_TRANSF, OUTLET_B) . ' A=' . Inventory::onHand(IT_TRANSF, $outletId), $failures, $checks);

    (new StockTransferService())->cancel($t['transferId'], $companyId, $adminId);
    $all = needsFor($companyId, OUTLET_B, IT_TRANSF);
    $open = array_values(array_filter($all, static fn ($r) => $r['status'] === 'open'));
    check('(H3) cancelar la transferencia reabre la necesidad', count($open) === 1 && $open[0]['needid'] === $needBId,
        json_encode($all), $failures, $checks);
    check('(H4) sin abrir un duplicado por la reversa del stock', count($all) === 1, json_encode($all), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (I) cierre manual ===\n";
    $threw = false;
    try {
        $svc->close($companyId, $adminId, $needBId, '   ', null);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    check('(I1) sin motivo, el servicio rechaza', $threw, 'close() aceptó un motivo vacío', $failures, $checks);
    $threw = false;
    try {
        ncmExecute("UPDATE replenishment_need SET status = 'closed' WHERE needid = ?", [$needBId]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    check('(I2) sin motivo, la base rechaza', $threw, 'el UPDATE a closed sin motivo pasó', $failures, $checks);
    $closed = $svc->close($companyId, $adminId, $needBId, 'Se discontinúa en esa sucursal', null);
    check('(I3) con motivo, queda cerrada con autor', $closed['status'] === 'closed' && $closed['closeReason'] === 'Se discontinúa en esa sucursal',
        json_encode($closed), $failures, $checks);
    move($companyId, OUTLET_B, IT_TRANSF, 1, '-');
    check('(I4) cerrada no bloquea: el próximo movimiento bajo mínimo abre otra',
        count(array_filter(needsFor($companyId, OUTLET_B, IT_TRANSF), static fn ($r) => $r['status'] === 'open')) === 1,
        json_encode(needsFor($companyId, OUTLET_B, IT_TRANSF)), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════
    echo "\n=== (J) tenant y sucursal ===\n";
    upsertItem(IT_MX, 'Repo MX', MX_COMPANY, 10, 5);
    move(MX_COMPANY, MX_OUTLET, IT_MX, 1, '+');
    $mxNeed = needsFor(MX_COMPANY, MX_OUTLET, IT_MX)[0] ?? null;
    check('(J0) el otro tenant tiene su necesidad', $mxNeed !== null, 'sin necesidad MX', $failures, $checks);

    $list = $svc->list($companyId, [], null);
    $ids  = array_column($list, 'needId');
    check('(J1) el listado no trae necesidades de otro tenant', !in_array($mxNeed['needid'] ?? '', $ids, true) && $ids !== [],
        'ids: ' . json_encode($ids), $failures, $checks);
    check('(J2) ni por id', $svc->get($companyId, (string) ($mxNeed['needid'] ?? ''), null) === null, 'get() devolvió la de MX', $failures, $checks);
    $threw = false;
    try {
        $svc->close($companyId, $adminId, (string) ($mxNeed['needid'] ?? ''), 'x', null);
    } catch (\InvalidArgumentException $e) {
        $threw = (int) $e->getCode() === 404;
    }
    check('(J3) ni se puede operar', $threw, 'close() operó sobre otro tenant', $failures, $checks);

    // Alcance por sucursal: el usuario queda asignado SOLO a la B.
    ncmExecute('INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?, ?, ?)', [$adminId, OUTLET_B, $companyId]);
    $scope = OutletScope::forUser($companyId, $adminId);
    $scoped = $svc->list($companyId, [], $scope === [] ? null : $scope);
    $outlets = array_values(array_unique(array_column($scoped, 'outletId')));
    check('(J4) con sucursales asignadas ve solo las suyas', $outlets === [OUTLET_B], json_encode($outlets), $failures, $checks);
    check('(J5) y no puede operar una de otra sucursal',
        $svc->get($companyId, $needId, $scope === [] ? null : $scope) === null,
        'get() devolvió una necesidad de la sucursal A', $failures, $checks);
    check('(J6) filtro por estado', array_filter($svc->list($companyId, ['status' => 'covered'], null), static fn ($r) => $r['status'] !== 'covered') === [],
        'el filtro por estado trajo otros estados', $failures, $checks);
    $feed = $svc->openForNotifications($companyId, [OUTLET_B]);
    check('(J7) notificaciones: solo abiertas y del alcance',
        $feed !== [] && array_filter($feed, static fn ($r) => $r['status'] !== 'open' || $r['outletId'] !== OUTLET_B) === [],
        json_encode($feed), $failures, $checks);
} finally {
    ncmExecute('DELETE FROM contact_outlet WHERE contactid = ? AND companyid = ?', [$adminId, $companyId]);
    try {
        $db->Execute('DROP TRIGGER IF EXISTS repo_test_boom ON replenishment_need');
    } catch (\Throwable $e) {
    }
}

harnessFinish($failures, $checks);
