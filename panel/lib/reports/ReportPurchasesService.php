<?php
/**
 * Dominio de Reportes — Compras y Gastos / Purchases (capa API, motor ERP).
 *
 * SOLO las 3 vistas de LECTURA del módulo legacy a_report_purchases.php:
 *   - general(): cabeceras de compra/gasto (transaction tipos 1=contado, 4=crédito) con
 *     proveedor/usuario/sucursal/medios de pago/plan de cuentas resueltos + deuda (batch).
 *   - cobros():  pagos a proveedores (transaction tipo 5) con su comprobante padre (tipo 4).
 *   - detail():  líneas de compra (transaction ⋈ itemSold, tipos 1,4) con costo unitario.
 *
 * El CRUD de edición (edit/update/paymentForm/addPayment/delete) y los 2 reportes fiscales
 * (rg90, libro-compra) NO se migran: siguen sirviéndose por el PHP legacy vía ?action=.
 *
 * Devuelve datos CRUDOS (números, sin formatear, sin HTML). El front formatea + arma las tablas.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * Fixes PG respecto del legacy:
 *  - `USE INDEX(...)` (MySQL) eliminado.
 *  - detail: `FROM transagction a` (typo legacy en la rama de búsqueda `src`) → `transaction`.
 *  - detail: búsqueda `src` legacy tenía el término LITERAL dentro de un string single-quote
 *    (`'... LIKE \'%\' . $word . \'%\''` no interpolaba) → acá es un ILIKE parametrizado.
 *  - cobros (rama supId): el legacy tenía un `AND transactionDate` colgante seguido del $roc
 *    (sin BETWEEN) → SQL roto en PG; se elimina la cláusula colgante.
 *  - deuda: el legacy hacía un SELECT SUM por fila (N+1); acá es un único SUM ... GROUP BY.
 *  - delete legacy de pagos (cobros) era IDOR (sin companyId) → el front sigue usando ?action=delete
 *    legacy; este service es read-only.
 *
 * Tenant: $roc (getROC) por query; companyId bound en cada lookup.
 */
class ReportPurchasesService
{
    /** Tipos de transacción que son compras/gastos (contado, crédito). */
    private const TX_TYPES = '1,4';

    /* ───────────────────────── general (tab "Compra o Gasto") ───────────────────────── */

