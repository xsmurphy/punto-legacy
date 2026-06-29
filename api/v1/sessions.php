<?php
require_once __DIR__ . '/../bootstrap.php';
$ctx = apiAuthTenant(['panel']); // solo el panel gestiona sesiones
require_once API_APP_DIR . '/includes/auth_session.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
global $db;

if ($method === 'GET') {
    $showRevoked = ($_GET['showRevoked'] ?? '') === '1';
    $where = '"companyId" = ?' . ($showRevoked ? '' : ' AND status = 1');
    $r = $db->Execute(
        'SELECT "sessionId", realm, "userId", "deviceId", "outletId", "registerId", module,
                status, "createdAt", "lastSeenAt", "expiresAt", "revokedAt", "ipLast", "userAgent"
           FROM auth_session WHERE ' . $where . ' ORDER BY "lastSeenAt" DESC NULLS LAST LIMIT 500',
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
