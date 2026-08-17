<?php
declare(strict_types=1);

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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$count    = max(1, min(200, (int) ($body['count'] ?? 100)));
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

// Timbrado vencido → no se arriendan números (owner 2026-08-08). Este es EL
// lugar donde se asigna la numeración fiscal de una venta, así que cortar acá
// corta la facturación entera, incluido el POS offline: sin números
// arrendados no puede emitir. Un documento con timbrado vencido es inválido
// ante la SET; el corte es duro a propósito, no un aviso salteable.
require_once __DIR__ . '/../../lib/services/RegisterService.php';
require_once __DIR__ . '/../../lib/services/RegisterLeaseService.php';
$authError = (new \Punto\Api\Services\RegisterService(
    \Punto\Api\Context\TenantContext::fromAuth($authCtx)
))->invoiceAuthError($regId, $compId);
if ($authError !== null) {
    apiError($authError, 422);
}

// F2 (context/29 §5.3) — exclusividad de caja atada al dispositivo.
//
// TODO camino de acá para abajo (servir un bloque ya arrendado o crear uno
// nuevo) pasa por el advisory lock + el chequeo de tenencia de
// `register_lease`. Antes de F2, la rama "hay lease activo sin consumir" de
// más abajo servía el bloque a CUALQUIER dispositivo que preguntara — ese
// era exactamente el P0 fiscal del plan (§2: dos dispositivos de la misma
// caja reciben el mismo bloque). El fix no es solo "agregar un chequeo": hay
// que MOVER esa rama adentro del lock, porque "leer tenencia → decidir
// servir/crear/rechazar" tiene que ser atómico frente a un segundo request
// concurrente del otro dispositivo — igual que ya lo era la creación del
// bloque de números.
//
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
    'SELECT "registerLeaseId", "deviceId", "expiresAt"
       FROM "register_lease"
      WHERE "registerId" = ? AND "status" = \'active\'
      FOR UPDATE',
    [$regId]
);

if ($activeLease !== false && $activeLease !== 0) {
    $isExpired = strtotime((string) $activeLease['expiresAt']) <= time();
    if ($isExpired) {
        // VENCIMIENTO (§4 del plan): cambió la fecha del outlet sin
        // liberación explícita. Cerrar la tenencia vieja y anular sus
        // números no consumidos ANTES de decidir si el request actual puede
        // tomar la caja — mismo lock, misma transacción.
        \Punto\Api\Services\RegisterLeaseService::close(
            (string) $activeLease['registerLeaseId'],
            'expired',
            'expiry',
            'expired',
        );
        $activeLease = false;
    }
}

if ($activeLease !== false && $activeLease !== 0 && (string) $activeLease['deviceId'] !== $deviceId) {
    // Otro dispositivo tiene la caja tomada y su tenencia sigue vigente — el
    // que llega segundo recibe un rechazo explícito, no la caja (§3 del
    // plan). No se emite ningún número.
    $holderRow = ncmExecute(
        'SELECT deviceName FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid LIMIT 1',
        [(string) $activeLease['deviceId'], $compId]
    );
    $db->FailTrans();
    $db->CompleteTrans();

    apiConflict('Esta caja está tomada por otro dispositivo', [
        'holderDeviceId'   => (string) $activeLease['deviceId'],
        'holderDeviceName' => is_array($holderRow) ? (string) ($holderRow['deviceName'] ?? '') : '',
        'expiresAt'        => (string) $activeLease['expiresAt'],
    ]);
}

