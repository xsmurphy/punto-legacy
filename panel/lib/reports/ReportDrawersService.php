<?php
/**
 * Dominio de Reportes — Cierres de Caja / Drawers (capa API, motor ERP).
 *
 * Lectura: sesiones de caja (apertura/cierre) con sus componentes CRUDOS (monto apertura/cierre,
 * vendido, extracciones, ingresos, devoluciones) + detalle por caja con desglose por medio de pago.
 * Escritura: cerrar caja, corregir cierre, eliminar. Sin formatear, sin HTML. El front arma el
 * markup, formatea montos/fechas, mapea usuarios y calcula la diferencia + colores. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_drawers.php (table/viewData/closeRegister/
 * correctClosure/delete).
 *
 * SEGURIDAD (endurecimientos vs legacy):
 *  - El detalle ya NO confía en un blob base64 del cliente (`?d=`): se re-consulta TODO por
 *    drawerId scopeado a companyId. El cliente solo manda el id.
 *  - `remove()` ahora scopea por companyId (el legacy hacía `DELETE ... WHERE drawerId = ? LIMIT 1`
 *    sin companyId = IDOR; y `LIMIT` no existe en DELETE de PG).
 *  - Las sumas de expenses (extracciones/ingresos) ahora filtran por companyId además de registerId.
 *
 * Tenant: $roc (getROC) en la lista; companyId bound en lookups y SIEMPRE en los WRITE.
 * Reusa helpers PG-safe ya arreglados: getAllSalesByDrawerPeriod / sumTotalBetweenDateRanges
 * (vendido por caja) y getSalesByPayment (desglose por medio de pago) — todos scopean por getROC.
 */
class ReportDrawersService
{
    /** @return array filas de cajas con componentes crudos (front calcula total/diferencia/colores). */
    public function listMovements($from, $to, $roc, $companyId)
    {
        $res = ncmExecute(
            "SELECT drawerId, registerId, outletId,
                    drawerOpenDate, drawerOpenAmount, drawerCloseDate, drawerCloseAmount,
                    drawerUserOpen, drawerUserClose
             FROM drawer
             WHERE drawerOpenDate >= ? AND drawerOpenDate <= ?" . $roc . "
             ORDER BY drawerUID ASC
             LIMIT 1000",
            [$from, $to], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }

        $raw = []; $outletIds = []; $registerIds = []; $userIds = [];
        while (!$res->EOF) {
            $f = $res->fields;
            $outlet = (string) ($f['outletId'] ?? '');
            $reg    = (string) ($f['registerId'] ?? '');
            $uOpen  = (string) ($f['drawerUserOpen'] ?? '');
            $uClose = (string) ($f['drawerUserClose'] ?? '');
            if ($outlet !== '') { $outletIds[$outlet] = true; }
            if ($reg    !== '') { $registerIds[$reg] = true; }
            if ($uOpen  !== '') { $userIds[$uOpen] = true; }
            if ($uClose !== '') { $userIds[$uClose] = true; }

            $raw[] = [
                'drawerId'    => (string) $f['drawerId'],
                'registerId'  => $reg,
                'outletId'    => $outlet,
                'openDate'    => (string) ($f['drawerOpenDate'] ?? ''),
                'openAmount'  => (float)  ($f['drawerOpenAmount'] ?? 0),
                'closeDate'   => (string) ($f['drawerCloseDate'] ?? ''),
                'closeAmount' => (float)  ($f['drawerCloseAmount'] ?? 0),
                'userOpen'    => $uOpen,
                'userClose'   => $uClose,
            ];
            $res->MoveNext();
        }
        $res->Close();

        $outlets   = $this->nameMap('outlet',   'outletId',   'outletName',   array_keys($outletIds),   $companyId);
        $registers = $this->nameMap('register', 'registerId', 'registerName', array_keys($registerIds), $companyId);
        $users     = $this->contactNames(array_keys($userIds), $companyId);

        // Ventas por caja del período (una sola query; getROC adentro scopea por companyId+outlet).
        $allSales = getAllSalesByDrawerPeriod($from, $to);

        $rows = [];
        foreach ($raw as $r) {
            $isClosed = $r['closeDate'] !== '';
            $closeBound = $isClosed ? $r['closeDate'] : date('Y-m-d 23:59:59', strtotime(TODAY));
            $t = $this->componentsFor($r['openDate'], $closeBound, $r['registerId'], $companyId, $allSales);

            $rows[] = [
                'drawerId'     => $r['drawerId'],
                'outletName'   => $outlets[$r['outletId']] ?? '',
                'registerName' => $registers[$r['registerId']] ?? '',
                'openDate'     => $r['openDate'],
                'openAmount'   => $r['openAmount'],
                'closeDate'    => $r['closeDate'],
                'closeAmount'  => $r['closeAmount'],
                'openUserName' => $users[$r['userOpen']]  ?? '',
                'closeUserName'=> $users[$r['userClose']] ?? '',
                'isClosed'     => $isClosed,
                'sold'         => $t['sold'],
                'expense'      => $t['expense'],
                'income'       => $t['income'],
                'return'       => $t['return'],
            ];
        }

        return $rows;
    }

