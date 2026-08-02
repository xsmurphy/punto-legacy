<?php

/**
 * PlanAdminService.php — CRUD de planes del catálogo SaaS (realm /admin).
 *
 * REGLA DE VERSIONADO (cerrada — context/34-admin-saas-plan.md F4, NO retroactiva):
 *   - Metadata cosmética (name) se edita IN-PLACE. No genera un plan nuevo.
 *   - Cualquier cambio de price, duration_days, los límites max_N, features
 *     o ai_credits_monthly es una VERSION NUEVA del plan: se inserta una fila
 *     con plan_code siguiente (MAX(plan_code)+1) y la fila vieja se archiva
 *     (archived=1). `company.plan` de los tenants vigentes NUNCA se toca acá
 *     — siguen operando y facturando con los términos del plan_code viejo
 *     hasta que un admin los mueva explícitamente (companies.php PATCH). Los
 *     planes archivados no aparecen en list() por default — no se ofrecen
 *     para asignar a tenants nuevos.
 *   - El plan default (plan_code=0) es especial: nunca se archiva y nunca
 *     admite cambios de price, duration, límites, features o ai_credits_monthly
 *     (guard explícito abajo) — solo su `name` es editable.
 *
 * Mismo patrón que CompanyAdminService: `global $db; $db->Execute(...)`,
 * iterando con while(!$r->EOF) — el realm admin está aislado y no carga
 * functions.php/ncmExecute.
 */
class PlanAdminService
{
    /** Columnas cuyo cambio dispara versionado (todo excepto `name`). */
    private const VERSIONED_FIELDS = [
        'type', 'price', 'duration_days',
        'max_items', 'max_users', 'max_customers', 'max_outlets', 'max_registers',
        'max_suppliers', 'max_categories', 'max_brands',
        'features', 'ai_credits_monthly',
    ];

    private const INT_FIELDS = [
        'duration_days', 'max_items', 'max_users', 'max_customers', 'max_outlets',
        'max_registers', 'max_suppliers', 'max_categories', 'max_brands', 'ai_credits_monthly',
    ];

