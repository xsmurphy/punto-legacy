<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Lecturas del realm /admin — el listado de tenants, el semáforo de salud y
 * los planes (rotos en prod el 2026-08-25, los tres a la vez).
 *
 * ── Los tres defectos que cierra ────────────────────────────────────────────
 *
 *   1. `CompanyAdminService` resolvía al propietario con `role = 1` (entero).
 *      `contact.role` es varchar(64) desde la mig 58, así que Postgres cortaba
 *      con 42883 "operator does not exist: character varying = integer" y
 *      /admin/companies quedaba VACÍO — lista y ficha. El predicado además
 *      estaba duplicado en cinco queries y tres de ellas sólo miraban el '1'
 *      legacy: un tenant cuyo dueño tiene rol UUID (todo lo creado post-mig 58)
 *      figuraba sin dueño aunque la query no reventara.
 *   2. `TenantHealthService` leía `orderspanel, tables, production, moduleData`
 *      como COLUMNAS de `company`. No lo son: viven dentro del JSONB `config`.
 *      42703 → sin semáforo y sin ordenar por riesgo.
 *   3. `PlanAdminService::list()` pasaba `$r->fields` (CaseInsensitiveArray) a
 *      un typehint `array` → TypeError, /admin/plans sin listado.
 *
 * ── Qué se verifica ────────────────────────────────────────────────────────
 *
 *   A. El predicado de propietario es UNO solo (`RoleService::ownerContactSql`)
 *      y califica TODAS sus columnas. Sin calificar, el `companyid` del EXISTS
 *      se resuelve contra `taxonomy` —única tabla del subquery— y la
 *      correlación con el contacto se pierde EN SILENCIO: aceptaría el rol
 *      owner de cualquier tenant. Es un chequeo de texto porque el bug no da
 *      error, da un resultado de más.
 *   B. Dueño con `role='1'` legacy → aparece en el listado.
 *   C. Dueño con rol UUID (taxonomy slug='owner') → aparece en el listado.
 *      Éste es el caso que las tres queries con `role='1'` perdían.
 *   D. Un contacto cuyo `role` apunta al rol owner de OTRO tenant NO es dueño
 *      (la correlación del EXISTS, caso A visto desde los datos).
 *   E. `listAll` y `get` no explotan y traen al dueño.
 *   F. El semáforo corre y lee los módulos del JSONB `config`: un tenant con
 *      `ordersPanel` prendido da `breadth.modules.orders.active = true`, y otro
 *      con el módulo sólo en `moduleData` también.
 *   G. `ModuleState` resuelve las tres formas de guardar el estado (key plana,
 *      `moduleData[k].status`, valor directo) y trata el ausente como apagado.
 *   H. `PlanAdminService::list()` devuelve planes (no TypeError).
 *   I. El login del panel autentica por ROL, sin exigir `main = 'true'`: hay
 *      dueños de seeds viejos con `main = 'admin'` y meterles el filtro de
 *      `main` los dejaría afuera del panel. Por eso el predicado son dos:
 *      `ownerRoleSql` (login) y `ownerContactSql` (fichas de /admin).
 *   J. La mig 172 normalizó ese `main = 'admin'` legacy a 'true' — con el dato
 *      sano, el dueño de esos comercios también aparece en la ficha. El caso
 *      queda para que no vuelva a colarse el valor basura sin que nadie mire.
 *   K. La empresa master del seed queda marcada `isInternal = 1` (mig 173): es
 *      la empresa del propio SaaS y las analíticas de /admin la contaban como
 *      un tenant más.
 *   L. "Entrar como empresa" (impersonación) emite una sesión de panel REAL
 *      para el dueño: realm `panel`, la company impersonada, y el `roleId`
 *      textual tal cual. La copia local del emisor lo casteaba a int, así que
 *      el rol UUID de un tenant post-mig 58 se guardaba como un número
 *      inventado — la sesión abría con permisos que no son los del dueño.
 *
 * Uso (un comando, desde la raíz del repo):
 *   bash api/tests/run_admin_tenant_reads_test.sh
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Admin/CompanyAdminService.php';
require_once dirname(__DIR__) . '/lib/Admin/PlanAdminService.php';
require_once dirname(__DIR__) . '/lib/Admin/TenantHealthService.php';

use Punto\Api\Modules\ModuleState;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
    echo "FAIL $label\n";
    if ($detail !== '') {
        echo "     $detail\n";
    }
}

/** Corre $fn y devuelve [resultado, mensajeDeError]. Un throw es dato, no accidente. */
function attempt(callable $fn): array
{
    try {
        return [$fn(), ''];
    } catch (\Throwable $e) {
        return [null, get_class($e) . ': ' . trim(explode("\n", $e->getMessage())[0])];
    }
}

