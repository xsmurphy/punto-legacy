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
 * POST { acquire?: false | true | "operator" } → confirma la tenencia y, según
 * `acquire`, la toma.
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
 * deliberado del cajero (el botón "Tomar caja" del POS) — nunca por un timer ni
 * por un evento.
 *
 * `acquire` ausente ⇒ automática. Es compatibilidad TRANSITORIA con un cliente
 * que todavía tenga el bundle viejo: ese PWA seguiría ocupando toda caja libre
 * en cada latido hasta que recargue. Sacar este default en cuanto no queden
 * bundles previos a 2026-09-01 en la calle. Ojo: el default del CLIENTE es el
 * contrario (`refreshTenancy()` en `lib/pos/register-tenancy.ts` exige un
 * `acquire` explícito) — ahí el lado seguro es no tomar.
 *
 * EL VETO DEL ADMIN (owner, 2026-09-09)
 * ─────────────────────────────────────
 * Regla del owner: *"si yo libero como administrador una caja, un cajero no
 * puede pasar por encima de mi acción y retomarla"*. Verificado en producción:
 * liberó la caja DOS veces desde el panel y la tablet la retomó sola las dos.
 *
 * Por eso `acquire` dejó de ser booleano. `true` sigue significando "tomala si
 * está libre", pero es una adquisición AUTOMÁTICA y el servidor la RECHAZA
 * cuando la última tenencia de este device sobre esta caja la cerró un admin
 * (`RegisterLeaseService::isAdminRevoked()`). El único valor que levanta el
 * veto es `"operator"`, que manda un solo call-site: el botón "Tomar caja" que
 * toca el cajero en el propio aparato.
 *
 * Que un bundle viejo no pueda mandar `"operator"` NO es un efecto colateral,
 * es la propiedad que se buscaba: la tablet del incidente sigue en la calle con
 * el bundle anterior y el veto tiene que valer contra ella HOY, sin esperar un
 * deploy del PWA ni que alguien recargue la app.
 *
 * EL COSTO, DECIDIDO Y ASUMIDO. Un aparato con el bundle viejo Y vetado queda
 * sin salida propia: su botón "Tomar caja" también manda `true`, así que el
 * cajero puede tocarlo todas las veces que quiera y recibe 409. El remedio es
 * recargar el POS —el bundle nuevo viaja en el mismo deploy que este cambio— y
 * después el botón entra.
 *
 * Se eligió así a sabiendas, y la alternativa se descartó: tratar `true` como
 * `"operator"` durante una ventana de compatibilidad haría que el veto NO
 * valiera contra ningún aparato de la calle, que es exactamente el único lugar
 * donde tiene que valer. Y el desenlace del error es el correcto: en la ventana
 * previa al reload la caja queda LIBRE y disponible para el teléfono del owner
 * —el objetivo—, en vez de tomada por una tablet que nadie autorizó.
 *
 * El 409 que devuelve el veto es el `revoked`/`REGISTER_RELEASED` de siempre —
 * ningún cliente tiene que aprender un código nuevo, y el POS ya lo pinta como
 * "caja libre, tocá para tomarla".
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

// `acquire`: ¿este POST puede TOMAR la caja, y con qué INTENCIÓN?
// Ver "CONFIRMAR ≠ ADQUIRIR" y "EL VETO DEL ADMIN" en el docblock.
//
// El body sale de `$_POST`, no de un `php://input` propio: `bootstrap.php`
// (líneas 90-110) ya normaliza el cuerpo de POST/PUT/DELETE/PATCH a `$_POST`
// para JSON y para form-encoded por igual. Releer el stream acá funcionaba,
// pero solo parseaba JSON — con un cuerpo form-encoded `acquire` se perdía y
// caía en el default `true`, o sea justo el comportamiento viejo que este
// cambio existe para sacar, en silencio.
//
// El parseo es explícito y NO un `(bool)` desnudo. Con form-encoding un
// `acquire=false` llega como el STRING "false", y `(bool) "false"` es `true` en
// PHP: el gate se abría solo, en silencio, justo en el camino que este endpoint
// existe para cerrar. Los strings de negación se tratan como negación.
$rawAcquire = $_POST['acquire'] ?? null;
if (is_string($rawAcquire)) {
    // Normalizar UNA vez, antes de comparar contra nada: si "operator" se
    // compara sin `trim` y los truthy con `trim`, un " operator" cae en NONE
    // por accidente y no por diseño.
    $rawAcquire = strtolower(trim($rawAcquire));
}
if ($rawAcquire === null) {
    // Ausente ⇒ AUTO. Compatibilidad TRANSITORIA con bundles previos a
    // 2026-09-01 (ver docblock). Nunca ⇒ OPERATOR: un cliente que no sabe
    // declarar la intención no puede levantar el veto del admin sin querer.
    $acquireIntent = \Punto\Api\Services\RegisterLeaseService::ACQUIRE_AUTO;
} elseif ($rawAcquire === 'operator') {
    $acquireIntent = \Punto\Api\Services\RegisterLeaseService::ACQUIRE_OPERATOR;
} elseif (is_string($rawAcquire)) {
    $acquireIntent = in_array($rawAcquire, ['1', 'true', 'on', 'yes'], true)
        ? \Punto\Api\Services\RegisterLeaseService::ACQUIRE_AUTO
        : \Punto\Api\Services\RegisterLeaseService::ACQUIRE_NONE;
} else {
    $acquireIntent = $rawAcquire
        ? \Punto\Api\Services\RegisterLeaseService::ACQUIRE_AUTO
        : \Punto\Api\Services\RegisterLeaseService::ACQUIRE_NONE;
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
    $acquireIntent
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
