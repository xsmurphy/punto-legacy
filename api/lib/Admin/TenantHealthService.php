<?php

/**
 * TenantHealthService.php — semáforo de salud/adopción por tenant (F2,
 * ver context/34-admin-saas-plan.md).
 *
 * Calcula un score 0-100 por empresa a partir de 6 dimensiones (activity,
 * breadth, depth, team, ai, commercial), cada una con subscore 0-100 propio
 * + detalle en `signals`. El score global es la suma ponderada de los
 * subscores (pesos en `platform_config.tenantHealthWeights`, editables sin
 * deploy). El semáforo (green/yellow/red) sale de umbrales sobre el score,
 * CON UN OVERRIDE crítico: si el tenant tuvo ventas alguna vez y la última
 * fue hace >14 días, el nivel es 'red' sin importar el score (la recencia
 * mata el promedio — ver plan F2).
 *
 * Cache: tenant_health (1 fila por companyId, snapshot vigente) +
 * tenant_health_history (1 fila por companyId+semana ISO, para la línea
 * histórica). computeAll() recomputa solo tenants con computed_at >6h (o
 * force=true) — batched: cada señal es UNA query agregada (GROUP BY
 * companyid) sobre TODOS los tenants stale, no N queries por tenant.
 *
 * Resolución de "módulos activos": mismo criterio que
 * Punto\Api\Modules\ModulesService::list() — columna plana de `company`
 * (prioridad) con fallback a company.moduleData[key].status. Solo orders
 * (ordersPanel), espacios (tables) y production (production) son módulos
 * toggleables — finance/purchases/ai son features base del producto, sin
 * gate, por eso siempre cuentan como "activos" en la dimensión breadth.
 *
 * Convención de columnas: tablas nuevas del dominio (fin_*, pos_order,
 * space_session, production_order, purchase_draft) usan lowercase sin
 * comillas (companyid). Tablas legacy (company, transaction, contact,
 * outlet, item, taxonomy, ai_credit_ledger, document_template) también
 * fueron creadas sin comillas → companyId se pliega a "companyid" y ambas
 * grafías funcionan. auth_session y printer_binding SÍ fueron creadas con
 * columnas quoted mixed-case ("companyId") — esas queries van con comillas
 * dobles exactas, si no Postgres no encuentra la columna.
 *
 * "commercial" (plan/pagos): se deriva de company.status/blocked/
 * planExpired/expiresAt — son las columnas que BillingService.php ya usa
 * como fuente de verdad del estado de facturación. billing_invoice (dLocal)
 * es un ledger de pagos one-time (packs de créditos), sin dueDate/semántica
 * de suscripción recurrente, así que NO se usa para "vencido/por vencer".
 */
class TenantHealthService
{
    private const DEFAULT_WEIGHTS = [
        'activity'   => 30,
        'breadth'    => 20,
        'depth'      => 15,
        'team'       => 10,
        'ai'         => 10,
        'commercial' => 15,
    ];

    /** key interno del probe => [columna plana en company, key en moduleData JSON]. */
    private const GATED_MODULES = [
        'orders'   => ['flat' => 'orderspanel', 'json' => 'ordersPanel'],
        'espacios' => ['flat' => 'tables',       'json' => 'tables'],
        'production' => ['flat' => 'production', 'json' => 'production'],
    ];

    /** Probes de breadth que son features base del producto (no gateadas). */
    private const UNGATED_MODULES = ['finance', 'purchases', 'ai'];

    private array $weightsCache;

    // ── API pública ──────────────────────────────────────────────────────

    /**
     * Recomputa tenants con cache vencida (>6h) o force=true, persiste, y
     * devuelve el listado resumido para el listado de empresas.
     *
     * Devuelve array de:
     *   ['companyId', 'name', 'score', 'level', 'computedAt', 'topIssue']
     */
    public function computeAll(bool $force = false): array
    {
        $staleIds = $this->staleCompanyIds($force);
        if ($staleIds) {
            $results = $this->computeBatch($staleIds);
            $this->persistBatch($results);
        }
        return $this->summaryList();
    }

    /**
     * Fuerza el recómputo de UNA empresa y devuelve el detalle completo
     * (mismo shape que getDetail).
     */
    public function computeFor(string $companyId): array
    {
        $results = $this->computeBatch([$companyId]);
        if (!isset($results[$companyId])) {
            return ['ok' => false, 'error' => 'Empresa no encontrada'];
        }
        $this->persistBatch($results);
        return $this->getDetail($companyId) ?? ['ok' => false, 'error' => 'Empresa no encontrada'];
    }

