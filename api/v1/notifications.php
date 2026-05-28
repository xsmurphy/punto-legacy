<?php
/**
 * /api/v1/notifications.php — notificaciones del POS (Slice 14, cluster ENCOM→Punto).
 *
 *   POST            → lista notificaciones nuevas + marca como visto (mutación → POST, §16)
 *   GET  ?type=     → cuenta notificaciones nuevas (lectura pura)
 *
 * companyId/userId/outletId SIEMPRE del JWT (el legacy los mandaba en el body como
 * enc(USER_ID)/enc(OUTLET_ID) = los valores authed). Envelope canónico { ok, data }.
 * list() va en POST (no GET) porque escribe lastSeen — ver §16/NotificationService.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/NotificationService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];
$userId     = $ctx['userId'];
$outletId   = $ctx['outletId'];

$svc    = new NotificationService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET = count (lectura pura). POST = list (mutación: marca como visto). Ver §22.7 / §16.
if ($method === 'GET') {
    $type = (string) ($_GET['type'] ?? 'notes');
    apiOk($svc->countForUser($companyId, $userId, $outletId, $type));
}

if ($method === 'POST') {
    apiOk($svc->listForUser($companyId, $userId, $outletId));
}

apiError('Método no permitido', 405);
