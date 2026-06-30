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
 * Auth: MULTI-REALM — `apiAuthTenant(['panel','pos-app'])`. Tenant por COMPANY_ID del JWT.
 * Tanto el panel admin como el POS (app-next) leen este bootstrap para hidratar
 * config de tenant (currency, decimales, taxName, outlets) en el primer paint.
 * Respuesta: envelope canónico { ok, data, meta }.
 *
 * Port FIEL de panel/API/v1/bootstrap.php (Fase 2 del desacople de /panel). Cambios:
 * `apiMiddleware()` → `apiAuthTenant(['panel','pos-app'])`; `PANEL_AUTHED_USER`/`PANEL_AUTHED_ROLE`
 * → `$ctx['userId']`/`$ctx['roleId']`. SQL y shape de respuesta idénticos — el front
 * (muchos a_*.js + app-next BFF) depende del shape exacto.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/RoleService.php';

$ctx = apiAuthTenant(['panel', 'pos-app']);

$row = ncmExecute(
    "SELECT
        config->>'settingCurrency'          AS currency,
        config->>'settingDecimal'           AS decimal,
        config->>'settingThousandSeparator' AS thousand,
        config->>'settingTaxName'           AS taxname,
        config->>'settingTIN'               AS tinname,
        config->>'settingCountry'           AS country,
        config->>'settingTimeZone'          AS timezone,
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

// Sucursales activas del tenant + nombre de la activa (para el selector del
// menú user en frontend). Se inline acá en vez de pedirlo aparte porque
// el sidebar lo necesita en el primer paint y evita un round-trip extra.
// `forceObj=true` (4to arg) fuerza recordset multi-row iterable — sin él
// ncmExecute con una única fila colapsa a CaseInsensitiveArray escalar.
$outletsRs = ncmExecute(
    'SELECT outletId, outletName FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletName ASC',
    [COMPANY_ID],
    false,
    true
);
$outlets = [];
$activeOutletName = '';
if ($outletsRs && is_object($outletsRs)) {
    while (!$outletsRs->EOF) {
        $f    = $outletsRs->fields;
        $oid  = (string) ($f['outletId'] ?? $f['outletid'] ?? '');
        $name = (string) ($f['outletName'] ?? $f['outletname'] ?? '');
        $outlets[] = ['id' => $oid, 'name' => $name];
        if ($oid === OUTLET_ID) {
            $activeOutletName = $name;
        }
        $outletsRs->MoveNext();
    }
    $outletsRs->Close();
}

$userPermissions = RoleService::getPermissions((string)$ctx['roleId'], (string)COMPANY_ID);

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
    // TZ del tenant (IANA, ej. 'America/Asuncion'). El server ya hace
    // date_default_timezone_set(settingTimeZone) en data.php, así que los
    // writes server-side quedan en hora local del tenant; el cliente debe
    // alinear sus writes a esta misma TZ (convención storage = local naive).
    'timezone'    => $row['timezone'] ?? '',
    'companyName' => $row['companyname'] ?? '',
    'companyId'   => COMPANY_ID,
    // Base de las pantallas standalone (PUBLIC_URL = <host>/screens) — para links del front.
    'publicUrl'   => defined('PUBLIC_URL') ? PUBLIC_URL : '',
    'user'        => [
        'id'          => $ctx['userId'],
        'role'        => $ctx['roleId'],
        'permissions' => $userPermissions,
    ],
    // Caja activa del POS (claim `rid` del JWT). '' = sin caja seleccionada
    // → el front fuerza el selector de caja (A7). La lista de cajas del outlet
    // la trae GET /v1/register?resource=list.
    'activeRegisterId' => $ctx['registerId'] ?? '',
    // Selector de sucursal del menú user (frontend 2026-06-12). El front
    // muestra el subtitle con `activeOutletName` y lista `outlets` cuando
    // hay ≥2. POST /v1/active-outlet re-emite el JWT con el `oid` nuevo.
    'activeOutletId'   => OUTLET_ID,
    'activeOutletName' => $activeOutletName,
    'outlets'          => $outlets,
    // Cantidad de usuarios (type=0) activos del tenant — usado por el POS
    // para auto-activar el lock screen al entrar cuando hay > 1 usuario
    // (regla de owner: comercio con varios cajeros se inicia bloqueado).
    'userCount'        => (int) (ncmExecute(
        'SELECT COUNT(*) AS c FROM contact WHERE companyId = ? AND type = 0 AND contactStatus > 0',
        [COMPANY_ID],
        false
    )['c'] ?? 0),
]);
