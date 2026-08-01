<?php

/**
 * AdminReportsService.php — agregados cross-tenant para el dashboard y reportes del admin realm.
 *
 * Realm aislado: queries SIN companyId filter (leen TODAS las empresas).
 * Complementa CompanyAdminService (que maneja CRUD empresa a empresa).
 *
 * Expone:
 *   overview()            → KPIs + distribuciones (MRR/ARR, por plan/país, nuevas/mes, top IA,
 *                            saas{} y series{} — F1 del plan admin, ver context/34)
 *   payments(from, to)    → pagos del período (cpayments JOIN company)
 *
 * Convenciones idénticas a CompanyAdminService:
 *   - $db global (wrapper PDO), sin inyección
 *   - PG devuelve column names en lowercase → usar pick() case-insensitive
 *   - Queries parametrizadas (nunca interpolar user input)
 *   - Aplanar config JSONB inline cuando sea necesario
 *
 * F1 — aproximaciones documentadas (context/34-admin-saas-plan.md):
 *   - "Activo/en buen estado comercial" (MRR, saas.tenantsGoodStanding):
 *     MISMO criterio que TenantHealthService::buildCommercialSignal() (F2)
 *     para subscore=100: NOT blocked AND NOT planExpired AND (expiresAt IS
 *     NULL OR expiresAt >= now()) AND status NOT IN ('suspended','cancelled').
 *     Ver commercialGoodStandingWhere().
 *   - "Morosos/vencidos" (saas.tenantsDelinquent) = complemento exacto del
 *     anterior (NOT good standing) — mismo criterio que F2 usa para
 *     commercial subscore=0.
 *   - MRR histórico (series.mrrByMonth) NO tiene historia real de altas/bajas
 *     ni de precio-al-momento: se aproxima con el estado ACTUAL de
 *     company.plan (precio de hoy) aplicado a compañías cuyo createdAt/
 *     expiresAt indican que estaban de alta ese mes (createdAt <= fin de
 *     mes Y (expiresAt IS NULL O expiresAt >= inicio de mes) Y status NOT
 *     IN ('suspended','cancelled')). No refleja cambios de plan/precio
 *     históricos ni bloqueos pasados (esos no tienen timestamp).
 *   - "Bajas por mes" (series.tenantsByMonth[].churned) = tenants cuyo
 *     expiresAt cae en ese mes Y siguen HOY en mal estado comercial (no
 *     renovaron desde entonces). No captura bajas por `blocked` manual sin
 *     expiresAt en ese mes (no hay columna con timestamp de ese evento).
 *   - GMV (series.gmvByMonth) sale de `report_rollup` (domain='sales',
 *     periodType='month', outletId IS NULL = fila consolidada por tenant),
 *     sumado cross-tenant. Ya agregado, sin costo de recorrer `transaction`.
 *   - Créditos IA por mes/capability salen de `ai_credit_ledger.meta->>'capability'`
 *     (delta<0 = consumo). NUNCA usar el operador `?`/`?|`/`?&` de jsonb con
 *     PDO — folleto ->/->> son seguros, están permitidos.
 *   - isInternal (F5) todavía no existe como columna: los WHERE que deberían
 *     excluir tenants internos quedan comentados con el TODO puntual.
 */

class AdminReportsService
{
    /**
     * Fragmento SQL "tenant en buen estado comercial" — mismo criterio que
     * TenantHealthService::buildCommercialSignal() (subscore=100/60, F2):
     * ni bloqueado, ni con plan vencido (flag o fecha), ni suspendido/cancelado.
     * $alias es el alias de la tabla company en el query que lo use.
     */
    private function commercialGoodStandingWhere(string $alias = 'company'): string
    {
        return "($alias.blocked IS NULL OR $alias.blocked = 0)
            AND ($alias.planExpired IS NULL OR $alias.planExpired = false)
            AND ($alias.expiresAt IS NULL OR $alias.expiresAt >= now())
            AND $alias.status NOT IN ('suspended', 'cancelled')";
    }

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

