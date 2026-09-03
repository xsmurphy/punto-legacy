<?php
/**
 * GET    /v1/devices                        -- Lista de dispositivos POS del tenant (solo activos por default).
 * GET    /v1/devices?showRevoked=1          -- Lista incluye revocados (historial).
 *
 * Cada device del GET trae, además de su caja ASIGNADA (`registerId`), la
 * TENENCIA que ESE dispositivo tiene (`register_lease` activa, mig 141 —
 * context/29). Son dos cosas distintas: la asignación dice a qué caja
 * pertenece el aparato, la tenencia dice qué caja está reteniendo AHORA.
 * Solo un dispositivo puede tener una caja a la vez, y facturar exige
 * tenerla.
 *
 *   holdsRegister    bool          -- este device tiene tomada alguna caja.
 *   heldRegisterName string | null -- nombre de la caja que tiene tomada.
 *
 * La tenencia se resuelve POR DISPOSITIVO, no por la caja asignada. La caja
 * tomada PUEDE no ser la asignada: un device reasignado que no liberó su
 * tenencia vieja sigue reteniendo la caja anterior (`heldRegisterName` !=
 * `registerName`). Esa es exactamente la pregunta que responde el DELETE de
 * abajo, que llama a `releaseByDevice()` — libera la lease DEL DISPOSITIVO,
 * sea sobre la caja que sea. Antes esto se resolvía por `d.registerid`
 * ("¿quién tiene tomada la caja asignada a este device?"), así que el aviso
 * previo a revocar y el efecto del revoke hablaban de cajas distintas.
 *
 * `holdsRegister` es false y `heldRegisterName` null cuando el device no
 * retiene ninguna caja — el caso normal de screen/kds/display/print, y
 * también el de un POS con su caja libre. "Libre" no es un problema que
 * reportar.
 *
 * El LISTADO de tenencias por caja (con la marca de huérfana y la acción
 * "Liberar caja") NO vive acá: es Ajustes → Sucursales → Cajas, contra
 * `/v1/register-lease`. Acá el dato existe solo para poder advertir, antes
 * de revocar, qué caja va a quedar libre.
 *
 * Cada device trae además `historyKinds`: qué rastro operativo dejó
 * (`register_lease` / `auth_session` / `pos_order_event` / `station_printer`).
 * Lista vacía = se puede borrar físicamente; con cualquier elemento, el DELETE
 * duro lo rechaza. Ver `DeviceHistoryService`.
 *
 * DELETE /v1/devices?id=X                   -- Soft revoke (status=0). Preserva auditoría.
 * DELETE /v1/devices?id=X&hard=1            -- DELETE físico. Requiere status=0 (ya revocado)
 *                                              Y `historyKinds` vacío — un aparato con
 *                                              historial se conserva (409 DEVICE_HAS_HISTORY).
 *
 * Auth: panel (solo admin del tenant).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/services/RegisterLeaseService.php';
require_once __DIR__ . '/../lib/services/DeviceHistoryService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Services\DeviceHistoryService;
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
        // Un dispositivo con historial operativo NO se borra (owner,
        // 2026-09-01). Sin este gate el DELETE reventaba con el 23503 crudo de
        // la FK de `register_lease` —el único de los cuatro rastros que tiene
        // FK dura— y borraba en SILENCIO la referencia de los otros tres,
        // dejando sesiones, eventos de orden e impresoras apuntando a un
        // aparato inexistente. El porqué de cada tabla, y por qué la FK NO
        // lleva CASCADE ni SET NULL, está en `DeviceHistoryService`.
        //
        // Revocar ya lo dejó fuera de servicio: no queda ninguna capacidad
        // operativa por retirarle, solo el rastro de lo que hizo.
        $historyKinds = DeviceHistoryService::kindsFor($deviceId, COMPANY_ID);
        if ($historyKinds !== []) {
            apiConflict(
                'Este dispositivo tiene historial de ' . DeviceHistoryService::describe($historyKinds)
                . ', y se conserva para poder auditarlo. Revocarlo ya lo dejó fuera de servicio.',
                ['conflictCode' => 'DEVICE_HAS_HISTORY', 'historyKinds' => $historyKinds]
            );
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

// Casing de identificadores: `device`, `outlet`, `register` y `contact` son
// tablas legacy creadas SIN comillas → todo minúscula. `auth_session` y
// `register_lease` nacieron con columnas camelCase entrecomilladas, pero la
// mig 150 las normalizó a minúscula también, así que hoy TODA esta query va
// sin comillas. (El docblock de api/v1/register-lease.php todavía dice que
// `register_lease` conserva el camelCase; quedó desactualizado por la 150 —
// su SQL sí está bien.)
//
// La tenencia entra por LEFT JOIN LATERAL con LIMIT 1, y eso NO es adorno.
// El índice único parcial `uq_register_lease_active` (mig 141) es sobre
// `registerid`, no sobre `deviceid`: la BD garantiza "una caja, un
// dispositivo", nunca "un dispositivo, una caja". En el flujo normal un
// device tiene como mucho una lease activa porque `claim()` cierra las otras
// del mismo device antes de insertar (su autocorrección), pero ese lock es
// `pg_advisory_xact_lock(hashtext(registerId))` — dos claims del MISMO device
// sobre cajas DISTINTAS toman locks distintos y no se serializan entre sí, y
// `releaseByDevice()` cierra con `LIMIT 1`, o sea que si alguna vez hay dos,
// solo se limpia una. Con un JOIN plano ese estado duplicaría la fila del
// dispositivo en el listado. El LATERAL lo vuelve imposible por
// construcción, y el orden (más reciente primero, con el id como desempate)
// hace que la caja que se muestra sea siempre la misma entre requests.
$rs = ncmExecute(
    "SELECT d.deviceid, d.devicename, d.outletid, o.outletname,
            d.registerid, r.registername, d.userid AS pairedbycontactid,
            c.contactname AS pairedbyname,
            d.createdat AS pairedat, d.lastseenat,
            d.status, d.revokedat, d.module, d.iplast::text AS iplast,
            rl.registerid AS heldregisterid,
            hr.registername AS heldregistername,
            " . DeviceHistoryService::selectSql('d') . ",
            (SELECT count(*) FROM auth_session s
              WHERE s.deviceid = d.deviceid
                AND s.companyid = d.companyid
                AND s.status = 1) AS activesessions
     FROM device d
     LEFT JOIN outlet   o ON o.outletid   = d.outletid   AND o.companyid = d.companyid
     LEFT JOIN register r ON r.registerid = d.registerid AND r.companyid = d.companyid
     LEFT JOIN contact  c ON c.contactid  = d.userid     AND c.companyid = d.companyid
     LEFT JOIN LATERAL (
         SELECT l.registerid
           FROM register_lease l
          WHERE l.deviceid  = d.deviceid
            AND l.companyid = d.companyid
            AND l.status    = 'active'
          ORDER BY l.takenat DESC, l.registerleaseid DESC
          LIMIT 1
     ) rl ON TRUE
     LEFT JOIN register hr ON hr.registerid = rl.registerid AND hr.companyid = d.companyid
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
        $deviceId       = (string) ($rs->fields['deviceid']        ?? '');
        $heldRegisterId = (string) ($rs->fields['heldregisterid'] ?? '');
        // Una sola pregunta: ¿este dispositivo está reteniendo alguna caja?
        // Sin lease activa queda en false — la caja libre no es una anomalía
        // que reportar.
        $holdsRegister  = $heldRegisterId !== '';
        $devices[] = [
            'deviceId'          => $deviceId,
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
            // Rastro operativo del aparato. NO es "tiene la caja ahora": es
            // "dejó historial alguna vez", de cualquier tipo y en cualquier
            // estado. El panel lo usa para deshabilitar "Eliminar" con el
            // motivo a la vista en vez de ofrecer una acción que el 409 de
            // abajo va a rechazar. Lista vacía = se puede borrar.
            'historyKinds'      => DeviceHistoryService::kindsFromRow($rs->fields),
            'holdsRegister'     => $holdsRegister,
            // La caja que este dispositivo tiene TOMADA, que puede no ser la
            // asignada (`registerName`) si quedó reteniendo la anterior.
            'heldRegisterName'  => $holdsRegister
                ? (string) ($rs->fields['heldregistername'] ?? '')
                : null,
        ];
        $rs->MoveNext();
    }
}

apiOk(['devices' => $devices]);
