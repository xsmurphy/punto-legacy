<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés (Postgres REAL) de la wallet en la CAJA — F2 de context/74, D12-D15.
 *
 * Qué protege:
 *
 *   a. Mig 234: impuesto por bolsillo (default = el primero del catálogo, ''
 *      = sin impuesto, de ESTE comercio) e ítem de sistema; borrar un impuesto
 *      en uso por un bolsillo se frena con un mensaje.
 *   b. La CARGA es una venta: el `load` se escribe en la MISMA transacción,
 *      con modo A, origen = la venta y el monto = el neto de la línea; la
 *      línea usa el ítem de sistema y el impuesto DEL BOLSILLO.
 *   c. Atomicidad: si la transacción de la venta no confirma, no queda ni la
 *      venta ni la carga.
 *   d. Lo que la carga NO acepta: a un hijo, a crédito, sin cliente; y una
 *      venta no puede declarar el medio `wallet` ni el tipo 15.
 *   e. El CONSUMO con saldo (D12/D13): descuenta stock y congela COGS, debita
 *      el bolsillo, numera en el talonario interno, y NO encola factura
 *      electrónica, NO toca el ledger de Finanzas y NO suma a los totales de
 *      ventas que leen los reportes. Reintento por uid = el mismo comprobante.
 *   f. Saldo insuficiente: no escribe NADA (ni comprobante, ni stock, ni
 *      número) y devuelve lo disponible (D14).
 *   g. Dos cajas cobran con saldo el mismo bolsillo a la vez: una sola pasa.
 *   h. El endpoint `/v1/pos-wallet` evalúa `pos.wallet.*` contra el OPERADOR
 *      del PIN (403 sin operador o sin la clave), y `/v1/sales` pide
 *      `pos.wallet.load` para una carga.
 *
 * Uso: bash api/tests/run_wallet_test.sh (corre después de wallet_test.php).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/OperatorAssertion.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Auth\OperatorAssertion;
use Punto\Api\Context\TenantContext;
use Punto\Api\Reports\NonAddingSales;
use Punto\Api\Reports\Roc;
use Punto\Api\Reports\SalesService;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Sales\SaleType;
use Punto\Api\Wallet\WalletInsufficientFundsException;
use Punto\Api\Wallet\WalletLoadItem;
use Punto\Api\Wallet\WalletService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

const WP_MX       = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
const WP_TAX10    = '3cf780bb-51d6-4b41-b52d-1e77bfb60969';
const WP_TAX5     = 'c5f98ab9-9622-446e-9e90-ffb103309828';
const WP_ITEM_STK = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f'; // trackeable
const WP_ITEM_SVC = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // sin stock
const MARCA       = 'wallet-pos-test';

// Clientes del arnés (type=1), distintos de los de wallet_test.php.
const WP_T  = 'a11e7000-0000-4000-8000-000000000301'; // titular
const WP_C  = 'a11e7000-0000-4000-8000-000000000302'; // hijo de WP_T
const WP_R  = 'a11e7000-0000-4000-8000-000000000303'; // titular de la carrera
const WP_H  = 'a11e7000-0000-4000-8000-000000000304'; // titular del endpoint HTTP

/** @var \DB $db */
global $db;

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

