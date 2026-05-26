<?php
/**
 * REST canónico — Bootstrap de config para el front.
 *
 *   GET /API/v1/bootstrap → config de la empresa (currency, decimales, taxName, …)
 *       + datos básicos del usuario logueado.
 *
 * El front (.html estático) NO puede recibir estas constantes por PHP (PHP nunca
 * sirve HTML). Las pide acá, vía el BFF, al cargar — y con ellas formatea números
 * y pinta el chrome (título, currency). La API es la única capa con BD.
 *
 * Auth: JWT (cookie _jwt_panel / Bearer / POST _jwt). Tenant por COMPANY_ID del JWT.
 * Respuesta: envelope canónico { ok, data, meta }.
 */

require_once __DIR__ . '/../lib/api_middleware.php';
apiMiddleware();

$row = ncmExecute(
    "SELECT
        config->>'settingCurrency'          AS currency,
        config->>'settingDecimal'           AS decimal,
        config->>'settingThousandSeparator' AS thousand,
        config->>'settingTaxName'           AS taxname,
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
    'thousand'    => ($row['thousand'] ?? '') === 'comma' ? ',' : '.',
    'taxName'     => $row['taxname'] ?: 'IVA',
    'companyName' => $row['companyname'] ?? '',
    'user'        => [
        'id'   => PANEL_AUTHED_USER,
        'role' => PANEL_AUTHED_ROLE,
    ],
]);
