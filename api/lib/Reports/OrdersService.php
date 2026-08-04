<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Órdenes (API compartida, motor ERP).
 *
 * Las órdenes viven en `pos_order` (módulo Órdenes real, context/24) — NO en
 * `transaction` type=12 (pedido online legacy, casi sin uso: 2 filas en prod
 * contra 32 en pos_order). Mismo defecto que se corrigió en el tab Órdenes de
 * la ficha del cliente (commit 003195c2, T5): el reporte agregado apuntaba a
 * la fuente muerta. `api/lib/services/OrderService.php` (legacy, type=12)
 * queda intacto — dominio distinto, no se toca.
 *
 * `pos_order` no tiene columna de total: se agrega sumando `pos_order_item`
 * (qty*price, excluyendo status='cancelled') en un JOIN a subquery — UNA
 * query, no N+1. `status` es string nativo de `pos_order` (ver
 * migs 79/96). `channel` se deriva de `source`: 'ecommerce' → 'ecom', el
 * resto ('counter'/'table'/'schedule') → 'local'. `dueDate` no existe en
 * `pos_order`: se devuelve null pero el campo queda en el contrato para no
 * romper al consumidor.
 *
 * Tenant: $roc (companyId + outletId del JWT, lo arma el endpoint) en la
 * lectura, SIN alias de tabla (pos_order es la única tabla del FROM externo
 * con columnas companyid/outletid — la subquery de totales no las tiene, así
 * que no hay ambigüedad); companyId bound en los lookups de nombres.
 */
final class OrdersService
{
    /** @return array filas [{id, date, dueDate, orderNo, customerName, outletName, total, status, channel}] */
    public function listOrders($from, $to, $roc, $companyId, ?string $customerId = null)
    {
        $customerClause = '';
        $params = [$from, $to];
        if ($customerId !== null && $customerId !== '') {
            $customerClause = ' AND customerid = ?';
            $params[] = $customerId;
        }

        $res = ncmExecute(
            "SELECT pos_order.orderid, created_at, ordernumber, status, source, customerid, outletid,
                    COALESCE(items.total, 0) AS total
             FROM pos_order
             LEFT JOIN (
                 SELECT orderid, SUM(qty * price) AS total
                 FROM pos_order_item
                 WHERE status <> 'cancelled'
                 GROUP BY orderid
             ) items ON items.orderid = pos_order.orderid
             WHERE created_at BETWEEN ? AND ?" . $customerClause . $roc . "
             ORDER BY created_at DESC
             LIMIT 500",
            $params, false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }

        $raw       = [];
        $outletIds = [];
        $custIds   = [];
        while (!$res->EOF) {
            $f        = $res->fields;
            $outlet   = (string) ($f['outletid'] ?? '');
            $customer = (string) ($f['customerid'] ?? '');
            if ($outlet !== '')   { $outletIds[$outlet] = true; }
            if ($customer !== '') { $custIds[$customer] = true; }

            $raw[] = [
                'id'         => (string) ($f['orderid'] ?? ''),
                'date'       => (string) ($f['created_at'] ?? ''),
                'dueDate'    => null,
                'orderNo'    => (string) ($f['ordernumber'] ?? ''),
                'total'      => (float)  ($f['total'] ?? 0),
                'status'     => (string) ($f['status'] ?? 'open'),
                'channel'    => ((string) ($f['source'] ?? '') === 'ecommerce') ? 'ecom' : 'local',
                'outletId'   => $outlet,
                'customerId' => $customer,
            ];
            $res->MoveNext();
        }
        $res->Close();

        $outlets   = $this->nameMap('outlet', 'outletId', 'outletName', array_keys($outletIds), $companyId);
        $customers = $this->contactNames(array_keys($custIds), $companyId);

        $rows = [];
        foreach ($raw as $r) {
            $rows[] = [
                'id'           => $r['id'],
                'date'         => $r['date'],
                'dueDate'      => $r['dueDate'],
                'orderNo'      => $r['orderNo'],
                'customerName' => $customers[$r['customerId']] ?? '',
                'outletName'   => $outlets[$r['outletId']] ?? '',
                'total'        => $r['total'],
                'status'       => $r['status'],
                'channel'      => $r['channel'],
            ];
        }
        return $rows;
    }

    /** Lookup batch id→name de una tabla simple, scopeado por companyId. */
    private function nameMap($table, $idCol, $nameCol, array $ids, $companyId)
    {
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

    /** Lookup batch contactId → nombre completo, scopeado por companyId. */
    private function contactNames(array $ids, $companyId)
    {
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, data->>'contactSecondName' AS contactSecondName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];

        $map = [];
        foreach ($res as $c) {
            $name = trim(((string) ($c['contactName'] ?? '')) . ' ' . ((string) ($c['contactSecondName'] ?? '')));
            $map[(string) $c['contactId']] = $name;
        }
        return $map;
    }
}
