<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) de la VISTA AGREGADA de Cuentas por Cobrar y
 * por Pagar (`OpenInvoicesService::summary()`, `GET /v1/reports/open_invoices?view=summary`).
 *
 * El dashboard suma plata que el dueño va a mirar para decidir a quién llamar y
 * qué puede pagar esta semana: cada número se calcula acá a mano y se compara
 * contra lo que devuelve el service. Nada de "no explotó, debe estar bien".
 *
 * ── Los casos que importan ───────────────────────────────────────────────────
 *
 * Cobrar (tipo 3, dos clientes para que el top se pueda ordenar):
 *   I1  vencida hace 5 días                        → bucket 0-30
 *   I2  vencida hace 45, con un recibo PARCIAL     → el saldo es el pendiente, no el emitido
 *   I3  vencida hace 80                            → bucket 61-90
 *   I4  vencida hace 200 y CON DESCUENTO           → en ventas el total va NETO de descuento
 *   I5  SIN VENCIMIENTO, emitida hace 10 días      → se mide desde la emisión y se DECLARA
 *   I6  vence en 3 días                            → por vencer, semana 1 de la proyección
 *   I7  vence en 10 días                           → semana 2
 *   I8  vence en 70 días                           → fuera de la grilla: `beyond`
 *   I9  saldada del todo (sigue `complete=false`)  → no entra en NINGÚN agregado
 *   I10 vence HOY                                  → cuenta como vencida (mismo criterio que general())
 *   I11 de OTRA empresa                            → no aparece en nada
 *
 * Pagar (tipo 4, un proveedor):
 *   P1  vencida hace 100 y con descuento  → en compras el total va CRUDO (asimetría deliberada)
 *   P2  vence en 5 días                   → semana 1, del lado de la salida
 *   P3  vencida hace 2, con un pago PARCIAL de kind `purchase_payment`
 *
 * Y las tres identidades que tienen que cerrar solas:
 *   vencido + por vencer = abierto total; la suma de los 4 buckets = vencido;
 *   overdue + las 8 semanas + beyond = abierto total, por lado.
 *
 * Uso (necesita Postgres migrado — Docker, ver run_open_invoices_summary_test.sh):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/open_invoices_summary_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

// IDs FIJOS definidos ANTES de bootstrap.php: `getContactData()` resuelve el
// nombre contra la constante global COMPANY_ID (ver el arnés de outlet scope).
$companyId = '02e0ce47-0000-4000-8000-000000000201';
$companyB  = '02e0ce47-0000-4000-8000-000000000202';
$outletA   = '02e0ce47-0000-4000-8000-000000000203';
$outletB   = '02e0ce47-0000-4000-8000-000000000204';
$registerA = '02e0ce47-0000-4000-8000-000000000205';
$registerB = '02e0ce47-0000-4000-8000-000000000206';
$userId    = '02e0ce47-0000-4000-8000-000000000207';
$userB     = '02e0ce47-0000-4000-8000-000000000208';
$cli1      = '02e0ce47-0000-4000-8000-000000000209';
$cli2      = '02e0ce47-0000-4000-8000-00000000020a';
$prov1     = '02e0ce47-0000-4000-8000-00000000020b';
$cliB      = '02e0ce47-0000-4000-8000-00000000020c';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\OpenInvoicesService;
use Punto\Api\Support\TenantClock;

/** @var \Punto\Api\Database\Query $db */
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

function money(float $a, float $b): bool
{
    return abs($a - $b) < 0.01;
}

/** El bucket de antigüedad pedido, dentro de la lista ordenada que devuelve el service. */
function bucket(array $side, string $key): array
{
    foreach ($side['aging'] as $b) {
        if ($b['bucket'] === $key) {
            return $b;
        }
    }
    return ['bucket' => $key, 'amount' => -1.0, 'count' => -1];
}

$created = ['transaction_link' => [], 'transaction' => [], 'contact' => [], 'register' => [], 'outlet' => [], 'company' => []];

