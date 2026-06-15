<?php
/**
 * /api/v1/sold_pack_usage.php — registrar canjes de servicios de un pack.
 *
 *   POST { soldPackId, usages:[{packComponentId, qty}], performedBy? }
 *        → registra los canjes y devuelve el estado actualizado del pack
 *
 * Auth: JWT de tenant — realms panel y pos-app.
 * Envelope: { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/PackService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\PackService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$svc       = new PackService(TenantContext::fromAuth($ctx));
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

$soldPackId  = trim((string) ($_POST['soldPackId'] ?? ''));
$usages      = $_POST['usages'] ?? [];
$performedBy = trim((string) ($_POST['performedBy'] ?? '')) ?: null;

if ($soldPackId === '') {
    apiError('Falta soldPackId', 422);
}
if (!is_array($usages) || count($usages) === 0) {
    apiError('usages debe ser un array no vacío', 422);
}

$result = $svc->recordUsage($companyId, $soldPackId, $usages, $performedBy);

if (empty($result['ok'])) {
    apiError($result['error'] ?? 'No se pudo registrar el canje', 422);
}

apiOk($result);
