<?php
/**
 * Dominio de Reportes — Pagos y Transacciones / Transactions (capa API, motor ERP).
 *
 * SOLO las 3 vistas de LECTURA basadas en BD del módulo legacy a_report_transactions.php:
 *   - detail(): ventas (transaction tipos 0=contado, 3=crédito, 6=devolución, 7=anulada,
 *     8=recursiva) con cliente/usuario/caja/medios de pago resueltos, deuda de crédito (batch),
 *     auth/prefijo/padding del comprobante (de la caja) y totales calculables por tipo.
 *   - cobros(): pagos de ventas a crédito (tipo 5) con su comprobante padre (tipo 0,3).
 *   - quotes(): cotizaciones (tipo 9) con estado.
 *
 * NO se migran (siguen en el PHP legacy vía ?action=): el CRUD de edición (edit/update/
 * updateItem/updateItemTotal/delete/addPayment/paymentForm), el export (download-report),
 * los fiscales (rg90, libro-ventas, mcal, tusFacturas) y la vista `feTable` (gateway a una API
 * externa de Facturación Electrónica, no verificable en dev — análogo a vpayments).
 *
 * Devuelve datos CRUDOS (números, sin formatear, sin HTML). El front formatea + arma las tablas.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * Fixes PG respecto del legacy:
 *  - `USE INDEX(...)` (MySQL) eliminado.
 *  - `tags` fue absorbido a `meta` JSONB → leer con `meta->>'tags'` (no SELECTear la columna).
 *  - búsqueda `src` legacy tenía el término LITERAL dentro de un string single-quote
 *    (`'... LIKE \'%\' . $word . \'%\''` nunca interpolaba) → acá subquery ILIKE parametrizada.
 *  - `invoiceNo = "word"` (comillas dobles = identificador en PG) → bound param numérico.
 *  - deuda de crédito vía un único SUM..GROUP BY parametrizado (legacy: getAllToPayTransactions
 *    con IN interpolado).
 *  - dato de caja por fila (N+1 en el legacy) → un solo lookup batch de registers.
 *
 * Tenant: $roc (getROC) por query; companyId bound en cada lookup.
 */
class ReportTransactionsService
{
    /** Tipos de transacción que son ventas (contado, crédito, devolución, anulada, recursiva). */
    private const TX_TYPES = '0,3,6,7,8';

    /* ───────────────────────── detail (tab "Transacciones") ───────────────────────── */

