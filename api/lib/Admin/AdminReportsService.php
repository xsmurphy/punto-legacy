<?php

/**
 * AdminReportsService.php — agregados cross-tenant para el dashboard y reportes del admin realm.
 *
 * Realm aislado: queries SIN companyId filter (leen TODAS las empresas).
 * Complementa CompanyAdminService (que maneja CRUD empresa a empresa).
 *
 * Expone:
 *   overview()            → KPIs + distribuciones (MRR/ARR, por plan/país, nuevas/mes, top IA)
 *   payments(from, to)    → pagos del período (cpayments JOIN company)
 *
 * Convenciones idénticas a CompanyAdminService:
 *   - $db global (ADOdb), sin inyección
 *   - ADOdb PG devuelve column names en lowercase → usar pick() case-insensitive
 *   - Queries parametrizadas (nunca interpolar user input)
 *   - Aplanar config JSONB inline cuando sea necesario
 */

class AdminReportsService
{
    /**
     * Vista general del sistema para el dashboard del admin.
     *
     * Devuelve:
     *   companies:    { total, active, trial, suspended, cancelled }
     *   mrr:          float  — MRR en unidades del plan.price
     *   arr:          float  — ARR = MRR * 12
     *   newThisMonth: int    — empresas creadas en el mes calendario actual
     *   byPlan:       [{planCode, planName, count}]
     *   byCountry:    [{country, count}]
     *   newPerMonth:  [{month:'YYYY-MM', count}]  — últimos 12 meses
     *   topAiCredits: [{companyId, name, balance}] — top 10 por aiCreditsBalance desc
     */
    public function overview(): array
    {
        global $db;

        // ── Conteos por status ──────────────────────────────────────────────
        $statusRow = $db->Execute(
            "SELECT
               COUNT(*) FILTER (WHERE 1=1)              AS total,
               COUNT(*) FILTER (WHERE status = 'active' AND (blocked IS NULL OR blocked = 0)) AS active,
               COUNT(*) FILTER (WHERE status = 'active' AND planExpired = true)               AS trial,
               COUNT(*) FILTER (WHERE status = 'suspended')                                   AS suspended,
               COUNT(*) FILTER (WHERE status = 'cancelled')                                   AS cancelled
             FROM company"
        );

        $sf = ($statusRow && !$statusRow->EOF) ? $statusRow->fields : [];
        $p  = fn(string $k) => $sf[$k] ?? $sf[strtolower($k)] ?? 0;

        $companies = [
            'total'     => (int) $p('total'),
            'active'    => (int) $p('active'),
            'trial'     => (int) $p('trial'),
            'suspended' => (int) $p('suspended'),
            'cancelled' => (int) $p('cancelled'),
        ];

        // ── MRR: SUM(price) sobre empresas activas JOIN plans ───────────────
        $mrrRow = $db->Execute(
            "SELECT COALESCE(SUM(pl.price), 0) AS mrr
             FROM company c
             JOIN plans pl ON pl.plan_code = c.plan
             WHERE c.status = 'active' AND (c.blocked IS NULL OR c.blocked = 0)"
        );
        $mrr = 0.0;
        if ($mrrRow && !$mrrRow->EOF) {
            $mrr = (float) ($mrrRow->fields['mrr'] ?? 0);
        }
        $arr = round($mrr * 12, 2);

        // ── Nuevas este mes ─────────────────────────────────────────────────
        $newMonthRow = $db->Execute(
            "SELECT COUNT(*) AS n FROM company
             WHERE date_trunc('month', createdAt) = date_trunc('month', now())"
        );
        $newThisMonth = 0;
        if ($newMonthRow && !$newMonthRow->EOF) {
            $newThisMonth = (int) ($newMonthRow->fields['n'] ?? 0);
        }

        // ── Por plan ────────────────────────────────────────────────────────
        $planRows = $db->Execute(
            "SELECT c.plan AS plan_code, pl.name AS plan_name, COUNT(*) AS cnt
             FROM company c
             LEFT JOIN plans pl ON pl.plan_code = c.plan
             GROUP BY c.plan, pl.name
             ORDER BY cnt DESC"
        );
        $byPlan = [];
        if ($planRows) {
            while (!$planRows->EOF) {
                $f = $planRows->fields;
                $byPlan[] = [
                    'planCode' => (int)    ($f['plan_code'] ?? 0),
                    'planName' => (string) ($f['plan_name'] ?? ''),
                    'count'    => (int)    ($f['cnt']       ?? 0),
                ];
                $planRows->MoveNext();
            }
        }

        // ── Por país (config->>'settingCountry') ────────────────────────────
        $countryRows = $db->Execute(
            "SELECT COALESCE(NULLIF(config->>'settingCountry',''), 'Desconocido') AS country,
                    COUNT(*) AS cnt
             FROM company
             GROUP BY country
             ORDER BY cnt DESC
             LIMIT 30"
        );
        $byCountry = [];
        if ($countryRows) {
            while (!$countryRows->EOF) {
                $f = $countryRows->fields;
                $byCountry[] = [
                    'country' => (string) ($f['country'] ?? 'Desconocido'),
                    'count'   => (int)    ($f['cnt']     ?? 0),
                ];
                $countryRows->MoveNext();
            }
        }

        // ── Nuevas por mes (últimos 12 meses) ───────────────────────────────
        $monthRows = $db->Execute(
            "SELECT to_char(date_trunc('month', createdAt), 'YYYY-MM') AS month,
                    COUNT(*) AS cnt
             FROM company
             WHERE createdAt >= date_trunc('month', now()) - INTERVAL '11 months'
             GROUP BY month
             ORDER BY month ASC"
        );
        // Asegurar los 12 meses aunque no haya datos en alguno.
        $rawMonths = [];
        if ($monthRows) {
            while (!$monthRows->EOF) {
                $f = $monthRows->fields;
                $rawMonths[$f['month'] ?? ''] = (int) ($f['cnt'] ?? 0);
                $monthRows->MoveNext();
            }
        }
        $newPerMonth = [];
        for ($i = 11; $i >= 0; $i--) {
            // Usar DateTimeImmutable para evitar wrapping de mes negativo con mktime.
            $dt  = (new \DateTimeImmutable('first day of -' . $i . ' months'))->format('Y-m');
            $newPerMonth[] = [
                'month' => $dt,
                'count' => $rawMonths[$dt] ?? 0,
            ];
        }

        // ── Top 10 por créditos IA ──────────────────────────────────────────
        $aiRows = $db->Execute(
            "SELECT companyId,
                    COALESCE(NULLIF(config->>'settingName',''), config->>'companyName', '') AS name,
                    aiCreditsBalance AS balance
             FROM company
             ORDER BY aiCreditsBalance DESC
             LIMIT 10"
        );
        $topAiCredits = [];
        if ($aiRows) {
            while (!$aiRows->EOF) {
                $f = $aiRows->fields;
                $topAiCredits[] = [
                    'companyId' => (string) ($f['companyid']       ?? $f['companyId']       ?? ''),
                    'name'      => (string) ($f['name']            ?? ''),
                    'balance'   => (int)    ($f['balance']         ?? 0),
                ];
                $aiRows->MoveNext();
            }
        }

        return [
            'companies'    => $companies,
            'mrr'          => round($mrr, 2),
            'arr'          => $arr,
            'newThisMonth' => $newThisMonth,
            'byPlan'       => $byPlan,
            'byCountry'    => $byCountry,
            'newPerMonth'  => $newPerMonth,
            'topAiCredits' => $topAiCredits,
        ];
    }

