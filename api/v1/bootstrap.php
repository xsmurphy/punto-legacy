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
require_once __DIR__ . '/../lib/Settings/StockCountSettings.php';

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

// Identidad legible del usuario del contexto: nombre propio y nombre del rol.
//
// Hasta 2026-09-02 el bloque `user` de abajo devolvia solo `id`, `role` y
// `permissions` — o sea que ninguna superficie podia SALUDAR al operador ni
// decir con que rol esta trabajando, aunque el dato estuviera a un JOIN. La
// lista de empleados si traia el nombre; el que estaba operando, no.
//
// Una sola query con LEFT JOIN a la taxonomia del rol, no dos: esto corre en
// CADA arranque del panel y de la caja. El LEFT es a proposito — un rol legacy
// que no exista como taxonomia deja `roleName` vacio, no rompe el arranque.
//
// El cast `taxonomyid::text` no es cosmetico: `contact.role` es varchar y
// convive con roles legacy int-as-string ('1' = owner) que no son uuid.
// Comparar uuid contra varchar aborta la query ENTERA en Postgres
// (operator does not exist), asi que un solo tenant con rol legacy tumbaria
// el arranque de todos. La comparacion es textual a proposito.
$userName = '';
$userRoleName = '';
if ((string)$ctx['userId'] !== '') {
    $uRs = ncmExecute(
        "SELECT c.contactName AS name, t.taxonomyname AS rolename
           FROM contact c
           LEFT JOIN taxonomy t
             ON t.taxonomyid::text = c.role
            AND t.taxonomytype = 'role'
            AND t.companyid = c.companyid
          WHERE c.contactId = ? AND c.companyId = ?
          LIMIT 1",
        [(string)$ctx['userId'], (string)COMPANY_ID]
    );
    if ($uRs && !$uRs->EOF) {
        $userName     = (string)($uRs->fields['name'] ?? '');
        $userRoleName = (string)($uRs->fields['rolename'] ?? '');
    }
}

// ── Roster de la pantalla de bloqueo del POS (SOLO la CAJA) ────────────────
// Proyección MÍNIMA (id/name/pinhash) de los usuarios activos habilitados en la
// sucursal del contexto. Ver el docblock de `UsersService::rosterForOutlet()`:
// es dato OPERATIVO de la caja, autorizado por el TIPO DE DISPOSITIVO + el
// scope de sucursal — NO pasa por `contacts.user.view`, que es el permiso de
// gestión de equipo y que el rol `device` no tiene (ni debe tener, mig 162).
//
// Eso es lo que hace defendible NO tener ese gate, así que el gate de acá NO es
// opcional: para `panel` la clave `users` ni siquiera se incluye en la
// respuesta. `pinhash` es un SHA-256 sin sal de 4 dígitos — 10.000
// combinaciones, forzable en un pestañeo. Devolvérselo a un rol de panel sin
// `contacts.user.view` le regalaría el PIN del encargado y con él su identidad
// en la caja (atribución de ventas, `pos.space.override`).
//
// EL REALM SOLO NO ALCANZA: `pos-app` no significa "caja". Los dispositivos
// `screen`, `kds`, `display` y `print` autentican con ESE MISMO realm y token
// eterno (`device.module`, ver `apiAuthTenant()` en `api/bootstrap.php`), y el
// `display` es literalmente una pantalla que mira el CLIENTE del comercio. El
// discriminante es el `module`, igual que en `unlock-pin.php` (que rechaza con
// 403 a todo `module !== 'pos'` justamente para que una pantalla de cliente no
// pueda desbloquear operadores): solo el dispositivo que ES una caja recibe los
// `pinhash`. Para el resto la clave `users` queda AUSENTE, igual que para el
// panel — mismo patrón fail-closed.
//
// No rompe el lock screen: el flujo real de la caja siempre viaja con el Bearer
// del slot `pos` (`frontend/lib/api/pos-fetch.ts`, `module` default `"pos"`, y
// `pos-client.ts` nunca pasa otro), y el BFF `/api/pos/bootstrap` reenvía ese
// header tal cual. La Estación de Impresión (module `print`) usa los BFF
// `/api/pos/*` pero NO el bootstrap: su cliente propio
// (`frontend/lib/print-station/api.ts`) solo pega a `/v1/station-printers`,
// `/v1/print-jobs` y `/v1/screens`. No tiene lock screen ni roster que mostrar.
//
// Y no reintroduce el lockout que este cambio arregla: el POS SIEMPRE autentica
// con el Bearer del device (ver `frontend/lib/api/pos-fetch.ts` — sin Bearer la
// API resuelve realm `panel` con `registerId=''`, lo que rompe toda mutación de
// caja). Un `/pos` sin device pareado no llega al lock screen: pide parear.
//
// Si alguien viene a "simplificar" esto devolviendo el roster para todos:
// no. Leer los tres párrafos de arriba primero.
//
// Sucursal: la del token, y punto. NO se consulta `VIEW_OUTLET_ID` (el
// override del selector `X-Outlet-Id`) como hacen reports/stock.php y
// compañía: ese header solo se acepta en realm `panel` (ver
// `api/bootstrap.php`), así que acá adentro la constante nunca está definida.
// Consultarla sería código muerto sugiriendo un scope "Todas" que este camino
// no puede alcanzar. El device opera SIEMPRE con la sucursal fija de su
// pairing — `rosterForOutlet()` igual documenta el caso '' para otros callers.
// Las DOS condiciones, explícitas. `apiAuthTenant()` deja `module` en '' para
// realm `panel`, así que hoy el segundo check ya alcanzaría — pero eso es un
// detalle de ESE resolver, no una garantía de este endpoint: si algún día el
// panel resolviera un module, un check suelto lo dejaría entrar con el default
// `'pos'` del `??`. El realm sigue siendo la primera puerta.
$isRegisterDevice = ($ctx['realm'] ?? '') === 'pos-app'
    && ($ctx['module'] ?? 'pos') === 'pos';
