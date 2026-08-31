<?php
declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) de las API keys del MCP — M0 de `context/58`.
 *
 * Lo que se verifica no es "el CRUD anda", sino las propiedades de SEGURIDAD
 * que hacen que una key sea segura de repartir:
 *
 *  1. El token crudo NUNCA se persiste — en `auth_session` solo vive su sha256.
 *  2. La key hereda `userId`/`roleId` del operador que la emitió. Es lo que
 *     hace que sus permisos sean los del usuario SIN una segunda tabla que
 *     pueda divergir (D6): si esto se rompe, una key podría más que su dueño.
 *  3. Nace en el realm `mcp` y NO en `panel`. Con `panel` entraría a todas las
 *     escrituras del panel sin que nadie lo decidiera (D4).
 *  4. Tiene vencimiento, a diferencia del device del POS (D7), y se rechaza
 *     una vigencia fuera de rango.
 *  5. Revocar es idempotente y no es oráculo: una key de OTRO tenant no se
 *     revoca y responde igual que una inexistente — el mismo P2 que se cerró
 *     en `devices.php`.
 *  6. El listado nunca devuelve el token ni su hash.
 *  7. La regla de auditoría del realm `mcp`: se auditan TODAS las llamadas,
 *     incluidas las GET. Es la excepción a la regla general (solo mutaciones)
 *     y auditar solo mutaciones en un realm read-only sería no auditar nada.
 *
 * Uso (necesita Postgres migrado — ver run_api_key_test.sh):
 *   POSTGRES_HOST=... php -d variables_order=EGPCS api/tests/api_key_test.php
 */

$companyA = 'b17ce470-0000-4000-8000-000000000101';
$companyB = 'b17ce470-0000-4000-8000-000000000102';
$outletA  = 'b17ce470-0000-4000-8000-000000000103';
$outletB  = 'b17ce470-0000-4000-8000-000000000104';
$userA    = 'b17ce470-0000-4000-8000-000000000105';
$userB    = 'b17ce470-0000-4000-8000-000000000106';

define('COMPANY_ID', $companyA);
define('OUTLET_ID',  $outletA);
define('USER_ID',    $userA);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/ApiKeyService.php';

use Punto\Api\Auth\ApiKeyService;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

$created = ['auth_session' => [], 'contact' => [], 'outlet' => [], 'company' => []];