function thrown(callable $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

function near(float $a, float $b, float $eps = 0.005): bool
{
    return abs($a - $b) < $eps;
}

function purgeHarness(): void
{
    global $db;
    $all = [WP_T, WP_C, WP_R, WP_H];
    $ph  = implode(',', array_fill(0, count($all), '?'));
    $db->BeginTrans();
    $db->Execute("SELECT set_config('punto.tenant_purge', ?, true)", [$GLOBALS['companyId']]);
    $db->Execute("DELETE FROM wallet_movement WHERE companyid = ? AND contactid IN ($ph)", array_merge([$GLOBALS['companyId']], $all));
    $db->CommitTrans();
    $db->Execute("UPDATE contact SET parentcontactid = NULL WHERE contactid IN ($ph)", $all);
    $db->Execute("DELETE FROM wallet_pocket WHERE companyid = ? AND name LIKE 'Caja Arnés %'", [$GLOBALS['companyId']]);
}

function uid(string $tag): string
{
    return 'wpos-' . $tag . '-' . bin2hex(random_bytes(5));
}

function txCount(string $uid): int
{
    $r = ncmExecute('SELECT COUNT(*) AS n FROM transaction WHERE transactionuid = ?', [$uid]);
    return (int) ($r['n'] ?? 0);
}

function movementsBySource(string $transId): array
{
    global $db;
    $rs = $db->Execute(
        'SELECT type, amount, billingmode, sourcetype, contactid, pocketid, actorcontactid
           FROM wallet_movement WHERE sourceid = ? ORDER BY seq',
        [$transId]
    );
    return $rs ? $rs->GetRows() : [];
}

$nextNo = 700000 + random_int(1, 90000);

/** Línea de venta en el shape del POS. */
function line(array $over): array
{
    return array_merge([
        'itemId' => '', 'count' => 1, 'name' => 'x', 'uniPrice' => 0, 'price' => 0, 'total' => 0,
        'tax' => 0, 'discount' => 0, 'totalDiscount' => 0, 'user' => '', 'type' => '', 'date' => '',
        'note' => '', 'currency' => '', 'uId' => 0,
    ], $over);
}

/** Payload de venta contado (type 0) del POS. */
function salePayload(string $uid, int $invoiceNo, ?string $client, array $lines, int $type = 0, ?array $payment = null): array
{
    $subtotal = array_sum(array_map(fn ($l) => (float) $l['total'], $lines));
    $discount = array_sum(array_map(fn ($l) => (float) $l['totalDiscount'], $lines));
    return ['transaction' => [
        'uid' => $uid, 'type' => $type, 'invoiceno' => $invoiceNo, 'sale' => $lines,
        'subtotal' => $subtotal, 'tax' => 0, 'discount' => $discount,
        'payment' => $payment ?? [['type' => 'cash', 'name' => 'Efectivo', 'total' => $subtotal - $discount]],
        'date' => date('Y-m-d H:i:s'), 'timestamp' => time(), 'client' => $client,
    ]];
}

function consumptionPayload(string $uid, string $client, array $lines): array
{
    return [
        'uid' => $uid, 'client' => $client, 'sale' => $lines,
        'subtotal' => array_sum(array_map(fn ($l) => (float) $l['total'], $lines)),
        'discount' => array_sum(array_map(fn ($l) => (float) $l['totalDiscount'], $lines)),
        'timestamp' => time(), 'date' => date('Y-m-d H:i:s'),
    ];
}

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);
$wallet  = new WalletService();
$modules = new \Punto\Api\Modules\ModulesService();

// ── Fixture ──────────────────────────────────────────────────────────────────
foreach ([[WP_T, 'Caja Titular'], [WP_C, 'Caja Hijo'], [WP_R, 'Caja Carrera'], [WP_H, 'Caja HTTP']] as [$cid, $name]) {
    $db->Execute(
        'INSERT INTO contact (contactId, contactName, companyId, type, contactStatus)
         VALUES (?, ?, ?, 1, 1)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName, contactStatus = 1',
        [$cid, $name, $companyId]
    );
}
purgeHarness();
$modules->toggle($companyId, 'wallet', true);

$deviceIds = [];

