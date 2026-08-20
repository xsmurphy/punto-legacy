<?php
declare(strict_types=1);

/**
 * Helper de `credit_debt_with_credit_note_test.php` — hace UN intento de
 * `CreditPaymentService::create()` en un subproceso propio. Mismo motivo que
 * `_void_once_cli.php`: `create()` usa `apiError()` (exit directo) cuando el
 * monto supera la deuda — no se puede `try/catch` en el proceso padre del
 * test sin terminarlo entero.
 *
 * Uso: php _pay_once_cli.php <companyId> <userId> <invoiceId> <amount>
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\CreditPaymentService;

[$script, $companyId, $userId, $invoiceId, $amount] = $argv;

$result = (new CreditPaymentService())->create(
    $companyId,
    $userId,
    [['parentTransactionId' => $invoiceId, 'amount' => (float) $amount]],
    'efectivo',
    null,
    null,
    true
);
echo json_encode(['ok' => true, 'data' => $result]);
