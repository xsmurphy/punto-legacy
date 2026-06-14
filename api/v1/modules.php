<?php
/**
 * REST canónico — Módulos nativos de Punto.
 *
 *   GET  /v1/modules               → mapa { [moduleKey]: { enabled, config? } }
 *   POST /v1/modules action=toggle → activa/desactiva un módulo (double-write POS-compat)
 *   POST /v1/modules action=config → actualiza config de un módulo con config-bearing
 *
 * Auth: realm `panel`. Tenant scopeado por COMPANY_ID del JWT.
 * Estructura idéntica a api/v1/settings.php.
 *
 * COMPATIBILIDAD POS (crítica): el toggle hace double-write:
 *   1. moduleData[key].status (JSONB — panel legacy)
 *   2. company.<key> flat column (enrutado a company.config — POS app/fetchs.php:448-456)
 * Ver ModulesService::toggle() y a_modules.php:34-46.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Modules\ModulesService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $action = (string) (validateHttp('action', 'post') ?: '');

    if ($action === 'toggle') {
        $key     = (string) (validateHttp('key', 'post') ?: '');
        $enabled = (bool) validateHttp('enabled', 'post');

        if ($key === '') {
            apiError('Falta el parámetro key', 422);
        }

        try {
            $svc->toggle(COMPANY_ID, $key, $enabled);
            apiOk(['ok' => true]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
    }

    if ($action === 'config') {
        $key       = (string) (validateHttp('key', 'post') ?: '');
        $configRaw = (string) (validateHttp('config', 'post') ?: '{}');
        $config    = json_decode($configRaw, true);

        if ($key === '') {
            apiError('Falta el parámetro key', 422);
        }
        if (!is_array($config)) {
            apiError('El parámetro config debe ser un objeto JSON válido', 422);
        }

        try {
            $svc->updateConfig(COMPANY_ID, $key, $config);
            apiOk(['ok' => true]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
    }

    apiError('Acción no soportada', 422);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

apiOk($svc->list(COMPANY_ID));
