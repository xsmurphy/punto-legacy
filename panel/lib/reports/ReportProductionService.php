<?php
/**
 * Dominio de Reportes — Producción / Production (capa API, motor ERP).
 *
 * 3 vistas de LECTURA del módulo legacy a_report_production.php:
 *   - general(): producción agregada por ítem (tabla `production` tipo 1 + ventas de ítems
 *     `direct_production`) con utilidad/costo por ítem.
 *   - detail():  ventas de ítems `direct_production` línea por línea.
 *   - compound($byDay): compuestos producidos (de `production.productionRecipe` + stock con
 *     stockSource='production'), opcionalmente agrupado por día.
 *
 * NO se migran (legacy vía ?action=): el modal de receta (`recipe`), el export XLSX y el
 * write (`delete`). Devuelve datos CRUDOS; el front formatea + arma tablas/KPIs. Ver REGLA RAÍZ 2.
 *
 * Fórmula de utilidad (réplica de buildTableList legacy): por unidad
 *   utility = ((itemPrice − average) − comisión) − impuesto;  average = COGS/units;
 *   el total mostrado es utility * units. ⚠️ El módulo de producción está deshabilitado en la
 *   empresa de prueba → la fórmula se portó FIEL al legacy pero NO se validó con datos reales.
 *
 * Fixes PG respecto del legacy (estaba roto en varios puntos):
 *  - compuestos: `$db->GetAssoc()` keyea por la 1ª columna (itemId) dejándolo FUERA del value →
 *    el IN() quedaba vacío; acá los itemIds se leen bien y se bindean parametrizados.
 *  - `stockSource = \'production\'` (en string PHP doble-comilla los `\'` quedan como backslash
 *    literal → error PG) → bound param 'production'.
 *  - `$roc` sin calificar era ambiguo en los JOIN (transaction/item ambos tienen companyId) →
 *    calificado por alias `b.` (transaction).
 *  - meta de ítem vía getItemData() (aplana JSONB; itemTax/itemComission/categoryId demovidos).
 *
 * Tenant: $roc (getROC) por query; companyId bound en cada lookup.
 */
class ReportProductionService
{
    /** Producción agregada por ítem (tab "General"). Devuelve filas + totales (qty/cogs/utility). */
    public function general($from, $to)
    {
        $items = [];   // itemId => ['units','cogs','type']

        // (1) Producción "previa" registrada en la tabla `production` (tipo 1).
        $sql = "SELECT itemId, productionCount, productionCOGS, productionType
                FROM production
                WHERE productionDate BETWEEN ? AND ? AND productionType = true" . getROC(1);
        $res = ncmExecute($sql, [$from, $to], false, false, true);
        foreach (is_array($res) ? $res : [] as $f) {
            $id = (string) $f['itemId'];
            if (isset($items[$id])) {
                $items[$id]['units'] += (float) $f['productionCount'];
                $items[$id]['cogs']  += (float) $f['productionCOGS'];
            } else {
                $items[$id] = ['units' => (float) $f['productionCount'], 'cogs' => (float) $f['productionCOGS'], 'type' => (string) $f['productionType']];
            }
        }

        // (2) Producción "directa": ventas de ítems itemType='direct_production' (agregadas por ítem).
        $rocB = $this->rocAlias('b');
        // Agregado por ítem (GROUP BY itemId solo): una fila por ítem acumulando TODOS los usuarios
        // (el general no muestra usuario; agrupar también por userId duplicaría/pisaría el ítem).
        $sql = "SELECT a.itemId as id, SUM(a.itemSoldUnits) as usold, SUM(a.itemSoldCOGS) as cogs,
                       MAX(a.userId) as usr, MAX(a.itemSoldDate) as sdate, MAX(b.outletId) as outlet
                FROM itemSold a, transaction b, item c
                WHERE b.transactionType IN (0,3) AND b.transactionDate BETWEEN ? AND ?" . $rocB . "
                AND a.transactionId = b.transactionId AND a.itemId = c.itemId
                AND c.itemType = 'direct_production'
                GROUP BY a.itemId ORDER BY usold DESC";
        $res = ncmExecute($sql, [$from, $to], false, false, true);
        foreach (is_array($res) ? $res : [] as $f) {
            $items[(string) $f['id']] = [
                'units' => (float) $f['usold'], 'cogs' => (float) $f['cogs'], 'type' => 'direct_production',
                'date' => (string) $f['sdate'], 'outlet' => (string) $f['outlet'], 'user' => (string) $f['usr'],
            ];
        }

        return $this->buildRows($items, false);
    }

