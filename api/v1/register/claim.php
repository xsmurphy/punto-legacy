<?php
declare(strict_types=1);

/**
 * /api/v1/register/claim.php — tomar/verificar la TENENCIA de esta caja.
 *
 * Antes vivía en `api/v1/numbering/lease.php` y hacía DOS cosas mezcladas:
 * arrendaba un bloque de números de `numbering_lease` Y tomaba la tenencia
 * de la caja en `register_lease`. El arriendo de números fue RECHAZADO por
 * el owner 2026-08-17 (context/29-numeracion-y-exclusividad-de-caja.md §6:
 * la unicidad del punto de expedición ya resuelve sola el problema que el
 * arriendo intentaba resolver — cada caja tiene su propia rama de
 * numeración, no hay con quién chocar). Este endpoint separa las dos cosas:
 * ahora SOLO hace lo segundo. El número lo decide el device localmente
 * (`frontend/lib/pos/invoice-numbering.ts`, "último correlativo de mi
 * caja + 1"), nunca acá.
 *
 * POST → toma la caja para este device, o confirma que ya la tiene.
 *   200 { registerLeaseId } — el device es (o pasa a ser) el tenedor.
 *   409 { holderDeviceId, holderDeviceName, expiresAt: null } — otro
 *       device tiene la caja tomada.
 *
 * La tenencia YA NO vence por fecha/TTL (context/29 §4, 2026-08-17) — se
 * libera solo al cerrar la caja o por revocación de admin (panel, "Liberar
 * caja", `api/v1/register-lease.php`, F4 — YA implementada, context/29 §7).
 *
 * Del lado del device, la respuesta de este endpoint se PERSISTE en IndexedDB
 * con su hora (`frontend/lib/pos/register-tenancy.ts`): es lo que le permite
 * al POS saber SIN RED si tiene derecho a emitir. Hasta 2026-08-23 el 409 de
 * acá se descartaba en silencio y sin conexión no quedaba ningún gate — el
 * cajero vendía, imprimía, y el rechazo llegaba al sincronizar.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/Auth/apiAuthPosContext.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$authCtx = apiAuthPosContext();
if (($authCtx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}

if (($authCtx['registerId'] ?? '') === '') {
    apiError('Seleccioná una caja antes de operar', 403);
}

$regId    = $authCtx['registerId'];
$compId   = $authCtx['companyId'];
$outletId = $authCtx['outletId'];
$deviceId = (string) ($authCtx['deviceId'] ?? '');

if ($deviceId === '') {
    // No debería pasar nunca: apiAuthPosContext() resuelve deviceId desde el
    // Bearer del realm device. Si llega vacío, algo está mal con el token —
    // cortar acá en vez de crear una tenencia sin dueño.
    apiError('Dispositivo no identificado', 401);
}

// Timbrado vencido → no se puede operar esta caja (owner 2026-08-08). Antes
// este era el lugar donde se asignaba la numeración fiscal, así que el corte
// tenía sentido acá; ahora que este endpoint solo toma tenencia, el motivo
// sigue siendo válido por otra razón: sin timbrado vigente ningún documento
// que se emita bajo esta caja es válido ante la SET, así que no tiene
// sentido dejar que un device tome custodia de una caja que no puede
// facturar.
require_once __DIR__ . '/../../lib/services/RegisterService.php';
require_once __DIR__ . '/../../lib/services/RegisterLeaseService.php';
$authError = (new \Punto\Api\Services\RegisterService(
    \Punto\Api\Context\TenantContext::fromAuth($authCtx)
))->invoiceAuthError($regId, $compId);
if ($authError !== null) {
    apiError($authError, 422);
}

// F2 (context/29 §4) — exclusividad de caja atada al dispositivo.
// hashtext() produce un int4 estable desde un string arbitrario — lo usamos
// para derivar el lock key desde el UUID de la caja sin truncarlo a int.
global $db;
$db->StartTrans();

// Lock exclusivo de sesión por caja. Dos requests para la misma caja esperan
// acá (sea del mismo dispositivo o de dos distintos); distintas cajas no
// bloquean entre sí (el lock key es por registerId).
ncmExecute(
    "SELECT pg_advisory_xact_lock(hashtext(?))",
    [$regId]
);

// Tenencia activa de esta caja, si hay alguna. FOR UPDATE: nadie más puede
// leer/tocar esta fila hasta que cerremos la transacción — el advisory lock
// ya serializa por registerId, este FOR UPDATE es defensa en profundidad
// (mismo registerId nunca tiene 2 filas active por el constraint de mig 141,
// pero el lock de fila es gratis acá adentro y no cuesta nada tenerlo).
$activeLease = ncmExecute(
    'SELECT registerleaseid, deviceid
       FROM "register_lease"
      WHERE registerid = ? AND "status" = \'active\'
      FOR UPDATE',
    [$regId]
);

if ($activeLease !== false && $activeLease !== 0 && (string) $activeLease['deviceId'] !== $deviceId) {
    // Otro dispositivo tiene la caja tomada — el que llega segundo recibe un
    // rechazo explícito, no la caja (§4 del plan).
    $holderRow = ncmExecute(
        'SELECT deviceName FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid LIMIT 1',
        [(string) $activeLease['deviceId'], $compId]
    );
    $db->FailTrans();
    $db->CompleteTrans();

    $holderHasRow = $holderRow !== false && $holderRow !== 0;
    // `reason` en el shape de `holderConflict()` — el POS persiste este 409
    // como "tenencia DENEGADA" en su grant local (`lib/pos/register-tenancy.ts`)
    // y lo usa para bloquear el cobro ANTES de numerar, también sin conexión.
    // Un claim solo puede fallar por esta causa: si la caja estuviera libre,
    // este endpoint la habría tomado en vez de devolver 409.
    $conflictDetails = [
        'holderDeviceId'   => (string) $activeLease['deviceId'],
        'holderDeviceName' => $holderHasRow ? (string) ($holderRow['deviceName'] ?? '') : '',
        'expiresAt'        => null,
        'reason'           => 'taken_by_other',
        'releasedBy'       => null,
        'releasedAt'       => null,
    ];
    // `conflictCode` en `details`, igual que `sales.php` — ver el comentario
    // de ese call-site: `error.code` es el status HTTP, no la causa.
    [$conflictCode, $conflictMessage] = \Punto\Api\Services\RegisterLeaseService::conflictMessage($conflictDetails);
    apiConflict($conflictMessage, $conflictDetails + ['conflictCode' => $conflictCode]);
}

if ($activeLease === false || $activeLease === 0) {
    // Defensa en profundidad (context/29 §4, "dispositivo cambia de caja" +
    // bug real 2026-08-20: tenencia colgada en la caja anterior de un device
    // reasignado). El camino correcto para liberar la caja VIEJA es
    // `active-register.php` (o cualquier otro que cambie `device.registerid`)
    // al momento del cambio — pero si algún camino se olvida, este device NO
    // puede terminar con tenencia activa en DOS cajas a la vez. Autocorrección:
    // antes de tomar ESTA caja (confirmado arriba que nadie la tiene), cerrar
    // cualquier OTRA tenencia activa de este MISMO deviceId. Nunca toca la
    // tenencia de OTRO device — eso sigue prohibido (§6, "último que llega
    // pisa al anterior" fue RECHAZADO por el owner).
    \Punto\Api\Services\RegisterLeaseService::releaseByDevice(
        $deviceId,
        $compId,
        'device:claim-self-correct',
        'released'
    );

    // Nadie tiene la caja tomada — este dispositivo la toma ahora.
    // `expiresAt` queda NULL (mig 144): la tenencia ya no vence por fecha.
    $newLease = ncmExecute(
        'INSERT INTO "register_lease"
            (companyid, outletid, registerid, deviceid, "status")
         VALUES (?, ?, ?, ?, \'active\')
         RETURNING registerleaseid',
        [$compId, $outletId, $regId, $deviceId]
    );
    if ($newLease === false || $newLease === 0 || (string) ($newLease['registerLeaseId'] ?? '') === '') {
        // No debería pasar (INSERT ... RETURNING sobre una tabla sin
        // triggers), pero si el driver devuelve false por una falla
        // transitoria de DB, cortar acá en vez de dejar una caja sin
        // tenedor real.
        $db->FailTrans();
        $db->CompleteTrans();
        apiError('No se pudo tomar la caja, intentá de nuevo', 500);
    }
    $registerLeaseId = (string) $newLease['registerLeaseId'];
} else {
    // Fila activa, mismo deviceId — ya es el tenedor, confirmar sin cambios.
    $registerLeaseId = (string) $activeLease['registerLeaseId'];
}

$db->CompleteTrans();

// `registerId` viaja en la respuesta para que el device guarde el grant contra
// la caja que el SERVIDOR le confirmó, no contra la que él cree tener. Las dos
// salen hoy de la misma fila `device`, pero si divergen (device reasignado y
// bootstrap viejo en memoria), guardar la del cliente crearía un grant que
// dice "tengo la caja X" cuando lo confirmado fue la Y — exactamente el tipo
// de afirmación sin respaldo que este cambio existe para eliminar.
apiOk([
    'registerLeaseId' => $registerLeaseId,
    'registerId'      => $regId,
]);
