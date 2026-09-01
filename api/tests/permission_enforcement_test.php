<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de enforcement de permisos.
 *
 * Verifica que cada clave de `PermissionCatalog` que gatea un endpoint lo
 * gatee DE VERDAD: no que el `hasPermission()` esté escrito en el archivo,
 * sino que un rol sin la clave reciba 403 al pegarle al endpoint real, con
 * una sesión de auth real, contra Postgres real.
 *
 * Existe porque el modo de falla que cubre es silencioso: una clave puede
 * estar en el catálogo, aparecer en la pantalla de roles del panel, y no
 * chequearse en ningún lado. Así estaban 25 de las 47 — entre ellas
 * `contacts.user.manage`, con /v1/users sin un solo gate en POST/PUT/DELETE.
 * Un test que solo grepeara `hasPermission(` no habría visto nada raro.
 *
 * Estructura:
 *   (A) Cobertura del catálogo — toda clave está gateada en algún endpoint o
 *       figura en EXCEPCIONES_CONOCIDAS con su motivo. Una clave nueva sin
 *       gate rompe el arnés.
 *   (B) Matriz endpoint × rol, end-to-end, realm `panel`:
 *         - rol SIN la clave  → 403 y el mensaje nombra la clave
 *         - rol CON la clave  → NO 403-por-permiso (puede fallar 404/422 más
 *           adelante; lo que importa es que el gate lo dejó pasar)
 *         - owner             → NO 403-por-permiso, siempre
 *   (C) Realm `pos-app`: el rol seed `device`. Piso (todo lo que el POS
 *       consume con el Bearer del dispositivo tiene que seguir andando) y
 *       techo (lo que una terminal del mostrador no puede hacer).
 *   (C2) El P0: un device no puede ver, editar ni archivar a los EMPLEADOS
 *       del comercio — el camino por el que le reescribía el teléfono de
 *       login al Dueño y se quedaba con el tenant.
 *   (D) Escalación de privilegios en /v1/users.
 *   (E) La regla anti-escalación es compartida (RoleEscalation).
 *   (F) Escalación por /v1/roles (editar permisos sin asignar ningún rol) y
 *       el import CSV con mode=update, que es un UPDATE masivo.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_permission_enforcement_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/permission_enforcement_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/PermissionCatalog.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleEscalation.php';

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$clienteId  = '2b9f6a71-3e2b-4b34-9b5a-7a6a6a6a6a6a';
$taxId      = '3cf780bb-51d6-4b41-b52d-1e77bfb60969';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

/**
 * userAgent con el que el arnés marca TODAS las sesiones que crea. Es lo que
 * después usa el DELETE de limpieza del final para borrarlas: sin pasarlo
 * explícito, bajo CLI no hay HTTP_USER_AGENT y la columna queda NULL, así que
 * el DELETE no matchea nada y cada corrida acumula sesiones vivas.
 */
const AGENTE_DEL_ARNES = 'permission-enforcement-test';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

// ═══════════════════════════════════════════════════════════════════════════
// (A) Cobertura del catálogo
// ═══════════════════════════════════════════════════════════════════════════
//
// Claves que a propósito NO se gatean. Cada una con su motivo — agregar algo
// acá es una decisión de diseño, no una forma de callar el test.
/**
 * Gates que NO se pueden reconocer por `hasPermission('clave')`: la clave se
 * arma por concatenación o sale de un mapa/ternario y a hasPermission() le
 * llega una variable. Clave del array = literal exacto que aparece en el
 * fuente; valor = claves del catálogo que ese literal gatea.
 */
const GATES_INDIRECTOS = [
    // contacts.php — contactsPermFor()
    "'contacts.customer.' . \$op"                          => ['contacts.customer.view', 'contacts.customer.create',
                                                               'contacts.customer.edit', 'contacts.customer.delete'],
    "'contacts.user.view' : 'contacts.user.manage'"         => ['contacts.user.view', 'contacts.user.manage'],
    "'contacts.supplier.view' : 'contacts.supplier.manage'" => ['contacts.supplier.view', 'contacts.supplier.manage'],
    // drawer.php — mapa acción → clave
    "'open'    => 'pos.drawer.open',"                       => ['pos.drawer.open', 'pos.drawer.close', 'finance.manage'],
    // taxonomies.php — mapa TIPO de taxonomía → clave ($taxonomyWritePermission).
    // El depósito es configuración de la sucursal que lo contiene, así que su
    // escritura pide el mismo permiso que la sucursal (ver el docblock ahí).
    "'location' => 'settings.outlet.manage',"               => ['settings.outlet.manage'],
    // items.php — itemsRequiredPermission()
    "return 'inventory.item.view';"                         => ['inventory.item.view'],
    "return 'inventory.stock.adjust';"                      => ['inventory.stock.adjust'],
    "'inventory.item.delete' : 'inventory.item.edit'"       => ['inventory.item.delete', 'inventory.item.edit'],
    "return 'inventory.item.create';"                       => ['inventory.item.create'],
    "? 'inventory.item.edit'"                               => ['inventory.item.edit'],
    // credit-payments.php — la clave sale del kind de la fila
    "'pos.sale.void' : 'finance.manage'"                    => ['pos.sale.void', 'finance.manage'],
    "'pos.sale.creditPayment' : 'finance.manage'"           => ['pos.sale.creditPayment', 'finance.manage'],
    // billing.php — la clave sale del método
    "'billing.view'"                                        => ['billing.view'],
    "'billing.manage'"                                      => ['billing.manage'],
    // SpaceOwnershipGuard — exclusividad de mesa (context/15). NO pasa por
    // hasPermission() a propósito: bajo realm `pos-app` ese helper resuelve
    // contra el rol del DEVICE, que es el mismo para todos los que usan esa
    // tablet, y la regla es sobre PERSONAS. Se evalúa contra el rol del
    // operador que probó su PIN (Punto\Api\Auth\OperatorContext).
    'OperatorContext::can($operator, self::OVERRIDE_PERMISSION, $companyId)' => ['pos.space.override'],
    // AgentActor (lib/Ai) — la MISMA puerta para las dos superficies del agente
    // IA: contra la credencial en el panel, contra el rol del OPERADOR en la
    // caja. La usan `ai/confirm.php` y `ai/execute.php`.
    '\hasPermission(self::PANEL_ENTRY_PERMISSION)'                          => ['ai.agent.use'],
    'OperatorContext::can($operator, self::POS_ENTRY_PERMISSION, $companyId)' => ['pos.ai.use'],
    '$this->can(self::ELEVATED_PERMISSION)'                                 => ['ai.agent.elevated'],
];

