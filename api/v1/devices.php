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
        'SELECT "deviceId", "companyId" FROM device WHERE "deviceId" = ?::uuid',
        [$deviceId]
    );
    if (!$device) {
        apiError('Device no encontrado', 404);
    }
    $devCompanyId = (string) ($device['companyid'] ?? $device['companyId'] ?? '');
    if ($devCompanyId !== COMPANY_ID) {
        apiError('No autorizado', 403);
    }
    DeviceAuth::revoke($deviceId);
    apiOk(['ok' => true]);
    exit;
}

// GET -- listar devices del tenant
$rs = ncmExecute(
    'SELECT d."deviceId", d."deviceName", d."outletId", o."outletName",
            d."registerId", r."registerName", d."userId" AS "pairedByContactId",
            c."contactName" AS "pairedByName",
            d."createdAt" AS "pairedAt", d."lastSeenAt",
            d.status, d."revokedAt"
     FROM device d
     LEFT JOIN outlet   o ON o."outletId"   = d."outletId"
     LEFT JOIN register r ON r."registerId" = d."registerId"
     LEFT JOIN contact  c ON c."contactId"  = d."userId"
     WHERE d."companyId" = ?::uuid
     ORDER BY d."lastSeenAt" DESC NULLS LAST',
    [COMPANY_ID],
    false,
    true
);

$devices = [];
if ($rs && !$rs->EOF) {
    while (!$rs->EOF) {
        $row      = (array) $rs->fields;
        $devices[] = [
            'deviceId'          => $row['deviceid']          ?? '',
            'deviceName'        => $row['devicename']        ?? '',
            'outletId'          => $row['outletid']          ?? null,
            'outletName'        => $row['outletname']        ?? null,
            'registerId'        => $row['registerid']        ?? null,
            'registerName'      => $row['registername']      ?? null,
            'pairedByContactId' => $row['pairedbycontactid'] ?? null,
            'pairedByName'      => $row['pairedbyname']      ?? null,
            'pairedAt'          => $row['pairedat']          ?? null,
            'lastSeenAt'        => $row['lastseenAt']        ?? $row['lastseent'] ?? $row['lastseenat'] ?? null,
            'status'            => (int) ($row['status']     ?? 1),
            'revokedAt'         => $row['revokedat']         ?? null,
        ];
        $rs->MoveNext();
    }
}

apiOk(['devices' => $devices]);
