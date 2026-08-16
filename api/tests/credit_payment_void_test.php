<?php
declare(strict_types=1);

/**
 * Test de integración (DB real) de `CreditPaymentService::void()` — anulación
 * de un recibo de pago/cobro (context/41, plan cerrado 2026-08-16 en
 * context/40-anulacion-y-nota-credito.md). A diferencia de
 * `credit_payment_distribution_test.php` (puro, sin DB — solo prueba
 * `distributeFifo()`), ESTE test necesita Postgres real: `void()` lockea
 * filas (`FOR UPDATE`), escribe `transactionStatus`/`transactionComplete`, y
 * su corrección depende de que `TransactionLinkService::sumDerivedAmounts()`
 * lea datos reales de `transaction_link`/`transaction`.
 *
 * Reusa el tenant fixture "Verify PY" que ya siembra
 * `api/lib/Sales/verify_chain/seed.sql` (mismo companyId/outletId/
 * registerId/userId que `run_sale_chain.php`) — evita reinventar fixtures de
 * company/outlet/register/tax. Las FACTURAS y RECIBOS de este test son
 * propios (transactionId generados acá vía `gen_random_uuid()`), no tocan
 * los del arnés de ventas.
 *
 * Casos (pedido del owner, ver brief de la tarea):
 *   (a) recibo que pagó 3 facturas → al anular, las 3 vuelven al saldo
 *       original (transactionComplete=false, sumDerivedAmounts=0).
 *   (b) factura con DOS pagos, se anula uno → queda con el saldo del OTRO,
 *       no vuelve a impaga del todo (recalculo real, no "vuelve a false").
 *   (c) el documento anulado deja de sumar en sumDerivedAmounts — implícito
 *       en (a)/(b), y confirmado explícito acá.
 *   Idempotencia: anular un recibo ya anulado se rechaza (vía subproceso —
 *   `void()` usa `apiError()`, que hace `exit` directo; no se puede
 *   `try/catch` en el mismo proceso sin terminar el test entero).
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_credit_payment_void_test.sh` para levantar todo de cero en Docker):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/credit_payment_void_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\CreditPaymentService;
use Punto\Api\Services\TransactionLinkService;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

// Contacto cliente del seed — se usa directo (las facturas de este test se
// insertan por SQL, sin pasar por SaleService, así que el chequeo de
// contactCreditable — que solo corre en el POS al emitir — no aplica).
$customerId = '2b9f6a71-3e2b-4b34-9b5a-7a6a6a6a6a6a';

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

/** Inserta una factura a crédito (type=3) mínima, propia de este test. */
function makeInvoice(string $companyId, string $outletId, string $registerId, string $userId, string $customerId, float $total): string
{
    global $db;
    $db->AutoExecute('transaction', [
        'transactionTotal'    => $total,
        'transactionDiscount' => 0,
        'transactionType'     => 3,
        'transactionComplete' => false,
        'transactionStatus'   => 1,
        'transactionDate'     => date('Y-m-d H:i:s'),
        'transactionDueDate'  => date('Y-m-d H:i:s', strtotime('+7 days')),
        'invoiceNo'           => random_int(1000000, 9999999),
        'timestamp'           => time(),
        'customerId'          => $customerId,
        'registerId'          => $registerId,
        'userId'              => $userId,
        'responsibleId'       => $userId,
        'outletId'            => $outletId,
        'companyId'           => $companyId,
    ], 'INSERT');
    return (string) $db->Insert_ID();
}

function invoicePaid(string $companyId, string $invId, TransactionLinkService $links): float
{
    return $links->sumDerivedAmounts($companyId, $invId, 'credit_payment');
}

function invoiceComplete(string $invId): bool
{
    $row = ncmExecute('SELECT transactionComplete FROM transaction WHERE transactionId = ?', [$invId]);
    return (bool) ($row['transactioncomplete'] ?? $row['transactionComplete'] ?? false);
}

$svc   = new CreditPaymentService();
$links = new TransactionLinkService();

// ── (a) recibo que pagó 3 facturas → al anular, las 3 vuelven al saldo original ──
$invA = makeInvoice($companyId, $outletId, $registerId, $userId, $customerId, 100.0);
$invB = makeInvoice($companyId, $outletId, $registerId, $userId, $customerId, 200.0);
$invC = makeInvoice($companyId, $outletId, $registerId, $userId, $customerId, 300.0);