const EXCEPCIONES_CONOCIDAS = [
    // Offline-first (memoria del producto + context/08): el back NUNCA rechaza
    // una venta ya EMITIDA. La caja imprimió la factura y el cliente se fue;
    // devolver 403 acá dejaría una venta real sin registrar. El control de
    // quién puede vender es el pareo del dispositivo, no un permiso de rol.
    'pos.sale.create'    => 'la venta emitida no se rechaza (offline-first)',
    // Igual: el descuento viaja DENTRO del payload de una venta ya emitida.
    // Gatearlo obligaría a rechazar la venta entera. Cuando exista sesión de
    // operador en el POS el control correcto es en el cliente (deshabilitar
    // el campo), no rechazar el documento en el back.
    'pos.discount.apply' => 'viaja dentro de una venta ya emitida (offline-first)',
    // `pos.ai.use` SALIÓ de esta lista el 2026-08-31, tal como anticipaba la
    // nota que estaba acá: desde que el asistente de la caja escribe, la clave
    // gatea `/v1/ai/confirm` y `/v1/ai/execute` de verdad, evaluada con
    // `OperatorContext::can()` contra el rol del operador que probó su PIN
    // (`lib/Ai/AgentActor.php`). Figura arriba, en GATES_INDIRECTOS.
];

echo "=== (A) cobertura del catálogo ===\n";

$apiDir  = dirname(__DIR__);
$gateados = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $path = $f->getPathname();
    // El catálogo y RoleService NOMBRAN todas las claves (definición y seed
    // defaults) — contarlas como gate daría cobertura 100% falsa.
    if (str_contains($path, '/lib/Auth/PermissionCatalog.php')) continue;
    if (str_contains($path, '/lib/Auth/RoleService.php')) continue;
    if (str_contains($path, '/database/migrations/')) continue;
    if (str_contains($path, '/tests/')) continue;

    $src = file_get_contents($path);
    // `OperatorContext::can(` es la tercera puerta de autorización del sistema
    // (además de hasPermission/contactsRequire): la que evalúa el permiso
    // contra el rol de la PERSONA que opera la caja y no contra el del device.
    // Sin incluirla acá, un archivo que solo use esa vía se saltea el scan
    // entero y sus claves figuran como no gateadas.
    if (!str_contains($src, 'hasPermission(')
        && !str_contains($src, 'contactsRequire(')
        && !str_contains($src, 'OperatorContext::can(')) continue;
    $rel = str_replace($apiDir . '/', '', $path);

    // La clave cuenta como gateada solo si aparece como ARGUMENTO LITERAL de
    // hasPermission(). Con el `str_contains($src, "'$id'")` anterior alcanzaba
    // con nombrarla en un comentario, en un mensaje de error o en una lista de
    // documentación dentro de un archivo que usara hasPermission() en otra
    // línea — cobertura verde sin ningún gate real.
    foreach (PermissionCatalog::ids() as $id) {
        if (preg_match('/hasPermission\\(\\s*[\'"]' . preg_quote($id, '/') . '[\'"]\\s*\\)/', $src)) {
            $gateados[$id][] = $rel;
        }
    }

    // Gates INDIRECTOS: la clave se arma por concatenación o sale de un mapa /
    // de un ternario, y a hasPermission() le llega una variable. No se pueden
    // matchear por el patrón de arriba, así que cada uno se declara por el
    // literal EXACTO del código que lo construye. Si ese código cambia el
    // literal deja de matchear y el check de cobertura lo denuncia — que es
    // precisamente el aviso que se quiere.
    foreach (GATES_INDIRECTOS as $literal => $ids) {
        if (!str_contains($src, $literal)) continue;
        foreach ($ids as $id) { $gateados[$id][] = $rel; }
    }
}

