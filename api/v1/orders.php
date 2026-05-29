<?php
/**
 * /api/v1/orders.php — aceptación de órdenes (Slice 7).
 *
 *   GET ?resource=customerHasOrders&customerId=<id> → ¿el cliente tiene órdenes abiertas?
 *   PUT ?id=<txId>&resource=accept              → acepta orden (status 2) + notifica cliente
 *   PUT ?id=<txId>&resource=outlet { outletId }  → mueve la orden a otro outlet
 *   PUT ?id=<txId>&resource=user   { userId }    → asigna usuario (mozo) + push
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7) — accept/transfer
 * son transiciones de estado → PUT con ?resource=.
 * Side-effects (push, WS, email, SMS) van aquí; el Service es puro de BD.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/OrderService.php';

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$registerId = $ctx['registerId'];

$svc      = new OrderService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- GET: ¿el cliente tiene órdenes abiertas? (customerHasOrders) ----------
if ($method === 'GET' && $resource === 'customerHasOrders') {
    $customerId = trim((string) ($_GET['customerId'] ?? ''));
    if ($customerId === '') {
        apiError('Falta customerId', 422);
    }
    apiOk(['hasOrders' => $svc->customerHasOpenOrders($companyId, $outletId, $customerId)]);
}

if ($method !== 'PUT') {
    apiError('Método no permitido', 405);
}

// --- accept (PUT ?resource=accept) ----------------------------------------
if ($resource === 'accept') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta id', 422);
    }

    $result = $svc->accept($transactionId, $companyId);
    if (!$result['ok']) {
        apiError('No se pudo aceptar la orden', 500);
    }

    // Side-effects best-effort (fallo no revierte el UPDATE).
    try {
        updateLastTimeEdit($companyId, 'order');

        // Push al cliente.
        if ($result['customerId']) {
            sendPush([
                'ids'     => $companyId,
                'message' => 'Su orden fue aceptada',
                'title'   => defined('COMPANY_NAME') ? COMPANY_NAME : 'Punto',
                'where'   => 'ecom',
                'filters' => [['key' => 'customerId', 'value' => $result['customerId']]],
            ]);
        }

        // WS al register (bug corregido: legacy enviaba enc($id) con $id indefinido).
        sendWS([
            'channel' => $outletId . '-register',
            'event'   => 'order',
            'message' => json_encode(['ID' => $transactionId, 'registerID' => $registerId]),
        ]);

        // Email + SMS al cliente.
        if ($result['customerId']) {
            $customer = getCustomerData($result['customerId'], 'uid');
            if ($customer) {
                $senderName = getCustomerName($customer, 'first');
                $url        = '/screens/orderView?s=' . base64_encode(
                    $transactionId . ',' . $companyId
                );
                $invoiceNo  = $result['invoiceNo'] ?? '';

                global $compName, $compLogo;
                $meta = [
                    'subject'  => '[' . $compName . '] Confirmación de pedido',
                    'to'       => $customer['email'],
                    'fromName' => $compName,
                    'data'     => [
                        'message'     => 'Hola ' . $senderName . ', <p>Su pedido <b>#' . $invoiceNo . '</b> fue confirmado!'
                            . '<br> Puede ver el estado de su pedido en ' . makeEmailActionBtn($url, 'Ver pedido') . '</p>',
                        'companyname' => $compName,
                        'companylogo' => $compLogo,
                    ],
                ];
                sendEmails($meta);

                $msg    = '[' . $compName . '] Hola ' . $senderName . ', su pedido #' . $invoiceNo . ' fue confirmado!: \n' . $url;
                $number = iftn($customer['phone'], $customer['phone2']);
                sendSMS($number, $msg);
            }
        }
    } catch (\Throwable $e) {
        error_log('[orders.accept] side-effect falló (ignorado): ' . $e->getMessage());
    }

    apiOk(['accepted' => true]);
}

// --- transfer (PUT ?resource=outlet) --------------------------------------
if ($resource === 'outlet') {
    $transactionId  = trim((string) ($_GET['id'] ?? ''));
    $targetOutletId = trim((string) ($_POST['outletId'] ?? ''));
    if ($transactionId === '' || $targetOutletId === '') {
        apiError('Faltan campos requeridos (id, outletId)', 422);
    }

    $result = $svc->transferToOutlet($transactionId, $targetOutletId, $companyId);
    if (!$result['ok']) {
        $map = [
            'order_not_found'  => ['Orden no encontrada', 404],
            'outlet_not_found' => ['Outlet no encontrado', 404],
            'update_failed'    => ['No se pudo transferir la orden', 500],
        ];
        [$msg, $code] = $map[$result['reason']] ?? ['No se pudo transferir la orden', 500];
        apiError($msg, $code);
    }

    apiOk(['transferred' => true]);
}

// --- assignUser (PUT ?resource=user) --------------------------------------
if ($resource === 'user') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    $assignUserId  = trim((string) ($_POST['userId'] ?? ''));
    if ($transactionId === '' || $assignUserId === '') {
        apiError('Faltan campos requeridos (id, userId)', 422);
    }

    $result = $svc->assignUser($transactionId, $companyId, $assignUserId);
    if (!$result['ok']) {
        apiError('No se pudo asignar el usuario a la orden', 500);
    }

    // Push best-effort al usuario asignado.
    try {
        updateLastTimeEdit($companyId, 'order');
        sendPush([
            'ids'     => $companyId,
            'message' => 'La orden # ' . ($result['invoiceNo'] ?? '') . ' le fue asignada',
            'title'   => defined('COMPANY_NAME') ? COMPANY_NAME : 'Punto',
            'where'   => 'caja',
            'filters' => [
                ['key' => 'userId',     'value' => $assignUserId],
                ['key' => 'isResource', 'value' => 'true'],
            ],
        ]);
    } catch (\Throwable $e) {
        error_log('[orders.assignUser] push falló (ignorado): ' . $e->getMessage());
    }

    apiOk(['assigned' => true]);
}

apiError('Operación no reconocida', 400);
