<?php
declare(strict_types=1);

/**
 * Helper de subproceso para `pos_device_revoked_auth_test.php`.
 *
 * `apiAuthTenant()` termina la ejecución con `die()`/`exit()` en el path de
 * error (igual que `apiError()` en general) — no se puede `try/catch` en el
 * mismo proceso del test sin terminarlo. Mismo patrón que
 * `_void_once_cli.php` usa para `CreditPaymentService::void()`.
 *
 * Uso: php _pos_auth_once_cli.php <bearerToken>
 * Imprime a stdout:
 *   HTTP_STATUS:<code>
 *   BODY:<lo que haya impreso apiAuthTenant()/apiError() antes de morir>
 *   CTX:<json del ctx devuelto, solo si NO murió>
 */

$bearerToken = $argv[1] ?? '';

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
$_SERVER['REQUEST_METHOD']     = 'GET';
$_SERVER['REQUEST_URI']        = '/v1/items';

register_shutdown_function(function (): void {
    echo "\nHTTP_STATUS:" . http_response_code() . "\n";
});

require_once dirname(__DIR__) . '/bootstrap.php';

$ctx = apiAuthTenant(['panel', 'pos-app']);
echo 'CTX:' . json_encode($ctx) . "\n";