foreach (PermissionCatalog::ids() as $id) {
    $tiene = isset($gateados[$id]);
    $excusa = EXCEPCIONES_CONOCIDAS[$id] ?? null;
    if ($excusa !== null) {
        check("catálogo: $id sin gate a propósito ($excusa)", !$tiene,
            "está gateada pero figura como excepción — sacala de EXCEPCIONES_CONOCIDAS", $failures, $checks);
        continue;
    }
    check("catálogo: $id gateada", $tiene,
        "la clave existe en PermissionCatalog y no la chequea ningún endpoint — es un bug de seguridad", $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// Roles de prueba
// ═══════════════════════════════════════════════════════════════════════════
RoleService::seedCompanyRoles($companyId);

/** Borra y recrea un rol custom con exactamente $perms. */
function makeRole(string $name, array $perms, string $companyId, string $creator): string
{
    $row = ncmExecute(
        "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND LOWER(taxonomyname)=LOWER(?)",
        [$companyId, $name]
    );
    if ($row && !empty($row['taxonomyid'])) {
        $id = (string) $row['taxonomyid'];
        RoleService::updateRole($id, null, $perms, $companyId);
        return $id;
    }
    return RoleService::createRole($name, $perms, $companyId, $creator);
}

function ownerRoleId(string $companyId): string
{
    $row = ncmExecute(
        "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'='owner'",
        [$companyId]
    );
    return (string) ($row['taxonomyid'] ?? '');
}

// ── Matriz de casos ────────────────────────────────────────────────────────
// [clave, etiqueta, endpointRel, método, query, body]
// El caso "permitido" NO tiene que terminar 200: alcanza con que NO sea el
// 403 del gate. Varios están armados para fallar 404/422 después del gate a
// propósito, así el arnés no muta datos del fixture.
$CASOS = [
    ['contacts.user.view',       'users GET lista',             'v1/users.php',              'GET',    '',                              []],
    ['contacts.user.manage',     'users POST alta',             'v1/users.php',              'POST',   '',                              ['name' => '']],
    ['contacts.user.manage',     'users PUT edición',           'v1/users.php',              'PUT',    'id=00000000-0000-0000-0000-0000000000ff', ['name' => 'x']],
    ['contacts.user.manage',     'users DELETE baja',           'v1/users.php',              'DELETE', 'id=00000000-0000-0000-0000-0000000000ff', []],
    ['contacts.user.manage',     'users GET roles',             'v1/users.php',              'GET',    'resource=roles',                []],

    ['contacts.customer.view',   'contacts GET clientes',       'v1/contacts.php',           'GET',    'type=1',                        []],
    ['contacts.customer.create', 'contacts POST cliente',       'v1/contacts.php',           'POST',   '',                              ['type' => 1, 'name' => '']],
    ['contacts.customer.edit',   'contacts PUT cliente',        'v1/contacts.php',           'PUT',    'id=__CLIENTE__',                ['name' => 'Verify PY Cliente sin credito']],
    ['contacts.customer.delete', 'contacts DELETE cliente',     'v1/contacts.php',           'DELETE', 'id=00000000-0000-0000-0000-0000000000ff', []],
    ['contacts.supplier.view',   'contacts GET proveedores',    'v1/contacts.php',           'GET',    'type=2',                        []],
    ['contacts.supplier.manage', 'contacts POST proveedor',     'v1/contacts.php',           'POST',   '',                              ['type' => 2, 'name' => '']],

    ['inventory.item.view',      'items GET catálogo',          'v1/items.php',              'GET',    'limit=1',                       []],
    ['inventory.item.create',    'items POST alta',             'v1/items.php',              'POST',   '',                              ['kind' => 'basic']],
    ['inventory.item.edit',      'items PUT edición',           'v1/items.php',              'PUT',    'id=00000000-0000-0000-0000-0000000000ff', ['itemName' => 'x']],
    ['inventory.item.delete',    'items DELETE baja',           'v1/items.php',              'DELETE', 'id=00000000-0000-0000-0000-0000000000ff', []],

    ['pos.sale.refund',          'returns POST devolución',     'v1/returns.php',            'POST',   '',                              ['action' => 'create']],

    ['settings.tax.manage',      'taxes POST alta',             'v1/taxes.php',              'POST',   '',                              ['name' => '']],
    ['settings.tax.manage',      'taxes DELETE baja',           'v1/taxes.php',              'DELETE', 'id=00000000-0000-0000-0000-0000000000ff', []],
    ['settings.template.manage', 'document-templates POST',     'v1/document-templates.php', 'POST',   '',                              ['name' => '']],
    ['settings.template.manage', 'document-templates DELETE',   'v1/document-templates.php', 'DELETE', 'id=00000000-0000-0000-0000-0000000000ff', []],
    // Módulos: hasta 2026-08-28 CUALQUIER sesión de panel prendía o apagaba un
    // módulo del comercio sin permiso (P2 de la auditoría de auth).
    ['settings.company.edit',    'modules POST toggle',         'v1/modules.php',            'POST',   '',                              ['action' => 'toggle', 'key' => 'tables', 'enabled' => '0']],
    ['settings.company.edit',    'modules POST config',         'v1/modules.php',            'POST',   '',                              ['action' => 'config', 'key' => 'tables']],
    ['settings.register.manage', 'register POST create',        'v1/register.php',           'POST',   '',                              ['action' => 'create']],
    ['settings.register.manage', 'register PUT config',         'v1/register.php',           'PUT',    'resource=config',               []],
    ['settings.device.manage',   'devices GET lista',           'v1/devices.php',            'GET',    '',                              []],
    ['settings.device.manage',   'devices DELETE revoke',       'v1/devices.php',            'DELETE', 'id=00000000-0000-0000-0000-0000000000ff', []],
    ['settings.device.pair',     'device_invitations GET list', 'v1/device_invitations.php', 'GET',    'resource=list',                 []],
    ['settings.device.pair',     'device_invitations approve',  'v1/device_invitations.php', 'POST',   '',                              ['action' => 'approve']],

    ['billing.view',             'billing GET resumen',         'v1/billing.php',            'GET',    '',                              []],
    ['billing.manage',           'billing POST checkout',       'v1/billing.php',            'POST',   '',                              ['action' => 'checkout']],
];

$permisosBajoPrueba = array_values(array_unique(array_column($CASOS, 0)));

$roleNone = makeRole('permtest-sin-permisos', [], $companyId, $adminId);
$roleAll  = makeRole('permtest-con-permisos', $permisosBajoPrueba, $companyId, $adminId);
$roleOwner = ownerRoleId($companyId);
if ($roleOwner === '') {
    echo "FAIL no se pudo resolver el rol owner del fixture\n";
    exit(1);
}

/** Sesión de panel real para un roleId dado. */
function panelSession(string $roleId, string $companyId, string $outletId, string $userId): string
{
    return authSessionCreate('panel', [
        'companyId' => $companyId,
        'userId'    => $userId,
        'outletId'  => $outletId,
        'roleId'    => $roleId,
        'expiresAt' => date('Y-m-d H:i:s', time() + 3600),
        // Sin esto el userAgent queda NULL bajo CLI y el DELETE de limpieza
        // del final (WHERE useragent = AGENTE_DEL_ARNES) no matchea NINGUNA
        // fila: cada corrida dejaba sus sesiones vivas en la BD.
        'userAgent' => AGENTE_DEL_ARNES,
    ]);
}

$tokNone  = panelSession($roleNone,  $companyId, $outletId, $adminId);
$tokAll   = panelSession($roleAll,   $companyId, $outletId, $adminId);
$tokOwner = panelSession($roleOwner, $companyId, $outletId, $adminId);

/**
 * Corre un endpoint en subproceso y devuelve ['status' => int, 'body' => string].
 * El body va por stdin además de por $_POST — ver el docblock del helper.
 */
function hitEndpoint(string $endpointRel, string $method, string $query, array $body, string $cookieTok, string $bearerTok = ''): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $endpointRel, $method, $query, json_encode($body), $cookieTok, $bearerTok,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso'];
    }
    fwrite($pipes[0], json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // El status sale del ENVELOPE canónico, no de http_response_code(): bajo
    // el SAPI cli el getter no devuelve de forma confiable lo que seteó
    // apiError(). El envelope sí es contrato de la API ({ok:false, error.code}
    // / {ok:true}), así que es la fuente correcta acá.
    $status = 0;
    if (preg_match('/BODY:(\{.*?\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
        }
    }
    if ($status === 0 && preg_match('/"code":(\d{3})/', $out, $m)) {
        $status = (int) $m[1];
    }
    if ($status === 0 && str_contains($out, '"ok":true')) {
        $status = 200;
    }
    return ['status' => $status, 'body' => $out . $err];
}

/** True si la respuesta es el 403 del gate de permisos (y no otro 403). */
function esGateDePermiso(array $res, string $perm): bool
{
    return $res['status'] === 403 && str_contains($res['body'], "requiere: $perm");
}

/**
 * `hitEndpoint()` devuelve status=0 cuando el subproceso no imprimió un
 * envelope parseable — o sea, cuando el endpoint reventó ANTES de responder
 * (fatal de PHP, require roto, boot caído). Los checks "rol CON la clave" y
 * "owner" se afirmaban como `!esGateDePermiso(...)`, que con status=0 da
 * true: el arnés pasaba en VERDE con el endpoint completamente roto. Pasar
 * el gate exige las dos cosas — que haya respuesta y que no sea el 403.
 */
function pasaElGate(array $res, string $perm): bool
{
    return $res['status'] !== 0 && !esGateDePermiso($res, $perm);
}

// ═══════════════════════════════════════════════════════════════════════════
// (B) Matriz endpoint × rol
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (B) matriz endpoint × rol (realm panel) ===\n";

foreach ($CASOS as [$perm, $etiqueta, $endpoint, $method, $query, $body]) {
    $query = str_replace('__CLIENTE__', $clienteId, $query);

    $sin = hitEndpoint($endpoint, $method, $query, $body, $tokNone);
    check("[$perm] $etiqueta — rol SIN la clave → 403",
        esGateDePermiso($sin, $perm),
        "status={$sin['status']} body=" . substr(trim($sin['body']), 0, 240),
        $failures, $checks);

    $con = hitEndpoint($endpoint, $method, $query, $body, $tokAll);
    check("[$perm] $etiqueta — rol CON la clave → pasa el gate",
        pasaElGate($con, $perm),
        "el gate rechazó a un rol que SÍ tiene la clave, o el endpoint no respondió. status={$con['status']} body=" . substr(trim($con['body']), 0, 240),
        $failures, $checks);

    $own = hitEndpoint($endpoint, $method, $query, $body, $tokOwner);
    check("[$perm] $etiqueta — owner → pasa el gate",
        pasaElGate($own, $perm),
        "el owner tiene que pasar SIEMPRE, y el endpoint tiene que responder. status={$own['status']} body=" . substr(trim($own['body']), 0, 240),
        $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// (C) Realm pos-app — el rol del DISPOSITIVO
// ═══════════════════════════════════════════════════════════════════════════
//
// El device ya NO se emite con roleId='1' (→ seed owner → hasPermission()
// true incondicional). Lleva el rol seed `device`, y tanto `bootstrap.php`
// (apiAuthTenant) como `DeviceAuth::resolveDeviceToken()` lo RESUELVEN contra
// el tenant en cada request en vez de leer el `roleid` de la sesión — así una
// sesión vieja tampoco opera como owner. Por eso este bloque no inyecta roles:
// inyectar uno no cambiaría nada, y el contrato que hay que fijar es el del
// rol real.
//
// Dos cosas se prueban acá, y las dos son necesarias:
//   (c.1) el PISO alcanza — el device puede hacer todo lo que el POS hace hoy
//         con su Bearer. Si esto se cae, el cambio rompe cajas en producción.
//   (c.2) el TECHO corta — lo que el device NO tiene que poder hacer, no puede.
echo "\n=== (C) realm pos-app: el rol del dispositivo ===\n";

$deviceId = '9f2c77aa-0000-4000-8000-0000000000d1';
ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$deviceId], true);
ncmExecute(
    'INSERT INTO device (deviceid, companyid, outletid, registerid, userid, module, status, devicename)
     VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, 1, ?)',
    [$deviceId, $companyId, $outletId, $registerId, $adminId, 'pos', 'permtest device'],
    true
);

