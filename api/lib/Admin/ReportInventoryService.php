<?php
/**
 * Dominio de Reportes — Historial de Stock / Inventario (capa API, motor ERP).
 *
 * Devuelve datasets CRUDOS (números sin formatear, fechas ISO, sin HTML): movimientos de
 * stock enriquecidos con datos ya resueltos (item, sucursal, depósito, usuario) + el KPI
 * widget (valor al costo / de venta / total en stock). El formateo (números, fechas), la
 * traducción de `source` y el markup viven en el front. Ver context/02-arquitectura.md.
 *
 * Reemplaza la lógica inline de panel/a_report_inventory.php (action=generalTable /
 * generalTableByDay / widget=inventory) que armaba HTML.
 *
 * Tenant: filtro $roc (getROC()) derivado de COMPANY_ID del JWT. Fix PG: el legacy usaba
 * `BETWEEN "..."` (comillas dobles = identificadores en PG) → acá van bound params.
 */
class ReportInventoryService
{
    /**
     * Movimientos de stock de un período (o el historial completo de un ítem si $itemId).
     * Si $byDay, agrupa por (día, ítem) quedándose con el último valor del día.
     * @return array  filas crudas
     */
    public function movements($from, $to, $itemId, $byDay, $roc, $limit = 200, $offset = 0)
    {
        $where  = ' WHERE 1=1';
        $params = [];

        if ($itemId) {
            $where   .= ' AND itemId = ?';
            $params[] = $itemId;
        } else {
            $where   .= ' AND stockDate BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        }

        $order = $byDay ? 'ASC' : 'DESC';
        $sql   = 'SELECT * FROM stock' . $where . $roc
               . ' ORDER BY stockId ' . $order
               . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $res = ncmExecute($sql, $params, false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        // Resolver nombres en lote (item) / por fila (outlet/location/user via helpers cacheados).
        $itemIds = [];
        while (!$res->EOF) {
            $itemIds[] = $res->fields['itemId'];
            $res->MoveNext();
        }
        $res->MoveFirst();

        $items    = $this->itemNames($itemIds);
        $allUsers = getAllUsers();

        $rows  = [];
        $byKey = []; // para byDay: clave (día+item) → fila (última gana)

        while (!$res->EOF) {
            $f   = $res->fields;
            $iid = $f['itemId'];

            $row = [
                'stockId'       => (string) $f['stockId'],
                'stockDate'     => (string) $f['stockDate'],          // ISO crudo
                'itemId'        => (string) $iid,
                'itemName'      => $items[$iid]['name'] ?? '',
                'itemSKU'       => $items[$iid]['sku'] ?? '',
                'outletName'    => (string) getCurrentOutletName($f['outletId']),
                'locationName'  => (string) getLocationName($f['locationId']),
                'userName'      => $allUsers[$f['userId']]['name'] ?? 'Sin Usuario',
                'source'        => (string) $f['stockSource'],         // key cruda; el front traduce
                'transactionId' => $f['transactionId'] ? (string) $f['transactionId'] : '',
                'note'          => (string) ($f['stockNote'] ?? ''),
                'count'         => (float) $f['stockCount'],
                'onHand'        => (float) $f['stockOnHand'],
                'cogs'          => (float) $f['stockCOGS'],
            ];

            if ($byDay) {
                $key         = substr($row['stockDate'], 0, 10) . $iid;
                $byKey[$key] = $row; // la última del día gana (orden ASC)
            } else {
                $rows[] = $row;
            }

            $res->MoveNext();
        }
        $res->Close();

        return $byDay ? array_values($byKey) : $rows;
    }

    /**
     * Mapa itemId → {name, sku} con query parametrizada (multi-tenant por COMPANY_ID).
     * NO usa getAllItems(): ese helper interpola COMPANY_ID y el IN sin comillas → rompe en PG.
     */
    private function itemNames(array $itemIds)
    {
        $itemIds = array_values(array_unique(array_filter($itemIds)));
        if (!$itemIds) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = 'SELECT itemId, itemName, itemSKU FROM item WHERE companyId = ? AND itemId IN (' . $ph . ')';

        $res = ncmExecute($sql, array_merge([COMPANY_ID], $itemIds), false, true);
        $map = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f = $res->fields;
                $map[(string) $f['itemId']] = [
                    'name' => toUTF8($f['itemName'] ?? ''),
                    'sku'  => toUTF8($f['itemSKU'] ?? ''),
                ];
                $res->MoveNext();
            }
            $res->Close();
        }
        return $map;
    }

    /** KPIs del widget: valor al costo, valor de venta, total de unidades en stock. */
    public function widget()
    {
        $inv = getAllInventoryAndItemsModule(); // [cost, sell, qty]
        return [
            'cost'  => (float) ($inv[0] ?? 0),
            'sell'  => (float) ($inv[1] ?? 0),
            'total' => (float) ($inv[2] ?? 0),
        ];
    }
}
