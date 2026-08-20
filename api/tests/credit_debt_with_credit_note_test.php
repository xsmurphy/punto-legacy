<?php
declare(strict_types=1);

/**
 * Test de integración (DB real) — el hallazgo de la tarea "cuentas por
 * cobrar no coincide entre Caja y panel": tres implementaciones separadas
 * calculaban "cuánto se debe" de una factura a crédito con fórmulas
 * DISTINTAS. `OpenInvoicesService::payedByParent()` (panel — Cuentas por
 * Cobrar + Financiero del contacto) restaba `return`/nota de crédito;
 * `Services\TransactionService::getSingle()` (Caja/POS) y
 * `Transactions\TransactionDetailService::find()` (panel `/transactions/
 * {id}`) NO. La misma fórmula inflada también alimentaba el chequeo de
 * sobrepago de `CreditPaymentService::create()`/`createDistributed()`/
 * `void()` — no solo un bug de DISPLAY, permitía aceptar un cobro por
 * encima de la deuda REAL.
 *
 * Las cuatro superficies migraron a UNA sola: `TransactionLinkService::
 * paidForCreditOrigin(s)()`. Este test verifica, contra Postgres real:
 *
 *   (a) una factura con DESCUENTO + una nota de crédito con SU PROPIO
 *       descuento da el mismo `paid` que calcularía a mano cualquiera de
 *       los tres consumidores — ya no hay "dos caminos", hay uno solo.
 *   (b) `create()` RECHAZA un cobro que supera la deuda real (post-NC),
 *       aunque esté por debajo de la deuda NOMINAL (pre-NC) — el bug de
 *       sobrepago que existía antes de unificar.
 *   (c) un cobro por el monto real (post-NC) SÍ se acepta y salda la
 *       factura (`transactionComplete=true`).
 *
 * Reusa el tenant fixture "Verify PY" (mismo criterio que
 * `credit_payment_void_test.php` — ver ese archivo para el detalle).
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_credit_payment_void_test.sh`, que también corre este test):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/credit_debt_with_credit_note_test.php
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

/** Factura a crédito (type=3) CON descuento — mismo helper que credit_payment_void_test.php, + $discount. */
function makeInvoice(string $companyId, string $outletId, string $registerId, string $userId, string $customerId, float $total, float $discount = 0.0): string
{
    global $db;
    $db->AutoExecute('transaction', [
        'transactionTotal'    => $total,
        'transactionDiscount' => $discount,
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

/**
 * Nota de crédito de venta (type=6) mínima, vinculada a $invoiceId vía
 * `transaction_link(kind='return')` — espejo de lo que hace `ReturnService`
 * (sin pasar por su flujo completo de stock/validación, fuera de alcance de
 * este test: solo necesitamos la fila + el vínculo para probar el cálculo).
 */
function makeReturn(
    string $companyId,
    string $outletId,
    string $registerId,
    string $userId,
    string $customerId,
    string $invoiceId,
    float $total,
    float $discount,
    TransactionLinkService $links
): string {
    global $db;
    $db->AutoExecute('transaction', [
        'transactionTotal'    => $total,
        'transactionDiscount' => $discount,
        'transactionType'     => 6,
        'transactionStatus'   => 1,
        'transactionDate'     => date('Y-m-d H:i:s'),
        'invoiceNo'           => random_int(1000000, 9999999),
        'timestamp'           => time(),
        'customerId'          => $customerId,
        'registerId'          => $registerId,
        'userId'              => $userId,
        'responsibleId'       => $userId,
        'outletId'            => $outletId,
        'companyId'           => $companyId,
    ], 'INSERT');
    $returnId = (string) $db->Insert_ID();
    $links->link($companyId, $invoiceId, $returnId, 'return');
    return $returnId;
}

function invoiceComplete(string $invId): bool
{
    $row = ncmExecute('SELECT transactionComplete FROM transaction WHERE transactionId = ?', [$invId]);
    return (bool) ($row['transactioncomplete'] ?? $row['transactionComplete'] ?? false);
}

$links = new TransactionLinkService();
$svc   = new CreditPaymentService();

// ── Setup: factura G, total=1000, descuento=200 → neto 800 ────────────────
$invG = makeInvoice($companyId, $outletId, $registerId, $userId, $customerId, 1000.0, 200.0);

// NC de 300 con descuento propio de 50 → neto imputado 250 (ABS(300-50)).
makeReturn($companyId, $outletId, $registerId, $userId, $customerId, $invG, 300.0, 50.0, $links);

// (a) paidForCreditOrigin() — SOLO la NC (sin pagos todavía) — debe dar 250,
//     el mismo número que usarían los tres consumidores ahora que comparten
//     el método (antes, dos de los tres hubiesen dado 0 acá).
$paidOnlyNc = $links->paidForCreditOrigin($companyId, $invG, true);
check(
    '(a) factura con descuento + NC con descuento propio: paidForCreditOrigin() = ABS(300-50) = 250',
    abs($paidOnlyNc - 250.0) < 0.001,
    "obtenido: $paidOnlyNc",
    $failures
);

// Deuda real tras la NC: 800 (neto) - 250 (NC) = 550.
$realDebt = 800.0 - $paidOnlyNc;
check('(a) deuda real post-NC = 550', abs($realDebt - 550.0) < 0.001, "obtenido: $realDebt", $failures);

// (b) Cobrar 700 supera la deuda REAL (550) aunque esté por debajo de la
//     deuda NOMINAL (800, sin restar la NC) — create() debe RECHAZAR. Antes
//     de unificar el cálculo, `create()` solo miraba `sumDerivedAmounts
//     ('credit_payment')` (0, sin pagos) y hubiese aceptado esto: sobrepago
//     real de 150 sobre la deuda verdadera.
$phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd = escapeshellarg($phpBin) . ' -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/_pay_once_cli.php')
    . ' ' . escapeshellarg($companyId) . ' ' . escapeshellarg($userId) . ' ' . escapeshellarg($invG) . ' ' . escapeshellarg('700') . ' 2>&1';
$output = shell_exec($cmd) ?? '';
check(
    '(b) cobrar 700 (> deuda real 550, < deuda nominal 800) se RECHAZA',
    str_contains($output, '"ok":false') && str_contains($output, 'supera su deuda'),
    "salida del subproceso: $output",
    $failures
);

// (c) Cobrar exactamente la deuda real (550) SÍ se acepta y salda la factura.
$receipt = $svc->create($companyId, $userId, [['parentTransactionId' => $invG, 'amount' => 550.0]], 'efectivo', null, null, true);
check('(c) cobrar 550 (deuda real exacta) se acepta', isset($receipt['id']), json_encode($receipt), $failures);
check(
    '(c) tras cobrar 550: paidForCreditOrigin() = 250 (NC) + 550 (pago) = 800 = total neto → factura saldada',
    invoiceComplete($invG),
    'transactionComplete debería ser true',
    $failures
);

if ($failures > 0) {
    echo "\n$failures caso(s) fallido(s).\n";
    exit(1);
}
echo "\nTodos los casos OK.\n";
exit(0);