function deviceSession(string $roleId, string $companyId, string $outletId, string $registerId, string $userId, string $deviceId): string
{
    return authSessionCreate('pos-app', [
        'companyId'  => $companyId,
        'userId'     => $userId,
        'deviceId'   => $deviceId,
        'outletId'   => $outletId,
        'registerId' => $registerId,
        'roleId'     => $roleId,
        'module'     => 'pos',
        'expiresAt'  => null,
        'userAgent'  => AGENTE_DEL_ARNES, // ver panelSession()
    ]);
}

$roleDevice = RoleService::deviceRoleId($companyId);
check('el tenant tiene el rol seed `device`', $roleDevice !== '',
    'DeviceAuth y bootstrap lo necesitan para emitir/resolver la sesión pos-app', $failures, $checks);

$permsDevice = RoleService::getPermissions($roleDevice, $companyId);
check('el rol `device` NO es owner (no tiene el catálogo completo)',
    count($permsDevice) < count(PermissionCatalog::ids()),
    'si tiene todo el catálogo volvimos al roleId=1 → owner: los gates del realm pos-app son letra muerta',
    $failures, $checks);

// (c.1) El PISO — toda clave que gatea algo que el POS consume con el Bearer
// del device. La lista se derivó endpoint por endpoint del inventario de
// `frontend/app/api/pos/*` + `lib/api/pos-client.ts`; si alguien recorta el
// piso sin mirar, esto se pone rojo ANTES de romper una caja.
foreach ([
    'pos.sale.void'            => 'sales-void.php + transactions.php?resource=void (anular desde la caja)',
    'pos.sale.refund'          => 'returns.php action=create (devolución)',
    'pos.sale.creditPayment'   => 'credit-payments.php (cobro de crédito al cliente)',
    'pos.drawer.open'          => 'drawer.php action=open',
    'pos.drawer.close'         => 'drawer.php action=close',
    'finance.manage'           => 'drawer.php action=expense|income (extracción/ingreso de efectivo)',
    'inventory.item.view'      => 'items.php GET + resource=bulk-get (catálogo y sync)',
    'contacts.customer.view'   => 'contacts.php GET type=1 + bulk-get',
    'contacts.customer.create' => 'contacts.php POST type=1 (alta de cliente en el mostrador)',
    'contacts.customer.edit'   => 'contacts.php PUT type=1',
    'reports.sales.view'       => 'returns.php action=returnOptions|listForParent + detalle de venta',
    'settings.register.manage' => 'register.php PUT resource=hotkeys|config (su propia caja)',
] as $clave => $paraQue) {
    check("piso del rol `device`: $clave — $paraQue",
        in_array($clave, $permsDevice, true),
        'sin esta clave el POS recibe 403 en producción', $failures, $checks);
}

// (c.2) El TECHO — lo que una terminal del mostrador no tiene que poder.
foreach ([
    'contacts.user.view', 'contacts.user.manage',
    'contacts.supplier.view', 'contacts.supplier.manage',
    'contacts.customer.delete',
    'inventory.item.create', 'inventory.item.edit', 'inventory.item.delete',
    'settings.template.manage', 'settings.tax.manage', 'settings.outlet.manage',
    'settings.role.manage', 'settings.device.manage', 'settings.device.pair',
    'settings.company.edit', 'billing.view', 'billing.manage',
    'ai.agent.use', 'ai.agent.elevated', 'production.manage', 'einvoice.manage',
] as $clave) {
    check("techo del rol `device`: NO tiene $clave", !in_array($clave, $permsDevice, true),
        'el token del device es eterno y vive en el localStorage de una tablet del mostrador',
        $failures, $checks);
}

// El pareo REAL: DeviceAuth tiene que emitir la sesión con el rol `device`, no
// con roleId='1'. Los checks de arriba prueban qué PUEDE el rol; este prueba
// que sea el que efectivamente se emite — sin él, cambiar SEED_PERMISSIONS
// daría verde mientras DeviceAuth sigue emitiendo tokens de Dueño.
// El $secret ya no se usa para nada (las sesiones son tokens opacos desde el
// auth rewrite): solo hay un guard de "no arranques sin configurarlo".
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
if (($_ENV['JWT_SECRET'] ?? '') === '') {
    $_ENV['JWT_SECRET'] = 'permission-enforcement-test';
}
$pareo = \Punto\Api\Auth\DeviceAuth::createDeviceAndIssueToken(
    $companyId, $outletId, $registerId, $adminId, 'permtest pareo', AGENTE_DEL_ARNES
);
$sesionPareada = ncmExecute(
    'SELECT roleid FROM auth_session WHERE tokenhash = ?',
    [authHashToken((string) $pareo['token'])]
);
check('DeviceAuth emite la sesión pos-app con el rol `device` (no roleId=1)',
    (string) ($sesionPareada['roleid'] ?? '') === $roleDevice,
    'roleid emitido=' . (string) ($sesionPareada['roleid'] ?? '(ninguno)') . " esperado=$roleDevice",
    $failures, $checks);
