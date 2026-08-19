<?php
/**
 * /api/v1/parked-sales.php — ventas en curso (parked sales).
 *
 *   GET    → lista del usuario activo en el outlet
 *   POST   { data: { cart, customer, notes } } → guarda venta
 *   DELETE ?id=<uuid> → elimina la venta guardada
 *
 * Auth: JWT de tenant (panel o pos-app). Envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

require_once dirname(__DIR__) . '/lib/Auth/apiAuthPosContext.php';
$ctx        = apiAuthPosContext();
if (($ctx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$userId     = $ctx['userId'];

global $db;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- GET → lista de ventas guardadas del usuario/outlet --------------------
if ($method === 'GET') {
    // Lectura RAW con $db->Execute, NO ncmExecute: el wrapper aplana las
    // columnas JSONB (`data`/`meta`/`config`) — mergea su contenido al nivel de
    // la fila y BORRA la columna (Query::flattenJsonb). Con ncmExecute,
    // `fields['data']` volvía null, el json_decode daba null y el POS mostraba
    // toda venta guardada como "0 ítems / Gs. 0", sin título ni cliente, aunque
    // en la BD estuviera completa (bug 2026-07-30). Mismo criterio ya
    // documentado en functions.php (getLastUpdates) y OrderItemsService.
    //
    // Alias en minúsculas + ::text: el recordset crudo devuelve las keys como
    // las da PG (lowercase) y sin CIA de por medio, así que se nombran acá para
    // no depender del casing.
    $rows = $db->Execute(
        'SELECT id::text AS id, data::text AS payload, createdat::text AS createdat'
        . ' FROM parked_sale'
        . ' WHERE companyid=? AND outletid=? AND userid=?'
        . ' ORDER BY createdat DESC',
        [$companyId, $outletId, $userId]
    );
    $result = [];
    if ($rows !== false) {
        while (!$rows->EOF) {
            $decoded = json_decode((string) ($rows->fields['payload'] ?? ''), true);
            $result[] = [
                'id'        => $rows->fields['id'],
                'data'      => is_array($decoded) ? $decoded : null,
                'createdAt' => $rows->fields['createdat'],
            ];
            $rows->MoveNext();
        }
    }
    apiOk($result);
}

// --- POST → guardar venta --------------------------------------------------
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!isset($body['data']) || !is_array($body['data'])) {
        apiError('Falta data', 422);
    }
    $dataJson = json_encode($body['data']);
    $id = ncmExecute(
        'INSERT INTO parked_sale (companyid, outletid, userid, data)'
        . ' VALUES (?, ?, ?, ?::jsonb) RETURNING id, createdat',
        [$companyId, $outletId, $userId, $dataJson],
        false,
        true
    );
    if (!$id || $id->EOF) {
        apiError('No se pudo guardar la venta', 500);
    }
    apiOk([
        'id'        => $id->fields['id'],
        'data'      => $body['data'],
        'createdAt' => $id->fields['createdAt'],
    ]);
}

// --- DELETE → eliminar venta guardada ------------------------------------
if ($method === 'DELETE') {
    $saleId = trim((string) ($_GET['id'] ?? ''));
    if ($saleId === '') {
        apiError('Falta id', 422);
    }
    ncmExecute(
        'DELETE FROM parked_sale WHERE id=? AND companyid=?',
        [$saleId, $companyId]
    );
    if ($db->Affected_Rows() === 0) {
        apiError('Venta guardada no encontrada', 404);
    }
    apiOk(['deleted' => true]);
}

apiError('Método no soportado', 405);
