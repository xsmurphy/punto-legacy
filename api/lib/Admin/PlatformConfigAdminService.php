<?php

require_once __DIR__ . '/PlatformConfig.php';

/**
 * PlatformConfigAdminService.php — CRUD de `platform_config` para la página
 * admin/platform (F6 §3, context/34-admin-saas-plan.md). Solo rol 'owner'
 * (gateado en el endpoint, no acá).
 *
 * Whitelist cerrada de grupos (self::GROUPS) — NO se puede leer/escribir una
 * key arbitraria desde la UI. Cada grupo es UNA fila de platform_config
 * (value jsonb = objeto con sus campos), tal como pide el plan ("keys tipo
 * `integration.evolution` con value jsonb").
 *
 * Secretos: el GET enmascara (••••+últimos 4 chars); el POST solo pisa un
 * campo si el valor mandado NO es la máscara (así un submit que no tocó el
 * secreto no lo borra). Nunca se expone el valor real de un secreto por GET.
 *
 * Secretos de bootstrap (DB/Redis/S3/JWT/workers) NO viven acá — ya están
 * fuera de esta whitelist por diseño (regla cerrada del plan).
 */
final class PlatformConfigAdminService
{
    /** Prefijo que marca un valor como "no tocado" (viene de una máscara del GET). */
    private const MASK_PREFIX = '••••';

    /**
     * Whitelist de grupos editables. 'env' es la constante de simple.config.php
     * usada como fallback cuando platform_config no tiene la key (para mostrar
     * "de dónde sale" el valor efectivo en la UI).
     */
    private const GROUPS = [
        'integration.evolution' => [
            'label'  => 'WhatsApp / Evolution API',
            'fields' => [
                'url'      => ['label' => 'API URL',   'secret' => false, 'env' => 'EVOLUTION_API_URL'],
                'instance' => ['label' => 'Instancia',  'secret' => false, 'env' => 'EVOLUTION_INSTANCE'],
                'key'      => ['label' => 'API Key',    'secret' => true,  'env' => 'EVOLUTION_API_KEY'],
            ],
        ],
        'integration.mailgun' => [
            'label'  => 'Mailgun',
            'fields' => [
                'domain' => ['label' => 'Dominio',    'secret' => false, 'env' => 'MAILGUN_DOMAIN'],
                'token'  => ['label' => 'API Token',  'secret' => true,  'env' => 'MAILGUN_TOKEN'],
            ],
        ],
        'integration.dlocal' => [
            'label'  => 'dLocal Go (config solamente — NO cableado aún, ver plan)',
            'fields' => [
                'environment'   => ['label' => 'Entorno (sandbox/production)', 'secret' => false, 'env' => 'DLOCAL_GO_ENVIRONMENT'],
                'baseUrl'       => ['label' => 'Base URL',                     'secret' => false, 'env' => 'DLOCAL_GO_BASE_URL'],
                'apiKey'        => ['label' => 'API Key',                      'secret' => true,  'env' => 'DLOCAL_GO_API_KEY'],
                'secretKey'     => ['label' => 'Secret Key',                   'secret' => true,  'env' => 'DLOCAL_GO_SECRET_KEY'],
                'webhookSecret' => ['label' => 'Webhook Secret',               'secret' => true,  'env' => 'DLOCAL_GO_WEBHOOK_SECRET'],
            ],
        ],
        'flags' => [
            'label'  => 'Flags de negocio',
            'fields' => [
                'signupOtp' => ['label' => 'OTP de registro (on/off)', 'secret' => false, 'env' => 'SIGNUP_OTP'],
            ],
        ],
    ];

