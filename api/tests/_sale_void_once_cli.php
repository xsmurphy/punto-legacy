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
 * Uso: php _sale_void_once_cli.php <companyId> <transactionId> <userId> <registerId> <outletId> <reason> [linesJson]
 *
 * `linesJson` (opcional) — array JSON de `{itemId|itemSoldId, restock}`, para
 * ejercitar el guard de `resolveLineDecisions()` (P2, code review F1+F2):
 * una línea ambigua (`itemId` sin `itemSoldId` cuando la venta tiene 2+
 * líneas de ese ítem) tira `AmbiguousVoidLineException`, catcheada dentro de
 * `void()` como 422 — otro caso que necesita subproceso, mismo motivo que el
 * resto de este helper.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\SaleVoidService;

$argv     = array_pad($argv, 8, null);
[$script, $companyId, $transactionId, $userId, $registerId, $outletId, $reason, $linesJson] = $argv;
$lines    = $linesJson ? (json_decode((string) $linesJson, true) ?: []) : [];

$result = (new SaleVoidService())->void($companyId, $transactionId, $userId, $reason, $lines, $registerId ?: null, $outletId ?: null);
echo json_encode(['ok' => true, 'data' => $result]);
