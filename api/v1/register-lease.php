<?php
/**
 * /api/v1/register-lease.php — F4 de context/29-numeracion-y-exclusividad-de-caja.md:
 * panel "Liberar caja" (tenencia de `register_lease`, mig 141).
 *
 *   GET  /v1/register-lease?outletId=<uuid opcional>
 *       → { leases: [{ registerId, registerName, lease: null | {
 *             registerLeaseId, deviceId, deviceName, takenAt } }] }
 *   POST /v1/register-lease { action: "release", registerLeaseId }
 *       → { ok: true, alreadyReleased: bool, releasedDeviceId?: string }
 *
 * Realm: EXCLUSIVAMENTE panel (cookie `_jwt_panel`). La tenencia de caja es
 * dato/acción de administración de sucursal — nunca algo que un device deba
 * poder leer o tocar sobre sí mismo desde acá (el device libera su propia
 * tenencia al cerrar caja por un camino aparte, fuera de este endpoint).
 *
 * NUEVO Y PROPIO de F4 (owner, 2026-08-17) — no modifica
 * `api/v1/numbering/lease.php` ni `api/v1/offline-sync.php`, que otro agente
 * está tocando en paralelo (F2/F3, arriendo de bloques). Comparte con esos
 * SOLO `RegisterLeaseService::close()`, que ya existe en
 * `api/lib/services/RegisterLeaseService.php` con un docblock que reserva
 * `close('forced', ...)` exactamente para este endpoint.
 *
 * Anti-IDOR: toda query de `register_lease`/`register`/`device` filtra por
 * companyId = COMPANY_ID — la constante que fija apiAuthTenant() a partir del
 * JWT, nunca un valor que venga del body/query del request.
 *
 * Permiso: `settings.register.release`, dedicado (PermissionCatalog.php) y
 * separado de `settings.register.manage` — liberar desconecta a un tercero
 * que puede estar operando ahora mismo y anula sus números arrendados no
 * consumidos; otro radio de impacto que el CRUD de la caja en sí.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/services/RegisterLeaseService.php';

use Punto\Api\Services\RegisterLeaseService;

$ctx    = apiAuthTenant(['panel']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'GET') {
    $outletId = trim((string) ($_GET['outletId'] ?? ''));
    if ($outletId !== '' && !preg_match($uuidRe, $outletId)) {
        apiError('outletId inválido', 422);
    }

    $params       = [COMPANY_ID];
    $outletFilter = '';
    if ($outletId !== '') {
        $outletFilter = ' AND r.outletid = ?::uuid';
        $params[]     = $outletId;
    }

    // `register`/`device` son tablas legacy con columnas SIN comillas en su
    // DDL (pliegan a lowercase físico: registerid/registername/outletid/
    // companyid, devicename) — NO camelCase quoted. `register_lease` (mig
    // 141) es al revés: sus columnas SÍ están quoted en el CREATE TABLE, así
    // que el nombre físico conserva el camelCase. Referenciar cada tabla con
    // el casing que le corresponde y alias-ear register/device a camelCase
    // en el SELECT — así el PHP de abajo lee $row['registerId'] igual para
    // las tres tablas sin tener que acordarse de la diferencia en cada punto
    // de uso.
    //
    // LEFT JOIN: una caja SIN tenedor activo tiene que aparecer igual en la
    // respuesta con lease=null ("libre"), no desaparecer del listado — el
    // admin necesita ver las libres tanto como las tomadas.
    $rs = ncmExecute(
        'SELECT r.registerid AS "registerId", r.registername AS "registerName",
                rl.registerleaseid, rl.deviceid, rl.takenat,
                d.devicename AS "deviceName", d.registerid AS "deviceRegisterId"
           FROM register r
           LEFT JOIN "register_lease" rl
                  ON rl.registerid = r.registerid AND rl."status" = \'active\'
           LEFT JOIN device d ON d.deviceid = rl.deviceid
          WHERE r.companyid = ?::uuid' . $outletFilter . '
          ORDER BY r.registername ASC',
        $params,
        false,
        true // forceObj — recordset, NO array (trampa conocida del wrapper)
    );

    $leases = [];
    if ($rs !== false && $rs !== 0) {
        while (!$rs->EOF) {
            $row      = $rs->fields;
            $leaseId  = $row['registerLeaseId'] ?? null;
            // `orphaned`: la tenencia sigue 'active' sobre ESTA caja pero la
            // fila `device` ya apunta a otra — señal de la clase de bug que
            // 151_release_orphaned_register_leases.sql limpió (device
            // reasignado sin liberar su tenencia vieja). Con el fix de
            // active-register.php/claim.php de esta sesión no debería volver
            // a pasar, pero si algún camino nuevo lo reintroduce, el admin lo
            // ve acá sin tener que ir a mirar la BD.
            $orphaned = $leaseId !== null && $leaseId !== ''
                && (string) ($row['deviceRegisterId'] ?? '') !== (string) $row['registerId'];
            $leases[] = [
                'registerId'   => (string) $row['registerId'],
                'registerName' => (string) $row['registerName'],
                'lease'        => ($leaseId === null || $leaseId === '') ? null : [
                    'registerLeaseId' => (string) $leaseId,
                    'deviceId'        => (string) $row['deviceId'],
                    'deviceName'      => (string) ($row['deviceName'] ?? ''),
                    'takenAt'         => (string) $row['takenAt'],
                    'orphaned'        => $orphaned,
                ],
            ];
            $rs->MoveNext();
        }
    }

    apiOk(['leases' => $leases]);
}

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

$body   = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
$action = (string) ($body['action'] ?? '');

if ($action !== 'release') {
    apiError('Acción no soportada', 422);
}

// Irreversible sobre numeración fiscal (anula los números arrendados no
// consumidos de la tenencia cortada) — permiso dedicado, no el genérico de
// gestionar cajas. Chequeo ANTES de tocar nada.
if (!hasPermission('settings.register.release')) {
    apiError('No tenés permiso para esta acción (requiere: settings.register.release)', 403);
}

$registerLeaseId = trim((string) ($body['registerLeaseId'] ?? ''));
if (!preg_match($uuidRe, $registerLeaseId)) {
    apiError('registerLeaseId inválido', 422);
}

// Resolver registerId ANTES del lock (y validar tenant — anti-IDOR): el
// advisory lock de abajo es por registerId (mismo mecanismo que
// numbering/lease.php), hace falta saberlo antes de poder tomarlo. Esta
// lectura NO decide nada todavía — la decisión real ocurre después del lock,
// con FOR UPDATE. 404 uniforme si no existe O no es de este tenant: no
// revelar cuál de las dos cosas pasó.
$pre = ncmExecute(
    'SELECT registerid FROM "register_lease" WHERE registerleaseid = ?::uuid AND companyid = ?::uuid',
    [$registerLeaseId, COMPANY_ID]
);
if (!$pre) {
    apiError('Tenencia no encontrada', 404);
}
$regId = (string) $pre['registerId'];

global $db;
$db->StartTrans();

// Mismo lock exclusivo por caja que numbering/lease.php — dos releases
// concurrentes de la MISMA caja (dos admins, o un admin y el propio device
// cerrando turno al mismo tiempo) se serializan acá.
ncmExecute('SELECT pg_advisory_xact_lock(hashtext(?))', [$regId]);

// Releer bajo lock. Si entre el listado que el admin vio y este click la
// tenencia ya se cerró (otro admin ganó la carrera, o el device cerró caja
// solo), `RegisterLeaseService::close()` sería un no-op silencioso (ver su
// docblock) — lo detectamos ACÁ antes para devolver éxito idempotente en vez
// de un error confuso: el estado final que el admin quería (caja libre) ya
// es el real.
$current = ncmExecute(
    'SELECT "status", deviceid FROM "register_lease"
      WHERE registerleaseid = ?::uuid AND companyid = ?::uuid
      FOR UPDATE',
    [$registerLeaseId, COMPANY_ID]
);

if (!$current || (string) $current['status'] !== 'active') {
    $db->CompleteTrans();
    apiOk(['ok' => true, 'alreadyReleased' => true]);
}

$releasedDeviceId = (string) $current['deviceId'];
$contactId        = (string) $ctx['userId'];

RegisterLeaseService::close($registerLeaseId, 'forced', 'admin:' . $contactId, 'forced');

$db->CompleteTrans();

apiOk(['ok' => true, 'alreadyReleased' => false, 'releasedDeviceId' => $releasedDeviceId]);
