<?php
/**
 * GET    /v1/devices                        -- Lista de dispositivos POS del tenant (solo activos por default).
 * GET    /v1/devices?showRevoked=1          -- Lista incluye revocados (historial).
 * DELETE /v1/devices?id=X                   -- Soft revoke (status=0). Preserva auditoría.
 * DELETE /v1/devices?id=X&hard=1            -- DELETE físico. Solo permitido si status=0 (ya revocado).
 *
 * Auth: panel (solo admin del tenant).
 */

require_once __DIR__ . '/../bootstrap.php';

use Punto\Api\Auth\DeviceAuth;

apiAuthTenant(['panel']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'DELETE') {
    $deviceId = trim((string) ($_GET['id'] ?? $_POST['deviceId'] ?? ''));
    $hard     = ($_GET['hard'] ?? '') === '1';
    if ($deviceId === '') {
        apiError('id requerido', 422);
    }
    $device = ncmExecute(
        'SELECT deviceid, companyid, status FROM device WHERE deviceid = ?::uuid',
        [$deviceId]
    );
    if (!$device) {
        apiError('Device no encontrado', 404);
    }
    $devCompanyId = (string) ($device['companyid'] ?? '');
    if ($devCompanyId !== COMPANY_ID) {
        apiError('No autorizado', 403);
    }
    if ($hard) {
        // DELETE físico solo permitido si ya está revocado (preserva la barrera de seguridad:
        // un device activo nunca se borra sin pasar primero por revoke).
        if ((int) ($device['status'] ?? 1) !== 0) {
            apiError('Solo se pueden eliminar dispositivos ya revocados', 409);
        }
        ncmExecute(
            'DELETE FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid',
            [$deviceId, COMPANY_ID]
        );
        apiOk(['ok' => true, 'deleted' => 'hard']);
        exit;
    }
    DeviceAuth::revoke($deviceId, COMPANY_ID);
    require_once API_APP_DIR . '/includes/auth_session.php';
    authSessionRevokeByDevice($deviceId, COMPANY_ID, defined('AUTHED_USER_ID') ? AUTHED_USER_ID : null);
    apiOk(['ok' => true, 'deleted' => 'soft']);
    exit;
}

// GET -- listar devices del tenant. Por default solo activos.
$showRevoked = ($_GET['showRevoked'] ?? '') === '1';
$statusFilter = $showRevoked ? '' : 'AND d.status = 1';

$rs = ncmExecute(
    "SELECT d.deviceid, d.devicename, d.outletid, o.outletname,
            d.registerid, r.registername, d.userid AS pairedbycontactid,
            c.contactname AS pairedbyname,
            d.createdat AS pairedat, d.lastseenat,
            d.status, d.revokedat, d.module, d.iplast::text AS iplast,
            (SELECT count(*) FROM auth_session s
              WHERE s.\"deviceId\" = d.deviceid
                AND s.\"companyId\" = d.companyid
                AND s.status = 1) AS activesessions
     FROM device d
     LEFT JOIN outlet   o ON o.outletid   = d.outletid   AND o.companyid = d.companyid
     LEFT JOIN register r ON r.registerid = d.registerid AND r.companyid = d.companyid
     LEFT JOIN contact  c ON c.contactid  = d.userid     AND c.companyid = d.companyid
     WHERE d.companyid = ?::uuid
     {$statusFilter}
     ORDER BY d.lastseenat DESC NULLS LAST",
    [COMPANY_ID],
    false,
    true
);

$devices = [];
if ($rs && !$rs->EOF) {
    while (!$rs->EOF) {
        $devices[] = [
            'deviceId'          => (string) ($rs->fields['deviceid']          ?? ''),
            'deviceName'        => (string) ($rs->fields['devicename']        ?? ''),
            'outletId'          => $rs->fields['outletid']                    ?? null,
            'outletName'        => $rs->fields['outletname']                  ?? null,
            'registerId'        => $rs->fields['registerid']                  ?? null,
            'registerName'      => $rs->fields['registername']                ?? null,
            'pairedByContactId' => $rs->fields['pairedbycontactid']           ?? null,
            'pairedByName'      => $rs->fields['pairedbyname']                ?? null,
            'pairedAt'          => $rs->fields['pairedat']                    ?? null,
            'lastSeenAt'        => $rs->fields['lastseenat']                  ?? null,
            'status'            => (int) ($rs->fields['status']               ?? 1),
            'revokedAt'         => $rs->fields['revokedat']                   ?? null,
            'module'            => (string) ($rs->fields['module']            ?? ''),
            'ipLast'            => (string) ($rs->fields['iplast']            ?? ''),
            'activeSessions'    => (int) ($rs->fields['activesessions']       ?? 0),
        ];
        $rs->MoveNext();
    }
}

apiOk(['devices' => $devices]);
