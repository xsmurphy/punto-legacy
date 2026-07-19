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
    /** Transiciones válidas de pos_order_item.status. */
    private const ITEM_TRANSITIONS = [
        'pending'   => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready'     => ['delivered'],
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

    /** Transiciones válidas de pos_order.status vía updateStatus() (closed es SOLO markPaid). */
    private const ORDER_TRANSITIONS = [
        'open'        => ['sent', 'cancelled'],
        'sent'        => ['in_progress', 'ready', 'delivered', 'cancelled'],
        'in_progress' => ['ready', 'delivered', 'cancelled'],
        'ready'       => ['delivered', 'cancelled'],
        'delivered'   => ['cancelled'],
        'closed'      => [],
        'cancelled'   => [],
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
     *              items:list<array{itemId?:string, qty:float, price?:float,
     *              note?:string, course?:int}>, customerId?:string,
     *              userId?:string, note?:string, channelRef?:string,
     *              sendNow?:bool} $data
     * @return string orderId
     */
    public function create(string $companyId, array $data): string
    {
        global $db;

        $outletId   = (string) ($data['outletId'] ?? '');
        $registerId = !empty($data['registerId']) ? (string) $data['registerId'] : null;
        $source     = (string) ($data['source'] ?? 'counter');
        $items      = (array) ($data['items'] ?? []);
        $customerId = !empty($data['customerId']) ? (string) $data['customerId'] : null;
        $userId     = !empty($data['userId']) ? (string) $data['userId'] : null;
        $note       = isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null;
        $channelRef = isset($data['channelRef']) && $data['channelRef'] !== '' ? (string) $data['channelRef'] : null;
        $sendNow    = !empty($data['sendNow']);

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

        $stations = $this->listStationsRaw($companyId, $outletId);

        $db->StartTrans();

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
                 customerid, userid, note, channelref, sent_at)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($sendNow ? 'now()' : 'NULL') . ")
             RETURNING orderid",
            [$companyId, $outletId, $registerId, $source, $status, $orderNumber, $customerId, $userId, $note, $channelRef]
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

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al crear la orden (transacción abortada)');
        }

        $order = $this->find($companyId, $orderId);
        if ($sendNow && $order !== null) {
            $this->publish($companyId, $outletId, 'order:new', $order);
            realtimePublish('order', 'create', $orderId);
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
        $order = $this->find($companyId, $id);
        if ($order === null) {
            throw new \RuntimeException('Orden no encontrada');
        }
        $this->assertOutletScope($order['outletId'], $outletScope);
        if ($order['status'] !== 'open') {
            throw new \RuntimeException('Solo se puede enviar una orden en status open (actual: ' . $order['status'] . ')');
        }
        $ok = $this->db->Execute(
            "UPDATE pos_order SET status = 'sent', sent_at = now()
              WHERE orderid = ? AND companyid = ? AND status = 'open'",
            [$id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo enviar la orden');
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
    public function updateStatus(string $companyId, string $id, string $status, ?string $outletScope = null): array
    {
        global $db;

        if ($status === 'closed') {
            throw new \InvalidArgumentException('closed solo se setea vía markPaid() (cobro de la orden)');
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

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al actualizar el estado de la orden (transacción abortada)');
        }

        $order = $this->find($companyId, $id);
        if ($order !== null) {
            $this->publish($companyId, $order['outletId'], 'order:status', $order);
            realtimePublish('order', 'update', $id);
        }
        return $order ?? [];
    }

    /**
     * Marca la orden como cobrada. La llama el flujo de cobro (O1: volcado
     * al carrito → SaleService → acá) con el transactionId ya creado — este
     * método NO crea la transacción, solo deja el rastro.
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

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al cobrar la orden (transacción abortada)');
        }

        $result = $this->find($companyId, $orderId);
        if ($result !== null) {
            $this->publish($companyId, $result['outletId'], 'order:status', $result);
            realtimePublish('order', 'update', $orderId);
        }
        return $result ?? [];
    }

    /**
     * Transición de un ítem individual. Recalcula y persiste el estado
     * agregado de la orden a partir de sus ítems activos (no cancelados):
     * todos ready → ready; todos delivered → delivered; alguno preparing o
     * más avanzado → in_progress; si todos siguen pending, no toca el
     * status de la orden.
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

        $timeCol = match ($status) {
            'ready'     => ', ready_at = now()',
            'delivered' => ', delivered_at = now()',
            default     => '',
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

    /** @return array<int,array<string,mixed>> */
    public function list(string $companyId, array $filters = []): array
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

        $sql = 'SELECT o.*
                  FROM pos_order o
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY o.created_at DESC
                 LIMIT 500';

        $rs = $this->db->Execute($sql, $params);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->presentOrder($row, false);
        }
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM pos_order WHERE orderid = ? AND companyid = ? LIMIT 1',
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
            $maxRank = max(array_map(static fn ($s) => self::ITEM_STATUS_RANK[$s] ?? 0, $statuses));
            $newStatus = $maxRank >= self::ITEM_STATUS_RANK['preparing'] ? 'in_progress' : null;
        }
        if ($newStatus === null) return;

        // Solo avanza el status de la orden si está en un estado operativo
        // activo (no toca closed/cancelled/open — open avanza recién con send()).
        $this->db->Execute(
            "UPDATE pos_order SET status = ?
              WHERE orderid = ? AND companyid = ? AND status IN ('sent','in_progress','ready','delivered')",
            [$newStatus, $orderId, $companyId]
        );
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
            'tableSessionId'    => $row['tablesessionid'] ?? null,
            'customerId'        => $row['customerid'] ?? null,
            'userId'            => $row['userid'] ?? null,
            'note'              => $row['note'] ?? null,
            'channelRef'        => $row['channelref'] ?? null,
            'saleTransactionId' => $row['saletransactionid'] ?? null,
            'createdAt'         => $row['created_at'] ?? null,
            'sentAt'            => $row['sent_at'] ?? null,
            'closedAt'          => $row['closed_at'] ?? null,
        ];

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
                    $items[] = [
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
            }
            $out['items'] = $items;
        }

        return $out;
    }
}