    /** Ventas. $filters: ['cusId'=>uuid, 'src'=>str, 'singleRow'=>uuid]. */
    public function detail(array $filters, $from, $to)
    {
        $roc = getROC(1);
        $cols = "transactionId, transactionDate, transactionDiscount, transactionTax,
                 transactionTotal, transactionPaymentType, transactionType, transactionNote,
                 transactionDueDate, transactionStatus, transactionComplete, invoiceNo,
                 invoicePrefix, customerId, registerId, userId, outletId, meta->>'tags' AS tags";

        if ($filters['singleRow']) {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ") AND transactionId = ?" . $roc . "
                    ORDER BY transactionDate DESC";
            $params = [$filters['singleRow']];
        } elseif ($filters['src']) {
            // Clientes (type 1) cuyo nombre/TIN/segundo-nombre matchea, + match por invoiceNo si es numérico.
            $like = '%' . $filters['src'] . '%';
            $isNum = ctype_digit($filters['src']);
            $invoiceClause = $isNum ? ' OR invoiceNo = ?' : '';
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ")" . $roc . "
                    AND (customerId IN (
                            SELECT contactId FROM contact
                            WHERE type = 1 AND companyId = ?
                            AND (contactName ILIKE ? OR contactTIN ILIKE ? OR contactSecondName ILIKE ?)
                         )" . $invoiceClause . ")
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = $isNum
                ? [COMPANY_ID, $like, $like, $like, (int) $filters['src']]
                : [COMPANY_ID, $like, $like, $like];
        } elseif ($filters['cusId']) {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ")" . $roc . "
                    AND customerId = ? ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$filters['cusId']];
        } else {
            $sql = "SELECT $cols FROM transaction
                    WHERE transactionType IN (" . self::TX_TYPES . ")
                    AND transactionDate BETWEEN ? AND ?" . $roc . "
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$from, $to];
        }

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        // IDs para lookups batch: recolectados con foreach (NO array_map — sobre filas getAssoc
        // de ncmExecute/CaseInsensitiveArray el array_map lee mal algunas columnas; foreach es fiable).
        $txIds = $custIds = $usrIds = $outletIds = $regIds = [];
        foreach ($res as $f) {
            $txIds[]     = (string) $f['transactionId'];
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
            $regIds[]    = (string) $f['registerId'];
        }

        $payedMap  = $this->payedByParent($txIds);
        $contacts  = $this->contactInfo(array_merge($custIds, $usrIds));
        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds);
        $registers = $this->registerInfo($regIds);

        $rows = [];
        foreach ($res as $f) {
            $type     = (string) $f['transactionType'];
            $total    = (float) $f['transactionTotal'];
            $discount = (float) $f['transactionDiscount'];
            $tax      = (float) $f['transactionTax'];
            $netTotal = $total - $discount;

            // Deuda restante (sólo crédito): netTotal − pagado.
            $topay = 0.0;
            if ($type === '3') {
                $payed = $payedMap[(string) $f['transactionId']] ?? 0;
                $topay = $netTotal - $payed;
            }

            // Totales calculables: anulada (7) no aporta nada.
            if ($type === '7') {
                $cDiscount = $cSubtotal = $cTax = $cNet = 0.0;
            } else {
                $cDiscount = $discount; $cSubtotal = $total; $cTax = $tax; $cNet = $netTotal;
            }

            // Comprobante: auth/prefijo/padding desde la caja, con casos especiales.
            $reg = $registers[(string) $f['registerId']] ?? [];
            $invoiceAuth   = (string) ($reg['invoiceAuth'] ?? '');
            $invoicePrefix = (string) ($f['invoicePrefix'] ?? '');
            if ($invoicePrefix === '') {
                $invoicePrefix = (string) ($reg['invoicePrefix'] ?? '');
            }
            // Devolución (6): usa el prefijo de devolución de la caja si la clave existe (aun
            // vacía → limpia el prefijo), igual que el legacy (isset registerReturnPrefix).
            if ($type === '6' && ($reg['returnPrefix'] ?? null) !== null) {
                $invoicePrefix = (string) $reg['returnPrefix'];
            }
            $lead = (int) ($reg['docsLeadingZeros'] ?? 0);
            $invoiceNo = (string) ($f['invoiceNo'] ?? '');
            $paddedNo  = $lead > 0 ? str_pad($invoiceNo, $lead, '0', STR_PAD_LEFT) : $invoiceNo;

            // Tags (desde meta JSONB; el writer guarda un JSON string).
            $tagsArr = $this->decodeTags($f['tags'] ?? null);
            // Etiqueta especial 166227 → sin prefijo/auth, número crudo (igual que el legacy).
            if ($tagsArr && (in_array('166227', $tagsArr, false))) {
                $invoicePrefix = ''; $invoiceAuth = ''; $paddedNo = $invoiceNo;
            }
            $tagNames = $this->tagNames($tagsArr);

            $custId = (string) $f['customerId'];
            $usrId  = (string) $f['userId'];

            $rows[] = [
                'transactionId'       => (string) $f['transactionId'],
                'authNo'              => $invoiceAuth,
                'docNo'               => $invoicePrefix . $paddedNo,
                'invoiceNo'           => $invoiceNo,
                'date'                => (string) $f['transactionDate'],
                'dueDate'             => (string) ($f['transactionDueDate'] ?? ''),
                'customerName'        => $custId ? ($contacts[$custId]['name'] ?? '') : '',
                'customerTIN'         => $custId ? ($contacts[$custId]['tin'] ?? '') : '',
                'userName'            => $usrId ? ($contacts[$usrId]['name'] ?? '-') : '-',
                'outletName'          => $outlets[(string) $f['outletId']] ?? '',
                'registerName'        => (string) ($reg['name'] ?? ''),
                'payments'            => $this->payments($f['transactionPaymentType'] ?? null),
                'note'                => (string) ($f['transactionNote'] ?? ''),
                'tags'                => $tagNames,
                'transactionType'     => (int) $type,
                'transactionComplete' => $this->isComplete($f['transactionComplete'] ?? null) ? 1 : 0,
                'topay'               => $topay,
                'netTotal'            => $cNet,
                'discount'            => $cDiscount,
                'subtotal'            => $cSubtotal,
                'tax'                 => $cTax,
                'totalGravado'        => $cNet - $cTax,
                'total'               => $cNet,
            ];
        }

        return ['rows' => $rows];
    }

    /* ───────────────────────── cobros (tab "Pagos recibidos") ───────────────────────── */

    /** Pagos de ventas a crédito (tipo 5). $filters: ['cusId'=>uuid, 'src'=>str]. */
    public function cobros(array $filters, $from, $to)
    {
        $roc = getROC(1);

        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (5)" . $roc . "
                    AND customerId IN (
                        SELECT contactId FROM contact WHERE type = 1 AND companyId = ?
                        AND (contactName ILIKE ? OR contactTIN ILIKE ? OR contactSecondName ILIKE ?)
                    )
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [COMPANY_ID, $like, $like, $like];
        } elseif ($filters['cusId']) {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (5)" . $roc . "
                    AND customerId = ? ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$filters['cusId']];
        } else {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (5)
                    AND transactionDate BETWEEN ? AND ?" . $roc . "
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$from, $to];
        }

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        // IDs para lookups batch vía foreach (ver nota en detail() sobre array_map + getAssoc).
        $parentIds = $custIds = $usrIds = $outletIds = $regIds = [];
        foreach ($res as $f) {
            $parentIds[] = (string) $f['transactionParentId'];
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
            $regIds[]    = (string) $f['registerId'];
        }

        // El padre debe ser una venta (tipo 0,3); sólo esos pagos se listan.
        $parents   = $this->parentInvoices($parentIds, '0,3');
        $contacts  = $this->contactInfo(array_merge($custIds, $usrIds));
        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds);
        foreach ($parents as $p) { $regIds[] = (string) $p['registerId']; }
        $registers = $this->registerInfo($regIds);

        $rows = [];
        foreach ($res as $f) {
            $pid = (string) $f['transactionParentId'];
            if (!isset($parents[$pid])) {
                continue;
            }
            $p = $parents[$pid];
            $parentReg = $registers[(string) $p['registerId']] ?? [];
            $custId = (string) $f['customerId'];
            $usrId  = (string) $f['userId'];

            $rows[] = [
                'transactionId' => (string) $f['transactionId'],
                'parentId'      => $pid,
                'parentInvoice' => trim(((string) ($parentReg['invoicePrefix'] ?? '')) . ' ' . ((string) ($p['invoiceNo'] ?? ''))),
                'invoiceNo'     => (string) ($f['invoiceNo'] ?? ''),
                'date'          => (string) $f['transactionDate'],
                'customerName'  => $custId ? ($contacts[$custId]['name'] ?? '') : '',
                'userName'      => $usrId ? ($contacts[$usrId]['name'] ?? '-') : '-',
                'outletName'    => $outlets[(string) $f['outletId']] ?? '',
                'registerName'  => (string) ($registers[(string) $f['registerId']]['name'] ?? ''),
                'payments'      => $this->payments($f['transactionPaymentType'] ?? null),
                'total'         => (float) $f['transactionTotal'],
                'outletId'      => (string) $f['outletId'],
                'type'          => (int) $f['transactionType'],
            ];
        }

        return ['rows' => $rows];
    }

    /* ───────────────────────── quotes (tab "Cotizaciones") ───────────────────────── */

    /** Cotizaciones (tipo 9). $filters: ['cusId'=>uuid, 'src'=>str]. */
    public function quotes(array $filters, $from, $to)
    {
        $roc = getROC(1);

        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (9)" . $roc . "
                    AND customerId IN (
                        SELECT contactId FROM contact WHERE type = 1 AND companyId = ?
                        AND (contactName ILIKE ? OR contactTIN ILIKE ? OR contactSecondName ILIKE ?)
                    )
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [COMPANY_ID, $like, $like, $like];
        } elseif ($filters['cusId']) {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (9)" . $roc . "
                    AND customerId = ? ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$filters['cusId']];
        } else {
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (9)
                    AND transactionDate BETWEEN ? AND ?" . $roc . "
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$from, $to];
        }

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        // IDs para lookups batch vía foreach (ver nota en detail() sobre array_map + getAssoc).
        $custIds = $usrIds = $outletIds = [];
        foreach ($res as $f) {
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
        }
        $contacts = $this->contactInfo(array_merge($custIds, $usrIds));
        $outlets  = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds);

        $rows = [];
        foreach ($res as $f) {
            $custId = (string) $f['customerId'];
            $usrId  = (string) $f['userId'];
            $rows[] = [
                'transactionId'     => (string) $f['transactionId'],
                'invoiceNo'         => (string) ($f['invoiceNo'] ?? ''),
                'date'              => (string) $f['transactionDate'],
                'transactionStatus' => (string) ($f['transactionStatus'] ?? ''),
                'customerName'      => $custId ? ($contacts[$custId]['name'] ?? '') : '',
                'customerTIN'       => $custId ? ($contacts[$custId]['tin'] ?? '') : '',
                'userName'          => $usrId ? ($contacts[$usrId]['name'] ?? '-') : '-',
                'outletName'        => $outlets[(string) $f['outletId']] ?? '',
                'total'             => (float) $f['transactionTotal'],
                'outletId'          => (string) $f['outletId'],
                'type'              => (int) $f['transactionType'],
            ];
        }

        return ['rows' => $rows];
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** Normaliza el BOOLEAN PG `transactionComplete`, robusto al driver (ADOdb 1/0, PDO 't'/'f'). */
    private function isComplete($v)
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return $s === '1' || $s === 't' || $s === 'true';
    }

    /** Decodifica `tags` (meta->>'tags'): JSON string → array de ids. */
    private function decodeTags($raw)
    {
        if (!$raw) {
            return [];
        }
        $arr = json_decode((string) $raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** Medios de pago resueltos a [{name}], sólo los de total > 0. */
    private function payments($json)
    {
        $arr = getPaymentMethodsInArray($json);
        $out = [];
        if (is_array($arr)) {
            foreach ($arr as $p) {
                if (($p['total'] ?? 0) > 0) {
                    $out[] = ['name' => (string) ($p['name'] ?? '')];
                }
            }
        }
        return $out;
    }

    /** SUM(ABS) de pagos+devoluciones (tipo 5,6) por transactionParentId, scopeado por companyId. */
    private function payedByParent(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionParentId, SUM(ABS(transactionTotal)) as payed
             FROM transaction WHERE transactionType IN (5,6) AND companyId = ? AND transactionParentId IN ($ph)
             GROUP BY transactionParentId",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionParentId']] = abs((float) $r['payed']);
        }
        return $map;
    }

    /** Comprobantes padre por id → [id => {invoiceNo, invoicePrefix, registerId}], filtrado por tipos. */
    private function parentInvoices(array $ids, $types)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix, registerId
             FROM transaction WHERE transactionId IN ($ph) AND transactionType IN ($types) AND companyId = ?",
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
                'name' => trim((string) ($c['contactName'] ?? '')),
                'tin'  => (string) ($c['contactTIN'] ?? ''),
            ];
        }
        return $map;
    }

    /** Lookup batch id→name de outlet, scopeado por companyId. */
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

    /**
     * Lookup batch de cajas → [registerId => {name, invoiceAuth, invoicePrefix, docsLeadingZeros,
     * returnPrefix}]. returnPrefix vive en el `data` JSONB de la caja (no flatten) → json_decode.
     */
    private function registerInfo(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT registerId, registerName, registerInvoiceAuth, registerInvoicePrefix,
                    registerDocsLeadingZeros, data
             FROM register WHERE companyId = ? AND registerId IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $data = json_decode((string) ($r['data'] ?? ''), true);
            $map[(string) $r['registerId']] = [
                'name'             => (string) ($r['registerName'] ?? ''),
                'invoiceAuth'      => (string) ($r['registerInvoiceAuth'] ?? ''),
                'invoicePrefix'    => (string) ($r['registerInvoicePrefix'] ?? ''),
                'docsLeadingZeros' => (int) ($r['registerDocsLeadingZeros'] ?? 0),
                // null = la clave no existe (no override); '' = existe vacía (limpia el prefijo).
                'returnPrefix'     => (is_array($data) && array_key_exists('registerReturnPrefix', $data))
                    ? (string) $data['registerReturnPrefix'] : null,
            ];
        }
        return $map;
    }

    /** Nombres de taxonomía (etiquetas) cacheados, ignorando 'None'. */
    private $taxonomyCache = [];
    private function tagNames(array $tagIds)
    {
        $out = [];
        foreach ($tagIds as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            if (!array_key_exists($id, $this->taxonomyCache)) {
                $name = (string) getTaxonomyName($id, false, false, true);
                $this->taxonomyCache[$id] = ($name === 'None') ? '' : $name;
            }
            if ($this->taxonomyCache[$id] !== '') {
                $out[] = $this->taxonomyCache[$id];
            }
        }
        return $out;
    }
}