    /**
     * Detalle para la ficha de salud de una empresa: signals por dimensión,
     * checklist de adopción, y línea histórica (últimas 12 semanas).
     *
     * Refresca la cache si está vencida (>6h) o ausente antes de leerla.
     */
    public function getDetail(string $companyId): ?array
    {
        global $db;

        $stale = $this->staleCompanyIds(false, [$companyId]);
        if ($stale) {
            $results = $this->computeBatch($stale);
            if ($results) {
                $this->persistBatch($results);
            }
        }

        $row = $db->Execute(
            'SELECT companyid, score, level, signals, computed_at FROM tenant_health WHERE companyid = ? LIMIT 1',
            [$companyId]
        );
        if (!$row || $row->EOF) {
            return null;
        }
        $f = $row->fields;
        $signals = json_decode((string) ($f['signals'] ?? '{}'), true);
        if (!is_array($signals)) {
            $signals = [];
        }
        $checklist = $signals['checklist'] ?? [];
        unset($signals['checklist']);

        $historyRows = [];
        $hr = $db->Execute(
            'SELECT week, score, level FROM tenant_health_history WHERE companyid = ? ORDER BY week DESC LIMIT 12',
            [$companyId]
        );
        if ($hr) {
            while (!$hr->EOF) {
                $hf = $hr->fields;
                $historyRows[] = [
                    'week'  => (string) ($hf['week'] ?? ''),
                    'score' => (int)    ($hf['score'] ?? 0),
                    'level' => (string) ($hf['level'] ?? ''),
                ];
                $hr->MoveNext();
            }
        }
        // Orden cronológico ascendente para el gráfico de línea.
        $historyRows = array_reverse($historyRows);

        return [
            'companyId'  => $companyId,
            'score'      => (int) ($f['score'] ?? 0),
            'level'      => (string) ($f['level'] ?? ''),
            'computedAt' => $f['computed_at'] ?? null,
            'signals'    => $signals,
            'checklist'  => $checklist,
            'history'    => $historyRows,
        ];
    }

    // ── listado resumido ─────────────────────────────────────────────────

    private function summaryList(): array
    {
        global $db;

        $r = $db->Execute(
            "SELECT th.companyid, th.score, th.level, th.computed_at, th.signals,
                    c.config->>'settingName' AS settingname, c.config->>'companyName' AS companyname
               FROM tenant_health th
               JOIN company c ON c.companyId = th.companyid
              ORDER BY th.score ASC"
        );

        $out = [];
        if ($r) {
            while (!$r->EOF) {
                $f = $r->fields;
                $signals = json_decode((string) ($f['signals'] ?? '{}'), true);
                $checklist = is_array($signals) ? ($signals['checklist'] ?? []) : [];
                $topIssue = $checklist[0]['title'] ?? null;

                $out[] = [
                    'companyId'  => (string) ($f['companyid'] ?? ''),
                    'name'       => (string) ($f['settingname'] ?: $f['companyname'] ?: ''),
                    'score'      => (int) ($f['score'] ?? 0),
                    'level'      => (string) ($f['level'] ?? ''),
                    'computedAt' => $f['computed_at'] ?? null,
                    'topIssue'   => $topIssue,
                ];
                $r->MoveNext();
            }
        }
        return $out;
    }

    // ── staleness ────────────────────────────────────────────────────────

    /**
     * IDs de company que necesitan recómputo: sin fila en tenant_health, o
     * computed_at vencida (>6h), o force=true (todas). $onlyIds opcional
     * para acotar el chequeo a un subconjunto (usado por getDetail).
     */
    private function staleCompanyIds(bool $force, ?array $onlyIds = null): array
    {
        global $db;

        $binds = [];
        $extra = '';
        if ($onlyIds) {
            $place = implode(',', array_fill(0, count($onlyIds), '?'));
            $extra = " AND c.companyId IN ($place)";
            $binds = $onlyIds;
        }

        $staleClause = $force ? '1=1' : "(th.companyid IS NULL OR th.computed_at < now() - interval '6 hours')";

        $r = $db->Execute(
            "SELECT c.companyId AS id
               FROM company c
               LEFT JOIN tenant_health th ON th.companyid = c.companyId
              WHERE $staleClause $extra",
            $binds
        );

        $ids = [];
        if ($r) {
            while (!$r->EOF) {
                $ids[] = (string) ($r->fields['id'] ?? '');
                $r->MoveNext();
            }
        }
        return array_values(array_filter($ids));
    }