// ── Fixtures propios: dos tenants, cada uno con su forma de marcar al dueño ──
// No se reusa el tenant de verify_chain porque su contacto admin tiene
// main='admin' (seed viejo) y acá hace falta controlar main/role exactamente.
$legacyCo   = '7c1f0a44-0001-4c00-9000-000000000001'; // dueño con role='1'
$modernCo   = '7c1f0a44-0002-4c00-9000-000000000002'; // dueño con rol UUID
$legacyOwn  = '7c1f0a44-1001-4c00-9000-000000000001';
$modernOwn  = '7c1f0a44-1002-4c00-9000-000000000002';
$intruder   = '7c1f0a44-1003-4c00-9000-000000000003'; // en legacyCo, con el rol de modernCo
$modernRole = '7c1f0a44-2002-4c00-9000-000000000002';
$seedOwn    = '7c1f0a44-1004-4c00-9000-000000000004'; // dueño de seed viejo: main='admin'

global $db;

$db->Execute(
    "INSERT INTO company (companyId, status, plan, balance, isParent, config) VALUES
        (?, 'active', 1, 0.00, FALSE, ?::jsonb),
        (?, 'active', 1, 0.00, FALSE, ?::jsonb)
     ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
    [
        $legacyCo, json_encode(['settingName' => 'Admin Reads Legacy', 'ordersPanel' => '1']),
        $modernCo, json_encode(['settingName' => 'Admin Reads Moderno', 'moduleData' => ['ordersPanel' => ['status' => 1]]]),
    ]
);

// Rol owner del tenant moderno (formato post-mig 58: contact.role = taxonomyId).
$db->Execute(
    "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
     VALUES (?, ?, 'role', 'Dueño', ?)
     ON CONFLICT (taxonomyId) DO UPDATE SET taxonomyExtra = EXCLUDED.taxonomyExtra",
    [$modernRole, $modernCo, json_encode(['slug' => 'owner', 'isSeed' => true])]
);

$db->Execute(
    "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, companyId)
     -- contactPhone SIN el '+' inicial: es la convención de storage del proyecto
     -- y lo que espera findPhoneLogin (hace ltrim del '+' de la entrada).
     VALUES (?, 'Dueño Legacy',   '595991900001', 'legacy@local.test',   1, 0, 'true',  '1', ?),
            (?, 'Dueño Moderno',  '595991900002', 'moderno@local.test',  1, 0, 'true',  ?,   ?),
            (?, 'Intruso',        '595991900003', 'intruso@local.test',  1, 0, 'true',  ?,   ?),
            (?, 'Dueño Seed',     '595991900004', 'seed@local.test',     1, 0, 'admin', '1', ?)
     ON CONFLICT (contactId) DO UPDATE SET role = EXCLUDED.role, main = EXCLUDED.main",
    [
        $legacyOwn, $legacyCo,
        $modernOwn, $modernRole, $modernCo,
        $intruder,  $modernRole, $legacyCo, // rol de OTRO tenant → no es dueño
        $seedOwn,   $modernCo,
    ]
);

// ── A. El predicado califica todas sus columnas ─────────────────────────────
$sql = RoleService::ownerContactSql();
check(
    'A1 ownerContactSql califica main/role/companyid con la tabla',
    str_contains($sql, 'contact.main') && str_contains($sql, 'contact.role')
        && str_contains($sql, '= contact.companyid'),
    $sql
);
check(
    'A2 el EXISTS no deja ninguna columna suelta (correlación intacta)',
    !preg_match('/=\s*companyid\b/', $sql) && !preg_match('/=\s*role\b/', $sql),
    $sql
);
check(
    'A5 ownerRoleSql NO mete main (es el predicado del login)',
    !str_contains(RoleService::ownerRoleSql(), 'main'),
    RoleService::ownerRoleSql()
);
$aliased = RoleService::ownerContactSql('c');
check('A3 acepta alias', str_contains($aliased, 'c.main') && str_contains($aliased, '= c.companyid'), $aliased);
[, $err] = attempt(fn() => RoleService::ownerContactSql('c; DROP TABLE contact'));
check('A4 rechaza un alias que no es identificador', $err !== '', 'no lanzó');

// ── B/C/D. Quién es dueño según los datos ───────────────────────────────────
$ownerIds = function (string $companyId): array {
    global $db;
    $r = $db->Execute(
        'SELECT contactId FROM contact WHERE companyId = ? AND type = 0 AND ' . RoleService::ownerContactSql(),
        [$companyId]
    );
    $out = [];
    while ($r && !$r->EOF) {
        $out[] = strtolower((string) ($r->fields['contactid'] ?? ''));
        $r->MoveNext();
    }
    return $out;
};