try {
    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (a) mig 234: impuesto del bolsillo e ítem de sistema ===\n";
    $cols = ncmExecute(
        "SELECT COUNT(*) AS n FROM information_schema.columns
          WHERE table_schema = current_schema()
            AND ((table_name = 'wallet_pocket' AND column_name = 'taxid')
              OR (table_name = 'item' AND column_name = 'systemkey'))"
    );
    check('(a1) existen wallet_pocket.taxid e item.systemkey', (int) ($cols['n'] ?? 0) === 2, json_encode($cols), $failures, $checks);

    $first = ncmExecute('SELECT taxid FROM tax WHERE companyid = ? ORDER BY sortorder NULLS LAST, name LIMIT 1', [$companyId]);
    $pDef  = $wallet->createPocket($companyId, 'Caja Arnés Default');
    check('(a2) sin impuesto indicado toma el primero del catálogo', $pDef['taxId'] === (string) ($first['taxid'] ?? ''),
        json_encode($pDef) . ' vs ' . json_encode($first), $failures, $checks);
    $pNone = $wallet->createPocket($companyId, 'Caja Arnés Exenta', '');
    check("(a3) '' = sin impuesto (exenta)", $pNone['taxId'] === null && $pNone['taxKind'] === 'exempt', json_encode($pNone), $failures, $checks);

    $pA = $wallet->createPocket($companyId, 'Caja Arnés Almuerzo', WP_TAX10);
    check('(a4) impuesto elegido, con su tasa resuelta', $pA['taxId'] === WP_TAX10 && near($pA['taxRate'], 10.0) && $pA['taxKind'] === 'rate',
        json_encode($pA), $failures, $checks);
    $pA2 = $wallet->setPocketTax($companyId, $pA['id'], WP_TAX5);
    check('(a5) cambiar el impuesto del bolsillo', $pA2['taxId'] === WP_TAX5, json_encode($pA2), $failures, $checks);
    $wallet->setPocketTax($companyId, $pA['id'], WP_TAX10);

    $mxTax = ncmExecute('SELECT taxid FROM tax WHERE companyid = ? LIMIT 1', [WP_MX]);
    if ($mxTax && !empty($mxTax['taxid'])) {
        $e = thrown(fn () => $wallet->setPocketTax($companyId, $pA['id'], (string) $mxTax['taxid']));
        check('(a6) un impuesto de OTRO comercio se rechaza', $e instanceof \Punto\Api\Wallet\WalletException, (string) $e?->getMessage(), $failures, $checks);
    }
    $e = thrown(fn () => (new \Punto\Api\Taxes\TaxService($db))->delete($companyId, WP_TAX10));
    check('(a7) borrar un impuesto en uso por un bolsillo se frena con motivo',
        $e instanceof \RuntimeException && str_contains($e->getMessage(), 'Caja Arnés Almuerzo'),
        (string) $e?->getMessage(), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (b) la carga es una venta con su load adentro ===\n";
    $uB = uid('load');
    $res = $service->save(SaleInput::fromPayload(salePayload($uB, $nextNo++, WP_T, [
        line(['name' => 'Carga', 'uniPrice' => 55000, 'price' => 55000, 'total' => 55000, 'walletLoad' => ['pocketId' => $pA['id']]]),
        // Segunda carga en la misma venta, con descuento de 5.000: entra el NETO.
        line(['name' => 'Carga', 'uniPrice' => 20000, 'price' => 20000, 'total' => 20000, 'totalDiscount' => 5000, 'walletLoad' => ['pocketId' => $pA['id']]]),
    ]), $companyId));
    $tB   = $res->transactionId;
    $movs = movementsBySource($tB);
    check('(b1) dos movimientos load con origen = la venta', count($movs) === 2
        && $movs[0]['type'] === 'load' && $movs[0]['sourcetype'] === 'sale' && $movs[0]['contactid'] === WP_T,
        json_encode($movs), $failures, $checks);
    check('(b2) modo A congelado y autor = el usuario de la venta',
        ($movs[0]['billingmode'] ?? '') === 'A' && ($movs[0]['actorcontactid'] ?? '') === $adminId, json_encode($movs), $failures, $checks);
    check('(b3) el monto cargado es el NETO de cada línea (55.000 y 15.000)',
        near((float) $movs[0]['amount'], 55000) && near((float) ($movs[1]['amount'] ?? 0), 15000), json_encode($movs), $failures, $checks);
    check('(b4) saldo del bolsillo = 70.000', near($wallet->balance($companyId, WP_T, $pA['id']), 70000),
        (string) $wallet->balance($companyId, WP_T, $pA['id']), $failures, $checks);

    $sysItem = WalletLoadItem::find($companyId);
    $sold    = $db->Execute('SELECT itemid, itemsoldtax, itemsoldtotal FROM itemSold WHERE transactionid = ? ORDER BY itemsoldtotal DESC', [$tB])->GetRows();
    check('(b5) las líneas usan el ítem de sistema "Carga de saldo"', $sysItem !== null
        && count($sold) === 2 && (string) $sold[0]['itemid'] === $sysItem, json_encode($sold) . " sys=$sysItem", $failures, $checks);
    check('(b6) el IVA de la línea es el del BOLSILLO (10% incluido de 55.000)',
        near((float) $sold[0]['itemsoldtax'], 55000 / 11, 1.0), json_encode($sold), $failures, $checks);
    $item = ncmExecute('SELECT systemkey, itemtrackinventory FROM item WHERE itemid = ?', [$sysItem]);
    $outs = ncmExecute('SELECT COUNT(*) AS n FROM item_outlet WHERE itemid = ?', [$sysItem]);
    check('(b7) ítem de sistema: clave wallet_load, sin stock, en ninguna caja',
        ($item['systemkey'] ?? '') === 'wallet_load' && !in_array($item['itemtrackinventory'] ?? null, [true, 't', 1, '1'], true)
        && (int) ($outs['n'] ?? -1) === 0, json_encode([$item, $outs]), $failures, $checks);
    check('(b8) ensure() es idempotente (un solo ítem por comercio)',
        WalletLoadItem::ensure($companyId) === $sysItem
        && (int) (ncmExecute("SELECT COUNT(*) AS n FROM item WHERE companyid = ? AND systemkey = 'wallet_load'", [$companyId])['n'] ?? 0) === 1,
        'hay más de uno', $failures, $checks);
    $e = thrown(fn () => (new \Punto\Api\Items\ItemService(new \Punto\Api\Items\ItemRepository($db)))->update($sysItem, $companyId, ['itemName' => 'hackeado']));
    $nameNow = ncmExecute('SELECT itemname FROM item WHERE itemid = ?', [$sysItem]);
    check('(b9) el panel no puede editar el ítem de sistema', ($nameNow['itemname'] ?? '') === WalletLoadItem::NAME,
        json_encode($nameNow) . ' ' . (string) $e?->getMessage(), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (c) atomicidad: la venta no confirma ⇒ no hay carga ===\n";
    // Un constraint trigger DIFERIDO sobre wallet_movement revienta el COMMIT
    // (después de que la venta y el load ya se escribieron) cuando la sesión
    // tiene la marca del arnés. Es la forma más fiel de "la transacción de la
    // venta falla al final": si el load viviera en otra transacción, sobreviviría.
    $db->Execute("CREATE OR REPLACE FUNCTION wallet_pos_test_fail_commit() RETURNS trigger LANGUAGE plpgsql AS \$f\$
        BEGIN
          IF current_setting('punto.wallet_pos_test_fail', true) = '1' THEN
            RAISE EXCEPTION 'arnés: el commit de la venta falla a propósito';
          END IF;
          RETURN NULL;
        END \$f\$");
    $db->Execute('DROP TRIGGER IF EXISTS trg_wallet_pos_test_fail ON wallet_movement');
    $db->Execute('CREATE CONSTRAINT TRIGGER trg_wallet_pos_test_fail AFTER INSERT ON wallet_movement
                  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION wallet_pos_test_fail_commit()');
    $balBefore = $wallet->balance($companyId, WP_T, $pA['id']);
    $uC = uid('rollback');
    $db->Execute("SELECT set_config('punto.wallet_pos_test_fail', '1', false)");
    $e = thrown(fn () => $service->save(SaleInput::fromPayload(salePayload($uC, $nextNo++, WP_T, [
        line(['uniPrice' => 9000, 'price' => 9000, 'total' => 9000, 'walletLoad' => ['pocketId' => $pA['id']]]),
    ]), $companyId)));
    $db->Execute("SELECT set_config('punto.wallet_pos_test_fail', '0', false)");
    $db->Execute('DROP TRIGGER IF EXISTS trg_wallet_pos_test_fail ON wallet_movement');
    check('(c1) el commit falló', $e !== null, 'no lanzó', $failures, $checks);
    check('(c2) no quedó la venta', txCount($uC) === 0, 'la venta persistió', $failures, $checks);
    check('(c3) y tampoco la carga', near($wallet->balance($companyId, WP_T, $pA['id']), $balBefore),
        $wallet->balance($companyId, WP_T, $pA['id']) . " vs $balBefore", $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (d) lo que una carga no acepta ===\n";
    $wallet->setParent($companyId, WP_C, WP_T, $adminId);
    $uD = uid('child');
    $e = thrown(fn () => $service->save(SaleInput::fromPayload(salePayload($uD, $nextNo++, WP_C, [
        line(['uniPrice' => 1000, 'price' => 1000, 'total' => 1000, 'walletLoad' => ['pocketId' => $pA['id']]]),
    ]), $companyId)));
    check('(d1) carga a un HIJO rechazada', $e instanceof InvalidSaleInputException && str_contains($e->getMessage(), 'a cargo'),
        (string) $e?->getMessage(), $failures, $checks);
    check('(d2) y no quedó ninguna venta', txCount($uD) === 0, 'persistió', $failures, $checks);

    $e = thrown(fn () => SaleInput::fromPayload(salePayload(uid('cred'), $nextNo++, WP_T, [
        line(['uniPrice' => 1000, 'price' => 1000, 'total' => 1000, 'walletLoad' => ['pocketId' => $pA['id']]]),
    ], 3), $companyId));
    check('(d3) carga a crédito rechazada', $e instanceof InvalidSaleInputException, (string) $e?->getMessage(), $failures, $checks);

    $uNoClient = uid('noclient');
    $e = thrown(fn () => $service->save(SaleInput::fromPayload(salePayload($uNoClient, $nextNo++, null, [
        line(['uniPrice' => 1000, 'price' => 1000, 'total' => 1000, 'walletLoad' => ['pocketId' => $pA['id']]]),
    ]), $companyId)));
    check('(d4) carga sin cliente rechazada', $e instanceof InvalidSaleInputException && txCount($uNoClient) === 0,
        (string) $e?->getMessage(), $failures, $checks);

    $e = thrown(fn () => SaleInput::fromPayload(salePayload(uid('wpay'), $nextNo++, WP_T, [
        line(['itemId' => WP_ITEM_SVC, 'uniPrice' => 1000, 'price' => 1000, 'total' => 1000]),
    ], 0, [['type' => 'wallet', 'name' => 'Saldo', 'total' => 1000]]), $companyId));
    check('(d5) una venta no puede declarar el medio "wallet"', $e instanceof InvalidSaleInputException, (string) $e?->getMessage(), $failures, $checks);

    $e = thrown(fn () => SaleInput::fromPayload(salePayload(uid('t15'), $nextNo++, WP_T, [
        line(['itemId' => WP_ITEM_SVC, 'uniPrice' => 1000, 'price' => 1000, 'total' => 1000]),
    ], SaleType::WalletConsumption->value), $companyId));
    check('(d6) el tipo 15 no entra por /v1/sales ni por la cola offline', $e instanceof InvalidSaleInputException,
        (string) $e?->getMessage(), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (e) consumo con saldo: stock y COGS sí, ingreso/caja/FE no ===\n";
    \Punto\App\Domain\Inventory::manageStock([
        'itemId' => WP_ITEM_STK, 'source' => 'adjustment', 'count' => 20, 'type' => '+', 'cogs' => 400,
        'userId' => $adminId, 'transactionId' => null, 'outletId' => $outletId, 'locationId' => null,
        'note' => MARCA, 'date' => date('Y-m-d H:i:s'), 'companyId' => $companyId,
    ]);
    $stockBefore = \Punto\App\Domain\Inventory::onHand(WP_ITEM_STK, $outletId);

    // Cuenta de FE conectada, para que "no encola" no sea vacío: la venta de
    // control de abajo SÍ encola con esta misma cuenta.
    $db->Execute(
        "INSERT INTO einvoice_account (companyid, provider, username, password_enc, status, environment, config)
         VALUES (?, 'fepy', ?, 'no-usada', 'ok', 'test', '{}'::jsonb)
         ON CONFLICT (companyid) DO UPDATE SET status = 'ok', provider = 'fepy', username = EXCLUDED.username, config = '{}'::jsonb",
        [$companyId, MARCA]
    );

    $today   = [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];
    $roc     = Roc::build($companyId);
    $reports = new SalesService();
    $totals0 = $reports->salesTotals($today[0], $today[1], $roc);
    $pay0    = NonAddingSales::salesByPayment($today[0], $today[1], $roc);
    $seqRow  = fn () => ncmExecute(
        "SELECT nextnumber FROM document_sequence WHERE companyid = ? AND doctype = 'consumo_saldo' AND scopeid = ?",
        [$companyId, $registerId]
    );

    $uE     = uid('consume');
    $pocket = $wallet->findPocket($companyId, $pA['id']);
    $resE   = $service->save(SaleInput::forWalletConsumption(consumptionPayload($uE, WP_T, [
        line(['itemId' => WP_ITEM_STK, 'count' => 2, 'uniPrice' => 3000, 'price' => 3000, 'total' => 6000]),
    ]), $companyId, $pocket, $adminId));
    $tE = $resE->transactionId;
    $tx = ncmExecute('SELECT transactiontype, invoiceno, invoiceauth, drawerid FROM transaction WHERE transactionid = ?', [$tE]);
    check('(e1) comprobante tipo 15 con número interno y SIN timbrado',
        (int) ($tx['transactiontype'] ?? -1) === 15 && (int) ($tx['invoiceno'] ?? 0) > 0 && empty($tx['invoiceauth']),
        json_encode($tx), $failures, $checks);
    check('(e2) descontó el stock (2 unidades)', near(\Punto\App\Domain\Inventory::onHand(WP_ITEM_STK, $outletId), $stockBefore - 2),
        \Punto\App\Domain\Inventory::onHand(WP_ITEM_STK, $outletId) . " vs $stockBefore", $failures, $checks);
    $soldE = ncmExecute('SELECT itemsoldunits, itemsoldcogs FROM itemSold WHERE transactionid = ? LIMIT 1', [$tE]);
    check('(e3) congeló el COGS por línea', ($soldE['itemsoldcogs'] ?? null) !== null && near((float) $soldE['itemsoldcogs'], 400, 1.0),
        json_encode($soldE), $failures, $checks);
    $movE = movementsBySource($tE);
    check('(e4) UN débito del neto (6.000), origen = el comprobante',
        count($movE) === 1 && $movE[0]['type'] === 'spend' && near((float) $movE[0]['amount'], -6000)
        && $movE[0]['sourcetype'] === 'consumption', json_encode($movE), $failures, $checks);
    check('(e5) saldo 70.000 − 6.000 = 64.000', near($wallet->balance($companyId, WP_T, $pA['id']), 64000),
        (string) $wallet->balance($companyId, WP_T, $pA['id']), $failures, $checks);
    $feE = ncmExecute('SELECT COUNT(*) AS n FROM einvoice_document WHERE transactionid = ?', [$tE]);
    check('(e6) NO encoló factura electrónica', (int) ($feE['n'] ?? -1) === 0, json_encode($feE), $failures, $checks);
    $finE = ncmExecute('SELECT COUNT(*) AS n FROM fin_movement WHERE sourceid = ?', [$tE]);
    check('(e7) NO tocó el ledger de Finanzas (caja)', (int) ($finE['n'] ?? -1) === 0, json_encode($finE), $failures, $checks);
    $totals1 = $reports->salesTotals($today[0], $today[1], $roc);
    $pay1    = NonAddingSales::salesByPayment($today[0], $today[1], $roc);
    check('(e8) NO suma a los totales de ventas de los reportes',
        near($totals1['total'], $totals0['total']) && $totals1['count'] === $totals0['count'],
        json_encode([$totals0, $totals1]), $failures, $checks);
    check('(e9) ni aparece como medio de pago en los reportes', json_encode($pay1) === json_encode($pay0),
        json_encode([$pay0, $pay1]), $failures, $checks);

    // Control: con ESA cuenta de FE, una venta normal SÍ encola.
    $uCtl  = uid('control');
    $resCt = $service->save(SaleInput::fromPayload(salePayload($uCtl, $nextNo++, WP_T, [
        line(['itemId' => WP_ITEM_SVC, 'uniPrice' => 11000, 'price' => 11000, 'total' => 11000]),
    ]), $companyId));
    $feC = ncmExecute('SELECT COUNT(*) AS n FROM einvoice_document WHERE transactionid = ?', [$resCt->transactionId]);
    check('(e10) control: una venta normal con la misma cuenta SÍ encola FE', (int) ($feC['n'] ?? 0) === 1,
        json_encode($feC), $failures, $checks);
    $totals2 = $reports->salesTotals($today[0], $today[1], $roc);
    check('(e11) control: la venta normal SÍ suma a los reportes', near($totals2['total'], $totals1['total'] + 11000),
        json_encode([$totals1, $totals2]), $failures, $checks);

    $e = thrown(fn () => $service->save(SaleInput::forWalletConsumption(consumptionPayload($uE, WP_T, [
        line(['itemId' => WP_ITEM_STK, 'count' => 2, 'uniPrice' => 3000, 'price' => 3000, 'total' => 6000]),
    ]), $companyId, $pocket, $adminId)));
    check('(e12) reintento con el mismo uid = el mismo comprobante, sin segundo débito',
        $e instanceof DuplicateSaleException && $e->existing->transactionId === $tE
        && near($wallet->balance($companyId, WP_T, $pA['id']), 64000),
        get_class((object) $e) . ' ' . (string) $e?->getMessage(), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (f) saldo insuficiente: no escribe nada y dice cuánto hay ===\n";
    $stockF = \Punto\App\Domain\Inventory::onHand(WP_ITEM_STK, $outletId);
    $seqF   = (int) ($seqRow()['nextnumber'] ?? 0);
    $uF     = uid('short');
    $e = thrown(fn () => $service->save(SaleInput::forWalletConsumption(consumptionPayload($uF, WP_T, [
        line(['itemId' => WP_ITEM_STK, 'count' => 1, 'uniPrice' => 100000, 'price' => 100000, 'total' => 100000]),
    ]), $companyId, $pocket, $adminId)));
    check('(f1) lanza con lo disponible (64.000)', $e instanceof WalletInsufficientFundsException && near($e->available, 64000),
        get_class((object) $e) . ' ' . (string) $e?->getMessage(), $failures, $checks);
    check('(f2) no quedó comprobante', txCount($uF) === 0, 'persistió', $failures, $checks);
    check('(f3) no se movió el stock', near(\Punto\App\Domain\Inventory::onHand(WP_ITEM_STK, $outletId), $stockF),
        \Punto\App\Domain\Inventory::onHand(WP_ITEM_STK, $outletId) . " vs $stockF", $failures, $checks);
    check('(f4) el número interno volvió (sin hueco)', (int) ($seqRow()['nextnumber'] ?? 0) === $seqF,
        json_encode($seqRow()) . " vs $seqF", $failures, $checks);
    check('(f5) el saldo sigue en 64.000', near($wallet->balance($companyId, WP_T, $pA['id']), 64000),
        (string) $wallet->balance($companyId, WP_T, $pA['id']), $failures, $checks);

    $e = thrown(fn () => SaleInput::forWalletConsumption(consumptionPayload(uid('mixed'), WP_T, [
        line(['uniPrice' => 1000, 'price' => 1000, 'total' => 1000, 'walletLoad' => ['pocketId' => $pA['id']]]),
    ]), $companyId, $pocket, $adminId));
    check('(f6) una carga no se paga con saldo', $e instanceof InvalidSaleInputException, (string) $e?->getMessage(), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (g) dos cajas cobran con saldo el mismo bolsillo a la vez ===\n";
    $pR = $wallet->createPocket($companyId, 'Caja Arnés Carrera', WP_TAX10);
    $service->save(SaleInput::fromPayload(salePayload(uid('rload'), $nextNo++, WP_R, [
        line(['uniPrice' => 5000, 'price' => 5000, 'total' => 5000, 'walletLoad' => ['pocketId' => $pR['id']]]),
    ]), $companyId));
    $cli   = __DIR__ . '/_wallet_consume_once_cli.php';
    $spawn = static function (string $u, int $hold) use ($cli, $pR) {
        $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
                $cli, $u, $pR['id'], WP_R, WP_ITEM_SVC, '5000', (string) $hold];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        return [$proc, $pipes];
    };
    // La caja 1 cobra y retiene la transacción 3 s; la caja 2 llega a mitad de
    // camino y tiene que esperar el lock del bolsillo.
    $u1 = uid('race1');
    $u2 = uid('race2');
    [$p1, $pipes1] = $spawn($u1, 3);
    usleep(1_500_000);
    [$p2, $pipes2] = $spawn($u2, 0);
    $out1 = stream_get_contents($pipes1[1]) . stream_get_contents($pipes1[2]);
    $out2 = stream_get_contents($pipes2[1]) . stream_get_contents($pipes2[2]);
    proc_close($p1);
    proc_close($p2);
    check('(g1) la caja 1 cobra y confirma', str_contains($out1, 'consumed') && str_contains($out1, 'committed'), $out1, $failures, $checks);
    check('(g2) la caja 2 esperó y encontró el bolsillo vacío', str_contains($out2, 'insufficient available=0'), $out2, $failures, $checks);
    check('(g3) un solo comprobante y saldo 0',
        txCount($u1) === 1 && txCount($u2) === 0 && near($wallet->balance($companyId, WP_R, $pR['id']), 0.0),
        'u1=' . txCount($u1) . ' u2=' . txCount($u2) . ' saldo=' . $wallet->balance($companyId, WP_R, $pR['id']), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (h) endpoint de la caja: permisos del OPERADOR ===\n";
    \RoleService::seedCompanyRoles($companyId);
    $roleSin = \RoleService::createRole('wpos-sin-' . bin2hex(random_bytes(3)), ['pos.sale.create'], $companyId, $adminId);
    $roleCon = \RoleService::createRole('wpos-con-' . bin2hex(random_bytes(3)),
        ['pos.sale.create', 'pos.wallet.spend', 'pos.wallet.load'], $companyId, $adminId);
    $mk = function (string $name, string $role) use ($companyId, $db): string {
        $email = strtolower($name) . '.walletpos@test.local';
        $row = ncmExecute('SELECT contactid FROM contact WHERE companyid = ? AND contactemail = ? AND type = 0 LIMIT 1', [$companyId, $email]);
        if ($row && !empty($row['contactid'])) {
            ncmExecute('UPDATE contact SET role = ?, contactstatus = 1 WHERE contactid = ?', [$role, (string) $row['contactid']]);
            return (string) $row['contactid'];
        }
        $rs = $db->Execute(
            'INSERT INTO contact (contactid, companyid, contactname, contactemail, type, contactstatus, role)
             VALUES (gen_random_uuid(), ?, ?, ?, 0, 1, ?) RETURNING contactid',
            [$companyId, $name, $email, $role]
        );
        return (string) ($rs->fields['contactid'] ?? '');
    };
    $sinId = $mk('WposSin', $roleSin);
    $conId = $mk('WposCon', $roleCon);
    $tokSin = OperatorAssertion::issue($companyId, $sinId);
    $tokCon = OperatorAssertion::issue($companyId, $conId);
    $issued = DeviceAuth::issueDeviceToken($companyId, $outletId, $registerId, $adminId,
        'Test device — wallet caja', MARCA, 'test-walletpos-' . bin2hex(random_bytes(6)));
    $deviceIds[] = $issued['deviceId'];
    $bearer = $issued['token'];

    $pH = $wallet->createPocket($companyId, 'Caja Arnés HTTP', WP_TAX10);
    $service->save(SaleInput::fromPayload(salePayload(uid('hload'), $nextNo++, WP_H, [
        line(['uniPrice' => 20000, 'price' => 20000, 'total' => 20000, 'walletLoad' => ['pocketId' => $pH['id']]]),
    ]), $companyId));

    $hit = function (string $endpoint, string $method, string $query, array $body, string $operator) use ($bearer): array {
        $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
                __DIR__ . '/_permission_once_cli.php', $endpoint, $method, $query, json_encode($body), '', $bearer, $operator];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        fwrite($pipes[0], json_encode($body));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $status = 0;
        $data   = null;
        $env    = null;
        if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
            $env = json_decode($m[1], true);
            if (is_array($env)) {
                $status = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
                $data   = $env['data'] ?? null;
            }
        }
        return ['status' => $status, 'data' => $data, 'env' => $env, 'body' => $out . $err];
    };

    $consume = fn (string $u, float $amount) => [
        'pocketId' => $pH['id'],
        'sale'     => consumptionPayload($u, WP_H, [
            line(['itemId' => WP_ITEM_SVC, 'uniPrice' => $amount, 'price' => $amount, 'total' => $amount]),
        ]),
    ];

    $uH1 = uid('h-noop');
    $r = $hit('v1/pos-wallet.php', 'POST', 'resource=consume', $consume($uH1, 1000), '');
    check('(h1) sin operador identificado → 403', $r['status'] === 403 && txCount($uH1) === 0, $r['body'], $failures, $checks);
    $r = $hit('v1/pos-wallet.php', 'POST', 'resource=consume', $consume($uH1, 1000), $tokSin);
    check('(h2) operador SIN pos.wallet.spend → 403 que nombra la clave',
        $r['status'] === 403 && str_contains($r['body'], 'pos.wallet.spend') && txCount($uH1) === 0, $r['body'], $failures, $checks);
    $r = $hit('v1/pos-wallet.php', 'GET', 'resource=balances&contactId=' . WP_H, [], $tokSin);
    check('(h3) sin ninguna pos.wallet.* tampoco ve saldos', $r['status'] === 403, $r['body'], $failures, $checks);

    $r = $hit('v1/pos-wallet.php', 'GET', 'resource=balances&contactId=' . WP_H, [], $tokCon);
    $bal = array_values(array_filter($r['data']['balances'] ?? [], fn ($b) => $b['pocketId'] === $pH['id']));
    check('(h4) con permiso ve el saldo por bolsillo', $r['status'] === 200 && ($r['data']['isChild'] ?? null) === false
        && near((float) ($bal[0]['balance'] ?? -1), 20000), $r['body'], $failures, $checks);
    $r = $hit('v1/pos-wallet.php', 'GET', 'resource=balances&contactId=' . WP_C, [], $tokCon);
    check('(h5) y sabe si el cliente es un hijo', $r['status'] === 200 && ($r['data']['isChild'] ?? null) === true, $r['body'], $failures, $checks);

    $uH2 = uid('h-ok');
    $r = $hit('v1/pos-wallet.php', 'POST', 'resource=consume', $consume($uH2, 8000), $tokCon);
    check('(h6) con permiso cobra: 201, saldo 12.000', $r['status'] === 200 && near((float) ($r['data']['balance'] ?? -1), 12000)
        && (int) ($r['data']['invoiceNo'] ?? 0) > 0, $r['body'], $failures, $checks);
    $autor = ncmExecute("SELECT actorcontactid FROM wallet_movement WHERE sourceid = ? AND type = 'spend'", [(string) ($r['data']['transactionId'] ?? '')]);
    check('(h7) el autor del débito es el OPERADOR, no quien pareó la tablet', ($autor['actorcontactid'] ?? '') === $conId,
        json_encode($autor), $failures, $checks);
    $r = $hit('v1/pos-wallet.php', 'POST', 'resource=consume', $consume($uH2, 8000), $tokCon);
    check('(h8) reintento del mismo uid → duplicated, sin segundo débito',
        $r['status'] === 200 && ($r['data']['duplicated'] ?? null) === true && near($wallet->balance($companyId, WP_H, $pH['id']), 12000),
        $r['body'], $failures, $checks);
    $uH3 = uid('h-short');
    $r = $hit('v1/pos-wallet.php', 'POST', 'resource=consume', $consume($uH3, 50000), $tokCon);
    check('(h9) sin saldo suficiente → 409 con lo disponible, nada escrito',
        $r['status'] === 409 && near((float) ($r['env']['error']['details']['available'] ?? -1), 12000) && txCount($uH3) === 0,
        $r['body'], $failures, $checks);

    // La CARGA por /v1/sales (camino directo): pide pos.wallet.load al operador.
    $loadBody = ['data' => json_encode(salePayload(uid('h-load'), $nextNo++, WP_H, [
        line(['uniPrice' => 1000, 'price' => 1000, 'total' => 1000, 'walletLoad' => ['pocketId' => $pH['id']]]),
    ]))];
    $r = $hit('v1/sales.php', 'POST', '', $loadBody, $tokSin);
    check('(h10) /v1/sales: carga con operador SIN pos.wallet.load → 403',
        $r['status'] === 403 && str_contains($r['body'], 'pos.wallet.load'), $r['body'], $failures, $checks);

    $modules->toggle($companyId, 'wallet', false);
    $r = $hit('v1/pos-wallet.php', 'GET', 'resource=balances&contactId=' . WP_H, [], $tokCon);
    check('(h11) con el módulo apagado la caja no opera la wallet', $r['status'] === 403, $r['body'], $failures, $checks);
    $modules->toggle($companyId, 'wallet', true);
} finally {
    $db->Execute("SELECT set_config('punto.wallet_pos_test_fail', '0', false)");
    $db->Execute('DROP TRIGGER IF EXISTS trg_wallet_pos_test_fail ON wallet_movement');
    $db->Execute('DROP FUNCTION IF EXISTS wallet_pos_test_fail_commit()');
    $db->Execute('DELETE FROM einvoice_account WHERE companyid = ? AND username = ?', [$companyId, MARCA]);
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?', [$did]);
    }
    $modules->toggle($companyId, 'wallet', false);
}

harnessFinish($failures, $checks);
