<?php
/**
 * GET    /v1/devices      -- Lista de dispositivos POS del tenant.
 * DELETE /v1/devices?id=X -- Revoca un device (alias de unpair).
 *
 * Auth: panel (solo admin del tenant).
 */

require_once __DIR__ . '/../bootstrap.php';

use Punto\Api\Auth\DeviceAuth;

apiAuthTenant(['panel']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'DELETE') {
    $deviceId = trim((string) ($_GET['id'] ?? $_POST['deviceId'] ?? ''));
    if ($deviceId === '') {
        apiError('id requerido', 422);
    }
    $device = ncmExecute(
        'SELECT deviceid, companyid FROM device WHERE deviceid = ?::uuid',
        [$deviceId]
    );
    if (!$device) {
        apiError('Device no encontrado', 404);
    }
    $devCompanyId = (string) ($device['companyid'] ?? '');
    if ($devCompanyId !== COMPANY_ID) {
        apiError('No autorizado', 403);
    }
    DeviceAuth::revoke($deviceId, COMPANY_ID);
    apiOk(['ok' => true]);
    exit;
}

// GET -- listar devices del tenant
$rs = ncmExecute(
    'SELECT d.deviceid, d.devicename, d.outletid, o.outletname,
            d.registerid, r.registername, d.userid AS pairedbycontactid,
            c.contactname AS pairedbyname,
            d.createdat AS pairedat, d.lastseenat,
            d.status, d.revokedat, d.module, d.iplast::text AS iplast
     FROM device d
     LEFT JOIN outlet   o ON o.outletid   = d.outletid   AND o.companyid = d.companyid
     LEFT JOIN register r ON r.registerid = d.registerid AND r.companyid = d.companyid
     LEFT JOIN contact  c ON c.contactid  = d.userid     AND c.companyid = d.companyid
     WHERE d.companyid = ?::uuid
     ORDER BY d.lastseenat DESC NULLS LAST',
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
        ];
        $rs->MoveNext();
    }
}

apiOk(['devices' => $devices]);
