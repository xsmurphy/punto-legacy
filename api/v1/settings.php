<?php
/**
 * REST canónico (API compartida /api) — Ajustes de la empresa (motor ERP, raw).
 *
 *   GET  /v1/settings?view=general                 → ajustes (perfil + parámetros), CRUDO.
 *   GET  /v1/settings?view=options                  → listas para selects.
 *   GET  /v1/settings?view=taxonomies&type=<t>       → items de taxonomía (tax/category/tag/...).
 *   GET  /v1/settings?view=currencies                → matriz de monedas (cotizaciones).
 *   GET  /v1/settings?view=templates                 → plantillas de impresión (taxonomy).
 *   GET  /v1/settings?view=templateFields            → datos dinámicos del template builder.
 *   POST /v1/settings (action=update&type=setting + campos)        → guarda en company.config.
 *   POST /v1/settings (action=update&type=currencies&currencies=…) → guarda cotizaciones (settingObj).
 *   POST /v1/settings (action=saveTemplate&data=…[&i=id])          → crea/actualiza plantilla.
 *   POST /v1/settings (action=removeTemplate&id=…)                 → elimina plantilla.
 *
 * Arregla el guardado roto en PG (tabla `setting` eliminada → company.config; el save de monedas
 * legacy interpolaba COMPANY_ID sin comillas; el delete de plantillas usaba LIMIT 1 inválido en PG y
 * el update no scopeaba companyId → IDOR). Escritura scopeada por COMPANY_ID del JWT. Auth: realm
 * `panel` (apiAuthTenant(['panel'])).
 *
 * Port FIEL de panel/API/v1/settings.php (Fase 2 del desacople de /panel). Cambios respecto al
 * original: `apiMiddleware()` → `apiAuthTenant(['panel'])`; `PANEL_AUTHED_ROLE` → `$ctx['roleId']`;
 * service en namespace `Punto\Api\Settings`. El contrato (vistas, acciones, shape, status codes)
 * es idéntico — el JS de a_settings.js y a_settings_templates.js dependen de él.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Settings\SettingsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');

    // Plantillas de impresión (taxonomy printTemplate).
    if ($action === 'saveTemplate') {
        $data = (string) (validateHttp('data', 'post') ?: '');
        $id   = (string) (validateHttp('i', 'post') ?: '');
        if ($data === '') {
            apiError('Falta el diseño de la plantilla', 422);
        }
        $r = $svc->saveTemplate(COMPANY_ID, $id, $data);
        if ($r === false) {
            apiError('No se pudo guardar la plantilla', 500);
        }
        apiOk(['action' => 'saveTemplate', 'id' => is_string($r) ? $r : $id]);
    }
    if ($action === 'removeTemplate') {
        $id = (string) (validateHttp('id', 'post') ?: '');
        if ($id === '') {
            apiError('Falta el id de la plantilla', 422);
        }
        if (!$svc->removeTemplate(COMPANY_ID, $id)) {
            apiError('No se pudo eliminar la plantilla', 500);
        }
        apiOk(['action' => 'removeTemplate', 'id' => $id]);
    }

    // Logo de la empresa — upload multipart + delete. Path en S3 idéntico al
    // legacy ({companyId}.jpg en raíz) → POS y panel legacy ven el mismo archivo.
    if ($action === 'uploadLogo') {
        if (empty($_FILES['logo']['tmp_name'])) {
            apiError('Falta el archivo (campo "logo")', 422);
        }
        try {
            apiOk($svc->uploadLogo(COMPANY_ID, $_FILES['logo']));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    if ($action === 'deleteLogo') {
        try {
            apiOk($svc->deleteLogo(COMPANY_ID));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 500);
        }
    }

    $type   = (string) (validateHttp('type', 'post') ?: '');
    if ($action !== 'update' || !in_array($type, ['setting', 'currencies'], true)) {
        apiError('Acción no soportada', 422);
    }

    if ($type === 'currencies') {
        $raw  = (string) (validateHttp('currencies', 'post') ?: '');
        $list = json_decode($raw, true);
        if (!is_array($list)) { $list = []; }
        if (!$svc->updateCurrencies(COMPANY_ID, $list)) {
            apiError('No se pudo guardar', 500);
        }
        apiOk(['action' => 'update', 'type' => 'currencies']);
    }

    $b = fn($k) => (bool) validateHttp($k, 'post');
    $s = fn($k) => (string) (validateHttp($k, 'post') ?: '');

    $fields = [
        'name'           => $s('name'),
        'address'        => $s('address'),
        'website'        => $s('website'),
        'email'          => $s('email'),
        'ruc'            => $s('ruc'),
        'phone'          => $s('phone'),
        'city'           => $s('city'),
        'country'        => $s('country'),
        'language'       => $s('language'),
        'timeZone'       => $s('timeZone'),
        'currency'       => $s('currency'),
        'taxName'        => $s('taxName'),
        'billingName'    => $s('billingName'),
        'tin'            => $s('tin'),
        'billDetail'     => $s('billDetail'),
        'category'       => $s('category'),
        'thousandSeparator' => $s('thousandSeparator'),
        'itemsSaleLimit' => $s('itemsSaleLimit'),
        'social'         => [
            'facebook'  => $s('facebook'),
            'instagram' => $s('instagram'),
            'youtube'   => $s('youtube'),
            'twitter'   => $s('twitter'),
        ],
        'decimal'             => $b('decimal'),
        'sellsoldout'         => $b('sellsoldout'),
        'itemSerialized'      => $b('itemSerialized'),
        'drawerEmail'         => $b('drawerEmail'),
        'drawerBlind'         => $b('drawerBlind'),
        'settingRemoveTaxes'  => $b('settingRemoveTaxes'),
        'paymentId'           => $b('paymentId'),
        'creditLine'          => $b('creditLine'),
        'storeCredit'         => $b('storeCredit'),
        'ignoreInternal'      => $b('ignoreInternal'),
        'stockCountBlind'     => $b('stockCountBlind'),
        'blockUsedDocNo'      => $b('blockUsedDocNo'),
        'autoSendDocs'        => $b('autoSendDocs'),
        'taxPy'               => $b('taxPy'),
        'weightBarcodes'      => $b('weightBarcodes'),
        'deletedItemsHistory' => $b('deletedItemsHistory'),
    ];

    if (!$svc->updateGeneral(COMPANY_ID, $fields)) {
        apiError('No se pudo guardar', 500);
    }
    apiOk(['action' => 'update', 'type' => 'setting']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$view = (string) (validateHttp('view') ?: 'general');

if ($view === 'options') {
    apiOk($svc->options());
}

if ($view === 'currencies') {
    apiOk($svc->currencies(COMPANY_ID));
}

if ($view === 'templates') {
    apiOk($svc->templates(COMPANY_ID));
}

if ($view === 'templateFields') {
    apiOk($svc->templateFields(COMPANY_ID));
}

if ($view === 'taxonomies') {
    $allowed = ['tax', 'category', 'tag', 'paymentMethod', 'bank', 'transactionCategory'];
    $type = (string) (validateHttp('type') ?: '');
    if (!in_array($type, $allowed, true)) {
        apiError('Tipo de taxonomía no soportado', 422);
    }
    apiOk(['rows' => $svc->taxonomies(COMPANY_ID, $type)]);
}

// default: general
$row = $svc->general(COMPANY_ID);
if ($row === null) {
    apiError('Empresa no encontrada', 404);
}
apiOk($row);
