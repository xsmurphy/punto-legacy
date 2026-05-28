<?php
/**
 * /api/v1/orders.php — aceptación de órdenes (Slice 7).
 *
 *   POST op=accept    { transactionId }              → acepta orden (status 2) + notifica cliente
 *   POST op=transfer  { transactionId, outletId }    → mueve la orden a otro outlet
 *
 * NOTA — op=assignUser (setUserToOrder) se difirió: escribe transactionDetails, que
 * en PG vive en `meta` (jsonb). Va al slice dedicado de meta-JSONB. Ver OrderService.
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }.
 * Side-effects (push, WS, email, SMS) van aquí; el Service es puro de BD.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/OrderService.php';

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$registerId = $ctx['registerId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc = new OrderService();
$op  = (string) ($_POST['op'] ?? '');

// --- accept ---------------------------------------------------------------
if ($op === 'accept') {
    $transactionId = trim((string) ($_POST['transactionId'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta transactionId', 422);
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

// --- transfer (transferOrderToOutlet) -------------------------------------
if ($op === 'transfer') {
    $transactionId  = trim((string) ($_POST['transactionId'] ?? ''));
    $targetOutletId = trim((string) ($_POST['outletId'] ?? ''));
    if ($transactionId === '' || $targetOutletId === '') {
        apiError('Faltan campos requeridos (transactionId, outletId)', 422);
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

apiError('Operación no reconocida', 400);
