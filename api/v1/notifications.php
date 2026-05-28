<?php
/**
 * /api/v1/notifications.php — notificaciones del POS (Slice 14, cluster ENCOM→Punto).
 *
 *   POST op=list           → lista notificaciones nuevas + marca como visto (mutación)
 *   POST op=count { type }  → cuenta notificaciones nuevas (lectura pura)
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc = new NotificationService();
$op  = (string) ($_POST['op'] ?? '');

if ($op === 'list') {
    apiOk($svc->listForUser($companyId, $userId, $outletId));
}

if ($op === 'count') {
    $type = (string) ($_POST['type'] ?? 'notes');
    apiOk($svc->countForUser($companyId, $userId, $outletId, $type));
}

apiError('Operación no reconocida', 400);
