<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Pagos y Transacciones / Transactions (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportTransactionsService.php (Fase 2 batch 13). Cambios vs original:
 *  - namespace + `final`
 *  - ROC y companyId por PARÁMETRO en las 3 vistas (no globals).
 *  - `getPaymentMethodsInArray($json)` (panel-only) → `paymentsFromJson()` private inline
 *    (mismo patrón aplicado en PurchasesService — sin enc, era no-op para UUIDs PG).
 *  - `getTaxonomyName` resuelve por fallback de namespace (existe en /app).
 *
 * 3 vistas: detail (ventas 0/3/6/7/8), cobros (pagos tipo 5), quotes (cotizaciones tipo 9).
 * El CRUD de edición, los reportes fiscales (rg90/libro-ventas/mcal/tusFacturas) y la vista
 * feTable (API externa) NO se migran (siguen en panel legacy vía ?action=).
 *
 * Tenant: $roc por query; companyId bound en cada lookup.
 */
final class TransactionsService
{
    private const TX_TYPES = '0,3,6,7,8';

    private array $taxonomyCache = [];

    /** Ventas. $filters: ['cusId','src','singleRow']. */
    public function detail(array $filters, $from, $to, string $roc, string $companyId): array
    {
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
                ? [$companyId, $like, $like, $like, (int) $filters['src']]
                : [$companyId, $like, $like, $like];
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

        $txIds = $custIds = $usrIds = $outletIds = $regIds = [];
        foreach ($res as $f) {
            $txIds[]     = (string) $f['transactionId'];
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
            $regIds[]    = (string) $f['registerId'];
        }

        $payedMap  = $this->payedByParent($txIds, $companyId);
        $contacts  = $this->contactInfo(array_merge($custIds, $usrIds), $companyId);
        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);
        $registers = $this->registerInfo($regIds, $companyId);

        $rows = [];
        foreach ($res as $f) {
            $type     = (string) $f['transactionType'];
            $total    = (float) $f['transactionTotal'];
            $discount = (float) $f['transactionDiscount'];
            $tax      = (float) $f['transactionTax'];
            $netTotal = $total - $discount;

            $topay = 0.0;
            if ($type === '3') {
                $payed = $payedMap[(string) $f['transactionId']] ?? 0;
                $topay = $netTotal - $payed;
            }

            if ($type === '7') {
                $cDiscount = $cSubtotal = $cTax = $cNet = 0.0;
            } else {
                $cDiscount = $discount; $cSubtotal = $total; $cTax = $tax; $cNet = $netTotal;
            }

            $reg = $registers[(string) $f['registerId']] ?? [];
            $invoiceAuth   = (string) ($reg['invoiceAuth'] ?? '');
            $invoicePrefix = (string) ($f['invoicePrefix'] ?? '');
            if ($invoicePrefix === '') {
                $invoicePrefix = (string) ($reg['invoicePrefix'] ?? '');
            }
            if ($type === '6' && ($reg['returnPrefix'] ?? null) !== null) {
                $invoicePrefix = (string) $reg['returnPrefix'];
            }
            $lead = (int) ($reg['docsLeadingZeros'] ?? 0);
            $invoiceNo = (string) ($f['invoiceNo'] ?? '');
            $paddedNo  = $lead > 0 ? str_pad($invoiceNo, $lead, '0', STR_PAD_LEFT) : $invoiceNo;

            $tagsArr = $this->decodeTags($f['tags'] ?? null);
            if ($tagsArr && (in_array('166227', $tagsArr, false))) {
                $invoicePrefix = ''; $invoiceAuth = ''; $paddedNo = $invoiceNo;
            }
            $tagNames = $this->tagNames($tagsArr, $companyId);

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

    /** Pagos de ventas a crédito (tipo 5). $filters: ['cusId','src']. */
    public function cobros(array $filters, $from, $to, string $roc, string $companyId): array
    {
        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (5)" . $roc . "
                    AND customerId IN (
                        SELECT contactId FROM contact WHERE type = 1 AND companyId = ?
                        AND (contactName ILIKE ? OR contactTIN ILIKE ? OR contactSecondName ILIKE ?)
                    )
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$companyId, $like, $like, $like];
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

        $parentIds = $custIds = $usrIds = $outletIds = $regIds = [];
        foreach ($res as $f) {
            $parentIds[] = (string) $f['transactionParentId'];
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
            $regIds[]    = (string) $f['registerId'];
        }

        $parents   = $this->parentInvoices($parentIds, '0,3', $companyId);
        $contacts  = $this->contactInfo(array_merge($custIds, $usrIds), $companyId);
        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);
        foreach ($parents as $p) { $regIds[] = (string) $p['registerId']; }
        $registers = $this->registerInfo($regIds, $companyId);

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

    /** Cotizaciones (tipo 9). $filters: ['cusId','src']. */
    public function quotes(array $filters, $from, $to, string $roc, string $companyId): array
    {
        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT * FROM transaction
                    WHERE transactionType IN (9)" . $roc . "
                    AND customerId IN (
                        SELECT contactId FROM contact WHERE type = 1 AND companyId = ?
                        AND (contactName ILIKE ? OR contactTIN ILIKE ? OR contactSecondName ILIKE ?)
                    )
                    ORDER BY transactionDate DESC LIMIT 5000";
            $params = [$companyId, $like, $like, $like];
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

        $custIds = $usrIds = $outletIds = [];
        foreach ($res as $f) {
            $custIds[]   = (string) $f['customerId'];
            $usrIds[]    = (string) $f['userId'];
            $outletIds[] = (string) $f['outletId'];
        }
        $contacts = $this->contactInfo(array_merge($custIds, $usrIds), $companyId);
        $outlets  = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);

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

    /**
     * Elimina un cobro (tipo 5) y desmarca el crédito padre como no completado.
     * Operación atómica: si el DELETE falla, el UPDATE del padre no se ejecuta.
     */
    public function deletePayment(string $id, ?string $parentId, string $companyId): bool
    {
        global $db;
        $db->BeginTrans();
        $r = $db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ? AND transactionType = 5',
            [$id, $companyId]
        );
        if ($r === false) {
            $db->RollbackTrans();
            return false;
        }
        if ($parentId) {
            $db->Execute(
                'UPDATE transaction SET transactionComplete = 0
                 WHERE transactionId = ? AND companyId = ? AND transactionType IN (0,3)',
                [$parentId, $companyId]
            );
        }
        $db->CommitTrans();
        return true;
    }

    /** Elimina una cotización (tipo 9). */
    public function deleteQuote(string $id, string $companyId): bool
    {
        global $db;
        $r = $db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ? AND transactionType = 9',
            [$id, $companyId]
        );
        return $r !== false;
    }

    /* ───────────── helpers ───────────── */

    private function isComplete($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return $s === '1' || $s === 't' || $s === 'true';
    }

    private function decodeTags($raw): array
    {
        if (!$raw) {
            return [];
        }
        $arr = json_decode((string) $raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** Medios de pago resueltos, sólo total > 0. */
    private function payments($json): array
    {
        $arr = $this->paymentsFromJson($json);
        $out = [];
        foreach ($arr as $p) {
            if (($p['total'] ?? 0) > 0) {
                $out[] = ['name' => (string) ($p['name'] ?? '')];
            }
        }
        return $out;
    }

    /** Port inline de getPaymentMethodsInArray del panel (sin enc — no-op para UUIDs PG). */
    private function paymentsFromJson($json): array
    {
        $array = json_decode($json ?: '{}', true);
        if (!is_array($array) || !$array) {
            return [];
        }
        $out = [];
        foreach ($array as $value) {
            $out[] = [
                'type'  => $value['type'] ?? '',
                'name'  => getPaymentMethodName($value['type'] ?? ''),
                'price' => (float) ($value['price'] ?? 0),
                'total' => (float) ($value['total'] ?? 0),
                'extra' => $value['extra'] ?? 0,
            ];
        }
        return $out;
    }

    private function payedByParent(array $ids, string $companyId): array
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
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionParentId']] = abs((float) $r['payed']);
        }
        return $map;
    }

    private function parentInvoices(array $ids, $types, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix, registerId
             FROM transaction WHERE transactionId IN ($ph) AND transactionType IN ($types) AND companyId = ?",
            array_merge($ids, [$companyId]), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionId']] = $r;
        }
        return $map;
    }

    private function contactInfo(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, contactTIN FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
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

    private function nameMap(string $table, string $idCol, string $nameCol, array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT $idCol, $nameCol FROM $table WHERE companyId = ? AND $idCol IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r[$idCol]] = (string) ($r[$nameCol] ?? '');
        }
        return $map;
    }

    /** registers → {name, invoiceAuth, invoicePrefix, docsLeadingZeros, returnPrefix} */
    private function registerInfo(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        // Migración 26 (2026-06-13): registerInvoiceAuth/Prefix/DocsLeadingZeros
        // viven en `data` JSONB. `SELECT *` deja que ncmExecute aplique
        // `_flattenJsonb` y exponga las keys del JSONB como columnas virtuales
        // en cada fila — el loop de abajo las lee con `$r['registerInvoiceAuth']`
        // sin cambios.
        $res = ncmExecute(
            "SELECT *
             FROM register WHERE companyId = ? AND registerId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            // Post-Migración 26 + flatten: registerInvoiceAuth/Prefix/DocsLeadingZeros
            // viven en el JSONB pero _flattenJsonb los re-expone como columnas
            // virtuales en `$r`. registerReturnPrefix nunca fue columna —
            // siempre vivió en `data`, accedido ahora también vía flatten.
            // ncmExecute(getAssoc=true) devuelve CaseInsensitiveArray por fila
            // → array_key_exists() rompe (espera array puro). Usamos ?? null
            // que funciona con ArrayAccess y mantiene la semántica original
            // (returnPrefix=null cuando la key no existe en el JSONB).
            $map[(string) $r['registerId']] = [
                'name'             => (string) ($r['registerName'] ?? ''),
                'invoiceAuth'      => (string) ($r['registerInvoiceAuth'] ?? ''),
                'invoicePrefix'    => (string) ($r['registerInvoicePrefix'] ?? ''),
                'docsLeadingZeros' => (int) ($r['registerDocsLeadingZeros'] ?? 0),
                'returnPrefix'     => isset($r['registerReturnPrefix'])
                    ? (string) $r['registerReturnPrefix'] : null,
            ];
        }
        return $map;
    }

    /**
     * Lookup directo bindeado por companyId: NO usa el global `getTaxonomyName` (que delega a
     * Punto\App\Domain\Taxonomy y lee `$SQLcompanyId` del global vacío en /api → 'None' silente).
     * Fix preventivo descubierto en batch 14 con ProductsService.
     */
    private function tagNames(array $tagIds, string $companyId): array
    {
        $out = [];
        foreach ($tagIds as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            if (!array_key_exists($id, $this->taxonomyCache)) {
                $r = ncmExecute(
                    "SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1",
                    [$id, $companyId]
                );
                $name = $r ? (string) ($r['taxonomyName'] ?? '') : '';
                $this->taxonomyCache[$id] = ($name === '' || $name === 'None') ? '' : toUTF8($name);
            }
            if ($this->taxonomyCache[$id] !== '') {
                $out[] = $this->taxonomyCache[$id];
            }
        }
        return $out;
    }
}