$roster = [];
if ($isRegisterDevice) {
    $roster = (new \Punto\Api\Users\UsersService())
        ->rosterForOutlet((string) COMPANY_ID, (string) OUTLET_ID);
}

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
// estado inicial que declara ModuleChannels — un canal nace APAGADO y se
// enciende a mano en la config del módulo. Se resuelve acá y NO en el front:
// el POS recibe booleans y no recombina nada.
//
// `pspQr` es el mapa genérico { provider: bool } que consume el POS para
// filtrar el medio de pago de CADA pasarela (ver PspCatalog y
// frontend/lib/payments/psp/). `bancardQr`/`bancardPos` siguen existiendo como
// claves propias porque una caja con el bootstrap cacheado (offline) todavía
// lee ese shape hasta su próxima sincronización.
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
// El terminal físico no es un canal de QR, así que no sale de PspCatalog —
// pero el default lo declara el mismo archivo (ModuleChannels), no este.
$bancardPos = \Punto\Api\Modules\ModuleChannels::on('bancard', 'pos', $moduleOn('bancard'), $bancardCfg);

$payload = [
    'currency'    => $row['currency'] ?? '',
    // settingDecimal es 'yes'/'no' (usar decimales o no), NO un conteo de dígitos.
    'decimal'     => $row['decimal'] ?? 'no',
    // Valor crudo 'comma'/'dot' (como lo espera formatNumber del front, no el símbolo).
    'thousand'    => ($row['thousand'] ?? '') === 'comma' ? 'comma' : 'dot',
    'taxName'     => $row['taxname'] ?: 'IVA',
    // Etiqueta del documento fiscal del cliente (ej. 'RUC'); usada como header
    // de columna y como label del campo en el alta de contactos.
    //
    // Cuando el tenant no la configuró sale la del PAÍS ('CUIT' en AR, 'CNPJ'
    // en BR). El fallback anterior era el literal 'TIN', y era peor que vacío:
    // el resolver del front (resolveTaxIdLabel, lib/tenant-locale.ts) lo leía
    // como una elección explícita del comercio y por eso NO caía al default
    // del país — el formulario rotulaba el campo "TIN". String vacío = no
    // configurado, que es lo que el front sabe interpretar.
    'tinName'     => $row['tinname']
        ?: (\Punto\Api\Support\CountryDefaults::taxIdLabel($row['country'] ?? null) ?? ''),
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
        'name'        => $userName,
        'role'        => $ctx['roleId'],
        'roleName'    => $userRoleName,
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
    // OJO: `users` (el roster del lock screen) NO va acá. Se agrega DESPUÉS del
    // literal y solo para un device que ES una caja (`pos-app` + module `pos`)
    // — ver el bloque del roster arriba.
    // Que la clave esté ausente por default es a propósito: si algún día este
    // literal se reordena o se copia, el default es no filtrar los `pinhash`.
    //
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
];

// Roster del lock screen: solo para la caja. Realm `pos-app` + module `pos` es
// el gate — ver el bloque del roster arriba del todo. Para el panel y para los
// devices que no son caja (screen/kds/display/print) la clave queda AUSENTE (y
// no en `null` ni con `pinhash => null`): ningún consumidor del panel la lee
// (`frontend/lib/types/bootstrap.ts` ni siquiera la declara), así que mandarla
// vacía solo serviría para que alguien la empiece a leer y la reintroduzca.
if ($isRegisterDevice) {
    $payload['users'] = $roster;

    // Conteo de stock en la caja (context/63 F1). Solo para un device que ES
    // una caja, mismo gate que el roster: en una pantalla de cliente o en un
    // KDS estas claves no habilitan nada.
    //
    // Bajan al bootstrap y no a un endpoint propio porque el conteo ciego es
    // OFFLINE-NATIVO: es exactamente lo que puede hacerse sin red (no necesita
    // el esperado), y un dato que la caja necesita sin conexión tiene que
    // viajar en el snapshot, no en una llamada que va a fallar justo cuando
    // hace falta.
    //
    // Lo que NO baja es el flag `stockCountBlind`: en esta fase la caja cuenta
    // SIEMPRE a ciegas, y el esperado no lo tiene aunque quiera (el ledger no
    // viaja al POS). El día que exista el conteo no ciego (F2), ese flag va a
    // significar algo acá; hoy mandarlo sería prometer un interruptor que no
    // controla nada.
    $countSettings = \Punto\Api\Settings\StockCountSettings::forCompany(COMPANY_ID);
    $payload['stockCountLists']      = $countSettings->lists();
    // Para que la caja pueda decir la verdad al confirmar: "se va a ajustar el
    // stock" vs "queda registrado". El flag NO lo decide la caja — lo aplica
    // `finish()` server-side — pero sin bajarlo la pantalla no puede anticipar
    // cuál de las dos cosas va a pasar.
    $payload['stockCountRecordOnly'] = $countSettings->recordOnly();
}

apiOk($payload);
