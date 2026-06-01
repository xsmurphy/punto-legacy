<?php
/**
 * /api/v1/giftcards.php — consulta de gift cards (POS).
 *
 *   GET ?code=<n>&amount=<n>     → valida para canje  → { success: <status> }
 *   GET ?code=<n>&resource=info  → datos de la gift card → { ...campos }
 *
 * companyId del JWT. Lectura → GET (§22.7). Port de chkGiftCard (modos JSON; sin HTML).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/GiftCardService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\GiftCardService;

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$svc      = new GiftCardService(TenantContext::fromAuth($ctx));
$code     = $_GET['code'] ?? '';
$resource = (string) ($_GET['resource'] ?? '');

// Validación numérica compartida (legacy: 'invalid' en 200 para ambos modos).
if (!is_numeric($code) || (int) $code < 1) {
    apiOk(['success' => 'invalid']);
}

if ($resource === 'info') {
    $info = $svc->getInfo($companyId, (int) $code);
    if ($info === null) {
        apiError('Gift Card Not Found', 404);
    }
    apiOk($info);
}

// Modo status (bool).
$status = $svc->checkStatus($companyId, $code, (float) ($_GET['amount'] ?? 0));
if ($status === 'notfound') {
    apiError('Gift Card Not Found', 404);
}
apiOk(['success' => $status]);
