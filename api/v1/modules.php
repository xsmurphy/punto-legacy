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

// MULTI-REALM. El POS necesita LEER qué módulos están activos para decidir si
// muestra Mesas y Órdenes en su sidebar. Antes esto era `['panel']` a secas y
// el POS igual lo consultaba con el cliente del PANEL (cookie `_jwt_panel`, que
// vence a las 24 h) desde una pantalla que corre con la sesión del DISPOSITIVO
// (`_jwt`, sin vencimiento). Resultado: al caducar la cookie del operador,
// `/v1/modules` respondía 401, el hook se quedaba sin datos y los módulos
// DESAPARECÍAN del sidebar en silencio — el panel los mostraba habilitados y el
// POS no, sin ningún error a la vista. Un módulo que se esfuma solo es
// exactamente el bug que reportó el owner ("hasta esta mañana sí aparecían").
//
// Es el mismo cruce de credenciales que ya documenta la convención "un cliente
// HTTP = un realm": el POS habla con su Bearer, no con la cookie del panel.
$ctx    = apiAuthTenant(['panel', 'pos-app']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// El device SOLO lee: habilitar o configurar un módulo es administración y se
// hace desde el panel. Guard arriba de todo, antes de cualquier dispatch —
// mismo patrón que `/v1/items.php` y `/v1/item_addons.php`.
if (($ctx['realm'] ?? '') === 'pos-app' && $method !== 'GET') {
    apiError('El dispositivo POS solo puede leer los módulos', 403);
}

// Prender/apagar un módulo o editar su config es administrar la empresa, no
// operarla: exige permiso, igual que `/v1/devices` o `/v1/document-templates`.
// Hasta acá CUALQUIER sesión de panel podía hacerlo — un cajero apagaba el
// módulo de mesas del comercio sin que nada lo frenara (P2 de la auditoría de
// seguridad 2026-08-26; era el más directo de los siete).
if ($method !== 'GET' && !hasPermission('settings.company.edit')) {
    apiError('No tenés permiso para administrar los módulos (requiere: settings.company.edit)', 403);
}

$svc    = new \Punto\Api\Modules\ModulesService();

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
