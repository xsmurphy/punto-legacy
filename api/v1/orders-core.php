<?php
/**
 * /api/v1/orders-core.php — núcleo del módulo de Órdenes (O0,
 * context/24-orders-module-plan.md). Entidad `pos_order`, PROPIA y separada
 * de `transaction` — no confundir con el legacy `api/v1/orders.php`
 * (aceptación de pedidos online sobre transaction type=12, dominio distinto,
 * no se toca).
 *
 *   GET  /v1/orders-core                                    → lista (filtros: outletId, status[], source, fulfillment, from, to, q, spaceSessionId, customerId; includeItems=1 adjunta ítems batched)
 *   GET  /v1/orders-core?id=<uuid>                           → detalle con ítems
 *   POST /v1/orders-core                                     → crea (body: outletId, registerId?, source?, fulfillment?, deliveryAddressId?,
 *                                                              items:[{itemId?,qty,price?,note?,course?}],
 *                                                              customerId?, note?, channelRef?, sendNow?, transactionId?)
 *                                                              transactionId: orden "nace pagada" (flujo Orden en venta) — deja el
 *                                                              rastro de cobro sin pasar por markPaid().
 *   POST /v1/orders-core?id=<uuid>&action=send                → open → sent
 *   POST /v1/orders-core?id=<uuid>&action=status  {status}    → transición a nivel orden (cancel, etc — closed solo si ya está cobrada)
 *   POST /v1/orders-core?id=<uuid>&action=mark-paid {transactionId} → cierra la orden tras cobrarla (llamado por el flujo de cobro, O1)
 *   POST /v1/orders-core?id=<uuid>&action=assign-courier {courierId} → asigna/desasigna repartidor (solo fulfillment='delivery')
 *   POST /v1/orders-core?resource=item-status&id=<orderItemId> {status} → transición de un ítem
 *
 * Auth: panel + pos-app (las órdenes las opera tanto el POS como el panel).
 * Para pos-app, outletId sale del device ctx (apiAuthTenant ya lo resuelve).
 *
 * Auth por module (O2, context/24-orders-module-plan.md): dentro del realm
 * pos-app, `$ctx['module']` distingue qué TIPO de dispositivo está pegando
 * (pos|kds|display|screen — screen no llama a este endpoint). GET (lectura)
 * es libre para cualquier module con outlet scope. Las escrituras se
 * restringen así:
 *   - module 'pos' (o panel, sin module): sin restricción — el cajero opera
 *     el ciclo completo (crear, enviar, cancelar, cobrar).
 *   - module 'kds': solo transiciones de ítem/orden hacia la cocina
 *     (preparing, ready, cancelled) — NO crea, envía ni cobra órdenes.
 *   - module 'display' (pantalla de mozos): SOLO marca `delivered` — de
 *     ítem o de orden. No puede tocar ningún otro estado.
 * Ver assertModuleCanSetStatus() abajo.
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

// El operador (la PERSONA, no la terminal) hace falta para la exclusividad de
// mesa: crear una orden contra un espacio ajeno se rechaza igual que tocarlo
// desde el diálogo de la mesa. Ver Punto\Api\Auth\OperatorContext.
require_once __DIR__ . '/../lib/Auth/OperatorContext.php';
$operator = \Punto\Api\Auth\OperatorContext::resolve($ctx);
$svc      = new \Punto\Api\Orders\OrderCoreService($db, $operator);

// pos-app: TODA operación queda scopeada al outlet del device (un POS/KDS de
// la sucursal A no ve ni opera órdenes de la sucursal B del mismo tenant).
$isPosApp    = ($ctx['realm'] ?? '') === 'pos-app';
$outletScope = $isPosApp ? $outletId : null;
$deviceModule = $isPosApp ? (string) ($ctx['module'] ?? 'pos') : null;

/**
 * Whitelist de status permitidos por module de device, separada por scope
 * (ítem vs orden — son dos máquinas de estado distintas en OrderCoreService,
 * ITEM_TRANSITIONS vs ORDER_TRANSITIONS). `null` (panel o module 'pos') =
 * sin restricción adicional — el service ya valida la transición en sí; esto
 * es una capa extra de "quién puede pedir qué transición".
 *
 * 'display' (pantalla de mozos) queda deliberadamente SIN order-level status
 * (`$scope='order'` → set vacío): permitirle `delivered` a nivel orden abriría
 * un atajo para marcar la orden entera como entregada sin pasar por
 * item-status, saltándose que haya ítems todavía `preparing`/`pending`
 * (ORDER_TRANSITIONS permite 'sent'→'delivered' directo — hallazgo del
 * code-reviewer en la review de este mismo commit). El flujo real de mozos
 * es SIEMPRE item-status por cada ítem `ready`.
 *
 * 'kds' suma 'pending' a nivel ítem (2026-07-27): el KDS ahora puede RETROCEDER
 * un ítem un paso (`ready → preparing → pending`) para deshacer un marcado
 * equivocado y para devolver una comanda a cocina. Sigue sin poder pedir
 * 'delivered' — entregar es de la pantalla de mozos, no de la cocina.
 */
