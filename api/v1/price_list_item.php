<?php
/**
 * /api/v1/price_list_item.php — Ítems de una lista de precios.
 *
 *   GET  ?priceListId=<uuid>              → ítems de la lista (con nombre y precio base)
 *   PUT  ?priceListId=<uuid> { items:[] } → bulk-replace de todos los ítems
 *
 * Auth: realm panel.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/PriceListService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Services\PriceListService;

$ctx          = apiAuthTenant(['panel']);
$companyId    = $ctx['companyId'];
$method       = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$priceListId  = trim((string) ($_GET['priceListId'] ?? ''));

if ($priceListId === '') {
    apiError('Falta priceListId', 422);
}

$svc = new PriceListService(TenantContext::fromAuth($ctx));

switch ($method) {
    case 'GET':
        $list = $svc->get($companyId, $priceListId);
        if ($list === null) {
            apiError('Lista no encontrada', 404);
        }
        apiOk($list['items']);

    case 'PUT':
        $items = $_POST['items'] ?? [];
        if (!is_array($items)) {
            apiError('items debe ser un array', 422);
        }
        $res = $svc->setItems($companyId, $priceListId, $items);
        if (empty($res['ok'])) {
            apiError($res['error'] ?? 'No se pudieron guardar los ítems', 500);
        }
        apiOk($res['items']);

    default:
        apiError('Método no permitido', 405);
}
