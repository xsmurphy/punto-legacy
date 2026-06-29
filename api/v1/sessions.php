<?php
require_once __DIR__ . '/../bootstrap.php';
$ctx = apiAuthTenant(['panel']); // solo el panel gestiona sesiones
require_once API_APP_DIR . '/includes/auth_session.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
global $db;

if ($method === 'GET') {
    $showRevoked = ($_GET['showRevoked'] ?? '') === '1';
    $r = $db->Execute(
        'SELECT s."sessionId", s.realm, s."userId", s."deviceId", s."outletId", s."registerId", s.module,
                s.status, s."createdAt", s."lastSeenAt", s."expiresAt", s."revokedAt", s."ipLast", s."userAgent",
                c.contactname AS "userName",
                o.outletname  AS "outletName",
                d.devicename  AS "deviceName"
           FROM auth_session s
           LEFT JOIN contact c ON c.contactid = s."userId"   AND c.companyid = s."companyId"
           LEFT JOIN outlet  o ON o.outletid  = s."outletId" AND o.companyid = s."companyId"
           LEFT JOIN device  d ON d.deviceid  = s."deviceId" AND d.companyid = s."companyId"
          WHERE s."companyId" = ? ' . ($showRevoked ? '' : 'AND s.status = 1 ') . 'ORDER BY s."lastSeenAt" DESC NULLS LAST LIMIT 500',
        [$ctx['companyId']]
    );
    $rows = [];
    while ($r && !$r->EOF) { $rows[] = $r->fields; $r->MoveNext(); }
    apiOk(['sessions' => $rows]);
}

if ($method === 'DELETE') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        apiError('sessionId inválido', 422);
    }
    // Scope de tenant (§1): solo sesiones de la propia empresa.
    $chk = $db->Execute('SELECT 1 FROM auth_session WHERE "sessionId" = ? AND "companyId" = ? LIMIT 1', [$id, $ctx['companyId']]);
    if (!$chk || $chk->EOF) { apiError('Sesión no encontrada', 404); }
    authSessionRevokeBySessionId($id, $ctx['userId']);
    apiOk(['ok' => true]);
}

apiError('Método no permitido', 405);