    /**
     * Cabeceras de compra/gasto. $filters: ['supId'=>uuid, 'singleRow'=>uuid].
     * supId      → todas las del proveedor (sin filtro de fecha, como el legacy).
     * singleRow  → una sola transacción por id (re-render tras editar/pagar).
     * default    → rango de fechas.
     */
    public function general(array $filters, $from, $to)
    {
        $roc = getROC(1);

        if ($filters['singleRow']) {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ") AND transactionId = ?" . $roc . "
                    ORDER BY transactionDate DESC";
            $params = [$filters['singleRow']];
        } elseif ($filters['supId']) {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ") AND supplierId = ?" . $roc . "
                    ORDER BY transactionDate DESC LIMIT 2000";
            $params = [$filters['supId']];
        } else {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ")
                    AND transactionDate BETWEEN ? AND ?" . $roc . "
                    ORDER BY transactionDate DESC LIMIT 2000";
            $params = [$from, $to];
        }

        $res = ncmExecute($sql, $params, false, true);
        $tx  = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $tx[] = $res->fields;
                $res->MoveNext();
            }
            $res->Close();
        }
        if (!$tx) {
            return ['rows' => []];
        }

        // Lookups batch.
        $supIds  = array_map(fn($r) => (string) $r['supplierId'], $tx);
        $usrIds  = array_map(fn($r) => (string) $r['userId'], $tx);
        $contacts = $this->contactInfo(array_merge($supIds, $usrIds));
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', array_map(fn($r) => (string) $r['outletId'], $tx));

        // Deuda: SUM de los pagos (hijos) por transacción de crédito incompleta, en una sola query.
        // transactionComplete es BOOLEAN en PG: ADOdb lo devuelve como 1/0, pero normalizamos
        // explícitamente para ser robustos al driver ('t'/'f', true/false) — ver isComplete().
        $creditIds = [];
        foreach ($tx as $r) {
            if ((string) $r['transactionType'] === '4' && !$this->isComplete($r['transactionComplete'] ?? null)) {
                $creditIds[] = (string) $r['transactionId'];
            }
        }
        $payedMap = $this->payedByParent($creditIds);

        $rows = [];
        foreach ($tx as $f) {
            [$authNo, $prefix] = $this->splitAuthPrefix((string) ($f['invoicePrefix'] ?? ''));

            $complete = $this->isComplete($f['transactionComplete'] ?? null);
            $debt = 0.0;
            $canAddPayment = false;
            if ((string) $f['transactionType'] === '4' && !$complete) {
                $payed = $payedMap[(string) $f['transactionId']] ?? 0;
                $debt  = (float) $f['transactionTotal'] - $payed;
                $canAddPayment = true;
            }

            $supId = (string) $f['supplierId'];
            $usrId = (string) $f['userId'];

            $rows[] = [
                'transactionId'      => (string) $f['transactionId'],
                'authNo'             => $authNo,
                'prefix'             => $prefix,
                'invoiceNo'          => (string) ($f['invoiceNo'] ?? ''),
                'date'               => (string) $f['transactionDate'],
                'dueDate'            => (string) ($f['transactionDueDate'] ?? ''),
                'outletName'         => $outlets[(string) $f['outletId']] ?? '',
                'userName'           => $contacts[$usrId]['name'] ?? '',
                'supplierName'       => $contacts[$supId]['name'] ?? '',
                'supplierTIN'        => $contacts[$supId]['tin'] ?? '',
                'note'               => (string) ($f['transactionNote'] ?? ''),
                'payments'           => $this->payments($f['transactionPaymentType'] ?? null),
                'transactionType'    => (int) $f['transactionType'],
                'transactionComplete'=> $complete ? 1 : 0,
                'transactionStatus'  => (string) ($f['transactionStatus'] ?? ''),
                'category'           => $f['categoryTransId'] ? $this->taxonomyName($f['categoryTransId']) : '',
                'tax'                => (float) $f['transactionTax'],
                'discount'           => (float) $f['transactionDiscount'],
                'total'              => (float) $f['transactionTotal'],
                'debt'               => $debt,
                'canAddPayment'      => $canAddPayment,
            ];
        }

        return ['rows' => $rows];
    }

    /* ───────────────────────── cobros (tab "Pagos realizados") ───────────────────────── */

    /** Pagos a proveedores (tipo 5). $filters: ['supId'=>uuid]. */
    public function cobros(array $filters, $from, $to)
    {
        $roc = getROC(1);

        if ($filters['supId']) {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (5) AND supplierId = ?" . $roc . "
                    ORDER BY transactionDate DESC LIMIT 2000";
            $params = [$filters['supId']];
        } else {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (5)
                    AND transactionDate BETWEEN ? AND ?" . $roc . "
                    ORDER BY transactionDate DESC LIMIT 2000";
            $params = [$from, $to];
        }

        $res = ncmExecute($sql, $params, false, true, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        // El comprobante padre debe ser una compra a crédito (tipo 4); sólo esos pagos se listan.
        $parentIds = array_values(array_unique(array_filter(array_map(fn($r) => (string) $r['transactionParentId'], $res))));
        $parents   = $this->parentInvoices($parentIds);

        $usrIds  = array_map(fn($r) => (string) $r['userId'], $res);
        $contacts = $this->contactInfo($usrIds);
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', array_map(fn($r) => (string) $r['outletId'], $res));

        $rows = [];
        foreach ($res as $f) {
            $pid = (string) $f['transactionParentId'];
            if (!isset($parents[$pid])) {
                continue;   // el legacy ignora pagos cuyo padre no es una compra a crédito
            }
            $p = $parents[$pid];
            $usrId = (string) $f['userId'];

            $rows[] = [
                'transactionId'  => (string) $f['transactionId'],
                'parentId'       => $pid,
                'parentInvoice'  => trim(((string) $p['invoicePrefix']) . ' ' . ((string) $p['invoiceNo'])),
                'invoiceNo'      => (string) ($f['invoiceNo'] ?? ''),
                'date'           => (string) $f['transactionDate'],
                'userName'       => $usrId ? ($contacts[$usrId]['name'] ?? '-') : '-',
                'outletId'       => (string) $f['outletId'],
                'outletName'     => $outlets[(string) $f['outletId']] ?? '',
                'note'           => (string) ($f['transactionNote'] ?? ''),
                'payments'       => $this->payments($f['transactionPaymentType'] ?? null),
                'total'          => (float) $f['transactionTotal'],
                'type'           => (int) $f['transactionType'],
            ];
        }

        return ['rows' => $rows];
    }

    /* ───────────────────────── detail (tab "Detalle") ───────────────────────── */

    /** Líneas de compra. $filters: ['src'=>str, 'supId'=>uuid, 'itmId'=>uuid]. */
    public function detail(array $filters, $from, $to)
    {
        $roc = str_replace(
            ['outletId', 'registerId', 'companyId'],
            ['a.outletId', 'a.registerId', 'a.companyId'],
            getROC(1)
        );

        // transactionDetails fue absorbido a `meta` JSONB (Phase PG) → leerlo con ->> (string-de-json).
        $sel = "a.supplierId as supplier, a.userId as trsUser, a.outletId, a.registerId,
                a.invoiceNo, a.invoicePrefix, a.transactionType, a.categoryTransId,
                a.meta->>'transactionDetails' AS transactionDetails,
                b.itemSoldId, b.itemId, b.itemSoldUnits, b.itemSoldTotal, b.itemSoldTax,
                b.itemSoldDiscount, b.itemSoldDate, b.itemSoldDescription, b.transactionId,
                b.userId as itemUser";

        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $roc . "
                    AND a.transactionType IN (" . self::TX_TYPES . ") AND a.transactionId = b.transactionId
                    AND b.itemId IN (SELECT itemId FROM item WHERE (itemName ILIKE ? OR itemSKU ILIKE ?) AND companyId = ? AND itemStatus = 1)
                    ORDER BY a.transactionDate DESC LIMIT 5000";
            $params = [$from, $to, $like, $like, COMPANY_ID];
        } elseif ($filters['supId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND a.supplierId = ?" . $roc . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 5000";
            $params = [$filters['supId']];
        } elseif ($filters['itmId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND b.itemId = ?" . $roc . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 5000";
            $params = [$filters['itmId']];
        } else {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $roc . "
                    AND a.transactionType IN (" . self::TX_TYPES . ") AND a.transactionId = b.transactionId
                    ORDER BY a.transactionDate DESC LIMIT 5000";
            $params = [$from, $to];
        }

        $res = ncmExecute($sql, $params, false, true);
        if (!$res || !is_object($res)) {
            return ['rows' => []];
        }
        $lines = [];
        while (!$res->EOF) {
            $lines[] = $res->fields;
            $res->MoveNext();
        }
        $res->Close();

        // Lookups batch.
        $items   = $this->itemMeta(array_map(fn($l) => (string) $l['itemId'], $lines));
        $supIds  = array_map(fn($l) => (string) $l['supplier'], $lines);
        $usrIds  = array_map(fn($l) => (string) ($l['itemUser'] ?: $l['trsUser']), $lines);
        $contacts = $this->contactInfo(array_merge($supIds, $usrIds));
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', array_map(fn($l) => (string) $l['outletId'], $lines));

        $rows = [];
        foreach ($lines as $l) {
            $iid  = (string) $l['itemId'];
            $itm  = $items[$iid] ?? null;

            // Plan de cuentas: por defecto categoryTransId; si transactionDetails trae un plan
            // para este itemId, se usa ese (igual que el legacy).
            $cat = $l['categoryTransId'];
            $details = json_decode((string) ($l['transactionDetails'] ?? ''), true);
            if (is_array($details)) {
                foreach ($details as $itm2) {
                    if (isset($itm2['itemId'], $itm2['plan']) && dec($itm2['itemId']) == $iid && $itm2['plan']) {
                        $cat = dec($itm2['plan']);
                    }
                }
            }
            $category = $cat ? $this->taxonomyName($cat) : '';
            if ($category === 'None') { $category = ''; }

            $uSold = (float) $l['itemSoldUnits'];
            $total = (float) $l['itemSoldTotal'];
            // Costo unitario: si el total es negativo (nota de crédito) se usa ABS/units.
            $cogs  = $uSold != 0 ? ($total < 0 ? (abs($total) / $uSold) : ($total / $uSold)) : 0.0;

            $name = $itm ? (string) $itm['itemName'] : (string) ($l['itemSoldDescription'] ?? '');

            [$authNo, $prefix] = $this->splitAuthPrefix((string) ($l['invoicePrefix'] ?? ''));
            $usrId = (string) ($l['itemUser'] ?: $l['trsUser']);

            $rows[] = [
                'transactionId' => (string) $l['transactionId'],
                'outletName'    => $outlets[(string) $l['outletId']] ?? '',
                'invoiceNo'     => trim($prefix . ' ' . ((string) ($l['invoiceNo'] ?? ''))),
                'userName'      => $contacts[$usrId]['name'] ?? '',
                'supplierName'  => $contacts[(string) $l['supplier']]['name'] ?? '',
                'date'          => (string) $l['itemSoldDate'],
                'name'          => $name,
                'category'      => $category,
                'usold'         => $uSold,
                'cogs'          => $cogs,
                'tax'           => (float) $l['itemSoldTax'],
                'total'         => $total,
            ];
        }

        return ['rows' => $rows];
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /**
     * Normaliza el BOOLEAN PG `transactionComplete`, robusto al driver:
     * ADOdb devuelve 1/0, pero PDO/otros pueden devolver 't'/'f' o true/false.
     * `(int)'t'` y `(int)'f'` son ambos 0 → un cast a int silenciaría los completos.
     */
    private function isComplete($v)
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return $s === '1' || $s === 't' || $s === 'true';
    }

    /** Parsea "auth;prefix" del invoicePrefix legacy → [authNo, prefix]. */
    private function splitAuthPrefix($invoicePrefix)
    {
        if (strpos($invoicePrefix, ';') !== false) {
            $parts = explode(';', $invoicePrefix);
            return [$parts[0], $parts[1] ?? ''];
        }
        return ['', $invoicePrefix];
    }

    /** Medios de pago resueltos a [{name, price}], sólo los de price > 0. */
    private function payments($json)
    {
        $arr = getPaymentMethodsInArray($json);
        $out = [];
        if (is_array($arr)) {
            foreach ($arr as $p) {
                if (($p['price'] ?? 0) > 0) {
                    $out[] = ['name' => (string) ($p['name'] ?? ''), 'price' => (float) ($p['price'] ?? 0)];
                }
            }
        }
        return $out;
    }

    /** SUM de los pagos (hijos) por transactionParentId, en una sola query. */
    private function payedByParent(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionParentId, SUM(transactionTotal) as payed
             FROM transaction WHERE transactionParentId IN ($ph) AND companyId = ?
             GROUP BY transactionParentId",
            array_merge($ids, [COMPANY_ID]), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionParentId']] = (float) $r['payed'];
        }
        return $map;
    }

    /** Comprobantes padre (tipo 4) por id → [id => {invoiceNo, invoicePrefix, supplierId}]. */
    private function parentInvoices(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix, supplierId
             FROM transaction WHERE transactionId IN ($ph) AND transactionType = 4 AND companyId = ?",
            array_merge($ids, [COMPANY_ID]), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionId']] = $r;
        }
        return $map;
    }

    /** Lookup batch contactId → ['name'=>contactName, 'tin'=>contactTIN], scopeado por companyId. */
    private function contactInfo(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, contactTIN FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = [
                'name' => (string) ($c['contactName'] ?? ''),
                'tin'  => (string) ($c['contactTIN'] ?? ''),
            ];
        }
        return $map;
    }

    /** itemId → fila item completa (getAllItems ya es PG-safe). */
    private function itemMeta(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        return getAllItems(false, true, implode(',', $ids), true);
    }

    /** Lookup batch id→name de outlet/register, scopeado por companyId. */
    private function nameMap($table, $idCol, $nameCol, array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT $idCol, $nameCol FROM $table WHERE companyId = ? AND $idCol IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r[$idCol]] = (string) ($r[$nameCol] ?? '');
        }
        return $map;
    }

    /** Nombre de taxonomía (plan de cuentas) cacheado. */
    private $taxonomyCache = [];
    private function taxonomyName($id)
    {
        $id = (string) $id;
        if ($id === '') {
            return '';
        }
        if (!array_key_exists($id, $this->taxonomyCache)) {
            $name = (string) getTaxonomyName($id, false, false, true);
            $this->taxonomyCache[$id] = ($name === 'None') ? '' : $name;
        }
        return $this->taxonomyCache[$id];
    }
}
