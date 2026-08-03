<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Cierres de Caja / Drawers (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportDrawersService.php (Fase 2 batch 9). Cambios vs original:
 *  - namespace + `final`
 *  - el ROC se recibe por PARÁMETRO en `listMovements` (no `getROC(1)` interno)
 *  - `getAllSalesByDrawerPeriod` (sólo en panel) → portado como `salesByDrawerPeriod()` privado
 *    que recibe `$roc` (no usa getROC interno). Mismo SQL y semántica.
 *  - `sumTotalBetweenDateRanges` (sólo en panel) → portado como `sumForRegister()` privado.
 *  - `getSalesByPayment($from, $to, $register, false)` (resolvería a la versión de /app: firma
 *    `($from,$to,$regId)` — funciona pero filtra tipo 6 además de 0,5, semántica distinta a la
 *    panel) → reemplazado por `NonAddingSales::salesByPayment` con $roc register-scoped.
 *
 * Tenant: $roc en lista; companyId SIEMPRE bound en lookups y WRITE. Helpers globales
 * usados (existen en /app): isInternalSale, isParentInternalSale, groupByPaymentMethod,
 * getPaymentMethodName.
 *
 * Endurecimientos vs legacy (heredados del panel original):
 *  - El detalle se re-consulta por id (no confía en blob del cliente).
 *  - `remove()` scopea por companyId (legacy era IDOR + LIMIT roto en PG).
 *  - Las sumas de expenses (extracciones/ingresos) filtran por companyId además de registerId.
 */
final class DrawersService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @return array filas de cajas con componentes crudos. */
    public function listMovements($from, $to, string $roc, string $companyId): array
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

        // Ventas por caja del período (una sola query, scopeada por $roc del caller).
        $allSales = $this->salesByDrawerPeriod($from, $to, $roc);

        $rows = [];
        foreach ($raw as $r) {
            $isClosed = $r['closeDate'] !== '';
            $closeBound = $isClosed ? $r['closeDate'] : date('Y-m-d 23:59:59', strtotime(TODAY));
            $t = $this->componentsFor($r['openDate'], $closeBound, $r['registerId'], $companyId, $allSales, $roc);

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

    /** Detalle de una caja (re-consultado, no blob del cliente). SCOPEADO por companyId. */
    public function detail(string $drawerId, string $companyId, string $roc): ?array
    {
        $d = ncmExecute(
            "SELECT drawerId, registerId, outletId,
                    drawerOpenDate, drawerOpenAmount, drawerCloseDate, drawerCloseAmount,
                    drawerUserOpen, drawerUserClose
             FROM drawer WHERE drawerId = ? AND companyId = ? LIMIT 1",
            [$drawerId, $companyId]
        );
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

        $t = $this->componentsFor($openDate, $closeBound, $register, $companyId, null, $roc);

        // Desglose por medio de pago — $roc scopeado al register de esta caja específica.
        // NonAddingSales::salesByPayment usa los tipos 0,5 (igual que la versión panel; la
        // versión de /app incluye tipo 6 = devoluciones, que NO se quieren acá).
        $payments = [];
        $regRoc = $this->buildRegisterRoc($companyId, $register);
        if ($regRoc !== '') {
            $closeArg = $isClosed ? $closeDate : '';
            foreach (NonAddingSales::salesByPayment($openDate, $closeArg, $regRoc) as $p) {
                $code = (string) ($p['type'] ?? '');
                $payments[] = [
                    'type'  => $code,
                    'label' => getPaymentMethodName($code),
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

    /** Cierra una caja. SCOPEADO por companyId. */
    public function close(string $drawerId, string $companyId, string $date, float $amount, string $userId): bool
    {
        global $db;
        // Doble barrera: scope por companyId + `drawerCloseDate IS NULL` para no
        // re-cerrar (pisar monto/fecha de) una caja ya cerrada. La edición de una
        // caja cerrada se hace por `correct()`, no por re-close. Affected_Rows()=0
        // ⇒ ya cerrada / no existe / de otra company ⇒ false.
        $r = $db->Execute(
            "UPDATE drawer SET drawerCloseAmount = ?, drawerCloseDate = ?, drawerUserClose = ?
             WHERE drawerId = ? AND companyId = ? AND drawerCloseDate IS NULL
             RETURNING drawerId",
            [$amount, $date, ($userId !== '' ? $userId : null), $drawerId, $companyId]
        );
        return $r !== false && $r->RecordCount() > 0;
    }

    /** Corrige fechas/montos de apertura y cierre. SCOPEADO por companyId. */
    public function correct(string $drawerId, string $companyId, string $openDate, string $closeDate, float $openAmount, float $closeAmount): bool
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
    public function remove(string $drawerId, string $companyId): bool
    {
        global $db;
        $r = $db->Execute('DELETE FROM drawer WHERE drawerId = ? AND companyId = ?', [$drawerId, $companyId]);
        return $r !== false;
    }

    /** Vendido / extracciones / ingresos / devoluciones para una caja [open, closeBound]. */
    private function componentsFor(string $openDate, string $closeBound, string $registerId, string $companyId, ?array $allSales, string $roc): array
    {
        if ($allSales === null) {
            $allSales = $this->salesByDrawerPeriod($openDate, $closeBound, $roc);
        }
        $sold = $this->sumForRegister($allSales, $registerId, $openDate, $closeBound);

        // type IS NULL = extracción; type IS NOT NULL = ingreso (semántica legacy).
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

    /**
     * Port fiel de getAllSalesByDrawerPeriod() del panel — sólo el ROC se recibe por parámetro
     * (no se recalcula adentro). Devuelve por registerId: lista de {date, total} (total=
     * transactionTotal - transactionDiscount) filtrando ventas internas.
     *
     * @return array<string, array<int, array{date:string,total:float}>>
     */
    private function salesByDrawerPeriod(string $from, string $to, string $roc): array
    {
        $sql = "SELECT transactionId, transactionTotal as total, transactionDiscount as discount, registerId,
                       transactionDate, transactionType, meta->>'tags' AS tags
                FROM transaction
                WHERE transactionDate BETWEEN ? AND ?
                  AND transactionType IN (0,5,6)" . $roc;

        $result = ncmExecute($sql, [$from, $to], false, true);
        if (!$result) {
            return [];
        }

        $rows = [];
        while (!$result->EOF) {
            $rows[] = $result->fields;
            $result->MoveNext();
        }
        $result->Close();

        // mig 115: transactionParentId dropeada — batch lookup del origen de
        // los pagos (type 5) vía transaction_link. companyId sale de $roc
        // (Roc::build lo embebe como literal validado), igual criterio que
        // NonAddingSales::salesByPayment (mismo helper acá, duplicado a
        // propósito: son clases sin relación de herencia).
        $companyId = self::companyIdFromRoc($roc);
        $paymentIds = array_values(array_filter(array_map(
            static fn($f) => (int) $f['transactionType'] === 5 ? (string) $f['transactionId'] : null,
            $rows
        )));
        $originByPayment = ($paymentIds !== [] && $companyId !== '')
            ? (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds($companyId, $paymentIds, 'credit_payment')
            : [];

        $a = [];
        foreach ($rows as $f) {
            if ((int) $f['transactionType'] === 5) {
                $parentId = $originByPayment[(string) $f['transactionId']] ?? null;
                $ignore   = $parentId ? isParentInternalSale($parentId) : false;
            } else {
                $tags   = json_decode((string) ($f['tags'] ?? ''), true);
                $ignore = isInternalSale($tags);
            }
            if (!$ignore) {
                $reg = (string) ($f['registerId'] ?? '');
                $a[$reg][] = [
                    'date'  => (string) $f['transactionDate'],
                    'total' => (float) $f['total'] - (float) $f['discount'],
                ];
            }
        }
        return $a;
    }

    /**
     * Extrae companyId del fragmento `$roc` (Roc::build() lo embebe como
     * literal validado: `AND companyId = 'uuid'`).
     */
    private static function companyIdFromRoc(string $roc): string
    {
        return preg_match("/companyId\\s*=\\s*'([0-9a-f-]{36})'/i", $roc, $m) === 1 ? $m[1] : '';
    }

    /** Port fiel de sumTotalBetweenDateRanges() del panel. */
    private function sumForRegister(array $allSales, string $registerId, string $from, string $to): float
    {
        if ($to === '0000-00-00 00:00:00' || $to === '') {
            $to = TODAY;
        }
        $total = 0.0;
        foreach ($allSales as $reg => $values) {
            if ($reg !== $registerId) { continue; }
            foreach ($values as $data) {
                if ($data['date'] > $from && $data['date'] < $to) {
                    $total += (float) $data['total'];
                }
            }
        }
        return $total;
    }

    /** ROC scoped a register específico (companyId + registerId). Sólo si ambos son UUID. */
    private function buildRegisterRoc(string $companyId, string $registerId): string
    {
        if (!preg_match(self::UUID_RE, $companyId) || !preg_match(self::UUID_RE, $registerId)) {
            return '';
        }
        return " AND companyId = '" . $companyId . "' AND registerId = '" . $registerId . "'";
    }

    /** Lookup batch id→name de outlet/register, scopeado por companyId. */
    private function nameMap(string $table, string $idCol, string $nameCol, array $ids, string $companyId): array
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
    private function contactNames(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
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
            $map[(string) $c['contactId']] = trim(((string) ($c['contactName'] ?? '')) . ' ' . ((string) ($c['contactSecondName'] ?? '')));
        }
        return $map;
    }
}
