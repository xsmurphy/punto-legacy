<?php
declare(strict_types=1);

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
 *   (C) Realm `pos-app` con roleId controlado: prueba que los gates de la
 *       caja (drawer) discriminan cuando el rol es real. Hoy en producción
 *       el device se emite con roleId='1' (owner) y pasan siempre — este
 *       bloque fija el comportamiento para cuando eso cambie.
 *   (D) Escalación de privilegios en /v1/users.
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
    if (!str_contains($src, 'hasPermission(') && !str_contains($src, 'contactsRequire(')) continue;
    $rel = str_replace($apiDir . '/', '', $path);

    foreach (PermissionCatalog::ids() as $id) {
        if (str_contains($src, "'$id'")) { $gateados[$id][] = $rel; }
    }
    // contacts.php arma la familia de clientes por concatenación.
    if (str_contains($src, "'contacts.customer.' . \$op")) {
        foreach (['view', 'create', 'edit', 'delete'] as $op) {
            $gateados["contacts.customer.$op"][] = $rel;
        }
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
        !esGateDePermiso($con, $perm),
        "el gate rechazó a un rol que SÍ tiene la clave. status={$con['status']} body=" . substr(trim($con['body']), 0, 240),
        $failures, $checks);

    $own = hitEndpoint($endpoint, $method, $query, $body, $tokOwner);
    check("[$perm] $etiqueta — owner → pasa el gate",
        !esGateDePermiso($own, $perm),
        "el owner tiene que pasar SIEMPRE. status={$own['status']} body=" . substr(trim($own['body']), 0, 240),
        $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// (C) Realm pos-app con roleId controlado — gates de la caja
// ═══════════════════════════════════════════════════════════════════════════
//
// En producción el device se emite con roleId='1' → seed owner → estos gates
// pasan siempre (ver DeviceAuth::buildToken). Acá se emite la sesión pos-app
// con un roleId REAL para probar que el gate discrimina: es lo que va a pasar
// el día que el POS tenga sesión de operador, y fija el contrato ahora.
echo "\n=== (C) gates de caja con rol real (realm pos-app) ===\n";

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
    ]);
}

$devNone  = deviceSession($roleNone, $companyId, $outletId, $registerId, $adminId, $deviceId);
$devDraw  = deviceSession(
    makeRole('permtest-caja', ['pos.drawer.open', 'pos.drawer.close'], $companyId, $adminId),
    $companyId, $outletId, $registerId, $adminId, $deviceId
);
$devOwner = deviceSession($roleOwner, $companyId, $outletId, $registerId, $adminId, $deviceId);

foreach ([['pos.drawer.open', 'open'], ['pos.drawer.close', 'close']] as [$perm, $accion]) {
    $body = ['action' => $accion, 'amount' => 0];

    $sin = hitEndpoint('v1/drawer.php', 'POST', '', $body, '', $devNone);
    check("[$perm] drawer POST $accion — rol SIN la clave → 403",
        esGateDePermiso($sin, $perm),
        "status={$sin['status']} body=" . substr(trim($sin['body']), 0, 240),
        $failures, $checks);

    $con = hitEndpoint('v1/drawer.php', 'POST', '', $body, '', $devDraw);
    check("[$perm] drawer POST $accion — rol CON la clave → pasa el gate",
        !esGateDePermiso($con, $perm),
        "status={$con['status']} body=" . substr(trim($con['body']), 0, 240),
        $failures, $checks);

    $own = hitEndpoint('v1/drawer.php', 'POST', '', $body, '', $devOwner);
    check("[$perm] drawer POST $accion — owner → pasa el gate",
        !esGateDePermiso($own, $perm),
        "status={$own['status']} body=" . substr(trim($own['body']), 0, 240),
        $failures, $checks);
}

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

// ── Limpieza de lo que creó el arnés ──────────────────────────────────────
ncmExecute('DELETE FROM auth_session WHERE companyid = ?::uuid AND useragent = ?', [$companyId, 'permission-enforcement-test'], true);
ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$deviceId], true);
ncmExecute('DELETE FROM contact WHERE contactid = ?::uuid', [$selfId], true);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n";
echo "checks: $checks   fallas: $failures\n";
if ($failures > 0) {
    echo "PERMISSION ENFORCEMENT TEST: FALLÓ\n";
    exit(1);
}
echo "PERMISSION ENFORCEMENT TEST: OK\n";
exit(0);
