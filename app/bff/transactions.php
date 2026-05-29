<?php
/**
 * /bff/transactions.php — BFF de transacciones/órdenes del POS (Slice 6).
 *
 * Reemplaza el dispatch de los siguientes handlers de action.php:
 *   deleteTransaction   (L1188)  — eliminar una transacción
 *   deleteInPrintServer (L155)   — eliminar de la cola de impresión
 *   rejectOrder         (L1260)  — rechazar una orden
 *   deleteItemHistory   (L605)   — registrar eliminación de ítem
 *
 * NO toca BD. Decodifica el sobre `?l=`, mapea a la op correspondiente, reenvía a
 * /api/v1/transactions.php (con cookie _jwt) y devuelve el shape legacy { success:"true" }.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');
$id     = (string) ($get['id'] ?? '');
$ep     = 'v1/transactions.php';

// load.php → quotesList / savedList (Slice 28): lista paginada, retorna objeto plano.
if ($action === 'quotesList' || $action === 'savedList') {
    $listType = ($action === 'quotesList') ? 'quotes' : 'saved';
    $res = bffApiGet($ep, [
        'resource'   => 'list',
        'listType'   => $listType,
        'customerId' => (string) ($get['customerId'] ?? ''),
        'date'       => (string) ($get['date'] ?? ''),
        'limit'      => (string) ($get['limit'] ?? '30'),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson($res['data']);
}

// load.php → GET single (lectura, sin efecto secundario)
if ($action === 'singleTransaction') {
    $res = bffApiGet($ep, ['id' => $id, 'resource' => 'single'], '_jwt');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['data' => $res['data']]);
}

// action.php → verbo REST (§22.7)
switch ($action) {
    case 'deleteTransaction':   // DELETE ?id=
        $res = bffApiDelete($ep, ['id' => $id], [], '_jwt');
        break;
    case 'deleteInPrintServer': // DELETE ?id=&resource=printjob
        $res = bffApiDelete($ep, ['id' => $id, 'resource' => 'printjob'], [], '_jwt');
        break;
    case 'rejectOrder':         // PUT ?id=&resource=reject { motive }
        $body = !empty($get['motive']) ? ['motive' => (string) $get['motive']] : [];
        $res  = bffApiPut($ep, ['id' => $id, 'resource' => 'reject'], $body, '_jwt');
        break;
    case 'deleteItemHistory':   // POST ?resource=itemDeletion { itemId, motive }
        $res = bffApiPost($ep . '?resource=itemDeletion', [
            'itemId' => $id,
            'motive' => (string) ($get['motive'] ?? ''),
        ], '_jwt');
        break;
    default:
        bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['success' => 'true']);
