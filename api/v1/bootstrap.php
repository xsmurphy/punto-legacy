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
require_once __DIR__ . '/../lib/Users/UsersService.php';

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
        config->>'settingName'              AS companyname,
        -- El logo NO vive en claves top-level de config: uploadLogo lo
        -- persiste DENTRO de config.settingObj (un JSON anidado como string,
        -- ver SettingsService::readSettingObj). Buscarlo con
        -- config->>'hasLogo' devolvía NULL siempre y el POS caía al fallback
        -- de la inicial aunque el tenant tuviera logo (reporte 2026-08-02).
        -- Se extrae el blob y se decodea en PHP, igual que SettingsService.
        config->>'settingObj'               AS settingobj,
        -- Razón social/RUC/email/sitio del tenant (ticket impreso, flujo
        -- NO-FE — ver context/10-roadmap.md §2026-07-30). A futuro la
        -- facturación electrónica puede terminar siendo otra fuente para
        -- estos mismos datos; hoy son los únicos disponibles.
        config->>'settingBillingName'       AS companybillingname,
        config->>'settingRUC'               AS companytin,
        config->>'settingEmail'             AS companyemail,
        config->>'settingWebSite'           AS companywebsite,
        -- Módulo Bancard: el toggle de /v1/modules escribe el flat key en
        -- company.config (ruteo de ncmUpdate) y los canales (qr/pos) en
        -- company.moduleData.bancard. El POS necesita los dos para saber si
        -- ofrecer el QR y si mostrar la config del terminal físico.
        config->>'bancard'                  AS bancard,
        -- D3/D2 de context/40-anulacion-y-nota-credito.md: el POS necesita
        -- estos dos para el flujo de devolución — settingReturnRefund decide
        -- si pregunta 'cash'/'credit' o los ofrece los dos ('ask', default);
        -- settingReturnAllowIngredientReversal habilita ofrecer reponer
        -- insumos de una producción directa que no llegó a prepararse. Mismo
        -- patrón que el resto de los settingX de acá: JSONB schemaless, sin
        -- columna propia.
        config->>'settingReturnRefund'                  AS returnrefund,
        config->>'settingReturnAllowIngredientReversal' AS returnallowingredientreversal,
        -- moduleData NO es una columna de company: vive DENTRO del JSONB
        -- config (ruteo de ncmUpdate/Schema::split), igual que los settingX de
        -- arriba. Pedirla como columna daba SQLSTATE 42703 y, desde que el
        -- wrapper DB dejo de tragarse los errores, eso paso a ser un 500 en
        -- /v1/bootstrap: el POS entero quedaba en la pantalla de sin conexion.
        -- El ->> la devuelve como el mismo string JSON que el caller ya espera
        -- (mas abajo hace json_decode).
        config->>'moduleData'               AS moduledata
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
    // outletAddress/BillingName/RUC/Phone viven en `data` JSONB (mig 14, jsonb
    // demote) — NO son columnas. Se selecciona `data` y flattenJsonb los sube
    // al nivel de la fila; nombrarlos en el SELECT rompería el bootstrap
    // entero con "column does not exist".
    'SELECT outletId, outletName, lat, lng, data
       FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletName ASC',
    [COMPANY_ID],
    false,
    true
);
$outlets = [];
$activeOutletName = '';
// Coordenadas de la sucursal activa (mig 14: columnas numéricas `lat`/`lng`
// que reemplazaron a `outletLatLng`). Las consume la vista mapa de
// /pos/ordenes para el PIN del local. NULL si la sucursal no cargó ubicación.
$activeOutletLat = null;
$activeOutletLng = null;
// Datos fiscales de la sucursal activa (ticket impreso, flujo NO-FE — ver
// context/10-roadmap.md §2026-07-30). '' si el outlet no los cargó.
$activeOutletAddress     = '';
$activeOutletBillingName = '';
$activeOutletTin         = '';
$activeOutletPhone       = '';
// Default incluido/añadido del IVA para la sucursal activa (F2b, context/38
// §Reglas LATAM "Incluido vs añadido"). `itemsTaxIncluded` vive DEMOTED a
// `data` JSONB (OutletsService) — la fila ya viene aplanada por el SELECT de
// abajo (mismo criterio que outletAddress/outletBillingName acá al lado).
// Default true si el outlet nunca lo configuró: mismo default fiscal que
// OutletsService::create() y que SaleService::enrichWithTaxes().
$activeOutletTaxIncluded = true;
if ($outletsRs && is_object($outletsRs)) {
    while (!$outletsRs->EOF) {
        $f    = $outletsRs->fields;
        $oid  = (string) ($f['outletId'] ?? $f['outletid'] ?? '');
        $name = (string) ($f['outletName'] ?? $f['outletname'] ?? '');
        $outlets[] = ['id' => $oid, 'name' => $name];
        if ($oid === OUTLET_ID) {
            $activeOutletName = $name;
            $rawLat = $f['lat'] ?? null;
            $rawLng = $f['lng'] ?? null;
            if ($rawLat !== null && $rawLat !== '' && is_numeric($rawLat)
                && $rawLng !== null && $rawLng !== '' && is_numeric($rawLng)) {
                $activeOutletLat = (float) $rawLat;
                $activeOutletLng = (float) $rawLng;
            }
            $activeOutletAddress     = (string) ($f['outletAddress'] ?? $f['outletaddress'] ?? '');
            $activeOutletBillingName = (string) ($f['outletBillingName'] ?? $f['outletbillingname'] ?? '');
            $activeOutletTin         = (string) ($f['outletRUC'] ?? $f['outletruc'] ?? '');
            $activeOutletPhone       = (string) ($f['outletPhone'] ?? $f['outletphone'] ?? '');
            // El JSONB puede haber guardado el booleano como STRING ("false",
            // "0") según quién lo haya escrito — (bool) "false" === true en
            // PHP, así que un outlet en modo "IVA no incluido" se leería mal
            // acá (mismo bug ya cazado dos veces, ver SaleService::toBoolOrNull).
            // FILTER_VALIDATE_BOOLEAN con FILTER_NULL_ON_FAILURE trata
            // ausente/no-parseable como "sin configurar" → default true.
            $rawTaxIncluded = $f['itemsTaxIncluded'] ?? $f['itemstaxincluded'] ?? null;
            if ($rawTaxIncluded !== null && $rawTaxIncluded !== '') {
                $parsed = is_bool($rawTaxIncluded)
                    ? $rawTaxIncluded
                    : filter_var((string) $rawTaxIncluded, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $activeOutletTaxIncluded = $parsed;
                }
            }
        }
        $outletsRs->MoveNext();
    }
    $outletsRs->Close();
}

