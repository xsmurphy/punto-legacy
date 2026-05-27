<?php
/**
 * Dominio de Reportes — Dashboard del panel (capa API, motor ERP).
 *
 * El dashboard es una COMPOSICIÓN de widgets; cada uno se pide por separado:
 *   GET /API/v1/reports/dashboard?widget=<nombre>&from=&to=[&week&prev&type]
 *
 * Widgets: info, incomeOutcomeStats, paymentStatus, customers, customersRates, topItems,
 * topHours, topCategories, topBrands, topPayments, satisfaction, orders, tables, schedule,
 * notifications, notificationsCount, getReminders.
 *
 * Devuelve datos CRUDOS (números, sin formatear, sin HTML); el front formatea + arma cada widget.
 * Read-only (el único write del legacy, `?action=tutorial`, queda fuera). Ver REGLA RAÍZ 2.
 *
 * GLOBALS DE PÁGINA RESUELTOS (el dashboard legacy depende de config.php, que el middleware de la
 * API v1 NO carga): `$_modules`/`$_cmpSettings`/plan/OUTLETS_COUNT → vía `company.config` (lookup
 * cacheado en `companyMeta()`); `$plansValues` → global de functions.php; `API_KEY` → computado
 * (sha1 config.accountId); `USER_ID` → `PANEL_AUTHED_USER`.
 *
 * Fixes PG: `FORCE INDEX` (MySQL) eliminado; `HOUR()` → `EXTRACT(HOUR FROM ...)`; `contactInCalendar`
 * demovido a `data` JSONB → `data->>'contactInCalendar'`; `customerId > 1` (UUID vs int) → `IS NOT NULL`.
 *
 * Tenant: $roc (getROC) por query; companyId bound en cada lookup.
 */
class ReportDashboardService
{
    private $meta = null;   // fila company.config (flatten) cacheada

    /** Despacha el widget pedido. */
    public function widget($name, array $opts)
    {
        switch ($name) {
            case 'info':                return $this->info();
            case 'incomeOutcomeStats':  return $this->incomeOutcomeStats($opts);
            case 'paymentStatus':       return $this->paymentStatus($opts);
            case 'customers':           return $this->customers($opts);
            case 'customersRates':      return $this->customersRates($opts);
            case 'topItems':            return $this->topItems($opts);
            case 'topHours':            return $this->topHours($opts);
            case 'topCategories':       return $this->topCategories($opts);
            case 'topBrands':           return $this->topBrands($opts);
            case 'topPayments':         return $this->topPayments($opts);
            case 'satisfaction':        return $this->satisfaction($opts);
            case 'orders':              return $this->orders();
            case 'tables':              return $this->tables();
            case 'schedule':            return $this->schedule($opts);
            case 'notifications':       return $this->notifications($opts);
            case 'notificationsCount':  return $this->notificationsCount($opts);
            case 'getReminders':        return [];   // legacy: hardcodeado a una company → muerto
            default:                    return null; // el endpoint responde 422
        }
    }

    /* ───────────────────────── widgets de stats ───────────────────────── */

    /** Contadores generales + límites del plan. */
    private function info()
    {
        $roc = getROC(1);
        $gift  = ncmExecute("SELECT COUNT(*) as count FROM giftCardSold WHERE transactionId IS NOT NULL AND giftCardSoldValue > 0" . $roc);
        $users = ncmExecute("SELECT COUNT(*) as count FROM contact WHERE type = 0 AND companyId = ?", [COMPANY_ID]);
        $items = ncmExecute("SELECT COUNT(*) as count FROM item WHERE companyId = ?", [COMPANY_ID]);
        $draw  = ncmExecute("SELECT COUNT(*) as count FROM drawer WHERE (drawerCloseDate IS NULL OR drawerCloseDate < '2010-01-01 00:00:00')" . $roc . " LIMIT 10");
        $startD = date('Y-m-01 00:00:00');
        $endD   = date('Y-m-t 23:59:59');
        $trans = ncmExecute("SELECT COUNT(*) as count FROM transaction WHERE companyId = ? AND transactionDate BETWEEN ? AND ?", [COMPANY_ID, $startD, $endD]);

        $m = $this->companyMeta();
        $outlets = (int) $this->scalar("SELECT COUNT(*) as count FROM outlet WHERE companyId = ?", [COMPANY_ID]);
        global $plansValues;
        if (!is_array($plansValues) || !$plansValues) {
            $plansValues = function_exists('getAllPlans') ? getAllPlans() : [];
        }
        $plan = (string) ($m['plan'] ?? '');
        $planRow = (is_array($plansValues) && isset($plansValues[$plan])) ? $plansValues[$plan] : [];

        return [
            'giftCardsCount'    => (int) ($gift['count'] ?? 0),
            'openDrawersCount'  => (int) ($draw['count'] ?? 0),
            'outletsCount'      => $outlets,
            'plan'              => (string) ($planRow['name'] ?? ''),
            'usersCount'        => (int) ($users['count'] ?? 0),
            'usersMax'          => (int) ($planRow['max_users'] ?? 0) * max(1, $outlets),
            'itemsCount'        => (int) ($items['count'] ?? 0),
            'itemsMax'          => (int) ($planRow['max_items'] ?? 0),
            'transactionsCount' => (int) ($trans['count'] ?? 0),
        ];
    }