    /**
     * Detalle de una caja por id (re-consultado, NO de un blob del cliente). Devuelve la fila + los
     * componentes crudos + el desglose por medio de pago. SCOPEADO por companyId.
     * @return array|null
     */
    public function detail($drawerId, $companyId)
    {
        $d = ncmExecute(
            "SELECT drawerId, registerId, outletId,
                    drawerOpenDate, drawerOpenAmount, drawerCloseDate, drawerCloseAmount,
                    drawerUserOpen, drawerUserClose
             FROM drawer WHERE drawerId = ? AND companyId = ? LIMIT 1",
            [$drawerId, $companyId]
        );
        // ncmExecute single-row → CaseInsensitiveArray (objeto, no array PHP) si hay fila; 0/false si no.
        if (!$d) {
            return null;
        }

        $register = (string) ($d['registerId'] ?? '');
        $outlet   = (string) ($d['outletId'] ?? '');
        $uOpen    = (string) ($d['drawerUserOpen'] ?? '');
        $uClose   = (string) ($d['drawerUserClose'] ?? '');
        $openDate = (string) ($d['drawerOpenDate'] ?? '');
        $closeDate= (string) ($d['drawerCloseDate'] ?? '');
        $isClosed = $closeDate !== '';
        $closeBound = $isClosed ? $closeDate : date('Y-m-d 23:59:59', strtotime(TODAY));

        $names     = $this->contactNames(array_filter([$uOpen, $uClose]), $companyId);
        $outlets   = $this->nameMap('outlet',   'outletId',   'outletName',   [$outlet],   $companyId);
        $registers = $this->nameMap('register', 'registerId', 'registerName', [$register], $companyId);

        $t = $this->componentsFor($openDate, $closeBound, $register, $companyId, null);

        // Desglose por medio de pago (getSalesByPayment scopea por companyId+register vía getROC).
        $payments = [];
        $byMethod = getSalesByPayment($openDate, $isClosed ? $closeDate : false, $register, false);
        if (is_array($byMethod)) {
            foreach ($byMethod as $p) {
                $code = (string) ($p['type'] ?? '');   // código crudo (cash/creditcard/… o taxonomy custom)
                $payments[] = [
                    'type'  => $code,                                  // el front identifica 'cash' por código
                    'label' => getPaymentMethodName($code),            // etiqueta resuelta (custom → taxonomy)
                    'price' => (float) ($p['price'] ?? 0),
                ];
            }
        }

        return [
            'drawerId'     => (string) $d['drawerId'],
            'outletName'   => $outlets[$outlet]     ?? '',
            'registerName' => $registers[$register] ?? '',
            'openDate'     => $openDate,
            'openAmount'   => (float) ($d['drawerOpenAmount'] ?? 0),
            'closeDate'    => $closeDate,
            'closeAmount'  => (float) ($d['drawerCloseAmount'] ?? 0),
            'openUserName' => $names[$uOpen]  ?? '',
            'closeUserName'=> $names[$uClose] ?? '',
            'isClosed'     => $isClosed,
            'sold'         => $t['sold'],
            'expense'      => $t['expense'],
            'income'       => $t['income'],
            'return'       => $t['return'],
            'payments'     => $payments,
        ];
    }

