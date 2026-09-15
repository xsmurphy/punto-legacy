<?php

/**
 * PlanAdminService.php — CRUD de planes del catálogo SaaS (realm /admin).
 *
 * UN PLAN SE EDITA EN EL LUGAR (owner 2026-09-15, context/34 §F4 — la regla
 * de versionado de F4 quedó SUPERSEDED):
 *   "Un cambio en el plan aplica a todos los que están atados a ese plan."
 *   Editar cualquier campo —precio incluido— hace UPDATE de la MISMA fila y
 *   el `plan_code` no cambia nunca. Si hace falta un plan distinto para un
 *   segmento de clientes, se crea un plan aparte y se mueve a esos tenants
 *   (ficha del tenant en /admin → "Cambiar plan manualmente").
 *
 *   Por qué se revirtió el versionado: clonaba la fila por CUALQUIER campo y
 *   archivaba la vieja sin mover a nadie, así que los tenants vigentes nunca
 *   recibían el cambio. En producción el Trial se editó para dar 500 créditos
 *   y los tenants siguieron en el Trial archivado con 0 — y `SignupService`
 *   asigna el código 3 fijo, así que los NUEVOS también caían en el archivado.
 *   Contradecía la D1 de §F7 ("el plan manda").
 *
 * QUÉ SIGNIFICA "APLICA A TODOS" (verificado 2026-09-15): todo lector de un
 * campo del plan lo resuelve EN VIVO por `company.plan = plans.plan_code`
 * (límites: UsersService/BillingService/DashboardService/Customer; créditos:
 * PlanLifecycleService::rechargeMonthlyAiCredits; precio: BillingService,
 * OutletRequestService, AdminReportsService). No hay copia en el tenant que
 * re-proyectar. Consecuencias que NO son bugs:
 *   - Precio: vale para lo que se calcule de acá en adelante. Una factura ya
 *     emitida guarda su monto y no se toca.
 *   - `ai_credits_monthly`: la recarga es una por período (`plan_monthly` +
 *     `YYYY-MM`, guard en `grantAiCredits()`). Subirla aplica en el próximo
 *     período no acreditado; no reacredita meses ya acreditados.
 *   - `features`: sigue sin lector (F7 P1 sin implementar). Cuando P1 exista,
 *     guardar un plan tiene que recalcular la proyección de D2
 *     (`company.<key>` + `moduleData`) de TODOS sus tenants por el mismo
 *     camino que el cambio de plan del tenant — no con un UPDATE paralelo.
 *
 * `plans.archived` queda como historial de la época del versionado: nada lo
 * escribe y no filtra nada (un plan archivado que todavía tiene tenants es un
 * plan vivo que los gobierna). La mig 222 consolidó los archivados que tenían
 * un vigente equivalente.
 *
 * El plan código 0 (Free, destino post-trial) se edita como cualquier otro.
 * Su única particularidad es de schema: el índice único de `plan_code` es
 * PARCIAL (`WHERE plan_code != 0`, mig 10), así que el UPDATE va por `id` de
 * la fila leída y no por `plan_code`, para no pisar filas duplicadas del 0.
 *
 * Mismo patrón que CompanyAdminService: `global $db; $db->Execute(...)`,
 * iterando con while(!$r->EOF) — el realm admin está aislado y no carga
 * functions.php/ncmExecute.
 */
class PlanAdminService
{
    private const INT_FIELDS = [
        'duration_days', 'max_items', 'max_users', 'max_customers', 'max_outlets',
        'max_registers', 'max_suppliers', 'max_categories', 'max_brands', 'ai_credits_monthly',
    ];

    /**
     * Lista TODOS los planes con el conteo de tenants en cada plan_code.
     * `archived` viaja como dato histórico, no filtra (ver docblock de la clase).
     */
    public function list(): array
    {
        global $db;

        $sql = "SELECT p.id, p.plan_code, p.name, p.type, p.price, p.duration_days,
                       p.max_items, p.max_users, p.max_customers, p.max_outlets, p.max_registers,
                       p.max_suppliers, p.max_categories, p.max_brands, p.features,
                       p.ai_credits_monthly, p.archived,
                       COALESCE(c.tenants, 0) AS tenants
                FROM plans p
                LEFT JOIN (
                    SELECT plan, COUNT(*) AS tenants FROM company GROUP BY plan
                ) c ON c.plan = p.plan_code
                ORDER BY p.plan_code ASC";

        $r   = $db->Execute($sql);
        $out = [];
        if ($r) {
            while (!$r->EOF) {
                // `$r->fields` es CaseInsensitiveArray, no array — pasarlo
                // crudo a un typehint `array` tira TypeError y dejaba
                // /admin/plans sin listado. Se normaliza con `toArray()`:
                // `ncmRow()` vive en includes/functions.php y el realm admin NO
                // lo carga (es aislado a propósito — ver el docblock de esta
                // clase).
                $out[] = $this->rowToPlan($r->fields->toArray(), true);
                $r->MoveNext();
            }
        }
        return $out;
    }

    public function get(int $planCode): ?array
    {
        $row = $this->getRaw($planCode);
        return $row ? $this->rowToPlan($row) : null;
    }

