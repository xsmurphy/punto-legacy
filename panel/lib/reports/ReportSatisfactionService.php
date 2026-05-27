<?php
/**
 * Dominio de Reportes — Satisfacción (NPS) (capa API, motor ERP).
 *
 * Lectura: filas crudas de votos de satisfacción (nivel, fecha, cliente, comentario, sucursal).
 * Escritura: borrar un voto. Sin formatear, sin HTML. El BFF calcula los conteos/% NPS; el
 * front formatea (timeago) y arma tabla + barras. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_satisfaction.php (action=generalTable/delete).
 *
 * Seguridad: el DELETE legacy NO scopeaba por companyId (IDOR — cualquier id borrable
 * cross-tenant). Acá el delete exige `companyId = ?` (aislamiento de tenant).
 */
class ReportSatisfactionService
{
    /** @return array filas [{satisfactionId, level, date, customerName, comment, outletName, transactionId}] */
    public function listVotes($from, $to, $roc)
    {
        $sql = 'SELECT * FROM satisfaction WHERE satisfactionDate BETWEEN ? AND ?' . $roc . ' ORDER BY satisfactionDate DESC';
        $res = ncmExecute($sql, [$from, $to], false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        $outlets = getAllOutlets(); // outletId → {name} (bound, PG-safe)

        $rows = [];
        while (!$res->EOF) {
            $f   = $res->fields;
            $cust = getCustomerData($f['customerId'], 'uid');
            $rows[] = [
                'satisfactionId' => (string) $f['satisfactionId'],
                'level'          => (int) $f['satisfactionLevel'],
                'date'           => (string) $f['satisfactionDate'],          // ISO crudo
                'customerName'   => $cust['name'] ?? '',
                'comment'        => (string) ($f['satisfactionComment'] ?? ''),
                'outletName'     => $outlets[$f['outletId']]['name'] ?? '',
                'transactionId'  => $f['transactionId'] ? (string) $f['transactionId'] : '',
            ];
            $res->MoveNext();
        }
        $res->Close();

        return $rows;
    }

    /** Borra un voto — SCOPEADO por companyId (fix IDOR). Devuelve true si ok. */
    public function deleteVote($id, $companyId)
    {
        global $db;
        // $db->Execute (no ncmExecute): para DELETE, ncmExecute devuelve false aunque borre
        // (no es un SELECT con filas). ADOdb Execute devuelve un objeto en éxito, false en error.
        $r = $db->Execute('DELETE FROM satisfaction WHERE satisfactionId = ? AND companyId = ?', [$id, $companyId]);
        return $r !== false;
    }
}
