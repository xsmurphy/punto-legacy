<?php

require_once __DIR__ . '/../Modules/ModulesService.php';

/**
 * ModuleAdminService.php — catálogo comercial de módulos (realm /admin).
 *
 * Los módulos son code-defined: la lista de keys reales vive en
 * `\Punto\Api\Modules\ModulesService::nativeKeys()` (backend) y en
 * `frontend/lib/modules-catalog.ts` (metadata de producto: título, ícono,
 * descripción). La metadata COMERCIAL (precio suelto, visibilidad, kill-switch)
 * vive acá, en `platform_config` con key='moduleCatalog', jsonb:
 *   { [moduleKey]: { price: number, visibility: 'ga'|'beta'|'hidden', killswitch: bool } }
 *
 * GET  → merge de las keys reales (ModulesService::nativeKeys()) + su metadata
 *        comercial (defaults si el módulo todavía no tiene entry).
 * POST → upsert de UN módulo (key + price/visibility/killswitch).
 *
 * ENFORCEMENT del kill-switch: NO pasa por acá — vive en
 * ModulesService::list() (ver docblock ahí), que es el único consumidor real
 * de "¿está prendido este módulo?" para el panel tenant. Esta clase solo
 * lee/escribe la metadata; no gatea nada.
 *
 * Mismo patrón que CompanyAdminService/PlanAdminService: `global $db;
 * $db->Execute(...)`, sin ncmExecute (realm admin aislado).
 */
class ModuleAdminService
{
    private const VISIBILITIES = ['ga', 'beta', 'hidden'];

    /** Merge de keys reales + metadata comercial (defaults si no hay entry). */
    public function list(): array
    {
        $catalog = $this->loadCatalog();
        $out     = [];

        foreach (\Punto\Api\Modules\ModulesService::nativeKeys() as $key) {
            $entry = $catalog[$key] ?? [];
            $out[] = [
                'key'        => $key,
                'price'      => (float) ($entry['price'] ?? 0),
                'visibility' => in_array($entry['visibility'] ?? 'ga', self::VISIBILITIES, true)
                    ? $entry['visibility']
                    : 'ga',
                'killswitch' => (bool) ($entry['killswitch'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Upsert de un módulo. $key debe estar en ModulesService::nativeKeys().
     */
    public function update(string $key, array $input): array
    {
        if (!in_array($key, \Punto\Api\Modules\ModulesService::nativeKeys(), true)) {
            return ['ok' => false, 'error' => "Módulo '{$key}' no reconocido", 'code' => 422];
        }

        $catalog = $this->loadCatalog();
        $current = $catalog[$key] ?? ['price' => 0, 'visibility' => 'ga', 'killswitch' => false];

        if (array_key_exists('price', $input)) {
            $current['price'] = max(0, (float) $input['price']);
        }
        if (array_key_exists('visibility', $input)) {
            $vis = (string) $input['visibility'];
            if (!in_array($vis, self::VISIBILITIES, true)) {
                return ['ok' => false, 'error' => 'visibility debe ser ga|beta|hidden', 'code' => 422];
            }
            $current['visibility'] = $vis;
        }
        if (array_key_exists('killswitch', $input)) {
            $current['killswitch'] = filter_var($input['killswitch'], FILTER_VALIDATE_BOOLEAN);
        }

        $catalog[$key] = $current;
        $this->saveCatalog($catalog);

        return ['ok' => true, 'module' => array_merge(['key' => $key], $current)];
    }

    // ── privados ─────────────────────────────────────────────────────────

    private function loadCatalog(): array
    {
        global $db;
        $r = $db->Execute("SELECT value FROM platform_config WHERE key = 'moduleCatalog' LIMIT 1");
        if (!$r || $r->EOF) {
            return [];
        }
        $decoded = json_decode((string) ($r->fields['value'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveCatalog(array $catalog): void
    {
        global $db;
        $json = json_encode($catalog, JSON_UNESCAPED_UNICODE);
        $adminId = defined('ADMIN_AUTHED_ID') ? ADMIN_AUTHED_ID : null;

        $db->Execute(
            "INSERT INTO platform_config (key, value, updated_by, updated_at)
             VALUES ('moduleCatalog', ?::jsonb, ?, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = now()",
            [$json, $adminId]
        );
    }
}
