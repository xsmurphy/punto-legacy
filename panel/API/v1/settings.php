<?php
/**
 * REST canónico — Ajustes de la empresa (motor ERP, raw).
 *
 *   GET  /API/v1/settings?view=general                 → ajustes (perfil + parámetros), CRUDO.
 *   GET  /API/v1/settings?view=options                  → listas para selects.
 *   GET  /API/v1/settings?view=taxonomies&type=<t>       → items de taxonomía (tax/category/tag/...).
 *   POST /API/v1/settings (action=update&type=setting + campos) → guarda en company.config.
 *
 * Arregla el guardado roto en PG (tabla `setting` eliminada → company.config). Escritura scopeada
 * por COMPANY_ID del JWT. Auth: JWT. Las demás vistas (templates/ecommerce/monedas) se agregan en
 * incrementos siguientes; por ahora siguen sirviéndose por el PHP legacy vía `?action=`.
 */

require_once __DIR__ . '/../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../lib/settings/SettingsService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$svc    = new SettingsService();

if ($method === 'POST') {
    if ((int) PANEL_AUTHED_ROLE === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    $type   = (string) (validateHttp('type', 'post') ?: '');
    if ($action !== 'update' || $type !== 'setting') {
        apiError('Acción no soportada', 422);
    }

    $b = fn($k) => (bool) validateHttp($k, 'post');
    $s = fn($k) => (string) (validateHttp($k, 'post') ?: '');

    $fields = [
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
