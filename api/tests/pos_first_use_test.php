<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Primer uso del POS sin código de pareo ni PIN (context/72 §9).
 *
 * D-P1 — pareo automático desde una sesión de panel:
 *   (P1) sin `settings.device.pair` no se listan cajas ni se crea invitación (403).
 *   (P2) el listado respeta `OutletScope`: un usuario con sucursales asignadas
 *        solo ve las suyas, y pedir una caja ajena da 403.
 *   (P3) el endpoint del PANEL devuelve SOLO el id: ninguna respuesta suya
 *        contiene un token, y la invitación nace sin device.
 *   (P4) el canje por el camino público entrega el token de device UNA vez; el
 *        segundo canje falla sin token.
 *   (P5) una caja con device vivo deja de estar disponible (listado + 409).
 *   (P6) la tenencia no se roba: si otro device toma la caja entre la creación
 *        y el canje, el canje falla SIN token, sin device nuevo y sin tocar la
 *        tenencia del otro.
 *   (P7) el canje re-valida alcance y vencimiento.
 *
 * D-P2 — desbloqueo sin PIN (`/v1/unlock-sole`):
 *   (U1) roster de UNO → 200 y la afirmación atribuye a ese usuario.
 *   (U2) roster de DOS → 403 `pin_required`.
 *   (U3) roster de CERO → 403.
 *   (U4) una sesión de PANEL no puede usarlo (401).
 *   (U5) la regla es dinámica: sumar un segundo usuario lo cierra, darlo de
 *        baja lo reabre.
 *   (U6) regression: `/v1/unlock-pin` sigue entregando el mismo shape.
 *
 * Tenant PROPIO del arnés (no "Verify PY"): el admin del seed es global —cero
 * filas en `contact_outlet`— y aparecería en el roster de toda sucursal, así
 * que no se podría armar un roster de uno.
 *
 * Endpoints reales en subproceso (`_permission_once_cli.php`): `apiError()`
 * hace `exit`.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
require_once dirname(__DIR__) . '/lib/Auth/OperatorAssertion.php';
require_once dirname(__DIR__) . '/lib/services/DeviceInvitationService.php';
require_once dirname(__DIR__) . '/lib/services/RegisterLeaseService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Auth\OperatorAssertion;
use Punto\Api\Services\DeviceInvitationService;
use Punto\Api\Services\RegisterLeaseService;

const FU_MARK = 'pos-first-use-test';

$companyId = '0f1a5e00-0000-4000-8000-0000000000c1';
$o1 = '0f1a5e00-0000-4000-8000-0000000000a1'; // roster: owner
$o2 = '0f1a5e00-0000-4000-8000-0000000000a2'; // roster: owner + scoped
$o3 = '0f1a5e00-0000-4000-8000-0000000000a3'; // roster: owner + cashier
$o4 = '0f1a5e00-0000-4000-8000-0000000000a4'; // roster: nadie
$r1  = '0f1a5e00-0000-4000-8000-0000000000b1';
$r1b = '0f1a5e00-0000-4000-8000-0000000000b2';
$r2  = '0f1a5e00-0000-4000-8000-0000000000b3';
$r2b = '0f1a5e00-0000-4000-8000-0000000000b4';
$r3  = '0f1a5e00-0000-4000-8000-0000000000b5';
$r4  = '0f1a5e00-0000-4000-8000-0000000000b6';
$uOwner   = '0f1a5e00-0000-4000-8000-0000000000d1';
$uScoped  = '0f1a5e00-0000-4000-8000-0000000000d2';
$uCashier = '0f1a5e00-0000-4000-8000-0000000000d3';
$uExtra   = '0f1a5e00-0000-4000-8000-0000000000d4';

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
 * Endpoint real en subproceso.
 *
 * @return array{status:int, raw:string, data:mixed, message:string, reason:string}
 */