try {
    foreach ([[$companyA, 'MCP Key Test A'], [$companyB, 'MCP Key Test B']] as [$cid, $cname]) {
        $db->Execute(
            "INSERT INTO company (companyId, status, plan, balance, isParent, config)
             VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)",
            [$cid, json_encode(['settingName' => $cname])]
        );
        $created['company'][] = $cid;
    }
    foreach ([[$outletA, $companyA], [$outletB, $companyB]] as [$oid, $cid]) {
        $db->Execute(
            'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
            [$oid, 'MCP Sucursal', $cid]
        );
        $created['outlet'][] = $oid;
    }
    foreach ([[$userA, $companyA, $outletA], [$userB, $companyB, $outletB]] as [$uid, $cid, $oid]) {
        $db->Execute(
            'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
             VALUES (?, ?, ?, ?, 0, 1)',
            [$uid, 'MCP Usuario', $cid, $oid]
        );
        $created['contact'][] = $uid;
    }

    $svc = new ApiKeyService();
    $ctxA = ['companyId' => $companyA, 'userId' => $userA, 'outletId' => $outletA, 'roleId' => 'admin'];

    // ── 1. Emisión ───────────────────────────────────────────────────────────
    $issued = $svc->issue($ctxA, 'Claude Desktop de Juan');
    $token  = $issued['token'];
    check('issue() devuelve un token crudo no vacío', $token !== '', 'token vacío', $failures, $checks);

    $row = ncmExecute(
        'SELECT sessionid, tokenhash, realm, companyid, userid, roleid, outletid, module, expiresat, meta, status
           FROM auth_session WHERE tokenhash = ?',
        [hash('sha256', $token)]
    );
    check('la sesión existe buscada por el HASH del token', (bool) $row, 'no se encontró por hash', $failures, $checks);
    $created['auth_session'][] = (string) ($row['sessionid'] ?? '');

    // ── 2. El crudo no se persiste ───────────────────────────────────────────
    $leak = ncmExecute(
        'SELECT count(*) as n FROM auth_session WHERE tokenhash = ? OR meta::text LIKE ?',
        [$token, '%' . $token . '%']
    );
    check(
        'el token CRUDO no aparece en ninguna columna',
        ((int) ($leak['n'] ?? 0)) === 0,
        'el token crudo quedó guardado en la tabla',
        $failures, $checks
    );

    // ── 3. Identidad heredada + realm propio ─────────────────────────────────
    check('realm = api, no panel', (string) $row['realm'] === 'api', 'realm = ' . (string) $row['realm'], $failures, $checks);
    check('hereda el userId del operador', (string) $row['userid'] === $userA, 'userid = ' . (string) $row['userid'], $failures, $checks);
    check('hereda el roleId del operador', (string) $row['roleid'] === 'admin', 'roleid = ' . (string) $row['roleid'], $failures, $checks);
    check('hereda el outletId del operador', (string) $row['outletid'] === $outletA, 'outletid = ' . (string) $row['outletid'], $failures, $checks);

    // ── 4. Vencimiento ───────────────────────────────────────────────────────
    check(
        'nace con vencimiento (a diferencia del device del POS)',
        (string) ($row['expiresat'] ?? '') !== '' && strtotime((string) $row['expiresat']) > time(),
        'expiresat = ' . var_export($row['expiresat'] ?? null, true),
        $failures, $checks
    );
    $rechazada = false;
    try { $svc->issue($ctxA, 'Eterna', 99999); } catch (\InvalidArgumentException) { $rechazada = true; }
    check('rechaza una vigencia fuera de rango', $rechazada, 'aceptó 99999 días', $failures, $checks);

    $sinNombre = false;
    try { $svc->issue($ctxA, '   '); } catch (\InvalidArgumentException) { $sinNombre = true; }
    check('rechaza una key sin nombre', $sinNombre, 'aceptó nombre vacío', $failures, $checks);

    // ── 5. Listado sin material sensible ─────────────────────────────────────
    $lista = $svc->listForCompany($companyA);
    check('la key aparece en el listado del tenant', count($lista) === 1, 'devolvió ' . count($lista), $failures, $checks);
    $serial = json_encode($lista);
    check(
        'el listado no filtra ni el token ni su hash',
        !str_contains((string) $serial, $token) && !str_contains((string) $serial, hash('sha256', $token)),
        'el listado incluye material del token',
        $failures, $checks
    );
    check('el listado trae el nombre', ($lista[0]['name'] ?? '') === 'Claude Desktop de Juan', 'name = ' . ($lista[0]['name'] ?? ''), $failures, $checks);
    check('la key del tenant B no se ve desde A', count($svc->listForCompany($companyB)) === 0, 'B ve keys de A', $failures, $checks);

    // ── 6. Revocación: aislada e idempotente ─────────────────────────────────
    $sessionId = (string) $row['sessionid'];
    check(
        'OTRO tenant no puede revocar la key',
        $svc->revoke($sessionId, $companyB, $userB) === false,
        'el tenant B revocó una key de A',
        $failures, $checks
    );
    check('el dueño la revoca', $svc->revoke($sessionId, $companyA, $userA) === true, 'no pudo revocar', $failures, $checks);
    check(
        'revocar dos veces devuelve false (idempotente, sin oráculo)',
        $svc->revoke($sessionId, $companyA, $userA) === false,
        'la segunda revocación devolvió true',
        $failures, $checks
    );
    check(
        'una key inexistente responde igual que una ajena',
        $svc->revoke('b17ce470-0000-4000-8000-0000000009ff', $companyA, $userA) === false,
        'una inexistente devolvió true',
        $failures, $checks
    );
    check('revocada, desaparece del listado activo', count($svc->listForCompany($companyA)) === 0, 'sigue activa', $failures, $checks);
    check('pero sigue en el historial', count($svc->listForCompany($companyA, true)) >= 1, 'se perdió la auditoría', $failures, $checks);
    // ── 7. Regla de auditoría del realm mcp ──────────────────────────────────
    // El gate vive en `apiAuthTenant()` (bootstrap.php) y depende de
    // `$_SERVER['REQUEST_METHOD']`, que bajo CLI no existe: en vez de simular
    // media request, se verifica la REGLA sobre el código, que es lo que puede
    // regresar. Un `tenantAudit()` que vuelva a mirar solo mutaciones dejaría
    // al MCP —que no muta— sin una sola línea de auditoría.
    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap.php');
    check(
        'la condición de auditoría contempla el realm api, no solo mutaciones',
        str_contains($bootstrap, '$__isApiKey') && str_contains($bootstrap, '$__isMutation || $__isApiKey'),
        'la guarda volvió a ser solo-mutaciones: el MCP quedaría sin auditoría',
        $failures, $checks
    );
    check(
        'el realtime sigue disparando SOLO en mutaciones',
        str_contains($bootstrap, 'if ($__isMutation) {'),
        'una lectura por API key estaría emitiendo eventos de realtime',
        $failures, $checks
    );
    check(
        'la auditoría del realm api registra QUÉ key hizo la llamada',
        str_contains($bootstrap, "'keyId' => (string) AUTHED_SESSION_ID"),
        'sin keyId el comercio ve la llamada pero no qué integración la hizo',
        $failures, $checks
    );

    // Y que la tabla acepte el shape que se le va a escribir.
    $auditId = 'b17ce470-0000-4000-8000-0000000009aa';
    $db->Execute(
        'INSERT INTO tenant_audit (id, companyid, userid, outletid, realm, method, endpoint, meta, ip)
         VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?::jsonb, ?)',
        [$auditId, $companyA, $userA, $outletA, 'api', 'GET', '/v1/reports/summary_year',
         json_encode(['keyId' => 'b17ce470-0000-4000-8000-0000000009bb']), '127.0.0.1']
    );
    $back = ncmExecute('SELECT realm, method, meta FROM tenant_audit WHERE id = ?::uuid', [$auditId]);
    check(
        'tenant_audit acepta una lectura del realm api con su keyId',
        $back && (string) $back['realm'] === 'api' && (string) $back['method'] === 'GET',
        'no se pudo escribir/leer la fila de auditoría del mcp',
        $failures, $checks
    );
    $db->Execute('DELETE FROM tenant_audit WHERE id = ?::uuid', [$auditId]);
} finally {
    $db->Execute("DELETE FROM auth_session WHERE companyid IN (?::uuid, ?::uuid) AND realm = 'api'", [$companyA, $companyB]);
    foreach ($created['contact'] as $id) { $db->Execute('DELETE FROM contact WHERE contactId = ?', [$id]); }
    foreach ($created['outlet'] as $id)  { $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$id]); }
    foreach ($created['company'] as $id) { $db->Execute('DELETE FROM company WHERE companyId = ?', [$id]); }
}

harnessFinish($failures, $checks);
