<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Reporte — Evolución de costos por proveedor (Compras).
 *
 * Solo LECTURA: el histórico de lo que costó cada artículo en cada compra,
 * asociado al proveedor que lo vendió (el mismo producto cuesta distinto según
 * a quién se le compra).
 *
 * ── De dónde sale el costo unitario ─────────────────────────────────────────
 *
 * `itemSold` de una compra NO tiene columna de costo unitario:
 * `Purchases\PurchasesService::create()` graba `itemSoldTotal` = lo pagado por
 * la línea e `itemSoldUnits` = unidades REALES (paquetes × `packSize`, regla 4
 * de `context/modules/08-compras.md`). El costo unitario es el cociente, el
 * mismo `$price = $lineTotal / $units` que esa función le pasa como `cogs` a
 * `manageStock()`. Si una compra trae el mismo artículo en dos líneas, se
 * agregan (SUM/SUM) y queda un costo por compra: un promedio ponderado de esa
 * factura, no dos puntos del mismo día.
 *
 * El total va con IVA incluido, igual que el costo de inventario (decisión del
 * owner: el costo es lo realmente pagado).
 *
 * ── Qué cuenta ──────────────────────────────────────────────────────────────
 *
 * Compras contado y crédito (`transactionType IN (1,4)`) VIGENTES
 * (`transactionStatus = 1`): una anulada (6) no cuenta. Las líneas de gasto
 * libre no están en `itemSold` (FK `itemId NOT NULL`) y no tienen artículo, así
 * que no aplican a este reporte.
 *
 * ── Variación ───────────────────────────────────────────────────────────────
 *
 * Contra la compra ANTERIOR del MISMO proveedor para ese artículo, aunque esa
 * compra haya sido antes del período elegido: por eso la ventana se calcula
 * sobre todo el historial hasta el fin del rango y el piso del rango se aplica
 * DESPUÉS. Comparar contra la compra anterior de cualquier proveedor marcaría
 * como "subió" un simple cambio de proveedor.
 *
 * Tenant: `companyId` bindeado en toda query + `$roc` (outlet scope del panel,
 * `context/25`) sobre la compra.
 */