        // ── MRR: SUM(price) sobre empresas en buen estado comercial (F2) ────
        // Criterio idéntico a TenantHealthService::buildCommercialSignal():
        // no basta con status='active' — también excluye planExpired y
        // expiresAt vencido (antes este query solo miraba status/blocked).
        $goodStandingC = $this->commercialGoodStandingWhere('c');
        $mrrRow = $db->Execute(
            "SELECT COALESCE(SUM(pl.price), 0) AS mrr
             FROM company c
             JOIN plans pl ON pl.plan_code = c.plan
             WHERE $goodStandingC"
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

        // ── F1: KPIs SaaS (buen estado comercial / morosos / créditos IA) ────
        $saasRow = $db->Execute(
            "SELECT
               COUNT(*) FILTER (WHERE $goodStandingC)       AS good_standing,
               COUNT(*) FILTER (WHERE NOT ($goodStandingC)) AS delinquent
             FROM company c"
        );
        $tenantsGoodStanding = 0;
        $tenantsDelinquent   = 0;
        if ($saasRow && !$saasRow->EOF) {
            $sf                  = $saasRow->fields;
            $tenantsGoodStanding = (int) ($sf['good_standing'] ?? 0);
            $tenantsDelinquent   = (int) ($sf['delinquent']    ?? 0);
        }

        // Créditos IA consumidos este mes (delta<0 = consumo, ver ai/debit.php).
        $aiMonthRow = $db->Execute(
            "SELECT COALESCE(SUM(-delta), 0) AS n
             FROM ai_credit_ledger
             WHERE delta < 0 AND createdAt >= date_trunc('month', now())"
        );
        $aiCreditsConsumedThisMonth = 0;
        if ($aiMonthRow && !$aiMonthRow->EOF) {
            $aiCreditsConsumedThisMonth = (int) ($aiMonthRow->fields['n'] ?? 0);
        }

        // ── Scaffold de 12 meses (mismo criterio que newPerMonth arriba) ─────
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = (new \DateTimeImmutable('first day of -' . $i . ' months'))->format('Y-m');
        }

        // ── MRR por mes (aproximación v1 — ver docstring de la clase) ────────
        // Precio ACTUAL del plan aplicado a compañías cuyo createdAt/expiresAt
        // indican que estaban de alta ese mes. No hay historia de precio ni
        // de altas/bajas reales, así que es una proyección hacia atrás del
        // estado de hoy, no una serie contable.
        //
        // UNA sola query (antes: 12 round-trips, uno por mes — P1). generate_series
        // produce los 12 meses; LEFT JOIN company reproduce EXACTAMENTE el WHERE
        // que tenía el loop (activa ese mes = createdAt <= fin de mes Y (expiresAt
        // NULL o >= inicio de mes) Y no suspendida/cancelada), movido a condición
        // de JOIN para que los meses sin compañías activas sigan apareciendo con
        // mrr=0 en vez de desaparecer de la serie.
        $mrrRows = $db->Execute(
            "SELECT to_char(gs.month_start, 'YYYY-MM') AS month,
                    COALESCE(SUM(pl.price), 0) AS mrr
             FROM generate_series(
                    date_trunc('month', now()) - INTERVAL '11 months',
                    date_trunc('month', now()),
                    INTERVAL '1 month'
                  ) AS gs(month_start)
             LEFT JOIN company c
               ON c.createdAt <= (gs.month_start + INTERVAL '1 month' - INTERVAL '1 day')
              AND (c.expiresAt IS NULL OR c.expiresAt >= gs.month_start)
              AND c.status NOT IN ('suspended', 'cancelled')
             LEFT JOIN plans pl ON pl.plan_code = c.plan
             GROUP BY gs.month_start
             ORDER BY gs.month_start ASC"
        );
        $rawMrr = [];
        if ($mrrRows) {
            while (!$mrrRows->EOF) {
                $f                       = $mrrRows->fields;
                $rawMrr[$f['month'] ?? ''] = round((float) ($f['mrr'] ?? 0), 2);
                $mrrRows->MoveNext();
            }
        }
        $mrrByMonth = [];
        foreach ($months as $ym) {
            $mrrByMonth[] = [
                'month' => $ym,
                'mrr'   => $rawMrr[$ym] ?? 0.0,
            ];
        }

        // ── Altas vs bajas por mes ─────────────────────────────────────────
        // Altas: reusa $rawMonths (createdAt) ya calculado arriba.
        // Bajas (aproximación v1): expiresAt cae en ese mes Y hoy sigue en
        // mal estado comercial (no renovó desde entonces). No captura bajas
        // por `blocked` manual sin expiresAt en ese mes — no hay columna con
        // timestamp de ese evento (ver docstring de la clase).
        $churnRows = $db->Execute(
            "SELECT to_char(date_trunc('month', expiresAt), 'YYYY-MM') AS month, COUNT(*) AS n
             FROM company
             WHERE expiresAt IS NOT NULL
               AND expiresAt >= date_trunc('month', now()) - INTERVAL '11 months'
               AND expiresAt <  date_trunc('month', now()) + INTERVAL '1 month'
               AND NOT (" . $this->commercialGoodStandingWhere('company') . ")
             GROUP BY month"
        );
        $rawChurn = [];
        if ($churnRows) {
            while (!$churnRows->EOF) {
                $f                          = $churnRows->fields;
                $rawChurn[$f['month'] ?? ''] = (int) ($f['n'] ?? 0);
                $churnRows->MoveNext();
            }
        }
        $tenantsByMonth = [];
        foreach ($months as $ym) {
            $tenantsByMonth[] = [
                'month'   => $ym,
                'new'     => $rawMonths[$ym] ?? 0,
                'churned' => $rawChurn[$ym]  ?? 0,
            ];
        }
        $churnedThisMonth = $rawChurn[$months[count($months) - 1]] ?? 0;

