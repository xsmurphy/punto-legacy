<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) del SCOPE POR SUCURSAL de Cuentas por
 * Cobrar/Pagar (`OpenInvoicesService`).
 *
 * ── El bug (reporte del tester, 2026-08-28) ─────────────────────────────────
 * «En cuentas por cobrar y cuentas por pagar aún se mezclan las informaciones
 * de otras sucursales» (/reports/open-invoices). `openCreditInvoices()`
 * filtraba SOLO por `companyId`: el selector de sucursal del panel no tenía
 * ningún efecto sobre este reporte, a diferencia de reports/stock.php y
 * reports/dashboard.php que sí resuelven `VIEW_OUTLET_ID`.
 *
 * ── Lo que este arnés fija, y que es fácil de romper "arreglando" ───────────
 *  1. `general()` con un outlet devuelve SOLO las facturas de esa sucursal.
 *  2. `general()` con '' ("Todas") consolida — no es un accidente, es el modo
 *     que manda el selector como 'all'.
 *  3. `forContact()` sigue siendo COMPANY-WIDE aunque el reporte no lo sea: el
 *     saldo de un contacto es lo que le debe a la EMPRESA. Scopearlo haría que
 *     el diálogo de cobro ofrezca cobrar contra una deuda parcial.
 *  4. Un pago hecho en OTRA sucursal sigue descontando de la factura. Es la
 *     trampa del fix: si alguien filtra también los pagos por outlet, una
 *     factura emitida en A y cobrada en B reaparece como impaga.
 *
 * Uso (necesita Postgres migrado — Docker, ver run_open_invoices_outlet_scope_test.sh):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/open_invoices_outlet_scope_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

// IDs FIJOS y definidos ANTES de bootstrap.php: `getContactData()` resuelve el
// nombre del contacto contra la constante global `COMPANY_ID`
// (App\Domain\Customer::getContactData), así que el arnés tiene que fijar el
// contexto de tenant igual que cost_center_test.php / drawer_*_test.php.
$companyId = '01e0ce47-0000-4000-8000-000000000101';
$outletA   = '01e0ce47-0000-4000-8000-000000000102';
$outletB   = '01e0ce47-0000-4000-8000-000000000103';
$customer  = '01e0ce47-0000-4000-8000-000000000104';
$userId    = '01e0ce47-0000-4000-8000-000000000105'; // transaction.userId es NOT NULL
// Una caja pertenece a UNA sucursal (context/04): una por outlet, no una
// compartida — así el fixture no miente sobre la jerarquía del dominio.
$registerA = '01e0ce47-0000-4000-8000-000000000106';
$registerB = '01e0ce47-0000-4000-8000-000000000107';
$saleA     = '01e0ce47-0000-4000-8000-000000000108'; // factura a crédito emitida en A
$saleB     = '01e0ce47-0000-4000-8000-000000000109'; // factura a crédito emitida en B
$payInB    = '01e0ce47-0000-4000-8000-00000000010a'; // recibo que cancela la de A, cobrado en B

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\OpenInvoicesService;

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

/** Los saleId que el reporte devolvió, aplanados. */
function saleIdsOf(array $res): array
{
    $out = [];
    foreach ($res['rows'] ?? [] as $row) {
        foreach ($row['invoices'] ?? [] as $inv) {
            $out[] = (string) $inv['saleId'];
        }
    }
    sort($out);
    return $out;
}

$created = ['transaction_link' => [], 'transaction' => [], 'contact' => [], 'register' => [], 'outlet' => [], 'company' => []];