function call(string $endpoint, string $method, string $query, array $body, string $panelToken, string $deviceToken): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $endpoint, $method, $query,
        json_encode($body === [] ? new \stdClass() : $body), $panelToken, $deviceToken, '', '',
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'raw' => 'no se pudo abrir el subproceso', 'data' => null, 'message' => '', 'reason' => ''];
    }
    fwrite($pipes[0], $body === [] ? '' : json_encode($body));
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $status = 0;
    $data = null;
    $message = '';
    $reason = '';
    $raw = '';
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $raw = $m[1];
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status  = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
            $data    = $env['data'] ?? null;
            $message = (string) ($env['error']['message'] ?? '');
            $reason  = (string) ($env['error']['details']['reason'] ?? '');
        }
    }
    // Envelopes que no son el canónico (el 401 de `requireCompleteContext()`
    // es `{error, code}`): el status sale de la línea que imprime el helper.
    if ($status === 0 && preg_match('/HTTP_STATUS:(\d+)/', $out, $hm)) {
        $status = (int) $hm[1];
    }
    if ($raw === '' && preg_match('/BODY:(\{.*\})\s*\n?HTTP_STATUS:/s', $out, $bm)) {
        $raw = $bm[1];
    }
    // Bajo cli `http_response_code()` no siempre refleja lo que seteó el
    // endpoint (ver `_permission_once_cli.php`). El 401 del embudo de device
    // (`DeviceAuth::requireCompleteContext()`) se reconoce por su `code`.
    if ($status === 0 && $raw !== '') {
        $alt = json_decode($raw, true);
        if (is_array($alt) && in_array($alt['code'] ?? null, ['session_revoked', 'device_incomplete'], true)) {
            $status = 401;
        }
    }
    return [
        'status'  => $status,
        'raw'     => $raw !== '' ? $raw : substr($out . $err, 0, 800),
        'data'    => $data,
        'message' => $message,
        'reason'  => $reason,
    ];
}

function panelSession(string $roleId, string $companyId, string $outletId, string $userId): string
{
    return authSessionCreate('panel', [
        'companyId' => $companyId,
        'userId'    => $userId,
        'outletId'  => $outletId,
        'roleId'    => $roleId,
        'expiresAt' => date('Y-m-d H:i:s', time() + 3600),
        'userAgent' => FU_MARK,
    ]);
}

/** Device POS vivo en una caja (con sesión activa), como lo deja un pareo. */
function liveDevice(string $companyId, string $outletId, string $registerId, string $userId): array
{
    $created = DeviceAuth::createDevice($companyId, $outletId, $registerId, $userId, FU_MARK, null, null, 'pos');
    $issued  = DeviceAuth::issueTokenForExistingDevice((string) $created['deviceId'], $companyId);
    return ['deviceId' => (string) $created['deviceId'], 'token' => (string) $issued['token']];
}

function registerIds(array $res): array
{
    $rows = is_array($res['data']['registers'] ?? null) ? $res['data']['registers'] : [];
    $ids = array_map(static fn (array $r) => (string) ($r['registerId'] ?? ''), $rows);
    sort($ids);
    return $ids;
}

function attempt(callable $fn): array
{
    try {
        return [$fn(), 0, ''];
    } catch (\Throwable $e) {
        return [null, (int) $e->getCode(), $e->getMessage()];
    }
}

function devicesOn(string $registerId): int
{
    $row = ncmExecute("SELECT count(*) AS n FROM device WHERE registerid = ?::uuid AND status = 1", [$registerId]);
    return (int) ($row['n'] ?? 0);
}

function leaseHolder(string $registerId): string
{
    $row = ncmExecute(
        "SELECT deviceid FROM register_lease WHERE registerid = ?::uuid AND status = 'active'",
        [$registerId]
    );
    return (string) ($row['deviceid'] ?? '');
}

