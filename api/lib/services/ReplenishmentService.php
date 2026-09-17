<?php
declare(strict_types=1);
namespace Punto\Api\Services;

use Punto\Api\Production\ProductionService;

/**
 * ReplenishmentService — necesidad de reposición (context/70 §B.5, D6-D8,
 * mig 228).
 *
 * ── Qué es y qué NO es ───────────────────────────────────────────────────────
 *
 * La necesidad es "a esta sucursal le faltan N de este ítem". NO es un
 * documento: comprar, transferir o producir son las formas de CUBRIRLA (D6), y
 * las elige una persona desde el panel. Nada acá crea una orden de producción
 * ni una transferencia por su cuenta (D7, opción B).
 *
 * ── Tres orígenes, una sola regla ────────────────────────────────────────────
 *
 *   - `min_stock`: cualquier movimiento de stock que deja el saldo de la
 *     sucursal ≤ `item.itemminstock` (disparo dentro de
 *     `Inventory::manageStock()`, el embudo único del ledger).
 *   - `count_panel` / `count_register`: al cerrar un conteo, para cada ítem
 *     contado cuyo saldo quedó ≤ mínimo (D8). Se calcula en el servidor, así
 *     que el conteo ciego sigue siendo ciego.
 *
 * La regla es la misma en los tres: el ítem tiene que tener "cantidad a
 * reponer" (`itemreplenishqty`), se abre por esa cantidad fija, y no se abre
 * si ya hay una ABIERTA para (sucursal, ítem). Eso último lo garantiza el
 * índice único parcial `uidx_replenishment_need_open` con
 * `ON CONFLICT DO NOTHING` — no un SELECT previo, que dos cajas pasarían a la
 * vez.
 *
 * ── El disparo nunca rompe el movimiento ─────────────────────────────────────
 *
 * Corre dentro de la transacción de la venta. Un INSERT fallido con
 * `$db->Execute()` haría rollback de la venta entera, así que el INSERT va por
 * `DB::ExecuteBestEffort()` (savepoint): si falla, se registra y la venta
 * sigue. Y como es la MISMA transacción, si la venta hace rollback la necesidad
 * se va con ella — no queda una necesidad por una venta que no existió.
 *
 * ── Cobertura ────────────────────────────────────────────────────────────────
 *
 * Cada documento vinculado planea cubrir una cantidad. Lo EFECTIVO se deriva
 * del documento, no se guarda: orden de producción COMPLETADA → lo producido;
 * transferencia vigente → lo transferido de ese ítem hacia la sucursal (las
 * transferencias de Punto se aplican al crearse, no hay "en tránsito");
 * cancelado → 0. Cuando lo efectivo alcanza la cantidad, la necesidad pasa a
 * `covered`; si un documento se cancela y deja de alcanzar, vuelve a `open`.
 */
final class ReplenishmentService
{
    public const ORIGINS = ['min_stock', 'count_panel', 'count_register'];
    public const STATUSES = ['open', 'covered', 'closed'];

    /** @var array<string, array<string, true>> companyId => needIds a publicar al final del request. */
    private static array $pendingEvents = [];
    private static bool $shutdownRegistered = false;

    // ── Disparo ───────────────────────────────────────────────────────────────

