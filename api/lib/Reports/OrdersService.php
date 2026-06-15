<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Órdenes (API compartida, motor ERP).
 *
 * Las órdenes son `transaction` con `transactionType = 12` (mismo origen que el
 * widget de órdenes del dashboard — DashboardService::orders). Lectura CRUDA: el
 * front mapea estado→label/badge y formatea fecha/monto. Ver REGLA RAÍZ 2.
 *
 * Estados (transactionStatus): 0/1 Pendiente · 2 En Espera · 3 En Proceso ·
 * 4 Finalizado · 5 Enviado · 6 Cancelado (mismo mapa que panel/API/get_orders.php).
 * Canal: transactionName = 'ecom' → online; sino local.
 *
 * Tenant: $roc (companyId + outletId del JWT, lo arma el endpoint) en la lectura;
 * companyId bound en los lookups de nombres (aislamiento de tenant).
 */
final class OrdersService
{
    /** @return array filas [{id, date, dueDate, orderNo, customerName, outletName, total, status, channel}] */
    public function listOrders($from, $to, $roc, $companyId)
    {
        $res = ncmExecute(
            "SELECT transactionId, transactionDate, transactionDueDate, invoiceNo,
                    transactionTotal, transactionStatus, transactionName, customerId, outletId
             FROM transaction
             WHERE transactionType = 12 AND transactionDate BETWEEN ? AND ?" . $roc . "
             ORDER BY transactionDate DESC
             LIMIT 500",
            [$from, $to], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }

        $raw       = [];
        $outletIds = [];
        $custIds   = [];
        while (!$res->EOF) {
            $f        = $res->fields;
            $outlet   = (string) ($f['outletId'] ?? '');
            $customer = (string) ($f['customerId'] ?? '');
            if ($outlet !== '')   { $outletIds[$outlet] = true; }
            if ($customer !== '') { $custIds[$customer] = true; }

            $raw[] = [
                'id'         => (string) ($f['transactionId'] ?? ''),
                'date'       => (string) ($f['transactionDate'] ?? ''),
                'dueDate'    => $f['transactionDueDate'] ?? null,
                'orderNo'    => (string) ($f['invoiceNo'] ?? ''),
                'total'      => (float)  ($f['transactionTotal'] ?? 0),
                'status'     => (int)    ($f['transactionStatus'] ?? 0),
                'channel'    => ((string) ($f['transactionName'] ?? '') === 'ecom') ? 'ecom' : 'local',
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
            "SELECT contactId, contactName, contactSecondName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
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