    /**
     * Pagos del período (cpayments JOIN company).
     *
     * $from / $to: strings ISO fecha ('YYYY-MM-DD'). Si vacíos, últimos 30 días.
     *
     * Devuelve:
     *   total:  float  — suma de montos del período
     *   count:  int    — cantidad de registros
     *   rows:   [{date, amount, invoice, status, companyId, companyName}]
     */
    public function payments(string $from, string $to): array
    {
        global $db;

        // Default: últimos 30 días.
        if ($from === '') {
            $from = date('Y-m-d', strtotime('-30 days'));
        }
        if ($to === '') {
            $to = date('Y-m-d');
        }

        // Sanitize: forzar formato YYYY-MM-DD para evitar inyección SQL.
        // Aunque los usamos en parámetros ligados, validamos el formato como
        // defensa en profundidad antes de pasar al driver.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-30 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }

        // Totales.
        $totRow = $db->Execute(
            "SELECT COALESCE(SUM(cp.cpaymentsAmount), 0) AS total, COUNT(*) AS cnt
             FROM cpayments cp
             WHERE cp.cpaymentsDate::date BETWEEN ? AND ?",
            [$from, $to]
        );
        $total = 0.0;
        $count = 0;
        if ($totRow && !$totRow->EOF) {
            $f     = $totRow->fields;
            $total = (float) ($f['total'] ?? 0);
            $count = (int)   ($f['cnt']   ?? 0);
        }

        // Filas con nombre de empresa.
        $r = $db->Execute(
            "SELECT cp.cpaymentsDate  AS date,
                    cp.cpaymentsAmount AS amount,
                    cp.cpaymentsInvoice AS invoice,
                    cp.cpaymentsStatus  AS status,
                    cp.companyId,
                    COALESCE(NULLIF(c.config->>'settingName',''), c.config->>'companyName', '') AS companyName
             FROM cpayments cp
             JOIN company c ON c.companyId = cp.companyId
             WHERE cp.cpaymentsDate::date BETWEEN ? AND ?
             ORDER BY cp.cpaymentsDate DESC
             LIMIT 500",
            [$from, $to]
        );
        $rows = [];
        if ($r) {
            while (!$r->EOF) {
                $f = $r->fields;
                $rows[] = [
                    'date'        => $f['date']        ?? null,
                    'amount'      => (float)  ($f['amount']      ?? 0),
                    'invoice'     => (int)    ($f['invoice']     ?? 0),
                    'status'      => (int)    ($f['status']      ?? 0),
                    'companyId'   => (string) ($f['companyid']   ?? $f['companyId'] ?? ''),
                    'companyName' => (string) ($f['companyname'] ?? $f['companyName'] ?? ''),
                ];
                $r->MoveNext();
            }
        }

        return [
            'total' => round($total, 2),
            'count' => $count,
            'rows'  => $rows,
        ];
    }
}
