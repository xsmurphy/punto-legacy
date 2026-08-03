<?php
declare(strict_types=1);

namespace Punto\Api\Purchases;

/**
 * Servicio de compras del panel.
 *
 * Compras viven en la misma tabla `transaction` que ventas, distinguidas por
 * `transactionType`:
 *   1 = compra al CONTADO (pagada al crearse, `transactionComplete = true`)
 *   4 = compra a CRÉDITO (cuenta por pagar, `transactionComplete = false`
 *       hasta que los pagos a proveedor —type 5 con `transactionParentId`—
 *       la salden). Es la semántica que ya asumían Reports/PurchasesService
 *       (debt/canAddPayment) y Reports/OpenInvoicesService (state='outcome').
 *
 * El docstring viejo decía "4 = orden de compra": era incorrecto, no existe
 * ninguna orden de compra en el modelo.
 *
 * Una compra tiene N líneas: cada una es un "item" que puede ser
 *   (a) un producto con `itemId` → se inserta en `itemSold` + se incrementa
 *       stock vía Inventory::manageStock.
 *   (b) un gasto libre sin `itemId` → solo vive en `transaction.meta.details`
 *       (NO se inserta en itemSold porque la FK es NOT NULL).
 *
 * Port FIEL (alcance reducido) de panel/a_purchase.php?action=insert: misma
 * lógica de totales, prefix factura, payment type JSON, manageStock por item.
 *
 * Multi-tenant: $companyId + $outletId siempre se pasan explícitos. Servicios
 * llamantes nunca usan globals.
 */