if ($activeLease === false || $activeLease === 0) {
    // Nadie tiene la caja tomada (o la tenencia vieja se acaba de vencer y
    // cerrar arriba) — este dispositivo la toma ahora.
    $expiresAt = \Punto\Api\Services\RegisterLeaseService::expiresAt($compId, $regId);
    $newLease  = ncmExecute(
        'INSERT INTO "register_lease"
            ("companyId", "outletId", "registerId", "deviceId", "status", "expiresAt")
         VALUES (?, ?, ?, ?, \'active\', ?)
         RETURNING "registerLeaseId"',
        [$compId, $outletId, $regId, $deviceId, $expiresAt]
    );
    if (!is_array($newLease) || (string) ($newLease['registerLeaseId'] ?? '') === '') {
        // No debería pasar (INSERT ... RETURNING sobre una tabla sin
        // triggers), pero si el driver devuelve false por una falla
        // transitoria de DB, cortar acá — seguir de largo escribiría
        // `numbering_lease."registerLeaseId" = ''` (viola la FK) o, peor,
        // dejaría un bloque de números sin tenencia real detrás.
        $db->FailTrans();
        $db->CompleteTrans();
        apiError('No se pudo tomar la caja, intentá de nuevo', 500);
    }
    $registerLeaseId = (string) $newLease['registerLeaseId'];
} else {
    // Fila activa, mismo deviceId — comportamiento actual, sin cambios.
    $registerLeaseId = (string) $activeLease['registerLeaseId'];
}

// Bloque de números YA arrendado bajo ESTA tenencia — servirlo sin crear uno
// nuevo. Antes de F2 este chequeo no filtraba por tenedor (`registerLeaseId`)
// y por eso podía devolver el bloque de otro dispositivo; ahora solo puede
// devolver el propio, porque arriba ya garantizamos `$registerLeaseId`
// pertenece al `deviceId` que está pidiendo.
$rsActive = ncmExecute(
    'SELECT "invoiceNo", "leaseId", "expiresAt" FROM "numbering_lease"
     WHERE "registerId" = ? AND "companyId" = ? AND "registerLeaseId" = ?
       AND "consumedAt" IS NULL AND "voidedAt" IS NULL AND "expiresAt" > NOW()
     ORDER BY "invoiceNo" ASC',
    [$regId, $compId, $registerLeaseId],
    false,
    true // forceObj — returns recordset
);

if ($rsActive !== false && $rsActive !== 0) {
    $invoiceNos    = [];
    $firstLeaseId  = null;
    $firstExpiresAt = null;

    while (!$rsActive->EOF) {
        $row = $rsActive->fields;
        if ($firstLeaseId === null) {
            $firstLeaseId   = $row['leaseId'];
            $firstExpiresAt = $row['expiresAt'];
        }
        $invoiceNos[] = (int) $row['invoiceNo'];
        $rsActive->MoveNext();
    }

    if (count($invoiceNos) > 0) {
        $db->CompleteTrans();
        apiOk([
            'from'      => $invoiceNos[0],
            'to'        => $invoiceNos[count($invoiceNos) - 1],
            'leaseId'   => $firstLeaseId,
            'expiresAt' => $firstExpiresAt,
        ]);
    }
}

// Asignación del bloque (F2, context/37). Antes acá se calculaba
// `max(MAX(invoiceno)+1, MAX(lease)+1, piso)`: una DERIVACIÓN, no un
// correlativo — borrar la última fila reemitía su número, no había techo de
// timbrado y cada arriendo escaneaba `transaction`. Ahora el próximo número
// sale de `document_sequence`, que es la única fuente de verdad y el mismo
// número que el panel muestra en la caja.
//
// `numbering_lease` NO desaparece: sigue siendo el registro auditable de los
// huecos que el modo offline genera por diseño (un número arrendado y nunca
// consumido ES un hueco, D1 de context/37). Lo que cambia es de dónde saca el
// primer número del bloque. (Sin require_once: `Punto\Api\Documents\*` lo
// resuelve el autoloader PSR-4 de bootstrap.php.)

// Si el timbrado declara techo, el bloque se recorta a lo que queda: pedir 100
// cuando quedan 10 autorizados haría fallar el arriendo entero y la caja se
// quedaría sin poder emitir esos 10, que sí son válidos.
$left = \Punto\Api\Documents\DocumentNumber::remaining(
    'factura',
    \Punto\Api\Documents\DocumentNumber::SCOPE_REGISTER,
    $regId,
    $compId,
);
if ($left !== null) {
    if ($left < 1) {
        $db->FailTrans();
        $db->CompleteTrans();
        apiError(
            'La numeración de esta caja llegó al fin del rango autorizado. Cargá un timbrado nuevo para seguir emitiendo.',
            422
        );
    }
    $count = min($count, $left);
}

try {
    $next = \Punto\Api\Documents\DocumentNumber::allocateBlock(
        'factura',
        \Punto\Api\Documents\DocumentNumber::SCOPE_REGISTER,
        $regId,
        $compId,
        $count,
    );
} catch (\Punto\Api\Documents\RangeExhaustedException $e) {
    // Igual que el timbrado vencido: emitir por encima del rango autorizado da
    // un documento inválido ante la SET. Corte duro, y el rollback devuelve los
    // números al no commitear.
    $db->FailTrans();
    $db->CompleteTrans();
    apiError($e->getMessage(), 422);
}

$expiresAtDb   = date('Y-m-d H:i:s', strtotime('+24 hours'));
$expiresAtJson = date('c', strtotime('+24 hours'));

// Generate first leaseId up-front
$uuidRow      = ncmExecute("SELECT gen_random_uuid() AS id", false);
$firstLeaseId = $uuidRow['id'];

for ($i = $next; $i < $next + $count; $i++) {
    if ($i === $next) {
        $lid = $firstLeaseId;
    } else {
        $lidRow = ncmExecute("SELECT gen_random_uuid() AS id", false);
        $lid    = $lidRow['id'];
    }

    ncmExecute(
        'INSERT INTO "numbering_lease" ("leaseId","companyId","outletId","registerId","invoiceNo","expiresAt","registerLeaseId") VALUES (?,?,?,?,?,?,?)',
        [$lid, $compId, $authCtx['outletId'], $regId, $i, $expiresAtDb, $registerLeaseId]
    );
}

$db->CompleteTrans();

apiOk([
    'from'      => $next,
    'to'        => $next + $count - 1,
    'leaseId'   => $firstLeaseId,
    'expiresAt' => $expiresAtJson,
]);
