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
require_once __DIR__ . '/../lib/services/RegisterLeaseService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Services\RegisterLeaseService;

$__ctx = apiAuthTenant(['panel']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Gate de autorización. El catálogo no separa ver de administrar dispositivos
// y la lista no es material de consulta: expone el parque de cajas del tenant
// y es la pantalla desde la que se revoca una. Una sola clave para todo el
// endpoint. Realm `panel` únicamente, así que el rol es el del operador real.
if (!hasPermission('settings.device.manage')) {
    apiError('No tenés permiso para esta acción (requiere: settings.device.manage)', 403);
}

if ($method === 'DELETE') {
    $deviceId = trim((string) ($_GET['id'] ?? $_POST['deviceId'] ?? ''));
    $hard     = ($_GET['hard'] ?? '') === '1';
    if ($deviceId === '') {
        apiError('id requerido', 422);
    }
    // El `companyId` va DENTRO de la query, no en un chequeo posterior (P2 de
    // la auditoría de auth del 2026-08-26).
    //
    // Antes se buscaba el device por id solo y se comparaba después: un device
    // ajeno daba 403 y uno inexistente 404, así que la diferencia de códigos
    // confirmaba la EXISTENCIA de un device de otro tenant a cualquiera que
    // probara UUIDs. Unificar los dos a 404 también lo cerraría, pero deja la
    // rama viva y dependiendo de que nadie la toque; scopeando la query no
    // queda nada que unificar — un device ajeno es indistinguible de uno que no
    // existe porque para esta consulta, literalmente, no existe.
    $device = ncmExecute(
        'SELECT deviceid, status FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid',
        [$deviceId, COMPANY_ID]
    );
    if (!$device) {
        apiError('Device no encontrado', 404);
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
    // Revocar el device sin liberar su tenencia de caja (context/29 §4) dejaba
    // `register_lease` tomada para siempre — el device revocado nunca más puede
    // llamar a claim.php para liberarse solo, y no había otra vía automática
    // (bug real 2026-08-19: caja bloqueada sin salida tras revocar el device
    // que la tenía). 'forced': es el admin actuando sobre un device de otro,
    // no el propio device liberándose — mismo motivo que register-lease.php.
    RegisterLeaseService::releaseByDevice($deviceId, COMPANY_ID, 'admin:' . (string) $__ctx['userId'], 'forced');
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
              WHERE s.deviceid = d.deviceid
                AND s.companyid = d.companyid
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