    // ── persistencia ─────────────────────────────────────────────────────

    private function persistBatch(array $results): void
    {
        global $db;

        foreach ($results as $companyId => $row) {
            $signalsJson = json_encode($row['signals'], JSON_UNESCAPED_UNICODE);

            $db->Execute(
                'INSERT INTO tenant_health (companyid, score, level, signals, computed_at)
                 VALUES (?, ?, ?, ?::jsonb, now())
                 ON CONFLICT (companyid) DO UPDATE SET
                   score = EXCLUDED.score,
                   level = EXCLUDED.level,
                   signals = EXCLUDED.signals,
                   computed_at = now()',
                [$companyId, $row['score'], $row['level'], $signalsJson]
            );

            $db->Execute(
                "INSERT INTO tenant_health_history (companyid, week, score, level, signals)
                 VALUES (?, date_trunc('week', now())::date, ?, ?, ?::jsonb)
                 ON CONFLICT (companyid, week) DO UPDATE SET
                   score = EXCLUDED.score,
                   level = EXCLUDED.level,
                   signals = EXCLUDED.signals",
                [$companyId, $row['score'], $row['level'], $signalsJson]
            );
        }
    }

    // ── cómputo batched ──────────────────────────────────────────────────

    /**
     * Calcula score+level+signals para un lote de companyIds. Cada señal se
     * trae con UNA query agregada (GROUP BY companyid) sobre TODO el lote —
     * evita N+1 (15 queries × tenant).
     */
    private function computeBatch(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $ids = array_values(array_unique($ids));

        $weights       = $this->getWeights();
        $companyRows   = $this->fetchCompanyRows($ids);
        $activity      = $this->fetchActivity($ids);
        $breadthUsage  = $this->fetchBreadthUsage($ids);
        $depth         = $this->fetchDepth($ids);
        $team          = $this->fetchTeam($ids);
        $ai            = $this->fetchAi($ids);

        $out = [];
        foreach ($ids as $companyId) {
            // Company inexistente (borrada, id inválido) — no fabricar un
            // health row con señales en cero. computeFor() depende de esto
            // para devolver 404 en vez de persistir un fantasma.
            if (!isset($companyRows[$companyId])) {
                continue;
            }
            $companyRow = $companyRows[$companyId];

            $activitySig   = $this->buildActivitySignal($activity[$companyId] ?? null);
            $breadthSig    = $this->buildBreadthSignal($companyRow, $breadthUsage[$companyId] ?? []);
            $depthSig      = $this->buildDepthSignal($depth[$companyId] ?? []);
            $teamSig       = $this->buildTeamSignal($team[$companyId] ?? []);
            $aiSig         = $this->buildAiSignal($ai[$companyId] ?? []);
            $commercialSig = $this->buildCommercialSignal($companyRow);

            $score = (int) round(
                $activitySig['subscore']   * $weights['activity']   / 100
                + $breadthSig['subscore']    * $weights['breadth']    / 100
                + $depthSig['subscore']      * $weights['depth']      / 100
                + $teamSig['subscore']       * $weights['team']       / 100
                + $aiSig['subscore']         * $weights['ai']         / 100
                + $commercialSig['subscore'] * $weights['commercial'] / 100
            );
            $score = max(0, min(100, $score));

            $level = $score >= 70 ? 'green' : ($score >= 40 ? 'yellow' : 'red');

            // Override crítico: tenant que vendía y hace >14 días que no
            // vende → rojo sin importar el score (la recencia mata el promedio).
            if ($activitySig['hadSalesEver'] && $activitySig['daysSinceLastSale'] !== null
                && $activitySig['daysSinceLastSale'] > 14) {
                $level = 'red';
            }

            $signals = [
                'activity'   => $activitySig,
                'breadth'    => $breadthSig,
                'depth'      => $depthSig,
                'team'       => $teamSig,
                'ai'         => $aiSig,
                'commercial' => $commercialSig,
            ];
            $signals['checklist'] = $this->buildChecklist($signals);

            $out[$companyId] = [
                'score'   => $score,
                'level'   => $level,
                'signals' => $signals,
            ];
        }

        return $out;
    }

    // ── weights ──────────────────────────────────────────────────────────

