<?php
/**
 * /api/v1/orders-core.php — núcleo del módulo de Órdenes (O0,
 * context/24-orders-module-plan.md). Entidad `pos_order`, PROPIA y separada
 * de `transaction` — no confundir con el legacy `api/v1/orders.php`
 * (aceptación de pedidos online sobre transaction type=12, dominio distinto,
 * no se toca).
 *
 *   GET  /v1/orders-core                                    → lista (filtros: outletId, status[], source, from, to, q)
 *   GET  /v1/orders-core?id=<uuid>                           → detalle con ítems
 *   POST /v1/orders-core                                     → crea (body: outletId, registerId?, source?,
 *                                                              items:[{itemId?,qty,price?,note?,course?}],
 *                                                              customerId?, note?, channelRef?, sendNow?)
 *   POST /v1/orders-core?id=<uuid>&action=send                → open → sent
 *   POST /v1/orders-core?id=<uuid>&action=status  {status}    → transición a nivel orden (cancel, etc — closed prohibido)
 *   POST /v1/orders-core?id=<uuid>&action=mark-paid {transactionId} → cierra la orden tras cobrarla (llamado por el flujo de cobro, O1)
 *   POST /v1/orders-core?resource=item-status&id=<orderItemId> {status} → transición de un ítem
 *
 * Auth: panel + pos-app (las órdenes las opera tanto el POS como el panel).
 * Para pos-app, outletId sale del device ctx (apiAuthTenant ya lo resuelve).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$outletId  = $ctx['outletId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action'] ?? null;

global $db;
$svc = new \Punto\Api\Orders\OrderCoreService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $order = $svc->find($companyId, (string) $id);
            if ($order === null) apiError('Orden no encontrada', 404);
            apiOk($order);
            break;
        }
        $filters = [
            'outletId' => $_GET['outletId'] ?? null,
            'status'   => $_GET['status'] ?? null,
            'source'   => $_GET['source'] ?? null,
            'from'     => $_GET['from'] ?? null,
            'to'       => $_GET['to'] ?? null,
            'q'        => $_GET['q'] ?? null,
        ];
        apiOk(['orders' => $svc->list($companyId, array_filter($filters, static fn ($v) => $v !== null && $v !== ''))]);
        break;

    case 'POST':
        if ($resource === 'item-status') {
            $orderItemId = (string) ($id ?? '');
            $status      = (string) ($_POST['status'] ?? '');
            if ($orderItemId === '' || $status === '') {
                apiError('id (orderItemId) y status son requeridos', 422);
            }
            try {
                apiOk($svc->updateItemStatus($companyId, $orderItemId, $status));
            } catch (\InvalidArgumentException $e) {
                apiError($e->getMessage(), 422);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'send') {
            try {
                apiOk($svc->send($companyId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'status') {
            $status = (string) ($_POST['status'] ?? '');
            if ($status === '') apiError('status requerido', 422);
            try {
                apiOk($svc->updateStatus($companyId, (string) $id, $status));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'mark-paid') {
            $transactionId = (string) ($_POST['transactionId'] ?? '');
            if ($transactionId === '') apiError('transactionId requerido', 422);
            try {
                apiOk($svc->markPaid($companyId, (string) $id, $transactionId));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiError('action inválida (esperado: send|status|mark-paid, o resource=item-status)', 422);
        }

        try {
            $data = $_POST;
            // pos-app: outletId siempre sale del device ctx, nunca del body
            // (evita que un device mal configurado cree órdenes en otro outlet).
            if (($ctx['realm'] ?? '') === 'pos-app') {
                $data['outletId']   = $outletId;
                $data['registerId'] = $ctx['registerId'] ?? null;
            }
            if (empty($data['userId'])) {
                $data['userId'] = $userId;
            }
            $newId = $svc->create($companyId, $data);
            apiOk($svc->find($companyId, $newId), 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
