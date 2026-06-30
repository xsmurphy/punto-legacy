<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * OrderService — órdenes del POS (Slice 7 + cluster meta-JSONB).
 *
 * Lógica portada de app/action.php:
 *   acceptOrder    (L1286) — cambia status a 2 ("aceptada")
 *   transferOrderToOutlet (L575) — mueve la orden a otro outlet
 *   setUserToOrder (L1354) — asigna userId + actualiza ['user'] en transactionDetails (meta jsonb)
 *
 * Los side-effects (sendPush, sendWS, sendEmails, sendSMS) quedan en el caller
 * (api/v1/orders.php) para mantener el servicio puro de BD.
 *
 * Bug corregido en acceptOrder: el legacy enviaba enc($id) al WS pero $id no estaba
 * definido en ese scope → el WS recibía null. Se usa $transactionId directamente.
 *
 * Bugs PG corregidos:
 *   - identificadores SIN comillas (PG pliega a lowercase; las columnas reales son lowercase).
 *   - COMPANY_ID interpolado sin comillas en WHERE → bindeado.
 *   - transactionDetails se lee/escribe vía meta (jsonb) con los helpers txMeta* (§22.6).
 */

require_once __DIR__ . '/../meta_transaction.php';

final class OrderService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * ¿El cliente tiene órdenes abiertas en este outlet? (customerHasOrders, load.php L1634).
     * Orden = transaction type 12 con status != 4 (no finalizada). Sólo chequea existencia.
     */
    public function customerHasOpenOrders(string $companyId, string $outletId, string $customerId): bool
    {
        $row = ncmExecute(
            'SELECT transactionId FROM transaction
              WHERE companyId = ? AND outletId = ? AND transactionType = 12
                AND transactionStatus != 4 AND customerId = ?
              LIMIT 1',
            [$companyId, $outletId, $customerId]
        );
        return (bool) $row;
    }

    /**
     * Acepta una orden: transactionStatus → 2.
     *
     * @return array{ok:bool, customerId:string|null, invoiceNo:string|null}
     *   Si ok=true, retorna customerId e invoiceNo para que el caller envíe
     *   push/WS/email/SMS al cliente.
     */
    public function accept(string $transactionId, string $companyId): array
    {
        global $db;

        $res = $db->Execute(
            'UPDATE transaction
                SET transactionStatus = 2, updated_at = NOW()
              WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );

        if ($res === false) {
            return ['ok' => false, 'customerId' => null, 'invoiceNo' => null];
        }

        // Leer datos del cliente e invoiceNo para los side-effects del caller.
        // Si la fila no existe (transactionId foráneo o de otro tenant), retorna ok:false
        // para evitar disparar notificaciones sobre datos inexistentes.
        $row = ncmExecute(
            'SELECT customerId, invoiceNo
               FROM transaction
              WHERE transactionId = ? AND companyId = ?
              LIMIT 1',
            [$transactionId, $companyId]
        );

        if (!$row) {
            return ['ok' => false, 'customerId' => null, 'invoiceNo' => null];
        }

        return [
            'ok'         => true,
            'customerId' => $row['customerId'] ?? null,
            'invoiceNo'  => $row['invoiceNo']  ?? null,
        ];
    }

    /**
     * Transfiere una orden a otro outlet (transferOrderToOutlet, action.php L575).
     * Sólo toca la columna real `outletId` (sin transactionDetails).
     *
     * Valida que la orden y el outlet destino existan y pertenezcan al tenant antes
     * de mover, para no dejar la orden en un outlet inexistente.
     *
     * @return array{ok:bool, reason:string|null}
     *   reason ∈ {null, 'order_not_found', 'outlet_not_found', 'update_failed'}
     */
    public function transferToOutlet(string $orderId, string $targetOutletId, string $companyId): array
    {
        global $db;

        $order = ncmExecute(
            'SELECT transactionId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$orderId, $companyId]
        );
        if (!$order) {
            return ['ok' => false, 'reason' => 'order_not_found'];
        }

        $outlet = ncmExecute(
            'SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
            [$targetOutletId, $companyId]
        );
        if (!$outlet) {
            return ['ok' => false, 'reason' => 'outlet_not_found'];
        }

        $res = $db->Execute(
            'UPDATE transaction SET outletId = ? WHERE transactionId = ? AND companyId = ?',
            [$targetOutletId, $orderId, $companyId]
        );
        if ($res === false) {
            return ['ok' => false, 'reason' => 'update_failed'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Asigna un usuario (mozo/responsable) a una orden (type 12): setea la columna userId
     * y el campo ['user'] de cada item en transactionDetails (que vive en meta jsonb).
     *
     * @return array{ok:bool, invoiceNo:string|null}  invoiceNo para el mensaje del push.
     */
    public function assignUser(string $transactionId, string $companyId, string $userId): array
    {
        global $db;

        // Verificar que la orden exista (type 12) + obtener invoiceNo para el push.
        $row = ncmExecute(
            'SELECT invoiceNo FROM transaction
              WHERE transactionId = ? AND transactionType = 12 AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return ['ok' => false, 'invoiceNo' => null];
        }

        // 1. Columna userId.
        $db->Execute(
            'UPDATE transaction SET userId = ?
              WHERE transactionId = ? AND transactionType = 12 AND companyId = ?',
            [$userId, $transactionId, $companyId]
        );

        // 2. ['user'] en cada item de transactionDetails (meta jsonb), preservando otras keys.
        $details = txDetailsFromMeta(txMetaRead($transactionId, $companyId, 12));
        if (!empty($details)) {
            foreach ($details as $k => $_) {
                $details[$k]['user'] = $userId;
            }
            txDetailsWrite($transactionId, $companyId, $details, 12);
        }

        return ['ok' => true, 'invoiceNo' => $row['invoiceNo'] ?? null];
    }

    // -------------------------------------------------------------------------
    // Slice 27 — ordersList (load.php L1396 + L3097)
    // -------------------------------------------------------------------------

    /**
     * Carga las filas de transacción para una mesa/cliente/orden (shared core).
     * Devuelve el DBResult y el identificador de tabla (string vacío para customer/any).
     *
     * @return array{0: DBResult|false, 1: string}
     */
    private function queryOrderRows(string $t, string $kind, string $outletId): array
    {
        global $db;

        if ($kind === 'customer') {
            $rs    = $db->Execute(
                'SELECT * FROM transaction
                  WHERE outletId = ? AND transactionType = 12
                    AND transactionStatus IN (0,1,2,3,5) AND customerId = ?
                  LIMIT 500',
                [$outletId, $t]
            );
            $table = '';
        } elseif ($kind === 'any') {
            $rs    = $db->Execute(
                'SELECT * FROM transaction WHERE outletId = ? AND transactionId = ? LIMIT 1',
                [$outletId, $t]
            );
            $table = '';
        } else {
            $rs    = $db->Execute(
                'SELECT * FROM transaction
                  WHERE outletId = ? AND transactionType = 12
                    AND transactionStatus IN (0,1,2,3,5) AND transactionName = ?
                  LIMIT 500',
                [$outletId, $t]
            );
            $table = $t;
        }

        return [$rs, $table];
    }

    /**
     * Ítems de una mesa/orden agrupados para cierre (json mode, callsites L6806/L6835).
     * Retorna { items, tags, ids }.
     */
    public function getTableClose(string $t, string $kind, string $outletId, string $companyId): array
    {
        [$rs, $table] = $this->queryOrderRows($t, $kind, $outletId);

        $orderAsoc = [];
        $tags      = [];
        $ids       = [];

        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f    = $rs->fields;
                $name = (string) ($f['transactionName'] ?? '');

                if (in_array($name, [$table, 'ecom'])) {
                    $meta    = json_decode($f['meta'] ?? '{}', true) ?: [];
                    $unJson  = json_decode($meta['transactionDetails'] ?? '[]', true) ?: [];
                    $tags[]  = json_decode($meta['tags'] ?? '[]', true) ?: [];
                    $ids[]   = enc($f['transactionId']);

                    foreach (array_keys($unJson) as $k) {
                        $unJson[$k]['date'] = $f['transactionDate'];
                    }
                    $orderAsoc[$f['transactionId']] = json_encode($unJson);
                }

                $rs->MoveNext();
            }
        }

        $orders = groupOrdersItems($orderAsoc);
        $items  = [];

        foreach ($orders as $val) {
            foreach ($val as $v) {
                if (arrKey($v, 'status', 1) == 1 && validity($v)) {
                    $items[] = $v;
                }
            }
        }

        $ttags = array_flatten($tags);

        if ($kind === 'table' || $kind === 'customer') {
            $merged = [];
            foreach ($items as $item) {
                $pos = array_search($item['itemId'], array_column($merged, 'itemId'));
                if ($pos > -1) {
                    if (in_array($item['type'], ['product','production','direct_production','dynamic'])
                        && $merged[$pos]['type'] !== 'inCombo') {
                        $merged[$pos]['count'] += $item['count'];
                        $merged[$pos]['oQty']  += $item['oQty'];
                        $merged[$pos]['total'] += $item['total'];
                    } else {
                        $merged[] = $item;
                    }
                } else {
                    $merged[] = $item;
                }
            }
            $items = $merged;
        }

        return ['items' => $items, 'tags' => $ttags, 'ids' => $ids];
    }

    /**
     * Detalle de ítems de una mesa/orden para vista modal (non-json mode, callsite L7495).
     * Retorna { data, title, subTitle, orderId, type }.
     */
    public function getTableDetail(string $t, string $kind, string $outletId, string $companyId): array
    {
        global $dec, $ts;

        [$rs, $table] = $this->queryOrderRows($t, $kind, $outletId);

        $orderAsoc = [];
        $orderNo   = null;

        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f    = $rs->fields;
                $name = (string) ($f['transactionName'] ?? '');

                if (in_array($name, [$table, 'ecom'])) {
                    $orderNo = $f['invoiceNo'];
                    $meta    = json_decode($f['meta'] ?? '{}', true) ?: [];
                    $unJson  = json_decode($meta['transactionDetails'] ?? '[]', true) ?: [];

                    foreach (array_keys($unJson) as $k) {
                        $unJson[$k]['date'] = $f['transactionDate'];
                    }
                    $orderAsoc[$f['transactionId']] = json_encode($unJson);
                }

                $rs->MoveNext();
            }
        }

        $orders = groupOrdersItems($orderAsoc);

        if (!$orders) {
            return ['type' => $kind];
        }

        $allUsers = getAllContacts(0);
        $allUsers = $allUsers[1];

        $title    = '';
        $subTitle = '';
        $orderId  = $t;

        if ($kind === 'customer') {
            $customerData = getContactData($t, 'uid');
            $title        = getCustomerName($customerData);
            $subTitle     = '';
            $orderId      = enc($t);
        } elseif ($kind === 'any') {
            $title    = 'Orden #' . $orderNo;
            $subTitle = '';
        } else {
            $tableOp     = ncmExecute(
                'SELECT * FROM transaction WHERE outletId = ? AND transactionType = 11 AND transactionName = ? LIMIT 1',
                [$outletId, $table]
            );
            $usrOpenName = $tableOp ? ($allUsers[$tableOp['userId']]['name'] ?? '') : '';
            $running     = $tableOp ? niceDate2($tableOp['transactionDate'], 'small') : '';
            $title       = $tableOp ? iftn($tableOp['transactionNote'], 'Espacio ' . $table) : 'Espacio ' . $table;
            $subTitle    = 'Por ' . $usrOpenName . ' hace ' . $running;
            $orderId     = $table;
        }

        $data = [];
        $i    = 0;

        foreach ($orders as $trsId => $order) {
            $oi = 0;
            foreach ($order as $item) {
                $strike = '';
                $hideX  = '';

                if (($item['type'] ?? '') === 'inCombo') {
                    $hideX = 'hidden';
                }

                if (array_key_exists('status', $item)) {
                    $strike = ($item['status'] == 2) ? 'text-muted' : 'text-l-t text-muted';
                    $hideX  = 'hidden';
                }

                $rawPrice = ($item['price'] ?? 0) * ($item['count'] ?? 1);
                if ($strike) {
                    $rawPrice = 0;
                }

                $itemRow = ncmExecute(
                    'SELECT itemName FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                    [dec($item['itemId'] ?? ''), $companyId]
                );

                $data[] = [
                    'index'         => $i,
                    'oindex'        => $oi,
                    'strike'        => $strike,
                    'hideX'         => $hideX,
                    'itemId'        => $item['itemId'] ?? '',
                    'count'         => $item['count'] ?? 0,
                    'note'          => iftn($item['note'] ?? false),
                    'unitPrice'     => formatCurrentNumber($item['price'] ?? 0, $dec, $ts),
                    'price'         => formatCurrentNumber($rawPrice, $dec, $ts),
                    'rawPrice'      => $rawPrice,
                    'name'          => toUTF8($itemRow ? ($itemRow['itemName'] ?? '') : ''),
                    'tagsList'      => iftn(printOutTags($item['tags'] ?? [], 'bg-light'), ''),
                    'userName'      => $allUsers[dec($item['user'] ?? '')] ['name'] ?? '',
                    'date'          => niceDate2($item['date'] ?? '', 'small'),
                    'type'          => $item['type'] ?? '',
                    'orderNo'       => $orderNo,
                    'transactionId' => enc($trsId),
                ];
                $i++;
                $oi++;
            }
        }

        return [
            'data'     => $data,
            'title'    => $title,
            'subTitle' => $subTitle,
            'orderId'  => $orderId,
            'type'     => $kind,
        ];
    }

    /**
     * Lista paginada de órdenes (load.php L3097, buildList callsite L11691).
     * Retorna { date, listName, transactionsList, footBtn }.
     */
    public function getList(
        string  $outletId,
        string  $companyId,
        ?string $encCustomerId,
        ?string $date,
        int     $limit
    ): array {
        global $db, $dec, $ts;

        $where  = ['transactionType = 12', 'companyId = ?'];
        $params = [$companyId];

        if ($encCustomerId) {
            $where[]  = 'customerId = ?';
            $params[] = dec($encCustomerId);
        } else {
            $where[]  = 'outletId = ?';
            $params[] = $outletId;
        }

        if ($date) {
            $where[]  = 'transactionDate BETWEEN ? AND ?';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 23:59:59';
            $limit    = 1500;
        }

        $sql = 'SELECT * FROM transaction WHERE ' . implode(' AND ', $where)
             . ' ORDER BY transactionDate DESC LIMIT ' . (int) $limit;
        $rs  = $db->Execute($sql, $params);

        $out = [
            'date'             => $date,
            'listName'         => 'Órdenes',
            'transactionsList' => [],
            'footBtn'          => '',
        ];

        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f       = $rs->fields;
                $status  = (string) ($f['transactionStatus'] ?? '');
                $cusData = getCustomerData($f['customerId'] ?? null, 'uid');
                $cusName = iftn(getCustomerName($cusData), 'Sin Nombre');

                $stat = 'b-5x b-l ';
                switch ($status) {
                    case '0': case '1': $stat .= 'b-light';   break;
                    case '2':           $stat .= 'b-warning';  break;
                    case '3':           $stat .= 'b-info';     break;
                    case '4':           $stat .= 'b-success';  break;
                    case '5':           $stat .= 'b-dark';     break;
                    case '6':           $stat .= 'b-danger';   break;
                }

                $out['transactionsList'][] = [
                    'id'          => enc($f['transactionId']),
                    'title'       => ' ' . $cusName,
                    'date'        => niceDate($f['transactionDate']),
                    'docNumber'   => ' #' . $f['invoicePrefix'] . $f['invoiceNo'],
                    'amount'      => formatCurrentNumber($f['transactionTotal'], $dec, $ts),
                    'label'       => '<span class="label bg-success text-xs">Orden</span>',
                    'type'        => $f['transactionType'],
                    'borderColor' => $stat,
                ];

                $rs->MoveNext();
            }

            $out['footBtn'] = $date
                ? '<a href="#openOrders&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>'
                : '<a href="#openOrders&l=' . ($limit + 50) . '&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</span>';
        } else {
            $out['footBtn'] = '<a href="#openOrders&c=' . $encCustomerId . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</span>';
        }

        return $out;
    }

    /**
     * Lista del panel de órdenes (Slice 40 — strangler de `load=ordersPanelAPI`).
     *
     * Devuelve las órdenes (transactionType=12) del outlet+tenant del JWT con status
     * abierto (0,1,2,3,5), del último mes hasta $date, ordenadas DESC por fecha.
     *
     * Shape ya mapeado al que consume el front (`ncmOrders.panel.allOrders`):
     *   { id, table, orderNo, source, status, note, userId, customerId, customerName,
     *     statusColor, statusName, created, createdDate, orderDue, tags, address, city,
     *     location, lat, lng }
     *
     * Paridad con legacy:
     *   - customerName ← COALESCE(NULLIF(contactSecondName,''), contactName) (panel/API/get_orders.php:163)
     *   - address/city/location/lat/lng ← `customerAddress` per-orden via `toAddress` JOIN
     *   - statusColor/statusName ← maps fijos del legacy (panel/API/get_orders.php:28-29)
     *   - source ← derivado de transactionName ('table' si numérico, 'ecom' si 'ecom', false sino)
     *   - tags ← json_decode(meta->>'tags') (§22.6 demotion)
     *   - El compare-timestamp del legacy (`lastChk` vs /get_last_update.php) NO se
     *     reimplementa: la columna `orderLastUpdate` fue demotada en Phase PG y nunca
     *     funcionó en PG → en prod siempre se fetcha; este método mantiene esa paridad.
     *
     * NOTA: NO incluye el filtrado por registerId del legacy (`getROC()` agrega registerId
     * cuando viene). El panel del POS muestra TODAS las órdenes del outlet, por eso el
     * scope acá es companyId + outletId (el registerId vendría a restringir más, pero el
     * front quiere ver órdenes de todas las cajas del outlet).
     *
     * @param ?string $orderId  filtrar a una única transacción (opcional).
     * @param ?string $date     end date (default: today 23:59:59).
     */
    public function getPanelList(string $companyId, string $outletId, ?string $orderId, ?string $date): array
    {
        global $db;

        $from = date('Y-m-d H:i:s', strtotime('-1 month'));
        $to   = ($date && $date !== '') ? $date : date('Y-m-d 23:59:59');

        $sql = "SELECT t.transactionId, t.transactionName, t.transactionStatus,
                       t.transactionNote, t.transactionDate, t.transactionDueDate,
                       t.userId, t.customerId, t.invoiceNo, t.meta,
                       COALESCE(NULLIF(c.data->>'contactSecondName',''), c.contactName) AS customerName,
                       ca.customerAddressText     AS customerAddress,
                       ca.customerAddressCity     AS customerCity,
                       ca.customerAddressLocation AS customerLocation,
                       ca.customerAddressLat      AS customerLat,
                       ca.customerAddressLng      AS customerLng
                  FROM transaction t
             LEFT JOIN contact         c  ON c.contactId         = t.customerId    AND c.companyId  = t.companyId
             LEFT JOIN toAddress       ta ON ta.transactionId    = t.transactionId
             LEFT JOIN customerAddress ca ON ca.customerAddressId = ta.customerAddressId AND ca.companyId = t.companyId
                 WHERE t.companyId       = ?
                   AND t.outletId        = ?
                   AND t.transactionType = 12
                   AND t.transactionStatus IN (0,1,2,3,5)
                   AND t.transactionDate BETWEEN ? AND ?";
        $params = [$companyId, $outletId, $from, $to];

        if ($orderId !== null && $orderId !== '') {
            $sql      .= ' AND t.transactionId = ?';
            $params[]  = $orderId;
        }

        $sql .= ' ORDER BY t.transactionDate DESC LIMIT 80';

        $rs = $db->Execute($sql, $params);
        if ($rs === false || $rs->EOF) {
            return [];
        }

        // Maps fijos legacy.
        $statusName = ['0' => 'Pendiente', '1' => 'Pendiente', '2' => 'En Espera', '3' => 'En Proceso', '4' => 'Finalizado', '5' => 'Enviado', '6' => 'Cancelado'];
        $statusCol  = ['0' => 'light',     '1' => 'light',     '2' => 'warning',   '3' => 'info',       '4' => 'success',    '5' => 'dark',    '6' => 'danger'];

        $out = [];
        while (!$rs->EOF) {
            $f = $rs->fields;

            $name = (string) ($f['transactionName'] ?? '');
            if (is_numeric($name)) {
                $source = 'table';
            } elseif ($name === 'ecom') {
                $source = 'ecom';
                $name   = '';
            } else {
                $source = false;
                $name   = '';
            }

            // tags vive en meta.tags (jsonb, §22.6). Lectura tolerante a forma.
            $tags = [];
            $meta = $f['meta'] ?? null;
            if (is_string($meta) && $meta !== '') {
                $m = json_decode($meta, true);
                if (is_array($m) && isset($m['tags'])) {
                    $raw = is_string($m['tags']) ? json_decode($m['tags'], true) : $m['tags'];
                    if (is_array($raw)) {
                        $tags = $raw;
                    }
                }
            }

            $status = (string) ($f['transactionStatus'] ?? '0');
            $date   = (string) ($f['transactionDate']   ?? '');

            $out[] = [
                'id'           => $f['transactionId'],
                'table'        => $name,
                'orderNo'      => $f['invoiceNo']     ?? '',
                'source'       => $source,
                'status'       => $status,
                'note'         => $f['transactionNote'] ?? '',
                'userId'       => $f['userId']        ?? '',
                'customerId'   => $f['customerId']    ?? '',
                'customerName' => $f['customerName']  ?? '',
                'statusColor'  => $statusCol[$status]  ?? '',
                'statusName'   => $statusName[$status] ?? '',
                'created'      => $date !== '' ? strtotime($date) : 0,
                'createdDate'  => $date,
                'orderDue'     => $f['transactionDueDate'] ?? null,
                'tags'         => $tags,
                'address'      => $f['customerAddress']  ?? '',
                'city'         => $f['customerCity']     ?? '',
                'location'     => $f['customerLocation'] ?? '',
                'lat'          => isset($f['customerLat']) && $f['customerLat'] !== null ? (float) $f['customerLat'] : null,
                'lng'          => isset($f['customerLng']) && $f['customerLng'] !== null ? (float) $f['customerLng'] : null,
            ];

            $rs->MoveNext();
        }

        return $out;
    }

    /**
     * Próxima orden en camino (delivery) asignada a un usuario, dentro del outlet+tenant.
     *
     * Reemplaza el call legacy a `panel/API/get_orders.php` con params {type=12, status=5,
     * limit=1, order=ASC, customerdata=1} que hacía el handler `load=userLocation`.
     *
     * Paridad con el legacy (panel/API/get_orders.php:163-256):
     *   - customerName ← iftn(contactSecondName, contactName)
     *   - address/lat/lng ← `customerAddress` (per-order delivery address), no `contact.contactLatLng`
     *     (default del cliente). El JOIN: transaction → toAddress (por orden) → customerAddress.
     *   - Si la orden no tiene toAddress asociado, lat/lng/address quedan null/''.
     *
     * Retorna null si no hay órdenes status=5 asignadas al usuario.
     */
    public function getNextDeliveryForUser(string $userId, string $companyId, string $outletId): ?array
    {
        $from = date('Y-m-d H:i:s', strtotime('-1 month'));
        $to   = date('Y-m-d 23:59:59');

        $row = ncmExecute(
            "SELECT t.transactionId, t.invoiceNo, t.customerId,
                    COALESCE(NULLIF(c.data->>'contactSecondName',''), c.contactName) AS customerName,
                    ca.customerAddressText AS customerAddress,
                    ca.customerAddressLat  AS customerLat,
                    ca.customerAddressLng  AS customerLng
               FROM transaction t
          LEFT JOIN contact         c  ON c.contactId         = t.customerId    AND c.companyId  = t.companyId
          LEFT JOIN toAddress       ta ON ta.transactionId    = t.transactionId
          LEFT JOIN customerAddress ca ON ca.customerAddressId = ta.customerAddressId AND ca.companyId = t.companyId
              WHERE t.companyId       = ?
                AND t.outletId        = ?
                AND t.userId          = ?
                AND t.transactionType = 12
                AND t.transactionStatus = 5
                AND t.transactionDate BETWEEN ? AND ?
              ORDER BY t.transactionDate ASC
              LIMIT 1",
            [$companyId, $outletId, $userId, $from, $to]
        );

        if (!$row) {
            return null;
        }

        return [
            'id'           => $row['transactionId'],
            'orderNo'      => $row['invoiceNo']       ?? '',
            'customerId'   => $row['customerId']      ?? '',
            'customerName' => $row['customerName']    ?? '',
            'address'      => $row['customerAddress'] ?? '',
            'lat'          => isset($row['customerLat']) && $row['customerLat'] !== null ? (float) $row['customerLat'] : null,
            'lng'          => isset($row['customerLng']) && $row['customerLng'] !== null ? (float) $row['customerLng'] : null,
        ];
    }
}