try {
    foreach ([[$companyId, 'OI Summary Test'], [$companyB, 'OI Summary Vecina']] as [$cid, $cname]) {
        $db->Execute(
            "INSERT INTO company (companyId, status, plan, balance, isParent, config)
             VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)",
            [$cid, json_encode(['settingName' => $cname])]
        );
        $created['company'][] = $cid;
    }

    foreach ([[$outletA, 'OI Sucursal A', $companyId], [$outletB, 'OI Sucursal B', $companyB]] as [$oid, $oname, $cid]) {
        $db->Execute(
            'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
            [$oid, $oname, $cid]
        );
        $created['outlet'][] = $oid;
    }

    foreach ([[$registerA, $outletA, $companyId], [$registerB, $outletB, $companyB]] as [$rid, $oid, $cid]) {
        $db->Execute(
            'INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
             VALUES (?, ?, TRUE, ?, ?)',
            [$rid, 'OI Caja', $oid, $cid]
        );
        $created['register'][] = $rid;
    }

    foreach ([
        [$userId, 'OI Usuario',   $companyId, $outletA],
        [$cli1,   'Cliente Uno',  $companyId, $outletA],
        [$cli2,   'Cliente Dos',  $companyId, $outletA],
        [$prov1,  'Proveedor Uno', $companyId, $outletA],
        [$userB,  'OI Usuario B', $companyB,  $outletB],
        [$cliB,   'Cliente Vecino', $companyB, $outletB],
    ] as [$cid, $cname, $comp, $oid]) {
        $db->Execute(
            'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
             VALUES (?, ?, ?, ?, 0, 1)',
            [$cid, $cname, $comp, $oid]
        );
        $created['contact'][] = $cid;
    }

    // "Hoy" del tenant — la misma fuente que usa `summary()`. Las fechas de las
    // facturas se derivan de acá en PHP (y no de un `NOW()` de Postgres) para
    // que el arnés no dependa de que las dos zonas coincidan.
    $today = new DateTimeImmutable(substr(TenantClock::now($companyId), 0, 10), new DateTimeZone('UTC'));
    $day = static function (int $offset) use ($today): string {
        return $today->modify(($offset >= 0 ? '+' : '-') . abs($offset) . ' days')->format('Y-m-d');
    };

    $seq = 0;
    /** Una factura a crédito abierta. $type 3 = venta al cliente, 4 = compra al proveedor. */
    $seedInvoice = static function (
        string $id, int $type, string $contact, float $total, float $discount,
        int $issuedDaysAgo, ?int $dueInDays
    ) use ($db, $companyId, $outletA, $registerA, $userId, $day, &$created, &$seq): void {
        $seq++;
        $col = $type === 3 ? 'customerId' : 'supplierId';
        $db->Execute(
            "INSERT INTO transaction
               (transactionId, companyId, outletId, registerId, userId, $col, transactionType,
                transactionStatus, transactionComplete, transactionTotal, transactionDiscount,
                transactionDate, transactionDueDate, invoicePrefix, invoiceNo)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, FALSE, ?, ?, ?::timestamp, ?::timestamp, '001-001-', ?)",
            [
                $id, $companyId, $outletA, $registerA, $userId, $contact, $type,
                $total, $discount,
                $day(-$issuedDaysAgo) . ' 10:00:00',
                $dueInDays === null ? null : $day($dueInDays) . ' 00:00:00',
                str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            ]
        );
        $created['transaction'][] = $id;
    };

    /** Un recibo/pago que imputa $amount contra la factura $originId. */
    $seedPayment = static function (string $id, string $originId, string $contact, float $amount, string $kind, int $type)
        use ($db, $companyId, $outletA, $registerA, $userId, &$created): void {
        $col = $kind === 'credit_payment' ? 'customerId' : 'supplierId';
        $db->Execute(
            "INSERT INTO transaction
               (transactionId, companyId, outletId, registerId, userId, $col, transactionType,
                transactionStatus, transactionComplete, transactionTotal, transactionDiscount, transactionDate)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, TRUE, ?, 0, NOW())",
            [$id, $companyId, $outletA, $registerA, $userId, $contact, $type, $amount]
        );
        $created['transaction'][] = $id;
        $db->Execute(
            'INSERT INTO transaction_link (companyId, originId, derivedId, kind, amount) VALUES (?, ?, ?, ?, ?)',
            [$companyId, $originId, $id, $kind, $amount]
        );
        $created['transaction_link'][] = $id;
    };

    $I = static fn (string $n): string => '02e0ce47-0000-4000-8000-0000000003' . $n;

    // ── Cobrar ───────────────────────────────────────────────────────────────
    $seedInvoice($I('01'), 3, $cli1, 100000.0, 0.0,      5,    -5);   // I1  0-30
    $seedInvoice($I('02'), 3, $cli1, 200000.0, 0.0,      60,  -45);   // I2  31-60, pago parcial
    $seedInvoice($I('03'), 3, $cli1,  80000.0, 0.0,      95,  -80);   // I3  61-90
    $seedInvoice($I('04'), 3, $cli2, 300000.0, 50000.0, 220, -200);   // I4  90+, NETO de descuento
    $seedInvoice($I('05'), 3, $cli1,  40000.0, 0.0,      10, null);   // I5  sin vencimiento
    $seedInvoice($I('06'), 3, $cli2, 500000.0, 0.0,       1,    3);   // I6  semana 1
    $seedInvoice($I('07'), 3, $cli2,  60000.0, 0.0,       1,   10);   // I7  semana 2
    $seedInvoice($I('08'), 3, $cli2,  25000.0, 0.0,       1,   70);   // I8  beyond
    $seedInvoice($I('09'), 3, $cli2,  90000.0, 0.0,      30,  -20);   // I9  saldada del todo
    $seedInvoice($I('10'), 3, $cli1,  10000.0, 0.0,      30,    0);   // I10 vence hoy

    $seedPayment($I('21'), $I('02'), $cli1,  50000.0, 'credit_payment', 5);
    $seedPayment($I('22'), $I('09'), $cli2,  90000.0, 'credit_payment', 5);

    // ── Pagar ────────────────────────────────────────────────────────────────
    $seedInvoice($I('11'), 4, $prov1, 400000.0, 100000.0, 150, -100);  // P1 90+, total CRUDO
    $seedInvoice($I('12'), 4, $prov1,  70000.0, 0.0,        2,    5);  // P2 semana 1
    $seedInvoice($I('13'), 4, $prov1,  30000.0, 0.0,       10,   -2);  // P3 0-30, pago parcial
    $seedPayment($I('23'), $I('13'), $prov1, 10000.0, 'purchase_payment', 13);

    // ── Otra empresa: no puede aparecer en ningún agregado ───────────────────
    $db->Execute(
        "INSERT INTO transaction
           (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
            transactionStatus, transactionComplete, transactionTotal, transactionDiscount, transactionDate, transactionDueDate)
         VALUES (?, ?, ?, ?, ?, ?, 3, 1, FALSE, 999000, 0, NOW(), NOW())",
        [$I('14'), $companyB, $outletB, $registerB, $userB, $cliB]
    );
    $created['transaction'][] = $I('14');

    // ── Lo esperado, calculado a mano ────────────────────────────────────────
    $res = (new OpenInvoicesService())->summary($companyId, []);
    $in  = $res['receivable'];
    $out = $res['payable'];

    // Saldos: I2 = 200.000 − 50.000; I4 = 300.000 − 50.000 de descuento;
    // I9 = 0 (queda afuera); P1 = 400.000 CRUDO (el descuento no resta en compras);
    // P3 = 30.000 − 10.000.
    $inOpen  = 100000.0 + 150000.0 + 80000.0 + 250000.0 + 40000.0 + 500000.0 + 60000.0 + 25000.0 + 10000.0;
    $outOpen = 400000.0 + 70000.0 + 20000.0;

    check('hoy sale del reloj del tenant', $res['today'] === $today->format('Y-m-d'),
        'devolvió ' . $res['today'] . ', esperaba ' . $today->format('Y-m-d'), $failures, $checks);

    check('total por cobrar = 1.215.000 (saldo pendiente, no emitido)',
        money((float) $res['totals']['receivable'], $inOpen),
        'devolvió ' . $res['totals']['receivable'], $failures, $checks);
    check('total por pagar = 490.000',
        money((float) $res['totals']['payable'], $outOpen),
        'devolvió ' . $res['totals']['payable'], $failures, $checks);
    check('neto = cobrar − pagar',
        money((float) $res['totals']['net'], $inOpen - $outOpen),
        'devolvió ' . $res['totals']['net'], $failures, $checks);
    check('la factura saldada no cuenta como comprobante abierto (9, no 10)',
        (int) $res['totals']['receivableCount'] === 9,
        'devolvió ' . $res['totals']['receivableCount'], $failures, $checks);
    check('comprobantes por pagar = 3',
        (int) $res['totals']['payableCount'] === 3,
        'devolvió ' . $res['totals']['payableCount'], $failures, $checks);
    check('la factura de la otra empresa no se filtró',
        money((float) $res['totals']['receivable'], $inOpen) && (int) $in['contacts'] === 2,
        'contactos: ' . $in['contacts'] . ', total: ' . $res['totals']['receivable'], $failures, $checks);

    // ── Antigüedad DESDE EL VENCIMIENTO ──────────────────────────────────────
    check('0-30 por cobrar = 150.000 en 3 comprobantes (I1 + I5 + la que vence hoy)',
        money((float) bucket($in, '0-30')['amount'], 150000.0) && bucket($in, '0-30')['count'] === 3,
        json_encode(bucket($in, '0-30')), $failures, $checks);
    check('31-60 por cobrar = 150.000 (I2 ya neta del recibo parcial)',
        money((float) bucket($in, '31-60')['amount'], 150000.0) && bucket($in, '31-60')['count'] === 1,
        json_encode(bucket($in, '31-60')), $failures, $checks);
    check('61-90 por cobrar = 80.000',
        money((float) bucket($in, '61-90')['amount'], 80000.0) && bucket($in, '61-90')['count'] === 1,
        json_encode(bucket($in, '61-90')), $failures, $checks);
    check('90+ por cobrar = 250.000 (I4 neta de descuento)',
        money((float) bucket($in, '90+')['amount'], 250000.0) && bucket($in, '90+')['count'] === 1,
        json_encode(bucket($in, '90+')), $failures, $checks);
    check('90+ por pagar = 400.000 (en compras el total NO resta descuento)',
        money((float) bucket($out, '90+')['amount'], 400000.0),
        json_encode(bucket($out, '90+')), $failures, $checks);
    check('0-30 por pagar = 20.000 (el pago parcial a proveedor descuenta)',
        money((float) bucket($out, '0-30')['amount'], 20000.0),
        json_encode(bucket($out, '0-30')), $failures, $checks);

    check('vencido por cobrar = 630.000 en 6 comprobantes',
        money((float) $in['overdue']['amount'], 630000.0) && (int) $in['overdue']['count'] === 6,
        json_encode($in['overdue']), $failures, $checks);
    check('por vencer por cobrar = 585.000 en 3',
        money((float) $in['notDue']['amount'], 585000.0) && (int) $in['notDue']['count'] === 3,
        json_encode($in['notDue']), $failures, $checks);
    check('vencido + por vencer = total abierto (cobrar)',
        money((float) $in['overdue']['amount'] + (float) $in['notDue']['amount'], $inOpen),
        'suma: ' . ($in['overdue']['amount'] + $in['notDue']['amount']), $failures, $checks);
    check('vencido + por vencer = total abierto (pagar)',
        money((float) $out['overdue']['amount'] + (float) $out['notDue']['amount'], $outOpen),
        'suma: ' . ($out['overdue']['amount'] + $out['notDue']['amount']), $failures, $checks);

    $sumBuckets = 0.0;
    foreach ($in['aging'] as $b) { $sumBuckets += (float) $b['amount']; }
    check('los 4 buckets suman exactamente el vencido',
        money($sumBuckets, (float) $in['overdue']['amount']),
        'buckets: ' . $sumBuckets . ' vs vencido: ' . $in['overdue']['amount'], $failures, $checks);

    check('la factura sin vencimiento se DECLARA (1 comprobante, 40.000)',
        (int) $in['sinVencimiento']['count'] === 1 && money((float) $in['sinVencimiento']['amount'], 40000.0),
        json_encode($in['sinVencimiento']), $failures, $checks);
    check('sin vencimiento del lado de pagar = 0',
        (int) $out['sinVencimiento']['count'] === 0,
        json_encode($out['sinVencimiento']), $failures, $checks);

    // ── Top por contacto ─────────────────────────────────────────────────────
    check('el top de deudores ordena por saldo abierto',
        count($in['top']) === 2
            && $in['top'][0]['contactId'] === $cli2 && money((float) $in['top'][0]['open'], 835000.0)
            && $in['top'][1]['contactId'] === $cli1 && money((float) $in['top'][1]['open'], 380000.0),
        json_encode($in['top']), $failures, $checks);
    check('el top trae el nombre del contacto, no el uuid',
        ($in['top'][0]['name'] ?? '') === 'Cliente Dos',
        'devolvió: ' . ($in['top'][0]['name'] ?? '(nada)'), $failures, $checks);
    check('el comprobante más viejo del deudor es el de fecha de emisión menor',
        ($in['top'][0]['oldest']['saleId'] ?? '') === $I('04')
            && (int) ($in['top'][0]['oldest']['daysOverdue'] ?? 0) === 200,
        json_encode($in['top'][0]['oldest'] ?? null), $failures, $checks);
    check('el top de acreedores tiene al único proveedor con 490.000',
        count($out['top']) === 1 && money((float) $out['top'][0]['open'], 490000.0),
        json_encode($out['top']), $failures, $checks);

    // ── Proyección por vencimiento ───────────────────────────────────────────
    $p = $res['projection'];
    check('la proyección declara que se basa en el vencimiento',
        ($p['basis'] ?? '') === 'dueDate', json_encode($p['basis'] ?? null), $failures, $checks);
    check('la grilla es de 8 semanas y arranca hoy',
        count($p['weeks']) === 8
            && $p['weeks'][0]['from'] === $today->format('Y-m-d')
            && $p['weeks'][0]['to'] === $today->modify('+6 days')->format('Y-m-d')
            && $p['weeks'][7]['to'] === $today->modify('+55 days')->format('Y-m-d'),
        json_encode([$p['weeks'][0] ?? null, $p['weeks'][7] ?? null]), $failures, $checks);
    check('el bucket de arrastre trae TODO lo ya vencido de las dos puntas',
        money((float) $p['overdue']['inflow'], 630000.0) && money((float) $p['overdue']['outflow'], 420000.0),
        json_encode($p['overdue']), $failures, $checks);
    check('semana 1: entra I6 (500.000) y sale P2 (70.000)',
        money((float) $p['weeks'][0]['inflow'], 500000.0)
            && money((float) $p['weeks'][0]['outflow'], 70000.0)
            && money((float) $p['weeks'][0]['net'], 430000.0),
        json_encode($p['weeks'][0]), $failures, $checks);
    check('semana 2: entra I7 (60.000), nada sale',
        money((float) $p['weeks'][1]['inflow'], 60000.0) && money((float) $p['weeks'][1]['outflow'], 0.0),
        json_encode($p['weeks'][1]), $failures, $checks);
    check('lo que vence más allá de la semana 8 se declara, no se esconde',
        money((float) $p['beyond']['inflow'], 25000.0) && money((float) $p['beyond']['outflow'], 0.0),
        json_encode($p['beyond']), $failures, $checks);

    $projIn = (float) $p['overdue']['inflow'] + (float) $p['beyond']['inflow'];
    $projOut = (float) $p['overdue']['outflow'] + (float) $p['beyond']['outflow'];
    foreach ($p['weeks'] as $w) {
        $projIn  += (float) $w['inflow'];
        $projOut += (float) $w['outflow'];
    }
    check('la proyección reparte TODO lo abierto por cobrar, sin perder ni duplicar',
        money($projIn, $inOpen), 'proyección: ' . $projIn . ' vs abierto: ' . $inOpen, $failures, $checks);
    check('la proyección reparte TODO lo abierto por pagar',
        money($projOut, $outOpen), 'proyección: ' . $projOut . ' vs abierto: ' . $outOpen, $failures, $checks);

    // ── El listado crudo sigue intacto (la vista nueva no lo tocó) ───────────
    $general = (new OpenInvoicesService())->general('income', $companyId, null, []);
    check('general() sigue devolviendo rows+kpi con los dos clientes',
        count($general['rows']) === 2 && isset($general['kpi']['totalDebt']),
        json_encode(array_keys($general)) . ' filas: ' . count($general['rows']), $failures, $checks);
} finally {
    foreach ($created['transaction_link'] as $id) {
        $db->Execute('DELETE FROM transaction_link WHERE derivedId = ?', [$id]);
    }
    foreach ($created['transaction'] as $id) {
        $db->Execute('DELETE FROM transaction WHERE transactionId = ?', [$id]);
    }
    foreach ($created['contact'] as $id) {
        $db->Execute('DELETE FROM contact WHERE contactId = ?', [$id]);
    }
    foreach ($created['register'] as $id) {
        $db->Execute('DELETE FROM register WHERE registerId = ?', [$id]);
    }
    foreach ($created['outlet'] as $id) {
        $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$id]);
    }
    foreach ($created['company'] as $id) {
        $db->Execute('DELETE FROM company WHERE companyId = ?', [$id]);
    }
}

harnessFinish($failures, $checks);