    /**
     * Abre la necesidad si corresponde. Best-effort: NUNCA lanza.
     *
     * El caller ya tiene el saldo y los umbrales del ítem (manageStock los lee
     * en la misma query con la que valida el ítem), así que en el caso normal
     * —saldo sobre el mínimo, o ítem sin cantidad a reponer— esto no toca la
     * base.
     *
     * @return string|null needId creado, o null si no se abrió (no correspondía,
     *                     ya había una abierta, o falló).
     */
    public static function trigger(
        string $companyId,
        string $outletId,
        string $itemId,
        float $onHand,
        mixed $minStock,
        mixed $replenishQty,
        ?string $userId,
        string $origin = 'min_stock',
        ?string $sourceId = null,
    ): ?string {
        try {
            if ($minStock === null || $minStock === '' || !is_numeric($minStock)) {
                return null;
            }
            if ($replenishQty === null || $replenishQty === '' || !is_numeric($replenishQty) || (float) $replenishQty <= 0) {
                return null;
            }
            if ($onHand > (float) $minStock + 1e-9) {
                return null;
            }
            if ($companyId === '' || $outletId === '' || $itemId === '' || !in_array($origin, self::ORIGINS, true)) {
                return null;
            }

            global $db;
            if (!is_object($db) || !method_exists($db, 'ExecuteBestEffort')) {
                return null;
            }

            $rs = $db->ExecuteBestEffort(
                'INSERT INTO replenishment_need
                    (companyid, outletid, itemid, quantity, origin, sourceid, onhandat, createdby)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (companyid, outletid, itemid) WHERE status = \'open\' DO NOTHING
                 RETURNING needid',
                [
                    $companyId, $outletId, $itemId, (float) $replenishQty, $origin,
                    self::uuidOrNull($sourceId), $onHand, self::uuidOrNull($userId),
                ]
            );

            if ($rs === null || $rs->EOF) {
                return null;
            }
            $needId = (string) ($rs->fields['needid'] ?? '');
            if ($needId === '') {
                return null;
            }

            self::queueEvent($companyId, $needId);
            return $needId;
        } catch (\Throwable $e) {
            error_log('[replenishment] disparo ignorado: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Disparo por conteo (D8): evalúa los ítems CONTADOS contra el saldo que
     * dejó el conteo en la sucursal. Best-effort, nunca lanza.
     *
     * @param string[] $itemIds
     * @return int necesidades abiertas
     */
    public static function triggerForCount(
        string $companyId,
        string $outletId,
        string $countId,
        string $origin,
        ?string $userId,
        array $itemIds,
    ): int {
        try {
            $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
            if ($itemIds === [] || $outletId === '') {
                return 0;
            }

            global $db;
            if (!is_object($db) || !method_exists($db, 'ExecuteBestEffort')) {
                return 0;
            }

            // Lecturas por el camino best-effort también: esto corre dentro de
            // la transacción del conteo, y un error de `ncmExecute()` haría
            // rollback del conteo entero (ver DB::ExecuteBestEffort()).
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $rs = $db->ExecuteBestEffort(
                'SELECT i.itemid, i.itemminstock, i.itemreplenishqty,
                        COALESCE((SELECT SUM(s.stockcount) FROM stock s
                                   WHERE s.itemid = i.itemid AND s.outletid = ?), 0) AS onhand
                   FROM item i
                  WHERE i.companyid = ? AND i.itemid IN (' . $placeholders . ')
                    AND i.itemminstock IS NOT NULL AND i.itemreplenishqty IS NOT NULL
                    AND i.itemtrackinventory IS TRUE AND i.itemstatus = 1',
                array_merge([$outletId, $companyId], $itemIds)
            );

            $candidates = [];
            if ($rs !== null) {
                while (!$rs->EOF) {
                    $candidates[(string) $rs->fields['itemid']] = $rs->fields;
                    $rs->MoveNext();
                }
            }
            if ($candidates === []) {
                return 0;
            }

            $opened = 0;
            foreach ($candidates as $itemId => $row) {
                $id = self::trigger(
                    $companyId,
                    $outletId,
                    (string) $itemId,
                    (float) ($row['onhand'] ?? 0.0),
                    $row['itemminstock'],
                    $row['itemreplenishqty'],
                    $userId,
                    $origin,
                    $countId,
                );
                if ($id !== null) {
                    $opened++;
                }
            }
            return $opened;
        } catch (\Throwable $e) {
            error_log('[replenishment] disparo por conteo ignorado: ' . $e->getMessage());
            return 0;
        }
    }

    // ── Lectura ───────────────────────────────────────────────────────────────

    /**
     * Listado del panel.
     *
     * @param array{status?: string, outletId?: string} $filters
     * @param string[]|null $allowedOutletIds  null = sin restricción (usuario
     *                                         global); [] = no ve ninguna.
     */
    public function list(string $companyId, array $filters, ?array $allowedOutletIds): array
    {
        [$where, $params] = $this->scopeWhere($companyId, $allowedOutletIds);
        if ($where === null) {
            return [];
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[]  = 'n.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['outletId'])) {
            $where[]  = 'n.outletid = ?';
            $params[] = (string) $filters['outletId'];
        }

        return $this->fetch($where, $params);
    }

    public function get(string $companyId, string $needId, ?array $allowedOutletIds): ?array
    {
        [$where, $params] = $this->scopeWhere($companyId, $allowedOutletIds);
        if ($where === null) {
            return null;
        }
        $where[]  = 'n.needid = ?';
        $params[] = $needId;
        $rows = $this->fetch($where, $params);
        return $rows[0] ?? null;
    }

    /**
     * Necesidades abiertas, para el centro de notificaciones (fuente derivada:
     * si se cubre o se cierra, deja de aparecer sola).
     *
     * @return list<array{needId: string, itemId: string, itemName: string, outletId: string, outletName: string, pending: float, createdAt: string}>
     */
    public function openForNotifications(string $companyId, ?array $allowedOutletIds, int $limit = 50): array
    {
        [$where, $params] = $this->scopeWhere($companyId, $allowedOutletIds);
        if ($where === null) {
            return [];
        }
        $where[] = "n.status = 'open'";
        return array_slice($this->fetch($where, $params), 0, $limit);
    }

    /** @return array{0: list<string>|null, 1: list<mixed>} */
    private function scopeWhere(string $companyId, ?array $allowedOutletIds): array
    {
        $where  = ['n.companyid = ?'];
        $params = [$companyId];
        if ($allowedOutletIds !== null) {
            $ids = array_values(array_filter(array_map('strval', $allowedOutletIds)));
            if ($ids === []) {
                return [null, []];
            }
            $where[] = 'n.outletid IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        return [$where, $params];
    }

    /** SQL de la cantidad efectiva y planeada por línea de cobertura. */
    private const COVERAGE_SQL = "
        SELECT c.coverageid, c.needid, c.sourcetype, c.sourceid, c.quantity, c.createdat,
               CASE c.sourcetype
                 WHEN 'production_order' THEN po.status
                 WHEN 'stock_transfer'   THEN CASE WHEN st.\"status\" = 1 THEN 'done' ELSE 'cancelled' END
               END AS sourcestatus,
               CASE c.sourcetype
                 WHEN 'production_order' THEN po.docnumber
                 WHEN 'stock_transfer'   THEN st.docnumber
               END AS sourcedocnumber,
               CASE c.sourcetype
                 WHEN 'production_order' THEN
                   CASE WHEN po.status = 'completed' THEN COALESCE(po.qtyproduced, 0) ELSE 0 END
                 WHEN 'stock_transfer' THEN
                   CASE WHEN st.\"status\" = 1 THEN COALESCE((
                     SELECT SUM(sti.\"qty\") FROM stock_transfer_item sti
                      WHERE sti.stocktransferid = st.stocktransferid AND sti.itemid = n.itemid
                   ), 0) ELSE 0 END
               END AS effective,
               CASE WHEN c.sourcetype = 'production_order' AND po.status IN ('draft','in_progress')
                    THEN c.quantity ELSE 0 END AS inflight
          FROM replenishment_need_coverage c
          JOIN replenishment_need n ON n.needid = c.needid
          LEFT JOIN production_order po
                 ON c.sourcetype = 'production_order' AND po.orderid = c.sourceid AND po.companyid = c.companyid
          LEFT JOIN stock_transfer st
                 ON c.sourcetype = 'stock_transfer' AND st.stocktransferid = c.sourceid
                AND st.companyid = c.companyid AND st.tooutletid = n.outletid
    ";

    private function fetch(array $where, array $params): array
    {
        $rs = ncmExecute(
            'SELECT n.needid, n.outletid, n.itemid, n.quantity, n.origin, n.sourceid, n.onhandat,
                    n.status, n.closereason, n.createdby, n.closedby, n.createdat, n.updatedat,
                    n.coveredat, n.closedat,
                    i.itemname, i.itemminstock, i.itemreplenishqty,
                    o.outletname,
                    cu.contactname AS createdbyname,
                    cl.contactname AS closedbyname,
                    EXISTS (SELECT 1 FROM item_compound ic WHERE ic.parentitemid = n.itemid) AS producible
               FROM replenishment_need n
               JOIN item i   ON i.itemid = n.itemid AND i.companyid = n.companyid
               JOIN outlet o ON o.outletid = n.outletid AND o.companyid = n.companyid
               LEFT JOIN contact cu ON cu.contactid = n.createdby
               LEFT JOIN contact cl ON cl.contactid = n.closedby
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY (n.status = \'open\') DESC, n.createdat DESC
              LIMIT 1000',
            $params,
            false,
            true
        );

        $needs = [];
        if ($rs) {
            while (!$rs->EOF) {
                $r = $rs->fields;
                $needs[(string) $r['needid']] = [
                    'needId'        => (string) $r['needid'],
                    'outletId'      => (string) $r['outletid'],
                    'outletName'    => (string) ($r['outletname'] ?? ''),
                    'itemId'        => (string) $r['itemid'],
                    'itemName'      => (string) ($r['itemname'] ?? ''),
                    'quantity'      => (float) $r['quantity'],
                    'origin'        => (string) $r['origin'],
                    'sourceId'      => $r['sourceid'] !== null ? (string) $r['sourceid'] : null,
                    'onHandAt'      => $r['onhandat'] !== null ? (float) $r['onhandat'] : null,
                    'status'        => (string) $r['status'],
                    'closeReason'   => $r['closereason'] !== null ? (string) $r['closereason'] : null,
                    'createdBy'     => $r['createdby'] !== null ? (string) $r['createdby'] : null,
                    'createdByName' => $r['createdbyname'] !== null ? (string) $r['createdbyname'] : null,
                    'closedByName'  => $r['closedbyname'] !== null ? (string) $r['closedbyname'] : null,
                    'createdAt'     => (string) $r['createdat'],
                    'coveredAt'     => $r['coveredat'] !== null ? (string) $r['coveredat'] : null,
                    'closedAt'      => $r['closedat'] !== null ? (string) $r['closedat'] : null,
                    'producible'    => in_array($r['producible'], [true, 't', 'true', 1, '1'], true),
                    'covered'       => 0.0,
                    'inFlight'      => 0.0,
                    'pending'       => (float) $r['quantity'],
                    'coverages'     => [],
                ];
                $rs->MoveNext();
            }
        }

        if ($needs === []) {
            return [];
        }

        $ids = array_keys($needs);
        $crs = ncmExecute(
            self::COVERAGE_SQL . ' WHERE c.needid IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY c.createdat',
            $ids,
            false,
            true
        );
        if ($crs) {
            while (!$crs->EOF) {
                $c      = $crs->fields;
                $needId = (string) $c['needid'];
                if (isset($needs[$needId])) {
                    $effective = (float) ($c['effective'] ?? 0);
                    $inflight  = (float) ($c['inflight'] ?? 0);
                    $needs[$needId]['covered']  += $effective;
                    $needs[$needId]['inFlight'] += $inflight;
                    $needs[$needId]['coverages'][] = [
                        'coverageId'   => (string) $c['coverageid'],
                        'sourceType'   => (string) $c['sourcetype'],
                        'sourceId'     => (string) $c['sourceid'],
                        'docNumber'    => $c['sourcedocnumber'] !== null ? (int) $c['sourcedocnumber'] : null,
                        'sourceStatus' => (string) ($c['sourcestatus'] ?? ''),
                        'quantity'     => (float) $c['quantity'],
                        'effective'    => $effective,
                        'createdAt'    => (string) $c['createdat'],
                    ];
                }
                $crs->MoveNext();
            }
        }

        foreach ($needs as &$n) {
            $n['pending'] = max(0.0, $n['quantity'] - $n['covered'] - $n['inFlight']);
        }
        unset($n);

        return array_values($needs);
    }

    // ── Cobertura (acciones humanas desde el panel) ──────────────────────────

    /**
     * "Producir": orden de producción en BORRADOR por lo pendiente (o la
     * cantidad indicada), vinculada como cobertura. Solo ítems con receta —
     * lo valida `ProductionService::create()`, que es quien decide qué se
     * puede producir.
     *
     * @return array{needId: string, orderId: string}
     */
    public function produce(string $companyId, string $userId, string $needId, ?float $qty, ?array $allowedOutletIds): array
    {
        global $db;

        $need = $this->requireCoverable($companyId, $needId, $allowedOutletIds);
        $qty  = $this->resolveQty($need, $qty);

        $db->StartTrans();
        try {
            $this->lockOpen($companyId, $needId);

            $orderId = (new ProductionService($db))->create($companyId, $userId, [
                'itemId'     => $need['itemId'],
                'outletId'   => $need['outletId'],
                'qtyPlanned' => $qty,
                'mode'       => 'draft',
            ]);

            $this->link($companyId, $needId, 'production_order', $orderId, $qty, $userId);
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        if (!$db->CompleteTrans()) {
            throw new \RuntimeException('No se pudo crear la orden de producción');
        }

        realtimePublish('production', 'create', $orderId, 'all', $companyId);
        realtimePublish('replenishment-need', 'update', $needId, 'all', $companyId);

        return ['needId' => $needId, 'orderId' => $orderId];
    }

    /**
     * "Transferir": transferencia desde otra sucursal (o depósito) HACIA la de
     * la necesidad. Las transferencias de Punto se aplican al crearse, así que
     * cubre en el momento.
     *
     * @return array{needId: string, transferId: string, status: string}
     */
    public function transfer(
        string $companyId,
        string $userId,
        string $needId,
        string $fromOutletId,
        ?string $fromLocationId,
        ?string $toLocationId,
        ?float $qty,
        ?array $allowedOutletIds,
    ): array {
        global $db;

        $need = $this->requireCoverable($companyId, $needId, $allowedOutletIds);
        $qty  = $this->resolveQty($need, $qty);

        if ($fromOutletId === '') {
            throw new \InvalidArgumentException('Elegí de dónde sale la mercadería', 422);
        }

        $db->StartTrans();
        try {
            $this->lockOpen($companyId, $needId);

            $result = (new StockTransferService())->create(
                $companyId,
                $userId,
                ['outletId' => $fromOutletId, 'locationId' => $fromLocationId ?: null],
                ['outletId' => $need['outletId'], 'locationId' => $toLocationId ?: null],
                null,
                [['itemId' => $need['itemId'], 'qty' => $qty]]
            );
            $transferId = (string) ($result['id'] ?? '');
            if ($transferId === '' || (int) ($result['itemsProcessed'] ?? 0) < 1) {
                throw new \InvalidArgumentException('Este artículo no lleva control de stock', 422);
            }

            $this->link($companyId, $needId, 'stock_transfer', $transferId, $qty, $userId);
            $status = $this->recompute($companyId, $needId);
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        if (!$db->CompleteTrans()) {
            throw new \RuntimeException('No se pudo crear la transferencia');
        }

        realtimePublish('replenishment-need', 'update', $needId, 'all', $companyId);

        return ['needId' => $needId, 'transferId' => $transferId, 'status' => $status];
    }

    /** Cierre manual. El motivo es obligatorio (también en la BD). */
    public function close(string $companyId, string $userId, string $needId, string $reason, ?array $allowedOutletIds): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Indicá el motivo del cierre', 422);
        }

        $need = $this->get($companyId, $needId, $allowedOutletIds);
        if ($need === null) {
            throw new \InvalidArgumentException('Necesidad no encontrada', 404);
        }
        if ($need['status'] === 'closed') {
            throw new \RuntimeException('La necesidad ya está cerrada', 409);
        }

        global $db;
        $db->Execute(
            "UPDATE replenishment_need
                SET status = 'closed', closereason = ?, closedby = ?, closedat = now(), updatedat = now()
              WHERE needid = ? AND companyid = ? AND status <> 'closed'",
            [$reason, self::uuidOrNull($userId), $needId, $companyId]
        );

        realtimePublish('replenishment-need', 'update', $needId, 'all', $companyId);

        return $this->get($companyId, $needId, $allowedOutletIds) ?? [];
    }

    /**
     * Un documento que cubre necesidades cambió de estado (orden completada o
     * cancelada, transferencia cancelada): recalcula esas necesidades.
     *
     * Best-effort de punta a punta — todas sus sentencias van por
     * `DB::ExecuteBestEffort()`, así que se puede llamar DENTRO de la
     * transacción del documento sin poder tirarla: la cancelación de una
     * transferencia lo llama antes de revertir el stock (ver
     * `StockTransferService::cancel()`), y producción después de su commit.
     * El listado deriva lo cubierto del documento igual, así que lo peor que
     * deja un fallo es un estado atrasado, nunca una cantidad falsa.
     */
    public static function onSourceChanged(string $companyId, string $sourceType, string $sourceId): void
    {
        try {
            global $db;
            if (!is_object($db) || !method_exists($db, 'ExecuteBestEffort')) {
                return;
            }
            $rs = $db->ExecuteBestEffort(
                'SELECT DISTINCT needid FROM replenishment_need_coverage
                  WHERE companyid = ? AND sourcetype = ? AND sourceid = ?',
                [$companyId, $sourceType, $sourceId]
            );
            if ($rs === null) {
                return;
            }
            $svc = new self();
            while (!$rs->EOF) {
                $needId = (string) $rs->fields['needid'];
                $svc->recompute($companyId, $needId);
                realtimePublish('replenishment-need', 'update', $needId, 'all', $companyId);
                $rs->MoveNext();
            }
        } catch (\Throwable $e) {
            error_log('[replenishment] recálculo de cobertura ignorado (' . $sourceType . ' ' . $sourceId . '): ' . $e->getMessage());
        }
    }

    /**
     * open ↔ covered según lo efectivamente cubierto. Una necesidad cerrada a
     * mano no se toca. Best-effort (no lanza): devuelve el estado resultante,
     * o '' si no pudo leerla.
     *
     * Si una cubierta vuelve a faltar pero YA hay otra abierta para
     * (sucursal, ítem), queda cubierta: el faltante ya está a la vista en la
     * abierta, y reabrir violaría la unicidad. La cancelación de una
     * transferencia evita ese caso recalculando ANTES de revertir el stock.
     */
    public function recompute(string $companyId, string $needId): string
    {
        global $db;

        $rs = $db->ExecuteBestEffort(
            'SELECT n.outletid, n.itemid, n.quantity, n.status,
                    (SELECT COALESCE(SUM(x.effective), 0)
                       FROM (' . self::COVERAGE_SQL . ' WHERE c.needid = ? AND c.companyid = ?) x) AS covered
               FROM replenishment_need n
              WHERE n.needid = ? AND n.companyid = ?',
            [$needId, $companyId, $needId, $companyId]
        );
        if ($rs === null || $rs->EOF) {
            return '';
        }
        $row = $rs->fields;

        $status  = (string) $row['status'];
        $covered = (float) $row['covered'];
        $qty     = (float) $row['quantity'];

        if ($status === 'open' && $covered + 1e-9 >= $qty) {
            $ok = $db->ExecuteBestEffort(
                "UPDATE replenishment_need SET status = 'covered', coveredat = now(), updatedat = now()
                  WHERE needid = ? AND companyid = ? AND status = 'open'",
                [$needId, $companyId]
            );
            return $ok === null ? $status : 'covered';
        }

        if ($status === 'covered' && $covered + 1e-9 < $qty) {
            // El índice único parcial es el que decide: si ya hay otra
            // abierta, el UPDATE choca, el savepoint lo absorbe y la necesidad
            // queda cubierta.
            $ok = $db->ExecuteBestEffort(
                "UPDATE replenishment_need SET status = 'open', coveredat = NULL, updatedat = now()
                  WHERE needid = ? AND companyid = ? AND status = 'covered'
                    AND NOT EXISTS (SELECT 1 FROM replenishment_need o
                                     WHERE o.companyid = ? AND o.outletid = ? AND o.itemid = ?
                                       AND o.status = 'open')
                 RETURNING needid",
                [$needId, $companyId, $companyId, $row['outletid'], $row['itemid']]
            );
            return ($ok !== null && !$ok->EOF) ? 'open' : 'covered';
        }

        return $status;
    }

    private function requireCoverable(string $companyId, string $needId, ?array $allowedOutletIds): array
    {
        $need = $this->get($companyId, $needId, $allowedOutletIds);
        if ($need === null) {
            throw new \InvalidArgumentException('Necesidad no encontrada', 404);
        }
        if ($need['status'] !== 'open') {
            throw new \RuntimeException('La necesidad ya no está abierta', 409);
        }
        return $need;
    }

    private function resolveQty(array $need, ?float $qty): float
    {
        $qty = $qty ?? (float) $need['pending'];
        if ($qty <= 0) {
            throw new \InvalidArgumentException('La cantidad tiene que ser mayor a cero', 422);
        }
        return $qty;
    }

    /** Lock de la fila dentro de la TX: dos "Producir" simultáneos no se pisan. */
    private function lockOpen(string $companyId, string $needId): void
    {
        $row = ncmExecute(
            "SELECT needid FROM replenishment_need WHERE needid = ? AND companyid = ? AND status = 'open' FOR UPDATE",
            [$needId, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('La necesidad ya no está abierta', 409);
        }
    }

    private function link(string $companyId, string $needId, string $sourceType, string $sourceId, float $qty, string $userId): void
    {
        global $db;
        $db->Execute(
            'INSERT INTO replenishment_need_coverage (needid, companyid, sourcetype, sourceid, quantity, createdby)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$needId, $companyId, $sourceType, $sourceId, $qty, self::uuidOrNull($userId)]
        );
        $db->Execute(
            'UPDATE replenishment_need SET updatedat = now() WHERE needid = ? AND companyid = ?',
            [$needId, $companyId]
        );
    }

    // ── Infra ────────────────────────────────────────────────────────────────

    private static function uuidOrNull(?string $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) ? $v : null;
    }

    /**
     * El disparo corre dentro de la transacción de la venta: el aviso de
     * tiempo real se difiere al final del request, igual que el de stock en
     * `manageStock()`. Si la venta hizo rollback, el aviso llega igual y el
     * panel relee una lista que no cambió — inocuo.
     */
    private static function queueEvent(string $companyId, string $needId): void
    {
        self::$pendingEvents[$companyId][$needId] = true;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flushEvents']);
        }
    }

    public static function flushEvents(): void
    {
        $pending = self::$pendingEvents;
        self::$pendingEvents = [];
        foreach ($pending as $companyId => $ids) {
            try {
                if (function_exists('realtimePublish')) {
                    realtimePublish('replenishment-need', 'create', null, 'all', (string) $companyId, array_keys($ids));
                }
            } catch (\Throwable $e) {
                error_log('[replenishment] realtime ignorado: ' . $e->getMessage());
            }
        }
    }
}