final class PurchasesService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Lista compras con paginación + filtros básicos. Devuelve array con
     * `rows` (resumen por fila) + `total` (count total para paginación).
     *
     * @param array{from?:string,to?:string,supplierId?:string,outletId?:string,limit?:int,offset?:int} $filters
     */
    public function list(string $companyId, string $roc, array $filters = []): array
    {
        $params = [];
        // Excluir compras anuladas (transactionStatus=6) del listado de reportes.
        // type 1 = contado, type 4 = crédito (cuenta por pagar). Ambos son
        // compras y deben verse en el listado.
        $where  = "transactionType IN (1,4) AND transactionStatus <> 6 {$roc}";

        if (!empty($filters['from'])) {
            $where    .= ' AND t.transactionDate >= ?';
            $params[]  = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where    .= ' AND t.transactionDate <= ?';
            $params[]  = $filters['to'];
        }
        if (!empty($filters['supplierId']) && preg_match(self::UUID_RE, $filters['supplierId'])) {
            $where    .= ' AND t.supplierId = ?';
            $params[]  = $filters['supplierId'];
        }
        $limit  = isset($filters['limit'])  ? max(1, min(200, (int) $filters['limit'])) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        // Sub-where con alias t. para el LEFT JOIN.
        $whereAliased = str_replace([' transactionType', ' transactionDate', ' companyId', ' outletId', ' supplierId'],
                                     [' t.transactionType', ' t.transactionDate', ' t.companyId', ' t.outletId', ' t.supplierId'],
                                     ' ' . $where);

        $totalRow = ncmExecute(
            "SELECT COUNT(*) AS n FROM transaction t WHERE {$whereAliased}",
            $params
        );
        $total = (int) ($totalRow['n'] ?? 0);

        $sql = "SELECT t.transactionId, t.transactionDate, t.transactionTotal, t.transactionTax,
                       t.transactionDiscount, t.transactionStatus, t.transactionDueDate,
                       t.transactionType, t.transactionComplete,
                       t.invoiceNo, t.invoicePrefix, t.supplierId, t.outletId,
                       c.contactName AS supplierName, o.outletName
                  FROM transaction t
                  LEFT JOIN contact c ON c.contactId = t.supplierId
                  LEFT JOIN outlet  o ON o.outletId  = t.outletId
                 WHERE {$whereAliased}
                 ORDER BY t.transactionDate DESC
                 LIMIT {$limit} OFFSET {$offset}";

        $res = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($res) {
            while (!$res->EOF) {
                $f = $res->fields;
                $rows[] = [
                    'id'           => (string) $f['transactionid'],
                    'date'         => (string) $f['transactiondate'],
                    'dueDate'      => $f['transactionduedate'] !== null ? (string) $f['transactionduedate'] : null,
                    'total'        => (float) $f['transactiontotal'],
                    'tax'          => $f['transactiontax'] !== null ? (float) $f['transactiontax'] : 0.0,
                    'discount'     => $f['transactiondiscount'] !== null ? (float) $f['transactiondiscount'] : 0.0,
                    'status'       => (int) $f['transactionstatus'],
                    'type'         => (int) $f['transactiontype'],
                    'condition'    => ((int) $f['transactiontype']) === 4 ? 'credit' : 'cash',
                    'complete'     => (bool) $f['transactioncomplete'],
                    'invoiceNo'    => $f['invoiceno'] !== null ? (int) $f['invoiceno'] : null,
                    'invoicePrefix' => $f['invoiceprefix'] !== null ? (string) $f['invoiceprefix'] : null,
                    'supplierId'   => $f['supplierid'] !== null ? (string) $f['supplierid'] : null,
                    'supplierName' => $f['suppliername'] !== null ? (string) $f['suppliername'] : null,
                    'outletId'     => (string) $f['outletid'],
                    'outletName'   => $f['outletname'] !== null ? (string) $f['outletname'] : null,
                ];
                $res->MoveNext();
            }
            $res->Close();
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Detalle completo de una compra. Incluye líneas (de meta.details) y supplier.
     */
    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        // userId en `transaction` es en realidad un contactId (mismo patrón que
        // Reports/UsersService.php) — de ahí el segundo LEFT JOIN a contact.
        $row = ncmExecute(
            // `t.meta::text AS meta_raw`: el wrapper aplana `meta` y borra la
            // columna (Query::flattenJsonb), asi que `$row['meta']` volvia null
            // y los items de la compra se perdian. El alias sobrevive al flatten.
            "SELECT t.*, t.meta::text AS meta_raw,
                    c.contactName AS supplierName, o.outletName,
                    u.contactName AS userName
               FROM transaction t
               LEFT JOIN contact c ON c.contactId = t.supplierId
               LEFT JOIN outlet  o ON o.outletId  = t.outletId
               LEFT JOIN contact u ON u.contactId = t.userId
              WHERE t.transactionId = ? AND t.companyId = ? AND t.transactionType IN (1,4)
              LIMIT 1",
            [$id, $companyId]
        );
        if (!$row) {
            return null;
        }
        // meta JSONB → array. Se lee del alias `meta_raw` (ver la query): la
        // columna `meta` no sobrevive al flatten del wrapper.
        $meta = $row['meta_raw'] ?? $row['meta'] ?? '{}';
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }
        $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];

        // transactionPaymentType es columna real de `transaction` desde la
        // mig 102 (_getTableSchema() en api/includes/functions.php ya la
        // lista — antes ncmInsert la enrutaba al JSONB `meta`, root-cause de
        // la Parte 2 del incidente 737M). El fallback a `meta` cubre compras
        // anteriores a la promoción de datos de esa misma migración, por si
        // alguna quedó fuera del backfill.
        $paymentType = $row['transactionpaymenttype'] ?? null;
        if ($paymentType === null || $paymentType === '') {
            $paymentType = $meta['transactionPaymentType'] ?? null;
        }
        if (is_string($paymentType) && $paymentType !== '') {
            $paymentType = json_decode($paymentType, true) ?: null;
        } elseif (!is_array($paymentType)) {
            $paymentType = null;
        }

        // Resolver nombres de producto para líneas con itemId pero title vacío
        // (el form de creación solo manda itemId, ver purchase/page.tsx).
        $itemIds = [];
        foreach ($details as $d) {
            $itemId = (string) ($d['itemId'] ?? '');
            if ($itemId !== '' && (string) ($d['title'] ?? '') === '') {
                $itemIds[$itemId] = true;
            }
        }
        if (!empty($itemIds)) {
            $ids = array_keys($itemIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $res = ncmExecute(
                "SELECT itemId, itemName FROM item WHERE companyId = ? AND itemId IN ({$placeholders})",
                array_merge([$companyId], $ids),
                false,
                true
            );
            $names = [];
            if ($res) {
                while (!$res->EOF) {
                    $f = $res->fields;
                    $names[(string) $f['itemid']] = (string) $f['itemname'];
                    $res->MoveNext();
                }
                $res->Close();
            }
            foreach ($details as &$d) {
                $itemId = (string) ($d['itemId'] ?? '');
                if ($itemId !== '' && (string) ($d['title'] ?? '') === '' && isset($names[$itemId])) {
                    $d['title'] = $names[$itemId];
                }
            }
            unset($d);
        }

        return [
            'id'            => (string) $row['transactionid'],
            'date'          => (string) $row['transactiondate'],
            'dueDate'       => $row['transactionduedate'] !== null ? (string) $row['transactionduedate'] : null,
            'total'         => (float) $row['transactiontotal'],
            'tax'           => $row['transactiontax'] !== null ? (float) $row['transactiontax'] : 0.0,
            'discount'      => $row['transactiondiscount'] !== null ? (float) $row['transactiondiscount'] : 0.0,
            'units'         => $row['transactionunitssold'] !== null ? (float) $row['transactionunitssold'] : 0.0,
            'status'        => (int) $row['transactionstatus'],
            'type'          => (int) $row['transactiontype'],
            'condition'     => ((int) $row['transactiontype']) === 4 ? 'credit' : 'cash',
            'complete'      => (bool) $row['transactioncomplete'],
            'invoiceNo'     => $row['invoiceno'] !== null ? (int) $row['invoiceno'] : null,
            'invoicePrefix' => $row['invoiceprefix'] !== null ? (string) $row['invoiceprefix'] : null,
            'note'          => $row['transactionnote'] ?? null,
            'supplierId'    => $row['supplierid'] !== null ? (string) $row['supplierid'] : null,
            'supplierName'  => $row['suppliername'] !== null ? (string) $row['suppliername'] : null,
            'outletId'      => (string) $row['outletid'],
            'outletName'    => $row['outletname'] !== null ? (string) $row['outletname'] : null,
            'userId'        => $row['userid'] !== null ? (string) $row['userid'] : null,
            'userName'      => $row['username'] !== null ? (string) $row['username'] : null,
            'paymentType'   => $paymentType,
            'details'       => $details,
        ];
    }

    /**
     * Crear una compra. Lógica:
     *   - Valida UUIDs y campos requeridos.
     *   - Inicia TX. Inserta `transaction` (status=1; type 1 contado / 4 crédito).
     *   - Por cada item: si tiene itemId → inserta en `itemSold` + manageStock;
     *     si no tiene itemId → solo guarda en meta.details (gasto libre).
     *   - Commit. Devuelve transactionId.
     *
     * @param array{
     *   supplierId?:string|null,
     *   outletId:string,
     *   userId:string,
     *   condition?:'cash'|'credit',
     *   invoiceDate?:string,
     *   dueDate?:string,
     *   invoiceNo?:int|string|null,
     *   invoicePrefix?:string,
     *   authNo?:string,
     *   discount?:float|string,
     *   paymentMethod?:string,
     *   note?:string,
     *   items:array<int,array<string,mixed>>
     * } $data
     *
     * @throws \RuntimeException si falla la inserción.
     */
    public function create(string $companyId, array $data): string
    {
        global $db;

        if (!preg_match(self::UUID_RE, $companyId)) {
            throw new \RuntimeException('companyId inválido');
        }
        $outletId = (string) ($data['outletId'] ?? '');
        if (!preg_match(self::UUID_RE, $outletId)) {
            throw new \RuntimeException('outletId requerido y debe ser UUID');
        }
        $userId = (string) ($data['userId'] ?? '');
        if (!preg_match(self::UUID_RE, $userId)) {
            throw new \RuntimeException('userId requerido');
        }
        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new \RuntimeException('Debe agregar al menos un ítem');
        }

        $supplierId = (string) ($data['supplierId'] ?? '');
        if ($supplierId !== '' && !preg_match(self::UUID_RE, $supplierId)) {
            throw new \RuntimeException('supplierId inválido');
        }
        $supplierId = $supplierId === '' ? null : $supplierId;

        // Condición de la factura. Default 'cash' por back-compat: cualquier
        // caller viejo (drafts, integraciones) sigue creando compras contado.
        $condition = strtolower(trim((string) ($data['condition'] ?? 'cash')));
        if ($condition === '') {
            $condition = 'cash';
        }
        if (!in_array($condition, ['cash', 'credit'], true)) {
            throw new \RuntimeException("condition inválida: debe ser 'cash' o 'credit'");
        }
        $isCredit = $condition === 'credit';

        $invoiceDate = $this->normalizeDate($data['invoiceDate'] ?? null, true);
        $dueDate     = $this->normalizeDate($data['dueDate'] ?? null, false);
        // Una cuenta por pagar sin vencimiento no entra en Previsiones
        // (ObligationsService filtra transactionDueDate IS NOT NULL), así que
        // el vencimiento es obligatorio cuando la compra es a crédito.
        if ($isCredit && $dueDate === null) {
            throw new \RuntimeException('El vencimiento es obligatorio en una compra a crédito');
        }
        $discount    = (float) ($data['discount'] ?? 0);
        $note        = (string) ($data['note'] ?? '');
        $authNo      = (string) ($data['authNo'] ?? '');
        $prefix      = (string) ($data['invoicePrefix'] ?? '');
        $invoiceNo   = isset($data['invoiceNo']) && $data['invoiceNo'] !== '' ? (int) $data['invoiceNo'] : null;
        // paymentMethodId: taxonomyId real del medio de pago (igual que ventas —
        // resuelve cuenta vía finAccountMap en FinanceLedger). Se acepta el legacy
        // `paymentMethod` (slug) como fallback para no romper integraciones viejas;
        // PaymentMethodResolver matchea ambos caminos igual.
        $payMethod    = trim((string) ($data['paymentMethodId'] ?? $data['paymentMethod'] ?? ''));
        $checkNumber  = trim((string) ($data['checkNumber'] ?? ''));
        $checkBank    = trim((string) ($data['checkBank'] ?? ''));
        $checkDueDate = (string) ($data['checkDueDate'] ?? '');

        // Procesar items: calcular totales + separar productos vs gastos libres.
        $totalUnits = 0.0;
        $total      = 0.0;
        $totalTax   = 0.0;
        $details    = [];
        foreach ($items as $idx => $it) {
            $units    = (float) ($it['units'] ?? 0);
            $price    = (float) ($it['price'] ?? 0);
            $taxValue = (float) ($it['taxValue'] ?? 0);
            $taxId    = (string) ($it['taxId'] ?? '');
            $title    = (string) ($it['title'] ?? '');
            $itemId   = (string) ($it['itemId'] ?? '');
            // packSize: unidades por paquete/caja. `units` es la cantidad de
            // paquetes cargada por el cajero; el stock/itemSold se registra en
            // unidades reales (units * packSize) — ver context/_feature-requests.md.
            $packSize = isset($it['packSize']) ? (int) $it['packSize'] : 1;
            if ($packSize < 1) {
                throw new \RuntimeException("Item #{$idx}: packSize debe ser >= 1");
            }

            if ($units <= 0 || $price < 0) {
                continue;
            }
            // Una línea es válida si tiene producto O descripción libre (cumple
            // ambas no es problema, el title queda como override en itemSold).
            if ($itemId === '' && $title === '') {
                continue;
            }
            if ($itemId !== '' && !preg_match(self::UUID_RE, $itemId)) {
                throw new \RuntimeException("Item #{$idx} tiene itemId no-UUID");
            }

            // lineTotal es lo pagado por la línea (precio de paquete × cantidad
            // de paquetes) — NO cambia con packSize. effectiveUnits es lo que se
            // registra en itemSold/stock, así el costo unitario real termina
            // siendo lineTotal / effectiveUnits = price / packSize.
            $effectiveUnits = $units * $packSize;
            $lineTotal      = abs($price * $units);
            $total         += $lineTotal;
            $totalTax      += $taxValue;
            $totalUnits    += $effectiveUnits;

            $details[]   = [
                'itemId'   => $itemId,
                'title'    => $title,
                'qty'      => $effectiveUnits,
                'price'    => $lineTotal,
                'tax'      => $taxValue,
                'taxId'    => $taxId,
                'packSize' => $packSize,
            ];
        }
        if (count($details) === 0) {
            throw new \RuntimeException('Ningún ítem válido (units > 0 + producto o descripción)');
        }

        $netTotal = $total - $discount;

        // Payment type — mismo shape que ventas ({type,name,total,identifier?}),
        // para que FinanceLedger::recordPaymentLines/createCheckFromLines lo lean
        // con la misma lógica (resolver la Parte 2 de raíz: antes esto era
        // {type,price} y no lo alimentaba nada además de recordPurchase). `name`
        // se resuelve server-side — nunca se confía el label del cliente.
        // A crédito NO hay pago al crear la compra: la línea de pago nace con
        // el pago a proveedor (type 5). Sin línea, FinanceLedger no mueve plata.
        $paymentTypeJson = null;
        if ($isCredit) {
            $payMethod = '';
        }
        if ($payMethod !== '') {
            $methodName = getPaymentMethodName($payMethod) ?: $payMethod;
            $line = ['type' => $payMethod, 'name' => $methodName, 'total' => $netTotal];
            if ($checkNumber !== '') {
                $line['identifier'] = $checkNumber;
            }
            if ($checkBank !== '') {
                $line['bankName'] = $checkBank;
            }
            if ($checkDueDate !== '') {
                $normalizedCheckDue = $this->normalizeDate($checkDueDate, false);
                if ($normalizedCheckDue !== null) {
                    $line['dueDate'] = $normalizedCheckDue;
                }
            }
            $paymentTypeJson = json_encode([$line]);
        }

        // Prefix del legacy: "authNo;prefix" — preservado para compat de
        // facturación electrónica que puede parsear ese shape.
        $combinedPrefix = ($authNo !== '' || $prefix !== '') ? ($authNo . ';' . $prefix) : null;

        $meta = [
            'details' => $details,
        ];

        $db->StartTrans();

        $transactionId = ncmInsert([
            'records' => [
                'transactionDate'       => $invoiceDate,
                'transactionDueDate'    => $dueDate,
                // 1 = contado (cerrada), 4 = crédito (pendiente → cuentas por
                // pagar + previsiones hasta que los pagos type 5 la salden).
                'transactionType'       => $isCredit ? 4 : 1,
                'transactionStatus'     => 1,
                'transactionComplete'   => $isCredit ? 0 : 1,
                'transactionNote'       => $note,
                'transactionTotal'      => $netTotal,
                'transactionDiscount'   => $discount,
                'transactionTax'        => $totalTax,
                'transactionUnitsSold'  => $totalUnits,
                'transactionPaymentType' => $paymentTypeJson,
                'invoiceNo'             => $invoiceNo,
                'invoicePrefix'         => $combinedPrefix,
                'userId'                => $userId,
                'supplierId'            => $supplierId,
                'outletId'              => $outletId,
                'companyId'             => $companyId,
                // json_encode explícito: meta es columna jsonb real; ncmInsert/
                // AutoExecute NO serializa un array PHP crudo en una columna real
                // (solo rutea campos desconocidos al jsonb), así que sin esto meta
                // se guardaba NULL y las líneas (meta.details) se perdían.
                'meta'                  => json_encode($meta),
            ],
            'table' => 'transaction',
        ]);

        if (!$transactionId) {
            $errMsg = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo insertar la compra: ' . ($errMsg !== '' ? $errMsg : 'sin detalle'));
        }

        // Insertar itemSold + manageStock para cada item con itemId real.
        foreach ($details as $d) {
            if ($d['itemId'] === '') {
                continue; // gasto libre — ya está en meta.details
            }
            $units     = (float) $d['qty'];
            $lineTotal = (float) $d['price'];
            $price     = $units > 0 ? $lineTotal / $units : 0.0;

            $itemSoldId = ncmInsert([
                'records' => [
                    'itemSoldTotal'       => $lineTotal,
                    'itemSoldTax'         => (float) $d['tax'],
                    'itemSoldUnits'       => $units,
                    'itemSoldDate'        => $invoiceDate,
                    'itemSoldDescription' => $d['title'] ?: null,
                    'taxId'               => $d['taxId'] !== '' ? $d['taxId'] : null,
                    'itemId'              => $d['itemId'],
                    'transactionId'       => $transactionId,
                ],
                'table' => 'itemSold',
            ]);

            if (!$itemSoldId) {
                $errMsg = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException("No se pudo insertar línea {$d['itemId']}: " . $errMsg);
            }

            // Stock: la compra suma. manageStock usa los globals OUTLET_ID/USER_ID
            // si no se pasan, pero acá pasamos explícito.
            \Punto\App\Domain\Inventory::manageStock([
                'itemId'        => $d['itemId'],
                'outletId'      => $outletId,
                'cogs'          => $price,
                'count'         => $units,
                'supplierId'    => $supplierId,
                'source'        => 'purchase',
                'transactionId' => $transactionId,
                'locationId'    => null,
                'userId'        => $userId,
                'companyId'     => $companyId,
                'date'          => $invoiceDate,
            ]);
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Transacción de compra abortó después del INSERT inicial');
        }

        return (string) $transactionId;
    }

    /**
     * Anula una compra (soft-void). NO borra la fila — auditoría + fiscal.
     *
     * Lógica:
     *   - Carga la transacción (type=1, companyId). Si no existe o status != 1
     *     → RuntimeException (idempotente: una compra ya anulada no se re-anula).
     *   - Inicia TX. UPDATE transactionStatus = 6 ("Cancelado").
     *   - Por cada línea con itemId real: revierte stock vía manageStock con
     *     type='-' / source='purchase-void'. Las líneas de gasto libre (sin
     *     itemId) no tocan stock.
     *   - Commit. Devuelve ['id', 'status'=>6].
     *
     * El dashboard de egresos filtra transactionStatus = 1, así que al marcar 6
     * la compra anulada se excluye sola de los reportes de egresos.
     *
     * @throws \RuntimeException si la compra no existe, no es anulable, o la TX falla.
     */
    public function void(string $id, string $companyId, string $userId): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id de compra inválido');
        }
        if (!preg_match(self::UUID_RE, $userId)) {
            throw new \RuntimeException('userId requerido');
        }

        $row = ncmExecute(
            "SELECT *, meta::text AS meta_raw FROM transaction
              WHERE transactionId = ? AND companyId = ? AND transactionType IN (1,4)
              LIMIT 1",
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Compra no encontrada');
        }
        if ((int) $row['transactionstatus'] !== 1) {
            throw new \RuntimeException('La compra ya fue anulada o no es anulable');
        }

        // meta JSONB → array, desde el alias `meta_raw` (la columna `meta` la
        // borra el flatten del wrapper). Sin esto, `details` quedaba vacio y
        // anular una compra NO revertia el stock.
        $meta = $row['meta_raw'] ?? $row['meta'] ?? '{}';
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }
        $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];

        $outletId   = (string) $row['outletid'];
        $supplierId = $row['supplierid'] !== null ? (string) $row['supplierid'] : null;
        $date       = (string) $row['transactiondate'];

        $db->StartTrans();

        ncmExecute(
            'UPDATE transaction SET transactionStatus = 6, updated_at = NOW()
              WHERE transactionId = ? AND companyId = ?',
            [$id, $companyId]
        );

        // Revertir stock por cada línea con producto real.
        foreach ($details as $d) {
            $itemId = (string) ($d['itemId'] ?? '');
            if ($itemId === '') {
                continue; // gasto libre — no tocó stock al crear
            }
            $units = (float) ($d['qty'] ?? 0);
            if ($units <= 0) {
                continue;
            }
            \Punto\App\Domain\Inventory::manageStock([
                'itemId'        => $itemId,
                'outletId'      => $outletId,
                'count'         => $units,
                'type'          => '-',
                'supplierId'    => $supplierId,
                'source'        => 'purchase-void',
                'transactionId' => $id,
                'locationId'    => null,
                'userId'        => $userId,
                'companyId'     => $companyId,
                'date'          => $date,
            ]);
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo anular la compra: la transacción abortó');
        }

        return ['id' => $id, 'status' => 6];
    }

    /**
     * Normaliza fecha del request:
     *  - null/empty + $useTodayDefault → ahora.
     *  - 'YYYY-MM-DD' → 'YYYY-MM-DD 00:00:00'.
     *  - 'YYYY-MM-DD HH:MM:SS' → tal cual.
     *  - inválido → null.
     */
    private function normalizeDate(?string $val, bool $useTodayDefault): ?string
    {
        $val = $val !== null ? trim($val) : '';
        if ($val === '') {
            return $useTodayDefault ? date('Y-m-d H:i:s') : null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $val)) {
            return $val;
        }
        return null;
    }
}