    /** Cierra una caja (monto + fecha de cierre + usuario actual). SCOPEADO por companyId. */
    public function close($drawerId, $companyId, $date, $amount, $userId)
    {
        global $db;
        $r = $db->Execute(
            "UPDATE drawer SET drawerCloseAmount = ?, drawerCloseDate = ?, drawerUserClose = ?
             WHERE drawerId = ? AND companyId = ?",
            [$amount, $date, ($userId !== '' ? $userId : null), $drawerId, $companyId]
        );
        return $r !== false;
    }

    /** Corrige fechas/montos de apertura y cierre. SCOPEADO por companyId. */
    public function correct($drawerId, $companyId, $openDate, $closeDate, $openAmount, $closeAmount)
    {
        global $db;
        $r = $db->Execute(
            "UPDATE drawer SET drawerOpenDate = ?, drawerCloseDate = ?, drawerOpenAmount = ?, drawerCloseAmount = ?
             WHERE drawerId = ? AND companyId = ?",
            [$openDate, ($closeDate !== '' ? $closeDate : null), $openAmount, $closeAmount, $drawerId, $companyId]
        );
        return $r !== false;
    }

    /** Elimina una caja. SCOPEADO por companyId (legacy era IDOR + LIMIT roto en PG). */
    public function remove($drawerId, $companyId)
    {
        global $db;
        $r = $db->Execute('DELETE FROM drawer WHERE drawerId = ? AND companyId = ?', [$drawerId, $companyId]);
        return $r !== false;
    }

    /** Vendido / extracciones / ingresos / devoluciones para una caja [open, closeBound]. */
    private function componentsFor($openDate, $closeBound, $registerId, $companyId, $allSales)
    {
        if ($allSales === null) {
            $allSales = getAllSalesByDrawerPeriod($openDate, $closeBound);
        }
        $sold = (float) sumTotalBetweenDateRanges($allSales, $registerId, $openDate, $closeBound);

        // type IS NULL = extracción; type IS NOT NULL = ingreso (semántica legacy).
        // ncmExecute single-row (SUM) → CaseInsensitiveArray (objeto, no array PHP); acceso por clave OK.
        $exp = ncmExecute(
            "SELECT SUM(expensesAmount) AS v FROM expenses
             WHERE expensesDate > ? AND expensesDate < ? AND type IS NULL AND registerId = ? AND companyId = ?",
            [$openDate, $closeBound, $registerId, $companyId]
        );
        $expense = $exp ? abs((float) ($exp['v'] ?? 0)) : 0.0;

        $inc = ncmExecute(
            "SELECT SUM(expensesAmount) AS v FROM expenses
             WHERE expensesDate > ? AND expensesDate < ? AND type IS NOT NULL AND registerId = ? AND companyId = ?",
            [$openDate, $closeBound, $registerId, $companyId]
        );
        $income = $inc ? abs((float) ($inc['v'] ?? 0)) : 0.0;

        $ret = ncmExecute(
            "SELECT SUM(transactionTotal) AS total, SUM(transactionDiscount) AS disc FROM transaction
             WHERE transactionDate BETWEEN ? AND ? AND registerId = ? AND transactionType = 6 AND companyId = ?",
            [$openDate, $closeBound, $registerId, $companyId]
        );
        $return = ($ret && $ret['total'] !== null) ? ((float) $ret['total'] - (float) ($ret['disc'] ?? 0)) : 0.0;

        return ['sold' => $sold, 'expense' => $expense, 'income' => $income, 'return' => $return];
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
        $ids = array_values(array_unique(array_filter($ids)));
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