check('B dueño con role=\'1\' legacy resuelve', $ownerIds($legacyCo) === [$legacyOwn], json_encode($ownerIds($legacyCo)));
check('C dueño con rol UUID (slug=owner) resuelve', $ownerIds($modernCo) === [$modernOwn], json_encode($ownerIds($modernCo)));
check(
    'D el contacto con el rol owner de OTRO tenant NO es dueño',
    !in_array($intruder, $ownerIds($legacyCo), true),
    json_encode($ownerIds($legacyCo))
);

// ── E. El listado de tenants ────────────────────────────────────────────────
$svc = new CompanyAdminService();
[$list, $err] = attempt(fn() => $svc->listAll(['page' => 1, 'pageSize' => 200]));
check('E1 listAll no explota', $err === '', $err);

$byId = [];
foreach ((array) ($list['rows'] ?? []) as $row) {
    $byId[strtolower((string) ($row['id'] ?? ''))] = $row;
}
check('E2 listAll trae los dos tenants del fixture', isset($byId[$legacyCo], $byId[$modernCo]), implode(',', array_keys($byId)));
check(
    'E3 el dueño legacy llega en la fila',
    ($byId[$legacyCo]['owner']['id'] ?? '') === $legacyOwn,
    json_encode($byId[$legacyCo]['owner'] ?? null)
);
check(
    'E4 el dueño con rol UUID también llega (lo perdía role=\'1\')',
    ($byId[$modernCo]['owner']['id'] ?? '') === $modernOwn,
    json_encode($byId[$modernCo]['owner'] ?? null)
);

[$detail, $err] = attempt(fn() => $svc->get($modernCo));
check('E5 get() no explota y trae la ficha', $err === '' && is_array($detail), $err);

// ── F. Semáforo de salud ────────────────────────────────────────────────────
$health = new TenantHealthService();
[$summary, $err] = attempt(fn() => $health->computeAll(true));
check('F1 computeAll no explota (leía columnas que no existen)', $err === '', $err);
check('F2 computeAll devuelve el resumen de tenants', is_array($summary) && $summary !== [], json_encode(array_slice((array) $summary, 0, 1)));

[$legacyDetail, $err] = attempt(fn() => $health->computeFor($legacyCo));
check('F3 computeFor no explota', $err === '', $err);
check(
    'F4 módulo prendido como key plana de config → activo',
    ($legacyDetail['signals']['breadth']['modules']['orders']['active'] ?? null) === true,
    json_encode($legacyDetail['signals']['breadth']['modules'] ?? null)
);

[$modernDetail, $err] = attempt(fn() => $health->computeFor($modernCo));
check('F5 computeFor (moduleData) no explota', $err === '', $err);
check(
    'F6 módulo prendido sólo en moduleData → activo',
    ($modernDetail['signals']['breadth']['modules']['orders']['active'] ?? null) === true,
    json_encode($modernDetail['signals']['breadth']['modules'] ?? null)
);

// ── G. El resolver de estado de módulo ──────────────────────────────────────
check('G1 key plana truthy', ModuleState::enabled(['ordersPanel' => '1'], 'ordersPanel') === true);
check('G2 key plana falsy', ModuleState::enabled(['ordersPanel' => '0'], 'ordersPanel') === false);
check('G3 lee case-insensitive (PG baja los identificadores)', ModuleState::enabled(['orderspanel' => 't'], 'ordersPanel') === true);
check('G4 moduleData[k].status', ModuleState::enabled(['moduleData' => ['tables' => ['status' => 1]]], 'tables') === true);
check('G5 moduleData[k] como valor directo', ModuleState::enabled(['moduleData' => ['tables' => true]], 'tables') === true);
check('G6 moduleData como JSON crudo (fila sin aplanar)', ModuleState::enabled(['moduledata' => '{"tables":{"status":"true"}}'], 'tables') === true);
check('G7 módulo ausente → apagado', ModuleState::enabled(['settingName' => 'X'], 'production') === false);
check('G8 key plana vacía no tapa a moduleData', ModuleState::enabled(['tables' => '', 'moduleData' => ['tables' => ['status' => 1]]], 'tables') === true);

// ── I. Login del panel: por rol, sin exigir main ────────────────────────────
[$login, $err] = attempt(fn() => findPhoneLogin('595991900004'));
check('I1 findPhoneLogin no explota', $err === '', $err);
check(
    'I2 el dueño de seed viejo (main=\'admin\') puede loguear al panel',
    strtolower((string) ($login['contactId'] ?? $login['contactid'] ?? '')) === $seedOwn,
    json_encode($login['contactid'] ?? null)
);
[$loginModern] = attempt(fn() => findPhoneLogin('595991900002'));
check(
    'I3 el dueño con rol UUID también loguea',
    strtolower((string) ($loginModern['contactId'] ?? $loginModern['contactid'] ?? '')) === $modernOwn
);
[$loginIntruder] = attempt(fn() => findPhoneLogin('595991900003'));
check(
    'I4 el contacto con el rol owner de otro tenant NO loguea',
    empty($loginIntruder['contactid'] ?? $loginIntruder['contactId'] ?? null),
    json_encode($loginIntruder['contactid'] ?? null)
);

