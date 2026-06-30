<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Facturas Recurrentes (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportRecurringService.php (Fase 2 batch 4). Único cambio
 * vs el original: namespace + `final`. SQL idéntico, incluye la lectura del payload de venta
 * desde `data->>'recurringSaleData'` (texto JSON-encoded) y el lookup batch parametrizado
 * de nombres por contactId (sin getAllContacts).
 *
 * Tenant: SOLO companyId (la tabla `recurring` no tiene outletId → sin ROC). Writes
 * (pause/activate/remove) siempre bindean companyId (aislamiento de tenant).
 */
final class RecurringService
{
    /** @return array filas [{recurringId, clientId, clientName, invoiceNo, txUid, nextDate, endDate, frecuency, status, total}] */
    public function listAll($companyId)
    {
        $res = ncmExecute(
            "SELECT recurringId, recurringNextDate, recurringEndDate, recurringFrecuency, recurringStatus,
                    data->>'recurringSaleData' AS saleData
             FROM recurring
             WHERE companyId = ?
             ORDER BY recurringNextDate DESC
             LIMIT 500",
            [$companyId], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }

        $raw       = [];
        $clientIds = [];
        $uuidRe    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        while (!$res->EOF) {
            $f    = $res->fields;
            $sale = json_decode((string) ($f['saleData'] ?? ''), true);
            $sale = is_array($sale) ? $sale : [];

            $client = (string) ($sale['client'] ?? '');
            if ($client !== '' && preg_match($uuidRe, $client)) {
                $clientIds[$client] = true;
            }
            $raw[] = [
                'recurringId' => (string) $f['recurringId'],
                'nextDate'    => (string) ($f['recurringNextDate'] ?? ''),
                'endDate'     => (string) ($f['recurringEndDate'] ?? ''),
                'frecuency'   => (string) ($f['recurringFrecuency'] ?? ''),
                'status'      => (int) $f['recurringStatus'],
                'sale'        => $sale,
                'client'      => $client,
            ];
            $res->MoveNext();
        }
        $res->Close();

        $names = $this->contactNames(array_keys($clientIds), $companyId);

        $rows = [];
        foreach ($raw as $r) {
            $sale   = $r['sale'];
            $client = $r['client'];
            $rows[] = [
                'recurringId'      => $r['recurringId'],
                'clientId'         => $client,
                'clientName'       => $names[$client]['name'] ?? '',
                'clientSecondName' => $names[$client]['secondName'] ?? '',
                'invoiceNo'        => (string) ($sale['invoiceno'] ?? ''),
                'txUid'            => (string) ($sale['uid'] ?? ''),
                'nextDate'         => $r['nextDate'],
                'endDate'          => $r['endDate'],
                'frecuency'        => $r['frecuency'],
                'status'           => $r['status'],
                'total'            => (float) ($sale['total'] ?? 0),
            ];
        }

        return $rows;
    }

    /** Lookup parametrizado contactId → {name, secondName}, scopeado por companyId. */
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
            $map[(string) $c['contactId']] = [
                'name'       => (string) ($c['contactName'] ?? ''),
                'secondName' => (string) ($c['contactSecondName'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * pause → status 2, activate → status 1, remove → DELETE. SCOPEADO por companyId.
     * @return bool
     */
    public function mutate($action, $id, $companyId)
    {
        global $db;
        if ($action === 'pause' || $action === 'activate') {
            $status = $action === 'pause' ? 2 : 1;
            $r = $db->Execute(
                'UPDATE recurring SET recurringStatus = ? WHERE recurringId = ? AND companyId = ?',
                [$status, $id, $companyId]
            );
            return $r !== false;
        }
        if ($action === 'remove') {
            $r = $db->Execute('DELETE FROM recurring WHERE recurringId = ? AND companyId = ?', [$id, $companyId]);
            return $r !== false;
        }
        return false;
    }
}