    private function getWeights(): array
    {
        if (isset($this->weightsCache)) {
            return $this->weightsCache;
        }
        global $db;

        $weights = self::DEFAULT_WEIGHTS;
        $r = $db->Execute("SELECT value FROM platform_config WHERE key = 'tenantHealthWeights' LIMIT 1");
        if ($r && !$r->EOF) {
            $decoded = json_decode((string) ($r->fields['value'] ?? ''), true);
            if (is_array($decoded)) {
                foreach ($weights as $k => $v) {
                    if (isset($decoded[$k]) && is_numeric($decoded[$k])) {
                        $weights[$k] = (float) $decoded[$k];
                    }
                }
            }
        }
        $this->weightsCache = $weights;
        return $weights;
    }

    // ── fetchers batched ─────────────────────────────────────────────────

    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** Ejecuta $sql y devuelve mapa companyId => fila (keys lowercase). $keyCol es la columna de agrupación. */
    private function mapByCompany($result, string $keyCol = 'companyid'): array
    {
        $out = [];
        if (!$result) {
            return $out;
        }
        while (!$result->EOF) {
            $f = $result->fields;
            $row = (is_object($f) && method_exists($f, 'toArray')) ? $f->toArray() : (array) $f;
            $normalized = [];
            foreach ($row as $k => $v) {
                $normalized[strtolower((string) $k)] = $v;
            }
            $cid = (string) ($normalized[$keyCol] ?? '');
            if ($cid !== '') {
                $out[$cid] = $normalized;
            }
            $result->MoveNext();
        }
        return $out;
    }

    private function fetchCompanyRows(array $ids): array
    {
        global $db;
        $place = $this->placeholders($ids);
        $r = $db->Execute(
            "SELECT companyId, status, blocked, planExpired, expiresAt,
                    orderspanel, tables, production, moduleData
               FROM company WHERE companyId IN ($place)",
            $ids
        );
        return $this->mapByCompany($r, 'companyid');
    }

    private function fetchActivity(array $ids): array
    {
        global $db;
        $place = $this->placeholders($ids);
        $r = $db->Execute(
            "SELECT companyId,
                    MAX(transactionDate) AS lastsaledate,
                    COUNT(*) FILTER (WHERE transactionDate >= now() - interval '30 days') AS sales30,
                    COUNT(*) FILTER (WHERE transactionDate >= now() - interval '60 days'
                                        AND transactionDate <  now() - interval '30 days') AS salesprev30
               FROM transaction
              WHERE companyId IN ($place) AND transactionType IN (0,3,6,7,8) AND transactionStatus <> 6
              GROUP BY companyId",
            $ids
        );
        return $this->mapByCompany($r, 'companyid');
    }

