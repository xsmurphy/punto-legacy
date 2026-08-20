<?php
declare(strict_types=1);

/**
 * Helper de subproceso para `pos_device_screen_module_scope_test.php`.
 *
 * `apiAuthPosContext()`/`DeviceAuth::requireCompleteContext()` terminan con
 * `die()` en el path de error — no se puede `try/catch` en el mismo proceso
 * del test sin terminarlo. Mismo patrón que `_pos_auth_once_cli.php` usa para
 * `apiAuthTenant()`.
 *
 * Uso: php _screen_auth_once_cli.php <bearerToken>
 * Imprime a stdout:
 *   HTTP_STATUS:<code>
 *   CTX:<json del ctx devuelto, solo si NO murió>
 */

$bearerToken = $argv[1] ?? '';

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
$_SERVER['REQUEST_METHOD']     = 'GET';
$_SERVER['REQUEST_URI']        = '/v1/screens?resource=context';

register_shutdown_function(function (): void {
    echo "\nHTTP_STATUS:" . http_response_code() . "\n";
});

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/apiAuthPosContext.php';

$ctx = apiAuthPosContext();
echo 'CTX:' . json_encode($ctx) . "\n";
