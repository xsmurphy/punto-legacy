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

$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$userId     = $ctx['userId'];

global $db;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- GET → lista de ventas guardadas del usuario/outlet --------------------
if ($method === 'GET') {
    $rows = ncmExecute(
        'SELECT id, data, "createdAt" FROM parked_sale'
        . ' WHERE "companyId"=? AND "outletId"=? AND "userId"=?'
        . ' ORDER BY "createdAt" DESC',
        [$companyId, $outletId, $userId],
        false,
        true
    );
    $result = [];
    while (!$rows->EOF) {
        $result[] = [
            'id'        => $rows->fields['id'],
            'data'      => json_decode($rows->fields['data'], true),
            'createdAt' => $rows->fields['createdAt'],
        ];
        $rows->MoveNext();
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
        'INSERT INTO parked_sale ("companyId", "outletId", "userId", data)'
        . ' VALUES (?, ?, ?, ?::jsonb) RETURNING id, "createdAt"',
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
        'DELETE FROM parked_sale WHERE id=? AND "companyId"=?',
        [$saleId, $companyId]
    );
    if ($db->Affected_Rows() === 0) {
        apiError('Venta guardada no encontrada', 404);
    }
    apiOk(['deleted' => true]);
}

apiError('Método no soportado', 405);