    /**
     * Uso 30d por módulo de breadth. Devuelve companyId => [probeKey => count].
     */
    private function fetchBreadthUsage(array $ids): array
    {
        global $db;
        $place = $this->placeholders($ids);
        $out = array_fill_keys($ids, [
            'orders' => 0, 'espacios' => 0, 'production' => 0,
            'finance' => 0, 'purchases' => 0, 'ai' => 0,
        ]);

        $add = function (string $sql, array $binds, string $probe) use (&$out, $db) {
            $r = $db->Execute($sql, $binds);
            $map = $this->mapByCompany($r, 'companyid');
            foreach ($map as $cid => $row) {
                $out[$cid][$probe] = ($out[$cid][$probe] ?? 0) + (int) ($row['n'] ?? 0);
            }
        };

        $add("SELECT companyid, COUNT(*) AS n FROM pos_order
                WHERE companyid IN ($place) AND created_at >= now() - interval '30 days'
                GROUP BY companyid", $ids, 'orders');

        $add("SELECT companyid, COUNT(*) AS n FROM space_session
                WHERE companyid IN ($place) AND opened_at >= now() - interval '30 days'
                GROUP BY companyid", $ids, 'espacios');

        $add("SELECT companyid, COUNT(*) AS n FROM production_order
                WHERE companyid IN ($place) AND created_at >= now() - interval '30 days'
                GROUP BY companyid", $ids, 'production');

        $add("SELECT companyid, COUNT(*) AS n FROM fin_movement
                WHERE companyid IN ($place) AND source = 'manual' AND created_at >= now() - interval '30 days'
                GROUP BY companyid", $ids, 'finance');
        $add("SELECT companyid, COUNT(*) AS n FROM fin_check
                WHERE companyid IN ($place) AND created_at >= now() - interval '30 days'
                GROUP BY companyid", $ids, 'finance');
        $add("SELECT companyid, COUNT(*) AS n FROM fin_loan
                WHERE companyid IN ($place) AND created_at >= now() - interval '30 days'
                GROUP BY companyid", $ids, 'finance');

        $add("SELECT companyId AS companyid, COUNT(*) AS n FROM transaction
                WHERE companyId IN ($place) AND transactionType = 1 AND transactionStatus <> 6
                  AND transactionDate >= now() - interval '30 days'
                GROUP BY companyId", $ids, 'purchases');
        $add("SELECT companyid, COUNT(*) AS n FROM purchase_draft
                WHERE companyid IN ($place) AND created_at >= now() - interval '30 days'
                GROUP BY companyid", $ids, 'purchases');

        $add("SELECT companyId AS companyid, COUNT(*) AS n FROM ai_credit_ledger
                WHERE companyId IN ($place) AND delta < 0 AND createdAt >= now() - interval '30 days'
                GROUP BY companyId", $ids, 'ai');

        return $out;
    }

    private function fetchDepth(array $ids): array
    {
        global $db;
        $place = $this->placeholders($ids);

        $out = array_fill_keys($ids, [
            'catalogitems' => 0, 'paymentmethodsnoncash' => 0,
            'printerbinding' => 0, 'printtemplate' => 0,
            'usersnonowner' => 0, 'outlets' => 0,
        ]);

        $set = function (string $sql, array $binds, string $field) use (&$out, $db) {
            $r = $db->Execute($sql, $binds);
            $map = $this->mapByCompany($r, 'companyid');
            foreach ($map as $cid => $row) {
                $out[$cid][$field] = (int) ($row['n'] ?? 0);
            }
        };

        $set("SELECT companyId AS companyid, COUNT(*) AS n FROM item
                WHERE companyId IN ($place) AND itemStatus = 1
                GROUP BY companyId", $ids, 'catalogitems');

        $set("SELECT companyId AS companyid, COUNT(*) AS n FROM taxonomy
                WHERE companyId IN ($place) AND taxonomyType = 'paymentMethod' AND taxonomyName NOT ILIKE 'efectivo'
                GROUP BY companyId", $ids, 'paymentmethodsnoncash');

        $set('SELECT "companyId" AS companyid, COUNT(*) AS n FROM "printer_binding"
                WHERE "companyId" IN (' . $place . ')
                GROUP BY "companyId"', $ids, 'printerbinding');

        $set("SELECT companyId AS companyid, COUNT(*) AS n FROM document_template
                WHERE companyId IN ($place)
                GROUP BY companyId", $ids, 'printtemplate');

        $set("SELECT companyId AS companyid, COUNT(*) AS n FROM contact
                WHERE companyId IN ($place) AND type = 0 AND NOT (main = 'true' AND role = '1')
                GROUP BY companyId", $ids, 'usersnonowner');

        $set("SELECT companyId AS companyid, COUNT(*) AS n FROM outlet
                WHERE companyId IN ($place) AND outletStatus = 1
                GROUP BY companyId", $ids, 'outlets');

        return $out;
    }

    private function fetchTeam(array $ids): array
    {
        global $db;
        $place = $this->placeholders($ids);

        $out = array_fill_keys($ids, [
            'totalusers' => 0, 'activeusers14d' => 0,
            'totaldevices' => 0, 'activedevices14d' => 0,
        ]);

        $set = function (string $sql, array $binds, string $field) use (&$out, $db) {
            $r = $db->Execute($sql, $binds);
            $map = $this->mapByCompany($r, 'companyid');
            foreach ($map as $cid => $row) {
                $out[$cid][$field] = (int) ($row['n'] ?? 0);
            }
        };

        $set("SELECT companyId AS companyid, COUNT(*) AS n FROM contact
                WHERE companyId IN ($place) AND type = 0
                GROUP BY companyId", $ids, 'totalusers');

        $set('SELECT "companyId" AS companyid, COUNT(DISTINCT "userId") AS n FROM auth_session
                WHERE "companyId" IN (' . $place . ") AND realm = 'panel' AND status = 1
                  AND \"lastSeenAt\" >= now() - interval '14 days'
                GROUP BY \"companyId\"", $ids, 'activeusers14d');

        $set("SELECT companyid, COUNT(*) AS n FROM device
                WHERE companyid IN ($place) AND status = 1
                GROUP BY companyid", $ids, 'totaldevices');

        $set("SELECT companyid, COUNT(*) AS n FROM device
                WHERE companyid IN ($place) AND status = 1 AND lastSeenAt >= now() - interval '14 days'
                GROUP BY companyid", $ids, 'activedevices14d');

        return $out;
    }

    private function fetchAi(array $ids): array
    {
        global $db;
        $place = $this->placeholders($ids);
        $r = $db->Execute(
            "SELECT companyId AS companyid,
                    SUM(CASE WHEN delta < 0 AND createdAt >= now() - interval '7 days' THEN -delta ELSE 0 END) AS consumed7,
                    SUM(CASE WHEN delta < 0 AND createdAt >= now() - interval '14 days'
                                  AND createdAt < now() - interval '7 days' THEN -delta ELSE 0 END) AS consumedprev7,
                    COUNT(*) FILTER (WHERE delta < 0) AS eversused
               FROM ai_credit_ledger
              WHERE companyId IN ($place)
              GROUP BY companyId",
            $ids
        );
        return $this->mapByCompany($r, 'companyid');
    }

    // ── builders de subscore por dimensión ──────────────────────────────

    private function buildActivitySignal(?array $row): array
    {
        $lastSaleDate = $row['lastsaledate'] ?? null;
        $hadSalesEver = $lastSaleDate !== null;
        $sales30      = (int) ($row['sales30'] ?? 0);
        $salesPrev30  = (int) ($row['salesprev30'] ?? 0);

        $daysSince = null;
        $subscore  = 0;

        if ($hadSalesEver) {
            $daysSince = max(0, (int) floor((time() - strtotime((string) $lastSaleDate)) / 86400));
            $recency = $daysSince >= 30 ? 0 : (int) round(100 - ($daysSince / 30) * 100);

            $trendRatio = $salesPrev30 > 0
                ? $sales30 / $salesPrev30
                : ($sales30 > 0 ? 1.2 : 1.0);
            $trendPenalty = $trendRatio < 1 ? min(20, (1 - $trendRatio) * 20) : 0;

            $subscore = (int) max(0, min(100, round($recency - $trendPenalty)));
        }

        return [
            'subscore'          => $subscore,
            'hadSalesEver'      => $hadSalesEver,
            'daysSinceLastSale' => $daysSince,
            'sales30d'          => $sales30,
            'salesPrev30d'      => $salesPrev30,
        ];
    }

    private function buildBreadthSignal(array $companyRow, array $usage): array
    {
        $modules = [];
        $usedSum = 0;
        $activeCount = 0;

        foreach (self::GATED_MODULES as $probe => $cols) {
            $active = $this->moduleEnabled($companyRow, $cols['flat'], $cols['json']);
            $count  = (int) ($usage[$probe] ?? 0);
            $modules[$probe] = ['active' => $active, 'used' => $active && $count > 0, 'count30d' => $count];
            if ($active) {
                $activeCount++;
                if ($count > 0) {
                    $usedSum++;
                }
            }
        }

        foreach (self::UNGATED_MODULES as $probe) {
            $count = (int) ($usage[$probe] ?? 0);
            $modules[$probe] = ['active' => true, 'used' => $count > 0, 'count30d' => $count];
            $activeCount++;
            if ($count > 0) {
                $usedSum++;
            }
        }

        $subscore = $activeCount > 0 ? (int) round(100 * $usedSum / $activeCount) : 100;

        return ['subscore' => $subscore, 'modules' => $modules];
    }

    private function moduleEnabled(array $companyRow, string $flatKey, string $jsonKey): bool
    {
        $flatVal = $companyRow[strtolower($flatKey)] ?? null;
        if ($flatVal !== null && $flatVal !== '') {
            return $this->truthy($flatVal);
        }

        $moduleData = json_decode((string) ($companyRow['moduledata'] ?? ''), true);
        if (!is_array($moduleData)) {
            return false;
        }
        $entry = $moduleData[$jsonKey] ?? null;
        if (is_array($entry) && isset($entry['status'])) {
            return $this->truthy($entry['status']);
        }
        if ($entry !== null && !is_array($entry)) {
            return $this->truthy($entry);
        }
        return false;
    }

    private function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return in_array($s, ['1', 't', 'true', 'yes', 'on'], true) || (is_numeric($s) && (float) $s > 0);
    }

    private function buildDepthSignal(array $d): array
    {
        $catalogItems  = (int) ($d['catalogitems'] ?? 0);
        $paymentsNC    = (int) ($d['paymentmethodsnoncash'] ?? 0);
        $printerBind   = (int) ($d['printerbinding'] ?? 0);
        $printTemplate = (int) ($d['printtemplate'] ?? 0);
        $usersNonOwner = (int) ($d['usersnonowner'] ?? 0);
        $outlets       = (int) ($d['outlets'] ?? 0);

        $checks = [
            'catalogOver10'       => $catalogItems > 10,
            'paymentMethodsNonCash' => $paymentsNC > 0,
            'printerBinding'      => $printerBind > 0,
            'printTemplate'       => $printTemplate > 0,
            'usersNonOwner'       => $usersNonOwner > 0,
            'multiOutlet'         => $outlets > 1,
        ];
        $passed   = count(array_filter($checks));
        $subscore = (int) round(100 * $passed / count($checks));

        return [
            'subscore'              => $subscore,
            'catalogItems'          => $catalogItems,
            'paymentMethodsNonCash' => $paymentsNC,
            'printerBindings'       => $printerBind,
            'printTemplates'        => $printTemplate,
            'usersNonOwner'         => $usersNonOwner,
            'outlets'               => $outlets,
            'checks'                => $checks,
        ];
    }

    private function buildTeamSignal(array $t): array
    {
        $totalUsers   = (int) ($t['totalusers'] ?? 0);
        $activeUsers  = (int) ($t['activeusers14d'] ?? 0);
        $totalDevices = (int) ($t['totaldevices'] ?? 0);
        $activeDevices = (int) ($t['activedevices14d'] ?? 0);

        $userRatio = $totalUsers > 0 ? min(1, $activeUsers / $totalUsers) : 1.0;
        $deviceComponent = $totalDevices > 0 ? ($activeDevices > 0 ? 1.0 : 0.0) : 1.0;

        $subscore = (int) round(100 * (0.7 * $userRatio + 0.3 * $deviceComponent));

        return [
            'subscore'       => $subscore,
            'totalUsers'     => $totalUsers,
            'activeUsers14d' => $activeUsers,
            'totalDevices'   => $totalDevices,
            'activeDevices14d' => $activeDevices,
        ];
    }

    private function buildAiSignal(array $a): array
    {
        $consumed7     = (float) ($a['consumed7'] ?? 0);
        $consumedPrev7 = (float) ($a['consumedprev7'] ?? 0);
        $everUsed      = (int) ($a['eversused'] ?? 0) > 0;

        if (!$everUsed) {
            $subscore = 50; // neutro — no castiga a quien nunca usó IA.
        } elseif ($consumed7 == 0 && $consumedPrev7 == 0) {
            $subscore = 40; // usó antes, nada en las últimas 2 semanas.
        } elseif ($consumedPrev7 == 0) {
            $subscore = 100; // consumo nuevo/creciente desde cero.
        } else {
            $ratio = $consumed7 / $consumedPrev7;
            $subscore = $ratio >= 1 ? 100 : ($ratio >= 0.5 ? 70 : 30);
        }

        return [
            'subscore'      => $subscore,
            'everUsed'      => $everUsed,
            'consumed7d'    => $consumed7,
            'consumedPrev7d' => $consumedPrev7,
        ];
    }

    private function buildCommercialSignal(array $c): array
    {
        $status      = (string) ($c['status'] ?? '');
        $blocked     = $this->truthy($c['blocked'] ?? 0);
        $planExpired = $this->truthy($c['planexpired'] ?? false);
        $expiresAt   = $c['expiresat'] ?? null;

        $expiredByDate = false;
        $expiringSoon  = false;
        $daysToExpire  = null;
        if ($expiresAt) {
            $daysToExpire = (int) floor((strtotime((string) $expiresAt) - time()) / 86400);
            $expiredByDate = $daysToExpire < 0;
            $expiringSoon  = $daysToExpire >= 0 && $daysToExpire < 7;
        }

        if ($blocked || $planExpired || $expiredByDate || in_array($status, ['suspended', 'cancelled'], true)) {
            $subscore = 0;
        } elseif ($expiringSoon) {
            $subscore = 60;
        } else {
            $subscore = 100;
        }

        return [
            'subscore'     => $subscore,
            'status'       => $status,
            'blocked'      => $blocked,
            'planExpired'  => $planExpired,
            'expiresAt'    => $expiresAt,
            'daysToExpire' => $daysToExpire,
        ];
    }

    // ── checklist de adopción ────────────────────────────────────────────

    private function buildChecklist(array $signals): array
    {
        $items = [];

        $activity = $signals['activity'];
        if ($activity['hadSalesEver'] && $activity['daysSinceLastSale'] !== null && $activity['daysSinceLastSale'] > 14) {
            $items[] = [
                'key'      => 'noSales',
                'title'    => "{$activity['daysSinceLastSale']} días sin ventas",
                'detail'   => 'La recencia de ventas cayó fuerte — contactar con urgencia antes de perder el tenant.',
                'priority' => 'high',
            ];
        } elseif (!$activity['hadSalesEver']) {
            $items[] = [
                'key'      => 'neverSold',
                'title'    => 'Nunca registró una venta',
                'detail'   => 'Tenant sin actividad de venta desde el alta — onboarding pendiente.',
                'priority' => 'high',
            ];
        }

        $moduleCopy = [
            'orders'     => ['title' => 'Tiene Órdenes activo y sin uso en 30 días',   'detail' => '0 órdenes creadas en el último mes — ofrecer ayuda para armar el flujo de pedidos.', 'priority' => 'high'],
            'espacios'   => ['title' => 'Tiene Espacios activo y 0 sesiones en 30 días', 'detail' => 'Ofrecerle ayuda para armar el salón y abrir mesas desde el POS.', 'priority' => 'high'],
            'production' => ['title' => 'Tiene Producción activo sin uso reciente',    'detail' => '0 órdenes de producción en 30 días — revisar si conoce el módulo.', 'priority' => 'medium'],
            'finance'    => ['title' => 'No usa el módulo de Finanzas',                'detail' => '0 movimientos manuales, cheques o créditos en 30 días — capacitar en registro de caja.', 'priority' => 'medium'],
            'purchases'  => ['title' => 'No registra compras en el sistema',           'detail' => '0 compras ni borradores OCR en 30 días.', 'priority' => 'medium'],
            'ai'         => ['title' => 'No usa el agente IA',                         'detail' => '0 mensajes debitados en 30 días — mostrarle casos de uso.', 'priority' => 'low'],
        ];
        foreach ($signals['breadth']['modules'] as $key => $m) {
            if ($m['active'] && !$m['used'] && isset($moduleCopy[$key])) {
                $items[] = array_merge(['key' => "module:$key"], $moduleCopy[$key]);
            }
        }

        $depth = $signals['depth'];
        if ($depth['paymentMethodsNonCash'] === 0) {
            $items[] = ['key' => 'paymentMethods', 'title' => 'No configuró medios de pago ≠ efectivo', 'detail' => 'Solo cobra en efectivo — ofrecer alta de tarjeta/transferencia/QR.', 'priority' => 'medium'];
        }
        if ($depth['printerBindings'] === 0) {
            $items[] = ['key' => 'printer', 'title' => 'Sin impresora configurada', 'detail' => 'No tiene binding de impresora en ninguna caja — revisar si imprime tickets.', 'priority' => 'medium'];
        }
        if ($depth['usersNonOwner'] === 0) {
            $items[] = ['key' => 'singleUser', 'title' => 'Un solo usuario en la cuenta', 'detail' => 'Solo opera el dueño — evaluar si necesita cargar empleados.', 'priority' => 'low'];
        }
        if ($depth['catalogItems'] < 10) {
            $items[] = ['key' => 'catalog', 'title' => 'Catálogo con menos de 10 ítems', 'detail' => "Cargó solo {$depth['catalogItems']} ítems activos — puede estar sub-explotando el catálogo.", 'priority' => 'medium'];
        }
        if ($depth['printTemplates'] === 0) {
            $items[] = ['key' => 'template', 'title' => 'Sin plantilla de impresión propia', 'detail' => 'Usa la plantilla default — ofrecer personalización de marca.', 'priority' => 'low'];
        }

        $team = $signals['team'];
        if ($team['totalUsers'] > 0 && $team['activeUsers14d'] === 0) {
            $items[] = ['key' => 'inactiveTeam', 'title' => 'Nadie inició sesión en 14 días', 'detail' => 'Ningún usuario activo recientemente — riesgo de abandono.', 'priority' => 'high'];
        }

        $commercial = $signals['commercial'];
        if ($commercial['subscore'] === 0) {
            $items[] = ['key' => 'billing', 'title' => 'Plan vencido o cuenta bloqueada', 'detail' => 'Contactar para regularizar el pago antes de perder el tenant.', 'priority' => 'high'];
        } elseif ($commercial['subscore'] === 60) {
            $items[] = ['key' => 'billingSoon', 'title' => 'Plan por vencer en menos de 7 días', 'detail' => 'Avisar antes del vencimiento para evitar corte de servicio.', 'priority' => 'medium'];
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($items, fn($a, $b) => ($order[$a['priority']] ?? 3) <=> ($order[$b['priority']] ?? 3));

        return $items;
    }
}