$userPermissions = RoleService::getPermissions((string)$ctx['roleId'], (string)COMPANY_ID);

// ── Roster de la pantalla de bloqueo del POS ────────────────────────────────
// Proyección MÍNIMA (id/name/pinhash) de los usuarios activos habilitados en la
// sucursal del contexto. Ver el docblock de `UsersService::rosterForOutlet()`:
// es dato OPERATIVO de la caja, autorizado por el realm + el scope de sucursal
// — NO pasa por `contacts.user.view`, que es el permiso de gestión de equipo y
// que el rol `device` no tiene (ni debe tener, mig 162).
//
// Sucursal efectiva: `VIEW_OUTLET_ID` si el panel mandó `X-Outlet-Id` (mismo
// patrón que reports/stock.php y reports/dashboard.php), si no la del token.
// '' = scope "Todas" → sin filtro de sucursal.
$rosterOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;
$roster = (new \Punto\Api\Users\UsersService())->rosterForOutlet((string) COMPANY_ID, $rosterOutletId);

// Logo: decodear settingObj y resolver la URL con la MISMA lógica que
// SettingsService::general() (hasLogo + logoUrl + cache-bust por timestamp).
$settingObj = json_decode((string) ($row['settingobj'] ?? ''), true);
$settingObj = is_array($settingObj) ? $settingObj : [];
$logoHas    = !empty($settingObj['hasLogo']);
$logoUrlRaw = (string) ($settingObj['logoUrl'] ?? '');
$logoStamp  = isset($settingObj['logoUploadedAt']) ? (int) $settingObj['logoUploadedAt'] : null;
$logoUrlResolved = ($logoHas && $logoUrlRaw !== '')
    ? $logoUrlRaw . ($logoStamp ? '?v=' . $logoStamp : '')
    : '';

// ── Pasarelas de pago con QR + terminal físico de Bancard ───────────────────
// Mismo criterio que ModulesService::list(): el módulo está en el flat key
// (lo que escribe el toggle) y los canales en moduleData.<module>, con el
// default que declara el catálogo. Se resuelve acá y NO en el front: el POS
// recibe booleans y no recombina nada.
//
// `pspQr` es el mapa genérico { provider: bool } que consume el POS para
// filtrar el medio de pago de CADA pasarela (ver PspCatalog y
// frontend/lib/payments/psp/). `bancardQr`/`bancardPos` se mantienen tal cual
// porque son el contrato que ya leen los clientes desplegados — un POS con
// config cacheada (offline) seguiría mandando el shape viejo.
$moduleDataAll = json_decode((string) ($row['moduledata'] ?? ''), true);
$moduleDataAll = is_array($moduleDataAll) ? $moduleDataAll : [];

$moduleOn = static function (string $moduleKey) use ($row): bool {
    return in_array((string) ($row[$moduleKey] ?? ''), ['1', 'true', 'on', 'yes'], true);
};