    /** Ventas de ítems direct_production, línea por línea (tab "Detallado"). */
    public function detail($from, $to)
    {
        $rocB = $this->rocAlias('b');
        $sql = "SELECT a.itemId as id, a.itemSoldUnits as usold, a.itemSoldCOGS as cogs,
                       a.userId as usr, a.itemSoldDate as sdate, b.outletId as outlet
                FROM itemSold a, transaction b, item c
                WHERE b.transactionType IN (0,3) AND b.transactionDate BETWEEN ? AND ?" . $rocB . "
                AND a.transactionId = b.transactionId AND a.itemId = c.itemId
                AND c.itemType = 'direct_production' ORDER BY usold DESC";
        $res = ncmExecute($sql, [$from, $to], false, false, true);

        // En detalle cada venta es una fila propia (no se agrega por ítem).
        $lines = [];
        foreach (is_array($res) ? $res : [] as $f) {
            $lines[] = [
                'id' => (string) $f['id'], 'units' => (float) $f['usold'], 'cogs' => (float) $f['cogs'],
                'type' => 'direct_production', 'date' => (string) $f['sdate'],
                'outlet' => (string) $f['outlet'], 'user' => (string) $f['usr'],
            ];
        }
        return $this->buildRows($lines, true);
    }

    /** Compuestos producidos (tab "Compuestos"). $byDay agrupa por día. */
    public function compound($from, $to, $byDay = false)
    {
        // Ítems "compuesto" (no vendibles).
        $itemIds = $this->compoundItemIds();
        $compounds = [];   // [itemId => [ {date,count,cost,type} ... ]]

        // (1) Producción previa: receta JSON en `production` (tipo 1).
        $sql = "SELECT itemId, productionDate, productionRecipe FROM production
                WHERE productionDate BETWEEN ? AND ? AND productionType = true" . getROC(1);
        $res = ncmExecute($sql, [$from, $to], false, false, true);
        foreach (is_array($res) ? $res : [] as $f) {
            $recipe = json_decode((string) ($f['productionRecipe'] ?? ''), true);
            if (!is_array($recipe)) { continue; }
            $date = substr((string) $f['productionDate'], 0, 10);
            foreach ($recipe as $rid => $v) {
                $compounds[(string) dec($rid)][] = [
                    'date' => $date, 'count' => (float) ($v['units'] ?? 0), 'cogs' => (float) ($v['cogs'] ?? 0), 'type' => 'prev',
                ];
            }
        }

        // (2) Producción directa: movimientos de stock con stockSource='production'.
        if ($itemIds) {
            $rocS = getROC(1);
            $ph = implode(',', array_fill(0, count($itemIds), '?'));
            $grp = $byDay ? "DATE(stockDate), itemId" : "itemId";
            $sql = "SELECT SUM(stockCount) as count, MAX(stockDate) as sdate, MAX(stockCOGS) as cogs, itemId
                    FROM stock WHERE itemId IN ($ph) AND stockSource = ? AND stockDate BETWEEN ? AND ?" . $rocS . "
                    GROUP BY $grp";
            $params = array_merge($itemIds, ['production', $from, $to]);
            $res = ncmExecute($sql, $params, false, false, true);
            foreach (is_array($res) ? $res : [] as $f) {
                $compounds[(string) $f['itemId']][] = [
                    'date' => substr((string) $f['sdate'], 0, 10), 'count' => abs((float) $f['count']),
                    'cogs' => (float) $f['cogs'], 'type' => 'direct',
                ];
            }
        }

        if (!$compounds) {
            return ['rows' => []];
        }
        $names = $this->itemNameSku(array_keys($compounds));
        $rows = [];
        foreach ($compounds as $itemId => $entries) {
            $meta = $names[(string) $itemId] ?? ['name' => '', 'sku' => ''];
            foreach ($entries as $e) {
                $cost = ($e['type'] === 'prev') ? $e['cogs'] : ($e['count'] * $e['cogs']);
                $rows[] = [
                    'date' => $e['date'], 'name' => $meta['name'], 'sku' => $meta['sku'],
                    'count' => $e['count'], 'cost' => $cost,
                ];
            }
        }
        return ['rows' => $rows];
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** getROC(1) calificado por alias (transaction `b` en los JOIN de itemSold). */
    private function rocAlias($alias)
    {
        return str_replace(
            ['registerId', 'outletId', 'companyId'],
            [$alias . '.registerId', $alias . '.outletId', $alias . '.companyId'],
            getROC(1)
        );
    }

    /**
     * Construye filas con la fórmula de utilidad del legacy (buildTableList). $perLine=true → cada
     * entrada es su propia fila (detalle); false → una fila por ítem (general).
     * @param array $src  general: map[itemId=>agg];  detail: lista de líneas con 'id'.
     */
    private function buildRows(array $src, $perLine)
    {
        if (!$src) {
            return ['rows' => [], 'totals' => ['qty' => 0, 'cogs' => 0, 'utility' => 0]];
        }
        $cats = getAllItemCategories();
        $cats = is_array($cats) ? $cats : [];
        $rows = [];
        $tUnits = $tCogs = $tUtility = 0.0;

        $entries = $perLine ? $src : array_map(fn($k, $v) => $v + ['id' => $k], array_keys($src), array_values($src));

        foreach ($entries as $v) {
            $itemId = (string) $v['id'];
            $itm = getItemData($itemId);
            if (!$itm) { continue; }

            $units = (float) $v['units'];
            $cogs  = (float) $v['cogs'];
            $price = (float) ($itm['itemPrice'] ?? 0);
            $average   = $units != 0 ? ($cogs / $units) : 0.0;
            $tax       = (float) addTax($itm['itemTax'] ?? 0, $price);
            $comission = (float) getComissionValue($itm['itemComission'] ?? 0, $price);
            $utilityUnit = (($price - $average) - $comission) - $tax;
            $utilityTotal = $utilityUnit * $units;

            $type = (string) ($v['type'] ?? '');
            $typeLabel = ($type === 'direct_production') ? 'Directa' : ($type === '2' ? 'Orden' : 'Previa');
            $catId = (string) ($itm['categoryId'] ?? '');

            $rows[] = [
                'itemId'     => $itemId,
                'name'       => (string) ($itm['itemName'] ?? ''),
                'sku'        => (string) ($itm['itemSKU'] ?? ''),
                'category'   => $catId !== '' ? (string) ($cats[$catId]['name'] ?? '') : '',
                'outletName' => isset($v['outlet']) ? (string) getCurrentOutletName($v['outlet']) : '',
                'userName'   => isset($v['user']) ? (string) (getContactData($v['user'])['name'] ?? '') : '',
                'date'       => (string) ($v['date'] ?? ''),
                'typeLabel'  => $typeLabel,
                'isOrder'    => ($type === '2'),
                'units'      => $units,
                'average'    => $average,
                'cogs'       => $cogs,
                'wasteValue' => (float) ($v['wasteValue'] ?? 0),
                'utility'    => $utilityTotal,
            ];
            $tUnits += $units; $tCogs += $cogs; $tUtility += $utilityTotal;
        }
        return ['rows' => $rows, 'totals' => ['qty' => $tUnits, 'cogs' => $tCogs, 'utility' => $tUtility]];
    }

    /** itemIds de ítems "compuesto" (no vendibles: itemCanSale < 1), scopeado por companyId. */
    private function compoundItemIds()
    {
        $res = ncmExecute(
            "SELECT itemId FROM item WHERE itemCanSale < 1 AND companyId = ?",
            [COMPANY_ID], false, false, true
        );
        $res = is_array($res) ? $res : [];
        $out = [];
        foreach ($res as $r) {
            $out[] = (string) $r['itemId'];
        }
        return $out;
    }

    /** Lookup batch itemId → {name, sku}, scopeado por companyId. */
    private function itemNameSku(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT itemId, itemName, itemSKU FROM item WHERE companyId = ? AND itemId IN ($ph)",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['itemId']] = ['name' => (string) ($r['itemName'] ?? ''), 'sku' => (string) ($r['itemSKU'] ?? '')];
        }
        return $map;
    }
}