    /** Lista todos los grupos con sus campos (valores reales, secretos enmascarados). */
    public function listGroups(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => $meta) {
            $out[] = $this->shapeGroup($key, $meta);
        }
        return $out;
    }

    /**
     * Guarda un grupo. $incoming: [field => value]. Campos secretos con valor
     * enmascarado (empieza con ••••) se ignoran (se conserva lo guardado).
     * Campos fuera de la whitelist del grupo se ignoran silenciosamente.
     */
    public function setGroup(string $key, array $incoming, ?string $updatedBy): array
    {
        if (!array_key_exists($key, self::GROUPS)) {
            return ['ok' => false, 'error' => 'Grupo desconocido', 'code' => 404];
        }
        $meta    = self::GROUPS[$key];
        $current = PlatformConfig::get($key, []);
        if (!is_array($current)) {
            $current = [];
        }
        $merged = $current;

        foreach ($meta['fields'] as $field => $fieldMeta) {
            if (!array_key_exists($field, $incoming)) {
                continue;
            }
            $value = $incoming[$field];
            if ($fieldMeta['secret'] && is_string($value) && str_starts_with($value, self::MASK_PREFIX)) {
                continue; // no tocado — conserva lo que ya había
            }
            $merged[$field] = is_bool($value) ? $value : (string) $value;
        }

        PlatformConfig::set($key, $merged, $updatedBy);
        return ['ok' => true, 'group' => $this->shapeGroup($key, $meta)];
    }

    /**
     * Broadcast a tenants: inserta UNA fila global en `notify` (companyId
     * NULL, outletId NULL) — tanto el feed del panel (FeedService) como el
     * legacy POS (NotificationService) ya tratan companyId/outletId NULL
     * como "para todos", sin cambios de schema ni iterar tenants.
     */
    public function broadcast(string $title, string $message, ?string $link): array
    {
        global $db;
        $title   = trim($title);
        $message = trim($message);
        $link    = $link !== null ? trim($link) : null;

        if ($title === '') {
            return ['ok' => false, 'error' => 'title es requerido', 'code' => 422];
        }
        if ($message === '') {
            return ['ok' => false, 'error' => 'message es requerido', 'code' => 422];
        }

        $ok = $db->Insert('notify', [
            'notifyTitle'   => $title,
            'notifyMessage' => $message,
            'notifyDate'    => date('Y-m-d H:i:s'),
            'notifyLink'    => $link !== '' ? $link : null,
            'notifyType'    => 'broadcast',
            'notifyMode'    => 0,
            'notifyStatus'  => 1,
            'registerId'    => null,
            'outletId'      => null,
            'companyId'     => null,
        ]);
        if (!$ok) {
            return ['ok' => false, 'error' => $db->ErrorMsg() ?: 'No se pudo crear el aviso', 'code' => 500];
        }
        return ['ok' => true, 'notifyId' => $db->Insert_ID()];
    }

    // ── F5: tenant emisor de facturación SaaS (dogfooding) ─────────────────
    // NO es un grupo de self::GROUPS (esos son campos de texto genéricos):
    // este config tiene selects encadenados tenant→outlet→register y un
    // efecto colateral (marcar `company.isinternal`), así que vive en
    // métodos dedicados. Ver context/34-admin-saas-plan.md F5.

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Config actual + nombres resueltos (para pintar los selects ya seleccionados). */
    public function getSaasBillingConfig(): array
    {
        global $db;

        $cfg = PlatformConfig::get('saasBilling', []);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $tenantId   = !empty($cfg['tenantId'])   ? (string) $cfg['tenantId']   : null;
        $outletId   = !empty($cfg['outletId'])   ? (string) $cfg['outletId']   : null;
        $registerId = !empty($cfg['registerId']) ? (string) $cfg['registerId'] : null;

        $tenantName = null;
        if ($tenantId !== null) {
            $r = $db->Execute(
                "SELECT COALESCE(NULLIF(config->>'settingName',''), config->>'companyName', '') AS name
                   FROM company WHERE companyId = ? LIMIT 1",
                [$tenantId]
            );
            $tenantName = ($r && !$r->EOF) ? (string) ($r->fields['name'] ?? '') : null;
        }
        $outletName = null;
        if ($outletId !== null) {
            $r = $db->Execute('SELECT outletName FROM outlet WHERE outletId = ? LIMIT 1', [$outletId]);
            $outletName = ($r && !$r->EOF) ? (string) ($r->fields['outletname'] ?? $r->fields['outletName'] ?? '') : null;
        }
        $registerName = null;
        if ($registerId !== null) {
            $r = $db->Execute('SELECT registerName FROM register WHERE registerId = ? LIMIT 1', [$registerId]);
            $registerName = ($r && !$r->EOF) ? (string) ($r->fields['registername'] ?? $r->fields['registerName'] ?? '') : null;
        }

        return [
            'tenantId'     => $tenantId,
            'tenantName'   => $tenantName,
            'outletId'     => $outletId,
            'outletName'   => $outletName,
            'registerId'   => $registerId,
            'registerName' => $registerName,
            'enabled'      => (bool) ($cfg['enabled'] ?? false),
        ];
    }

    /**
     * Guarda tenant/outlet/register emisor + enabled. Efecto colateral: marca
     * `company.isinternal=1` en el tenant nuevo y `=0` en el anterior (si
     * cambió) — atómico, misma transacción que el guardado de config.
     */
    public function setSaasBillingConfig(array $in, ?string $updatedBy): array
    {
        global $db;

        $tenantId   = trim((string) ($in['tenantId']   ?? ''));
        $outletId   = trim((string) ($in['outletId']   ?? ''));
        $registerId = trim((string) ($in['registerId'] ?? ''));
        $enabled    = filter_var($in['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($enabled) {
            if ($tenantId === '' || !preg_match(self::UUID_RE, $tenantId)) {
                return ['ok' => false, 'error' => 'Elegí el tenant emisor', 'code' => 422];
            }
            if ($outletId === '' || !preg_match(self::UUID_RE, $outletId)) {
                return ['ok' => false, 'error' => 'Elegí la sucursal emisora', 'code' => 422];
            }
            if ($registerId === '' || !preg_match(self::UUID_RE, $registerId)) {
                return ['ok' => false, 'error' => 'Elegí la caja emisora', 'code' => 422];
            }
        }

        if ($tenantId !== '') {
            $t = $db->Execute('SELECT companyId FROM company WHERE companyId = ? LIMIT 1', [$tenantId]);
            if (!$t || $t->EOF) {
                return ['ok' => false, 'error' => 'Tenant no encontrado', 'code' => 404];
            }
        }
        if ($outletId !== '') {
            $o = $db->Execute('SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1', [$outletId, $tenantId]);
            if (!$o || $o->EOF) {
                return ['ok' => false, 'error' => 'La sucursal no pertenece al tenant elegido', 'code' => 422];
            }
        }
        if ($registerId !== '') {
            $r = $db->Execute(
                'SELECT registerId FROM register WHERE registerId = ? AND companyId = ? AND outletId = ? LIMIT 1',
                [$registerId, $tenantId, $outletId]
            );
            if (!$r || $r->EOF) {
                return ['ok' => false, 'error' => 'La caja no pertenece a la sucursal elegida', 'code' => 422];
            }
        }

        $prev = PlatformConfig::get('saasBilling', []);
        $prevTenantId = (is_array($prev) && !empty($prev['tenantId'])) ? (string) $prev['tenantId'] : '';

        $db->BeginTrans();
        try {
            if ($prevTenantId !== '' && $prevTenantId !== $tenantId) {
                $db->Execute('UPDATE company SET isinternal = 0 WHERE companyId = ?', [$prevTenantId]);
            }
            if ($tenantId !== '') {
                $db->Execute('UPDATE company SET isinternal = 1 WHERE companyId = ?', [$tenantId]);
            }
            PlatformConfig::set('saasBilling', [
                'tenantId'   => $tenantId !== ''   ? $tenantId   : null,
                'outletId'   => $outletId !== ''   ? $outletId   : null,
                'registerId' => $registerId !== '' ? $registerId : null,
                'enabled'    => $enabled,
            ], $updatedBy);
            $db->CommitTrans();
        } catch (\Throwable $e) {
            $db->RollbackTrans();
            return ['ok' => false, 'error' => 'No se pudo guardar: ' . $e->getMessage(), 'code' => 500];
        }

        return ['ok' => true, 'config' => $this->getSaasBillingConfig()];
    }

    // ── privados ─────────────────────────────────────────────────────────

    private function shapeGroup(string $key, array $meta): array
    {
        $stored = PlatformConfig::get($key, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $fields = [];
        foreach ($meta['fields'] as $field => $fieldMeta) {
            $envVal = defined($fieldMeta['env']) ? (string) constant($fieldMeta['env']) : '';
            $stored_val = array_key_exists($field, $stored) ? (string) $stored[$field] : null;
            $effective = $stored_val !== null && $stored_val !== '' ? $stored_val : $envVal;
            $source    = $stored_val !== null && $stored_val !== '' ? 'config' : ($envVal !== '' ? 'env' : 'none');

            $fields[$field] = [
                'label'  => $fieldMeta['label'],
                'secret' => $fieldMeta['secret'],
                'source' => $source, // 'config' | 'env' | 'none' — de dónde sale el valor efectivo
                'value'  => $fieldMeta['secret'] ? $this->mask($effective) : $effective,
            ];
        }

        return ['key' => $key, 'label' => $meta['label'], 'fields' => $fields];
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $tail = strlen($value) >= 4 ? substr($value, -4) : $value;
        return self::MASK_PREFIX . $tail;
    }
}