// ── J. Sin main='admin' colgando en usuarios con rol de dueño (mig 172) ─────
// Excluye las companies del fixture: una de ellas TIENE un dueño con
// main='admin' a propósito, para el caso I (el login no debe mirar `main`).
$legacyMain = $db->Execute(
    "SELECT COUNT(*) AS n FROM contact
      WHERE main = 'admin' AND type = 0 AND companyId NOT IN (?, ?) AND " . RoleService::ownerRoleSql(),
    [$legacyCo, $modernCo]
);
check(
    'J1 ningún dueño real quedó con el main legacy \'admin\' (mig 172)',
    ((int) ($legacyMain->fields['n'] ?? -1)) === 0,
    'quedan ' . ($legacyMain->fields['n'] ?? '?') . ' — la mig 172 no corrió o alguien reintrodujo el valor'
);

// ── K. La empresa master no cuenta como tenant (mig 173) ────────────────────
$master = $db->Execute(
    "SELECT isInternal FROM company WHERE companyId = '00000000-0000-0000-0000-000000000001'"
);
check(
    'K1 la empresa master existe (mig 173 + seed corrieron)',
    (bool) $master && !$master->EOF,
    'no está la company 00000000-…-0001'
);
check(
    'K2 la empresa master está marcada isInternal (no cuenta como tenant)',
    (bool) $master && !$master->EOF && ((int) ($master->fields['isinternal'] ?? 0)) === 1,
    'isInternal=' . (($master && !$master->EOF) ? ($master->fields['isinternal'] ?? '?') : 'sin fila')
);

// ── L. Impersonación: sesión de panel del dueño, emitida por PanelAuth ──────
[$enter, $err] = attempt(fn() => $svc->getEnterToken($modernCo));
check('L1 getEnterToken no explota', $err === '', $err);
check('L2 devuelve token y vencimiento', !empty($enter['token']) && ((int) ($enter['expiresIn'] ?? 0)) > 0, json_encode($enter));

$sessionRow = null;
if (!empty($enter['token'])) {
    require_once dirname(__DIR__) . '/includes/auth_session.php';
    $sessionRow = authSessionLookup((string) $enter['token']);
}
// authSessionLookup devuelve CaseInsensitiveArray, no array (trap §40.3):
// `is_array()` sobre esa fila es siempre false.
check(
    'L3 la sesión existe y es del realm panel',
    $sessionRow !== null && ((string) ($sessionRow['realm'] ?? '')) === 'panel',
    'realm=' . ($sessionRow['realm'] ?? 'sin sesión')
);
check(
    'L4 apunta a la company impersonada y a su dueño',
    strtolower((string) ($sessionRow['companyId'] ?? $sessionRow['companyid'] ?? '')) === $modernCo
        && strtolower((string) ($sessionRow['userId'] ?? $sessionRow['userid'] ?? '')) === $modernOwn,
    json_encode([$sessionRow['companyid'] ?? null, $sessionRow['userid'] ?? null])
);
check(
    'L5 conserva el roleId textual (el int-casteo lo volvía un número inventado)',
    strtolower((string) ($sessionRow['roleId'] ?? $sessionRow['roleid'] ?? '')) === $modernRole,
    'roleId=' . ($sessionRow['roleid'] ?? $sessionRow['roleId'] ?? '?') . ' esperado=' . $modernRole
);
check(
    'L6 el tenant sin dueño no habilita impersonar',
    $svc->getEnterToken('00000000-0000-0000-0000-0000000000ff') === null,
    'devolvió algo para una company inexistente'
);

// ── H. Planes ───────────────────────────────────────────────────────────────
[$plans, $err] = attempt(fn() => (new PlanAdminService())->list(true));
check('H1 PlanAdminService::list no explota con CaseInsensitiveArray', $err === '', $err);
check('H2 devuelve planes con code/name', is_array($plans) && (!$plans || isset($plans[0]['code'], $plans[0]['name'])), json_encode(array_slice((array) $plans, 0, 1)));

// ── Limpieza ────────────────────────────────────────────────────────────────
$db->Execute('DELETE FROM contact WHERE contactId IN (?,?,?,?)', [$legacyOwn, $modernOwn, $intruder, $seedOwn]);
$db->Execute('DELETE FROM tenant_health WHERE companyid IN (?,?)', [$legacyCo, $modernCo]);
$db->Execute('DELETE FROM taxonomy WHERE taxonomyId = ?', [$modernRole]);
$db->Execute('DELETE FROM company WHERE companyId IN (?,?)', [$legacyCo, $modernCo]);

harnessFinish($failures, $checks);
