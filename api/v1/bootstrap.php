<?php
/**
 * REST canónico (API compartida /api) — Bootstrap de config para el front.
 *
 *   GET /v1/bootstrap → config de la empresa (currency, decimales, taxName, …)
 *       + datos básicos del usuario logueado.
 *
 * El front (.html estático) NO puede recibir estas constantes por PHP (PHP nunca
 * sirve HTML). Las pide acá, vía el BFF, al cargar — y con ellas formatea números
 * y pinta el chrome (título, currency). La API es la única capa con BD.
 *
 * Auth: realm `panel` (apiAuthTenant(['panel'])). Tenant por COMPANY_ID del JWT.
 * Respuesta: envelope canónico { ok, data, meta }.
 *
 * Port FIEL de panel/API/v1/bootstrap.php (Fase 2 del desacople de /panel). Cambios:
 * `apiMiddleware()` → `apiAuthTenant(['panel'])`; `PANEL_AUTHED_USER`/`PANEL_AUTHED_ROLE`
 * → `$ctx['userId']`/`$ctx['roleId']`. SQL y shape de respuesta idénticos — el front
 * (muchos a_*.js) depende del shape exacto.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

$row = ncmExecute(
    "SELECT
        config->>'settingCurrency'          AS currency,
        config->>'settingDecimal'           AS decimal,
        config->>'settingThousandSeparator' AS thousand,
        config->>'settingTaxName'           AS taxname,
        config->>'settingTIN'               AS tinname,
        config->>'settingCountry'           AS country,
        config->>'settingName'              AS companyname
     FROM company
     WHERE companyId = ?",
    [COMPANY_ID]
);
// ncmExecute devuelve un CaseInsensitiveArray (objeto con ArrayAccess) en éxito,
// o 0/false si no hay fila/error. Solo reemplazamos los escalares de fallo.
if (!is_object($row) && !is_array($row)) {
    $row = [];
}

apiOk([
    'currency'    => $row['currency'] ?? '',
    // settingDecimal es 'yes'/'no' (usar decimales o no), NO un conteo de dígitos.
    'decimal'     => $row['decimal'] ?? 'no',
    // Valor crudo 'comma'/'dot' (como lo espera formatNumber del front, no el símbolo).
    'thousand'    => ($row['thousand'] ?? '') === 'comma' ? 'comma' : 'dot',
    'taxName'     => $row['taxname'] ?: 'IVA',
    // Etiqueta del documento fiscal del cliente (ej. 'RUC'); usada como header de columna.
    'tinName'     => $row['tinname'] ?: 'TIN',
    // Código de país (ej. 'PY') — usado para gatear reportes fiscales locales (RG90, Libro Compra).
    'country'     => $row['country'] ?? '',
    'companyName' => $row['companyname'] ?? '',
    'companyId'   => COMPANY_ID,
    // Base de las pantallas standalone (PUBLIC_URL = <host>/screens) — para links del front.
    'publicUrl'   => defined('PUBLIC_URL') ? PUBLIC_URL : '',
    'user'        => [
        'id'   => $ctx['userId'],
        'role' => $ctx['roleId'],
    ],
]);
