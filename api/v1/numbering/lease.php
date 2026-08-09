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

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$count  = max(1, min(200, (int) ($body['count'] ?? 100)));
$regId  = $authCtx['registerId'];
$compId = $authCtx['companyId'];

// Timbrado vencido → no se arriendan números (owner 2026-08-08). Este es EL
// lugar donde se asigna la numeración fiscal de una venta, así que cortar acá
// corta la facturación entera, incluido el POS offline: sin números
// arrendados no puede emitir. Un documento con timbrado vencido es inválido
// ante la SET; el corte es duro a propósito, no un aviso salteable.
require_once __DIR__ . '/../../lib/services/RegisterService.php';
$authError = (new \Punto\Api\Services\RegisterService(
    \Punto\Api\Context\TenantContext::fromAuth($authCtx)
))->invoiceAuthError($regId, $compId);
if ($authError !== null) {
    apiError($authError, 422);
}

// Check for existing active leases
$rsActive = ncmExecute(
    'SELECT "invoiceNo", "leaseId", "expiresAt" FROM "numbering_lease" WHERE "registerId" = ? AND "companyId" = ? AND "consumedAt" IS NULL AND "expiresAt" > NOW() ORDER BY "invoiceNo" ASC',
    [$regId, $compId],
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
        apiOk([
            'from'      => $invoiceNos[0],
            'to'        => $invoiceNos[count($invoiceNos) - 1],
            'leaseId'   => $firstLeaseId,
            'expiresAt' => $firstExpiresAt,
        ]);
    }
}

// No active lease — emitir un bloque nuevo de números.
//
// P0 race condition (code-review S4): sin serialización, dos requests
// concurrentes para la misma caja leen el mismo MAX(invoiceNo) y ambos
// intentan INSERT con los mismos valores → el segundo falla en el UNIQUE
// constraint con un error 500 no manejado. Fix: advisory lock de sesión
// (pg_advisory_xact_lock) serializa el bloque MAX→INSERT. El lock se
// libera al commitear la transacción. Si el segundo request llega mientras
// el primero mantiene el lock, espera. Al obtenerlo, el check de lease
// activo al inicio del bloque (re-chequeado antes de emitir) lo detectará
// y retornará el lease del primero sin emitir uno nuevo.
//
// hashtext() produce un int4 estable desde un string arbitrario — lo usamos
// para derivar el lock key desde el UUID de la caja sin truncarlo a int.
global $db;
$db->StartTrans();

// Dentro de la transacción: adquirir lock exclusivo de sesión por caja.
// Dos requests para la misma caja esperan acá; distintas cajas no bloquean
// entre sí (el lock key es por registerId).
ncmExecute(
    "SELECT pg_advisory_xact_lock(hashtext(?))",
    [$regId]
);

// Re-chequear lease activo DENTRO del lock — puede que el primer request
// lo haya emitido mientras esperábamos. Si ya existe, retornar ese.
$rsRecheck = ncmExecute(
    'SELECT "invoiceNo", "leaseId", "expiresAt" FROM "numbering_lease"
     WHERE "registerId" = ? AND "companyId" = ? AND "consumedAt" IS NULL AND "expiresAt" > NOW()
     ORDER BY "invoiceNo" ASC',
    [$regId, $compId],
    false,
    true
);

if ($rsRecheck !== false && $rsRecheck !== 0) {
    $recheckNos     = [];
    $recheckLeaseId = null;
    $recheckExpires = null;
    while (!$rsRecheck->EOF) {
        $row = $rsRecheck->fields;
        if ($recheckLeaseId === null) {
            $recheckLeaseId = $row['leaseId'];
            $recheckExpires = $row['expiresAt'];
        }
        $recheckNos[] = (int) $row['invoiceNo'];
        $rsRecheck->MoveNext();
    }
    if (count($recheckNos) > 0) {
        $db->CompleteTrans();
        apiOk([
            'from'      => $recheckNos[0],
            'to'        => $recheckNos[count($recheckNos) - 1],
            'leaseId'   => $recheckLeaseId,
            'expiresAt' => $recheckExpires,
        ]);
    }
}

// Tabla legacy `transaction`: columnas creadas sin quotes en el DDL → PG las
// almacena en lowercase. NO usar quoted identifiers acá (causaría error
// "column invoiceNo does not exist"). Tablas nuevas (camelCase quoted §44)
// como numbering_lease sí usan quotes.
$maxRow  = ncmExecute(
    'SELECT MAX(invoiceno) AS maxno FROM transaction WHERE registerid = ? AND companyid = ?',
    [$regId, $compId]
);
$maxno   = ($maxRow && isset($maxRow['maxno']) && $maxRow['maxno'] !== null) ? (int) $maxRow['maxno'] : 0;

// También considerar el máximo ya en lease (por si hay leases consumidos
// recientes que aún no están en transaction — evita gaps dobles).
$maxLeaseRow = ncmExecute(
    'SELECT MAX("invoiceNo") AS maxno FROM "numbering_lease" WHERE "registerId" = ? AND "companyId" = ?',
    [$regId, $compId]
);
$maxLease = ($maxLeaseRow && isset($maxLeaseRow['maxno']) && $maxLeaseRow['maxno'] !== null)
    ? (int) $maxLeaseRow['maxno']
    : 0;

// Piso configurado para la caja (RegisterAdminService::update): un timbrado
// puede arrancar en 1234 y no en 1. Se toma el MÁXIMO entre el piso y lo ya
// emitido +1 — nunca al revés: un piso viejo o mal cargado no puede reemitir
// un número que ya salió (invariante del owner: no duplicar documentos).
$regRow  = ncmExecute(
    'SELECT data FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
    [$regId, $compId]
);
// OJO: ncmExecute aplana `data` al nivel de la fila y hace unset de la columna
// (Query::flattenJsonb) — leer `$regRow['data']` daría null. La clave del
// JSONB se lee directo de la fila aplanada.
$numbering = is_array($regRow) || $regRow instanceof \ArrayAccess
    ? ($regRow['registerNumbering'] ?? null)
    : null;
if (is_string($numbering)) {
    $numbering = json_decode($numbering, true);
}
$floor = (is_array($numbering) && isset($numbering['factura'])) ? (int) $numbering['factura'] : 0;

$next = max($maxno + 1, $maxLease + 1, $floor);

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
        'INSERT INTO "numbering_lease" ("leaseId","companyId","outletId","registerId","invoiceNo","expiresAt") VALUES (?,?,?,?,?,?)',
        [$lid, $compId, $authCtx['outletId'], $regId, $i, $expiresAtDb]
    );
}

$db->CompleteTrans();

apiOk([
    'from'      => $next,
    'to'        => $next + $count - 1,
    'leaseId'   => $firstLeaseId,
    'expiresAt' => $expiresAtJson,
]);
