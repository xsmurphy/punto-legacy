<?php
declare(strict_types=1);

namespace Punto\Api\Billing;

/**
 * BillingService — facturación SaaS del tenant (API compartida, motor ERP).
 *
 * Todas las operaciones scopean por $companyId del JWT: nunca cruza tenants.
 * Usa ncmExecute (función global del namespace raíz) como el resto de los
 * services que usan el global ncm* helper (pattern de SettingsService/DashboardService).
 *
 * IMPORTANTE: NO captura datos de pago (tarjeta, cuenta bancaria, etc.).
 * El flujo es: tenant solicita cambio de plan → admin aprueba/rechaza.
 */
final class BillingService
{
    /**
     * Resumen de facturación del tenant.
     *
     * Retorna:
     *   plan        : { code, name, type, price, durationDays }
     *   usage       : [{ key, label, used, max }] para items/users/outlets/registers/customers
     *   trial       : { isTrial, expiresAt, expired }
     *   balance     : float
     *   smsCredit   : int
     *   aiCredits   : { balance, monthly }
     *   addons      : [{ key, label, qty, unitPrice }]
     *   payments    : últimos 50 pagos de cpayments
     */
    public function summary(string $companyId): array
    {
        // ── 1. Fila de company ───────────────────────────────────────────────
        $co = ncmExecute(
            "SELECT plan, balance, planExpired, isTrial, expiresAt,
                    smsCredit, aiCreditsBalance, config, discount
             FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        if (!$co) {
            return $this->emptyPayload();
        }

        $planCode  = (int)   ($co['plan']              ?? 0);
        $balance   = (float) ($co['balance']           ?? 0);
        $smsCredit = (int)   ($co['smscredit']         ?? $co['smsCredit'] ?? 0);
        $aiBalance = (int)   ($co['aicreditsbalance']  ?? $co['aiCreditsBalance'] ?? 0);
        $isTrial   = (bool)  ($co['istrial']           ?? $co['isTrial'] ?? false);
        $expired   = (bool)  ($co['planexpired']       ?? $co['planExpired'] ?? false);
        $expiresAt = $co['expiresat'] ?? $co['expiresAt'] ?? null;

        // Desempaquetar config JSONB para moduleData (add-ons). Se lee del alias
        // `config_raw`: la columna `config` la aplana y borra el wrapper
        // (Query::flattenJsonb), asi que moduleData quedaba siempre vacio.
        $config = $co['config_raw'] ?? $co['config'] ?? '';
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        if (!is_array($config)) {
            $config = [];
        }
        $moduleData = $config['moduleData'] ?? [];
        if (!is_array($moduleData)) {
            $moduleData = [];
        }

        // ── 2. Plan ──────────────────────────────────────────────────────────
        $planRow = ncmExecute(
            "SELECT name, type, price, duration_days, max_items, max_users,
                    max_customers, max_outlets, max_registers, ai_credits_monthly
             FROM plans WHERE plan_code = ? LIMIT 1",
            [$planCode]
        );

        $planName     = (string) ($planRow['name']               ?? '');
        $planType     = (string) ($planRow['type']               ?? '');
        $planPrice    = (float)  ($planRow['price']              ?? 0);
        $planDuration = (int)    ($planRow['duration_days']      ?? 0);
        $maxItems     = (int)    ($planRow['max_items']          ?? 0);
        $maxUsers     = (int)    ($planRow['max_users']          ?? 0);
        $maxCustomers = (int)    ($planRow['max_customers']      ?? 0);
        $maxOutlets   = (int)    ($planRow['max_outlets']        ?? 0);
        $maxRegisters = (int)    ($planRow['max_registers']      ?? 0);
        $aiMonthly    = (int)    ($planRow['ai_credits_monthly'] ?? 0);

        // ── 3. Conteos de uso (mismo patrón que DashboardService::info) ──────
        $usersRow  = ncmExecute(
            "SELECT COUNT(*) AS n FROM contact WHERE type = 0 AND companyId = ?",
            [$companyId]
        );
        $itemsRow  = ncmExecute(
            "SELECT COUNT(*) AS n FROM item WHERE companyId = ?",
            [$companyId]
        );
        $outletsRow = ncmExecute(
            "SELECT COUNT(*) AS n FROM outlet WHERE companyId = ?",
            [$companyId]
        );
        $registersRow = ncmExecute(
            "SELECT COUNT(*) AS n FROM register WHERE companyId = ?",
            [$companyId]
        );
        $customersRow = ncmExecute(
            "SELECT COUNT(*) AS n FROM contact WHERE type = 1 AND companyId = ?",
            [$companyId]
        );

        $usedItems     = (int) ($itemsRow['n']     ?? 0);
        $usedUsers     = (int) ($usersRow['n']     ?? 0);
        $usedOutlets   = (int) ($outletsRow['n']   ?? 0);
        $usedRegisters = (int) ($registersRow['n'] ?? 0);
        $usedCustomers = (int) ($customersRow['n'] ?? 0);

        // max_users se multiplica por sucursales activas (misma lógica DashboardService).
        $effectiveMaxUsers = $maxUsers * max(1, $usedOutlets);

        // Extra-users/registers/items de add-ons (stored en config.moduleData).
        $extraUsers     = (int) ($moduleData['extraUsers']     ?? 0);
        $extraRegisters = (int) ($moduleData['extraRegisters'] ?? 0);
        $extraItems     = (int) ($moduleData['extraItems']     ?? 0);

        $usage = [
            [
                'key'   => 'items',
                'label' => 'Artículos',
                'used'  => $usedItems,
                'max'   => $maxItems + $extraItems,
            ],
            [
                'key'   => 'users',
                'label' => 'Usuarios',
                'used'  => $usedUsers,
                'max'   => $effectiveMaxUsers + $extraUsers,
            ],
            [
                'key'   => 'outlets',
                'label' => 'Sucursales',
                'used'  => $usedOutlets,
                'max'   => $maxOutlets,
            ],
            [
                'key'   => 'registers',
                'label' => 'Cajas',
                'used'  => $usedRegisters,
                'max'   => $maxRegisters + $extraRegisters,
            ],
            [
                'key'   => 'customers',
                'label' => 'Clientes',
                'used'  => $usedCustomers,
                'max'   => $maxCustomers,
            ],
        ];

        // ── 4. Add-ons ────────────────────────────────────────────────────────
        $addons = [];
        $addonDefs = [
            'extraUsers'     => ['label' => 'Usuarios adicionales',  'unitPrice' => 0],
            'extraRegisters' => ['label' => 'Cajas adicionales',     'unitPrice' => 0],
            'extraItems'     => ['label' => 'Artículos adicionales', 'unitPrice' => 0],
        ];
        foreach ($addonDefs as $key => $def) {
            $qty = (int) ($moduleData[$key] ?? 0);
            if ($qty > 0) {
                $addons[] = [
                    'key'       => $key,
                    'label'     => $def['label'],
                    'qty'       => $qty,
                    'unitPrice' => $def['unitPrice'],
                ];
            }
        }

        // ── 5. Últimos 50 pagos de cpayments ──────────────────────────────────
        $payments = $this->getPayments($companyId);

        return [
            'plan'      => [
                'code'        => $planCode,
                'name'        => $planName,
                'type'        => $planType,
                'price'       => $planPrice,
                'durationDays'=> $planDuration,
            ],
            'usage'     => $usage,
            'trial'     => [
                'isTrial'   => $isTrial,
                'expiresAt' => $expiresAt,
                'expired'   => $expired,
            ],
            'balance'   => $balance,
            'smsCredit' => $smsCredit,
            'aiCredits' => [
                'balance' => $aiBalance,
                'monthly' => $aiMonthly,
            ],
            'addons'    => $addons,
            'payments'  => $payments,
        ];
    }

    /**
     * Todos los planes disponibles para la pantalla de comparación.
     * Incluye plan 0 (free) marcado con isDefault=true.
     */
    public function plans(): array
    {
        $rs = ncmExecute(
            "SELECT plan_code, name, type, price, duration_days,
                    max_items, max_users, max_customers, max_outlets, max_registers,
                    max_suppliers, max_categories, features, ai_credits_monthly
             FROM plans ORDER BY plan_code ASC",
            [],
            false,
            true   // forceObj = true → devuelve RecordSet (iterable)
        );

        $out = [];

        if (!$rs || $rs === false) {
            return $out;
        }

        // Si ncmExecute devuelve RecordSet (forceObj), iteramos.
        // Si devuelve array asociativo (forceObj=false), wrapped en array.
        if (is_object($rs) && method_exists($rs, 'GetRows')) {
            $rows = $rs->GetRows();
        } elseif (is_array($rs)) {
            // ncmExecute sin forceObj devuelve solo la primera fila.
            $rows = [$rs];
        } else {
            return $out;
        }

        foreach ($rows as $r) {
            $features = $r['features'] ?? '{}';
            if (is_string($features)) {
                $features = json_decode($features, true) ?: [];
            }
            $out[] = [
                'code'             => (int)    ($r['plan_code']          ?? 0),
                'name'             => (string) ($r['name']               ?? ''),
                'type'             => (string) ($r['type']               ?? ''),
                'price'            => (float)  ($r['price']              ?? 0),
                'durationDays'     => (int)    ($r['duration_days']      ?? 0),
                'maxItems'         => (int)    ($r['max_items']          ?? 0),
                'maxUsers'         => (int)    ($r['max_users']          ?? 0),
                'maxCustomers'     => (int)    ($r['max_customers']      ?? 0),
                'maxOutlets'       => (int)    ($r['max_outlets']        ?? 0),
                'maxRegisters'     => (int)    ($r['max_registers']      ?? 0),
                'maxSuppliers'     => (int)    ($r['max_suppliers']      ?? 0),
                'maxCategories'    => (int)    ($r['max_categories']     ?? 0),
                'aiCreditsMonthly' => (int)    ($r['ai_credits_monthly'] ?? 0),
                'features'         => is_array($features) ? $features : [],
                'isDefault'        => ((int) ($r['plan_code'] ?? 0)) === 0,
            ];
        }

        return $out;
    }

    /**
     * Últimos 20 movimientos del ledger de créditos IA del tenant.
     */
    public function aiLedger(string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT id, delta, balanceAfter, reason, tokensIn, tokensOut, createdAt
             FROM ai_credit_ledger
             WHERE companyId = ?
             ORDER BY createdAt DESC LIMIT 20",
            [$companyId],
            false,
            true
        );

        $out = [];
        if (!$rs || $rs === false) {
            return $out;
        }

        $rows = is_object($rs) && method_exists($rs, 'GetRows') ? $rs->GetRows() : (is_array($rs) ? [$rs] : []);

        foreach ($rows as $r) {
            $out[] = [
                'id'          => (string) ($r['id']           ?? ''),
                'delta'       => (int)    ($r['delta']        ?? 0),
                'balanceAfter'=> (int)    ($r['balanceafter'] ?? $r['balanceAfter'] ?? 0),
                'reason'      => (string) ($r['reason']       ?? ''),
                'tokensIn'    => (int)    ($r['tokensin']     ?? $r['tokensIn']    ?? 0),
                'tokensOut'   => (int)    ($r['tokensout']    ?? $r['tokensOut']   ?? 0),
                'createdAt'   => $r['createdat'] ?? $r['createdAt'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Solicita un cambio de plan. Inserta un billing_request en estado "pending".
     * NO modifica company.plan — el admin aprueba y resuelve.
     * NO incluye datos de pago (tarjeta, cuenta, etc.).
     *
     * @throws \InvalidArgumentException si el planCode no existe en la tabla plans.
     */
    public function requestPlanChange(string $companyId, int $planCode, ?string $note): array
    {
        // Validar que el plan existe.
        $planExists = ncmExecute(
            "SELECT plan_code, name FROM plans WHERE plan_code = ? LIMIT 1",
            [$planCode]
        );
        if (!$planExists) {
            throw new \InvalidArgumentException("Plan {$planCode} no existe");
        }

        // Leer plan actual del tenant.
        $co = ncmExecute(
            "SELECT plan, config->>'companyName' AS companyName FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $currentPlanCode = (int) ($co['plan'] ?? 0);

        // Insertar solicitud.
        $requestId = $this->generateUuid();
        $noteClean = ($note !== null && $note !== '') ? substr(strip_tags($note), 0, 1000) : null;

        $rs = ncmExecute(
            "INSERT INTO billing_request
               (id, companyId, requestedPlanCode, currentPlanCode, status, note)
             VALUES (?, ?, ?, ?, 'pending', ?)
             RETURNING id",
            [$requestId, $companyId, $planCode, $currentPlanCode, $noteClean]
        );

        $returnedId = $rs['id'] ?? $requestId;

        // Notificación interna (sin datos de pago).
        $this->notifyBillingTeam($companyId, $co['companyname'] ?? $co['companyName'] ?? '', $planCode, $planExists['name'] ?? '');

        return ['ok' => true, 'requestId' => $returnedId];
    }

    // ── privados ────────────────────────────────────────────────────────────

    /** Últimos 50 pagos de cpayments del tenant. */
    private function getPayments(string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT cpaymentsId, cpaymentsDate, cpaymentsAmount,
                    cpaymentsOrder, cpaymentsInvoice, cpaymentsStatus
             FROM cpayments WHERE companyId = ?
             ORDER BY cpaymentsDate DESC LIMIT 50",
            [$companyId],
            false,
            true
        );

        $out = [];
        if (!$rs || $rs === false) {
            return $out;
        }

        $rows = is_object($rs) && method_exists($rs, 'GetRows') ? $rs->GetRows() : (is_array($rs) ? [$rs] : []);

        foreach ($rows as $r) {
            $out[] = [
                'id'      => (string) ($r['cpaymentsid']      ?? ''),
                'date'    => $r['cpaymentsdate']    ?? $r['cpaymentsDate']    ?? null,
                'amount'  => (float)  ($r['cpaymentsamount']  ?? $r['cpaymentsAmount']  ?? 0),
                'order'   => (int)    ($r['cpaymentsorder']   ?? $r['cpaymentsOrder']   ?? 0),
                'invoice' => (int)    ($r['cpaymentsinvoice'] ?? $r['cpaymentsInvoice'] ?? 0),
                'status'  => (int)    ($r['cpaymentsstatus']  ?? $r['cpaymentsStatus']  ?? 0),
            ];
        }

        return $out;
    }

    /** Notificación interna al equipo de Punto (sin datos de pago). */
    private function notifyBillingTeam(string $companyId, string $companyName, int $planCode, string $planName): void
    {
        try {
            $billingEmail = $_ENV['BILLING_EMAIL'] ?? 'billing@punto.la';
            if (function_exists('sendEmails')) {
                sendEmails([
                    'to'      => $billingEmail,
                    'subject' => "Solicitud de cambio de plan — {$companyName}",
                    'body'    => "La empresa \"{$companyName}\" (ID: {$companyId}) ha solicitado cambiar al plan {$planName} (código {$planCode}).\n\nRevisar en el panel de administración.",
                ]);
            }
        } catch (\Throwable $e) {
            // No rompemos la solicitud si el email falla.
        }
    }

    /** Genera un UUID v4. */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Payload vacío si la empresa no existe. */
    private function emptyPayload(): array
    {
        return [
            'plan'      => ['code' => 0, 'name' => '', 'type' => '', 'price' => 0, 'durationDays' => 0],
            'usage'     => [],
            'trial'     => ['isTrial' => false, 'expiresAt' => null, 'expired' => false],
            'balance'   => 0,
            'smsCredit' => 0,
            'aiCredits' => ['balance' => 0, 'monthly' => 0],
            'addons'    => [],
            'payments'  => [],
        ];
    }
}
