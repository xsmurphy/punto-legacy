<?php
/**
 * Dominio de Reportes — Movimientos de Caja (capa API, motor ERP).
 *
 * Lectura: filas CRUDAS de extracciones/ingresos de caja (fecha, sucursal, caja, usuario,
 * nota, tipo, monto). Escritura: editar (fecha/monto/nota/usuario) y eliminar. Sin formatear,
 * sin HTML. El front mapea type→{Ingreso|Extracción}, formatea fecha + monto y arma el modal
 * de edición + las acciones. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_expenses.php (action=generalTable/edit/update/delete).
 *
 * Tenant: $roc (getROC, companyId + outletId del JWT) en la lectura; companyId bound en los
 * lookups de nombres y SIEMPRE en los WRITE (aislamiento de tenant).
 *
 * Resolución de nombres (sucursal/caja/usuario): NO se JOINea — el fragmento $roc trae columnas
 * SIN calificar (companyId/outletId), ambiguas en un JOIN — así que la lectura corre sobre
 * `expenses` y los nombres se resuelven con lookups batch parametrizados (mismo patrón que
 * customers/recurring), evitando god-functions (getAllRegisters/getAllUsers/getCurrentOutletName).
 */
class ReportExpensesService
{
    /** @return array filas [{expensesId, date, outletName, registerName, userId, userName, note, type, amount}] */
    public function listMovements($from, $to, $roc, $companyId)
    {
        $res = ncmExecute(
            "SELECT expensesId, expensesDate, expensesDescription, expensesAmount, type,
                    userId, registerId, outletId
             FROM expenses
             WHERE expensesDate BETWEEN ? AND ?" . $roc . "
             ORDER BY expensesDate DESC
             LIMIT 500",
            [$from, $to], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }

        $raw         = [];
        $outletIds   = [];
        $registerIds = [];
        $userIds     = [];
        while (!$res->EOF) {
            $f = $res->fields;
            $outlet   = (string) ($f['outletId'] ?? '');
            $register = (string) ($f['registerId'] ?? '');
            $user     = (string) ($f['userId'] ?? '');
            if ($outlet !== '')   { $outletIds[$outlet] = true; }
            if ($register !== '') { $registerIds[$register] = true; }
            if ($user !== '')     { $userIds[$user] = true; }

            $raw[] = [
                'expensesId' => (string) $f['expensesId'],
                'date'       => (string) ($f['expensesDate'] ?? ''),
                'note'       => (string) ($f['expensesDescription'] ?? ''),
                'amount'     => (float)  ($f['expensesAmount'] ?? 0),
                'type'       => (int)    $f['type'],
                'outletId'   => $outlet,
                'registerId' => $register,
                'userId'     => $user,
            ];
            $res->MoveNext();
        }
        $res->Close();

        $outlets   = $this->nameMap('outlet',   'outletId',   'outletName',   array_keys($outletIds),   $companyId);
        $registers = $this->nameMap('register', 'registerId', 'registerName', array_keys($registerIds), $companyId);
        $users     = $this->contactNames(array_keys($userIds), $companyId);

        $rows = [];
        foreach ($raw as $r) {
            $rows[] = [
                'expensesId'   => $r['expensesId'],
                'date'         => $r['date'],   // ISO crudo
                'outletName'   => $outlets[$r['outletId']] ?? '',
                'registerName' => $registers[$r['registerId']] ?? '',
                'userId'       => $r['userId'],
                'userName'     => $users[$r['userId']] ?? '',
                'note'         => $r['note'],
                'type'         => $r['type'],
                'amount'       => $r['amount'],
            ];
        }

        return $rows;
    }

    /** Usuarios (contactos type 0) para el dropdown del modal de edición. @return array [{id, name}] */
    public function users($companyId)
    {
        $res = ncmExecute(
            "SELECT contactId, contactName, contactSecondName
             FROM contact
             WHERE companyId = ? AND type = 0
             ORDER BY contactName",
            [$companyId], false, false, true
        );
        $res = is_array($res) ? $res : [];

        $out = [];
        foreach ($res as $c) {
            $name = trim(((string) ($c['contactName'] ?? '')) . ' ' . ((string) ($c['contactSecondName'] ?? '')));
            $out[] = ['id' => (string) $c['contactId'], 'name' => $name];
        }
        return $out;
    }

    /** Edita un movimiento. SCOPEADO por companyId. $userId vacío → NULL. @return bool */
    public function update($id, $companyId, $date, $amount, $note, $userId)
    {
        global $db;
        // $db->Execute (no ncmExecute): UPDATE no es SELECT → ncmExecute devolvería false aunque
        // funcione. ADOdb Execute devuelve objeto en éxito, false en error.
        $r = $db->Execute(
            "UPDATE expenses
             SET expensesDate = ?, expensesAmount = ?, expensesDescription = ?, userId = ?
             WHERE expensesId = ? AND companyId = ?",
            [$date, $amount, $note, ($userId !== '' ? $userId : null), $id, $companyId]
        );
        return $r !== false;
    }

    /** Elimina un movimiento. SCOPEADO por companyId. @return bool */
    public function remove($id, $companyId)
    {
        global $db;
        // Sin `LIMIT 1`: PG no soporta DELETE ... LIMIT (el legacy lo tenía → roto en PG).
        $r = $db->Execute(
            "DELETE FROM expenses WHERE expensesId = ? AND companyId = ?",
            [$id, $companyId]
        );
        return $r !== false;
    }

    /** Lookup batch id→name de una tabla simple (outlet/register), scopeado por companyId. */
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
            $map[(string) $c['contactId']] = trim(((string) ($c['contactName'] ?? '')) . ' ' . ((string) ($c['contactSecondName'] ?? '')));
        }
        return $map;
    }
}
