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

// Mapa de action.php → op en /api/v1/transactions.php
$opMap = [
    'deleteTransaction'   => 'delete',
    'deleteInPrintServer' => 'deletePrintJob',
    'rejectOrder'         => 'reject',
    'deleteItemHistory'   => 'recordItemDeletion',
];

if (!isset($opMap[$action])) {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$op = $opMap[$action];

// Construir payload según operación
$payload = ['op' => $op];

switch ($op) {
    case 'delete':
    case 'deletePrintJob':
        $payload['transactionId'] = (string) ($get['id'] ?? '');
        break;

    case 'reject':
        $payload['transactionId'] = (string) ($get['id'] ?? '');
        if (!empty($get['motive'])) {
            $payload['motive'] = (string) $get['motive'];
        }
        break;

    case 'recordItemDeletion':
        $payload['itemId']  = (string) ($get['id'] ?? '');
        $payload['motive']  = (string) ($get['motive'] ?? '');
        break;
}

$res = bffApiPost('v1/transactions.php', $payload, '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['success' => 'true']);