function assertModuleCanSetStatus(?string $module, string $scope, string $status): void
{
    if ($module === null || $module === 'pos') {
        return; // panel y caja POS: sin restricción adicional
    }
    $allowed = [
        'item'  => [
            'kds'     => ['pending', 'preparing', 'ready', 'cancelled'],
            'display' => ['delivered'],
        ],
        'order' => [
            'kds'     => ['in_progress', 'ready', 'cancelled'],
            'display' => [],
        ],
    ];
    $set = $allowed[$scope][$module] ?? [];
    if (!in_array($status, $set, true)) {
        apiError("El dispositivo ({$module}) no puede pedir la transición de {$scope} a '{$status}'", 403);
    }
}

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $order = $svc->find($companyId, (string) $id);
            if ($order === null) apiError('Orden no encontrada', 404);
            if ($outletScope !== null && $order['outletId'] !== $outletScope) {
                apiError('Orden no encontrada', 404);
            }
            apiOk($order);
            break;
        }
        $filters = [
            'outletId'       => $outletScope ?? ($_GET['outletId'] ?? null),
            'status'         => $_GET['status'] ?? null,
            'source'         => $_GET['source'] ?? null,
            'fulfillment'    => $_GET['fulfillment'] ?? null,
            'from'           => $_GET['from'] ?? null,
            'to'             => $_GET['to'] ?? null,
            'q'              => $_GET['q'] ?? null,
            'spaceSessionId' => $_GET['spaceSessionId'] ?? null,
            'customerId'     => $_GET['customerId'] ?? null,
        ];
        $includeItems = ($_GET['includeItems'] ?? '') === '1';
        apiOk(['orders' => $svc->list($companyId, array_filter($filters, static fn ($v) => $v !== null && $v !== ''), $includeItems)]);
        break;

    case 'POST':
        if ($resource === 'item-status') {
            $orderItemId = (string) ($id ?? '');
            $status      = (string) ($_POST['status'] ?? '');
            if ($orderItemId === '' || $status === '') {
                apiError('id (orderItemId) y status son requeridos', 422);
            }
            assertModuleCanSetStatus($deviceModule, 'item', $status);
            try {
                apiOk($svc->updateItemStatus($companyId, $orderItemId, $status, $outletScope));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'send') {
            if ($deviceModule === 'kds' || $deviceModule === 'display') {
                apiError("El dispositivo ({$deviceModule}) no puede enviar órdenes a cocina", 403);
            }
            try {
                apiOk($svc->send($companyId, (string) $id, $outletScope));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'status') {
            $status = (string) ($_POST['status'] ?? '');
            if ($status === '') apiError('status requerido', 422);
            assertModuleCanSetStatus($deviceModule, 'order', $status);
            // Cancelar exige motivo — lo valida el service (ver updateStatus),
            // no este endpoint: la regla vale para TODO cliente, no solo el POS.
            $reason = isset($_POST['reason']) ? (string) $_POST['reason'] : null;
            try {
                apiOk($svc->updateStatus($companyId, (string) $id, $status, $outletScope, $reason));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'mark-paid') {
            if ($deviceModule === 'kds' || $deviceModule === 'display') {
                apiError("El dispositivo ({$deviceModule}) no puede cobrar órdenes", 403);
            }
            $transactionId = (string) ($_POST['transactionId'] ?? '');
            if ($transactionId === '') apiError('transactionId requerido', 422);
            try {
                apiOk($svc->markPaid($companyId, (string) $id, $transactionId, $outletScope));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'assign-courier') {
            if ($deviceModule === 'kds' || $deviceModule === 'display') {
                apiError("El dispositivo ({$deviceModule}) no puede asignar repartidores", 403);
            }
            $courierId = array_key_exists('courierId', $_POST) ? $_POST['courierId'] : null;
            $courierId = ($courierId === '' || $courierId === null) ? null : (string) $courierId;
            try {
                apiOk($svc->assignCourier($companyId, (string) $id, $courierId, $outletScope));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiError('action inválida (esperado: send|status|mark-paid|assign-courier, o resource=item-status)', 422);
        }

        if ($deviceModule === 'kds' || $deviceModule === 'display') {
            apiError("El dispositivo ({$deviceModule}) no puede crear órdenes", 403);
        }

        try {
            $data = $_POST;
            // pos-app: outletId siempre sale del device ctx, nunca del body
            // (evita que un device mal configurado cree órdenes en otro outlet).
            if ($isPosApp) {
                $data['outletId']   = $outletId;
                $data['registerId'] = $ctx['registerId'] ?? null;
            }
            if (empty($data['userId'])) {
                $data['userId'] = $userId;
            }
            $newId = $svc->create($companyId, $data);
            apiOk($svc->find($companyId, $newId), 201);
        } catch (\Punto\Api\Spaces\SpaceOwnershipException $e) {
            // Exclusividad de mesa: es autorización, no datos inválidos. 403
            // para que el front pueda distinguirlo (mismo criterio que
            // space-sessions.php).
            apiError($e->getMessage(), 403);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