    /**
     * Lista de planes con conteo de tenants vigentes en cada plan_code.
     * Por default excluye archivados (usar $includeArchived=true para el
     * listado admin, que muestra ambos con flag).
     */
    public function list(bool $includeArchived = false): array
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
                ) c ON c.plan = p.plan_code";
        if (!$includeArchived) {
            $sql .= ' WHERE p.archived = 0';
        }
        $sql .= ' ORDER BY p.plan_code ASC';

        $r   = $db->Execute($sql);
        $out = [];
        if ($r) {
            while (!$r->EOF) {
                $out[] = $this->rowToPlan($r->fields, true);
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
        $record['archived']   = 0;

        $ok        = $db->Insert('plans', $record);
        $committed = $db->CompleteTrans();
        if (!$ok || !$committed) {
            return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo crear el plan', 'code' => 500];
        }

        return ['ok' => true, 'plan' => $this->get($nextCode), 'versioned' => false];
    }

    /**
     * Aplica la regla de versionado: name-only → UPDATE in-place; cualquier
     * otro campo (price/duration/limits/features/ai_credits_monthly) → crea
     * plan_code nuevo y archiva el viejo.
     */
    public function update(int $planCode, array $input): array
    {
        global $db;

        $current = $this->getRaw($planCode);
        if (!$current) {
            return ['ok' => false, 'error' => 'Plan no encontrado', 'code' => 404];
        }
        if ((int) $current['archived'] === 1) {
            return ['ok' => false, 'error' => 'El plan está archivado — no se puede editar', 'code' => 422];
        }

        $isDefault = $planCode === 0;
        $sanitized = $this->sanitize($input, $current);

        $versionedChanged = false;
        foreach (self::VERSIONED_FIELDS as $f) {
            if (!array_key_exists($f, $sanitized)) {
                continue;
            }
            if ($f === 'features') {
                if (!$this->featuresEqual((string) $sanitized[$f], (string) $current[$f])) {
                    $versionedChanged = true;
                }
            } elseif ($f === 'price') {
                // NUMERIC vuelve de PDO como string ("0.00") — comparar como
                // float, NUNCA como string ("0" !== "0.00" da falso-positivo
                // en CADA edición, code-review 2026-08-01).
                if (abs((float) $sanitized[$f] - (float) $current[$f]) > 0.0001) {
                    $versionedChanged = true;
                }
            } elseif (in_array($f, self::INT_FIELDS, true)) {
                if ((int) $sanitized[$f] !== (int) $current[$f]) {
                    $versionedChanged = true;
                }
            } elseif (trim((string) $sanitized[$f]) !== trim((string) $current[$f])) {
                // 'type' — único VERSIONED_FIELD que queda como string plano.
                $versionedChanged = true;
            }
        }

        if ($versionedChanged && $isDefault) {
            return [
                'ok'    => false,
                'error' => 'El plan default (código 0) no admite cambios de precio/duración/límites/features/créditos IA — solo el nombre es editable',
                'code'  => 422,
            ];
        }

        $newName = array_key_exists('name', $sanitized) ? trim((string) $sanitized['name']) : null;
        if ($newName === '') {
            return ['ok' => false, 'error' => 'name no puede quedar vacío', 'code' => 422];
        }

        if (!$versionedChanged) {
            if ($newName !== null && $newName !== $current['name']) {
                $db->AutoExecute('plans', ['name' => $newName], 'UPDATE', 'plan_code = ?', [$planCode]);
            }
            return ['ok' => true, 'plan' => $this->get($planCode), 'versioned' => false];
        }

        // Versionado: nuevo plan_code con los campos mergeados, archivar el viejo.
        // nextPlanCode() se recalcula DENTRO de la transacción para acotar la
        // ventana de carrera; el índice único plans_plan_code_key (mig 10) es
        // la red de seguridad final — un choque hace fallar el INSERT en vez
        // de duplicar plan_code.
        $merged = array_merge($current, $sanitized);

        $db->StartTrans();
        $nextCode = $this->nextPlanCode();

        $newRecord = [
            'plan_code'          => $nextCode,
            'name'               => $newName ?? $current['name'],
            'type'               => $merged['type'],
            'price'              => $merged['price'],
            'duration_days'      => (int) $merged['duration_days'],
            'max_items'          => (int) $merged['max_items'],
            'max_users'          => (int) $merged['max_users'],
            'max_customers'      => (int) $merged['max_customers'],
            'max_outlets'        => (int) $merged['max_outlets'],
            'max_registers'      => (int) $merged['max_registers'],
            'max_suppliers'      => (int) $merged['max_suppliers'],
            'max_categories'     => (int) $merged['max_categories'],
            'max_brands'         => (int) $merged['max_brands'],
            'features'           => $merged['features'],
            'ai_credits_monthly' => (int) $merged['ai_credits_monthly'],
            'archived'           => 0,
        ];

        $ok1 = $db->Insert('plans', $newRecord);
        $ok2 = $db->AutoExecute('plans', ['archived' => 1], 'UPDATE', 'plan_code = ?', [$planCode]);
        $committed = $db->CompleteTrans();

        if (!$ok1 || !$ok2 || !$committed) {
            return ['ok' => false, 'error' => 'No se pudo versionar el plan', 'code' => 500];
        }

        return [
            'ok'           => true,
            'plan'         => $this->get($nextCode),
            'versioned'    => true,
            'archivedCode' => $planCode,
        ];
    }

    public function archive(int $planCode): array
    {
        if ($planCode === 0) {
            return ['ok' => false, 'error' => 'El plan default (código 0) no se puede archivar', 'code' => 422];
        }

        global $db;
        $current = $this->getRaw($planCode);
        if (!$current) {
            return ['ok' => false, 'error' => 'Plan no encontrado', 'code' => 404];
        }
        if ((int) $current['archived'] === 1) {
            return ['ok' => true, 'alreadyArchived' => true];
        }

        $db->AutoExecute('plans', ['archived' => 1], 'UPDATE', 'plan_code = ?', [$planCode]);
        return ['ok' => true];
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

    /**
     * Compara dos jsonb `features` como VALOR, no como texto — `===` sobre
     * arrays decodificados es order-sensitive (un round-trip del cliente que
     * reordena claves da falso-positivo). Normalizamos con ksort recursivo
     * antes de comparar.
     */
    private function featuresEqual(string $a, string $b): bool
    {
        $da = json_decode($a, true);
        $db_ = json_decode($b, true);
        if (!is_array($da)) {
            $da = [];
        }
        if (!is_array($db_)) {
            $db_ = [];
        }
        $this->ksortRecursive($da);
        $this->ksortRecursive($db_);
        return $da === $db_;
    }

    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
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
