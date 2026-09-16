<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (Postgres real, endpoint real) del ALCANCE POR SUCURSAL
 * de `/v1/orders-core`.
 *
 * ── El bug (P0, 2026-09-16) ─────────────────────────────────────────────────
 * `orders-core.php` armaba `$outletScope = $isPosApp ? $outletId : null`: en el
 * realm `panel` el alcance quedaba en `null`, así que cualquier usuario del
 * panel LEÍA Y OPERABA órdenes de TODAS las sucursales del tenant aunque
 * tuviera asignada una sola en `contact_outlet`.
 *
 * ── La regla (owner, cerrada) ───────────────────────────────────────────────
 * La de `Punto\Api\Outlets\OutletScope`: con ≥1 fila en `contact_outlet`, solo
 * esas sucursales; con cero filas, global. Sin clave de permiso nueva.
 *
 * ── Casos ───────────────────────────────────────────────────────────────────
 *   (A) usuario con 1 sucursal: lista solo la suya; detalle, send, status,
 *       cancel, item-status, mark-paid y assign-courier sobre una orden AJENA
 *       → 404 (sin revelar existencia) y la orden ajena NO cambia; `outletId`
 *       ajeno en la lista o en el alta → 403; sobre la suya, sí opera.
 *   (B) usuario con 2 sucursales: ve las dos, no la tercera; `outletId` de la
 *       tercera → 403.
 *   (C) usuario global (cero filas): ve las tres y abre el detalle de cualquiera.
 *   (D) device pos-app: sigue viendo y operando SOLO su sucursal, e ignora el
 *       `?outletId=` (comportamiento previo intacto).
 *
 * Cada request corre en subproceso (`_permission_once_cli.php`): `apiError()`
 * hace `exit`, y el alcance vive en constantes (`VIEW_OUTLET_IDS`) que no se
 * pueden redefinir dentro de un proceso.
 *
 * Uso (Postgres migrado + seed.sql — ver run_orders_outlet_scope_test.sh):
 *   POSTGRES_HOST=... php -d variables_order=EGPCS api/tests/orders_outlet_scope_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
require_once dirname(__DIR__) . '/lib/Orders/OrderCoreService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Orders\OrderCoreService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletA    = '1a282724-6073-49c3-8bc3-0114a132e349'; // la del seed
$registerA  = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$outletId   = $outletA;
$registerId = $registerA;
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

$outletB = '0dde5c09-0000-4000-8000-0000000000b2';
$outletC = '0dde5c09-0000-4000-8000-0000000000b3';

$userA      = '0dde5c09-0000-4000-8000-0000000000d1'; // contact_outlet: A
$userAB     = '0dde5c09-0000-4000-8000-0000000000d2'; // contact_outlet: A, B
$userGlobal = '0dde5c09-0000-4000-8000-0000000000d3'; // cero filas → global

const MARCA_DEL_ARNES = 'orders-outlet-scope-test';

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
 * Llama al endpoint real en subproceso.
 *
 * @return array{status:int, body:string, data:mixed, message:string}
 */
function call(string $method, string $query, array $body, string $bearer): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        'v1/orders-core.php', $method, $query,
        json_encode($body), '', $bearer, '', '',
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso', 'data' => null, 'message' => ''];
    }
    fwrite($pipes[0], json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $status  = 0;
    $data    = null;
    $message = '';
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status  = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
            $data    = $env['data'] ?? null;
            $message = (string) ($env['error']['message'] ?? '');
        }
    }
    return ['status' => $status, 'body' => substr($out . $err, 0, 600), 'data' => $data, 'message' => $message];
}

/** Ids de las órdenes que devolvió un GET de lista. */
function listedIds(array $res): array
{
    $orders = is_array($res['data']['orders'] ?? null) ? $res['data']['orders'] : [];
    return array_map(static fn (array $o) => (string) ($o['id'] ?? ''), $orders);
}

/** Sucursales distintas presentes en un GET de lista. */
function listedOutlets(array $res): array
{
    $orders = is_array($res['data']['orders'] ?? null) ? $res['data']['orders'] : [];
    $out = array_values(array_unique(array_map(static fn (array $o) => (string) ($o['outletId'] ?? ''), $orders)));
    sort($out);
    return $out;
}

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

/** Crea una orden de una línea (delivery, para poder probar el repartidor) y devuelve [orderId, orderItemId]. */
function mkOrder(OrderCoreService $svc, string $companyId, string $outletId): array
{
    $orderId = $svc->create($companyId, [
        'outletId' => $outletId,
        'source'   => 'counter',
        'items'    => [['name' => 'Empanada del arnés', 'qty' => 1, 'price' => 5000]],
    ]);
    $row = ncmExecute(
        'SELECT orderitemid FROM pos_order_item WHERE orderid = ? AND companyid = ? LIMIT 1',
        [$orderId, $companyId]
    );
    return [$orderId, (string) ($row['orderitemid'] ?? '')];
}

