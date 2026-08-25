<?php
declare(strict_types=1);

namespace Punto\Api\Modules;

use Punto\Api\PaymentMethods\PspCatalog;

/**
 * Dominio de Módulos nativos de Punto.
 *
 * Los flags de módulos viven en DOS lugares (compatibilidad POS):
 *   1. `company.<key>` — columna plana (enrutada a company.config JSONB por ncmUpdate).
 *      El POS lee de aquí (app/fetchs.php:448-456).
 *   2. `company.moduleData[key].status` — JSONB anidado. El panel legacy lee de aquí.
 *
 * REGLA CRÍTICA: todo toggle escribe en AMBOS. Ver a_modules.php:34-46.
 *
 * Los módulos "soon" (campaigns, reminder) no tienen llave en el backend —
 * solo existen en el catálogo frontend como placeholders.
 *
 * Nota namespace: funciones globales (ncmExecute, ncmUpdate) resuelven por
 * fallback de PHP — no requieren `use`.
 *
 * KILL-SWITCH (F4, context/34-admin-saas-plan.md): el catálogo comercial de
 * módulos vive en `platform_config` (key='moduleCatalog', jsonb
 * { [moduleKey]: { price, visibility, killswitch } }, ver
 * api/lib/Admin/ModuleAdminService.php). list() es el ÚNICO punto de
 * resolución server-side de "¿está prendido este módulo?" que consume el
 * panel (api/v1/modules.php GET, gating de UI) — por eso el kill-switch se
 * enforcea ACÁ: si killswitch=true, el módulo se reporta enabled=false para
 * TODOS los tenants sin tocar el estado por-tenant (moduleData/columna plana
 * quedan intactos — al soltar el switch, vuelve solo). El realm /admin
 * (CompanyAdminService::listModules, TenantHealthService breadth) reimplementa
 * su propia lectura de company (aislamiento de realm, no puede requerir
 * functions.php) y NO pasa por acá — ver decisión en context/34.
 */
final class ModulesService
{
    /** Cache estática (vida del request PHP) del catálogo comercial de platform_config. */
    private static ?array $moduleCatalogCache = null;
    /**
     * Módulos nativos toggleables. Excluye terceros (spotify, dropbox, mcal,
     * tusfacturas, newton, osWidget, extraUsers) y "soon" (campaigns, reminder).
     */
    private const NATIVE_KEYS = [
        'ecom', 'attendance', 'priceCheck',
        'loyalty', 'feedback', 'crm',
        'calendar', 'tables', 'production', 'kds', 'cds', 'cos', 'ordersPanel',
        'recurring', 'dunning', 'digitalInvoice', 'salesSummaryDaily',
        'einvoicePy',
        'api',
        // Bancard — módulo paraguas de los dos canales de cobro del banco:
        // `qr` (QR de pago, ePagos/BANCARD_QR_API) y `pos` (terminal físico
        // Caja POS Android por LAN). Cada canal se habilita por separado
        // desde la config del módulo; el toggle del módulo los apaga a los
        // dos de una.
        'bancard',
    ];

    /** Módulos que tienen config adicional (admiten action=config). */
    private const CONFIG_KEYS = ['loyalty', 'tables', 'ordersPanel', 'feedback', 'crm', 'bancard'];

    /**
     * Allowlist de módulos nativos toggleables (fuente de verdad única).
     * Usado también por el realm /admin (CompanyAdminService::toggleModule)
     * para validar keys sin duplicar la lista.
     */
    public static function nativeKeys(): array
    {
        return self::NATIVE_KEYS;
    }

