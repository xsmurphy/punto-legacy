<?php
declare(strict_types=1);

namespace Punto\Api\Orders;

/**
 * OrderCoreService — núcleo del módulo de Órdenes (O0, context/24-orders-module-plan.md).
 *
 * La orden (`pos_order`) es entidad PROPIA, separada de `transaction`. El
 * ciclo operativo (open→sent→in_progress→ready→delivered) vive acá;
 * `transaction` se crea SOLO al cobrar (SaleService, fuera de este
 * service) — markPaid() solo deja el rastro (saletransactionid) una vez
 * que YA se cobró.
 *
 * Patrón de service: mismo que ProductionService.php — $companyId
 * explícito en cada método público, ncmExecute() para lecturas simples,
 * StartTrans/HasFailedTrans/CompleteTrans para escrituras con invariantes
 * (create, updateItemStatus, updateStatus, markPaid).
 */
final class OrderCoreService
{
    /**
     * Transiciones válidas de pos_order_item.status.
     *
     * El ciclo es BIDIRECCIONAL hasta 'ready' (KDS recall + deshacer, 2026-07-27):
     *   - `ready → preparing` es "devolver a preparación": una comanda que ya
     *     salió del board vuelve a él porque salió mal, faltó algo o despacho
     *     la rechazó.
     *   - `preparing → pending` es "deshacer": el error más común de quien
     *     opera es marcar la línea equivocada, y hasta ahora no había vuelta
     *     atrás.
     * `delivered` y `cancelled` siguen siendo TERMINALES: lo que ya salió al
     * cliente o se anuló no se re-abre desde una pantalla de preparación (eso
     * es una operación de caja, con su propia autorización).
     *
     * El re-trabajo NO se esconde: cada retroceso queda en `pos_order_event`
     * como la transición que es (recordEvent), que es justamente lo que el
     * timeline existe para poder medir (context/27 PARTE C).
     */
    private const ITEM_TRANSITIONS = [
        'pending'   => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'pending', 'cancelled'],
        'ready'     => ['delivered', 'preparing'],
        'delivered' => [],
        'cancelled' => [],
    ];

    /** Rank para agregar el estado de la orden a partir de sus ítems activos. */
    private const ITEM_STATUS_RANK = [
        'pending'   => 0,
        'preparing' => 1,
        'ready'     => 2,
        'delivered' => 3,
    ];

    /**
     * Transiciones válidas de pos_order.status vía updateStatus() (closed es SOLO markPaid).
     *
     * Los retrocesos (`ready → in_progress`, `→ sent`) acompañan al retroceso de
     * ítems: son los estados a los que llega una orden devuelta a preparación o
     * con su marcado deshecho. En el flujo normal los deriva
     * recomputeOrderStatus(), que persiste con un UPDATE propio y NO consulta
     * este mapa — razón de más para declararlos acá: un estado alcanzable por un
     * camino y prohibido por el otro es la máquina mintiendo sobre sí misma, y
     * el próximo que lea esta constante para razonar va a razonar mal.
     */
    private const ORDER_TRANSITIONS = [
        'open'             => ['sent', 'cancelled'],
        'sent'             => ['in_progress', 'ready', 'delivered', 'cancelled'],
        'in_progress'      => ['sent', 'ready', 'delivered', 'cancelled'],
        // out_for_delivery ("En camino", F-D-1, context/27 §B.2) SOLO se
        // alcanza desde ready — no se sale en camino algo que la cocina no
        // terminó. El guard de fulfillment='delivery' vive en updateStatus().
        'ready'            => ['sent', 'in_progress', 'delivered', 'out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['delivered', 'cancelled'],
        'delivered'        => ['cancelled'],
        'closed'           => [],
        'cancelled'        => [],
    ];

    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Órdenes
    // ------------------------------------------------------------------

    /**
     * Crea una orden con sus ítems. Resuelve stationId por ítem (categorías
     * → order_station) y el correlativo ordernumber (por outlet+día local).
     * Atómico — TX propia.
     *
     * @param array{outletId:string, registerId?:string, source?:string,
     *              spaceSessionId?:string, fulfillment?:string,
     *              deliveryAddressId?:string,
     *              items:list<array{itemId?:string, qty:float, price?:float,
     *              note?:string, course?:int}>, customerId?:string,
     *              userId?:string, note?:string, channelRef?:string,
     *              sendNow?:bool} $data
     * @return string orderId
     */
    public function create(string $companyId, array $data): string
    {
        global $db;

        $outletId       = (string) ($data['outletId'] ?? '');
        $registerId     = !empty($data['registerId']) ? (string) $data['registerId'] : null;
        $spaceSessionId = !empty($data['spaceSessionId']) ? (string) $data['spaceSessionId'] : null;
        // Una orden asociada a un espacio siempre tiene source='table' —
        // manda sobre el source recibido (context/15 F2, decisión del
        // owner: "espacio seleccionado → Ordenar genera una orden asociada al
        // espacio"). El valor 'table' del enum `source` se conserva —
        // identifica el origen "asociado a un espacio", no se renombra.
        $source         = $spaceSessionId !== null ? 'table' : (string) ($data['source'] ?? 'counter');
        $items          = (array) ($data['items'] ?? []);
        $customerId     = !empty($data['customerId']) ? (string) $data['customerId'] : null;
        $userId         = !empty($data['userId']) ? (string) $data['userId'] : null;
        $note           = isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null;
        $channelRef     = isset($data['channelRef']) && $data['channelRef'] !== '' ? (string) $data['channelRef'] : null;
        $sendNow        = !empty($data['sendNow']);

        // `fulfillment` es ORTOGONAL a `source` (context/27 §B.1) — ver mig 94.
        // Una orden de espacio SIEMPRE es dine_in (misma lógica que fuerza
        // source='table' arriba): una mesa no pide delivery/takeaway.
        $fulfillment = $spaceSessionId !== null
            ? 'dine_in'
            : (string) ($data['fulfillment'] ?? 'dine_in');
        if (!in_array($fulfillment, ['dine_in', 'takeaway', 'delivery'], true)) {
            throw new \InvalidArgumentException("fulfillment inválido (esperado 'dine_in'|'takeaway'|'delivery')");
        }

        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }
        if (!in_array($source, ['counter', 'table', 'ecommerce', 'schedule'], true)) {
            throw new \InvalidArgumentException("source inválido (esperado 'counter'|'table'|'ecommerce'|'schedule')");
        }
        if ($items === []) {
            throw new \InvalidArgumentException('la orden requiere al menos un ítem');
        }

        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        // Resolución + snapshot del destino de entrega (context/27 §B.3/§D.3).
        // Se resuelve ANTES de la TX — falla rápido (400) sin tomar ningún
        // lock si la dirección ni siquiera existe/pertenece al cliente+tenant.
        // Fuera de 'delivery' las 5 columnas quedan NULL a propósito, aunque
        // el payload traiga algo (ej. un fulfillment cambiado a último
        // momento) — no tiene sentido guardar un destino de entrega para una
        // orden que no se entrega.
        $deliveryAddressId = null;
        $deliveryAddress    = null;
        $deliveryReference  = null;
        $deliveryLat        = null;
        $deliveryLng        = null;
        if ($fulfillment === 'delivery') {
            if ($customerId === null) {
                throw new \InvalidArgumentException('delivery requiere customerId');
            }
            $addressId = !empty($data['deliveryAddressId']) ? (string) $data['deliveryAddressId'] : null;
            if ($addressId === null) {
                throw new \InvalidArgumentException('delivery requiere deliveryAddressId');
            }
            $address = ncmExecute(
                'SELECT customerAddressId, customerAddressText, reference, customerAddressLat, customerAddressLng
                   FROM customerAddress
                  WHERE customerAddressId = ? AND companyId = ? AND customerId = ? AND status = 1
                  LIMIT 1',
                [$addressId, $companyId, $customerId]
            );
            if (!$address) {
                throw new \InvalidArgumentException('deliveryAddressId inválido para este cliente');
            }
            $deliveryAddressId = (string) $address['customeraddressid'];
            $deliveryAddress   = (string) ($address['customeraddresstext'] ?? '');
            $deliveryReference = $address['reference'] ?? null;
            // lat/lng pueden quedar NULL — dirección sin pin es un caso
            // válido (§D.5, gap de links cortos de Maps sin coords).
            $deliveryLat = isset($address['customeraddresslat']) ? (float) $address['customeraddresslat'] : null;
            $deliveryLng = isset($address['customeraddresslng']) ? (float) $address['customeraddresslng'] : null;
        }

        if ($spaceSessionId !== null) {
            // Pre-check fuera de la TX — falla rápido (400) sin tomar lock si
            // la sesión ni siquiera existe/pertenece al tenant. La validación
            // que importa (status='open') se REPITE con FOR UPDATE dentro de
            // la TX, justo abajo — evita la ventana TOCTOU entre este check y
            // el INSERT (ej. otro request llama request-bill/cancel/close
            // sobre la misma sesión mientras se arma esta orden; hallazgo del
            // code-reviewer).
            $session = ncmExecute(
                'SELECT sessionid, outletid, status FROM space_session WHERE sessionid = ? AND companyid = ? LIMIT 1',
                [$spaceSessionId, $companyId]
            );
            if (!$session) {
                throw new \InvalidArgumentException('spaceSessionId inválido para este tenant');
            }
            if ((string) $session['outletid'] !== $outletId) {
                throw new \InvalidArgumentException('spaceSessionId no pertenece a este outlet');
            }
        }

        $stations = $this->listStationsRaw($companyId, $outletId);

        $db->StartTrans();

        $lockedStatus = null;
        if ($spaceSessionId !== null) {
            // Re-check atómico: FOR UPDATE bloquea la fila hasta el commit de
            // esta TX, así que request-bill/cancel/close (UPDATEs simples sin
            // lock explícito, ver SpaceSessionService) quedan bloqueados
            // hasta que esta orden termine de crearse — o, si ya corrieron
            // antes de este SELECT, el status ya no es 'open' y abortamos acá.
            $lockedSession = $db->Execute(
                "SELECT status FROM space_session WHERE sessionid = ? AND companyid = ? FOR UPDATE",
                [$spaceSessionId, $companyId]
            );
            $lockedStatus = ($lockedSession !== false && !$lockedSession->EOF)
                ? (string) $lockedSession->fields['status']
                : null;
            // `bill_requested` NO bloquea: pedir la cuenta es una señal para
            // la caja, no un cierre — que la mesa sume "un café más" después
            // de pedirla es flujo normal de gastronomía. Se acepta la orden y
            // la sesión VUELVE a 'open' (abajo, tras el INSERT): el pedido de
            // cuenta valía para el total de ese momento y ese total cambió,
            // así que hay que volver a pedirla. Solo closed/cancelled cierran
            // de verdad.
            if (!in_array($lockedStatus, ['open', 'bill_requested'], true)) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \InvalidArgumentException('El espacio no admite nuevas órdenes (status: ' . ($lockedStatus ?? 'desconocido') . ')');
            }
        }

        // Correlativo por (companyid, outletid, día local): advisory lock
        // transaction-scoped evita la carrera entre dos creates concurrentes
        // del mismo outlet en el mismo día (no hay UI en O0 todavía — si O1
        // revela contención real de alto volumen, se resuelve con secuencia
        // dedicada por outlet+día; el lock suave alcanza para esta fase).
        $lockKey = $companyId . ':' . $outletId . ':' . date('Y-m-d');
        $db->Execute('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);

        $numRow = ncmExecute(
            "SELECT COALESCE(MAX(ordernumber), 0) + 1 AS nextnum
               FROM pos_order
              WHERE companyid = ? AND outletid = ? AND created_at::date = CURRENT_DATE",
            [$companyId, $outletId]
        );
        $orderNumber = (int) ($numRow['nextnum'] ?? 1);

        $status = $sendNow ? 'sent' : 'open';

        $rs = $this->db->Execute(
            "INSERT INTO pos_order
                (orderid, companyid, outletid, registerid, source, status, ordernumber,
                 spacesessionid, customerid, userid, note, channelref,
                 fulfillment, deliveryaddressid, deliveryaddress, deliveryreference, deliverylat, deliverylng,
                 sent_at)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($sendNow ? 'now()' : 'NULL') . ")
             RETURNING orderid",
            [
                $companyId, $outletId, $registerId, $source, $status, $orderNumber, $spaceSessionId, $customerId, $userId, $note, $channelRef,
                $fulfillment, $deliveryAddressId, $deliveryAddress, $deliveryReference, $deliveryLat, $deliveryLng,
            ]
        );
        if ($rs === false || $rs->EOF) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la orden');
        }
        $orderId = (string) ($rs->fields['orderid'] ?? '');
        if ($orderId === '') {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la orden');
        }

        // Un solo evento con el status REAL con el que nació la orden (open o
        // sent si sendNow) — no se inventa un 'open' que nunca existió cuando
        // sendNow crea la orden ya 'sent'.
        $this->recordEvent($companyId, $outletId, $orderId, null, null, 'order', null, $status);

        foreach ($items as $item) {
            $itemId = !empty($item['itemId']) ? (string) $item['itemId'] : null;
            $qty    = (float) ($item['qty'] ?? 0);
            if ($qty <= 0) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \InvalidArgumentException('qty de cada ítem debe ser > 0');
            }

            $name  = null;
            $price = isset($item['price']) ? (float) $item['price'] : null;
            $categoryIds = [];

            if ($itemId !== null) {
                $itemRow = ncmExecute(
                    'SELECT itemid, itemname, itemprice FROM item WHERE itemid = ? AND companyid = ? LIMIT 1',
                    [$itemId, $companyId]
                );
                if (!$itemRow) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    throw new \InvalidArgumentException('itemId inválido para este tenant: ' . $itemId);
                }
                $name  = (string) $itemRow['itemname'];
                $price = $price ?? (float) ($itemRow['itemprice'] ?? 0);
                $categoryIds = $this->fetchItemCategoryIds($itemId);
            }
            if ($name === null) {
                $name = isset($item['name']) ? (string) $item['name'] : 'Ítem';
            }

            $stationId = $this->resolveStationId($stations, $categoryIds);
            $itemNote  = isset($item['note']) && $item['note'] !== '' ? (string) $item['note'] : null;
            $course    = isset($item['course']) ? (int) $item['course'] : 1;

            $itemOk = $this->db->Execute(
                'INSERT INTO pos_order_item
                    (orderitemid, orderid, companyid, itemid, name, qty, price, note, stationid, course)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$orderId, $companyId, $itemId, $name, $qty, $price, $itemNote, $stationId, $course]
            );
            if ($itemOk === false) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('No se pudo agregar un ítem a la orden');
            }
        }

        // La sesión vuelve a 'open' si había pedido la cuenta — el total
        // cambió, ese pedido de cuenta ya no vale. Dentro de la TX (la fila
        // sigue bloqueada por el FOR UPDATE de arriba); el publish va después
        // del commit para no notificar un estado que puede rollbackearse.
        $reopened = false;
        if ($spaceSessionId !== null && $lockedStatus === 'bill_requested') {
            $sessions = new \Punto\Api\Spaces\SpaceSessionService($this->db);
            $sessions->reopenFromBillRequested($companyId, $spaceSessionId);
            $reopened = true;
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al crear la orden (transacción abortada)');
        }

        // Side-effects post-commit: best-effort. La orden YA está commiteada —
        // si un publish falla, propagar la excepción devolvería 500 sobre un
        // request exitoso y el cliente reintentaría → orden DUPLICADA.
        try {
            $order = $this->find($companyId, $orderId);
            if ($sendNow && $order !== null) {
                $this->publish($companyId, $outletId, 'order:new', $order);
                realtimePublish('order', 'create', $orderId);
            }
            if ($reopened) {
                (new \Punto\Api\Spaces\SpaceSessionService($this->db))
                    ->publishSessionState($companyId, $spaceSessionId);
            }
        } catch (\Throwable $e) {
            error_log('[OrderCoreService::create] publish post-commit falló (orden ' . $orderId . '): ' . $e->getMessage());
        }

        return $orderId;
    }

    /**
     * open → sent. $outletScope (realm pos-app): restringe la operación a
     * órdenes del outlet del device — un POS/KDS de la sucursal A no puede
     * operar órdenes de la sucursal B del mismo tenant.
     */
    public function send(string $companyId, string $id, ?string $outletScope = null): array
    {
        global $db;

        $order = $this->find($companyId, $id);
        if ($order === null) {
            throw new \RuntimeException('Orden no encontrada');
        }
        $this->assertOutletScope($order['outletId'], $outletScope);
        if ($order['status'] !== 'open') {
            throw new \RuntimeException('Solo se puede enviar una orden en status open (actual: ' . $order['status'] . ')');
        }

        // TX propia (antes de F-EVT-0 este método hacía un único UPDATE
        // autocommit) — el evento debe entrar en la MISMA TX que el UPDATE
        // de status (invariante §C.4).
        $db->StartTrans();
        $ok = $this->db->Execute(
            "UPDATE pos_order SET status = 'sent', sent_at = now()
              WHERE orderid = ? AND companyid = ? AND status = 'open'",
            [$id, $companyId]
        );
        if ($ok === false) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo enviar la orden');
        }
        $this->recordEvent($companyId, $order['outletId'], $id, null, null, 'order', 'open', 'sent');

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al enviar la orden (transacción abortada)');
        }

        $order = $this->find($companyId, $id);
        if ($order !== null) {
            $this->publish($companyId, $order['outletId'], 'order:new', $order);
            realtimePublish('order', 'update', $id);
        }
        return $order ?? [];
    }

    /**
     * Transición a nivel orden. `cancelled` requiere que la orden NO tenga
     * saletransactionid (ya cobrada = no se puede cancelar acá, hay que
     * anular la venta). `closed` está prohibido — solo markPaid() cierra.
     */
    public function updateStatus(
        string $companyId,
        string $id,
        string $status,
        ?string $outletScope = null,
        ?string $reason = null
    ): array {
        global $db;

        if ($status === 'closed') {
            throw new \InvalidArgumentException('closed solo se setea vía markPaid() (cobro de la orden)');
        }

        // Una orden NUNCA se elimina: se cancela, y toda cancelación lleva
        // motivo (regla del owner 2026-07-19). El enforcement va ACÁ, no en la
        // UI — si viviera solo en el diálogo del POS, cualquier otro cliente
        // (KDS, integración, curl) cancelaría sin dejar rastro del porqué.
        $reason = $reason !== null ? trim($reason) : null;
        if ($status === 'cancelled' && ($reason === null || $reason === '')) {
            throw new \InvalidArgumentException('Cancelar una orden requiere un motivo');
        }

        $db->StartTrans();

        // FOR UPDATE + CAS sobre el status: sin el lock, un markPaid()
        // concurrente puede cerrar (cobrar) la orden entre nuestro read y
        // nuestro UPDATE, y este método la pisaría de vuelta a cancelled con
        // saletransactionid ya seteado (orden cobrada que figura cancelada).
        $order = ncmExecute(
            'SELECT * FROM pos_order WHERE orderid = ? AND companyid = ? FOR UPDATE',
            [$id, $companyId]
        );
        if (!$order) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Orden no encontrada');
        }
        if ($outletScope !== null && (string) $order['outletid'] !== $outletScope) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Orden no encontrada'); // no revelar existencia cross-outlet
        }
        $current = (string) $order['status'];
        $allowed = self::ORDER_TRANSITIONS[$current] ?? [];
        if (!in_array($status, $allowed, true)) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException("Transición inválida: {$current} → {$status}");
        }
        if ($status === 'cancelled' && !empty($order['saletransactionid'])) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('No se puede cancelar una orden ya cobrada (saleTransactionId presente)');
        }
        // out_for_delivery ("En camino") solo tiene sentido si la orden se
        // envía — una mesa o un retiro en mostrador no salen "en camino"
        // (context/27 §B.2).
        if ($status === 'out_for_delivery' && (string) ($order['fulfillment'] ?? 'dine_in') !== 'delivery') {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException("out_for_delivery solo es válido para órdenes con fulfillment='delivery'");
        }

        $closedAtSql = $status === 'cancelled' ? ', closed_at = now()' : '';
        $ok = $this->db->Execute(
            "UPDATE pos_order SET status = ?{$closedAtSql} WHERE orderid = ? AND companyid = ? AND status = ?",
            [$status, $id, $companyId, $current]
        );
        if ($ok === false) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo actualizar el estado de la orden');
        }
        $this->recordEvent($companyId, (string) $order['outletid'], $id, null, null, 'order', $current, $status, $reason);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al actualizar el estado de la orden (transacción abortada)');
        }

        $order = $this->find($companyId, $id);
        // Si seguimos dentro de una TX externa (cascada — ej. SpaceSessionService::
        // cancel), el publish se DIFIERE: notificar al KDS un cambio que la TX
        // externa puede rollbackear sería un phantom notify. El orquestador
        // llama publishOrderStatus() tras SU commit.
        if ($order !== null && !$db->InTrans()) {
            $this->publish($companyId, $order['outletId'], 'order:status', $order);
            realtimePublish('order', 'update', $id);
        }
        return $order ?? [];
    }

    /**
     * Publica el estado actual de una orden a KDS/realtime. Para orquestadores
     * que mutan órdenes dentro de su propia TX (updateStatus difiere el publish
     * cuando InTrans()) y notifican recién después del commit.
     */
    public function publishOrderStatus(string $companyId, string $orderId): void
    {
        $order = $this->find($companyId, $orderId);
        if ($order !== null) {
            $this->publish($companyId, $order['outletId'], 'order:status', $order);
            realtimePublish('order', 'update', $orderId);
        }
    }

    /**
     * Asigna (o desasigna, `$courierId=null`) el repartidor de una orden
     * delivery (F-D-1, context/27 §B.6.2). El repartidor es una PERSONA
     * (staff en `contact`), no un dispositivo pareado — hoy la caja asigna y
     * marca las transiciones por él; cuando exista su app propia va a usar
     * la misma columna con su propio actor.
     *
     * Sin TX/FOR UPDATE: no es una transición de status con carrera contra
     * markPaid, es un campo independiente que no participa de la máquina de
     * estados.
     *
     * NO se llama a recordEvent() acá — decisión cerrada (context/27 §C.3/§C.4):
     * `pos_order_event.to_status` es NOT NULL y `scope` está limitado a
     * 'order'|'item' como transiciones de la MÁQUINA DE ESTADOS. Asignar
     * repartidor no es una transición de status; forzarlo con `to_status` =
     * el status actual sin cambio sería un evento mintiendo que hubo una
     * transición que no ocurrió. Si a futuro se quiere trackear "quién asignó
     * a quién y cuándo" en el timeline, es una evolución de schema aparte
     * (scope nuevo o columna dedicada), no de esta tarea.
     */
    public function assignCourier(string $companyId, string $orderId, ?string $courierId, ?string $outletScope = null): array
    {
        global $db;

        $order = ncmExecute('SELECT * FROM pos_order WHERE orderid = ? AND companyid = ?', [$orderId, $companyId]);
        if (!$order) {
            throw new \RuntimeException('Orden no encontrada');
        }
        $this->assertOutletScope((string) $order['outletid'], $outletScope);

        if ((string) ($order['fulfillment'] ?? 'dine_in') !== 'delivery') {
            throw new \InvalidArgumentException("Solo se puede asignar repartidor a órdenes con fulfillment='delivery'");
        }

        if ($courierId !== null) {
            $courier = ncmExecute('SELECT contactid FROM contact WHERE contactid = ? AND companyid = ?', [$courierId, $companyId]);
            if (!$courier) {
                throw new \InvalidArgumentException('El repartidor no existe');
            }
        }

        $ok = $this->db->Execute(
            'UPDATE pos_order SET courierid = ? WHERE orderid = ? AND companyid = ?',
            [$courierId, $orderId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo asignar el repartidor');
        }

        $order = $this->find($companyId, $orderId);
        if ($order !== null) {
            $this->publish($companyId, $order['outletId'], 'order:status', $order);
            realtimePublish('order', 'update', $orderId);
        }
        return $order ?? [];
    }

    /**
     * Marca la orden como cobrada. La llama el flujo de cobro (O1: volcado
     * al carrito → SaleService → acá) con el transactionId ya creado — este
     * método NO crea la transacción, solo deja el rastro.
     *
     * Publish diferido si InTrans() (mismo patrón que updateStatus()):
     * SpaceSettlementService::settleIfCovered (F3) llama a este método
     * ANIDADO dentro de la TX de registerPayment() — sin este guard,
     * publicaría "orden cerrada" por WS antes de que esa TX externa
     * confirme el commit real, y un rollback (ej. el pago que la cubre
     * falla después) dejaría un phantom notify contra una orden que en
     * BD sigue abierta. El caller anidado llama publishOrderStatus()
     * después de SU commit — igual que hace SpaceSessionService::cancel()
     * con las cancelaciones en cascada.
     */
    public function markPaid(string $companyId, string $orderId, string $transactionId, ?string $outletScope = null): array
    {
        global $db;

        if ($transactionId === '') {
            throw new \InvalidArgumentException('transactionId requerido');
        }

        $db->StartTrans();

        $order = ncmExecute(
            'SELECT * FROM pos_order WHERE orderid = ? AND companyid = ? FOR UPDATE',
            [$orderId, $companyId]
        );
        if (!$order) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Orden no encontrada');
        }
        if ($outletScope !== null && (string) $order['outletid'] !== $outletScope) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Orden no encontrada'); // no revelar existencia cross-outlet
        }
        if (in_array($order['status'], ['closed', 'cancelled'], true)) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('La orden ya fue ' . $order['status'] . ' — no se puede cobrar de nuevo (idempotencia)');
        }

        $ok = $this->db->Execute(
            "UPDATE pos_order
                SET status = 'closed', saletransactionid = ?, closed_at = now()
              WHERE orderid = ? AND companyid = ? AND status NOT IN ('closed','cancelled')",
            [$transactionId, $orderId, $companyId]
        );
        if ($ok === false) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo marcar la orden como cobrada');
        }
        $this->recordEvent($companyId, (string) $order['outletid'], $orderId, null, null, 'order', (string) $order['status'], 'closed');

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al cobrar la orden (transacción abortada)');
        }

        $result = $this->find($companyId, $orderId);
        if ($result !== null && !$db->InTrans()) {
            $this->publish($companyId, $result['outletId'], 'order:status', $result);
            realtimePublish('order', 'update', $orderId);
        }
        return $result ?? [];
    }

    /**
     * Transición de un ítem individual. Recalcula y persiste el estado
     * agregado de la orden a partir de sus ítems activos (no cancelados):
     * todos ready → ready; todos delivered → delivered; alguno preparing o
     * más avanzado → in_progress; todos pending → sent.
     *
     * La derivación vale en los DOS sentidos: es también el único camino por el
     * que una orden vuelve de 'ready' a 'in_progress' cuando el KDS devuelve
     * una comanda a preparación (des-bumpear sus ítems). El caller no setea el
     * status de la orden a mano — lo deriva esta maquinaria, que es la que
     * sabe agregarlo.
     */
    public function updateItemStatus(string $companyId, string $orderItemId, string $status, ?string $outletScope = null): array
    {
        global $db;

        if (!array_key_exists($status, self::ITEM_TRANSITIONS)) {
            throw new \InvalidArgumentException('status de ítem inválido: ' . $status);
        }

        $db->StartTrans();

        $item = ncmExecute(
            'SELECT oi.*, o.companyid AS ordercompanyid, o.outletid AS orderoutletid
               FROM pos_order_item oi
               JOIN pos_order o ON o.orderid = oi.orderid AND o.companyid = oi.companyid
              WHERE oi.orderitemid = ? AND oi.companyid = ?
              FOR UPDATE OF oi',
            [$orderItemId, $companyId]
        );
        if (!$item) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Ítem de orden no encontrado');
        }
        if ($outletScope !== null && (string) ($item['orderoutletid'] ?? '') !== $outletScope) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Ítem de orden no encontrado'); // no revelar existencia cross-outlet
        }

        $current = (string) $item['status'];
        $allowed = self::ITEM_TRANSITIONS[$current] ?? [];
        if (!in_array($status, $allowed, true)) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException("Transición de ítem inválida: {$current} → {$status}");
        }

        // Los timestamps ESPEJAN el status, también cuando se retrocede: un ítem
        // devuelto a preparación no está listo, así que no puede conservar el
        // ready_at de la vuelta anterior (mediría un tiempo de preparación que
        // no fue). El historial del re-trabajo vive en pos_order_event, no en
        // esta columna, que responde "¿cuándo quedó listo?" — y la respuesta
        // correcta mientras se re-prepara es "todavía no".
        $timeCol = match ($status) {
            'ready'                => ', ready_at = now()',
            'delivered'            => ', delivered_at = now()',
            'preparing', 'pending' => ', ready_at = NULL',
            default                => '',
        };
        $ok = $this->db->Execute(
            "UPDATE pos_order_item SET status = ?{$timeCol} WHERE orderitemid = ? AND companyid = ?",
            [$status, $orderItemId, $companyId]
        );
        if ($ok === false) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo actualizar el ítem de la orden');
        }

        $orderId = (string) $item['orderid'];
        $this->recordEvent(
            $companyId,
            (string) $item['orderoutletid'],
            $orderId,
            $orderItemId,
            $item['stationid'] ?? null,
            'item',
            $current,
            $status
        );

        // recomputeOrderStatus() puede pisar pos_order.status como efecto
        // agregado de este cambio de ítem (ej. último ítem pasa a 'ready' →
        // la orden entera pasa a 'ready') — emite SU PROPIO evento scope='order'
        // si eso ocurre, ver ahí.
        $this->recomputeOrderStatus($companyId, $orderId);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al actualizar el ítem de la orden (transacción abortada)');
        }

        $order = $this->find($companyId, $orderId);
        if ($order !== null) {
            $this->publish($companyId, $order['outletId'], 'order:item-status', [
                'orderId'     => $orderId,
                'orderItemId' => $orderItemId,
                'status'      => $status,
                'order'       => $order,
            ]);
            realtimePublish('order', 'update', $orderId);
        }
        return $order ?? [];
    }

    /**
     * @param bool $includeItems Si true, adjunta los ítems de cada orden (una
     *             sola query batched con `orderid IN (...)`, no N+1 — usado
     *             por el diálogo de sesión de espacio, context/15 F3). Los
     *             ítems `cancelled` SE INCLUYEN; el consumidor decide qué
     *             hacer con ellos (tacharlos, excluirlos del total, etc).
     * @return array<int,array<string,mixed>>
     */
    public function list(string $companyId, array $filters = [], bool $includeItems = false): array
    {
        $where  = ['o.companyid = ?'];
        $params = [$companyId];

        if (!empty($filters['outletId'])) {
            $where[]  = 'o.outletid = ?';
            $params[] = (string) $filters['outletId'];
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $where[]  = "o.status IN ($placeholders)";
            foreach ($statuses as $s) $params[] = (string) $s;
        }
        if (!empty($filters['source'])) {
            $where[]  = 'o.source = ?';
            $params[] = (string) $filters['source'];
        }
        if (!empty($filters['fulfillment'])) {
            $fulfillment = (string) $filters['fulfillment'];
            if (!in_array($fulfillment, ['dine_in', 'takeaway', 'delivery'], true)) {
                throw new \InvalidArgumentException("fulfillment inválido (esperado 'dine_in'|'takeaway'|'delivery')");
            }
            $where[]  = 'o.fulfillment = ?';
            $params[] = $fulfillment;
        }
        if (!empty($filters['spaceSessionId'])) {
            $where[]  = 'o.spacesessionid = ?';
            $params[] = (string) $filters['spaceSessionId'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'o.created_at >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'o.created_at <= ?';
            $params[] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[]  = '(o.note ILIKE ? OR o.channelref ILIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }

        // Enriquecimiento de cliente (nombre + coords) y de espacio en el MISMO
        // query — el shape de una orden es único, venga del listado o del
        // detalle (ver find()). `contactLatLng` vive en el JSONB `data` (demote
        // de la mig 06) y se lee con `->>`: el operador jsonb `?` está
        // PROHIBIDO en queries PDO (colisiona con el placeholder). Los LEFT
        // JOIN acarrean companyid para no filtrar datos de otro tenant.
        $sql = 'SELECT o.*,
                       c.contactname          AS customer_name,
                       c.data->>\'contactLatLng\' AS customer_latlng,
                       sp.name                AS space_name,
                       co.contactname         AS courier_name
                  FROM pos_order o
             LEFT JOIN contact c
                    ON c.contactid = o.customerid
                   AND c.companyid = o.companyid
             LEFT JOIN space_session ss
                    ON ss.sessionid = o.spacesessionid
                   AND ss.companyid = o.companyid
             LEFT JOIN space sp
                    ON sp.tableid = ss.tableid
                   AND sp.companyid = ss.companyid
             LEFT JOIN contact co
                    ON co.contactid = o.courierid
                   AND co.companyid = o.companyid
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY o.created_at DESC
                 LIMIT 500';

        $rs = $this->db->Execute($sql, $params);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->presentOrder($row, false);
        }

        if ($includeItems && $out !== []) {
            $orderIds     = array_map(static fn (array $o) => (string) $o['id'], $out);
            $itemsByOrder = $this->loadItemsByOrderIds($companyId, $orderIds);
            foreach ($out as &$order) {
                $order['items'] = $itemsByOrder[$order['id']] ?? [];
            }
            unset($order);
        }

        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            // Mismo enriquecimiento de cliente y espacio que list() — el shape
            // de una orden es único, venga del listado o del detalle.
            'SELECT o.*,
                    c.contactname          AS customer_name,
                    c.data->>\'contactLatLng\' AS customer_latlng,
                    sp.name                AS space_name,
                    co.contactname         AS courier_name
               FROM pos_order o
          LEFT JOIN contact c
                 ON c.contactid = o.customerid
                AND c.companyid = o.companyid
          LEFT JOIN space_session ss
                 ON ss.sessionid = o.spacesessionid
                AND ss.companyid = o.companyid
          LEFT JOIN space sp
                 ON sp.tableid = ss.tableid
                AND sp.companyid = ss.companyid
          LEFT JOIN contact co
                 ON co.contactid = o.courierid
                AND co.companyid = o.companyid
              WHERE o.orderid = ? AND o.companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->presentOrder($row, true, $companyId);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Recalcula pos_order.status agregando el estado de sus ítems activos. */
    private function recomputeOrderStatus(string $companyId, string $orderId): void
    {
        $rs = $this->db->Execute(
            "SELECT status FROM pos_order_item WHERE orderid = ? AND companyid = ? AND status != 'cancelled'",
            [$orderId, $companyId]
        );
        if ($rs === false) return;
        $statuses = array_map(static fn ($r) => (string) $r['status'], $rs->GetRows());
        if ($statuses === []) return;

        $unique = array_unique($statuses);
        if (count($unique) === 1 && $unique[0] === 'delivered') {
            $newStatus = 'delivered';
        } elseif (count($unique) === 1 && $unique[0] === 'ready') {
            $newStatus = 'ready';
        } else {
            // Antes, "todos pending" devolvía null (= no tocar la orden): en un
            // flujo que solo avanzaba, todos-pending era el estado inicial y no
            // había nada que recalcular. Con los retrocesos de ítem (recall /
            // deshacer) ese caso SÍ es alcanzable hacia atrás, y dejarlo en null
            // dejaba la orden en un estado imposible: 'ready' con todos sus
            // ítems pending — fuera del board para siempre, porque el board mira
            // el status de la orden. El status de la orden es una FUNCIÓN de sus
            // ítems, así que todos-pending deriva a 'sent' (salió a preparar,
            // nadie la tomó todavía).
            //
            // Hacia adelante no cambia nada: en el único caso donde antes daba
            // null la orden YA estaba en 'sent', y el guard de abajo corta sin
            // UPDATE ni evento cuando el status derivado es el que ya tenía.
            $maxRank = max(array_map(static fn ($s) => self::ITEM_STATUS_RANK[$s] ?? 0, $statuses));
            $newStatus = $maxRank >= self::ITEM_STATUS_RANK['preparing'] ? 'in_progress' : 'sent';
        }

        // FLAG (auditoría F-EVT-0): este UPDATE es un SEGUNDO cambio de
        // pos_order.status disparado por updateItemStatus() — un camino de
        // escritura fuera de los 5 puntos "oficiales" que NO emitía evento
        // hasta este commit (ej. sent→in_progress cuando el primer ítem pasa
        // a preparing, o →ready cuando el último ítem llega a ready). Se
        // cubre acá en vez de dejarlo silencioso: se snapshotea el status
        // ANTES del UPDATE (para from_status + outletid, que este método no
        // conocía) y solo se emite si el UPDATE realmente pisó una fila.
        $before = ncmExecute(
            'SELECT outletid, status FROM pos_order WHERE orderid = ? AND companyid = ?',
            [$orderId, $companyId]
        );
        if (!$before || (string) $before['status'] === $newStatus) return; // sin cambio real, sin evento

        // Solo avanza el status de la orden si está en un estado operativo
        // activo (no toca closed/cancelled/open — open avanza recién con send()).
        $rs = $this->db->Execute(
            "UPDATE pos_order SET status = ?
              WHERE orderid = ? AND companyid = ? AND status IN ('sent','in_progress','ready','delivered')
              RETURNING orderid",
            [$newStatus, $orderId, $companyId]
        );
        if ($rs !== false && !$rs->EOF) {
            $this->recordEvent($companyId, (string) $before['outletid'], $orderId, null, null, 'order', (string) $before['status'], $newStatus);
        }
    }

    /** @return list<array{stationId:string, categoryIds:list<string>}> */
    private function listStationsRaw(string $companyId, string $outletId): array
    {
        $rs = $this->db->Execute(
            "SELECT stationid, categoryids FROM order_station
              WHERE companyid = ? AND status = 1 AND (outletid = ? OR outletid IS NULL)
              ORDER BY sort ASC",
            [$companyId, $outletId]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $raw = $row['categoryids'] ?? '[]';
            $ids = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
            $out[] = ['stationId' => (string) $row['stationid'], 'categoryIds' => array_map('strval', $ids)];
        }
        return $out;
    }

    /** Rechaza operar sobre una orden de otro outlet (realm pos-app). */
    private function assertOutletScope(?string $orderOutletId, ?string $outletScope): void
    {
        if ($outletScope !== null && (string) $orderOutletId !== $outletScope) {
            // Mismo mensaje que "no existe" — no revelar existencia cross-outlet.
            throw new \RuntimeException('Orden no encontrada');
        }
    }

    /**
     * Sin companyid en el WHERE a propósito: item_category no tiene esa
     * columna (mig 16, m2m). El invariante es que $itemId YA fue validado
     * contra companyid aguas arriba (create() lo chequea antes de llamar).
     *
     * @return list<string>
     */
    private function fetchItemCategoryIds(string $itemId): array
    {
        $rs = $this->db->Execute('SELECT categoryId FROM item_category WHERE itemId = ?', [$itemId]);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $cid = $row['categoryid'] ?? $row['categoryId'] ?? null;
            if ($cid !== null) $out[] = (string) $cid;
        }
        return $out;
    }

    /**
     * Primera estación cuyo categoryIds interseque las categorías del ítem
     * (comodín [] = atiende todo) — mismo modelo que printer bindings
     * (frontend/lib/hardware/printers/binding.ts).
     */
    private function resolveStationId(array $stations, array $itemCategoryIds): ?string
    {
        foreach ($stations as $station) {
            if ($station['categoryIds'] === []) {
                return $station['stationId'];
            }
            if (array_intersect($station['categoryIds'], $itemCategoryIds) !== []) {
                return $station['stationId'];
            }
        }
        return null;
    }

    private function publish(string $companyId, string $outletId, string $type, array $payload): void
    {
        wsPublish($companyId . ':kds:' . $outletId, $type, $payload);
    }

    /**
     * Punto ÚNICO de escritura de pos_order_event (F-EVT-0,
     * context/27-delivery-sla-plan.md §C.4). Llamado desde create(), send(),
     * updateStatus(), updateItemStatus(), markPaid() y recomputeOrderStatus()
     * (el agregado que updateItemStatus dispara) — SIEMPRE dentro de la misma
     * TX que el UPDATE/INSERT de status que lo origina. No abre TX propia:
     * corre dentro de la del caller (StartTrans anida con contador, ver
     * DB::StartTrans) — abrir una acá sería redundante y, si el caller ya
     * falló la TX (FailTrans), este INSERT igual se revierte con el resto.
     *
     * El actor se resuelve del contexto AMBIENTE del request (constantes
     * AUTHED_USER_ID/AUTHED_DEVICE_ID definidas por jwtAuthenticate en
     * auth_session.php) en vez de recibirse como parámetro thread-eado por
     * las 5 firmas de escritura: es contexto DE REQUEST (quién pegó al
     * endpoint), no un dato de dominio de la orden — mismo criterio que
     * tenantAudit() en bootstrap.php, que audita mutaciones leyendo AUTHED_*
     * ambiente en vez de recibir el actor por parámetro. cron/backfill (sin
     * sesión HTTP) → ninguna constante definida → actor_kind='system'.
     *
     * El module del device NO viaja en una constante ambiente en el path de
     * auth que usa este endpoint (apiAuthTenant/jwtAuthenticate) — a
     * diferencia de apiAuthPosContext (otro flujo, no usado por
     * orders-core.php) que sí define DEVICE_MODULE. Se resuelve con un
     * lookup puntual por PK sobre `device`, barato y dentro de la misma TX.
     */
    private function recordEvent(
        string $companyId,
        string $outletId,
        string $orderId,
        ?string $orderItemId,
        ?string $stationId,
        string $scope,
        ?string $fromStatus,
        string $toStatus,
        ?string $reason = null
    ): void {
        $userId   = (defined('AUTHED_USER_ID') && AUTHED_USER_ID !== '') ? AUTHED_USER_ID : null;
        $deviceId = (defined('AUTHED_DEVICE_ID') && AUTHED_DEVICE_ID !== '') ? AUTHED_DEVICE_ID : null;

        $actorKind   = 'system';
        $actorId     = null;
        $actorModule = null;

        if ($deviceId !== null) {
            $actorKind = 'device';
            $actorId   = $deviceId;
            $dev = ncmExecute('SELECT module FROM device WHERE deviceid = ? AND companyid = ?', [$deviceId, $companyId]);
            $mod = $dev ? (string) ($dev['module'] ?? '') : '';
            $actorModule = $mod !== '' ? $mod : null;
        } elseif ($userId !== null) {
            $actorKind = 'user';
            $actorId   = $userId;
        }

        $this->db->Execute(
            'INSERT INTO pos_order_event
                (eventid, companyid, outletid, orderid, orderitemid, stationid, scope,
                 from_status, to_status, actor_kind, actor_id, actor_module, reason)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$companyId, $outletId, $orderId, $orderItemId, $stationId, $scope,
             $fromStatus, $toStatus, $actorKind, $actorId, $actorModule, $reason]
        );
    }

    private function presentOrder(array $row, bool $withItems, ?string $companyId = null): array
    {
        $out = [
            'id'                => (string) ($row['orderid'] ?? ''),
            'companyId'         => (string) ($row['companyid'] ?? ''),
            'outletId'          => (string) ($row['outletid'] ?? ''),
            'registerId'        => $row['registerid'] ?? null,
            'source'            => (string) ($row['source'] ?? ''),
            'status'            => (string) ($row['status'] ?? ''),
            'orderNumber'       => isset($row['ordernumber']) ? (int) $row['ordernumber'] : null,
            'spaceSessionId'    => $row['spacesessionid'] ?? null,
            'customerId'        => $row['customerid'] ?? null,
            'courierId'         => $row['courierid'] ?? null,
            'userId'            => $row['userid'] ?? null,
            'note'              => $row['note'] ?? null,
            'channelRef'        => $row['channelref'] ?? null,
            'saleTransactionId' => $row['saletransactionid'] ?? null,
            'createdAt'         => $row['created_at'] ?? null,
            'sentAt'            => $row['sent_at'] ?? null,
            'closedAt'          => $row['closed_at'] ?? null,
            'fulfillment'       => (string) ($row['fulfillment'] ?? 'dine_in'),
            'deliveryAddressId' => $row['deliveryaddressid'] ?? null,
            'deliveryAddress'   => $row['deliveryaddress'] ?? null,
            'deliveryReference' => $row['deliveryreference'] ?? null,
        ];
        // lat/lng snapshoteados de la orden (mig 94) — mismo criterio que
        // customerLat/customerLng: float o null, nunca NaN.
        $out['deliveryLat'] = isset($row['deliverylat']) && $row['deliverylat'] !== null ? (float) $row['deliverylat'] : null;
        $out['deliveryLng'] = isset($row['deliverylng']) && $row['deliverylng'] !== null ? (float) $row['deliverylng'] : null;

        // Datos del cliente para la vista mapa de /pos/ordenes. Vienen del
        // LEFT JOIN a contact (list()/find()); si la orden no tiene cliente o
        // la query no los trajo, quedan null.
        $customerName = $row['customer_name'] ?? null;
        $out['customerName'] = ($customerName !== null && $customerName !== '')
            ? (string) $customerName
            : null;
        [$out['customerLat'], $out['customerLng']] = self::parseLatLng($row['customer_latlng'] ?? null);

        // Nombre del repartidor (LEFT JOIN a `contact` en list()/find(), mismo
        // patrón que customerName). Solo relevante si fulfillment==='delivery'.
        $courierName = $row['courier_name'] ?? null;
        $out['courierName'] = ($courierName !== null && $courierName !== '')
            ? (string) $courierName
            : null;

        // Nombre del espacio (LEFT JOIN space_session → space en list()/find()).
        // Sin él, una comanda de salón solo puede decir "Espacio" a secas: el
        // spacesessionid es un UUID y la cocina necesita saber A DÓNDE va el
        // plato. null cuando la orden no es de espacio o el row no lo trajo.
        $spaceName = $row['space_name'] ?? null;
        $out['spaceName'] = ($spaceName !== null && $spaceName !== '') ? (string) $spaceName : null;

        if ($withItems && $companyId !== null) {
            $rs = $this->db->Execute(
                'SELECT oi.*, s.name AS stationname
                   FROM pos_order_item oi
              LEFT JOIN order_station s ON s.stationid = oi.stationid AND s.companyid = oi.companyid
                  WHERE oi.orderid = ? AND oi.companyid = ?
                  ORDER BY oi.created_at ASC',
                [$row['orderid'], $companyId]
            );
            $items = [];
            if ($rs !== false) {
                foreach ($rs->GetRows() as $item) {
                    $items[] = $this->presentOrderItem($item);
                }
            }
            $out['items'] = $items;

            // Timeline de la orden (F-EVT-0) — SOLO en find() (detalle), nunca
            // en list(): $withItems=true acá es exclusivo del path de find(),
            // list() siempre llama presentOrder(..., false) y adjunta ítems
            // aparte vía loadItemsByOrderIds(). Una sola query, LEFT JOIN a
            // order_station para el nombre — sin N+1.
            $evRs = $this->db->Execute(
                'SELECT e.*, s.name AS stationname
                   FROM pos_order_event e
              LEFT JOIN order_station s ON s.stationid = e.stationid AND s.companyid = e.companyid
                  WHERE e.orderid = ? AND e.companyid = ?
                  ORDER BY e.created_at ASC',
                [$row['orderid'], $companyId]
            );
            $events = [];
            if ($evRs !== false) {
                foreach ($evRs->GetRows() as $ev) {
                    $events[] = [
                        'scope'       => (string) ($ev['scope'] ?? ''),
                        'orderItemId' => $ev['orderitemid'] ?? null,
                        'stationId'   => $ev['stationid'] ?? null,
                        'stationName' => $ev['stationname'] ?? null,
                        'fromStatus'  => $ev['from_status'] ?? null,
                        'toStatus'    => (string) ($ev['to_status'] ?? ''),
                        'actorKind'   => (string) ($ev['actor_kind'] ?? ''),
                        'actorModule' => $ev['actor_module'] ?? null,
                        'reason'      => $ev['reason'] ?? null,
                        'createdAt'   => $ev['created_at'] ?? null,
                    ];
                }
            }
            $out['events'] = $events;
        }

        return $out;
    }

    /**
     * Parsea el string legacy "lat,lng" de `contact.data->>'contactLatLng'`.
     * Devuelve [null, null] ante vacío, formato inesperado, valores no
     * numéricos o fuera de rango — NUNCA NaN (rompería el mapa del cliente).
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function parseLatLng(mixed $raw): array
    {
        if (!is_string($raw)) return [null, null];
        $parts = explode(',', trim($raw));
        if (count($parts) !== 2) return [null, null];

        $lat = trim($parts[0]);
        $lng = trim($parts[1]);
        if (!is_numeric($lat) || !is_numeric($lng)) return [null, null];

        $latF = (float) $lat;
        $lngF = (float) $lng;
        if (!is_finite($latF) || !is_finite($lngF)) return [null, null];
        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) return [null, null];
        // "0,0" es el valor basura típico de un backfill fallido, no una coord real.
        if ($latF === 0.0 && $lngF === 0.0) return [null, null];

        return [$latF, $lngF];
    }

    /** Shape único de un ítem de orden — usado por find()/presentOrder() y por loadItemsByOrderIds() (list con includeItems). */
    private function presentOrderItem(array $item): array
    {
        return [
            'id'          => (string) ($item['orderitemid'] ?? ''),
            'itemId'      => $item['itemid'] ?? null,
            'name'        => (string) ($item['name'] ?? ''),
            'qty'         => (float) ($item['qty'] ?? 0),
            'price'       => isset($item['price']) ? (float) $item['price'] : null,
            'note'        => $item['note'] ?? null,
            'stationId'   => $item['stationid'] ?? null,
            'stationName' => $item['stationname'] ?? null,
            'status'      => (string) ($item['status'] ?? ''),
            'course'      => (int) ($item['course'] ?? 1),
            'createdAt'   => $item['created_at'] ?? null,
            'readyAt'     => $item['ready_at'] ?? null,
            'deliveredAt' => $item['delivered_at'] ?? null,
        ];
    }

    /**
     * Carga ítems de varias órdenes en UNA sola query (`orderid IN (...)`,
     * placeholders generados dinámicamente — nunca interpolación de valores).
     * Evita el N+1 de pedir el detalle de cada orden por separado.
     *
     * @param list<string> $orderIds
     * @return array<string,list<array<string,mixed>>> ítems agrupados por orderId
     */
    private function loadItemsByOrderIds(string $companyId, array $orderIds): array
    {
        if ($orderIds === []) return [];

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $params       = $orderIds;
        $params[]     = $companyId;

        $rs = $this->db->Execute(
            "SELECT oi.*, s.name AS stationname
               FROM pos_order_item oi
          LEFT JOIN order_station s ON s.stationid = oi.stationid AND s.companyid = oi.companyid
              WHERE oi.orderid IN ($placeholders) AND oi.companyid = ?
              ORDER BY oi.created_at ASC",
            $params
        );
        if ($rs === false) return [];

        $out = [];
        foreach ($rs->GetRows() as $item) {
            $orderId = (string) ($item['orderid'] ?? '');
            $out[$orderId][] = $this->presentOrderItem($item);
        }
        return $out;
    }
}
