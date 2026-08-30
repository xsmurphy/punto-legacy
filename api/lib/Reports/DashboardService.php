<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Dashboard del panel (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportDashboardService.php (Fase 2 batch 15 — FINAL F2).
 * Cambios vs el original:
 *  - namespace + `final`
 *  - ROC, companyId, outletId, userId por PARÁMETRO (no globals).
 *  - `lessInternalTotals`, `getSalesByPayment`, `getPreviousPeriod` (panel-only / rotos en
 *    /app) → `NonAddingSales::*` (helper compartido, expuestos public static en batches
 *    previos: lessInternalTotals y previousPeriod en batch 14, salesByPayment en batch 8).
 *  - `getAllToPayTransactions` (en /app pero cacheaba en la sesión de PHP y `COMPANY_ID` interpolado en
 *    el SQL) → `payedByParent()` private inline parametrizado.
 *  - `getItemData` (panel-only) → `itemData()` private inline.
 *  - `getCustomersRate` (panel-only, contiene `COMPANY_ID` interpolado sin comillas →
 *    roto en PG) → `customersRate()` private inline con bound params.
 *  - `getTaxonomyName` → lookup directo bindeado por companyId (fix preventivo aplicado
 *    en batch 14 + commit fix 348b4ba; ver ProductsService::tname).
 *  - `enc` → identity en PG (mismo patrón que otros services).
 *  - `getAllPlans`, `getPaymentMethodName`, `curlContents`, `toUTF8` resuelven por
 *    fallback de namespace (en /app).
 *
 * 17 widgets (uno por llamada GET); el dashboard del panel compone llamando varios.
 * Read-only. Tenant: $roc por query; companyId bound en cada lookup.
 *
 * Fixes PG heredados del original: `FORCE INDEX` MySQL eliminado; `HOUR()` →
 * `EXTRACT(HOUR FROM ...)`; `contactInCalendar` JSONB; `customerId > 1` (UUID vs int)
 * → `customerId IS NOT NULL`.
 */
final class DashboardService
{
    private mixed $meta = null;   // CaseInsensitiveArray (wrapper) o []
    private array $taxonomyCache = [];

    public function widget(string $name, array $opts, string $roc, string $companyId, string $outletId, string $userId): array
    {
        switch ($name) {
            case 'info':                return $this->info($roc, $companyId);
            case 'incomeOutcomeStats':  return $this->incomeOutcomeStats($opts, $roc);
            case 'paymentStatus':       return $this->paymentStatus($opts, $roc, $companyId);
            case 'customers':           return $this->customers($opts, $roc, $companyId);
            case 'customersRates':      return $this->customersRates($opts, $roc, $companyId);
            case 'customersSeries':     return $this->customersSeries($opts, $companyId);
            case 'topItems':            return $this->topItems($opts, $roc, $companyId);
            case 'topHours':            return $this->topHours($opts, $roc);
            case 'topCategories':       return $this->topTaxonomy($opts, 'categoryId', 'Sin categoría', $roc, $companyId);
            case 'topBrands':           return $this->topTaxonomy($opts, 'brandId', 'Sin marca', $roc, $companyId);
            case 'topPayments':         return $this->topPayments($opts, $roc);
            case 'satisfaction':        return $this->satisfaction($opts, $roc, $companyId);
            case 'orders':              return $this->orders($roc, $companyId);
            case 'tables':              return $this->tables($roc, $companyId);
            case 'schedule':            return $this->schedule($opts, $roc, $companyId, $outletId);
            case 'notifications':       return $this->notifyGateway('get_notifications',       $opts, $companyId, $outletId, $userId);
            case 'notificationsCount':  return $this->notifyGateway('get_notifications_count', $opts, $companyId, $outletId, $userId);
            case 'getReminders':        return [];
            default:                    return [];
        }
    }

    /* ───────────── widgets de stats ───────────── */

    private function info(string $roc, string $companyId): array
    {
        $gift  = ncmExecute("SELECT COUNT(*) as count FROM giftCardSold WHERE transactionId IS NOT NULL AND giftCardSoldValue > 0" . $roc);
        $users = ncmExecute("SELECT COUNT(*) as count FROM contact WHERE type = 0 AND companyId = ?", [$companyId]);
        $items = ncmExecute("SELECT COUNT(*) as count FROM item WHERE companyId = ?", [$companyId]);
        $draw  = ncmExecute("SELECT COUNT(*) as count FROM drawer WHERE (drawerCloseDate IS NULL OR drawerCloseDate < '2010-01-01 00:00:00')" . $roc . " LIMIT 10");
        $startD = date('Y-m-01 00:00:00');
        $endD   = date('Y-m-t 23:59:59');
        $trans = ncmExecute("SELECT COUNT(*) as count FROM transaction WHERE companyId = ? AND transactionDate BETWEEN ? AND ?", [$companyId, $startD, $endD]);
        // ¿Vendió ALGUNA VEZ? (lifetime, EXISTS barato). El gate del hero de
        // bienvenida del dashboard usa esto — transactionsCount es del MES
        // calendario, así que gatear por él mostraba "Bienvenido a Punto" a
        // cuentas con meses de ventas cada día 1 del mes (bug 2026-08-01).
        $everSold = ncmExecute("SELECT EXISTS(SELECT 1 FROM transaction WHERE companyId = ?) AS e", [$companyId]);
        $hasSalesRaw = is_array($everSold) || $everSold instanceof \ArrayAccess ? ($everSold['e'] ?? false) : false;

        $m = $this->companyMeta($companyId);
        $outlets = (int) $this->scalar("SELECT COUNT(*) as count FROM outlet WHERE companyId = ?", [$companyId]);
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
            // Lifetime — ver comentario arriba. PDO/PG puede devolver bool o 't'.
            'hasSales'          => ($hasSalesRaw === true || $hasSalesRaw === 't' || $hasSalesRaw === '1' || $hasSalesRaw === 1),
        ];
    }

    private function incomeOutcomeStats(array $opts, string $roc): array
    {
        [$from, $to] = $this->range($opts);

        $sales = ncmExecute(
            "SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount,
                    SUM(transactionUnitsSold) as units, COUNT(transactionId) as count
             FROM transaction WHERE transactionType IN (0,3,6)
             AND " . SaleFilters::notVoidedSql() . "
             AND transactionDate >= ? AND transactionDate <= ?" . $roc,
            [$from, $to]
        );
        $internals = NonAddingSales::lessInternalTotals($roc, $from, $to);
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

    private function paymentStatus(array $opts, string $roc, string $companyId): array
    {
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT transactionId, transactionTotal, transactionDiscount, transactionType
             FROM transaction WHERE transactionType IN (0,3) AND " . SaleFilters::notVoidedSql() . "
             AND transactionDate BETWEEN ? AND ?" . $roc,
            [$from, $to], false, false, true
        );
        $res  = is_array($res) ? $res : [];
        $paid = $this->payedByParent($companyId);

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

    private function customers(array $opts, string $roc, string $companyId): array
    {
        [$from, $to] = $this->range($opts);

        $total = (int) $this->scalar("SELECT COUNT(contactId) as count FROM contact WHERE type = 1 AND companyId = ?", [$companyId]);
        $new   = (int) $this->scalar(
            "SELECT COUNT(contactId) as count FROM contact WHERE type = 1 AND contactDate BETWEEN ? AND ? AND companyId = ?",
            [$from, $to, $companyId]
        );

        $rocA = str_replace(['outletId', 'companyId'], ['a.outletId', 'a.companyId'], $roc);
        $res = ncmExecute(
            "SELECT COUNT(DISTINCT a.customerId) as count
             FROM transaction a, contact b
             WHERE a.transactionDate BETWEEN ? AND ? AND a.customerId IS NOT NULL" . $rocA . "
             AND a.transactionType IN (0,3) AND " . SaleFilters::notVoidedSql('a') . "
             AND a.customerId = b.contactId
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

    private function customersRates(array $opts, string $roc, string $companyId): array
    {
        [$from, $to] = $this->range($opts);
        return $this->customersRate($from, $to, $roc, $companyId);
    }

    /**
     * Serie de clientes NUEVOS por mes (para gráficos de evolución, ej. el agente IA).
     * Mismo criterio que `customers()->new`: contact.type=1, contactDate en rango,
     * companyId. Sin roc: contact NO está scopeado por outlet (igual que `customers()`,
     * cuyo `new`/`total` tampoco aplica $roc — solo el join de recurrentes lo usa).
     */
    private function customersSeries(array $opts, string $companyId): array
    {
        [$from, $to] = $this->range($opts);

        $sql = "SELECT to_char(date_trunc('month', contactDate), 'YYYY-MM') AS bucket,
                       COUNT(contactId) AS count
                FROM contact
                WHERE type = 1 AND companyId = ? AND contactDate BETWEEN ? AND ?
                GROUP BY bucket
                ORDER BY bucket ASC";

        $res  = ncmExecute($sql, [$companyId, $from, $to], false, true);
        $rows = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f      = $res->fields;
                $rows[] = ['bucket' => (string) $f['bucket'], 'new' => (int) ($f['count'] ?? 0)];
                $res->MoveNext();
            }
            $res->Close();
        }

        return ['rows' => $rows];
    }

    /* ───────────── widgets "top" ───────────── */

    private function topHours(array $opts, string $roc): array
    {
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT EXTRACT(HOUR FROM transactionDate)::int as hora, COUNT(transactionId) as total,
                    SUM(transactionUnitsSold) as units
             FROM transaction WHERE transactionType IN (0,3) AND " . SaleFilters::notVoidedSql() . "
             AND transactionDate BETWEEN ? AND ?" . $roc . "
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

    private function topItems(array $opts, string $roc, string $companyId): array
    {
        $rocB = str_replace(['outletId', 'companyId'], ['b.outletId', 'b.companyId'], $roc);
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT a.itemId as id, SUM(a.itemSoldUnits) as count, SUM(a.itemSoldTotal) as total
             FROM itemSold a, transaction b
             WHERE b.transactionType IN (0,3) AND " . SaleFilters::notVoidedSql('b') . "
             AND b.transactionDate BETWEEN ? AND ?" . $rocB . "
             AND a.transactionId = b.transactionId AND a.itemSoldTotal > 0
             GROUP BY a.itemId ORDER BY count DESC LIMIT 5",
            [$from, $to], false, false, true
        );
        $res = is_array($res) ? $res : [];
        $out = [];
        foreach ($res as $r) {
            $item = $this->itemData((string) $r['id'], $companyId);
            $out[] = [
                'name'  => $item ? (string) ($item['itemName'] ?? $item['itemname'] ?? '') : '',
                'count' => (float) $r['count'],
                'total' => (float) $r['total'],
            ];
        }
        return $out;
    }

    private function topTaxonomy(array $opts, string $col, string $emptyLabel, string $roc, string $companyId): array
    {
        $rocC = str_replace(['outletId', 'companyId'], ['c.outletId', 'c.companyId'], $roc);
        [$from, $to] = $this->range($opts);
        $res = ncmExecute(
            "SELECT b.$col as tax, SUM(a.itemSoldUnits) as usold
             FROM itemSold a, item b, transaction c
             WHERE a.itemId = b.itemId AND a.itemSoldDate BETWEEN ? AND ?
             AND c.transactionType IN (0,3) AND " . SaleFilters::notVoidedSql('c') . "
             AND a.transactionId = c.transactionId" . $rocC . "
             GROUP BY b.$col ORDER BY usold DESC LIMIT 10",
            [$from, $to], false, false, true
        );
        $res = is_array($res) ? $res : [];
        $out = [];
        foreach ($res as $r) {
            $name = $this->tname((string) $r['tax'], $companyId);
            $out[] = ['title' => ($name === 'None' || $name === '') ? $emptyLabel : $name, 'total' => round((float) $r['usold'], 2)];
        }
        return $out;
    }

    private function topPayments(array $opts, string $roc): array
    {
        [$from, $to] = $this->range($opts);
        $top = NonAddingSales::salesByPayment($from, $to, $roc);
        usort($top, fn($a, $b) => (float) $b['price'] <=> (float) $a['price']);
        $out = ['title' => [], 'amount' => []];
        foreach ($top as $m) {
            $out['title'][]  = (string) getPaymentMethodName($m['type']);
            $out['amount'][] = (float) $m['price'];
        }
        return $out;
    }

    private function satisfaction(array $opts, string $roc, string $companyId): array
    {
        if (!$this->moduleOn('feedback', $companyId)) {
            return [];
        }
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

    /* ───────────── widgets gateados por módulo ───────────── */

    private function orders(string $roc, string $companyId): array
    {
        if (!$this->moduleOn('ordersPanel', $companyId)) {
            return [];
        }
        // Órdenes reales (pos_order, mig 79/24), no transaction type=12 legacy
        // (mismo defecto que OrdersService::listOrders, ver T5). "Activa" =
        // mismos estados que el POS considera activos — ACTIVE_ORDER_STATUSES
        // en frontend/hooks/use-orders.ts (closed/cancelled quedan afuera).
        $activeStatuses = "'open','sent','in_progress','ready','out_for_delivery','delivered'";
        // onlineCount: origen ecommerce. El enum de `source` (mig 79) incluye
        // 'ecommerce', pero hoy no hay integración que lo produzca — la query
        // queda lista para cuando exista, mientras tanto cuenta 0 en prod.
        return [
            'ordersCount' => (int) $this->scalar("SELECT COUNT(*) as count FROM pos_order WHERE status IN ($activeStatuses)" . $roc),
            'onlineCount' => (int) $this->scalar("SELECT COUNT(*) as count FROM pos_order WHERE status IN ($activeStatuses) AND source = 'ecommerce'" . $roc),
        ];
    }

    private function tables(string $roc, string $companyId): array
    {
        if (!$this->moduleOn('tables', $companyId)) {
            return [];
        }
        // transactionName es VARCHAR (no INT) → comparar con `> 0` falla en PG
        // ("operator does not exist: character varying > integer"). El intent es
        // "tiene nombre asignado" → checkear no-vacío y, si es numérico, > 0.
        $occup = (int) $this->scalar("SELECT COUNT(*) as count FROM transaction WHERE transactionType = 11 AND transactionName IS NOT NULL AND transactionName <> ''" . $roc);
        $m = $this->companyMeta($companyId);
        $totalTables = (int) ($m['tablesCount'] ?? 0) ?: 90;
        return [
            'tablesCount' => $occup,
            'totalTables' => $totalTables,
            'occupacy'    => $totalTables ? round(($occup * 100) / $totalTables) : 0,
            'freeTables'  => $totalTables - $occup,
        ];
    }

    private function schedule(array $opts, string $roc, string $companyId, string $outletId): array
    {
        if (!$this->moduleOn('calendar', $companyId)) {
            return [];
        }
        [$from, $to] = $this->range($opts);
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

        $agendables = (int) $this->scalar(
            "SELECT COUNT(*) as count FROM contact
             WHERE type = 0 AND contactStatus > 0 AND data->>'contactInCalendar' = '1' AND companyId = ?
             AND (outletId = ? OR outletId IS NULL)",
            [$companyId, $outletId]
        );
        $m = $this->companyMeta($companyId);
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

    /* ───────────── widgets gateway (API externa) ───────────── */

    private function notifyGateway(string $endpoint, array $opts, string $companyId, string $outletId, string $userId): array
    {
        $data = [
            'api_key'    => $this->apiKey($companyId),
            'company_id' => self::enc($companyId),
            'user'       => self::enc($userId),
            'type'       => $opts['type'] ?: 'notes',
            'outlet'     => self::enc($outletId),
        ];
        $raw = curlContents(API_URL . '/' . $endpoint, 'POST', $data);
        $res = json_decode((string) $raw, true);
        return is_array($res) ? $res : [];
    }

    /* ───────────── helpers ───────────── */

    private function range(array $opts): array
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

    /** Retorna el ROW de company (CaseInsensitiveArray del wrapper) — NO castear a (array) porque
     *  el cast pierde el lookup case-insensitive y devuelve campos vacíos. */
    private function companyMeta(string $companyId)
    {
        if ($this->meta === null) {
            $row = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [$companyId]);
            $this->meta = ($row && (is_object($row) || is_array($row))) ? $row : [];
        }
        return $this->meta;
    }

    private function moduleOn(string $key, string $companyId): bool
    {
        $m = $this->companyMeta($companyId);
        $v = $m[$key] ?? null;
        return $v && $v !== '0' && $v !== 'false' && $v !== 'null';
    }

    private function apiKey(string $companyId): string
    {
        if (defined('API_KEY')) {
            return API_KEY;
        }
        $m = $this->companyMeta($companyId);
        return sha1((string) ($m['accountId'] ?? ''));
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $row = ncmExecute($sql, $params);
        return $row ? ($row['count'] ?? 0) : 0;
    }

    private function div($a, $b): float
    {
        return ($b == 0) ? 0.0 : ($a / $b);
    }

    /**
     * Port inline de getAllToPayTransactions del panel: SUM(ABS) por transactionParentId.
     * (El global leía `COMPANY_ID` interpolado + cacheaba en la sesión de PHP — incompatible con /api,
     * que es stateless: no hay `session_start()` desde 2026-08-22.)
     *
     * FIX (encontrado 2026-08-16, auditoría del bug latente de anulación de
     * recibos — ver `context/40-anulacion-y-nota-credito.md`): esta query
     * propia (no pasa por `TransactionLinkService::sumDerivedAmounts()`/
     * `mapSumDerivedAmounts()`, que SÍ excluyen `transactionStatus=6` desde su
     * creación) no filtraba anulados — un recibo (type=5) o devolución (type=6)
     * anulado seguía sumando acá para siempre, inflando "Cobrado" en el widget
     * de dashboard. Mismo criterio `COALESCE(transactionStatus, 1) <> 6` que
     * ya usa el resto del codebase para excluir anulados.
     */
    private function payedByParent(string $companyId): array
    {
        // mig 115: transactionParentId dropeada — los vínculos company-wide
        // viven en transaction_link (TransactionLinkService::mapAllDerivedIdsByOrigin).
        $derivedByOrigin = (new \Punto\Api\Services\TransactionLinkService())->mapAllDerivedIdsByOrigin($companyId);
        $allDerived = [];
        foreach ($derivedByOrigin as $ids) {
            foreach ($ids as $d) {
                $allDerived[] = $d;
            }
        }
        if ($allDerived === []) {
            return [];
        }

        $ph  = implode(',', array_fill(0, count($allDerived), '?'));
        $res = ncmExecute(
            "SELECT transactionId, ABS(transactionTotal - COALESCE(transactionDiscount, 0)) as payed
             FROM transaction WHERE transactionType IN (5,6) AND COALESCE(transactionStatus, 1) <> 6
               AND companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $allDerived), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $payedByDerived = [];
        foreach ($res as $r) {
            $payedByDerived[(string) $r['transactionId']] = (float) ($r['payed'] ?? 0);
        }

        $map = [];
        foreach ($derivedByOrigin as $originId => $derivedIds) {
            $sum = 0.0;
            foreach ($derivedIds as $d) {
                $sum += $payedByDerived[$d] ?? 0.0;
            }
            $map[$originId] = abs($sum);
        }
        return $map;
    }

    /** Port inline de getItemData (panel-only): lookup item por id+companyId.
     *  Retorna el CaseInsensitiveArray del wrapper sin cast — el cast pierde campos. */
    private function itemData(string $itemId, string $companyId)
    {
        $r = ncmExecute(
            "SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1",
            [$itemId, $companyId]
        );
        return $r ?: [];
    }

    /**
     * Port inline de getCustomersRate (panel-only). Fix vs el original: el legacy interpola
     * COMPANY_ID en el SQL `companyId = " . COMPANY_ID` sin comillas → roto en PG (UUID).
     * Acá bindeado.
     */
    private function customersRate(string $from, string $to, string $roc, string $companyId): array
    {
        $rocC = str_replace(['companyId', 'outletId'], ['c.companyId', 'c.outletId'], $roc);
        [$backStart, $backEnd] = NonAddingSales::previousPeriod($from, $to);

        $newC = ncmExecute(
            "SELECT COUNT(contactId) as count FROM contact
             WHERE type = 1 AND contactDate BETWEEN ? AND ? AND companyId = ?",
            [$from, $to, $companyId]
        );
        $acquired = $newC ? (int) $newC['count'] : 0;

        $sql = 'SELECT COUNT(c.contactId) as count
                FROM contact c
                WHERE c.contactDate < ?' . $rocC . '
                AND EXISTS (
                    SELECT 1 FROM transaction t
                    WHERE t.transactionDate BETWEEN ? AND ?
                    AND t.transactionType IN (0,3)
                    AND ' . SaleFilters::notVoidedSql('t') . '
                    AND t.customerId = c.contactId
                    -- Correlación de tenant en el EXISTS (P2 de la auditoría de
                    -- auth del 2026-08-26): el `contact` de afuera ya va
                    -- scopeado por $rocC, pero la subconsulta no filtraba
                    -- `transaction` por empresa. Se correlaciona contra `c` en
                    -- vez de bindear otro parámetro: así no hay un segundo
                    -- lugar del que el scope pueda divergir.
                    AND t.companyId = c.companyId
                )';

        $resPast = ncmExecute($sql, [$backStart, $backStart, $backEnd]);
        $customerStart = $resPast ? (int) ($resPast['count'] ?? 0) : 0;

        $resNow = ncmExecute($sql, [$from, $from, $to]);
        $customerEnd = $resNow ? (int) ($resNow['count'] ?? 0) : 0;

        // Réplica fiel del bug histórico: el legacy resetea $acquired = 0 antes de los cálculos.
        $acquired = 0;

        $custGrowth = $customerEnd - $customerStart;
        $growthR    = $this->div($custGrowth, $customerStart) * 100;
        $churn      = $customerEnd - $acquired - $customerStart;
        $churn      = $churn > 0 ? 0 : abs($churn);
        $churnR     = $this->div($churn, $customerStart) * 100;
        $retentionR = $this->div(($customerEnd - $acquired), $customerStart) * 100;

        return [
            'churn_rate'           => round($churnR, 2),
            'churn'                => round($churn, 2),
            'retention_rate'       => round($retentionR - $growthR, 2),
            'customer_growth'      => round($custGrowth, 2),
            'customer_growth_rate' => round($growthR, 2),
            'start_count'          => $customerStart,
            'end_count'            => $customerEnd,
            'new_count'            => $acquired,
        ];
    }

    /**
     * Lookup directo de taxonomyName bindeado por companyId. NO usa el global
     * getTaxonomyName (delega a /app que lee $SQLcompanyId vacío → 'None' silente).
     * Fix preventivo establecido en batch 14 / commit fix 348b4ba.
     */
    private function tname(string $id, string $companyId): string
    {
        if ($id === '') {
            return '';
        }
        if (!array_key_exists($id, $this->taxonomyCache)) {
            $r = ncmExecute(
                'SELECT taxonomyName AS "taxonomyName" FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1',
                [$id, $companyId]
            );
            // Post-refactor 28-jun: array plano lowercase es el contrato base;
            // el alias quoted preserva camelCase. Fallback al lowercase por las
            // dudas si alguna ruta aún devuelve el legacy CaseInsensitiveArray.
            $name = $r ? (string) ($r['taxonomyName'] ?? $r['taxonomyname'] ?? '') : '';
            $this->taxonomyCache[$id] = ($name === '' || $name === 'None') ? '' : toUTF8($name);
        }
        return $this->taxonomyCache[$id];
    }

    /** Port fiel de enc (identity en PG). */
    private static function enc(string $str): string
    {
        return $str;
    }
}
