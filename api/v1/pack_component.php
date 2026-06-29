<?php
/**
 * /api/v1/pack_component.php — componentes de un pack (editor de items en frontend).
 *
 *   GET ?packItemId=<uuid>               → lista componentes del pack
 *   PUT ?packItemId=<uuid>  {components:[{componentItemId, componentQty, sort}]}
 *                                        → reemplaza todos (bulk replace)
 *
 * Auth: JWT de tenant — realms panel y pos-app.
 * Envelope: { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/PackService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\PackService;

$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$svc        = new PackService(TenantContext::fromAuth($ctx));
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        $packItemId = trim((string) ($_GET['packItemId'] ?? ''));
        if ($packItemId === '') {
            apiError('Falta packItemId', 422);
        }
        apiOk($svc->listComponents($companyId, $packItemId));

    case 'PUT':
        $packItemId  = trim((string) ($_GET['packItemId'] ?? ''));
        $components  = $_POST['components'] ?? [];
        if ($packItemId === '') {
            apiError('Falta packItemId', 422);
        }
        if (!is_array($components)) {
            apiError('components debe ser un array', 422);
        }
        $result = $svc->setComponents($companyId, $packItemId, $components);
        apiOk($result);

    default:
        apiError('Método no permitido', 405);
}