    /**
     * Devuelve el mapa de módulos nativos con estado + config por módulo.
     *
     * Shape: { [moduleKey]: { enabled: bool, config?: {...} } }
     */
    public function list($companyId): array
    {
        $row = ncmExecute(
            "SELECT * FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );

        if (!$row) {
            return [];
        }

        $moduleData = ModuleState::moduleData($row);

        $result = [];

        foreach (self::NATIVE_KEYS as $key) {
            // Estado por-tenant: resolver único (ModuleState) — lo comparte el
            // semáforo de salud de /admin, que antes tenía su propia copia.
            $enabled = ModuleState::enabled($row, $key);

            // Kill-switch de plataforma: apaga el módulo para TODOS los tenants
            // sin tocar el estado por-tenant recién calculado arriba.
            if ($enabled && $this->isKilled($key)) {
                $enabled = false;
            }

            $entry = ['enabled' => $enabled];

            // Config por módulo
            switch ($key) {
                case 'loyalty':
                    $loyaltyData = json_decode((string) ($row['loyaltyData'] ?? ''), true);
                    $entry['config'] = [
                        'min'             => (float) ($row['loyaltyMin'] ?? 0),
                        'value'           => (float) ($row['loyaltyValue'] ?? 0),
                        'customerVisible' => $this->truthy($loyaltyData['customerVisible'] ?? null),
                    ];
                    break;

                case 'tables':
                    $entry['config'] = [
                        'count' => (int) ($row['tablesCount'] ?? 0),
                    ];
                    break;

                case 'ordersPanel':
                    $entry['config'] = [
                        'averageTime' => (int) ($row['orderAverageTime'] ?? 60) ?: 60,
                    ];
                    break;

                case 'feedback':
                    $entry['config'] = [
                        'question' => (string) ($row['feedbackQuestion'] ?? ''),
                    ];
                    break;

                case 'crm':
                    $crmData = is_array($moduleData['crm'] ?? null) ? $moduleData['crm'] : [];
                    $entry['config'] = [
                        'dontAutoSendDocs' => $this->truthy($crmData['crmDontAutoSendDocsToCustomer'] ?? null),
                    ];
                    break;

                case 'bancard':
                    // Canales del módulo (qr / pos). Los estados iniciales los
                    // declara ModuleChannels — acá no se repite el default.
                    $bancardData = is_array($moduleData['bancard'] ?? null) ? $moduleData['bancard'] : [];
                    $entry['config'] = ModuleChannels::values('bancard', $bancardData);
                    break;
            }

            $result[$key] = $entry;
        }

        return $result;
    }

    /**
     * Activa/desactiva un módulo nativo. Double-write para compatibilidad POS.
     *
     * @throws \RuntimeException si la key no está en el allowlist.
     */
    public function toggle($companyId, string $key, bool $enabled): void
    {
        if (!in_array($key, self::NATIVE_KEYS, true)) {
            throw new \RuntimeException("Módulo '$key' no reconocido.");
        }

        $value = $enabled ? 1 : 0;

        // 1) Leer el moduleData actual para hacer read-modify-write (preservar otras keys)
        $exists = ncmExecute(
            "SELECT * FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );

        $moduleData = json_decode((string) ($exists['moduleData'] ?? ''), true);
        if (!is_array($moduleData)) {
            $moduleData = [];
        }

        // Actualizar (o crear) el entry para este key PRESERVANDO su config.
        // El entry es un mapa donde `status` convive con las claves de config
        // del módulo (crm guarda crmDontAutoSendDocsToCustomer acá, bancard
        // guarda qr/pos). Pisarlo con ['status' => x] borraba esa config en
        // cada toggle — apagar y prender un módulo reseteaba su configuración.
        $prev = is_array($moduleData[$key] ?? null) ? $moduleData[$key] : [];
        $moduleData[$key] = array_merge($prev, ['status' => $value]);

        // 2) Write moduleData JSONB
        $r1 = ncmUpdate([
            'table'       => 'company',
            'records'     => ['moduleData' => json_encode($moduleData)],
            'where'       => 'companyId = ?',
            'whereParams' => [$companyId],
        ]);

        // 3) Write flat column (lo que lee el POS — enrutado a company.config por ncmUpdate)
        $r2 = ncmUpdate([
            'table'       => 'company',
            'records'     => [$key => $value],
            'where'       => 'companyId = ?',
            'whereParams' => [$companyId],
        ]);

        // Si alguno falla quedaríamos en split-brain (moduleData ≠ columna que
        // lee el POS). Fallamos visible para que el front no muestre OK falso. (P1)
        $ok1 = is_array($r1) && empty($r1['error']);
        $ok2 = is_array($r2) && empty($r2['error']);
        if (!$ok1 || !$ok2) {
            throw new \RuntimeException('No se pudo actualizar el estado del módulo.');
        }

        // Un módulo de pasarela (PSP) con el canal QR activo necesita SU medio
        // de pago para que el POS tenga contra qué registrar el cobro (se
        // dispara por systemKey). Se recorre el catálogo en vez de nombrar
        // 'bancard': cada PSP provisiona su propia fila y así el arqueo puede
        // separarlos — ver PspCatalog.
        if ($enabled) {
            $moduleCfg = is_array($moduleData[$key] ?? null) ? $moduleData[$key] : [];
            foreach (PspCatalog::qrProvidersForModule($key) as $provider => $psp) {
                if (PspCatalog::qrChannelOn($psp, true, $moduleCfg)) {
                    $this->ensurePspPaymentMethod($companyId, $provider, $psp);
                }
            }
        }
    }

    /**
     * Provisiona el medio de pago de una pasarela (idempotente). Best-effort:
     * si falla, el módulo igual queda activo — el POS avisa al cajero que
     * falta el método en vez de romper el toggle.
     */
    private function ensurePspPaymentMethod($companyId, string $provider, array $psp): void
    {
        try {
            global $db;
            (new \Punto\Api\PaymentMethods\PaymentMethodService($db))->ensurePspMethod(
                (string) $companyId,
                (string) $psp['systemKey'],
                (string) $psp['methodName'],
                (string) $psp['code'],
                (string) $psp['color']
            );
        } catch (\Throwable $e) {
            error_log("[modules:$provider] no se pudo provisionar el medio de pago del QR: " . $e->getMessage());
        }
    }

    /**
     * Actualiza la config de un módulo con config-bearing.
     * Solo para: loyalty, tables, ordersPanel, feedback, crm.
     *
     * @throws \RuntimeException si la key no está en CONFIG_KEYS.
     */
    public function updateConfig($companyId, string $key, array $config): void
    {
        if (!in_array($key, self::CONFIG_KEYS, true)) {
            throw new \RuntimeException("El módulo '$key' no tiene configuración editable.");
        }

        // Fila actual de company — necesaria para merges no-destructivos
        // (ej. loyaltyData) y para el read-modify-write de moduleData (crm).
        $exists = ncmExecute(
            "SELECT * FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );

        switch ($key) {
            case 'loyalty':
                $min   = isset($config['min'])   ? (float) $config['min']   : null;
                $value = isset($config['value'])  ? (float) $config['value'] : null;
                $customerVisible = isset($config['customerVisible']) ? (int) (bool) $config['customerVisible'] : 0;

                $record = [];
                if ($min !== null) {
                    $record['loyaltyMin'] = $min;
                }
                if ($value !== null) {
                    $record['loyaltyValue'] = $value;
                }
                // Solo reescribir loyaltyData si el caller mandó al menos un campo
                // de loyalty — sino un config vacío zeroaría min/value/visible
                // existentes (P1 del code-review). Mergeamos con lo guardado para
                // no pisar campos que el caller no envió.
                if ($min !== null || $value !== null || isset($config['customerVisible'])) {
                    $existing = json_decode((string) ($exists['loyaltyData'] ?? ''), true);
                    $existing = is_array($existing) ? $existing : [];
                    $record['loyaltyData'] = json_encode([
                        'customerVisible' => isset($config['customerVisible'])
                            ? $customerVisible
                            : (int) ($this->truthy($existing['customerVisible'] ?? null)),
                        'value' => $value ?? (float) ($existing['value'] ?? 0),
                        'min'   => $min ?? (float) ($existing['min'] ?? 0),
                    ]);
                }
                if (!empty($record)) {
                    ncmUpdate([
                        'table'       => 'company',
                        'records'     => $record,
                        'where'       => 'companyId = ?',
                        'whereParams' => [$companyId],
                    ]);
                }
                break;

            case 'tables':
                $count = isset($config['count']) ? (int) $config['count'] : null;
                if ($count !== null) {
                    ncmUpdate([
                        'table'       => 'company',
                        'records'     => ['tablesCount' => $count],
                        'where'       => 'companyId = ?',
                        'whereParams' => [$companyId],
                    ]);
                }
                break;

            case 'ordersPanel':
                $avg = isset($config['averageTime']) ? (int) $config['averageTime'] : null;
                if ($avg !== null && $avg > 0) {
                    ncmUpdate([
                        'table'       => 'company',
                        'records'     => ['orderAverageTime' => $avg],
                        'where'       => 'companyId = ?',
                        'whereParams' => [$companyId],
                    ]);
                }
                break;

            case 'feedback':
                $question = isset($config['question']) ? (string) $config['question'] : null;
                if ($question !== null) {
                    ncmUpdate([
                        'table'       => 'company',
                        'records'     => ['feedbackQuestion' => $question],
                        'where'       => 'companyId = ?',
                        'whereParams' => [$companyId],
                    ]);
                }
                break;

            case 'crm':
                // crm config vive SOLO en moduleData.crm.crmDontAutoSendDocsToCustomer
                $dontSend = isset($config['dontAutoSendDocs']) ? (bool) $config['dontAutoSendDocs'] : null;
                if ($dontSend !== null) {
                    $exists = ncmExecute(
                        "SELECT moduleData FROM company WHERE companyId = ? LIMIT 1",
                        [$companyId]
                    );
                    $moduleData = json_decode((string) ($exists['moduleData'] ?? ''), true);
                    if (!is_array($moduleData)) {
                        $moduleData = [];
                    }

                    // Preservar el status del módulo crm si ya existía
                    $crmEntry = is_array($moduleData['crm'] ?? null) ? $moduleData['crm'] : [];
                    $crmEntry['crmDontAutoSendDocsToCustomer'] = $dontSend;
                    $moduleData['crm'] = $crmEntry;

                    ncmUpdate([
                        'table'       => 'company',
                        'records'     => ['moduleData' => json_encode($moduleData)],
                        'where'       => 'companyId = ?',
                        'whereParams' => [$companyId],
                    ]);
                }
                break;

            case 'bancard':
                // Canales del módulo (qr / pos), en moduleData.bancard junto al
                // status. Merge no destructivo: `toggle` preserva estas claves.
                $moduleData = json_decode((string) ($exists['moduleData'] ?? ''), true);
                if (!is_array($moduleData)) {
                    $moduleData = [];
                }
                $bancardEntry = is_array($moduleData['bancard'] ?? null) ? $moduleData['bancard'] : [];
                foreach (ModuleChannels::names('bancard') as $channel) {
                    if (array_key_exists($channel, $config)) {
                        $bancardEntry[$channel] = (bool) $config[$channel];
                    }
                }
                $moduleData['bancard'] = $bancardEntry;

                ncmUpdate([
                    'table'       => 'company',
                    'records'     => ['moduleData' => json_encode($moduleData)],
                    'where'       => 'companyId = ?',
                    'whereParams' => [$companyId],
                ]);

                // Mismo resolver de canal que el toggle (PspCatalog): guardar
                // la config con el canal QR prendido provisiona el medio de
                // pago de la pasarela si todavía no existe.
                foreach (PspCatalog::qrProvidersForModule('bancard') as $provider => $psp) {
                    if (PspCatalog::qrChannelOn($psp, true, $bancardEntry)) {
                        $this->ensurePspPaymentMethod($companyId, $provider, $psp);
                    }
                }
                break;
        }
    }

    /** true si el admin activó el kill-switch de plataforma para este módulo. */
    private function isKilled(string $key): bool
    {
        $catalog = $this->loadModuleCatalog();
        return (bool) ($catalog[$key]['killswitch'] ?? false);
    }

    /**
     * Lee `platform_config.moduleCatalog` (jsonb) — cacheada en una estática
     * de clase para no repetir la query dentro del mismo request (list() se
     * puede llamar una vez por request, pero el guard es barato y correcto
     * si en el futuro se llama más de una vez).
     */
    private function loadModuleCatalog(): array
    {
        if (self::$moduleCatalogCache !== null) {
            return self::$moduleCatalogCache;
        }

        $catalog = [];
        try {
            $row = ncmExecute(
                "SELECT value FROM platform_config WHERE key = 'moduleCatalog' LIMIT 1",
                []
            );
            $raw = $row['value'] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $catalog = $decoded;
                }
            }
        } catch (\Throwable $e) {
            // Best-effort DELIBERADO: si platform_config no está disponible,
            // ningún módulo se considera killed (fail-open del kill-switch, no
            // del gating base). Fail-closed acá apagaría TODOS los módulos de
            // TODOS los tenants ante un hiccup de BD — blast radius peor que
            // el riesgo de des-matar un módulo unos minutos. Se loggea fuerte
            // para que el incidente no pase en silencio (decisión review F4).
            error_log('[modules] loadModuleCatalog fallo leyendo platform_config — kill-switches NO aplicados este request: ' . $e->getMessage());
        }

        self::$moduleCatalogCache = $catalog;
        return $catalog;
    }

    /** Atajo local al normalizador único de estado (ver ModuleState::truthy). */
    private function truthy($v): bool
    {
        return ModuleState::truthy($v);
    }
}