$receipt = $svc->create($companyId, $userId, [
    ['parentTransactionId' => $invA, 'amount' => 100.0],
    ['parentTransactionId' => $invB, 'amount' => 200.0],
    ['parentTransactionId' => $invC, 'amount' => 300.0],
], 'efectivo', null, null, true);

check(
    '(a) setup: A/B/C completamente pagadas antes de anular',
    invoicePaid($companyId, $invA, $links) === 100.0
        && invoicePaid($companyId, $invB, $links) === 200.0
        && invoicePaid($companyId, $invC, $links) === 300.0
        && invoiceComplete($invA) && invoiceComplete($invB) && invoiceComplete($invC),
    'paid=' . json_encode([invoicePaid($companyId, $invA, $links), invoicePaid($companyId, $invB, $links), invoicePaid($companyId, $invC, $links)]),
    $failures
);

$voidResult = $svc->void((string) $receipt['id'], $companyId, $userId);
check('(a) void() devuelve status=6 y las 3 facturas afectadas', $voidResult['status'] === 6 && count($voidResult['affectedInvoices']) === 3, json_encode($voidResult), $failures);

check(
    '(a) al anular: las 3 facturas vuelven a paid=0 (saldo original) y transactionComplete=false',
    invoicePaid($companyId, $invA, $links) === 0.0
        && invoicePaid($companyId, $invB, $links) === 0.0
        && invoicePaid($companyId, $invC, $links) === 0.0
        && !invoiceComplete($invA) && !invoiceComplete($invB) && !invoiceComplete($invC),
    'paid=' . json_encode([invoicePaid($companyId, $invA, $links), invoicePaid($companyId, $invB, $links), invoicePaid($companyId, $invC, $links)])
        . ' complete=' . json_encode([invoiceComplete($invA), invoiceComplete($invB), invoiceComplete($invC)]),
    $failures
);

// ── (c) el documento anulado deja de sumar en sumDerivedAmounts — explícito ──
check(
    '(c) sumDerivedAmounts excluye el recibo anulado',
    invoicePaid($companyId, $invA, $links) === 0.0,
    'esperado 0.0, obtenido ' . invoicePaid($companyId, $invA, $links),
    $failures
);

// ── Idempotencia: anular un recibo ya anulado se rechaza ──
// void() usa apiError() (exit directo) — no se puede try/catch en el mismo
// proceso sin terminar el test entero, así que el segundo intento corre en
// un subproceso PHP y se verifica la salida (envelope {"ok":false,...}).
$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/_void_once_cli.php')
    . ' ' . escapeshellarg($companyId) . ' ' . escapeshellarg((string) $receipt['id']) . ' ' . escapeshellarg($userId) . ' 2>&1';
$output = shell_exec($cmd) ?? '';
check(
    'anular un recibo YA anulado se rechaza (idempotencia)',
    str_contains($output, '"ok":false') && str_contains($output, 'anulado'),
    "salida del subproceso: $output",
    $failures
);

// ── (b) factura con DOS pagos, se anula uno → queda con el saldo del OTRO ──
$invD = makeInvoice($companyId, $outletId, $registerId, $userId, $customerId, 500.0);
$payment1 = $svc->create($companyId, $userId, [['parentTransactionId' => $invD, 'amount' => 200.0]], 'efectivo', null, null, true);
$payment2 = $svc->create($companyId, $userId, [['parentTransactionId' => $invD, 'amount' => 300.0]], 'efectivo', null, null, true);

check('(b) setup: D saldada por los 2 pagos (200+300=500)', invoiceComplete($invD) && invoicePaid($companyId, $invD, $links) === 500.0, 'paid=' . invoicePaid($companyId, $invD, $links), $failures);

$svc->void((string) $payment1['id'], $companyId, $userId);

check(
    '(b) al anular el pago de 200: D queda con 300 pagados (el otro pago sigue vigente)',
    invoicePaid($companyId, $invD, $links) === 300.0,
    'obtenido: ' . invoicePaid($companyId, $invD, $links),
    $failures
);
check(
    '(b) D vuelve a transactionComplete=false (parcial, NO impaga del todo)',
    !invoiceComplete($invD),
    'transactionComplete debería ser false',
    $failures
);

if ($failures > 0) {
    echo "\n$failures caso(s) fallido(s).\n";
    exit(1);
}
echo "\nTodos los casos OK.\n";
exit(0);
