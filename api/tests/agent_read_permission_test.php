<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de LECTURAS DEL ASISTENTE SUJETAS AL PERMISO DE QUIEN PREGUNTA.
 *
 * ── Qué se prueba y por qué así ─────────────────────────────────────────────
 *
 * Regla del owner, confirmada varias veces: las capacidades del asistente están
 * sujetas a los permisos del usuario que le está preguntando, y eso vale para
 * las LECTURAS igual que para las escrituras. Si un cajero no puede abrir el
 * reporte de ventas en el panel, tampoco puede sacárselo al asistente de la
 * caja: sería la misma pantalla con otra puerta.
 *
 * El agujero que cerró el 2026-09-01 tenía dos capas, y las dos se verifican
 * acá contra el ENDPOINT REAL (`/v1/reports/transactions.php`), en subproceso,
 * con sesiones de auth reales y Postgres real — no llamando al service ni
 * mirando la UI:
 *
 *   1. El GET no chequeaba NINGÚN permiso (los dos `hasPermission()` del
 *      archivo estaban en el POST y en el PUT).
 *   2. Aunque lo hubiera chequeado, bajo `pos-app` habría pasado siempre: ese
 *      helper resuelve contra el rol del DEVICE, que tiene `reports.sales.view`
 *      en su piso para las devoluciones. La persona viaja aparte, en el
 *      `X-Operator-Token` que firma el unlock por PIN.
 *
 * Casos:
 *   (A) realm pos-app — el gate mide contra el OPERADOR
 *       A1 sin afirmación de operador                      → 403 (fail-closed)
 *       A2 operador SIN reports.sales.view                  → 403
 *       A3 operador CON reports.sales.view                  → pasa el gate
 *       A4 afirmación de OTRA empresa                       → 403
 *       A5 afirmación con la firma manipulada               → 403
 *       A6 el detalle (`?id=`) está detrás del mismo gate   → 403
 *   (B) realm panel — el gate que faltaba del todo
 *       B1 rol SIN la clave → 403   B2 rol CON la clave → pasa   B3 owner → pasa
 *   (C) El corte NO es parejo: lo que la pantalla de venta ya muestra sigue
 *       abierto al device sin operador (catálogo y clientes). Sin este bloque,
 *       "endurecer" sería indistinguible de romper la caja.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_agent_read_permission_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/agent_read_permission_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/OperatorAssertion.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Auth\OperatorAssertion;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'agent-read-permission-test';
const PERM            = 'reports.sales.view';

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

/**
 * Corre un endpoint en subproceso (apiError() hace exit — un 403 dentro del
 * proceso del arnés lo mataría entero) y devuelve status + body. Reusa el shim
 * compartido de `permission_enforcement_test.php`.
 */
function hit(
    string $endpointRel,
    string $query,
    string $panelToken = '',
    string $bearer = '',
    string $operatorToken = ''
): array {
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $endpointRel, 'GET', $query, '{}', $panelToken, $bearer, $operatorToken,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // El status sale del ENVELOPE canónico: bajo SAPI cli http_response_code()
    // no devuelve de forma confiable lo que seteó apiError().
    $status = 0;
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
        }
    }
    if ($status === 0 && preg_match('/"code":(\d{3})/', $out, $m)) $status = (int) $m[1];
    if ($status === 0 && str_contains($out, '"ok":true'))           $status = 200;

    return ['status' => $status, 'body' => $out . $err];
}

/**
 * "Pasó el gate" exige DOS cosas: que haya respuesta parseable y que no sea el
 * 403 del permiso. Sin lo primero, un endpoint reventado (fatal, require roto)
 * daría verde — el falso verde que ya mordió en `permission_enforcement_test`.
 */
function pasaElGate(array $res): bool
{
    return $res['status'] !== 0 && $res['status'] !== 403;
}

/** Crea (o reusa) un rol custom con exactamente $perms. */
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

/** Crea (o reusa) un contacto-usuario del comercio con un rol dado. */
function makeContact(string $name, string $roleId, string $companyId): string
{
    $email = strtolower($name) . '.agent-read@test.local';
    $row = ncmExecute(
        'SELECT contactid FROM contact WHERE companyid = ? AND contactemail = ? AND type = 0 LIMIT 1',
        [$companyId, $email]
    );
    if ($row && !empty($row['contactid'])) {
        $id = (string) $row['contactid'];
        ncmExecute('UPDATE contact SET role = ?, contactstatus = 1 WHERE contactid = ?', [$roleId, $id]);
        return $id;
    }
    global $db;
    $rs = $db->Execute(
        'INSERT INTO contact (contactid, companyid, contactname, contactemail, type, contactstatus, role)
         VALUES (gen_random_uuid(), ?, ?, ?, 0, 1, ?) RETURNING contactid',
        [$companyId, $name, $email, $roleId]
    );
    return (string) ($rs->fields['contactid'] ?? '');
}

