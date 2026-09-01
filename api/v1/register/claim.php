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
 * POST { acquire?: bool } → confirma la tenencia y, solo si `acquire`, la toma.
 *   200 { registerLeaseId, registerId } — el device es (o pasa a ser) el tenedor.
 *   409 { holderDeviceId, holderDeviceName, expiresAt: null, reason, ... } —
 *       este device NO es el tenedor. `reason` dice por qué (ver
 *       `RegisterLeaseService::holderConflict()`): `taken_by_other` es el
 *       único donde la caja está ocupada; `released`/`revoked`/`never_held`
 *       significan que está LIBRE y este device simplemente no la tiene.
 *
 * CONFIRMAR ≠ ADQUIRIR (owner, 2026-09-01)
 * ────────────────────────────────────────
 * Hasta este cambio el endpoint hacía las dos cosas juntas —"confirmá O
 * tomá"— y el POS lo llamaba cada `HEARTBEAT_MS` (5 min) sin importar si
 * tenía la caja o no. Consecuencia: un POS abierto en esa caja se la volvía a
 * tomar SOLO, en silencio, apenas quedaba libre. El bug que reportó el owner
 * sale de ahí: dos dispositivos con la misma caja asignada, el primero libera,
 * y el segundo sigue sin poder facturar porque el latido del primero se la
 * lleva de nuevo antes. El segundo solo ganaba si su latido caía en la ventana
 * entre la liberación y el próximo latido del primero — una carrera que casi
 * siempre pierde. (La mig 183 atacó una cara de lo mismo: las sesiones
 * fantasma que latían por su cuenta. Esto ataca la otra: que latir tome.)
 *
 * Con `acquire: false` el latido solo PREGUNTA. La caja se toma por un acto
 * deliberado del cajero (el botón "Tomar caja" del POS) o cuando el drenaje de
 * la cola offline recupera una venta YA EMITIDA — nunca por un timer ni por un
 * evento.
 *
 * `acquire` ausente ⇒ `true`. Es compatibilidad TRANSITORIA con un cliente que
 * todavía tenga el bundle viejo: ese PWA seguiría ocupando la caja en cada
 * latido hasta que recargue. La ventana es chica y se cierra sola con un
 * reload, y el default opuesto sería peor —un cliente viejo no podría tomar la
 * caja NUNCA—. Sacar este default en cuanto no queden bundles previos a
 * 2026-09-01 en la calle. Ojo: el default del CLIENTE es el contrario
 * (`refreshTenancy()` en `lib/pos/register-tenancy.ts` exige `acquire: true`
 * explícito) — ahí el lado seguro es no tomar.
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

// `acquire`: ¿este POST puede TOMAR la caja, o solo confirmar si ya es suya?
// Ver "CONFIRMAR ≠ ADQUIRIR" en el docblock. Default `true` por
// compatibilidad transitoria con bundles viejos; el cliente actual siempre lo
// manda explícito.
$claimBody   = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
$mayAcquire  = !array_key_exists('acquire', $claimBody) || (bool) $claimBody['acquire'];

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
//
// La DECISIÓN (confirmar / tomar / rechazar, con su lock y su transacción) vive
// en `RegisterLeaseService::claim()`, no acá: es la política de exclusividad de
// caja, y una política que solo se puede ejercitar levantando un endpoint HTTP
// no se puede testear. Este archivo es transporte — resuelve el contexto,
// llama, y traduce el resultado a 200/409.
$outcome = \Punto\Api\Services\RegisterLeaseService::claim(
    $regId,
    $compId,
    $outletId,
    $deviceId,
    $mayAcquire
);

if ($outcome['conflict'] !== null) {
    // `conflictCode` en `details`, igual que `sales.php` — ver el comentario
    // de ese call-site: `error.code` es el status HTTP, no la causa.
    [$conflictCode, $conflictMessage] = \Punto\Api\Services\RegisterLeaseService::conflictMessage($outcome['conflict']);
    apiConflict($conflictMessage, $outcome['conflict'] + ['conflictCode' => $conflictCode]);
}

if ($outcome['registerLeaseId'] === null) {
    // El servicio no pudo ni confirmar ni rechazar (falla transitoria de DB en
    // el INSERT). Cortar acá en vez de dejar una caja sin tenedor real.
    apiError('No se pudo tomar la caja, intentá de nuevo', 500);
}

// `registerId` viaja en la respuesta para que el device guarde el grant contra
// la caja que el SERVIDOR le confirmó, no contra la que él cree tener. Las dos
// salen hoy de la misma fila `device`, pero si divergen (device reasignado y
// bootstrap viejo en memoria), guardar la del cliente crearía un grant que
// dice "tengo la caja X" cuando lo confirmado fue la Y — exactamente el tipo
// de afirmación sin respaldo que este cambio existe para eliminar.
apiOk([
    'registerLeaseId' => $outcome['registerLeaseId'],
    'registerId'      => $regId,
]);
