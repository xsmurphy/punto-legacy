<?php
declare(strict_types=1);

/**
 * run_sale_chain.php — arnés end-to-end del proceso de venta (ver
 * README.md en este directorio). Corre los casos de `fixtures.json` para UN tenant contra
 * `SaleService::save()` REAL (mismo camino que /api/v1/sales.php), sin
 * mocks: DB Postgres real, motor de impuestos real, persistencia real.
 *
 * Por qué un tenant por proceso: `data.php` define COMPANY_ID/OUTLET_ID/
 * USER_ID/etc. como constantes PHP — solo se pueden definir una vez por
 * proceso. `run.sh` invoca este script una vez por companyId de
 * `fixtures.json`.
 *
 * Verifica:
 *   1. Lo persistido en `itemSold` (itemSoldTotal/itemSoldTax/itemSoldUnits)
 *      y `transaction` (transactionTax/transactionTotal/transactionUnitsSold)
 *      contra los valores calculados A MANO en fixtures.json (no contra el
 *      propio código — comparar el motor con el motor no prueba nada).
 *   2. El desglose `toTaxObj` por tasa: suma correctamente y
 *      Σ(byRate.amount) == transactionTax (el pedido central del owner: "que
 *      se sume bien el IVA por cada tipo de IVA").
 *   3. Facturación electrónica (solo tenant PY): el payload que arma
 *      `EInvoiceService::buildSaleArrayForMapper()` + `SaleToInvoiceMapper`
 *      usa el IVA CONGELADO de la venta (no el catálogo) y mapea las tasas
 *      correctas para SIFEN — sin llamar a Factomate ni a ninguna red.
 *   4. Escribe un dump JSON por caso (shape `TicketableTransaction`) para
 *      que `frontend/lib/hardware/printers/verify-chain.mts` verifique la
 *      impresión sobre la MISMA venta persistida.
 *
 * Uso: ver verify_chain/run.sh (orquesta ambos tenants + el paso de Node).
 *   php api/lib/Sales/verify_chain/run_sale_chain.php <companyId>
 *
 * Exit code 0 si todo pasa, 1 si algún caso o assertion falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;

$companyIdArg = $argv[1] ?? '';
if ($companyIdArg === '') {
    fwrite(STDERR, "Uso: php run_sale_chain.php <companyId>\n");
    exit(1);
}

// ── Fixtures de tenant conocidas (ver seed.sql) — mapea companyId a los ids
//    de outlet/register/user que el seed crea para ese tenant. ──────────────
$TENANTS = [
    '0ea6c5d8-57e5-4226-8140-ec914deec024' => [
        'outletId'   => '1a282724-6073-49c3-8bc3-0114a132e349',
        'registerId' => '81c541da-640e-4891-a1a0-b32841e64c75',
        'userId'     => '3e52da17-74a2-49c3-9d07-8d4806671fd5',
    ],
    'fa8cf679-9003-417e-8726-5b772d3b6e88' => [
        'outletId'   => '6d3cab3a-c040-4428-8090-6790469de3bd',
        'registerId' => 'e91e3e74-b593-4833-9ee8-25b8ce9e4454',
        'userId'     => '999986f1-05fe-4d91-841f-156a090e7a15',
    ],
];

if (!isset($TENANTS[$companyIdArg])) {
    fwrite(STDERR, "companyId desconocido: {$companyIdArg}\n");
    exit(1);
}

// ── Reconstruye lo que `apiAuthTenant()` hace en el request real, sin JWT:
//    define COMPANY_ID/OUTLET_ID/USER_ID/etc. vía data.php (mismo archivo
//    que usa el endpoint), a partir de las locales de abajo. ────────────────
$companyId  = $companyIdArg;
$outletId   = $TENANTS[$companyIdArg]['outletId'];
$userId     = $TENANTS[$companyIdArg]['userId'];
$registerId = $TENANTS[$companyIdArg]['registerId'];
$roleId     = '1';
require API_APP_DIR . '/data.php';

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);

$fixturesPath = __DIR__ . '/fixtures.json';
$allCases     = json_decode((string) file_get_contents($fixturesPath), true);
if (!is_array($allCases)) {
    fwrite(STDERR, "fixtures.json inválido: " . json_last_error_msg() . "\n");
    exit(1);
}

$cases = array_values(array_filter($allCases, static fn ($c) => $c['company'] === $companyId));

$outDir = sys_get_temp_dir() . '/punto-verify-chain';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$failures = 0;
$total    = 0;

/** Compara con tolerancia 0 (los esperados ya vienen redondeados a `decimals`). */
function assertEq(string $label, $expected, $actual, int &$failures): void
{
    $ok = (is_float($expected) || is_int($expected) || is_float($actual) || is_int($actual))
        ? (round((float) $expected, 6) === round((float) $actual, 6))
        : ($expected === $actual);
    if ($ok) {
        echo "  PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$label}\n";
    echo '        esperado: ' . var_export($expected, true) . "\n";
    echo '        obtenido: ' . var_export($actual, true) . "\n";
}

foreach ($cases as $case) {
    $total++;
    echo "\n=== {$case['id']} — {$case['description']} ===\n";

    // ── Resolver itemId de catálogo por SKU (fixture, ver seed.sql). Salvo
    //    `itemIdOverride` (caso de aislamiento multi-tenant): referencia a
    //    propósito el itemId REAL de OTRO tenant, sin pasar por el lookup
    //    scoped a companyId — así se ejercita el mismo camino que un payload
    //    manipulado por un cliente malicioso. ─────────────────────────────
    $lines = [];
    foreach ($case['lines'] as $i => $l) {
        if (isset($l['itemIdOverride'])) {
            $itemId = $l['itemIdOverride'];
        } else {
            $row = $db->Execute(
                'SELECT itemId FROM item WHERE itemSKU = ? AND companyId = ? LIMIT 1',
                [$l['itemSku'], $companyId]
            );
            if (!$row || $row->EOF) {
                echo "  FAIL  no se encontró item con SKU {$l['itemSku']} — revisar seed.sql\n";
                $failures++;
                continue 2;
            }
            $itemId = (string) $row->fields['itemid'];
        }

        $grossBase = $l['qty'] * $l['unitPrice'];
        // `discount` (porcentaje efectivo) espeja lo que manda el front real
        // (allocateLineDiscounts → SaleItem.discount, frontend/lib/commands/
        // create-sale.ts:331) — NO es el monto, es el % de esa línea. El monto
        // real es `totalDiscount`. Ver hallazgo del reporte sobre el bloque
        // de impresión `item_discount`, que confunde estos dos campos.
        $discountPct = $grossBase > 0 ? round($l['discount'] / $grossBase * 100, 4) : 0;

        $lines[] = [
            'itemId'        => $itemId,
            'count'         => $l['qty'],
            'name'          => $l['itemSku'],
            'uniPrice'      => $l['unitPrice'],
            'price'         => $l['unitPrice'],
            'total'         => $l['expected']['gross'],
            'tax'           => 0,
            'discount'      => $discountPct,
            'totalDiscount' => $l['discount'],
            'user'          => '',
            'type'          => '',
            'date'          => '',
            'note'          => '',
            'currency'      => '',
            'uId'           => 0,
        ];
    }
    if (count($lines) !== count($case['lines'])) {
        continue; // ya se contó el fallo arriba
    }

    $uid = $case['id'] . '-' . bin2hex(random_bytes(6));
    $payload = [
        'transaction' => [
            'uid'      => $uid,
            'type'     => 0, // Cashsale
            'sale'     => $lines,
            'subtotal' => $case['expectedTotals']['gross'],
            'tax'      => 0,
            'discount' => 0,
            'payment'  => [
                ['type' => 'cash', 'name' => 'Efectivo', 'total' => $case['expectedTotals']['gross']],
            ],
            'date'      => date('Y-m-d H:i:s'),
            'timestamp' => time(),
        ],
    ];

    try {
        $input  = SaleInput::fromPayload($payload);
        $result = $service->save($input);
    } catch (InvalidSaleInputException|SaleAbortedException|DuplicateSaleException $e) {
        echo '  FAIL  la venta no se pudo guardar: ' . $e->getMessage() . "\n";
        $failures++;
        continue;
    }

    $transId = $result->transactionId;
    echo "  venta creada: transactionId={$transId}\n";

    // ── 1. itemSold por línea (match por itemId — cada línea del caso usa un
    //    SKU distinto, así que itemId identifica la línea sin depender del
    //    orden de SELECT). ────────────────────────────────────────────────
    $soldRows = [];
    $rs = $db->Execute(
        'SELECT itemId, itemSoldTotal, itemSoldTax, itemSoldUnits FROM itemSold WHERE transactionId = ?',
        [$transId]
    );
    while ($rs && !$rs->EOF) {
        $soldRows[(string) $rs->fields['itemid']] = $rs->fields;
        $rs->MoveNext();
    }

    foreach ($case['lines'] as $i => $l) {
        $itemId = $lines[$i]['itemId'];
        $sold   = $soldRows[$itemId] ?? null;
        if ($sold === null) {
            echo "  FAIL  línea {$i} ({$l['itemSku']}): no se encontró itemSold\n";
            $failures++;
            continue;
        }
        assertEq("línea {$i} ({$l['itemSku']}) itemSoldTax", $l['expected']['tax'], (float) $sold['itemsoldtax'], $failures);
        assertEq("línea {$i} ({$l['itemSku']}) itemSoldTotal", $l['expected']['gross'], (float) $sold['itemsoldtotal'], $failures);
        assertEq("línea {$i} ({$l['itemSku']}) itemSoldUnits (decimal)", (float) $l['qty'], (float) $sold['itemsoldunits'], $failures);
    }

    // ── 2. transaction: totales + unidades ──────────────────────────────
    $txRow = $db->Execute(
        'SELECT transactionTax, transactionTotal, transactionUnitsSold FROM transaction WHERE transactionId = ?',
        [$transId]
    );
    if ($txRow && !$txRow->EOF) {
        assertEq('transaction.transactionTax', $case['expectedTotals']['tax'], (float) $txRow->fields['transactiontax'], $failures);
        assertEq('transaction.transactionTotal', $case['expectedTotals']['gross'], (float) $txRow->fields['transactiontotal'], $failures);
        assertEq('transaction.transactionUnitsSold (decimal)', (float) $case['expectedUnitsSold'], (float) $txRow->fields['transactionunitssold'], $failures);
    } else {
        echo "  FAIL  no se encontró la fila transaction\n";
        $failures++;
    }

    // ── 3. toTaxObj: desglose por tasa — Σ(amount) tiene que cerrar con
    //    transactionTax (aserción central del pedido del owner). ──────────
    $taxObjRow = $db->Execute('SELECT toTaxObjText FROM toTaxObj WHERE transactionId = ?', [$transId]);
    $byRate    = [];
    if ($taxObjRow && !$taxObjRow->EOF) {
        $decoded = json_decode((string) $taxObjRow->fields['totaxobjtext'], true);
        $byRate  = is_array($decoded) ? $decoded : [];
    }
    assertEq('toTaxObj: cantidad de buckets por tasa', count($case['expectedByRate']), count($byRate), $failures);
    foreach ($case['expectedByRate'] as $expBucket) {
        $match = null;
        foreach ($byRate as $b) {
            if ((float) $b['rate'] === (float) $expBucket['rate'] && $b['kind'] === $expBucket['kind']) {
                $match = $b;
                break;
            }
        }
        $label = "toTaxObj bucket rate={$expBucket['rate']} kind={$expBucket['kind']}";
        if ($match === null) {
            echo "  FAIL  {$label}: no se encontró en el desglose persistido\n";
            $failures++;
            continue;
        }
        assertEq("{$label} base", $expBucket['base'], (float) $match['base'], $failures);
        assertEq("{$label} amount", $expBucket['amount'], (float) $match['amount'], $failures);
    }
    $sumByRateAmount = array_sum(array_column($byRate, 'amount'));
    assertEq('Σ(toTaxObj.amount) == transactionTax', $case['expectedTotals']['tax'], $sumByRateAmount, $failures);
    $sumItemSoldTotal = array_sum(array_map(static fn ($r) => (float) $r['itemsoldtotal'], $soldRows));
    assertEq('Σ(itemSold.itemSoldTotal) == transactionTotal', $case['expectedTotals']['gross'], $sumItemSoldTotal, $failures);

    // ── 4. Facturación electrónica (solo donde el caso lo pide) ─────────
    if (($case['checkEInvoice'] ?? 'skip') !== 'skip') {
        verifyEInvoice($case, $transId, $companyId, $failures);
    }

    // ── 5. Dump para el paso de impresión (Node) ─────────────────────────
    $metaRow = $db->Execute('SELECT meta, transactionPaymentType, transactionDiscount, transactionTotal, transactionTax FROM transaction WHERE transactionId = ?', [$transId]);
    $meta    = json_decode((string) $metaRow->fields['meta'], true);
    $transactionDatas = json_decode((string) ($meta['transactionDetails'] ?? '[]'), true);
    $paymentsRaw = json_decode((string) $metaRow->fields['transactionpaymenttype'], true);
    $dump = [
        'caseId'   => $case['id'],
        'decimals' => $case['decimals'],
        // Moneda/separadores del tenant (fixtures.json) — el paso de Node
        // arma con esto el `config` (PosConfig) que le pasa a
        // buildTicketDataFromTransaction, para probar que formatMoney
        // (blocks.ts) sale de la config real y no de un hardcode "es-PY"
        // (hallazgo #2 del reporte de esta tarea).
        'moneyConfig' => [
            'currency' => $case['currency'],
            'thousand' => $case['thousand'],
            'decimal'  => $case['decimals'] === 2 ? 'yes' : 'no',
        ],
        'transaction' => [
            'transactionId'          => $transId,
            'customerName'           => null,
            'documentNo'             => null,
            'invoicePrefix'          => null,
            'date'                   => date('c'),
            'discount'               => (float) $metaRow->fields['transactiondiscount'],
            'total'                  => (float) $metaRow->fields['transactiontotal'],
            'note'                   => null,
            'transactionDatas'       => $transactionDatas,
            'pMethods'               => array_map(
                static fn ($p) => ['name' => $p['name'] ?? '', 'type' => $p['type'] ?? '', 'amount' => (float) ($p['total'] ?? 0)],
                is_array($paymentsRaw) ? $paymentsRaw : []
            ),
        ],
        'expectedByRate'    => $case['expectedByRate'],
        'expectedTotals'    => $case['expectedTotals'],
        'expectedLines'     => $case['lines'],
    ];
    file_put_contents("{$outDir}/{$case['id']}.json", json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "  dump escrito: {$outDir}/{$case['id']}.json\n";
}

/**
 * Verifica EInvoiceService::buildSaleArrayForMapper() (privado, se invoca
 * por Reflection — es puro lectura de BD, SIN llamar a Factomate ni red) y,
 * cuando aplica, que SaleToInvoiceMapper::build() arme el payload esperado
 * con timbrado/config FALSOS (no se emite nada de verdad).
 */
function verifyEInvoice(array $case, string $transId, string $companyId, int &$failures): void
{
    $svc = new EInvoiceService();
    $ref = new ReflectionMethod($svc, 'buildSaleArrayForMapper');
    $ref->setAccessible(true);

    try {
        $sale = $ref->invoke($svc, $companyId, $transId, 'FCT');
    } catch (\Throwable $e) {
        if (($case['checkEInvoice'] ?? '') === 'rejects') {
            $msg = $e->getMessage();
            $allFound = true;
            foreach ($case['expectedEInvoiceErrorContains'] ?? [] as $needle) {
                if (!str_contains($msg, $needle)) {
                    $allFound = false;
                }
            }
            if ($allFound) {
                echo "  PASS  EInvoice rechaza con error claro: {$msg}\n";
            } else {
                echo "  FAIL  EInvoice rechazó, pero el mensaje no contiene lo esperado\n";
                echo '        esperado que contenga: ' . implode(', ', $case['expectedEInvoiceErrorContains']) . "\n";
                echo "        obtenido: {$msg}\n";
                $failures++;
            }
            return;
        }
        echo "  FAIL  EInvoice: buildSaleArrayForMapper lanzó inesperadamente: {$e->getMessage()}\n";
        $failures++;
        return;
    }

    if (($case['checkEInvoice'] ?? '') === 'rejects') {
        echo "  FAIL  EInvoice: se esperaba que rechazara (tasa no admitida por SIFEN) y no lo hizo\n";
        $failures++;
        return;
    }

    if ($sale === null) {
        echo "  FAIL  EInvoice: buildSaleArrayForMapper devolvió null\n";
        $failures++;
        return;
    }

    $gotRates = array_column($sale['items'], 'taxRate');
    assertEq('EInvoice items[].taxRate (SIFEN 10|5|0, IVA CONGELADO de la venta)', $case['expectedEInvoiceTaxRates'] ?? [], $gotRates, $failures);
    assertEq('EInvoice total == transactionTotal', $case['expectedTotals']['gross'], $sale['total'], $failures);

    // Payload final con timbrado/config de PRUEBA — no se emite, solo se
    // arma el JSON para confirmar que el mapper cierra sin llamar red.
    $mapper = new \Punto\Api\EInvoice\SaleToInvoiceMapper();
    try {
        $doc = $mapper->build($sale, ['Id' => 'test-stamp'], ['series' => 'AA'], date('Y-m-d\TH:i:s'));
        assertEq('EInvoice payload.total (mapper)', $case['expectedTotals']['gross'], (float) $doc['total'], $failures);
        echo "  PASS  SaleToInvoiceMapper::build() arma el documento sin llamar red\n";
    } catch (\Throwable $e) {
        echo "  FAIL  SaleToInvoiceMapper::build() lanzó inesperadamente: {$e->getMessage()}\n";
        $failures++;
    }

    // ── Prueba de que usa el IVA CONGELADO, no el catálogo ──────────────
    // Solo para el caso principal (py-multi-rate): mutamos la tasa del
    // ítem VERIFY-10-INC en el catálogo DESPUÉS de vendido y confirmamos
    // que el payload de la venta YA EMITIDA no cambia (sigue en 10, no en
    // la nueva tasa del catálogo).
    if ($case['id'] === 'py-multi-rate') {
        global $db;
        // try/finally: si invoke() lanza, la tasa NO puede quedar mutada a 99
        // para el resto de la sesión de este Postgres — seed.sql la
        // restauraría en el PRÓXIMO run.sh, pero un run posterior en la MISMA
        // sesión (ej. correrlo dos veces contra un Postgres reusado) vería la
        // tasa corrupta.
        $db->Execute("UPDATE tax SET rate = 99 WHERE taxId = '3cf780bb-51d6-4b41-b52d-1e77bfb60969'");
        try {
            $saleAfterCatalogChange = $ref->invoke($svc, $companyId, $transId, 'FCT');
            $ratesAfter = array_column($saleAfterCatalogChange['items'], 'taxRate');
            assertEq('EInvoice usa IVA CONGELADO (no cambia si el catálogo cambia después)', $gotRates, $ratesAfter, $failures);
        } finally {
            $db->Execute("UPDATE tax SET rate = 10 WHERE taxId = '3cf780bb-51d6-4b41-b52d-1e77bfb60969'");
        }
    }
}

echo "\n";
echo "companyId {$companyId}: " . ($failures === 0 ? 'TODOS los casos OK' : "{$failures} assertion(es) fallida(s)") . "\n";

exit($failures > 0 ? 1 : 0);