$pspQr = [];
foreach (\Punto\Api\PaymentMethods\PspCatalog::qrProviders() as $provider => $psp) {
    $moduleKey = (string) $psp['module'];
    $cfg = is_array($moduleDataAll[$moduleKey] ?? null) ? $moduleDataAll[$moduleKey] : [];
    $pspQr[$provider] = \Punto\Api\PaymentMethods\PspCatalog::qrChannelOn(
        $psp,
        $moduleOn($moduleKey),
        $cfg
    );
}

$bancardCfg = is_array($moduleDataAll['bancard'] ?? null) ? $moduleDataAll['bancard'] : [];
$bancardQr  = (bool) ($pspQr['bancard'] ?? false);
$bancardPos = $moduleOn('bancard')
    && (!array_key_exists('pos', $bancardCfg) || filter_var($bancardCfg['pos'], FILTER_VALIDATE_BOOLEAN));

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
    // Razón social/RUC/email/sitio del tenant — ticket impreso (flujo NO-FE).
    'companyBillingName' => $row['companybillingname'] ?? '',
    'companyTin'         => $row['companytin'] ?? '',
    'companyEmail'       => $row['companyemail'] ?? '',
    'companyWebsite'     => $row['companywebsite'] ?? '',
    // Canales del módulo Bancard, ya resueltos a bool (módulo activo Y canal
    // no apagado en la config). El front no vuelve a combinar nada.
    'bancardQr'          => $bancardQr,
    'bancardPos'         => $bancardPos,
    // Canal QR por pasarela — { provider: bool }. Sumar una pasarela nueva no
    // toca este archivo: entra por PspCatalog.
    'pspQr'              => $pspQr,
    // Logo del tenant (S3, público). '' si no hay logo cargado — el front
    // hace fallback a la marca Punto. `?v=` cache-bust con logoUploadedAt.
    // MISMA lógica que SettingsService::general(): el logo vive en el blob
    // settingObj (decodeado arriba del apiOk), no en claves top-level.
    'logoUrl'     => $logoUrlResolved,
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
    // Coords del local para el PIN fijo del mapa de órdenes (/pos/ordenes).
    'activeOutletLat'  => $activeOutletLat,
    'activeOutletLng'  => $activeOutletLng,
    // Datos fiscales de la sucursal activa — ticket impreso (flujo NO-FE).
    'activeOutletAddress'     => $activeOutletAddress,
    'activeOutletBillingName' => $activeOutletBillingName,
    'activeOutletTin'         => $activeOutletTin,
    'activeOutletPhone'       => $activeOutletPhone,
    // Default incluido/añadido del IVA de la sucursal activa (F2b,
    // context/38). El POS lo usa para las líneas cuyo ítem no trae override
    // propio — mismo fallback que SaleService::enrichWithTaxes.
    'activeOutletTaxIncluded' => $activeOutletTaxIncluded,
    'outlets'          => $outlets,
    // D3 (context/40): política de reintegro de devoluciones — 'cash' |
    // 'credit' | 'ask' (default). Con 'ask' el POS pregunta en cada
    // devolución; con 'cash'/'credit' fijado, el back rechaza (422) un
    // request con el otro modo, así que el POS ni siquiera debería ofrecerlo.
    'settingReturnRefund' => in_array((string) ($row['returnrefund'] ?? ''), ['cash', 'credit'], true)
        ? (string) $row['returnrefund']
        : 'ask',
    // D2 (context/40): habilita OFRECER la reposición de insumos de una
    // producción directa/combo que no llegó a prepararse. Default false —
    // mismo criterio 'yes'/'no' que el resto de los settingX booleanos.
    'settingReturnAllowIngredientReversal' => ((string) ($row['returnallowingredientreversal'] ?? '')) === 'yes',
    // Roster de la pantalla de bloqueo del POS — SOLO id/name/pinhash de los
    // usuarios activos habilitados en la sucursal del contexto. El POS lo baja
    // acá (y no por `/v1/users`, que exige `contacts.user.view` y le da 403 al
    // device) y lo persiste en el snapshot offline: el lock screen valida el
    // PIN sin red. Ver `UsersService::rosterForOutlet()`.
    'users'            => $roster,
    // Cantidad de usuarios (type=0) activos del tenant. YA NO gobierna el
    // auto-lock del POS: desde 2026-08-24 el lock screen es siempre lo primero,
    // sin importar cuántos operadores haya (pedido del owner — ver
    // `frontend/lib/pos/lock-store.ts`). Se conserva como dato informativo del
    // tenant para clientes ya desplegados que lo lean.
    'userCount'        => (int) (ncmExecute(
        'SELECT COUNT(*) AS c FROM contact WHERE companyId = ? AND type = 0 AND contactStatus > 0',
        [COMPANY_ID],
        false
    )['c'] ?? 0),
]);