final class PurchaseCostsService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param array{itemId?:string,supplierId?:string} $filters
     * @return array<string,mixed>
     *   Sin artículo:  ['mode'=>'items', 'items'=>[…]]
     *   Con artículo:  ['mode'=>'item', 'item'=>{…}, 'rows'=>[…], 'suppliers'=>[…], 'series'=>{granularity, buckets, points}]
     */
    public function costs(array $filters, string $from, string $to, string $roc, string $companyId): array
    {
        if (!preg_match(self::UUID_RE, $companyId)) {
            throw new \RuntimeException('companyId inválido');
        }
        $itemId     = $this->uuidOrEmpty($filters['itemId'] ?? '');
        $supplierId = $this->uuidOrEmpty($filters['supplierId'] ?? '');

        return $itemId !== ''
            ? $this->forItem($itemId, $supplierId, $from, $to, $roc, $companyId)
            : $this->allItems($supplierId, $from, $to, $roc, $companyId);
    }

    /* ───────────────────────── con artículo ───────────────────────── */

    private function forItem(string $itemId, string $supplierId, string $from, string $to, string $roc, string $companyId): array
    {
        // Todo el historial del artículo hasta el fin del rango: la variación de
        // la primera compra del período se mide contra una anterior al período,
        // y el comparativo es el último costo de cada proveedor a esa fecha.
        $lines = $this->costedLines($companyId, $roc, $from, $to, [$itemId]);

        $rows      = [];
        $suppliers = [];
        foreach ($lines as $l) {
            $supKey = (string) ($l['supplierId'] ?? '');
            // Último costo por proveedor: las líneas vienen en orden
            // cronológico, así que la última que se ve gana.
            $suppliers[$supKey] = [
                'supplierId' => $supKey !== '' ? $supKey : null,
                'lastCost'   => $l['unitCost'],
                'lastDate'   => $l['date'],
                'purchases'  => ($suppliers[$supKey]['purchases'] ?? 0) + 1,
            ];

            if (!$l['inRange']) {
                continue;
            }
            if ($supplierId !== '' && $supKey !== $supplierId) {
                continue;
            }
            $rows[] = $l;
        }

        $names = $this->contactNames(
            array_merge(array_keys($suppliers), array_map(fn($r) => (string) $r['supplierId'], $rows)),
            $companyId
        );
        foreach ($rows as &$r) {
            $r['supplierName'] = $names[(string) $r['supplierId']] ?? '';
        }
        unset($r);

        $cmp = array_values($suppliers);
        $min = null;
        foreach ($cmp as $s) {
            $min = $min === null ? $s['lastCost'] : min($min, $s['lastCost']);
        }
        foreach ($cmp as &$s) {
            $s['supplierName'] = $names[(string) $s['supplierId']] ?? '';
            // Con UN solo proveedor no hay "más barato": no hay contra quién.
            $s['cheapest'] = count($cmp) > 1 && abs($s['lastCost'] - (float) $min) < 0.000001;
        }
        unset($s);
        usort($cmp, fn($a, $b) => $a['lastCost'] <=> $b['lastCost']);

        $item = $this->itemInfo($itemId, $companyId);

        return [
            'mode'      => 'item',
            'item'      => $item,
            'rows'      => $rows,
            'suppliers' => $cmp,
            'series'    => $this->costSeries($rows, $from, $to),
        ];
    }

    /**
     * Costo unitario por período y proveedor, para el gráfico. El grano —día,
     * semana o mes según el largo del rango— es la regla única de
     * `TimeBuckets`, la de todos los gráficos con fechas.
     *
     * Dentro de un período el costo es el PROMEDIO PONDERADO por unidades de
     * las compras a ese proveedor: dos compras de la misma semana, una de 10
     * unidades y otra de 1000, no pesan igual. Los períodos sin compra no
     * llevan punto (un costo no es cero porque no se compró): el calendario
     * completo va en `buckets` para que el eje respete el tiempo.
     *
     * @param list<array<string,mixed>> $rows las compras del período, ya filtradas
     * @return array{granularity: string, buckets: list<array<string,mixed>>, points: list<array<string,mixed>>}
     */
    private function costSeries(array $rows, string $from, string $to): array
    {
        $tb  = \Punto\Api\Support\TimeBuckets::forRange($from, $to);
        $acc = [];
        foreach ($rows as $r) {
            $key = $tb->keyFor((string) $r['date']);
            $sup = (string) ($r['supplierId'] ?? '');
            $g   = &$acc[$key . '|' . $sup];
            $g ??= ['bucket' => $key, 'supplierId' => $sup !== '' ? $sup : null, 'units' => 0.0, 'cost' => 0.0, 'purchases' => 0];
            $g['units']     += (float) $r['units'];
            $g['cost']      += (float) $r['unitCost'] * (float) $r['units'];
            $g['purchases'] += 1;
            unset($g);
        }

        $points = [];
        foreach ($acc as $g) {
            $points[] = [
                'bucket'     => $g['bucket'],
                'supplierId' => $g['supplierId'],
                'unitCost'   => $g['units'] > 0 ? $g['cost'] / $g['units'] : 0.0,
                'purchases'  => $g['purchases'],
            ];
        }

        return ['granularity' => $tb->granularity, 'buckets' => $tb->buckets(), 'points' => $points];
    }

    /* ───────────────────────── sin artículo ───────────────────────── */

    private function allItems(string $supplierId, string $from, string $to, string $roc, string $companyId): array
    {
        $rocA = $this->aliasRoc($roc);

        // Artículos con al menos una compra en el período (y del proveedor, si
        // se filtró). Es el universo del listado.
        $sql = "SELECT DISTINCT b.itemId AS item_id
                  FROM transaction a
                  JOIN itemSold b ON b.transactionId = a.transactionId
                 WHERE a.companyId = ?" . $rocA . "
                   AND a.transactionType IN (1,4) AND a.transactionStatus = 1
                   AND b.itemSoldUnits > 0
                   AND a.transactionDate BETWEEN ? AND ?";
        $params = [$companyId, $from, $to];
        if ($supplierId !== '') {
            $sql     .= ' AND a.supplierId = ?';
            $params[] = $supplierId;
        }
        $res = ncmExecute($sql, $params, false, false, true);
        $itemIds = array_values(array_filter(array_map(
            fn($r) => (string) ($r['item_id'] ?? ''),
            is_array($res) ? $res : []
        )));
        if (!$itemIds) {
            return ['mode' => 'items', 'items' => []];
        }

        $lines = $this->costedLines($companyId, $roc, $from, $to, $itemIds);

        // La ÚLTIMA compra del período de cada artículo (del proveedor
        // filtrado, si hay), con su variación contra la anterior del mismo
        // proveedor.
        $last = [];
        foreach ($lines as $l) {
            if (!$l['inRange']) {
                continue;
            }
            if ($supplierId !== '' && (string) $l['supplierId'] !== $supplierId) {
                continue;
            }
            $last[$l['itemId']] = $l;
        }

        $items = $this->itemNames(array_keys($last), $companyId);
        $names = $this->contactNames(array_map(fn($l) => (string) $l['supplierId'], $last), $companyId);

        $out = [];
        foreach ($last as $iid => $l) {
            $out[] = [
                'itemId'        => $iid,
                'itemName'      => $items[$iid] ?? '',
                'supplierId'    => $l['supplierId'],
                'supplierName'  => $names[(string) $l['supplierId']] ?? '',
                'lastCost'      => $l['unitCost'],
                'lastDate'      => $l['date'],
                'previousCost'  => $l['previousCost'],
                'variationPct'  => $l['variationPct'],
                'transactionId' => $l['transactionId'],
            ];
        }
        usort($out, fn($a, $b) => ($b['variationPct'] ?? -INF) <=> ($a['variationPct'] ?? -INF));

        return ['mode' => 'items', 'items' => $out];
    }

    /* ───────────────────────── núcleo ───────────────────────── */

    /**
     * Una fila por (compra, artículo) hasta `$to`, en orden cronológico, con el
     * costo unitario y el de la compra anterior del MISMO proveedor.
     *
     * @param string[] $itemIds
     * @return list<array<string,mixed>>
     */
    private function costedLines(string $companyId, string $roc, string $from, string $to, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            $itemIds,
            fn($id) => (bool) preg_match(self::UUID_RE, (string) $id)
        )));
        if (!$itemIds) {
            return [];
        }
        $rocA = $this->aliasRoc($roc);
        $ph   = implode(',', array_fill(0, count($itemIds), '?'));

        $sql = "WITH lines AS (
                    SELECT b.itemId              AS item_id,
                           a.supplierId          AS supplier_id,
                           a.transactionId       AS transaction_id,
                           a.transactionDate     AS tx_date,
                           SUM(b.itemSoldUnits)  AS units,
                           SUM(b.itemSoldTotal)  AS total
                      FROM transaction a
                      JOIN itemSold b ON b.transactionId = a.transactionId
                     WHERE a.companyId = ?" . $rocA . "
                       AND a.transactionType IN (1,4) AND a.transactionStatus = 1
                       AND a.transactionDate <= ?
                       AND b.itemId IN ($ph)
                       AND b.itemSoldUnits > 0
                     GROUP BY b.itemId, a.supplierId, a.transactionId, a.transactionDate
                ), costed AS (
                    SELECT l.*,
                           l.total / l.units AS unit_cost,
                           LAG(l.total / l.units) OVER (
                               PARTITION BY l.item_id, l.supplier_id
                               ORDER BY l.tx_date, l.transaction_id
                           ) AS prev_cost
                      FROM lines l
                )
                SELECT item_id, supplier_id, transaction_id, tx_date, units, unit_cost, prev_cost,
                       CASE WHEN tx_date >= ? THEN 1 ELSE 0 END AS in_range
                  FROM costed
                 ORDER BY tx_date, transaction_id";

        // Recordset y no array: el modo array del wrapper indexa por la
        // PRIMERA columna (item_id) y colapsaría todas las compras del mismo
        // artículo en una sola fila.
        $res  = ncmExecute($sql, array_merge([$companyId, $to], $itemIds, [$from]), false, true);
        $rows = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $rows[] = $res->fields;
                $res->MoveNext();
            }
            $res->Close();
        }
        $out = [];
        foreach ($rows as $r) {
            $unit = (float) $r['unit_cost'];
            $prev = $r['prev_cost'] !== null ? (float) $r['prev_cost'] : null;
            $sup  = (string) ($r['supplier_id'] ?? '');
            $out[] = [
                'itemId'        => (string) $r['item_id'],
                'supplierId'    => $sup !== '' ? $sup : null,
                'transactionId' => (string) $r['transaction_id'],
                'date'          => (string) $r['tx_date'],
                'inRange'       => (int) $r['in_range'] === 1,
                'units'         => (float) $r['units'],
                'unitCost'      => $unit,
                'previousCost'  => $prev,
                'variationPct'  => ($prev !== null && $prev > 0)
                    ? round(($unit - $prev) / $prev * 100, 2)
                    : null,
            ];
        }
        return $out;
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** El ROC del endpoint viene sin alias; acá la compra es `a`. */
    private function aliasRoc(string $roc): string
    {
        return str_replace(['outletId', 'companyId'], ['a.outletId', 'a.companyId'], $roc);
    }

    private function uuidOrEmpty($v): string
    {
        $v = (string) ($v ?: '');
        return preg_match(self::UUID_RE, $v) ? $v : '';
    }

    /** @return array{itemId:string,itemName:string}|null */
    private function itemInfo(string $itemId, string $companyId): ?array
    {
        $names = $this->itemNames([$itemId], $companyId);
        return isset($names[$itemId]) ? ['itemId' => $itemId, 'itemName' => $names[$itemId]] : null;
    }

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
        $map = [];
        foreach (is_array($res) ? $res : [] as $r) {
            $map[(string) $r['itemId']] = (string) ($r['itemName'] ?? '');
        }
        return $map;
    }

    private function contactNames(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter(
            $ids,
            fn($id) => (bool) preg_match(self::UUID_RE, (string) $id)
        )));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $map = [];
        foreach (is_array($res) ? $res : [] as $r) {
            $map[(string) $r['contactId']] = (string) ($r['contactName'] ?? '');
        }
        return $map;
    }
}