/** Sesión de panel real para un roleId dado (el realm panel viaja por Bearer). */
function panelSession(string $roleId, string $companyId, string $outletId, string $userId): string
{
    return authSessionCreate('panel', [
        'companyId' => $companyId,
        'userId'    => $userId,
        'outletId'  => $outletId,
        'roleId'    => $roleId,
        'expiresAt' => date('Y-m-d H:i:s', time() + 3600),
        'userAgent' => MARCA_DEL_ARNES,
    ]);
}

function ownerRoleId(string $companyId): string
{
    $row = ncmExecute(
        "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'='owner'",
        [$companyId]
    );
    return (string) ($row['taxonomyid'] ?? '');
}

$deviceIds = [];
// Rango chico a propósito: lo que se afirma es el STATUS del gate, no el
// contenido del reporte.
$QUERY = 'view=detail&from=2026-01-01&to=2026-01-02';

try {
    RoleService::seedCompanyRoles($companyId);

    // Roles CUSTOM y no los seed, para que el arnés no dependa de qué permisos
    // tenga hoy `cashier`: lo que se prueba es el efecto de LA CLAVE, aislado.
    $roleSinVentas = makeRole('agentread-sin-ventas', [
        'pos.sale.create', 'pos.ai.use', 'inventory.item.view', 'contacts.customer.view',
    ], $companyId, $adminId);
    $roleConVentas = makeRole('agentread-con-ventas', [
        'pos.sale.create', 'pos.ai.use', 'inventory.item.view', 'contacts.customer.view', PERM,
    ], $companyId, $adminId);
    // El rol que motivó separar el gate del DETALLE del gate del LISTADO: cobra
    // créditos (y por eso abre el documento sobre el que va a cobrar) pero no
    // ve el reporte de ventas. Es el perfil del `cashier` del seed.
    $roleCobrador = makeRole('agentread-cobrador', [
        'pos.sale.creditPayment', 'contacts.customer.view',
    ], $companyId, $adminId);

    $cajero    = makeContact('AgentReadCajero',    $roleSinVentas, $companyId);
    $encargado = makeContact('AgentReadEncargado', $roleConVentas, $companyId);
    $cobrador  = makeContact('AgentReadCobrador',  $roleCobrador,  $companyId);

    $tokCajero    = OperatorAssertion::issue($companyId, $cajero);
    $tokEncargado = OperatorAssertion::issue($companyId, $encargado);
    $tokCobrador  = OperatorAssertion::issue($companyId, $cobrador);

    // Device real (realm pos-app): la tablet compartida con la que el BFF del
    // asistente hace TODAS sus lecturas.
    $issued = DeviceAuth::issueDeviceToken(
        $companyId, $outletId, $registerId, $adminId,
        'Test device — lecturas del asistente',
        MARCA_DEL_ARNES,
        'test-agentread-' . bin2hex(random_bytes(6)),
    );
    $deviceIds[] = $issued['deviceId'];
    $bearer = $issued['token'];

    // ═══════════════════════════════════════════════════════════════════════
    // (A) realm pos-app — el permiso se mide contra la PERSONA del PIN
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A) pos-app: la lectura de ventas se mide contra el operador ===\n";

    // El piso del rol `device` INCLUYE reports.sales.view (devoluciones), así
    // que este caso es exactamente el que un `hasPermission()` a secas dejaría
    // pasar. Si alguna vez vuelve a pasar, este check se pone rojo.
    $res = hit('v1/reports/transactions.php', $QUERY, '', $bearer, '');
    check('(A1) sin operador identificado la caja NO lee ventas', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(A1b) el 403 dice qué hacer (desbloquear con el PIN)',
        str_contains($res['body'], 'PIN'),
        "el mensaje no menciona el PIN: {$res['body']}", $failures, $checks);

    $res = hit('v1/reports/transactions.php', $QUERY, '', $bearer, $tokCajero);
    check('(A2) operador SIN ' . PERM . ' → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(A2b) el 403 nombra la clave que falta',
        str_contains($res['body'], 'requiere: ' . PERM),
        "el mensaje no nombra la clave: {$res['body']}", $failures, $checks);

    $res = hit('v1/reports/transactions.php', $QUERY, '', $bearer, $tokEncargado);
    check('(A3) operador CON ' . PERM . ' sí lee', pasaElGate($res),
        "esperaba pasar el gate, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $tokOtraEmpresa = OperatorAssertion::issue('11111111-1111-1111-1111-111111111111', $encargado);
    $res = hit('v1/reports/transactions.php', $QUERY, '', $bearer, $tokOtraEmpresa);
    check('(A4) afirmación de OTRA empresa no identifica → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $partes    = explode('.', $tokEncargado);
    $tokTrucho = $partes[0] . '.' . strrev($partes[1]);
    $res = hit('v1/reports/transactions.php', $QUERY, '', $bearer, $tokTrucho);
    check('(A5) afirmación con firma manipulada → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // El detalle de UNA transacción es la otra puerta al mismo dato: sin este
    // caso, gatear solo el listado dejaría abierta la que importa (el total, el
    // cliente y los pagos de una venta puntual).
    $res = hit('v1/reports/transactions.php', 'id=00000000-0000-0000-0000-0000000000ff', '', $bearer, $tokCajero);
    check('(A6) el detalle ?id= también está gateado (rol sin ninguna de las claves)', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // Pero el detalle NO pide la clave del REPORTE: quien cobra un crédito
    // necesita abrir el documento que va a cobrar. 404 (el id es inventado) es
    // exactamente lo que se espera: pasó el gate y no encontró la fila.
    $res = hit('v1/reports/transactions.php', 'id=00000000-0000-0000-0000-0000000000ff', '', $bearer, $tokCobrador);
    check('(A7) el que cobra créditos SÍ abre el detalle de un documento', pasaElGate($res),
        "esperaba pasar el gate, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/reports/transactions.php', $QUERY, '', $bearer, $tokCobrador);
    check('(A8) …y aun así NO lee el listado de ventas de la sucursal', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) realm panel — el gate que directamente no existía
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) panel: la lectura exige la clave (antes no exigía nada) ===\n";

    $panelSin  = panelSession($roleSinVentas, $companyId, $outletId, $adminId);
    $panelCon  = panelSession($roleConVentas, $companyId, $outletId, $adminId);
    $roleOwner = ownerRoleId($companyId);
    check('(B0) el fixture resuelve el rol owner', $roleOwner !== '',
        'no se pudo resolver el rol owner del tenant fixture', $failures, $checks);
    $panelOwner = $roleOwner !== '' ? panelSession($roleOwner, $companyId, $outletId, $adminId) : '';

    $res = hit('v1/reports/transactions.php', $QUERY, $panelSin);
    check('(B1) panel con rol SIN la clave → 403',
        $res['status'] === 403 && str_contains($res['body'], 'requiere: ' . PERM),
        "esperaba el 403 del permiso, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/reports/transactions.php', $QUERY, $panelCon);
    check('(B2) panel con rol CON la clave sí lee', pasaElGate($res),
        "esperaba pasar el gate, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // La regresión que este bloque previene: el tab "Financiero" de un cliente
    // empuja a `/transactions/{id}`, y esa página se sirve SOLO de la rama
    // `?id=`. Un rol que cobra créditos tiene que poder abrirla.
    $panelCobrador = panelSession($roleCobrador, $companyId, $outletId, $adminId);
    $res = hit('v1/reports/transactions.php', 'id=00000000-0000-0000-0000-0000000000ff', $panelCobrador);
    check('(B4) panel: el que cobra créditos abre el detalle sin ' . PERM, pasaElGate($res),
        "esperaba pasar el gate, vino {$res['status']}: {$res['body']}", $failures, $checks);

    if ($panelOwner !== '') {
        $res = hit('v1/reports/transactions.php', $QUERY, $panelOwner);
        check('(B3) el owner siempre lee', pasaElGate($res),
            "esperaba pasar el gate, vino {$res['status']}: {$res['body']}", $failures, $checks);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // (C) El corte no es parejo — lo de mostrador sigue abierto
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) el catálogo de mostrador NO exige operador ===\n";

    $res = hit('v1/items.php', 'limit=1', '', $bearer, '');
    check('(C1) el catálogo se lee sin operador (precio y stock ya están en la pantalla de venta)',
        pasaElGate($res),
        "esperaba pasar, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/contacts.php', 'type=1&limit=1', '', $bearer, '');
    check('(C2) los clientes se leen sin operador (el selector de cliente ya los muestra)',
        pasaElGate($res),
        "esperaba pasar, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/reports/stock.php', 'limit=1', '', $bearer, '');
    check('(C3) el stock se lee sin operador', pasaElGate($res),
        "esperaba pasar, vino {$res['status']}: {$res['body']}", $failures, $checks);
} finally {
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?::uuid', [$did]);
        ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$did]);
    }
    ncmExecute('DELETE FROM auth_session WHERE useragent = ?', [MARCA_DEL_ARNES]);
}

harnessFinish($failures, $checks);
