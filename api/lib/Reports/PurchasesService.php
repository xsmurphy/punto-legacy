<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Compras y Gastos / Purchases (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportPurchasesService.php (Fase 2 batch 12). Cambios vs original:
 *  - namespace + `final`
 *  - ROC y companyId por PARÁMETRO en las 3 vistas (no globals).
 *  - `dec($str)` → `dec` private static (identity en PG).
 *  - `getPaymentMethodsInArray($json)` (panel-only) → `paymentsFromJson()` private inline
 *    (mismo SQL/lógica, sin `enc` que es no-op para UUIDs en PG).
 *  - `getAllItems(false, true, $ids, true)` (sólo se usa el `itemName`) → `itemNames()` lookup
 *    batch directo.
 *  - `getTaxonomyName` resuelve por fallback (en /app/includes/functions.php).
 *
 * 3 vistas: general (cabeceras de compra/gasto), cobros (pagos a proveedores), detail (líneas).
 * El CRUD de edición y los reportes fiscales (rg90, libro-compra) NO se migran — siguen en el
 * panel legacy vía ?action= (migración parcial).
 *
 * Tenant: $roc por query; companyId bound en cada lookup.
 */
final class PurchasesService
{
    private const TX_TYPES = '1,4';

    private array $taxonomyCache = [];

    /** Cabeceras (tab "Compra o Gasto"). $filters: ['supId','singleRow']. */
    public function general(array $filters, $from, $to, string $roc, string $companyId): array
    {
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

        $supIds  = array_map(fn($r) => (string) $r['supplierId'], $tx);
        $usrIds  = array_map(fn($r) => (string) $r['userId'], $tx);
        $contacts = $this->contactInfo(array_merge($supIds, $usrIds), $companyId);
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', array_map(fn($r) => (string) $r['outletId'], $tx), $companyId);

        $creditIds = [];
        foreach ($tx as $r) {
            if ((string) $r['transactionType'] === '4' && !$this->isComplete($r['transactionComplete'] ?? null)) {
                $creditIds[] = (string) $r['transactionId'];
            }
        }
        $payedMap = $this->payedByParent($creditIds, $companyId);

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
                'category'           => $f['categoryTransId'] ? $this->taxonomyName($f['categoryTransId'], $companyId) : '',
                'tax'                => (float) $f['transactionTax'],
                'discount'           => (float) $f['transactionDiscount'],
                'total'              => (float) $f['transactionTotal'],
                'debt'               => $debt,
                'canAddPayment'      => $canAddPayment,
            ];
        }

        return ['rows' => $rows];
    }

    /** Pagos a proveedores (tipo 5). $filters: ['supId']. */
    public function cobros(array $filters, $from, $to, string $roc, string $companyId): array
    {
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

        $parentIds = array_values(array_unique(array_filter(array_map(fn($r) => (string) $r['transactionParentId'], $res))));
        $parents   = $this->parentInvoices($parentIds, $companyId);

        $usrIds  = array_map(fn($r) => (string) $r['userId'], $res);
        $contacts = $this->contactInfo($usrIds, $companyId);
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', array_map(fn($r) => (string) $r['outletId'], $res), $companyId);

        $rows = [];
        foreach ($res as $f) {
            $pid = (string) $f['transactionParentId'];
            if (!isset($parents[$pid])) {
                continue;
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

    /** Líneas de compra. $filters: ['src','supId','itmId']. */
    public function detail(array $filters, $from, $to, string $roc, string $companyId): array
    {
        $rocA = str_replace(
            ['outletId', 'registerId', 'companyId'],
            ['a.outletId', 'a.registerId', 'a.companyId'],
            $roc
        );

        $sel = "a.supplierId as supplier, a.userId as trsUser, a.outletId, a.registerId,
                a.invoiceNo, a.invoicePrefix, a.transactionType, a.categoryTransId,
                a.meta->>'transactionDetails' AS transactionDetails,
                b.itemSoldId, b.itemId, b.itemSoldUnits, b.itemSoldTotal, b.itemSoldTax,
                b.itemSoldDiscount, b.itemSoldDate, b.itemSoldDescription, b.transactionId,
                b.userId as itemUser";

        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $rocA . "
                    AND a.transactionType IN (" . self::TX_TYPES . ") AND a.transactionId = b.transactionId
                    AND b.itemId IN (SELECT itemId FROM item WHERE (itemName ILIKE ? OR itemSKU ILIKE ?) AND companyId = ? AND itemStatus = 1)
                    ORDER BY a.transactionDate DESC LIMIT 5000";
            $params = [$from, $to, $like, $like, $companyId];
        } elseif ($filters['supId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND a.supplierId = ?" . $rocA . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 5000";
            $params = [$filters['supId']];
        } elseif ($filters['itmId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND b.itemId = ?" . $rocA . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 5000";
            $params = [$filters['itmId']];
        } else {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $rocA . "
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

        $items   = $this->itemNames(array_map(fn($l) => (string) $l['itemId'], $lines), $companyId);
        $supIds  = array_map(fn($l) => (string) $l['supplier'], $lines);
        $usrIds  = array_map(fn($l) => (string) ($l['itemUser'] ?: $l['trsUser']), $lines);
        $contacts = $this->contactInfo(array_merge($supIds, $usrIds), $companyId);
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', array_map(fn($l) => (string) $l['outletId'], $lines), $companyId);

        $rows = [];
        foreach ($lines as $l) {
            $iid  = (string) $l['itemId'];

            $cat = $l['categoryTransId'];
            $details = json_decode((string) ($l['transactionDetails'] ?? ''), true);
            if (is_array($details)) {
                foreach ($details as $itm2) {
                    if (isset($itm2['itemId'], $itm2['plan']) && self::dec($itm2['itemId']) == $iid && $itm2['plan']) {
                        $cat = self::dec($itm2['plan']);
                    }
                }
            }
            $category = $cat ? $this->taxonomyName($cat, $companyId) : '';
            if ($category === 'None') { $category = ''; }

            $uSold = (float) $l['itemSoldUnits'];
            $total = (float) $l['itemSoldTotal'];
            $cogs  = $uSold != 0 ? ($total < 0 ? (abs($total) / $uSold) : ($total / $uSold)) : 0.0;

            $name = isset($items[$iid]) ? $items[$iid] : (string) ($l['itemSoldDescription'] ?? '');

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

    /* ───────────── helpers ───────────── */

    private function isComplete($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return $s === '1' || $s === 't' || $s === 'true';
    }

    private function splitAuthPrefix(string $invoicePrefix): array
    {
        if (strpos($invoicePrefix, ';') !== false) {
            $parts = explode(';', $invoicePrefix);
            return [$parts[0], $parts[1] ?? ''];
        }
        return ['', $invoicePrefix];
    }

    /** Medios de pago resueltos, sólo price > 0. */
    private function payments($json): array
    {
        $arr = $this->paymentsFromJson($json);
        $out = [];
        foreach ($arr as $p) {
            if (($p['price'] ?? 0) > 0) {
                $out[] = ['name' => (string) ($p['name'] ?? ''), 'price' => (float) ($p['price'] ?? 0)];
            }
        }
        return $out;
    }

    /**
     * Port inline de getPaymentMethodsInArray del panel. En PG con UUIDs el `enc` legacy era
     * no-op (sólo aplicaba a tipos numéricos MySQL viejos) → no se reproduce.
     */
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
            "SELECT transactionParentId, SUM(transactionTotal) as payed
             FROM transaction WHERE transactionParentId IN ($ph) AND companyId = ?
             GROUP BY transactionParentId",
            array_merge($ids, [$companyId]), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionParentId']] = (float) $r['payed'];
        }
        return $map;
    }

    private function parentInvoices(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix, supplierId
             FROM transaction WHERE transactionId IN ($ph) AND transactionType = 4 AND companyId = ?",
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
                'name' => (string) ($c['contactName'] ?? ''),
                'tin'  => (string) ($c['contactTIN'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * itemId → itemName, scopeado por companyId. Reemplaza getAllItems(false, true, $ids, true)
     * del panel — sólo se usa el itemName.
     */
    private function itemNames(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT itemId, itemName FROM item WHERE companyId = ? AND itemId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['itemId']] = (string) ($r['itemName'] ?? '');
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

    /**
     * Cache por instancia del nombre de taxonomía. Lookup directo bindeado por companyId del
     * contexto: NO usa el global `getTaxonomyName` (que delega a Punto\App\Domain\Taxonomy y
     * lee `$SQLcompanyId` del global — pero `apiAuthTenant` define ese SQL como variable local,
     * por lo que el query saldría con WHERE roto y devolvería 'None' para todo). Bug descubierto
     * en batch 14 con ProductsService; aplicado preventivamente acá.
     */
    private function taxonomyName($id, string $companyId): string
    {
        $id = (string) $id;
        if ($id === '') {
            return '';
        }
        if (!array_key_exists($id, $this->taxonomyCache)) {
            $r = ncmExecute(
                "SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1",
                [$id, $companyId]
            );
            $name = $r ? (string) ($r['taxonomyName'] ?? '') : '';
            $this->taxonomyCache[$id] = ($name === '' || $name === 'None') ? '' : toUTF8($name);
        }
        return $this->taxonomyCache[$id];
    }

    /** Port fiel de dec (identity). */
    private static function dec($str): string
    {
        return (string) $str;
    }
}