ncmExecute('UPDATE device SET status = 0 WHERE deviceid = ?::uuid', [(string) $pareo['deviceId']], true);

$devTok = deviceSession($roleDevice, $companyId, $outletId, $registerId, $adminId, $deviceId);

// El drawer completo, con el rol real del device.
foreach ([
    ['pos.drawer.open',  'open'],
    ['pos.drawer.close', 'close'],
    ['finance.manage',   'expense'],
    ['finance.manage',   'income'],
] as [$perm, $accion]) {
    $res = hitEndpoint('v1/drawer.php', 'POST', '', ['action' => $accion, 'amount' => 0], '', $devTok);
    check("[$perm] drawer POST $accion — el device pasa el gate",
        pasaElGate($res, $perm),
        "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
        $failures, $checks);
}

// Fail-closed del mapa de acciones: una acción que no está en $drawerPerm no
// puede colarse al switch de abajo sin haber pasado por ningún permiso.
$res = hitEndpoint('v1/drawer.php', 'POST', '', ['action' => 'inventada', 'amount' => 0], '', $devTok);
check('drawer: acción fuera del mapa de permisos → 400 antes del switch',
    $res['status'] === 400,
    "una acción sin clave asignada no puede pasar el gate. status={$res['status']} body="
        . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// El device SÍ lee el catálogo, y NO lo escribe (guard de realm de items.php).
$res = hitEndpoint('v1/items.php', 'GET', 'limit=1', [], '', $devTok);
check('device: GET /v1/items → pasa el gate',
    pasaElGate($res, 'inventory.item.view'),
    "el POS necesita leer el catálogo. status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$res = hitEndpoint('v1/items.php', 'POST', '', ['kind' => 'basic'], '', $devTok);
check('device: POST /v1/items (alta de ítem) → 403',
    $res['status'] === 403,
    "la administración del catálogo es del panel. status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// El device SÍ opera clientes.
$res = hitEndpoint('v1/contacts.php', 'GET', 'type=1', [], '', $devTok);
check('device: GET /v1/contacts?type=1 → pasa el gate',
    pasaElGate($res, 'contacts.customer.view'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$res = hitEndpoint('v1/contacts.php', 'PUT', 'id=' . $clienteId,
    ['name' => 'Verify PY Cliente sin credito'], '', $devTok);
check('device: PUT /v1/contacts sobre un CLIENTE → pasa el gate',
    pasaElGate($res, 'contacts.customer.edit'),
    "el alta/edición de clientes en el mostrador tiene que seguir andando. status={$res['status']} body="
        . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (C2) P0 — un device no puede tocar a los EMPLEADOS del comercio
// ═══════════════════════════════════════════════════════════════════════════
//
// La cadena completa que esto cierra: `/v1/contacts` acepta el realm `pos-app`
// → el gate de PUT resuelve `contacts.user.manage` para un contacto `type=0`
// → la sesión del device se emitía con roleId='1' → LEGACY_MAP lo mapea a
// `owner` → hasPermission() true incondicional → `ContactService::mapToColumns`
// mapea `phone` a la columna `contactPhone` → `/v1/login.php` autentica por
// `contactPhone AND type=0`. O sea: el device le cambiaba el teléfono de login
// al Dueño y se quedaba con el comercio. El `contactId` del owner lo publica
// `/v1/bootstrap.php`, así que ni siquiera había que adivinarlo.
//
// Dos capas lo cierran, y las dos se prueban: el rol `device` no tiene
// `contacts.user.manage`, y `contactsRequire()` corta por REALM antes de
// mirar permisos (para que siga cerrado aunque alguien le agregue la clave al
// rol desde el panel).
echo "\n=== (C2) el device no toca a los empleados del comercio ===\n";

$ownerRow = ncmExecute(
    "SELECT contactid, contactphone FROM contact
      WHERE companyid = ? AND type = 0 AND contactstatus > 0
      ORDER BY main DESC NULLS LAST LIMIT 1",
    [$companyId]
);
$empleadoId  = (string) ($ownerRow['contactid'] ?? '');
$telefonoPre = (string) ($ownerRow['contactphone'] ?? '');
check('fixture: hay un contacto type=0 (empleado/dueño) para atacar',
    $empleadoId !== '', 'sin esto los casos del P0 no prueban nada', $failures, $checks);

// (a) PUT sobre un contacto type=0 desde el device
$res = hitEndpoint('v1/contacts.php', 'PUT', 'id=' . $empleadoId, ['name' => 'Tomado'], '', $devTok);
check('P0 (a): device PUT /v1/contacts sobre un type=0 → 403',
    $res['status'] === 403,
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (b) el ataque concreto: reescribirle el teléfono de login al Dueño
$res = hitEndpoint('v1/contacts.php', 'PUT', 'id=' . $empleadoId, ['phone' => '595999000111'], '', $devTok);
check('P0 (b): device cambiando el contactPhone del owner → 403',
    $res['status'] === 403,
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$post = ncmExecute('SELECT contactphone FROM contact WHERE contactid = ?::uuid', [$empleadoId]);
check('P0 (b): el teléfono de login del owner quedó intacto',
    (string) ($post['contactphone'] ?? '') === $telefonoPre,
    'si cambió, el 403 llegó tarde y el UPDATE ya se había hecho', $failures, $checks);

// El mismo corte para las otras dos operaciones sobre type=0. La asimetría
// original era exactamente esta: el guard de realm existía SOLO en DELETE.
$res = hitEndpoint('v1/contacts.php', 'GET', 'id=' . $empleadoId, [], '', $devTok);
check('P0: device GET /v1/contacts?id=<type=0> → 403',
    $res['status'] === 403,
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$res = hitEndpoint('v1/contacts.php', 'DELETE', 'id=' . $empleadoId, [], '', $devTok);
check('P0: device DELETE /v1/contacts?id=<type=0> → 403',
    $res['status'] === 403,
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// Y desde el PANEL, un rol sin contacts.user.manage tampoco: el gate de
// permiso sigue vivo, no lo reemplazó el de realm.
$res = hitEndpoint('v1/contacts.php', 'PUT', 'id=' . $empleadoId, ['name' => 'Tomado'], $tokNone);
check('P0: panel sin contacts.user.manage, PUT sobre un type=0 → 403 del gate',
    esGateDePermiso($res, 'contacts.user.manage'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// Los sub-recursos del perfil resolvían el type del `?type=` del CALLER, no de
// la fila: pasando ?type=1 sobre el id de un empleado el gate se evaluaba
// contra la familia de clientes y ni el permiso ni el guard de realm llegaban
// a mirar que el contacto era type=0.
foreach (["analytics", "statement"] as $sub) {
    $res = hitEndpoint('v1/contacts.php', 'GET', "id=$empleadoId&resource=$sub&type=1", [], '', $devTok);
    check("P0: device GET /v1/contacts?resource=$sub sobre un type=0 (con ?type=1) → 403",
        $res['status'] === 403,
        "el type tiene que salir de la fila, no del query. status={$res['status']} body="
            . substr(trim($res['body']), 0, 240),
        $failures, $checks);
}

// Oráculo de existencia: sin permiso, un id que NO existe tiene que dar el
// mismo 403 que uno que sí — nunca 404 (si no, se enumera la cartera).
$res = hitEndpoint('v1/contacts.php', 'GET', 'id=00000000-0000-0000-0000-0000000000ff', [], $tokNone);
check('contacts: el detalle por id gatea ANTES del 404 (no es oráculo de existencia)',
    $res['status'] === 403,
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (D) Escalación de privilegios en /v1/users
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (D) escalación de privilegios (/v1/users) ===\n";

// Rol "encargado de prueba": puede gestionar usuarios, pero no es owner.
$roleManagerish = makeRole('permtest-gestor-usuarios', [
    'contacts.user.view', 'contacts.user.manage', 'contacts.customer.view',
], $companyId, $adminId);
$tokManagerish = panelSession($roleManagerish, $companyId, $outletId, $adminId);

// (d.1) crear un usuario con rol owner
$res = hitEndpoint('v1/users.php', 'POST', '', ['name' => 'Escalador', 'roleId' => $roleOwner], $tokManagerish);
check('escalación: crear usuario con rol owner → 403',
    $res['status'] === 403 && str_contains($res['body'], 'más permisos que el tuyo'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (d.2) crear un usuario con un rol que tiene UNA clave de más
$roleUnaMas = makeRole('permtest-una-clave-mas', [
    'contacts.user.view', 'contacts.user.manage', 'contacts.customer.view', 'settings.company.edit',
], $companyId, $adminId);
$res = hitEndpoint('v1/users.php', 'POST', '', ['name' => 'Escalador', 'roleId' => $roleUnaMas], $tokManagerish);
check('escalación: crear usuario con un rol de UNA clave más → 403',
    $res['status'] === 403 && str_contains($res['body'], 'settings.company.edit'),
    "el mensaje tiene que nombrar la clave que falta. status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (d.3) crear un usuario con un rol de permisos IGUALES o menores → pasa el gate
$res = hitEndpoint('v1/users.php', 'POST', '', ['name' => '', 'roleId' => $roleManagerish], $tokManagerish);
check('escalación: crear usuario con el MISMO rol → pasa el gate',
    !($res['status'] === 403 && str_contains($res['body'], 'más permisos que el tuyo')),
    "asignar el rol propio es legítimo. status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (d.4) editar al owner del tenant (sin tocar su rol)
$ownerContact = ncmExecute(
    "SELECT contactid FROM contact WHERE companyid=? AND type=0 AND contactstatus>0 AND role=? LIMIT 1",
    [$companyId, $roleOwner]
);
$ownerContactId = (string) ($ownerContact['contactid'] ?? '');
if ($ownerContactId === '') {
    // El fixture guarda role='1' (int legacy) — resolvemos por ahí.
    ncmExecute('UPDATE contact SET role = ? WHERE contactid = ?::uuid', [$roleOwner, $adminId], true);
    $ownerContactId = $adminId;
}
$res = hitEndpoint('v1/users.php', 'PUT', 'id=' . $ownerContactId, ['name' => 'Hackeado'], $tokManagerish);
check('escalación: editar al usuario owner → 403',
    $res['status'] === 403 && str_contains($res['body'], 'más permisos que el tuyo'),
    "un no-owner no puede editar (ni resetear la clave de) el owner. status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (d.5) desactivar al owner del tenant
$res = hitEndpoint('v1/users.php', 'DELETE', 'id=' . $ownerContactId, [], $tokManagerish);
check('escalación: desactivar al usuario owner → 403',
    $res['status'] === 403 && str_contains($res['body'], 'más permisos que el tuyo'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (d.6) cambiarse el rol a uno mismo
$selfId = '7c1d55bb-0000-4000-8000-0000000000e1';
ncmExecute('DELETE FROM contact WHERE contactid = ?::uuid', [$selfId], true);
ncmExecute(
    'INSERT INTO contact (contactid, contactname, contactphone, contactemail, contactstatus, type, main, role, outletid, companyid)
     VALUES (?::uuid, ?, ?, ?, 1, 0, ?, ?, ?::uuid, ?::uuid)',
    [$selfId, 'Permtest Gestor', '+595991000099', 'permtest-gestor@local.test', '', $roleManagerish, $outletId, $companyId],
    true
);
$tokSelf = panelSession($roleManagerish, $companyId, $outletId, $selfId);
$res = hitEndpoint('v1/users.php', 'PUT', 'id=' . $selfId, ['roleId' => $roleManagerish], $tokSelf);
check('escalación: cambiarse el rol a uno mismo → 403',
    $res['status'] === 403 && str_contains($res['body'], 'tu propio rol'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (d.7) el owner sí puede asignar el rol owner
$res = hitEndpoint('v1/users.php', 'POST', '', ['name' => '', 'roleId' => $roleOwner], $tokOwner);
check('escalación: el owner SÍ puede asignar el rol owner',
    !($res['status'] === 403),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (E) La regla anti-escalación es COMPARTIDA, no de /v1/users
// ═══════════════════════════════════════════════════════════════════════════
//
// El equipo del tenant se muta por dos puertas: /v1/users y la acción
// `create_user` del agente IA. La segunda resolvía el rol POR NOMBRE contra
// una lista negra de tres strings que NO incluye "Dueño" (el nombre del seed
// owner), así que pedirle al agente un usuario con rol Dueño salteaba el
// guard entero. La regla vive ahora en RoleEscalation y las dos puertas la
// usan; acá se prueba la regla y que la segunda puerta la invoque.
echo "\n=== (E) regla anti-escalación compartida ===\n";

$extra = RoleEscalation::extraPermissions($roleOwner, $roleManagerish, $companyId);
check('RoleEscalation: owner tiene permisos que el gestor no → diff no vacío',
    !empty($extra),
    'el diff tiene que listar lo que le falta al caller', $failures, $checks);

check('RoleEscalation: el mismo rol contra sí mismo → diff vacío',
    empty(RoleEscalation::extraPermissions($roleManagerish, $roleManagerish, $companyId)),
    'asignar el rol propio no escala', $failures, $checks);

check('RoleEscalation: el owner puede asignar cualquier rol → diff vacío',
    empty(RoleEscalation::extraPermissions($roleOwner, $roleOwner, $companyId)),
    'el owner tiene el catálogo completo', $failures, $checks);

check('RoleEscalation: rol inexistente → diff vacío (no puede escalar)',
    empty(RoleEscalation::extraPermissions('00000000-0000-0000-0000-0000000000ff', $roleManagerish, $companyId)),
    'un rol que no existe no otorga nada', $failures, $checks);

$lanzo = false;
try {
    // caller explícito: en el proceso del arnés ROLE_ID es el owner del fixture.
    RoleEscalation::guardOrThrow($roleOwner, $companyId, 'crear un usuario con', $roleManagerish);
} catch (\InvalidArgumentException $e) {
    $lanzo = str_contains($e->getMessage(), 'más permisos que el tuyo');
}
check('RoleEscalation::guardOrThrow lanza en vez de cortar la response',
    $lanzo,
    'el ejecutor del agente corre en un loop con try/catch y no puede hacer exit', $failures, $checks);

$aiSrc = file_get_contents(dirname(__DIR__) . '/v1/ai/execute.php');
check('ai/execute.php create_user pasa por RoleEscalation',
    str_contains($aiSrc, 'RoleEscalation::guardOrThrow'),
    'la acción create_user del agente asigna un rol y tiene que aplicar la MISMA regla que /v1/users', $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (F) Escalación por la puerta de los ROLES, y el import que era un UPDATE
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (F) /v1/roles y /v1/items?resource=import ===\n";

// `settings.role.manage` sin control de escalación era la clave maestra del
// tenant: /v1/roles no invocaba RoleEscalation en ninguna de sus tres puertas,
// así que un PATCH sobre el rol PROPIO le agregaba las 47 claves del catálogo
// sin asignarle un rol a nadie — y el guard "no cambiás tu propio rol" de
// users.php nunca se enteraba, porque ningún usuario cambió de rol.
$roleEditor = makeRole('permtest-editor-roles', [
    'settings.role.manage', 'contacts.customer.view',
], $companyId, $adminId);
$tokEditor = panelSession($roleEditor, $companyId, $outletId, $adminId);

// (f.1) PATCH del rol PROPIO
$res = hitEndpoint('v1/roles.php', 'PATCH', 'id=' . $roleEditor,
    ['permissions' => ['settings.role.manage', 'contacts.customer.view']], $tokEditor);
check('roles: PATCH del rol PROPIO → 403',
    $res['status'] === 403 && str_contains($res['body'], 'tu propio rol'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (f.2) PATCH de OTRO rol, agregándole claves que el caller no tiene
$roleVictima = makeRole('permtest-rol-victima', ['contacts.customer.view'], $companyId, $adminId);
$res = hitEndpoint('v1/roles.php', 'PATCH', 'id=' . $roleVictima,
    ['permissions' => ['contacts.customer.view', 'settings.company.edit']], $tokEditor);
check('roles: PATCH agregando una clave que el caller no tiene → 403',
    $res['status'] === 403 && str_contains($res['body'], 'settings.company.edit'),
    "el mensaje tiene que nombrar la clave. status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (f.3) POST creando un rol con el catálogo completo
$res = hitEndpoint('v1/roles.php', 'POST', '',
    ['name' => 'permtest-rol-dios', 'permissions' => PermissionCatalog::ids()], $tokEditor);
check('roles: POST creando un rol con el catálogo completo → 403',
    $res['status'] === 403 && str_contains($res['body'], 'que vos no tenés'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (f.4) PATCH legítimo (subset de lo que el caller tiene) sigue pasando
$res = hitEndpoint('v1/roles.php', 'PATCH', 'id=' . $roleVictima,
    ['permissions' => ['contacts.customer.view']], $tokEditor);
check('roles: PATCH con un subset de lo propio → pasa',
    $res['status'] !== 403,
    "recortar un rol con lo que uno mismo tiene es legítimo. status={$res['status']} body="
        . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (f.5) el owner sí puede todo
$res = hitEndpoint('v1/roles.php', 'PATCH', 'id=' . $roleVictima,
    ['permissions' => ['contacts.customer.view', 'settings.company.edit']], $tokOwner);
check('roles: el owner SÍ puede otorgar cualquier clave',
    $res['status'] !== 403,
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (f.6) despojar a un rol MÁS PODEROSO. La escalación por esta puerta va al
// revés y no es menos efectiva: el que tiene settings.role.manage le vacía los
// permisos al Encargado —o al rol custom del otro admin— y queda como el único
// que puede hacer algo, sin haberse otorgado nada a sí mismo.
$res = hitEndpoint('v1/roles.php', 'PATCH', 'id=' . $roleOwner, ['permissions' => []], $tokEditor);
check('roles: PATCH vaciando el rol owner → 403',
    $res['status'] === 403,
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$roleFuerte = makeRole('permtest-rol-fuerte', [
    'settings.company.edit', 'settings.outlet.manage', 'contacts.user.manage',
], $companyId, $adminId);
$res = hitEndpoint('v1/roles.php', 'PATCH', 'id=' . $roleFuerte, ['permissions' => []], $tokEditor);
check('roles: PATCH vaciando un rol con claves que el caller no tiene → 403',
    $res['status'] === 403 && str_contains($res['body'], 'más permisos que el tuyo'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$res = hitEndpoint('v1/roles.php', 'DELETE', 'id=' . $roleFuerte, [], $tokEditor);
check('roles: DELETE de un rol con claves que el caller no tiene → 403',
    $res['status'] === 403 && str_contains($res['body'], 'más permisos que el tuyo'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$res = hitEndpoint('v1/roles.php', 'DELETE', 'id=' . $roleEditor, [], $tokEditor);
check('roles: DELETE del rol PROPIO → 403',
    $res['status'] === 403 && str_contains($res['body'], 'tu propio rol'),
    "status={$res['status']} body=" . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// (f.7) import mode=update pide inventory.item.edit, no .create.
// El importador con mode=update hace UPDATE masivo de precios y costos de TODO
// el catálogo; pedía `inventory.item.create`, así que un rol de solo alta lo
// reescribía entero.
$roleSoloAlta = makeRole('permtest-solo-alta', [
    'inventory.item.view', 'inventory.item.create',
], $companyId, $adminId);
$tokSoloAlta = panelSession($roleSoloAlta, $companyId, $outletId, $adminId);

$res = hitEndpoint('v1/items.php', 'POST', 'resource=import', ['mode' => 'update'], $tokSoloAlta);
check('import: mode=update sin inventory.item.edit → 403',
    esGateDePermiso($res, 'inventory.item.edit'),
    "un rol de solo alta no puede reescribir el catálogo entero por CSV. status={$res['status']} body="
        . substr(trim($res['body']), 0, 240),
    $failures, $checks);

$res = hitEndpoint('v1/items.php', 'POST', 'resource=import', ['mode' => 'insert'], $tokSoloAlta);
check('import: mode=insert con inventory.item.create → pasa el gate',
    pasaElGate($res, 'inventory.item.create'),
    "el alta por CSV sigue siendo del rol de alta. status={$res['status']} body="
        . substr(trim($res['body']), 0, 240),
    $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (G) Las acciones de CONFIGURACIÓN del agente IA (context/66 F1)
// ═══════════════════════════════════════════════════════════════════════════
//
// El agente pasó a poder configurar la cuenta: crear sucursales, crear cajas y
// cambiarle el rol a un usuario existente (D1 del owner, 2026-09-01). Son las
// acciones de más alcance del catálogo —una define la estructura fiscal del
// comercio, la otra reparte accesos— y las tres fallan en silencio si se les
// olvida el gate: `requiredPermission()` devuelve null para una acción sin
// entrada, y `execute.php` interpreta null como "esta acción no tiene gate
// propio" y la deja pasar. O sea que olvidarse de mapearla no rompe nada
// visible: la acción simplemente queda sin control.
echo "\n=== (G) acciones de configuración del agente IA ===\n";

require_once dirname(__DIR__) . '/lib/Ai/AgentActor.php';
$refActor = new ReflectionClass(\Punto\Api\Ai\AgentActor::class);
/** @var array<string,string> $mapaAccionPermiso */
$mapaAccionPermiso = $refActor->getConstant('ACTION_PERMISSION');
/** @var list<string> $bloqueadasEnCaja */
$bloqueadasEnCaja  = $refActor->getConstant('POS_BLOCKED_ACTIONS');
$elevadas          = $refActor->getConstant('ELEVATED_ACTIONS');

foreach ([
    'create_outlet'   => 'settings.outlet.manage',
    // Editar una sucursal exige el MISMO permiso que crearla: es la misma
    // superficie —la estructura del comercio— y partirla en dos claves haría
    // que un rol pudiera renombrar sucursales sin poder crearlas, que no es
    // una distinción que el panel ofrezca ni que nadie haya pedido.
    'update_outlet'   => 'settings.outlet.manage',
    'create_register' => 'settings.register.manage',
    'assign_role'     => 'contacts.user.manage',
] as $accion => $claveEsperada) {
    check("agente: $accion exige $claveEsperada",
        ($mapaAccionPermiso[$accion] ?? null) === $claveEsperada,
        'sin entrada en ACTION_PERMISSION la acción queda sin gate: requiredPermission() devuelve null y execute.php la deja pasar',
        $failures, $checks);
    check("agente: $accion está bloqueada en la caja",
        in_array($accion, $bloqueadasEnCaja, true),
        'configurar el comercio es tarea de dueño desde el panel, no de cajero en el mostrador',
        $failures, $checks);
}

check('agente: assign_role exige además la clave elevada',
    in_array('assign_role', $elevadas, true),
    'cambiarle el rol a alguien reparte accesos igual que crear un usuario — misma superficie, mismo requisito',
    $failures, $checks);

// Las claves que el mapa nombra tienen que EXISTIR en el catálogo: un typo
// ('settings.registers.manage') convierte el gate en un permiso que nadie
// tiene nunca, así que la acción queda muerta en vez de controlada.
foreach ($mapaAccionPermiso as $accion => $clave) {
    check("agente: la clave de $accion existe en el catálogo",
        in_array($clave, PermissionCatalog::ids(), true),
        "'$clave' no está en PermissionCatalog: el gate nunca lo va a cumplir nadie",
        $failures, $checks);
}

// COBERTURA — toda acción del catálogo de `/v1/ai/confirm` tiene su gate. Es
// el check que hace que la PRÓXIMA acción que se agregue sin permiso salga
// roja, en vez de descubrirse cuando alguien la use.
$confirmSrc = file_get_contents(dirname(__DIR__) . '/v1/ai/confirm.php');
preg_match('/AI_CONFIRM_ALLOWED_ACTIONS\s*=\s*\[(.*?)\];/s', (string) $confirmSrc, $m);
preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $m2);
$accionesPermitidas = $m2[1] ?? [];
check('agente: se pudo leer AI_CONFIRM_ALLOWED_ACTIONS de confirm.php',
    count($accionesPermitidas) >= 13,
    'si el parseo falla los checks de cobertura de abajo dan verde sin mirar nada',
    $failures, $checks);

// `tabular_import` es la única sin entrada fija: su permiso depende de QUÉ se
// importa y lo resuelve `requiredPermission()` del payload.
$sinGateFijo = ['tabular_import'];
foreach ($accionesPermitidas as $accion) {
    if (in_array($accion, $sinGateFijo, true)) {
        continue;
    }
    check("agente: la acción $accion tiene permiso mapeado",
        isset($mapaAccionPermiso[$accion]),
        'toda acción registrable tiene que exigir el permiso que la persona necesitaría para hacerla a mano',
        $failures, $checks);
}

// `assign_role` muta el equipo del comercio: aplica los MISMOS dos guards que
// /v1/users PUT (el rol ACTUAL del target y el rol que se asigna). Sin el
// primero, un Encargado le cambia el rol al Dueño y se queda con el comercio.
$aiExecSrc  = (string) file_get_contents(dirname(__DIR__) . '/v1/ai/execute.php');
$posAssign  = strpos($aiExecSrc, "case 'assign_role':");
$posOutlet  = strpos($aiExecSrc, "case 'create_outlet':");
$casoAssign = ($posAssign !== false && $posOutlet !== false && $posOutlet > $posAssign)
    ? substr($aiExecSrc, $posAssign, $posOutlet - $posAssign)
    : '';
check('ai/execute.php: el case assign_role se pudo aislar',
    $casoAssign !== '',
    'sin aislarlo, los checks de abajo matchearían el guard de create_user y darían verde de más',
    $failures, $checks);
check('ai/execute.php assign_role aplica RoleEscalation al rol ACTUAL y al NUEVO',
    substr_count($casoAssign, 'RoleEscalation::guardOrThrow') === 2,
    'son dos direcciones distintas de escalación y ninguna implica a la otra',
    $failures, $checks);
check('ai/execute.php assign_role no deja cambiarse el rol propio',
    str_contains($casoAssign, 'No podés cambiar tu propio rol'),
    'la misma regla que /v1/users PUT: cambiarse el rol propio es la vía más corta a la escalación',
    $failures, $checks);
check('ai/execute.php: la lista negra de roles admin vive en el resolver compartido',
    str_contains($aiExecSrc, "'super admin', 'admin', 'administrador'")
        && str_contains($aiExecSrc, 'function aiResolveRoleByName'),
    'create_user y assign_role no pueden tener cada uno su propia idea de qué rol es admin',
    $failures, $checks);
// El catálogo de roles del tenant es el de RoleService (tabla `taxonomy`).
// `UsersService::roles()` devuelve una const de UN elemento: resolver contra
// ella hacía que ningún rol real del comercio existiera para el agente.
check('ai/execute.php: los roles se resuelven contra el catálogo real del tenant',
    str_contains($aiExecSrc, 'RoleService::getRoles($companyId)'),
    'UsersService::roles() es una const de un solo elemento — con ella ningún rol del comercio resuelve',
    $failures, $checks);

// ── Limpieza de lo que creó el arnés ──────────────────────────────────────
ncmExecute('DELETE FROM auth_session WHERE companyid = ?::uuid AND useragent = ?', [$companyId, AGENTE_DEL_ARNES], true);
ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$deviceId], true);
ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [(string) $pareo['deviceId']], true);
ncmExecute('DELETE FROM contact WHERE contactid = ?::uuid', [$selfId], true);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n";
harnessFinish($failures, $checks);
