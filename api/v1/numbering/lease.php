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
// El advisory lock sigue acá pero por un motivo distinto al original: la
// asignación en sí ya es atómica (DocumentNumber::allocateBlock hace
// `UPDATE ... RETURNING`, que toma el row lock de PG). Lo que serializa el
// lock es el par "chequear lease activo → arrendar": sin él, dos requests
// concurrentes de la misma caja no verían el lease del otro y arrendarían dos
// bloques, quemando 100 números de timbrado por nada.
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
