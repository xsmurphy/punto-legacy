<?php
/**
 * /api/v1/sold_pack.php — instancias de packs vendidas.
 *
 *   GET ?contactId=<uuid>               → packs activos/históricos del cliente
 *   PUT ?id=<soldPackId>&action=block   → bloquear manualmente un pack
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

switch ($method) {
    case 'GET':
        $contactId = trim((string) ($_GET['contactId'] ?? ''));
        if ($contactId === '') {
            apiError('Falta contactId', 422);
        }
        apiOk($svc->listForContact($companyId, $contactId));

    case 'PUT':
        $soldPackId = trim((string) ($_GET['id'] ?? ''));
        $action     = trim((string) ($_GET['action'] ?? ''));
        if ($soldPackId === '') {
            apiError('Falta id', 422);
        }
        if ($action !== 'block') {
            apiError('Acción no reconocida. Use action=block', 422);
        }
        // Bloqueo manual — reutilizamos expireStale para lazy; o Execute directo.
        global $db;
        $res = $db->Execute(
            'UPDATE sold_pack SET status = 0, updatedAt = now()
              WHERE soldPackId = ? AND companyId = ?',
            [$soldPackId, $companyId]
        );
        if (!$res) {
            apiError('No se pudo bloquear el pack', 500);
        }
        apiOk(['blocked' => true, 'soldPackId' => $soldPackId]);

    default:
        apiError('Método no permitido', 405);
}