    /**
     * Crea un plan nuevo (plan_code auto-asignado = MAX(plan_code)+1).
     */
    public function create(array $input): array
    {
        global $db;

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'name es requerido', 'code' => 422];
        }

        $sanitized = $this->sanitize($input, $this->defaults());

        $db->StartTrans();
        $nextCode = $this->nextPlanCode();

        $record               = $sanitized;
        $record['plan_code']  = $nextCode;
        $record['name']       = $name;

        $ok        = $db->Insert('plans', $record);
        $committed = $db->CompleteTrans();
        if (!$ok || !$committed) {
            return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo crear el plan', 'code' => 500];
        }

        return ['ok' => true, 'plan' => $this->get($nextCode)];
    }

    /**
     * Edita el plan EN EL LUGAR. Aplica a todos los tenants con ese plan_code
     * (ver docblock de la clase). Devuelve cuántos son, para que /admin lo diga.
     */
    public function update(int $planCode, array $input): array
    {
        global $db;

        $current = $this->getRaw($planCode);
        if (!$current) {
            return ['ok' => false, 'error' => 'Plan no encontrado', 'code' => 404];
        }

        $sanitized = $this->sanitize($input, $current);
        if (array_key_exists('name', $sanitized) && $sanitized['name'] === '') {
            return ['ok' => false, 'error' => 'name no puede quedar vacío', 'code' => 422];
        }

        if ($sanitized) {
            $ok = $db->AutoExecute('plans', $sanitized, 'UPDATE', 'id = ?', [$current['id']]);
            if (!$ok) {
                return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo guardar el plan', 'code' => 500];
            }
        }

        $plan = $this->get($planCode);
        return ['ok' => true, 'plan' => $plan, 'tenants' => $this->tenantCount($planCode)];
    }

    // ── privados ─────────────────────────────────────────────────────────

    private function getRaw(int $planCode): ?array
    {
        global $db;
        $r = $db->Execute('SELECT * FROM plans WHERE plan_code = ? LIMIT 1', [$planCode]);
        if (!$r || $r->EOF) {
            return null;
        }
        return $r->fields->toArray();
    }

    private function nextPlanCode(): int
    {
        global $db;
        $r = $db->Execute('SELECT COALESCE(MAX(plan_code), 0) AS m FROM plans');
        return ((int) ($r->fields['m'] ?? 0)) + 1;
    }

    private function tenantCount(int $planCode): int
    {
        global $db;
        $r = $db->Execute('SELECT COUNT(*) AS n FROM company WHERE plan = ?', [$planCode]);
        return ($r && !$r->EOF) ? (int) ($r->fields['n'] ?? 0) : 0;
    }

    private function defaults(): array
    {
        return [
            'type' => 'custom', 'price' => 0, 'duration_days' => 30,
            'max_items' => 0, 'max_users' => 0, 'max_customers' => 0, 'max_outlets' => 0,
            'max_registers' => 0, 'max_suppliers' => 0, 'max_categories' => 0, 'max_brands' => 0,
            'features' => '{}', 'ai_credits_monthly' => 0,
        ];
    }

    /**
     * Sanitiza el input del cliente contra $base (fila actual o defaults),
     * devolviendo solo las claves reconocidas + coeridas a su tipo.
     */
    private function sanitize(array $input, array $base): array
    {
        $out = [];

        if (array_key_exists('name', $input)) {
            $out['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('type', $input)) {
            $type = trim((string) $input['type']);
            // Explicit empty-string check — NUNCA `?:` acá: un `type` "0" es
            // falsy en PHP y caería al fallback (mismo footgun que "rubro
            // Otro (valor 0)", fix(settings) 7e2bf85d).
            $out['type'] = $type !== '' ? $type : (string) ($base['type'] ?? 'custom');
        }
        if (array_key_exists('price', $input)) {
            $out['price'] = max(0, (float) $input['price']);
        }
        foreach (self::INT_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $out[$f] = max(0, (int) $input[$f]);
            }
        }
        if (array_key_exists('features', $input)) {
            $features = $input['features'];
            if (is_array($features)) {
                $out['features'] = json_encode($features, JSON_UNESCAPED_UNICODE);
            } elseif (is_string($features)) {
                $decoded = json_decode($features, true);
                $out['features'] = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '{}';
            }
        }

        return $out;
    }

    private function rowToPlan(array $r, bool $withTenants = false): array
    {
        $features = $r['features'] ?? '{}';
        if (is_string($features)) {
            $features = json_decode($features, true) ?: [];
        }

        $out = [
            'id'               => (string) ($r['id'] ?? ''),
            'code'             => (int) ($r['plan_code'] ?? 0),
            'name'             => (string) ($r['name'] ?? ''),
            'type'             => (string) ($r['type'] ?? ''),
            'price'            => (float) ($r['price'] ?? 0),
            'durationDays'     => (int) ($r['duration_days'] ?? 0),
            'maxItems'         => (int) ($r['max_items'] ?? 0),
            'maxUsers'         => (int) ($r['max_users'] ?? 0),
            'maxCustomers'     => (int) ($r['max_customers'] ?? 0),
            'maxOutlets'       => (int) ($r['max_outlets'] ?? 0),
            'maxRegisters'     => (int) ($r['max_registers'] ?? 0),
            'maxSuppliers'     => (int) ($r['max_suppliers'] ?? 0),
            'maxCategories'    => (int) ($r['max_categories'] ?? 0),
            'maxBrands'        => (int) ($r['max_brands'] ?? 0),
            'aiCreditsMonthly' => (int) ($r['ai_credits_monthly'] ?? 0),
            'features'         => is_array($features) ? $features : [],
            'archived'         => ((int) ($r['archived'] ?? 0)) === 1,
            'isDefault'        => ((int) ($r['plan_code'] ?? 0)) === 0,
        ];
        if ($withTenants) {
            $out['tenants'] = (int) ($r['tenants'] ?? 0);
        }
        return $out;
    }
}
