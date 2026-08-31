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
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\OrderService;

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$registerId = $ctx['registerId'];

$svc      = new OrderService(TenantContext::fromAuth($ctx));
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

// --- GET ?resource=tableClose: ítems agrupados para cierre de espacio/orden -
if ($method === 'GET' && $resource === 'tableClose') {
    $t    = trim((string) ($_GET['t'] ?? ''));
    $kind = trim((string) ($_GET['kind'] ?? 'table'));
    if ($t === '') apiError('Falta t', 422);
    apiOk($svc->getTableClose($t, $kind, $outletId, $companyId));
}

// --- GET ?resource=tableDetail: vista detalle de ítems para modal ---------
if ($method === 'GET' && $resource === 'tableDetail') {
    $t    = trim((string) ($_GET['t'] ?? ''));
    $kind = trim((string) ($_GET['kind'] ?? 'table'));
    if ($t === '') apiError('Falta t', 422);
    apiOk($svc->getTableDetail($t, $kind, $outletId, $companyId));
}

// --- GET ?resource=list: lista paginada de órdenes (buildList) ------------
if ($method === 'GET' && $resource === 'list') {
    $encCid = trim((string) ($_GET['customerId'] ?? '')) ?: null;
    $date   = trim((string) ($_GET['date'] ?? '')) ?: null;
    $limit  = max(1, (int) ($_GET['limit'] ?? 30));
    apiOk($svc->getList($outletId, $companyId, $encCid, $date, $limit));
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

// --- GET ?resource=panelList: lista del panel de órdenes (Slice 40 — strangler ordersPanelAPI) ---
// Parámetros opcionales: ID (filtrar a una sola orden), date (end date inclusive).
// El compare-timestamp del legacy (lastChk) se ignora — el endpoint upstream estaba roto en PG.
if ($method === 'GET' && $resource === 'panelList') {
    $orderId = trim((string) ($_GET['ID']   ?? '')) ?: null;
    $date    = trim((string) ($_GET['date'] ?? '')) ?: null;

    $orders = $svc->getPanelList($companyId, $outletId, $orderId, $date);
    apiOk(['orders' => $orders]);
}

// --- GET ?resource=userLocation&id=<userId>: ubicación del repartidor + próxima delivery ---
if ($method === 'GET' && $resource === 'userLocation') {
    $userId = trim((string) dec($_GET['id'] ?? ''));
    if ($userId === '') {
        apiError('Falta id', 422);
    }

    // Lookup del contact del staff (type=0, tracking activado). Tenant-scoped.
    $contact = ncmExecute(
        'SELECT contactId, contactLatLng FROM contact
          WHERE type = 0 AND contactTrackLocation = 1
            AND contactId = ? AND companyId = ? LIMIT 1',
        [$userId, $companyId]
    );
    if (!$contact) {
        apiError('not found', 404);
    }

    $out    = [];
    $latLng = (string) ($contact['contactLatLng'] ?? '');
    if ($latLng !== '' && str_contains($latLng, ',')) {
        [$lat, $lng] = array_map('floatval', explode(',', $latLng, 2));
        $out['lat'] = $lat;
        $out['lng'] = $lng;

        $delivery = $svc->getNextDeliveryForUser($userId, $companyId, $outletId);
        if ($delivery !== null) {
            $out['orderData'] = $delivery;
        }
    }

    apiOk($out);
}

apiError('Operación no reconocida', 400);
