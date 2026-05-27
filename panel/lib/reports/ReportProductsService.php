<?php
/**
 * Dominio de Reportes — Productos / Reporte de Artículos (capa API, motor ERP).
 *
 * Tres vistas, todas sobre itemSold ⋈ transaction (tipos 0,3,6):
 *   - general(): agregado por producto (unidades, total, tax, COGS, comisión, descuento) +
 *     período anterior (modo default) + internas (lessInternalTotals). Modos de filtro:
 *     default (período + anterior), cusId (cliente, all-time), usrId (usuario, período),
 *     itmId (un ítem), itmId+month (desglose mensual del año).
 *   - detail(): líneas de venta crudas (una por itemSold) con meta + nombres resueltos.
 *   - combos(): igual que general pero sólo ítems combo/precombo/comboAddons.
 *
 * Devuelve datos CRUDOS (números, sin formatear, sin HTML). El BFF calcula utilidad/KPIs/chart
 * y el front formatea + arma tablas/tabs. Ver REGLA RAÍZ 2.
 *
 * Reemplaza panel/a_report_products.php (action=generalTable/detailTable/combosTable).
 *
 * Fixes PG (las queries legacy estaban rotas en Postgres):
 *  - `USE INDEX(...)` (MySQL) eliminado.
 *  - `MONTH()`/`YEAR()` → EXTRACT(MONTH/YEAR FROM ...) (modo mensual).
 *  - detail default seleccionaba `a.tags` (columna movida a `meta` JSONB) → no se selecciona.
 *  - búsqueda `src` legacy tenía el término LITERAL dentro de un string single-quote
 *    (`'... LIKE \'%\' . $word . \'%\''` no interpolaba) → acá es un ILIKE parametrizado.
 *  - getAllCombosCompoundsDiscount() está roto en PG (`itemSoldParent != 0`: UUID vs int) →
 *    se OMITE (compoundsDiscount = []), igual que brands/categories. Afecta sólo el ajuste de
 *    combos en el período anterior (flechas de comparación), no los totales mostrados.
 *  - el self-heal de itemSoldTax (ncmUpdate durante el read, sin companyId + LIMIT inválido en
 *    PG DELETE/UPDATE) se elimina: el tax corregido se calcula sólo para DISPLAY, sin escribir.
 *
 * Tenant: $roc (getROC) calificado por alias de la tabla transaction en cada query; companyId
 * bound en los lookups de meta.
 */
class ReportProductsService
{
    private const TX_TYPES = '0,3,6';

    /** getROC(1) con companyId/outletId/registerId calificados para el alias de `transaction`. */
    private function roc($alias)
    {
        return str_replace(
            ['registerId', 'outletId', 'companyId'],
            [$alias . '.registerId', $alias . '.outletId', $alias . '.companyId'],
            getROC(1)
        );
    }

    /**
     * Vista agregada por producto. $filters: ['cusId'=>uuid, 'usrId'=>uuid, 'itmId'=>uuid,
     * 'month'=>bool, 'year'=>int]. Devuelve filas crudas + meta; en modo default agrega `prev`
     * (totales del período anterior) e `internals`.
     */
    public function general(array $filters, $from, $to)
    {
        [$rows, $isMonth] = $this->aggregate($filters, $from, $to, false);
        $withMeta = $this->attachMeta($rows);
        // Utilidad general = (total − COGS) − comisión (el tax NO se resta; el legacy main loop
        // no aplica zeroing de combos — sólo el período anterior lo hace, ver prevTotals()).
        foreach ($withMeta as &$gr) {
            $gr['utility'] = ($gr['total'] - $gr['cogs']) - $gr['comission'];
        }
        unset($gr);
        $out = ['rows' => $withMeta, 'month' => $isMonth];

        // Período anterior + internas: sólo en el modo default (sin filtros de cliente/usuario/ítem).
        if (!$filters['cusId'] && !$filters['usrId'] && !$filters['itmId']) {
            [$prevStart, $prevEnd] = getPreviousPeriod($from, $to);
            [$prevRows] = $this->aggregate($filters, $prevStart, $prevEnd, false);
            $out['prev']      = $this->prevTotals($prevRows);
            $out['internals'] = $this->internals($from, $to);
            // usold previo por itemId (para la línea de comparación del chart).
            $byItem = [];
            foreach ($prevRows as $pr) {
                if ($pr['total'] > 0) { $byItem[$pr['id']] = $pr['usold']; }
            }
            $out['prevByItem'] = $byItem;
        }

        return $out;
    }