    /** Ingresos/egresos/revenue/margen/ticket promedio del período (+week, +prev). */
    private function incomeOutcomeStats(array $opts)
    {
        $roc = getROC(1);
        [$from, $to] = $this->range($opts);

        $sales = ncmExecute(
            "SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount,
                    SUM(transactionUnitsSold) as units, COUNT(transactionId) as count
             FROM transaction WHERE transactionType IN (0,3,6)
             AND transactionDate >= ? AND transactionDate <= ?" . $roc,
            [$from, $to]
        );
        $internals = lessInternalTotals($roc, $from, $to);
        $finalTotal = $sales ? (((float) $sales['total'] - (float) $sales['discount']) - (float) ($internals['total'] ?? 0)) : 0.0;
        $count = $sales ? (int) $sales['count'] : 0;
        $customerAverage = ($sales && $count) ? ((float) $sales['total'] / $count) : 0.0;

        $expen = ncmExecute(
            "SELECT SUM(transactionTotal) as total FROM transaction
             WHERE transactionType IN (1,4) AND transactionDate >= ? AND transactionDate <= ?
             AND transactionStatus = 1" . $roc,
            [$from, $to]
        );
        $totalExpenses = $expen ? (float) ($expen['total'] ?? 0) : 0.0;
        $revenue = $finalTotal - $totalExpenses;
        $margin = ($finalTotal > 0 && $totalExpenses > 0) ? max(0, ($revenue / $finalTotal) * 100) : 100;

        return [
            'total'           => $finalTotal,
            'expenses'        => $totalExpenses,
            'revenue'         => $revenue,
            'margin'          => round($margin),
            'count'           => $count,
            'customerAverage' => $customerAverage,
        ];
    }

    /**
     * Contado/crédito/cobrado/por-cobrar (totales + conteos) del período.
     * Reimplementado: el getAllTransactions del legacy usa `global $startDate/$endDate` (no
     * existen en el contexto API → query con BETWEEN NULL) → se consulta acá con from/to explícito.
     */
    private function paymentStatus(array $opts)
    {
        $roc = getROC(1);
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT transactionId, transactionTotal, transactionDiscount, transactionType
             FROM transaction WHERE transactionType IN (0,3) AND transactionDate BETWEEN ? AND ?" . $roc,
            [$from, $to], false, false, true
        );
        $res  = is_array($res) ? $res : [];
        $paid = getAllToPayTransactions(true);   // pagos acumulados por parentId (sin filtro de fecha, como el legacy)
        $paid = is_array($paid) ? $paid : [];

