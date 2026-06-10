<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Satisfacción (NPS) (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportSatisfactionService.php (Fase 2 batch 4). Cambios vs original:
 *  - namespace + `final`
 *  - `getAllOutlets()` del panel (NO existe en /app) → reemplazado por lookup batch inline
 *    (mismo patrón que ExpensesService::nameMap). Misma semántica: outletId → name.
 *  - `getCustomerData` (de /app) sigue resolviendo por fallback de namespace.
 *
 * Read-only + write (DELETE de un voto). El delete del legacy NO scopeaba por companyId (IDOR);
 * acá el DELETE exige `companyId = ?`.
 */
final class SatisfactionService
{
    /** @return array filas [{satisfactionId, level, date, customerName, comment, outletName, transactionId}] */
    public function listVotes($from, $to, $roc, $companyId)
    {
        $sql = 'SELECT * FROM satisfaction WHERE satisfactionDate BETWEEN ? AND ?' . $roc . ' ORDER BY satisfactionDate DESC';
        $res = ncmExecute($sql, [$from, $to], false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        // Recolectamos los outletIds que aparecen para hacer un lookup batch (en vez de
        // depender de getAllOutlets()).
        $raw       = [];
        $outletIds = [];
        while (!$res->EOF) {
            $f = $res->fields;
            $outlet = (string) ($f['outletId'] ?? '');
            if ($outlet !== '') { $outletIds[$outlet] = true; }
            $raw[] = $f;
            $res->MoveNext();
        }
        $res->Close();

        $outlets = $this->outletNames(array_keys($outletIds), $companyId);

        $rows = [];
        foreach ($raw as $f) {
            $cust = getCustomerData($f['customerId'], 'uid');
            $outlet = (string) ($f['outletId'] ?? '');
            $rows[] = [
                'satisfactionId' => (string) $f['satisfactionId'],
                'level'          => (int) $f['satisfactionLevel'],
                'date'           => (string) $f['satisfactionDate'],
                'customerName'   => $cust['name'] ?? '',
                'comment'        => (string) ($f['satisfactionComment'] ?? ''),
                'outletName'     => $outlets[$outlet] ?? '',
                'transactionId'  => $f['transactionId'] ? (string) $f['transactionId'] : '',
            ];
        }

        return $rows;
    }

    /** Borra un voto — SCOPEADO por companyId (fix IDOR). @return bool */
    public function deleteVote($id, $companyId)
    {
        global $db;
        $r = $db->Execute('DELETE FROM satisfaction WHERE satisfactionId = ? AND companyId = ?', [$id, $companyId]);
        return $r !== false;
    }

    /** Lookup batch outletId → name, scopeado por companyId. Reemplaza getAllOutlets() del panel. */
    private function outletNames(array $ids, $companyId): array
    {
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT outletId, outletName FROM outlet WHERE companyId = ? AND outletId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['outletId']] = (string) ($r['outletName'] ?? '');
        }
        return $map;
    }
}
