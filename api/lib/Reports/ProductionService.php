<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Producción / Production (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportProductionService.php (Fase 2 batch 10). Cambios vs original:
 *  - namespace + `final`
 *  - el ROC se recibe por PARÁMETRO en `general/detail/compound` (no `getROC(1)` interno)
 *  - el companyId se recibe por parámetro (no lee constante)
 *  - 3 helpers panel-only portados como métodos privados:
 *    * `getItemData($id, $cache)` → `itemData($id, $companyId)` private
 *    * `getComissionValue($pct, $price)` → `comissionValue` private static
 *    * `dec($str)` → `dec` private static (identity en PG)
 *  - `getAllItemCategories()` panel (sin args) → `getAllItemCategories($companyId)` /app (con firma).
 *  - `addTax`, `getCurrentOutletName`, `getContactData` → resuelven por fallback de namespace
 *    (todos en /app/includes/functions.php).
 *
 * Tenant: $roc por parámetro; companyId bound en todos los lookups. SOLO lecturas (las 3 vistas
 * general/detail/compound). Los writes (`recipe`, export XLSX, `delete`) siguen en el panel legacy.
 */
final class ProductionService
{
    /** Producción agregada por ítem (tab "General"). */
    public function general($from, $to, string $roc, string $companyId): array
    {
        $items = [];

        // (1) Producción "previa" registrada en la tabla `production` (tipo 1).
        $sql = "SELECT itemId, productionCount, productionCOGS, productionType
                FROM production
                WHERE productionDate BETWEEN ? AND ? AND productionType = true" . $roc;
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

        // (2) Producción "directa": ventas de ítems itemType='direct_production'.
        $rocB = $this->rocAlias($roc, 'b');
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

        return $this->buildRows($items, false, $companyId);
    }

    /** Ventas de ítems direct_production, línea por línea (tab "Detallado"). */
    public function detail($from, $to, string $roc, string $companyId): array
    {
        $rocB = $this->rocAlias($roc, 'b');
        $sql = "SELECT a.itemId as id, a.itemSoldUnits as usold, a.itemSoldCOGS as cogs,
                       a.userId as usr, a.itemSoldDate as sdate, b.outletId as outlet
                FROM itemSold a, transaction b, item c
                WHERE b.transactionType IN (0,3) AND b.transactionDate BETWEEN ? AND ?" . $rocB . "
                AND a.transactionId = b.transactionId AND a.itemId = c.itemId
                AND c.itemType = 'direct_production' ORDER BY usold DESC";
        $res = ncmExecute($sql, [$from, $to], false, false, true);

        $lines = [];
        foreach (is_array($res) ? $res : [] as $f) {
            $lines[] = [
                'id' => (string) $f['id'], 'units' => (float) $f['usold'], 'cogs' => (float) $f['cogs'],
                'type' => 'direct_production', 'date' => (string) $f['sdate'],
                'outlet' => (string) $f['outlet'], 'user' => (string) $f['usr'],
            ];
        }
        return $this->buildRows($lines, true, $companyId);
    }

    /** Compuestos producidos (tab "Compuestos"). $byDay agrupa por día. */
    public function compound($from, $to, string $roc, string $companyId, bool $byDay = false): array
    {
        $itemIds = $this->compoundItemIds($companyId);
        $compounds = [];

        // (1) Producción previa: receta JSON.
        $sql = "SELECT itemId, productionDate, productionRecipe FROM production
                WHERE productionDate BETWEEN ? AND ? AND productionType = true" . $roc;
        $res = ncmExecute($sql, [$from, $to], false, false, true);
        foreach (is_array($res) ? $res : [] as $f) {
            $recipe = json_decode((string) ($f['productionRecipe'] ?? ''), true);
            if (!is_array($recipe)) { continue; }
            $date = substr((string) $f['productionDate'], 0, 10);
            foreach ($recipe as $rid => $v) {
                $compounds[self::dec((string) $rid)][] = [
                    'date' => $date, 'count' => (float) ($v['units'] ?? 0), 'cogs' => (float) ($v['cogs'] ?? 0), 'type' => 'prev',
                ];
            }
        }

        // (2) Producción directa: movimientos de stock con stockSource='production'.
        if ($itemIds) {
            $ph = implode(',', array_fill(0, count($itemIds), '?'));
            $grp = $byDay ? "DATE(stockDate), itemId" : "itemId";
            $sql = "SELECT SUM(stockCount) as count, MAX(stockDate) as sdate, MAX(stockCOGS) as cogs, itemId
                    FROM stock WHERE itemId IN ($ph) AND stockSource = ? AND stockDate BETWEEN ? AND ?" . $roc . "
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
        $names = $this->itemNameSku(array_keys($compounds), $companyId);
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

    /* ───────────── helpers ───────────── */

    /** $roc con alias prefijado en outletId/registerId/companyId (para JOINs). */
    private function rocAlias(string $roc, string $alias): string
    {
        return str_replace(
            ['registerId', 'outletId', 'companyId'],
            [$alias . '.registerId', $alias . '.outletId', $alias . '.companyId'],
            $roc
        );
    }

    /**
     * Construye filas con la fórmula de utilidad del legacy.
     * @param array $src    general: map[itemId=>agg];  detail: lista de líneas con 'id'.
     */
    private function buildRows(array $src, bool $perLine, string $companyId): array
    {
        if (!$src) {
            return ['rows' => [], 'totals' => ['qty' => 0, 'cogs' => 0, 'utility' => 0]];
        }
        $cats = getAllItemCategories($companyId);
        $cats = is_array($cats) ? $cats : [];
        $rows = [];
        $tUnits = $tCogs = $tUtility = 0.0;

        $entries = $perLine ? $src : array_map(fn($k, $v) => $v + ['id' => $k], array_keys($src), array_values($src));

        foreach ($entries as $v) {
            $itemId = (string) $v['id'];
            $itm = $this->itemData($itemId, $companyId);
            if (!$itm) { continue; }

            $units = (float) $v['units'];
            $cogs  = (float) $v['cogs'];
            $price = (float) ($itm['itemPrice'] ?? 0);
            $average   = $units != 0 ? ($cogs / $units) : 0.0;
            $tax       = (float) addTax($itm['itemTax'] ?? 0, $price);
            $comission = self::comissionValue((float) ($itm['itemComission'] ?? 0), $price);
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

    /** itemIds de ítems "compuesto" (no vendibles), scopeado por companyId. */
    private function compoundItemIds(string $companyId): array
    {
        $res = ncmExecute(
            "SELECT itemId FROM item WHERE itemCanSale IS FALSE AND companyId = ?",
            [$companyId], false, false, true
        );
        $res = is_array($res) ? $res : [];
        $out = [];
        foreach ($res as $r) {
            $out[] = (string) $r['itemId'];
        }
        return $out;
    }

    /** Lookup batch itemId → {name, sku}, scopeado por companyId. */
    private function itemNameSku(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT itemId, itemName, itemSKU FROM item WHERE companyId = ? AND itemId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['itemId']] = ['name' => (string) ($r['itemName'] ?? ''), 'sku' => (string) ($r['itemSKU'] ?? '')];
        }
        return $map;
    }

    /** Port fiel de getItemData del panel (sin cache — el caller lo gestiona). */
    private function itemData(string $itemId, string $companyId)
    {
        $r = ncmExecute("SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1", [$itemId, $companyId]);
        return $r ?: [];
    }

    /** Port fiel de getComissionValue del panel. */
    private static function comissionValue(float $percent, float $price): float
    {
        return ($percent && $price) ? (($price * $percent) / 100) : 0.0;
    }

    /** Port fiel de dec del panel (identity en PG — historicamente decodificaba base64). */
    private static function dec(string $str): string
    {
        return $str;
    }
}