        $contado = 0.0; $credito = 0.0; $cobrado = 0.0;
        $coCount = 0; $cCount = 0; $cobCount = 0; $porCount = 0;
        foreach ($res as $t) {
            if ((string) $t['transactionType'] === '3') {
                $tTotal = (float) $t['transactionTotal'] - (float) $t['transactionDiscount'];
                $tPayed = (float) ($paid[(string) $t['transactionId']] ?? 0);
                $topay = $tTotal - $tPayed;
                $cobrado += $tPayed;
                if ($topay <= 0) { $cobCount++; } else { $porCount++; }
                $credito += $tTotal; $cCount++;
            } else {
                $contado += (float) $t['transactionTotal'] - (float) $t['transactionDiscount'];
                $coCount++;
            }
        }
        return [
            'contado' => $contado, 'credito' => $credito, 'cobrado' => $cobrado,
            'porcobrar' => $credito - $cobrado,
            'contadoCount' => $coCount, 'creditoCount' => $cCount,
            'cobradoCount' => $cobCount, 'porcobrarCount' => $porCount,
        ];
    }

    /** Clientes: total, nuevos del período, recurrentes, tasa de retorno (+week, +prev). */
    private function customers(array $opts)
    {
        $roc = getROC(1);
        [$from, $to] = $this->range($opts);

        $total = (int) $this->scalar("SELECT COUNT(contactId) as count FROM contact WHERE type = 1 AND companyId = ?", [COMPANY_ID]);
        $new   = (int) $this->scalar(
            "SELECT COUNT(contactId) as count FROM contact WHERE type = 1 AND contactDate BETWEEN ? AND ? AND companyId = ?",
            [$from, $to, COMPANY_ID]
        );
        // Recurrentes: clientes (creados antes del período) con ventas en el período.
        // Fix PG: `customerId > 1` (UUID vs int) → customerId IS NOT NULL.
        $rocA = str_replace(['outletId', 'companyId'], ['a.outletId', 'a.companyId'], $roc);
        $res = ncmExecute(
            "SELECT COUNT(DISTINCT a.customerId) as count
             FROM transaction a, contact b
             WHERE a.transactionDate BETWEEN ? AND ? AND a.customerId IS NOT NULL" . $rocA . "
             AND a.transactionType IN (0,3) AND a.customerId = b.contactId
             AND b.contactDate < ? AND b.type = 1",
            [$from, $to, $from]
        );
        $recurring = max(0, (int) ($res['count'] ?? 0));

        return [
            'total'       => $total,
            'totalPeriod' => $new + $recurring,
            'new'         => $new,
            'old'         => $recurring,
            'returnRate'  => round($this->div($recurring, $new + $recurring) * 100, 2),
        ];
    }

    /** Tasas de clientes (helper de dominio). */
    private function customersRates(array $opts)
    {
        [$from, $to] = $this->range($opts);
        $out = getCustomersRate($from, $to);
        return is_array($out) ? $out : [];
    }

    /* ───────────────────────── widgets "top" ───────────────────────── */

    /** Top 6 horas por unidades. Fix PG: HOUR()→EXTRACT, sin FORCE INDEX. */
    private function topHours(array $opts)
    {
        $roc = getROC(1);
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT EXTRACT(HOUR FROM transactionDate)::int as hora, COUNT(transactionId) as total,
                    SUM(transactionUnitsSold) as units
             FROM transaction WHERE transactionType IN (0,3) AND transactionDate BETWEEN ? AND ?" . $roc . "
             GROUP BY hora ORDER BY units DESC LIMIT 6",
            [$from, $to], false, false, true
        );
        $res = is_array($res) ? $res : [];
        $hour = []; $total = [];
        foreach ($res as $r) {
            $hour[]  = str_pad((string) (int) $r['hora'], 2, '0', STR_PAD_LEFT) . ':00 Ventas';
            $total[] = round((float) $r['total'], 2);
        }
        return ['hour' => $hour, 'total' => $total];
    }

    /** Top 5 artículos por unidades vendidas. */
    private function topItems(array $opts)
    {
        $roc = str_replace(['outletId', 'companyId'], ['b.outletId', 'b.companyId'], getROC(1));
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT a.itemId as id, SUM(a.itemSoldUnits) as count, SUM(a.itemSoldTotal) as total
             FROM itemSold a, transaction b
             WHERE b.transactionType IN (0,3) AND b.transactionDate BETWEEN ? AND ?" . $roc . "
             AND a.transactionId = b.transactionId AND a.itemSoldTotal > 0
             GROUP BY a.itemId ORDER BY count DESC LIMIT 5",
            [$from, $to], false, false, true
        );
        $res = is_array($res) ? $res : [];
        $out = [];
        foreach ($res as $r) {
            $item = getItemData((string) $r['id']);
            $out[] = [
                'name'  => $item ? (string) ($item['itemName'] ?? '') : '',
                'count' => (float) $r['count'],
                'total' => (float) $r['total'],
            ];
        }
        return $out;
    }

    /** Top categorías por unidades. Reimplementado: el helper legacy SELECTea `a.itemId` sin
     *  agruparlo (rompe en PG); acá GROUP BY categoryId limpio (categoryId es columna real). */
    private function topCategories(array $opts)
    {
        return $this->topTaxonomy($opts, 'categoryId', 'Sin categoría');
    }

    /** Top marcas por unidades (mismo fix PG que topCategories; brandId es columna real). */
    private function topBrands(array $opts)
    {
        return $this->topTaxonomy($opts, 'brandId', 'Sin marca');
    }

    private function topTaxonomy(array $opts, $col, $emptyLabel)
    {
        $roc = str_replace(['outletId', 'companyId'], ['c.outletId', 'c.companyId'], getROC(1));
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT b.$col as tax, SUM(a.itemSoldUnits) as usold
             FROM itemSold a, item b, transaction c
             WHERE a.itemId = b.itemId AND a.itemSoldDate BETWEEN ? AND ?
             AND c.transactionType IN (0,3) AND a.transactionId = c.transactionId" . $roc . "
             GROUP BY b.$col ORDER BY usold DESC LIMIT 10",
            [$from, $to], false, false, true
        );
        $res = is_array($res) ? $res : [];
        $out = [];
        foreach ($res as $r) {
            $name = (string) getTaxonomyName((string) $r['tax']);
            $out[] = ['title' => ($name === 'None' || $name === '') ? $emptyLabel : $name, 'total' => round((float) $r['usold'], 2)];
        }
        return $out;
    }

    /** Ventas por medio de pago (helper de dominio), ordenadas desc. */
    private function topPayments(array $opts)
    {
        $roc = getROC(1);
        [$from, $to] = $this->range($opts);
        $top = getSalesByPayment($from, $to, $roc);
        $top = is_array($top) ? $top : [];
        usort($top, fn($a, $b) => (float) $b['price'] <=> (float) $a['price']);
        $out = ['title' => [], 'amount' => []];
        foreach ($top as $m) {
            $out['title'][]  = (string) getPaymentMethodName($m['type']);
            $out['amount'][] = (float) $m['price'];
        }
        return $out;
    }

    /** Satisfacción (NPS): detractores/pasivos/promotores. Gateado por módulo feedback. */
    private function satisfaction(array $opts)
    {
        if (!$this->moduleOn('feedback')) {
            return [];
        }
        $roc = getROC(1);
        [$from, $to] = $this->range($opts);
        $lvl = function ($n) use ($roc, $from, $to) {
            return (int) $this->scalar(
                "SELECT COUNT(*) as count FROM satisfaction WHERE satisfactionLevel = ? AND satisfactionDate BETWEEN ? AND ?" . $roc,
                [$n, $from, $to]
            );
        };
        $uno = $lvl(1); $dos = $lvl(2); $tres = $lvl(3);
        $total = $uno + $dos + $tres;
        return [
            'detractors' => ['percent' => round($this->div($uno, $total) * 100), 'count' => $uno],
            'passives'   => ['percent' => round($this->div($dos, $total) * 100), 'count' => $dos],
            'promoters'  => ['percent' => round($this->div($tres, $total) * 100), 'count' => $tres],
        ];
    }

    /* ───────────────────────── widgets gateados por módulo ───────────────────────── */

    /** Órdenes (tipo 12). Gateado por módulo ordersPanel. */
    private function orders()
    {
        if (!$this->moduleOn('ordersPanel')) {
            return [];
        }
        $roc = getROC(1);
        return [
            'ordersCount' => (int) $this->scalar("SELECT COUNT(*) as count FROM transaction WHERE transactionType = 12 AND transactionStatus IN (0,1,2,3,5)" . $roc),
            'onlineCount' => (int) $this->scalar("SELECT COUNT(*) as count FROM transaction WHERE transactionType = 12 AND transactionStatus IN (0,1,2,3,5) AND transactionName = 'ecom'" . $roc),
        ];
    }

    /** Mesas (tipo 11). Gateado por módulo tables. */
    private function tables()
    {
        if (!$this->moduleOn('tables')) {
            return [];
        }
        $roc = getROC(1);
        $occup = (int) $this->scalar("SELECT COUNT(*) as count FROM transaction WHERE transactionType = 11 AND transactionName > 0" . $roc);
        $m = $this->companyMeta();
        $totalTables = (int) ($m['tablesCount'] ?? 0) ?: 90;
        return [
            'tablesCount' => $occup,
            'totalTables' => $totalTables,
            'occupacy'    => $totalTables ? round(($occup * 100) / $totalTables) : 0,
            'freeTables'  => $totalTables - $occup,
        ];
    }

    /** Agenda (tipo 13): ocupación de horas. Gateado por módulo calendar. */
    private function schedule(array $opts)
    {
        if (!$this->moduleOn('calendar')) {
            return [];
        }
        $roc = getROC(1);
        [$from, $to] = $this->range($opts);
        // Itera el recordset (forceObj), NO getAssoc: GetAssoc keyea por la 1ª columna (fromDate) y
        // colapsaría las citas que comparten hora de inicio. Ver nota homóloga en ReportScheduleService.
        $res = ncmExecute(
            "SELECT fromDate, toDate, transactionStatus FROM transaction
             WHERE transactionType = 13 AND fromDate >= ? AND toDate <= ?
             AND transactionStatus IN (0,1,2,3,6,7)" . $roc,
            [$from, $to], false, true
        );
        $workingHours = 0.0; $blockedHours = 0.0; $scheduledCount = 0;
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f = $res->fields;
                $h = max(0, (strtotime((string) $f['toDate']) - strtotime((string) $f['fromDate'])) / 3600);
                if ((string) $f['transactionStatus'] === '7') { $blockedHours += $h; }
                else { $workingHours += $h; $scheduledCount++; }
                $res->MoveNext();
            }
            $res->Close();
        }

        // Usuarios agendables. Fix PG: contactInCalendar demovido a data JSONB.
        $agendables = (int) $this->scalar(
            "SELECT COUNT(*) as count FROM contact
             WHERE type = 0 AND contactStatus > 0 AND data->>'contactInCalendar' = '1' AND companyId = ?
             AND (outletId = ? OR outletId IS NULL)",
            [COMPANY_ID, OUTLET_ID]
        );
        $m = $this->companyMeta();
        $openFrom = (string) ($m['settingOpenFrom'] ?? '08');
        $openTo   = (string) ($m['settingOpenTo'] ?? '20');
        $openHours = max(0, (int) $openTo - (int) $openFrom);
        $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400));
        $shift = ($openHours * $agendables) * $days;

        return [
            'scheduledCount' => $scheduledCount,
            'occupancy'      => $workingHours > 0 ? round($this->div($workingHours * 100, $shift)) : 0,
            'shiftHours'     => $shift,
            'workingHours'   => $workingHours,
            'freeHours'      => $shift - $workingHours,
            'blockedHours'   => $blockedHours,
        ];
    }

    /* ───────────────────────── widgets gateway (API externa) ───────────────────────── */

    /** Notificaciones (gateway a la API legacy get_notifications). */
    private function notifications(array $opts)
    {
        return $this->notifyGateway('get_notifications', $opts);
    }

    /** Conteo de notificaciones (gateway). */
    private function notificationsCount(array $opts)
    {
        return $this->notifyGateway('get_notifications_count', $opts);
    }

    private function notifyGateway($endpoint, array $opts)
    {
        $data = [
            'api_key'    => $this->apiKey(),
            'company_id' => enc(COMPANY_ID),
            'user'       => enc(PANEL_AUTHED_USER),   // §14: en la API es PANEL_AUTHED_USER, no USER_ID
            'type'       => $opts['type'] ?: 'notes',
            'outlet'     => enc(OUTLET_ID),
        ];
        $raw = curlContents(API_URL . '/' . $endpoint, 'POST', $data);
        $res = json_decode((string) $raw, true);
        return is_array($res) ? $res : [];
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** Rango de fechas resuelto: week → última semana; prev → período anterior espejado. */
    private function range(array $opts)
    {
        $from = $opts['from']; $to = $opts['to'];
        if (!empty($opts['week'])) {
            $from = date('Y-m-d 00:00:00', strtotime('-1 week'));
            $to   = date('Y-m-d 23:59:59');
        }
        if (!empty($opts['prev'])) {
            $sF = strtotime($from); $eF = strtotime($to); $diff = $eF - $sF;
            $from = date('Y-m-d H:i:00', $sF - $diff);
            $to   = date('Y-m-d H:i:00', $eF - $diff);
        }
        return [$from, $to];
    }

    /** Fila company.config (flatten) cacheada — fuente de módulos/settings/plan. */
    private function companyMeta()
    {
        if ($this->meta === null) {
            $row = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [COMPANY_ID]);
            $this->meta = ($row && (is_object($row) || is_array($row))) ? $row : [];
        }
        return $this->meta;
    }

    /** ¿Módulo activo? Lee el flag del company.config (= $_modules del legacy). */
    private function moduleOn($key)
    {
        $m = $this->companyMeta();
        $v = $m[$key] ?? null;
        return $v && $v !== '0' && $v !== 'false' && $v !== 'null';
    }

    /** api_key del gateway: sha1(company.config.accountId) — igual que config.php (define API_KEY). */
    private function apiKey()
    {
        if (defined('API_KEY')) {
            return API_KEY;
        }
        $m = $this->companyMeta();
        return sha1((string) ($m['accountId'] ?? ''));
    }

    /** Escalar de un SELECT agregado (siempre aliaseado `as count`). */
    private function scalar($sql, array $params = [])
    {
        $row = ncmExecute($sql, $params);
        return $row ? ($row['count'] ?? 0) : 0;
    }

    /** División segura. */
    private function div($a, $b)
    {
        return ($b == 0) ? 0 : ($a / $b);
    }
}
