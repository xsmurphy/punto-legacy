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
 * caja", F4 — no implementado todavía).
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
    'SELECT "registerLeaseId", "deviceId"
       FROM "register_lease"
      WHERE "registerId" = ? AND "status" = \'active\'
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
    apiConflict('Esta caja está tomada por otro dispositivo', [
        'holderDeviceId'   => (string) $activeLease['deviceId'],
        'holderDeviceName' => $holderHasRow ? (string) ($holderRow['deviceName'] ?? '') : '',
        'expiresAt'        => null,
    ]);
}

if ($activeLease === false || $activeLease === 0) {
    // Nadie tiene la caja tomada — este dispositivo la toma ahora.
    // `expiresAt` queda NULL (mig 144): la tenencia ya no vence por fecha.
    $newLease = ncmExecute(
        'INSERT INTO "register_lease"
            ("companyId", "outletId", "registerId", "deviceId", "status")
         VALUES (?, ?, ?, ?, \'active\')
         RETURNING "registerLeaseId"',
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

apiOk([
    'registerLeaseId' => $registerLeaseId,
]);
