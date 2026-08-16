<?php
declare(strict_types=1);

/**
 * Helper de `credit_payment_void_test.php` — hace UN intento de
 * `CreditPaymentService::void()` en un subproceso propio. Existe porque
 * `void()` usa `apiError()` (mismo error-path que el endpoint real), que hace
 * `exit` directo — no se puede `try/catch` un segundo intento de anulación en
 * el MISMO proceso del test sin terminarlo entero. Corriendo esto como
 * subproceso, el test padre solo necesita leer la salida (envelope
 * `{"ok":false,...}` de `apiError()`, o `{"ok":true,...}` si por error
 * lograra anular dos veces).
 *
 * Uso: php _void_once_cli.php <companyId> <paymentId> <userId>
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\CreditPaymentService;

[$script, $companyId, $paymentId, $userId] = $argv;

$result = (new CreditPaymentService())->void($paymentId, $companyId, $userId);
echo json_encode(['ok' => true, 'data' => $result]);