    /** Igual que general() pero sólo ítems combo/precombo/comboAddons. */
    public function combos(array $filters, $from, $to)
    {
        [$rows, $isMonth] = $this->aggregate($filters, $from, $to, false);
        $withMeta = $this->attachMeta($rows);
        $combo = array_values(array_filter(
            $withMeta,
            fn($r) => in_array($r['itemType'], ['combo', 'precombo', 'comboAddons'], true)
        ));
        // Utilidad combos = ((total − tax) − COGS) − comisión (el tax SÍ se resta, a diferencia de general).
        foreach ($combo as &$cr) {
            $cr['utility'] = (($cr['total'] - $cr['tax']) - $cr['cogs']) - $cr['comission'];
        }
        unset($cr);
        return ['rows' => $combo, 'month' => $isMonth];
    }

    /** Construye el agregado por producto según el modo de filtro. @return [rows[], isMonth bool] */
    private function aggregate(array $f, $from, $to, $unusedCombos)
    {
        $roc     = $this->roc('b');
        $isMonth = false;
        // COGS y descuento: en los modos cliente/usuario el legacy NO multiplica por unidades;
        // en default/ítem/mensual SÍ (`* a.itemSoldUnits`). Se respeta esa asimetría (afecta los números).
        $selFlat  = "SUM(a.itemSoldUnits) as usold, SUM(a.itemSoldTotal) as total,
                     SUM(a.itemSoldTax) as tax, SUM(ABS(a.itemSoldCOGS)) as cogs,
                     SUM(a.itemSoldComission) as comission, SUM(a.itemSoldDiscount) as discount";
        $selUnits = "SUM(a.itemSoldUnits) as usold, SUM(a.itemSoldTotal) as total,
                     SUM(a.itemSoldTax) as tax, SUM(ABS(a.itemSoldCOGS) * a.itemSoldUnits) as cogs,
                     SUM(a.itemSoldComission) as comission, SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount";

        if ($f['cusId']) {
            // Cliente: all-time (el legacy no filtra fecha en este modo). SUM sin *units.
            $sql = "SELECT a.itemId as id, $selFlat
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ") AND b.customerId = ?" . $roc . "
                    AND b.transactionId = a.transactionId
                    GROUP BY id ORDER BY usold DESC";
            $params = [$f['cusId']];
        } elseif ($f['usrId']) {
            // Usuario: SUM sin *units.
            $sql = "SELECT a.itemId as id, $selFlat
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")
                    AND b.transactionDate BETWEEN ? AND ? AND b.userId = ?" . $roc . "
                    AND a.transactionId = b.transactionId
                    GROUP BY id ORDER BY usold DESC";
            $params = [$from, $to, $f['usrId']];
        } elseif ($f['itmId'] && $f['month']) {
            $sel = $selUnits;
            $isMonth = true;
            $year    = (int) ($f['year'] ?: date('Y'));
            $sql = "SELECT a.itemId as id, EXTRACT(MONTH FROM a.itemSoldDate)::int as smonth, $sel
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")" . $roc . "
                    AND a.transactionId = b.transactionId
                    AND EXTRACT(YEAR FROM a.itemSoldDate) = ? AND a.itemId = ?
                    GROUP BY smonth, id ORDER BY smonth ASC";
            $params = [$year, $f['itmId']];
        } elseif ($f['itmId']) {
            $sql = "SELECT a.itemId as id, $selUnits
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")" . $roc . "
                    AND a.itemId = ? AND a.transactionId = b.transactionId
                    GROUP BY id ORDER BY usold DESC";
            $params = [$f['itmId']];
        } else {
            $sql = "SELECT a.itemId as id, $selUnits
                    FROM itemSold a, transaction b
                    WHERE b.transactionType IN (" . self::TX_TYPES . ")
                    AND b.transactionDate BETWEEN ? AND ?" . $roc . "
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

    /** Totales del período anterior (combos en 0, igual que el legacy resultB). */
    private function prevTotals(array $rows)
    {
        if (!$rows) {
            return ['total' => 0, 'cogs' => 0, 'tax' => 0, 'discount' => 0, 'comission' => 0, 'usold' => 0, 'utility' => 0];
        }
        $meta = $this->itemMeta(array_column($rows, 'id'));
        $t = ['total' => 0, 'cogs' => 0, 'tax' => 0, 'discount' => 0, 'comission' => 0, 'usold' => 0, 'utility' => 0];
        foreach ($rows as $r) {
            $type = $meta[$r['id']]['itemType'] ?? '';
            $uSold = $r['usold']; $discount = $r['discount']; $total = $r['total'];
            $comission = $r['comission']; $tax = $r['tax']; $cogs = $r['cogs'];
            $utility = (($total - $tax) - $cogs) - $comission;   // fórmula del período anterior (legacy)
            if (in_array($type, ['precombo', 'combo'], true)) {
                $discount = 0; $comission = 0; $utility = 0; $cogs = 0; $tax = 0;
            }
            $t['usold'] += $uSold; $t['tax'] += $tax; $t['discount'] += $discount;
            $t['total'] += $total; $t['comission'] += $comission; $t['cogs'] += $cogs; $t['utility'] += $utility;
        }
        return $t;
    }

    /** Ventas internas a restar de los totales (lessInternalTotals ya es PG-safe). */
    private function internals($from, $to)
    {
        $i = lessInternalTotals(getROC(1), $from, $to);
        return [
            'total'    => (float) ($i['total'] ?? 0),
            'qty'      => (float) ($i['qty'] ?? 0),
            'tax'      => (float) ($i['tax'] ?? 0),
            'discount' => (float) ($i['discount'] ?? 0),
        ];
    }

    /** Vista detallada: una fila por línea de venta. $filters como general + 'src' (búsqueda). */
    public function detail(array $filters, $from, $to)
    {
        $roc = $this->roc('a');   // en detail, transaction es el alias `a`
        $sel = "a.customerId as customer, a.userId as trsUser, a.outletId, a.registerId,
                a.invoiceNo, a.invoicePrefix, a.transactionType, a.transactionId,
                b.itemSoldId, b.itemId, b.itemSoldUnits, b.itemSoldTotal, b.itemSoldTax,
                b.itemSoldDiscount, b.itemSoldDate, b.itemSoldDescription, b.itemSoldParent,
                ABS(b.itemSoldCOGS) as itemSoldCOGS, b.itemSoldComission, b.userId as itemUser";

        if ($filters['src']) {
            // Búsqueda por nombre/SKU: subquery de itemIds parametrizada (el legacy tenía el término literal).
            $like = '%' . $filters['src'] . '%';
            $sql = "SELECT $sel
                    FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $roc . "
                    AND a.transactionType IN (" . self::TX_TYPES . ") AND a.transactionId = b.transactionId
                    AND b.itemId IN (SELECT itemId FROM item WHERE (itemName ILIKE ? OR itemSKU ILIKE ?) AND companyId = ? AND itemStatus = 1)
                    ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$from, $to, $like, $like, COMPANY_ID];
        } elseif ($filters['cusId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND a.customerId = ?" . $roc . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$filters['cusId']];
        } elseif ($filters['usrId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ? AND a.transactionType IN (" . self::TX_TYPES . ")" . $roc . "
                    AND a.transactionId = b.transactionId AND b.userId = ? ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$from, $to, $filters['usrId']];
        } elseif ($filters['itmId']) {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionType IN (" . self::TX_TYPES . ") AND b.itemId = ?" . $roc . "
                    AND a.transactionId = b.transactionId ORDER BY a.transactionDate DESC LIMIT 2000";
            $params = [$filters['itmId']];
        } else {
            $sql = "SELECT $sel FROM transaction a, itemSold b
                    WHERE a.transactionDate BETWEEN ? AND ?" . $roc . "
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

        // Resolución de meta en batch.
        $items   = $this->itemMeta(array_map(fn($l) => (string) $l['itemId'], $lines));
        $custIds = array_values(array_unique(array_filter(array_map(fn($l) => (string) $l['customer'], $lines))));
        $custs   = $this->contactNames($custIds);
        $userIds = array_values(array_unique(array_filter(array_map(fn($l) => (string) ($l['itemUser'] ?: $l['trsUser']), $lines))));
        $users   = $this->contactNames($userIds);
        $outlets = $this->nameMap('outlet',   'outletId',   'outletName',   array_map(fn($l) => (string) $l['outletId'], $lines));
        $regs    = $this->nameMap('register', 'registerId', 'registerName', array_map(fn($l) => (string) $l['registerId'], $lines));
        $taxes   = getAllTax();

        $rows = [];
        foreach ($lines as $l) {
            $iid  = (string) $l['itemId'];
            $itm  = $items[$iid] ?? null;
            $type = $itm ? (string) ($itm['itemType'] ?? '') : '';
            $uSold = (float) $l['itemSoldUnits'];
            $total = (float) $l['itemSoldTotal'];
            $tax   = (float) $l['itemSoldTax'];

            // Self-heal de display (sin escribir): si el tax guardado es >= total, se recalcula.
            if ($tax >= $total && $itm) {
                $tax = (float) getTaxOfPrice(getTaxValue($itm['taxId'] ?? null), $total);
            }

            $cogs      = (float) $l['itemSoldCOGS'] * $uSold;
            $comission = (float) $l['itemSoldComission'];
            $discount  = (float) $l['itemSoldDiscount'] * $uSold;
            $name      = $itm ? (string) $itm['itemName'] : ((!$l['itemId'] && $l['itemSoldDescription']) ? (string) $l['itemSoldDescription'] : '');
            // Utilidad detalle = (total − COGS) − comisión (sin tax, igual que general).
            $utility   = ($total - $cogs) - $comission;

            // Special-casing del legacy: combos no aportan costos/utilidad; las líneas hijas
            // (itemSoldParent) no aportan total/utilidad (se contabilizan en el combo padre).
            $parent = (string) ($l['itemSoldParent'] ?? '');
            if (in_array($type, ['precombo', 'combo'], true)) {
                $cogs = 0; $tax = 0; $discount = 0; $comission = 0; $utility = 0;
            } elseif ($parent !== '' && $type !== 'comboAddons') {
                // El legacy captura comboAddons en una rama previa (no zerea); sólo ítems "planos"
                // con parent se contabilizan en el combo padre (utility/total = 0).
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
                'brand'         => $itm ? ($this->taxonomyName($itm['brandId'] ?? null)) : '',
                'category'      => $itm ? ($this->taxonomyName($itm['categoryId'] ?? null)) : '',
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

    /* ─────────────── helpers de meta (lookups batch parametrizados) ─────────────── */

    /** Adjunta meta (nombre/sku/marca/categoría/precio/tax/tipo) a filas agregadas por itemId. */
    private function attachMeta(array $rows)
    {
        if (!$rows) {
            return [];
        }
        $meta  = $this->itemMeta(array_column($rows, 'id'));
        $taxes = getAllTax();
        $out = [];
        foreach ($rows as $r) {
            $m = $meta[$r['id']] ?? null;
            $out[] = array_merge($r, [
                'name'     => $m ? (string) $m['itemName'] : '',
                'deleted'  => $m ? false : true,
                'sku'      => $m ? (string) ($m['itemSKU'] ?? '') : '',
                'brand'    => $m ? $this->taxonomyName($m['brandId'] ?? null) : '',
                'category' => $m ? $this->taxonomyName($m['categoryId'] ?? null) : '',
                'price'    => $m ? (float) ($m['itemPrice'] ?? 0) : 0,
                'taxName'  => $m ? (string) ($taxes[$m['taxId']]['name'] ?? '') : '',
                'itemType' => $m ? (string) ($m['itemType'] ?? '') : '',
            ]);
        }
        return $out;
    }

    /** itemId → fila item completa (getAllItems ya es PG-safe: companyId bound, IN parametrizado). */
    private function itemMeta(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        return getAllItems(false, true, implode(',', $ids), true);
    }

    /** Nombre de taxonomía (marca/categoría) cacheado. */
    private $taxonomyCache = [];
    private function taxonomyName($id)
    {
        $id = (string) $id;
        if ($id === '') {
            return '';
        }
        if (!array_key_exists($id, $this->taxonomyCache)) {
            $this->taxonomyCache[$id] = (string) getTaxonomyName($id, false, false, true);
        }
        return $this->taxonomyCache[$id];
    }

    /** Lookup batch contactId → nombre completo, scopeado por companyId. */
    private function contactNames(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, contactSecondName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = trim(((string) ($c['contactName'] ?? '')) . ' ' . ((string) ($c['contactSecondName'] ?? '')));
        }
        return $map;
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
}