function orderStatus(string $orderId): string
{
    $row = ncmExecute('SELECT status FROM pos_order WHERE orderid = ?', [$orderId]);
    return (string) ($row['status'] ?? '');
}

function itemStatus(string $orderItemId): string
{
    $row = ncmExecute('SELECT status FROM pos_order_item WHERE orderitemid = ?', [$orderItemId]);
    return (string) ($row['status'] ?? '');
}

$deviceIds = [];

try {
    global $db;

    RoleService::seedCompanyRoles($companyId);
    $ownerRole = (string) (ncmExecute(
        "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'='owner'",
        [$companyId]
    )['taxonomyid'] ?? '');
    check('fixture: el tenant tiene rol owner', $ownerRole !== '', 'seedCompanyRoles no dejó rol owner', $failures, $checks);

    // Sucursales B y C (A es la del seed).
    foreach ([[$outletB, 'Sucursal B arnés'], [$outletC, 'Sucursal C arnés']] as [$oid, $name]) {
        $db->Execute(
            'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
             ON CONFLICT (outletId) DO UPDATE SET outletStatus = 1',
            [$oid, $name, $companyId]
        );
    }

    // Usuarios con rol OWNER a propósito: todos los permisos, así lo único que
    // puede cortarles el paso es el alcance por sucursal y no un gate de clave.
    foreach ([
        [$userA,      'Arnes Scope A',      '595991900001'],
        [$userAB,     'Arnes Scope AB',     '595991900002'],
        [$userGlobal, 'Arnes Scope Global', '595991900003'],
    ] as [$uid, $uname, $uphone]) {
        $db->Execute(
            "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, role, companyId)
             VALUES (?, ?, ?, ?, 1, 0, ?, ?)
             ON CONFLICT (contactId) DO UPDATE SET role = EXCLUDED.role, contactStatus = 1",
            [$uid, $uname, $uphone, strtolower(str_replace(' ', '', $uname)) . '@local.test', $ownerRole, $companyId]
        );
        $db->Execute('DELETE FROM contact_outlet WHERE contactid = ?::uuid', [$uid]);
    }
    foreach ([[$userA, $outletA], [$userAB, $outletA], [$userAB, $outletB]] as [$uid, $oid]) {
        $db->Execute(
            'INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid)
             ON CONFLICT DO NOTHING',
            [$uid, $oid, $companyId]
        );
    }

    $svc = new OrderCoreService($db);
    [$oA, $iA]   = mkOrder($svc, $companyId, $outletA);
    [$oB, $iB]   = mkOrder($svc, $companyId, $outletB);
    [$oC, ]      = mkOrder($svc, $companyId, $outletC);
    [$oA2, ]     = mkOrder($svc, $companyId, $outletA);

    // Sesión del usuario A parada en B a propósito (el `oid` del token): el
    // embudo tiene que repuntarla dentro de su conjunto, nunca abrir B.
    $tokA      = panelSession($ownerRole, $companyId, $outletB, $userA);
    $tokAB     = panelSession($ownerRole, $companyId, $outletA, $userAB);
    $tokGlobal = panelSession($ownerRole, $companyId, $outletA, $userGlobal);

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A) usuario con UNA sucursal (A) ===\n";

    $res = call('GET', '', [], $tokA);
    $ids = listedIds($res);
    check('(A1) lista → 200', $res['status'] === 200, "status={$res['status']} {$res['body']}", $failures, $checks);
    check('(A2) lista incluye la orden de A', in_array($oA, $ids, true), 'ids=' . json_encode($ids), $failures, $checks);
    check('(A3) lista NO incluye órdenes de B ni de C', !in_array($oB, $ids, true) && !in_array($oC, $ids, true),
        'ids=' . json_encode($ids), $failures, $checks);
    check('(A4) todas las filas son de A', listedOutlets($res) === [$outletA],
        'outlets=' . json_encode(listedOutlets($res)), $failures, $checks);

    $res = call('GET', 'includeItems=1', [], $tokA);
    check('(A5) lista con ítems tampoco trae B', !in_array($oB, listedIds($res), true) && $res['status'] === 200,
        "status={$res['status']}", $failures, $checks);

    $res = call('GET', 'id=' . $oB, [], $tokA);
    check('(A6) detalle de orden de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);
    $res404 = call('GET', 'id=0dde5c09-0000-4000-8000-00000000ffff', [], $tokA);
    check('(A7) mismo mensaje que una orden inexistente (no revela existencia)',
        $res['message'] !== '' && $res['message'] === $res404['message'],
        "ajena='{$res['message']}' inexistente='{$res404['message']}'", $failures, $checks);

    $res = call('GET', 'id=' . $oA, [], $tokA);
    check('(A8) detalle de orden de A → 200', $res['status'] === 200 && ($res['data']['id'] ?? '') === $oA,
        "status={$res['status']} {$res['body']}", $failures, $checks);

    $res = call('GET', 'outletId=' . $outletB, [], $tokA);
    check('(A9) lista con outletId=B → 403 (no lista vacía)', $res['status'] === 403, "status={$res['status']} {$res['body']}", $failures, $checks);

    $res = call('GET', 'outletId=' . $outletA, [], $tokA);
    check('(A10) lista con outletId=A → 200 solo A', $res['status'] === 200 && listedOutlets($res) === [$outletA],
        "status={$res['status']} outlets=" . json_encode(listedOutlets($res)), $failures, $checks);

    $res = call('POST', 'id=' . $oB . '&action=send', [], $tokA);
    check('(A11) send de orden de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);
    check('(A11b) la orden de B sigue open', orderStatus($oB) === 'open', 'status=' . orderStatus($oB), $failures, $checks);

    $res = call('POST', 'id=' . $oB . '&action=status', ['status' => 'cancelled', 'reason' => 'arnés'], $tokA);
    check('(A12) cancelar orden de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);
    check('(A12b) la orden de B NO quedó cancelada', orderStatus($oB) === 'open', 'status=' . orderStatus($oB), $failures, $checks);

    $res = call('POST', 'resource=item-status&id=' . $iB, ['status' => 'cancelled', 'reason' => 'arnés'], $tokA);
    check('(A13) anular ítem de orden de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);
    $res = call('POST', 'resource=item-status&id=' . $iB, ['status' => 'preparing'], $tokA);
    check('(A14) bumpear ítem de orden de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);
    check('(A14b) el ítem de B sigue pending', itemStatus($iB) === 'pending', 'status=' . itemStatus($iB), $failures, $checks);

    $res = call('POST', 'id=' . $oB . '&action=mark-paid', ['transactionId' => '0dde5c09-0000-4000-8000-00000000eeee'], $tokA);
    check('(A15) mark-paid de orden de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);

    $res = call('POST', 'id=' . $oB . '&action=assign-courier', ['courierId' => ''], $tokA);
    check('(A16) assign-courier de orden de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);

    $res = call('POST', '', ['outletId' => $outletB, 'items' => [['name' => 'x', 'qty' => 1, 'price' => 1]]], $tokA);
    check('(A17) crear orden en B → 403', $res['status'] === 403, "status={$res['status']} {$res['body']}", $failures, $checks);

    $res = call('POST', '', ['outletId' => $outletA, 'items' => [['name' => 'x', 'qty' => 1, 'price' => 1]]], $tokA);
    check('(A18) crear orden en A → 200', $res['status'] === 200 && ($res['data']['outletId'] ?? '') === $outletA,
        "status={$res['status']} {$res['body']}", $failures, $checks);

    $res = call('POST', 'id=' . $oA . '&action=send', [], $tokA);
    check('(A19) send de orden de A → 200 (el alcance no bloquea lo propio)', $res['status'] === 200 && orderStatus($oA) === 'sent',
        "status={$res['status']} order=" . orderStatus($oA) . " {$res['body']}", $failures, $checks);

    $res = call('POST', 'resource=item-status&id=' . $iA, ['status' => 'preparing'], $tokA);
    check('(A20) bumpear ítem de orden de A → 200', $res['status'] === 200 && itemStatus($iA) === 'preparing',
        "status={$res['status']} item=" . itemStatus($iA) . " {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) usuario con DOS sucursales (A, B) ===\n";

    $res = call('GET', '', [], $tokAB);
    $ids = listedIds($res);
    check('(B1) lista incluye A y B', $res['status'] === 200 && in_array($oA, $ids, true) && in_array($oB, $ids, true),
        "status={$res['status']} ids=" . json_encode($ids), $failures, $checks);
    check('(B2) lista NO incluye C', !in_array($oC, $ids, true), 'ids=' . json_encode($ids), $failures, $checks);
    $exp = [$outletA, $outletB];
    sort($exp);
    check('(B3) sucursales presentes = {A, B}', listedOutlets($res) === $exp,
        'outlets=' . json_encode(listedOutlets($res)), $failures, $checks);

    $res = call('GET', 'outletId=' . $outletC, [], $tokAB);
    check('(B4) outletId=C → 403', $res['status'] === 403, "status={$res['status']} {$res['body']}", $failures, $checks);
    $res = call('GET', 'outletId=' . $outletB, [], $tokAB);
    check('(B5) outletId=B → solo B', $res['status'] === 200 && listedOutlets($res) === [$outletB],
        "status={$res['status']} outlets=" . json_encode(listedOutlets($res)), $failures, $checks);
    $res = call('GET', 'id=' . $oC, [], $tokAB);
    check('(B6) detalle de C → 404', $res['status'] === 404, "status={$res['status']}", $failures, $checks);
    $res = call('GET', 'id=' . $oB, [], $tokAB);
    check('(B7) detalle de B → 200', $res['status'] === 200, "status={$res['status']} {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) usuario GLOBAL (cero filas en contact_outlet) ===\n";

    $res = call('GET', '', [], $tokGlobal);
    $ids = listedIds($res);
    check('(C1) lista incluye A, B y C',
        $res['status'] === 200 && in_array($oA, $ids, true) && in_array($oB, $ids, true) && in_array($oC, $ids, true),
        "status={$res['status']} ids=" . json_encode($ids), $failures, $checks);
    $res = call('GET', 'id=' . $oC, [], $tokGlobal);
    check('(C2) detalle de C → 200', $res['status'] === 200, "status={$res['status']} {$res['body']}", $failures, $checks);
    $res = call('GET', 'outletId=' . $outletC, [], $tokGlobal);
    check('(C3) outletId=C → solo C', $res['status'] === 200 && listedOutlets($res) === [$outletC],
        "status={$res['status']} outlets=" . json_encode(listedOutlets($res)), $failures, $checks);
    $res = call('GET', 'outletId=0dde5c09-0000-4000-8000-0000000000cc', [], $tokGlobal);
    check('(C4) outletId de otra empresa / inexistente → 403', $res['status'] === 403,
        "status={$res['status']} {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) device pos-app (sucursal A) — sin cambio de comportamiento ===\n";

    $issued = DeviceAuth::issueDeviceToken(
        $companyId, $outletA, $registerA, $adminId,
        'Test device — alcance de órdenes',
        MARCA_DEL_ARNES,
        'test-orderscope-' . bin2hex(random_bytes(6)),
    );
    $deviceIds[] = $issued['deviceId'];
    $bearer = $issued['token'];

    $res = call('GET', '', [], $bearer);
    check('(D1) device lista solo A', $res['status'] === 200 && listedOutlets($res) === [$outletA] && in_array($oA2, listedIds($res), true),
        "status={$res['status']} outlets=" . json_encode(listedOutlets($res)) . " {$res['body']}", $failures, $checks);
    $res = call('GET', 'outletId=' . $outletB, [], $bearer);
    check('(D2) device con ?outletId=B lo ignora → sigue solo A', $res['status'] === 200 && listedOutlets($res) === [$outletA],
        "status={$res['status']} outlets=" . json_encode(listedOutlets($res)), $failures, $checks);
    $res = call('GET', 'id=' . $oB, [], $bearer);
    check('(D3) device detalle de B → 404', $res['status'] === 404, "status={$res['status']}", $failures, $checks);
    $res = call('POST', 'id=' . $oB . '&action=send', [], $bearer);
    check('(D4) device send de B → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);
    $res = call('POST', 'id=' . $oA2 . '&action=send', [], $bearer);
    check('(D5) device send de A → 200', $res['status'] === 200 && orderStatus($oA2) === 'sent',
        "status={$res['status']} order=" . orderStatus($oA2) . " {$res['body']}", $failures, $checks);
    $res = call('POST', '', ['outletId' => $outletB, 'items' => [['name' => 'x', 'qty' => 1, 'price' => 1]]], $bearer);
    check('(D6) device crea: el outletId del body se pisa con el del device (A)',
        $res['status'] === 200 && ($res['data']['outletId'] ?? '') === $outletA,
        "status={$res['status']} {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) semántica del service ===\n";
    try {
        $svc->list($companyId, [], false, ['no-es-uuid']);
        check('(E1) alcance con id inválido LANZA (no se degrada a global)', false, 'no lanzó', $failures, $checks);
    } catch (\InvalidArgumentException $e) {
        check('(E1) alcance con id inválido LANZA (no se degrada a global)', true, '', $failures, $checks);
    }
    $all = array_map(static fn ($o) => $o['id'], $svc->list($companyId, [], false, []));
    check('(E2) [] = sin restricción (convención OutletScope)', in_array($oC, $all, true) && in_array($oA, $all, true),
        'ids=' . json_encode($all), $failures, $checks);
} catch (\Throwable $e) {
    $failures++;
    echo "FAIL excepción no esperada: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?', [$did]);
    }
    ncmExecute('DELETE FROM auth_session WHERE useragent = ?', [MARCA_DEL_ARNES]);
}

harnessFinish($failures, $checks);
