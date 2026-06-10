<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Productos (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportProductsService.php (Fase 2 batch 14). Cambios vs original:
 *  - namespace + `final`
 *  - ROC y companyId por PARÁMETRO en las 3 vistas (no globals).
 *  - `getPreviousPeriod` y `lessInternalTotals` (panel-only) → `NonAddingSales::previousPeriod`
 *    y `NonAddingSales::lessInternalTotals` (helper compartido del batch 7, expuestos públicos
 *    en este batch — mismo patrón que `salesByPayment` en batch 8).
 *  - `getAllItems(false, true, $ids, true)` → `itemMeta` lookup batch directo (sólo se usan
 *    itemName, itemSKU, itemPrice, itemType, brandId, categoryId, taxId).
 *  - `getAllTax()` (panel-only) → `taxNames($companyId)` private inline.
 *  - `getTaxOfPrice` (panel-only) → `taxOfPrice` private static (port fiel, 7 líneas).
 *  - `getAllCombosCompoundsDiscount` (roto en PG en el panel) → no se porta (mismo comentario
 *    del panel original: "se OMITE, igual que brands/categories").
 *  - `getTaxValue`, `getTaxonomyName` resuelven por fallback de namespace (en /app).
 *
 * 3 vistas: general (agregado por producto + período anterior + internas), detail (líneas
 * crudas con meta), combos (igual que general pero sólo ítems combo/precombo/comboAddons).
 *
 * Tenant: $roc por query; companyId bound en lookups.
 */
final class ProductsService
{
    private const TX_TYPES = '0,3,6';

    private array $taxonomyCache = [];

    /** $roc del endpoint, calificado por alias de la tabla `transaction`. */
    private function rocAlias(string $roc, string $alias): string
    {
        return str_replace(
            ['registerId', 'outletId', 'companyId'],
            [$alias . '.registerId', $alias . '.outletId', $alias . '.companyId'],
            $roc
        );
    }

    /** Vista agregada. */
    public function general(array $filters, $from, $to, string $roc, string $companyId): array
    {
        [$rows, $isMonth] = $this->aggregate($filters, $from, $to, $roc);
        $withMeta = $this->attachMeta($rows, $companyId);
        foreach ($withMeta as &$gr) {
            $gr['utility'] = ($gr['total'] - $gr['cogs']) - $gr['comission'];
        }
        unset($gr);
        $out = ['rows' => $withMeta, 'month' => $isMonth];

        if (!$filters['cusId'] && !$filters['usrId'] && !$filters['itmId']) {
            [$prevStart, $prevEnd] = NonAddingSales::previousPeriod($from, $to);
            [$prevRows] = $this->aggregate($filters, $prevStart, $prevEnd, $roc);
            $out['prev']      = $this->prevTotals($prevRows, $companyId);
            $out['internals'] = $this->internals($from, $to, $roc);
            $byItem = [];
            foreach ($prevRows as $pr) {
                if ($pr['total'] > 0) { $byItem[$pr['id']] = $pr['usold']; }
            }
            $out['prevByItem'] = $byItem;
        }

        return $out;
    }

    /** Igual que general() pero sólo ítems combo/precombo/comboAddons. */
    public function combos(array $filters, $from, $to, string $roc, string $companyId): array
    {
        [$rows, $isMonth] = $this->aggregate($filters, $from, $to, $roc);
        $withMeta = $this->attachMeta($rows, $companyId);
        $combo = array_values(array_filter(
            $withMeta,
            fn($r) => in_array($r['itemType'], ['combo', 'precombo', 'comboAddons'], true)
        ));
        foreach ($combo as &$cr) {
            $cr['utility'] = (($cr['total'] - $cr['tax']) - $cr['cogs']) - $cr['comission'];
        }
        unset($cr);
        return ['rows' => $combo, 'month' => $isMonth];
    }

    /** Construye el agregado por producto según el modo de filtro. */
    private function aggregate(array $f, $from, $to, string $roc): array
    {
        $rocB    = $this->rocAlias($roc, 'b');
        $isMonth = false;

        $selFlat  = "SUM(a.itemSoldUnits) as usold, SUM(a.itemSoldTotal) as total,
                     SUM(a.itemSoldTax) as tax, SUM(ABS(a.itemSoldCOGS)) as cogs,
                     SUM(a.itemSoldComission) as comission, SUM(a.itemSoldDiscount) as discount";
        $selUnits = "SUM(a.itemSoldUnits) as usold, SUM(a.itemSoldTotal) as total,
                     SUM(a.itemSoldTax) as tax, SUM(ABS(a.itemSoldCOGS) * a.itemSoldUnits) as cogs,
                     SUM(a.itemSoldComission) as comission, SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount";

        if ($f['cusId']) {
            $sql = "SELECT a.itemId as id, $selFlat
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ") AND b.customerId = ?" . $rocB . "
                    AND b.transactionId = a.transactionId
                    GROUP BY id ORDER BY usold DESC";
            $params = [$f['cusId']];
        } elseif ($f['usrId']) {
            $sql = "SELECT a.itemId as id, $selFlat
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")
                    AND b.transactionDate BETWEEN ? AND ? AND b.userId = ?" . $rocB . "
                    AND a.transactionId = b.transactionId
                    GROUP BY id ORDER BY usold DESC";
            $params = [$from, $to, $f['usrId']];
        } elseif ($f['itmId'] && $f['month']) {
            $sel = $selUnits;
            $isMonth = true;
            $year    = (int) ($f['year'] ?: date('Y'));
            $sql = "SELECT a.itemId as id, EXTRACT(MONTH FROM a.itemSoldDate)::int as smonth, $sel
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")" . $rocB . "
                    AND a.transactionId = b.transactionId
                    AND EXTRACT(YEAR FROM a.itemSoldDate) = ? AND a.itemId = ?
                    GROUP BY smonth, id ORDER BY smonth ASC";
            $params = [$year, $f['itmId']];
        } elseif ($f['itmId']) {
            $sql = "SELECT a.itemId as id, $selUnits
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")" . $rocB . "
                    AND a.itemId = ? AND a.transactionId = b.transactionId
                    GROUP BY id ORDER BY usold DESC";
            $params = [$f['itmId']];
        } else {
            $sql = "SELECT a.itemId as id, $selUnits
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")
                    AND b.transactionDate BETWEEN ? AND ?" . $rocB . "
                    AND a.transactionId = b.transactionId
                    GROUP BY id ORDER BY usold DESC";
            $params = [$from, $to];
        }

        $res  = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $fld = $res->fields;
                $rows[] = [
                    'id'        => (string) $fld['id'],
                    'smonth'    => isset($fld['smonth']) ? (int) $fld['smonth'] : null,
                    'usold'     => (float) $fld['usold'],
                    'total'     => (float) $fld['total'],
                    'tax'       => (float) $fld['tax'],
                    'cogs'      => (float) $fld['cogs'],
                    'comission' => (float) $fld['comission'],
                    'discount'  => (float) $fld['discount'],
                ];
                $res->MoveNext();
            }
            $res->Close();
        }
        return [$rows, $isMonth];
    }

    private function prevTotals(array $rows, string $companyId): array
    {
        if (!$rows) {
            return ['total' => 0, 'cogs' => 0, 'tax' => 0, 'discount' => 0, 'comission' => 0, 'usold' => 0, 'utility' => 0];
        }
        $meta = $this->itemMeta(array_column($rows, 'id'), $companyId);
        $t = ['total' => 0, 'cogs' => 0, 'tax' => 0, 'discount' => 0, 'comission' => 0, 'usold' => 0, 'utility' => 0];
        foreach ($rows as $r) {
            $type = $meta[$r['id']]['itemType'] ?? '';
            $uSold = $r['usold']; $discount = $r['discount']; $total = $r['total'];
            $comission = $r['comission']; $tax = $r['tax']; $cogs = $r['cogs'];
            $utility = (($total - $tax) - $cogs) - $comission;
            if (in_array($type, ['precombo', 'combo'], true)) {
                $discount = 0; $comission = 0; $utility = 0; $cogs = 0; $tax = 0;
            }
            $t['usold'] += $uSold; $t['tax'] += $tax; $t['discount'] += $discount;
            $t['total'] += $total; $t['comission'] += $comission; $t['cogs'] += $cogs; $t['utility'] += $utility;
        }
        return $t;
    }

    /** Internas (lessInternalTotals del helper compartido NonAddingSales — PG-correcto). */
    private function internals($from, $to, string $roc): array
    {
        $i = NonAddingSales::lessInternalTotals($roc, $from, $to);
        return [
            'total'    => (float) ($i['total'] ?? 0),
            'qty'      => (float) ($i['qty'] ?? 0),
            'tax'      => (float) ($i['tax'] ?? 0),
            'discount' => (float) ($i['discount'] ?? 0),
        ];
    }

    /** Vista detallada: una fila por línea de venta. */
    public function detail(array $filters, $from, $to, string $roc, string $companyId): array
    {
        $rocA = $this->rocAlias($roc, 'a');
        $sel = "a.customerId as customer, a.userId as trsUser, a.outletId, a.registerId,
                a.invoiceNo, a.invoicePrefix, a.transactionType, a.transactionId,
                b.itemSoldId, b.itemId, b.itemSoldUnits, b.itemSoldTotal, b.itemSoldTax,
                b.itemSoldDiscount, b.itemSoldDate, b.itemSoldDescription, b.itemSoldParent,
                ABS(b.itemSoldCOGS) as itemSoldCOGS, b.itemSoldComission, b.userId as itemUser";

        if ($filters['src']) {
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT $sel
                    FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $rocA . "
                    AND a.transactionType IN (" . self::TX_TYPES . ") AND a.transactionId = b.transactionId
                    AND b.itemId IN (SELECT itemId FROM item WHERE (itemName ILIKE ? OR itemSKU ILIKE ?) AND companyId = ? AND itemStatus = 1)
                    ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$from, $to, $like, $like, $companyId];
        } elseif ($filters['cusId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND a.customerId = ?" . $rocA . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$filters['cusId']];
        } elseif ($filters['usrId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ? AND a.transactionType IN (" . self::TX_TYPES . ")" . $rocA . "
                    AND a.transactionId = b.transactionId AND b.userId = ? ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$from, $to, $filters['usrId']];
        } elseif ($filters['itmId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND b.itemId = ?" . $rocA . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$filters['itmId']];
        } else {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $rocA . "
                    AND a.transactionType IN (" . self::TX_TYPES . ") AND a.transactionId = b.transactionId
                    ORDER BY a.transactionDate DESC LIMIT 2000";
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

        $items   = $this->itemMeta(array_map(fn($l) => (string) $l['itemId'], $lines), $companyId);
        $custIds = array_values(array_unique(array_filter(array_map(fn($l) => (string) $l['customer'], $lines))));
        $custs   = $this->contactNames($custIds, $companyId);
        $userIds = array_values(array_unique(array_filter(array_map(fn($l) => (string) ($l['itemUser'] ?: $l['trsUser']), $lines))));
        $users   = $this->contactNames($userIds, $companyId);
        $outlets = $this->nameMap('outlet',   'outletId',   'outletName',   array_map(fn($l) => (string) $l['outletId'], $lines), $companyId);
        $regs    = $this->nameMap('register', 'registerId', 'registerName', array_map(fn($l) => (string) $l['registerId'], $lines), $companyId);
        $taxes   = $this->taxNames($companyId);

        $rows = [];
        foreach ($lines as $l) {
            $iid  = (string) $l['itemId'];
            $itm  = $items[$iid] ?? null;
            $type = $itm ? (string) ($itm['itemType'] ?? '') : '';
            $uSold = (float) $l['itemSoldUnits'];
            $total = (float) $l['itemSoldTotal'];
            $tax   = (float) $l['itemSoldTax'];

            if ($tax >= $total && $itm) {
                $tax = self::taxOfPrice((float) getTaxValue($itm['taxId'] ?? null), $total);
            }

            $cogs      = (float) $l['itemSoldCOGS'] * $uSold;
            $comission = (float) $l['itemSoldComission'];
            $discount  = (float) $l['itemSoldDiscount'] * $uSold;
            $name      = $itm ? (string) $itm['itemName'] : ((!$l['itemId'] && $l['itemSoldDescription']) ? (string) $l['itemSoldDescription'] : '');
            $utility   = ($total - $cogs) - $comission;

            $parent = (string) ($l['itemSoldParent'] ?? '');
            if (in_array($type, ['precombo', 'combo'], true)) {
                $cogs = 0; $tax = 0; $discount = 0; $comission = 0; $utility = 0;
            } elseif ($parent !== '' && $type !== 'comboAddons') {
                $utility = 0; $total = 0;
                $name = '↳ ' . $name;
            }

            $uid = (string) ($l['itemUser'] ?: $l['trsUser']);
            $rows[] = [
                'transactionId' => (string) $l['transactionId'],
                'outletName'    => $outlets[(string) $l['outletId']] ?? '',
                'registerName'  => $regs[(string) $l['registerId']] ?? '',
                'invoiceNo'     => (string) $l['invoicePrefix'] . (string) $l['invoiceNo'],
                'userName'      => $users[$uid] ?? '',
                'customerName'  => $custs[(string) $l['customer']] ?? '',
                'date'          => (string) $l['itemSoldDate'],
                'name'          => $name,
                'deleted'       => $itm ? false : (bool) ($l['itemId'] && !$l['itemSoldDescription']),
                'sku'           => $itm ? (string) ($itm['itemSKU'] ?? '') : '',
                'brand'         => $itm ? ($this->tname($itm['brandId'] ?? null, $companyId)) : '',
                'category'      => $itm ? ($this->tname($itm['categoryId'] ?? null, $companyId)) : '',
                'usold'         => $uSold,
                'comission'     => $comission,
                'cogs'          => $cogs,
                'tax'           => $tax,
                'taxName'       => $itm ? (string) ($taxes[$itm['taxId']]['name'] ?? '') : '',
                'discount'      => $discount,
                'utility'       => $utility,
                'total'         => $total,
            ];
        }

        return ['rows' => $rows];
    }

    /* ───────────── helpers ───────────── */

    private function attachMeta(array $rows, string $companyId): array
    {
        if (!$rows) {
            return [];
        }
        $meta  = $this->itemMeta(array_column($rows, 'id'), $companyId);
        $taxes = $this->taxNames($companyId);
        $out = [];
        foreach ($rows as $r) {
            $m = $meta[$r['id']] ?? null;
            $out[] = array_merge($r, [
                'name'     => $m ? (string) $m['itemName'] : '',
                'deleted'  => $m ? false : true,
                'sku'      => $m ? (string) ($m['itemSKU'] ?? '') : '',
                'brand'    => $m ? $this->tname($m['brandId'] ?? null, $companyId) : '',
                'category' => $m ? $this->tname($m['categoryId'] ?? null, $companyId) : '',
                'price'    => $m ? (float) ($m['itemPrice'] ?? 0) : 0,
                'taxName'  => $m ? (string) ($taxes[$m['taxId']]['name'] ?? '') : '',
                'itemType' => $m ? (string) ($m['itemType'] ?? '') : '',
            ]);
        }
        return $out;
    }

    /**
     * Lookup batch itemId → meta. Reemplaza getAllItems(false, true, $ids, true).
     * Devuelve TODOS los campos del item (igual que el original) para que attachMeta y prevTotals
     * accedan a itemName/itemSKU/itemPrice/itemType/brandId/categoryId/taxId.
     */
    private function itemMeta(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT * FROM item WHERE companyId = ? AND itemId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['itemId']] = $r;
        }
        return $map;
    }

    /** Taxes (taxonomy type='tax') → taxonomyId → {name}. Reemplaza getAllTax(). */
    private function taxNames(string $companyId): array
    {
        $res = ncmExecute(
            "SELECT taxonomyName, taxonomyId FROM taxonomy WHERE taxonomyType = 'tax' AND companyId = ? ORDER BY taxonomyName ASC LIMIT 100",
            [$companyId], false, true
        );
        $tax = [];
        $added = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f = $res->fields;
                if (!in_array($f['taxonomyName'], $added, true)) {
                    $tax[(string) $f['taxonomyId']] = ['name' => toUTF8($f['taxonomyName'])];
                    $added[] = $f['taxonomyName'];
                }
                $res->MoveNext();
            }
            $res->Close();
        }
        return $tax;
    }

    /**
     * Lookup directo por id (sin getTaxonomyName global): la versión moderna en /app delega a
     * `Punto\App\Domain\Taxonomy::getName` que lee `$SQLcompanyId` del global. En /api, ese
     * global está vacío (apiAuthTenant define $SQLcompanyId como local de función) → la query
     * sale `WHERE taxonomyId = ? AND ` (sintaxis rota) → null → 'None'. Acá hacemos un SELECT
     * directo bindeado por companyId del contexto.
     */
    private function tname($id, string $companyId): string
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
            $this->taxonomyCache[$id] = $name !== '' ? toUTF8($name) : 'None';
        }
        return $this->taxonomyCache[$id];
    }

    private function contactNames(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, contactSecondName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = trim(((string) ($c['contactName'] ?? '')) . ' ' . ((string) ($c['contactSecondName'] ?? '')));
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

    /** Port fiel de getTaxOfPrice del panel (tax includido en precio → magnitud del IVA). */
    private static function taxOfPrice(float $tax, float $price): float
    {
        if ($tax > 0 && $price) {
            $taxVal = $price / (1 + ($tax / 100));
            $total  = $price - $taxVal;
            return $total > 0 ? $total : 0.0;
        }
        return 0.0;
    }
}
