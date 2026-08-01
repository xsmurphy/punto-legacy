<?php
declare(strict_types=1);

namespace Punto\Api\Modules;

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
 */
final class ModulesService
{
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
    ];

    /** Módulos que tienen config adicional (admiten action=config). */
    private const CONFIG_KEYS = ['loyalty', 'tables', 'ordersPanel', 'feedback', 'crm'];

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

        $moduleData = json_decode((string) ($row['moduleData'] ?? ''), true);
        if (!is_array($moduleData)) {
            $moduleData = [];
        }

        $result = [];

        foreach (self::NATIVE_KEYS as $key) {
            // enabled: flat column tiene prioridad (lo que lee el POS);
            // fallback a moduleData[key].status para módulos que solo viven en JSONB.
            $flatVal     = $row[$key] ?? null;
            $moduleEntry = $moduleData[$key] ?? null;

            if ($flatVal !== null) {
                $enabled = $this->truthy($flatVal);
            } elseif (is_array($moduleEntry) && isset($moduleEntry['status'])) {
                $enabled = $this->truthy($moduleEntry['status']);
            } elseif ($moduleEntry !== null && !is_array($moduleEntry)) {
                // moduleData[key] puede ser el valor directo (crm legacy es array con sub-keys)
                $enabled = $this->truthy($moduleEntry);
            } else {
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

        // Actualizar (o crear) el entry para este key
        $moduleData[$key] = ['status' => $value];

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
        }
    }

    /** Normaliza un valor a bool (tolerante a '1', 'true', 'yes', 't', 'on'). */
    private function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return in_array($s, ['1', 't', 'true', 'yes', 'on'], true)
            || (is_numeric($s) && (float) $s > 0);
    }
}
