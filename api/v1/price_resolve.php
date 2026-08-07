<?php
/**
 * /api/v1/price_resolve.php — Resolución de precios con listas activas.
 *
 *   POST { items:[{itemId, basePrice}], contactId?, outletId?, priceListId? }
 *        → [{itemId, price, originalPrice, priceListId?, priceListName?}]
 *
 * Usado por el POS en tiempo real: cuando se selecciona un cliente o se cambia
 * la lista manualmente, el carrito recalcula precios en batch.
 *
 * Auth: multi-realm (panel + pos-app) — tanto el panel como el POS pueden usarlo.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/PriceListService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Services\PriceListService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'POST';

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

$items       = $_POST['items'] ?? [];
$contactId   = trim((string) ($_POST['contactId']   ?? '')) ?: null;
$outletId    = trim((string) ($_POST['outletId']    ?? '')) ?: null;
$priceListId = trim((string) ($_POST['priceListId'] ?? '')) ?: null;

if (!is_array($items) || empty($items)) {
    apiError('items es requerido', 422);
}

// Validar la FORMA de cada ítem, no solo que `items` sea un array.
// resolvePriceBatch espera [{itemId, basePrice}]; con una lista plana de IDs
// (["uuid", ...]) el array_map de adentro hacía `$i['itemId']` sobre un string
// y el request moría en un 500 fatal — "Cannot access offset of type string on
// string" (capturado en producción, GlitchTip issue 8). El borde del endpoint
// es donde se valida la entrada: acá sale un 422 que dice qué se mandó mal.
foreach ($items as $i => $item) {
    if (!is_array($item) || !isset($item['itemId']) || !is_string($item['itemId']) || $item['itemId'] === '') {
        apiError('items[' . $i . '] debe ser un objeto con itemId — se recibió ' . gettype($item), 422);
    }
}

$svc    = new PriceListService(TenantContext::fromAuth($ctx));
$result = $svc->resolvePriceBatch($companyId, $items, $contactId, $outletId, $priceListId);

apiOk($result);