try {
    global $db;

    // ── Fixture ────────────────────────────────────────────────────────────
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config) VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
         ON CONFLICT (companyId) DO UPDATE SET status = EXCLUDED.status, config = EXCLUDED.config",
        [$companyId, json_encode([
            'settingName' => 'Primer uso arnés', 'settingDecimal' => 'no', 'settingThousandSeparator' => 'dot',
            'settingCountry' => 'PY', 'settingCurrency' => 'PYG', 'settingTimeZone' => 'America/Asuncion',
            'settingTaxName' => 'IVA', 'settingLanguage' => 'es', 'settingSocialMedia' => '{}', 'settingObj' => '{}',
        ])]
    );
    foreach ([$o1 => 'Sucursal 1', $o2 => 'Sucursal 2', $o3 => 'Sucursal 3', $o4 => 'Sucursal 4'] as $oid => $name) {
        $db->Execute(
            'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
             ON CONFLICT (outletId) DO UPDATE SET outletStatus = 1',
            [$oid, $name, $companyId]
        );
    }
    foreach ([[$r1, $o1, 'Caja 1'], [$r1b, $o1, 'Caja 1b'], [$r2, $o2, 'Caja 2'], [$r2b, $o2, 'Caja 2b'],
              [$r3, $o3, 'Caja 3'], [$r4, $o4, 'Caja 4']] as [$rid, $oid, $name]) {
        $db->Execute(
            'INSERT INTO register (registerid, registername, registerstatus, outletid, companyid)
             VALUES (?, ?, TRUE, ?, ?) ON CONFLICT (registerid) DO UPDATE SET registerstatus = TRUE',
            [$rid, $name, $oid, $companyId]
        );
    }

    RoleService::seedCompanyRoles($companyId);
    $roleBySlug = static function (string $slug) use ($companyId): string {
        return (string) (ncmExecute(
            "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'=?",
            [$companyId, $slug]
        )['taxonomyid'] ?? '');
    };
    $ownerRole   = $roleBySlug('owner');
    $cashierRole = $roleBySlug('cashier');
    check('fixture: roles owner y cashier', $ownerRole !== '' && $cashierRole !== '', "owner=$ownerRole cashier=$cashierRole", $failures, $checks);
    check('fixture: cashier NO tiene settings.device.pair',
        !RoleService::hasPermission('settings.device.pair', $cashierRole, $companyId), '', $failures, $checks);

    foreach ([
        [$uOwner, 'Duenia Arnes', $ownerRole, '595991910001'],
        [$uScoped, 'Encargado Arnes', $ownerRole, '595991910002'],
        [$uCashier, 'Cajero Arnes', $cashierRole, '595991910003'],
    ] as [$uid, $uname, $role, $phone]) {
        $db->Execute(
            "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, role, companyId)
             VALUES (?, ?, ?, ?, 1, 0, ?, ?)
             ON CONFLICT (contactId) DO UPDATE SET role = EXCLUDED.role, contactStatus = 1",
            [$uid, $uname, $phone, $phone . '@local.test', $role, $companyId]
        );
        $db->Execute('DELETE FROM contact_outlet WHERE contactid = ?::uuid', [$uid]);
    }
    foreach ([[$uOwner, $o1], [$uOwner, $o2], [$uOwner, $o3], [$uScoped, $o2], [$uCashier, $o3]] as [$uid, $oid]) {
        $db->Execute(
            'INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid) ON CONFLICT DO NOTHING',
            [$uid, $oid, $companyId]
        );
    }

    $tokOwner   = panelSession($ownerRole, $companyId, $o1, $uOwner);
    $tokScoped  = panelSession($ownerRole, $companyId, $o2, $uScoped);
    $tokCashier = panelSession($cashierRole, $companyId, $o3, $uCashier);
    $svc = new DeviceInvitationService();
    $inv = 'v1/device_invitations.php';

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (P1) sin settings.device.pair ===\n";
    $res = call($inv, 'GET', 'resource=autopair-registers', [], $tokCashier, '');
    check('(P1a) listado → 403', $res['status'] === 403, "status={$res['status']} {$res['raw']}", $failures, $checks);
    $res = call($inv, 'POST', '', ['action' => 'autopair', 'registerId' => $r3], $tokCashier, '');
    check('(P1b) crear → 403', $res['status'] === 403, "status={$res['status']} {$res['raw']}", $failures, $checks);

    echo "\n=== (P2) alcance por sucursal ===\n";
    $res = call($inv, 'GET', 'resource=autopair-registers', [], $tokScoped, '');
    $expectedScoped = [$r2, $r2b];
    sort($expectedScoped);
    check('(P2a) usuario de la sucursal 2 ve solo sus cajas', $res['status'] === 200 && registerIds($res) === $expectedScoped,
        "status={$res['status']} ids=" . json_encode(registerIds($res)), $failures, $checks);
    $res = call($inv, 'POST', '', ['action' => 'autopair', 'registerId' => $r1], $tokScoped, '');
    check('(P2b) pedir una caja de otra sucursal → 403', $res['status'] === 403, "status={$res['status']} {$res['raw']}", $failures, $checks);
    $res = call($inv, 'GET', 'resource=autopair-registers', [], $tokOwner, '');
    $expectedOwner = [$r1, $r1b, $r2, $r2b, $r3];
    sort($expectedOwner);
    check('(P2c) dueña con sucursales 1-3 ve esas cajas y no la 4', $res['status'] === 200 && registerIds($res) === $expectedOwner,
        'ids=' . json_encode(registerIds($res)), $failures, $checks);

    echo "\n=== (P3) el endpoint del panel devuelve SOLO el id ===\n";
    $res = call($inv, 'POST', '', ['action' => 'autopair', 'registerId' => $r1], $tokOwner, '');
    $invId = (string) ($res['data']['id'] ?? '');
    check('(P3a) crear → 200 con id', $res['status'] === 200 && preg_match('/^[0-9a-f-]{36}$/', $invId) === 1,
        "status={$res['status']} {$res['raw']}", $failures, $checks);
    check('(P3b) la respuesta no trae token ni url', stripos($res['raw'], 'token') === false && stripos($res['raw'], 'url') === false
        && array_keys((array) $res['data']) === ['id', 'expiresAt'], $res['raw'], $failures, $checks);
    $row = ncmExecute('SELECT status, auto_approve, device_id, created_by, module FROM device_invitation WHERE id = ?::uuid', [$invId]);
    check('(P3c) invitación auto-aprobada, sin device, del usuario que la pidió',
        $row && (string) $row['status'] === 'pending' && in_array($row['auto_approve'], [true, 't', 1, '1'], true)
        && ($row['device_id'] ?? null) === null && (string) $row['created_by'] === $uOwner && (string) $row['module'] === 'pos',
        json_encode($row), $failures, $checks);
    check('(P3d) todavía no existe ningún device en la caja', devicesOn($r1) === 0, 'devices=' . devicesOn($r1), $failures, $checks);

    echo "\n=== (P4) canje de un solo uso ===\n";
    [$open1, $code1, $msg1] = attempt(fn () => $svc->open($invId, FU_MARK, '127.0.0.1'));
    $tokDev1 = (string) ($open1['token'] ?? '');
    check('(P4a) primer canje entrega token de device', $code1 === 0 && $tokDev1 !== '' && ($open1['autoApprove'] ?? false) === true,
        "code=$code1 msg=$msg1", $failures, $checks);
    $ctx1 = $tokDev1 !== '' ? DeviceAuth::resolveDeviceToken($tokDev1) : null;
    check('(P4b) el token resuelve a la caja y la sucursal elegidas',
        $ctx1 !== null && $ctx1['registerId'] === $r1 && $ctx1['outletId'] === $o1 && $ctx1['companyId'] === $companyId,
        json_encode($ctx1), $failures, $checks);
    [$open2, $code2] = attempt(fn () => $svc->open($invId, FU_MARK, '127.0.0.1'));
    check('(P4c) segundo canje falla sin token', $open2 === null && in_array($code2, [409, 410], true), "code=$code2", $failures, $checks);
    [$open3, $code3] = attempt(fn () => $svc->open($invId, FU_MARK, '127.0.0.1', str_repeat('a', 64)));
    check('(P4d) segundo canje con secreto inventado también falla', $open3 === null && in_array($code3, [409, 410], true), "code=$code3", $failures, $checks);
    check('(P4e) quedó exactamente un device en la caja', devicesOn($r1) === 1, 'devices=' . devicesOn($r1), $failures, $checks);
    $dev1 = (string) ($open1['deviceId'] ?? '');

    echo "\n=== (P5) caja con device vivo deja de estar disponible ===\n";
    $res = call($inv, 'GET', 'resource=autopair-registers', [], $tokOwner, '');
    check('(P5a) la caja 1 ya no se lista', !in_array($r1, registerIds($res), true), 'ids=' . json_encode(registerIds($res)), $failures, $checks);
    $res = call($inv, 'POST', '', ['action' => 'autopair', 'registerId' => $r1], $tokOwner, '');
    check('(P5b) pedirla igual → 409', $res['status'] === 409, "status={$res['status']} {$res['raw']}", $failures, $checks);

    echo "\n=== (P6) la tenencia no se roba ===\n";
    $claimed = RegisterLeaseService::claim($r1, $companyId, $o1, $dev1, true);
    check('(P6a) el device pareado toma la caja libre', $claimed['registerLeaseId'] !== null && $claimed['created'] === true,
        json_encode($claimed), $failures, $checks);
    // Invitación para 1b mientras está libre; ANTES del canje otro device toma la caja.
    $inv1b = $svc->createAutoPair($companyId, $uOwner, $r1b);
    $other = DeviceAuth::createDevice($companyId, $o1, $r1b, $uOwner, 'Otro', null, null, 'pos');
    $otherId = (string) $other['deviceId'];
    $otherClaim = RegisterLeaseService::claim($r1b, $companyId, $o1, $otherId, true);
    check('(P6b) otro device toma la caja 1b', $otherClaim['registerLeaseId'] !== null, json_encode($otherClaim), $failures, $checks);
    $devicesBefore = devicesOn($r1b);
    [$openT, $codeT, $msgT] = attempt(fn () => $svc->open((string) $inv1b['id'], FU_MARK, '127.0.0.1'));
    check('(P6c) el canje falla sin token', $openT === null && $codeT === 409, "code=$codeT msg=$msgT", $failures, $checks);
    check('(P6d) no se creó ningún device nuevo', devicesOn($r1b) === $devicesBefore, 'devices=' . devicesOn($r1b), $failures, $checks);
    check('(P6e) la tenencia sigue siendo del otro', leaseHolder($r1b) === $otherId, 'holder=' . leaseHolder($r1b), $failures, $checks);
    $st = ncmExecute('SELECT status FROM device_invitation WHERE id = ?::uuid', [(string) $inv1b['id']]);
    check('(P6f) la invitación quedó quemada', (string) ($st['status'] ?? '') === 'consumed', json_encode($st), $failures, $checks);
    $steal = RegisterLeaseService::claim($r1b, $companyId, $o1, $dev1, true);
    check('(P6g) un device ajeno pidiendo tomarla recibe conflicto', $steal['conflict'] !== null && leaseHolder($r1b) === $otherId,
        json_encode($steal), $failures, $checks);

    echo "\n=== (P7) el canje re-valida alcance y vencimiento ===\n";
    $invScoped = $svc->createAutoPair($companyId, $uScoped, $r2b);
    $db->Execute('DELETE FROM contact_outlet WHERE contactid = ?::uuid', [$uScoped]);
    $db->Execute('INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid)', [$uScoped, $o4, $companyId]);
    [$openS, $codeS] = attempt(fn () => $svc->open((string) $invScoped['id'], FU_MARK, '127.0.0.1'));
    check('(P7a) sucursal sacada del alcance entre crear y canjear → sin token', $openS === null && $codeS === 409,
        "code=$codeS", $failures, $checks);
    check('(P7b) y sin device creado', devicesOn($r2b) === 0, 'devices=' . devicesOn($r2b), $failures, $checks);
    $db->Execute('DELETE FROM contact_outlet WHERE contactid = ?::uuid', [$uScoped]);
    $db->Execute('INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid)', [$uScoped, $o2, $companyId]);

    $invExp = $svc->createAutoPair($companyId, $uOwner, $r3);
    $db->Execute("UPDATE device_invitation SET expires_at = now() - interval '1 minute' WHERE id = ?::uuid", [(string) $invExp['id']]);
    [$openE, $codeE] = attempt(fn () => $svc->open((string) $invExp['id'], FU_MARK, '127.0.0.1'));
    check('(P7c) invitación vencida → 410 sin token', $openE === null && $codeE === 410, "code=$codeE", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    $sole = 'v1/unlock-sole.php';
    echo "\n=== (U1) roster de uno ===\n";
    $res = call($sole, 'POST', '', [], '', $tokDev1);
    check('(U1a) sucursal 1 (solo la dueña) → 200', $res['status'] === 200, "status={$res['status']} {$res['raw']}", $failures, $checks);
    check('(U1b) opera a nombre de la dueña', (string) ($res['data']['user']['id'] ?? '') === $uOwner, $res['raw'], $failures, $checks);
    $assertion = (string) ($res['data']['operatorToken'] ?? '');
    check('(U1c) la afirmación firmada verifica y atribuye a la dueña',
        OperatorAssertion::verify($assertion, $companyId) === $uOwner, 'verify=' . var_export(OperatorAssertion::verify($assertion, $companyId), true),
        $failures, $checks);
    check('(U1d) trae la lista de permisos de caja', is_array($res['data']['permissions'] ?? null)
        && in_array('pos.sale.create', (array) $res['data']['permissions'], true), $res['raw'], $failures, $checks);

    echo "\n=== (U2/U3) roster de dos y de cero ===\n";
    $dev2 = liveDevice($companyId, $o2, $r2, $uOwner);
    $res = call($sole, 'POST', '', [], '', $dev2['token']);
    check('(U2) sucursal 2 (dos usuarios) → 403 pin_required', $res['status'] === 403 && $res['reason'] === 'pin_required',
        "status={$res['status']} {$res['raw']}", $failures, $checks);
    check('(U2b) y no entrega afirmación', stripos($res['raw'], 'operatorToken') === false, $res['raw'], $failures, $checks);
    $dev4 = liveDevice($companyId, $o4, $r4, $uOwner);
    $res = call($sole, 'POST', '', [], '', $dev4['token']);
    check('(U3) sucursal 4 (nadie) → 403', $res['status'] === 403 && $res['reason'] === 'pin_required',
        "status={$res['status']} {$res['raw']}", $failures, $checks);

    echo "\n=== (U4) el realm panel no puede usarlo ===\n";
    $res = call($sole, 'POST', '', [], $tokOwner, '');
    check('(U4) sesión de panel → 401 sin afirmación', $res['status'] === 401 && stripos($res['raw'], 'operatorToken') === false,
        "status={$res['status']} {$res['raw']}", $failures, $checks);

    echo "\n=== (U5) la regla es dinámica ===\n";
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, role, companyId)
         VALUES (?, 'Empleado Nuevo', '595991910004', 'nuevo@local.test', 1, 0, ?, ?)
         ON CONFLICT (contactId) DO UPDATE SET contactStatus = 1",
        [$uExtra, $cashierRole, $companyId]
    );
    $db->Execute('INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid) ON CONFLICT DO NOTHING',
        [$uExtra, $o1, $companyId]);
    $res = call($sole, 'POST', '', [], '', $tokDev1);
    check('(U5a) con un segundo usuario en la sucursal → 403', $res['status'] === 403 && $res['reason'] === 'pin_required',
        "status={$res['status']} {$res['raw']}", $failures, $checks);
    $db->Execute('UPDATE contact SET contactStatus = 0 WHERE contactId = ?', [$uExtra]);
    $res = call($sole, 'POST', '', [], '', $tokDev1);
    check('(U5b) dado de baja, vuelve a ser uno → 200', $res['status'] === 200 && (string) ($res['data']['user']['id'] ?? '') === $uOwner,
        "status={$res['status']} {$res['raw']}", $failures, $checks);

    echo "\n=== (U6) regression: unlock-pin mismo shape ===\n";
    $db->Execute('UPDATE contact SET pinhash = ? WHERE contactId = ?', [hash('sha256', '4321'), $uOwner]);
    $res = call('v1/unlock-pin.php', 'POST', '', ['pin' => '4321'], '', $tokDev1);
    check('(U6a) PIN correcto → 200 a nombre de la dueña', $res['status'] === 200 && (string) ($res['data']['user']['id'] ?? '') === $uOwner,
        "status={$res['status']} {$res['raw']}", $failures, $checks);
    $keys = array_keys((array) $res['data']);
    sort($keys);
    check('(U6b) mismo shape que el desbloqueo sin PIN', $keys === ['operatorToken', 'permissions', 'user'], json_encode($keys), $failures, $checks);
    check('(U6c) su afirmación también verifica', OperatorAssertion::verify((string) ($res['data']['operatorToken'] ?? ''), $companyId) === $uOwner,
        '', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (S) PIN del signup al dar de alta el segundo usuario ===\n";
    $company2 = '0f1a5e00-0000-4000-8000-0000000000c2';
    $outlet2  = '0f1a5e00-0000-4000-8000-0000000000a9';
    $owner2   = '0f1a5e00-0000-4000-8000-0000000000e1';
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config) VALUES (?, 'active', 1, 0.00, FALSE, '{}'::jsonb)
         ON CONFLICT (companyId) DO NOTHING",
        [$company2]
    );
    $db->Execute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?) ON CONFLICT (outletId) DO NOTHING',
        [$outlet2, 'Central', $company2]);
    RoleService::seedCompanyRoles($company2);
    $owner2Role = (string) (ncmExecute(
        "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'='owner'",
        [$company2]
    )['taxonomyid'] ?? '');
    // Mismo INSERT que el signup (ncmInsert + Schema::split): la marca tiene que
    // caer en la COLUMNA, no en el JSONB `data`.
    $inserted = ncmInsert(['table' => 'contact', 'records' => [
        'contactId' => $owner2, 'contactName' => 'Duenio Signup', 'contactPhone' => '595991920001',
        'companyId' => $company2, 'outletId' => $outlet2, 'main' => 'true', 'role' => $owner2Role,
        'lockPass' => '1111', 'pinhash' => hash('sha256', '1111'), 'pinIsDefault' => 'true', 'type' => '0',
        'contactStatus' => 1,
    ]]);
    $flagRow = ncmExecute('SELECT pinisdefault, data FROM contact WHERE contactid = ?', [$owner2]);
    check('(S0) el alta con pinIsDefault escribe la columna, no el JSONB',
        $inserted !== false && in_array($flagRow['pinisdefault'] ?? null, [true, 't', 1, '1'], true)
        && !str_contains((string) ($flagRow['data'] ?? ''), 'pinIsDefault'),
        json_encode($flagRow), $failures, $checks);

    // `UsersService` sella `updated_at` con TODAY, que en una request real define
    // el embudo de auth; este proceso no pasa por él.
    if (!defined('TODAY')) define('TODAY', date('Y-m-d H:i:s'));
    $usersSvc = new \Punto\Api\Users\UsersService();
    $tokOwner2 = panelSession($owner2Role, $company2, $outlet2, $owner2);
    $res = call('v1/users.php', 'GET', 'resource=own-pin', [], $tokOwner2, '');
    check('(S1) con un solo usuario no se pide elegir PIN', $res['status'] === 200 && ($res['data']['required'] ?? null) === false,
        "status={$res['status']} {$res['raw']}", $failures, $checks);

    $employee2 = $usersSvc->create($company2, ['name' => 'Empleada Dos', 'password' => 'clave-arnes-123', 'roleId' => $owner2Role]);
    $res = call('v1/users.php', 'GET', 'resource=own-pin', [], $tokOwner2, '');
    check('(S2) al darse de alta el segundo usuario se pide elegir PIN', ($res['data']['required'] ?? null) === true,
        "status={$res['status']} {$res['raw']}", $failures, $checks);
    $res = call('v1/users.php', 'GET', 'resource=own-pin', [], panelSession($owner2Role, $company2, $outlet2, $employee2), '');
    check('(S2b) a la empleada (PIN no por defecto) no se le pide', ($res['data']['required'] ?? null) === false,
        "status={$res['status']} {$res['raw']}", $failures, $checks);

    $res = call('v1/users.php', 'POST', 'resource=own-pin', ['lockPass' => '12'], $tokOwner2, '');
    check('(S3a) PIN inválido → 422', $res['status'] === 422, "status={$res['status']} {$res['raw']}", $failures, $checks);
    $res = call('v1/users.php', 'POST', 'resource=own-pin', ['lockPass' => '5678'], $tokOwner2, '');
    $after = ncmExecute('SELECT pinisdefault, pinhash, lockpass FROM contact WHERE contactid = ?', [$owner2]);
    check('(S3b) elige su PIN → guardado con su hash y sin la marca',
        $res['status'] === 200 && (string) ($after['pinhash'] ?? '') === hash('sha256', '5678')
        && in_array($after['pinisdefault'] ?? null, [false, 'f', 0, '0'], true),
        "status={$res['status']} " . json_encode($after), $failures, $checks);
    $res = call('v1/users.php', 'POST', 'resource=own-pin', ['lockPass' => '9999'], $tokOwner2, '');
    check('(S3c) ya elegido: el atajo sin permiso se cierra (409)', $res['status'] === 409, "status={$res['status']} {$res['raw']}", $failures, $checks);

    $dev2owner = liveDevice($company2, $outlet2, (string) (ncmExecute(
        "INSERT INTO register (registerid, registername, registerstatus, outletid, companyid)
         VALUES (gen_random_uuid(), 'Caja S', TRUE, ?, ?) RETURNING registerid",
        [$outlet2, $company2]
    )['registerid'] ?? ''), $owner2);
    $res = call('v1/users.php', 'GET', 'resource=own-pin', [], '', $dev2owner['token']);
    check('(S4) un device no puede usarlo', $res['status'] === 403, "status={$res['status']} {$res['raw']}", $failures, $checks);

    $db->Execute("UPDATE contact SET pinisdefault = true WHERE contactid = ?", [$owner2]);
    $usersSvc->update($owner2, $company2, ['lockPass' => '4545']);
    $again = ncmExecute('SELECT pinisdefault FROM contact WHERE contactid = ?', [$owner2]);
    check('(S5) editar el PIN desde Equipo también baja la marca',
        in_array($again['pinisdefault'] ?? null, [false, 'f', 0, '0'], true), json_encode($again), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (O) la caja cambia el PIN propio del operador (/v1/operator-pin) ===\n";
    $db->Execute("UPDATE contact SET pinisdefault = true, lockpass = '1111', pinhash = ? WHERE contactid = ?", [hash('sha256', '1111'), $owner2]);
    $usersSvc->update($employee2, $company2, ['lockPass' => '3333']);
    $rosterMap = [];
    foreach ($usersSvc->rosterForOutlet($company2, $outlet2) as $u) {
        $rosterMap[$u['id']] = $u['pinIsDefault'];
    }
    check('(O0) el roster de la caja expone pinIsDefault por usuario',
        ($rosterMap[$owner2] ?? null) === true && ($rosterMap[$employee2] ?? null) === false, json_encode($rosterMap), $failures, $checks);

    $opPin = 'v1/operator-pin.php';
    $callOp = static function (array $body, string $panel, string $device, string $assertion) use ($opPin): array {
        $cmd = [
            PHP_BINARY, '-d', 'variables_order=EGPCS',
            '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
            __DIR__ . '/_permission_once_cli.php', $opPin, 'POST', '', json_encode($body), $panel, $device, $assertion, '',
        ];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        fwrite($pipes[0], json_encode($body));
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $status = 0;
        if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
            $env = json_decode($m[1], true);
            if (is_array($env)) {
                $status = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
                if ($status === 0 && in_array($env['code'] ?? null, ['session_revoked', 'device_incomplete'], true)) {
                    $status = 401;
                }
            }
        }
        return ['status' => $status, 'raw' => substr($out, 0, 500)];
    };
    $ownerPinhash = static fn (): string => (string) (ncmExecute('SELECT pinhash FROM contact WHERE contactid = ?', [$owner2])['pinhash'] ?? '');

    $r = $callOp(['lockPass' => '7777'], '', $dev2owner['token'], '');
    check('(O1) sin afirmación de operador → 403', $r['status'] === 403 && $ownerPinhash() === hash('sha256', '1111'), $r['raw'], $failures, $checks);
    $r = $callOp(['lockPass' => '7777'], '', $dev2owner['token'], OperatorAssertion::issue($company2, $employee2));
    check('(O2) con la afirmación de OTRO usuario no toca el PIN del dueño', $r['status'] === 409 && $ownerPinhash() === hash('sha256', '1111'),
        $r['raw'], $failures, $checks);
    $r = $callOp(['lockPass' => '7777'], '', $dev2owner['token'], OperatorAssertion::issue($companyId, $uOwner));
    check('(O2b) afirmación de otro tenant → 403', $r['status'] === 403 && $ownerPinhash() === hash('sha256', '1111'), $r['raw'], $failures, $checks);
    $r = $callOp(['lockPass' => '7777'], $tokOwner2, '', OperatorAssertion::issue($company2, $owner2));
    check('(O3) desde realm panel → 401', $r['status'] === 401 && $ownerPinhash() === hash('sha256', '1111'), $r['raw'], $failures, $checks);
    $ownerAssertion = OperatorAssertion::issue($company2, $owner2);
    $r = $callOp(['lockPass' => '12'], '', $dev2owner['token'], $ownerAssertion);
    check('(O4a) PIN inválido → 422', $r['status'] === 422, $r['raw'], $failures, $checks);
    $r = $callOp(['lockPass' => '3333'], '', $dev2owner['token'], $ownerAssertion);
    check('(O4b) PIN en uso por otro usuario del tenant → 422', $r['status'] === 422 && $ownerPinhash() === hash('sha256', '1111'),
        $r['raw'], $failures, $checks);
    $r = $callOp(['lockPass' => '7777', 'id' => $employee2, 'contactId' => $employee2], '', $dev2owner['token'], $ownerAssertion);
    $o = ncmExecute('SELECT pinisdefault, pinhash FROM contact WHERE contactid = ?', [$owner2]);
    $e = ncmExecute('SELECT pinhash FROM contact WHERE contactid = ?', [$employee2]);
    check('(O5) afirmación propia → 200, cambia SU PIN (ignora ids del body) y baja pinisdefault',
        $r['status'] === 200 && (string) $o['pinhash'] === hash('sha256', '7777')
        && in_array($o['pinisdefault'] ?? null, [false, 'f', 0, '0'], true) && (string) $e['pinhash'] === hash('sha256', '3333'),
        $r['raw'] . ' ' . json_encode($o), $failures, $checks);
    $r = $callOp(['lockPass' => '8888'], '', $dev2owner['token'], $ownerAssertion);
    check('(O6) con el PIN ya elegido se cierra (409)', $r['status'] === 409 && $ownerPinhash() === hash('sha256', '7777'), $r['raw'], $failures, $checks);
} catch (\Throwable $e) {
    $failures++;
    echo 'FAIL excepción no esperada: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

harnessFinish($failures, $checks);
