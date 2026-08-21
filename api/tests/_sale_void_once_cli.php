<?php
declare(strict_types=1);

/**
 * Helper de `sale_void_test.php` — hace UN intento de `SaleVoidService::
 * void()` en un subproceso propio. Mismo motivo que `_void_once_cli.php`
 * (credit_payment_void_test): `void()` usa `apiError()`/`apiConflict()`/
 * `apiUnprocessable()` (todas hacen `exit` directo) — no se puede `try/catch`
 * un intento que se espera que FALLE en el mismo proceso del test padre sin
 * terminarlo entero. El test padre solo lee la salida (envelope
 * `{"ok":false,...}`).
 *
 * Uso: php _sale_void_once_cli.php <companyId> <transactionId> <userId> <registerId> <outletId> <reason>
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\SaleVoidService;

[$script, $companyId, $transactionId, $userId, $registerId, $outletId, $reason] = $argv;

$result = (new SaleVoidService())->void($companyId, $transactionId, $userId, $reason, [], $registerId ?: null, $outletId ?: null);
echo json_encode(['ok' => true, 'data' => $result]);