try {
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"OI Scope Test\"}'::jsonb)",
        [$companyId]
    );
    $created['company'][] = $companyId;

    foreach ([[$outletA, 'OI Sucursal A'], [$outletB, 'OI Sucursal B']] as [$oid, $name]) {
        $db->Execute(
            'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
            [$oid, $name, $companyId]
        );
        $created['outlet'][] = $oid;
    }

    foreach ([[$registerA, $outletA, 'OI Caja A'], [$registerB, $outletB, 'OI Caja B']] as [$rid, $oid, $rname]) {
        $db->Execute(
            'INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
             VALUES (?, ?, TRUE, ?, ?)',
            [$rid, $rname, $oid, $companyId]
        );
        $created['register'][] = $rid;
    }

    // `contact` no tiene `contactType` — el rol vive en `type` (SMALLINT). El
    // usuario que opera también es un `contact` (transaction.userId lo referencia).
    foreach ([[$customer, 'OI Cliente'], [$userId, 'OI Usuario']] as [$cid, $cname]) {
        $db->Execute(
            'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
             VALUES (?, ?, ?, ?, 0, 1)',
            [$cid, $cname, $companyId, $outletA]
        );
        $created['contact'][] = $cid;
    }

    // Dos facturas a crédito (type 3) abiertas, una por sucursal, mismo cliente.
    foreach ([[$saleA, $outletA, $registerA, 100000.0], [$saleB, $outletB, $registerB, 250000.0]] as [$sid, $oid, $rid, $total]) {
        $db->Execute(
            "INSERT INTO transaction
               (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
                transactionStatus, transactionComplete, transactionTotal, transactionDiscount,
                transactionDate, transactionDueDate)
             VALUES (?, ?, ?, ?, ?, ?, 3, 1, FALSE, ?, 0, NOW(), NOW() + INTERVAL '30 days')",
            [$sid, $companyId, $oid, $rid, $userId, $customer, $total]
        );
        $created['transaction'][] = $sid;
    }

    $svc = new OpenInvoicesService();

    // ── 1. Scope por sucursal ────────────────────────────────────────────────
    check(
        'general() con la sucursal A devuelve SOLO la factura de A',
        saleIdsOf($svc->general('income', $companyId, null, $outletA)) === [$saleA],
        'devolvió: ' . implode(',', saleIdsOf($svc->general('income', $companyId, null, $outletA))),
        $failures, $checks
    );
    check(
        'general() con la sucursal B devuelve SOLO la factura de B',
        saleIdsOf($svc->general('income', $companyId, null, $outletB)) === [$saleB],
        'devolvió: ' . implode(',', saleIdsOf($svc->general('income', $companyId, null, $outletB))),
        $failures, $checks
    );

    // ── 2. "Todas" consolida ─────────────────────────────────────────────────
    $todas = saleIdsOf($svc->general('income', $companyId, null, ''));
    $ambas = [$saleA, $saleB];
    sort($ambas);
    check(
        'general() sin sucursal ("Todas") consolida las dos',
        $todas === $ambas,
        'devolvió: ' . implode(',', $todas),
        $failures, $checks
    );

    // ── 3. El saldo del contacto NO se scopea ────────────────────────────────
    check(
        'forContact() sigue siendo company-wide (100.000 + 250.000)',
        abs($svc->forContact($customer, $companyId, true) - 350000.0) < 0.01,
        'devolvió: ' . $svc->forContact($customer, $companyId, true),
        $failures, $checks
    );

    // ── 4. Un pago hecho en OTRA sucursal descuenta igual ────────────────────
    // Recibo (type 5) emitido en la sucursal B que cancela la factura de A.
    $db->Execute(
        "INSERT INTO transaction
           (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
            transactionStatus, transactionComplete, transactionTotal, transactionDiscount, transactionDate)
         VALUES (?, ?, ?, ?, ?, ?, 5, 1, TRUE, ?, 0, NOW())",
        [$payInB, $companyId, $outletB, $registerB, $userId, $customer, 100000.0]
    );
    $created['transaction'][] = $payInB;

    $db->Execute(
        "INSERT INTO transaction_link (companyId, originId, derivedId, kind, amount)
         VALUES (?, ?, ?, 'credit_payment', ?)",
        [$companyId, $saleA, $payInB, 100000.0]
    );
    $created['transaction_link'][] = $payInB;

    // La factura de A quedó saldada por un cobro de B: ya no debe aparecer en
    // el reporte de A. Si alguien filtrara los pagos por outlet, el pago de B
    // sería invisible desde A y la factura seguiría figurando como impaga.
    $rowsA = $svc->general('income', $companyId, null, $outletA);
    check(
        'un pago cobrado en la sucursal B salda la factura emitida en A',
        saleIdsOf($rowsA) === [],
        'la factura de A sigue apareciendo como abierta: ' . implode(',', saleIdsOf($rowsA)),
        $failures, $checks
    );
    check(
        'y el saldo del contacto baja a 250.000',
        abs($svc->forContact($customer, $companyId, true) - 250000.0) < 0.01,
        'devolvió: ' . $svc->forContact($customer, $companyId, true),
        $failures, $checks
    );
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