        // ── Consumo IA por mes, desglosado por capability ────────────────────
        // meta->>'capability' — operador flecha jsonb (permitido con PDO).
        // PROHIBIDO el operador `?`/`?|`/`?&`: colisiona con el placeholder.
        $aiRowsByMonth = $db->Execute(
            "SELECT to_char(date_trunc('month', createdAt), 'YYYY-MM') AS month,
                    COALESCE(NULLIF(meta->>'capability', ''), 'sin_capability') AS capability,
                    SUM(-delta) AS credits
             FROM ai_credit_ledger
             WHERE delta < 0
               AND createdAt >= date_trunc('month', now()) - INTERVAL '11 months'
             GROUP BY month, capability"
        );
        $rawAiByMonth     = []; // month => [capability => credits]
        $capabilitiesSeen = [];
        if ($aiRowsByMonth) {
            while (!$aiRowsByMonth->EOF) {
                $f                             = $aiRowsByMonth->fields;
                $m                              = (string) ($f['month'] ?? '');
                $cap                            = (string) ($f['capability'] ?? 'sin_capability');
                $rawAiByMonth[$m][$cap]         = (int) ($f['credits'] ?? 0);
                $capabilitiesSeen[$cap]         = true;
                $aiRowsByMonth->MoveNext();
            }
        }
        $aiCapabilities = array_keys($capabilitiesSeen);
        sort($aiCapabilities);

        $aiCreditsByMonth = [];
        foreach ($months as $ym) {
            $row = ['month' => $ym, 'total' => 0];
            foreach ($aiCapabilities as $cap) {
                $credits    = $rawAiByMonth[$ym][$cap] ?? 0;
                $row[$cap]  = $credits;
                $row['total'] += $credits;
            }
            $aiCreditsByMonth[] = $row;
        }

        // ── GMV agregado por mes (report_rollup, fila consolidada por tenant) ─
        // domain='sales', periodType='month', outletId IS NULL = total del
        // tenant ese mes (ver rollup_recompute_period en mig 41). Ya viene
        // agregado — no hace falta recorrer `transaction`.
        // TODO(F5, isInternal): cuando exista company.isInternal, sumar
        // "AND EXISTS (SELECT 1 FROM company co WHERE co.companyId =
        // report_rollup.companyId AND co.isInternal = false)" para excluir
        // tenants internos del GMV agregado. Hoy no filtra nada.
        $gmvRows = $db->Execute(
            "SELECT to_char(periodStart, 'YYYY-MM') AS month, COALESCE(SUM(total), 0) AS gmv
             FROM report_rollup
             WHERE domain = 'sales' AND periodType = 'month' AND outletId IS NULL
               AND periodStart >= date_trunc('month', now()) - INTERVAL '11 months'
             GROUP BY month"
        );
        $rawGmv = [];
        if ($gmvRows) {
            while (!$gmvRows->EOF) {
                $f                        = $gmvRows->fields;
                $rawGmv[$f['month'] ?? ''] = (float) ($f['gmv'] ?? 0);
                $gmvRows->MoveNext();
            }
        }
        $gmvByMonth = [];
        foreach ($months as $ym) {
            $gmvByMonth[] = [
                'month' => $ym,
                'gmv'   => round($rawGmv[$ym] ?? 0, 2),
            ];
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
            // ── F1: analíticas SaaS (context/34-admin-saas-plan.md) ──────────
            'saas' => [
                'tenantsGoodStanding'        => $tenantsGoodStanding,
                'tenantsTrial'               => $companies['trial'],
                'tenantsDelinquent'          => $tenantsDelinquent,
                'churnedThisMonth'           => $churnedThisMonth,
                'aiCreditsConsumedThisMonth' => $aiCreditsConsumedThisMonth,
            ],
            'series' => [
                'mrrByMonth'       => $mrrByMonth,
                'tenantsByMonth'   => $tenantsByMonth,
                'aiCreditsByMonth' => $aiCreditsByMonth,
                'gmvByMonth'       => $gmvByMonth,
            ],
            'aiCapabilities' => $aiCapabilities,
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
