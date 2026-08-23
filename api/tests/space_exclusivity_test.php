<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de EXCLUSIVIDAD DE MESAS (context/15, pedido del owner 2026-08-23).
 *
 * Lo que prueba, y por qué de esta manera:
 *
 * El pedido fue explícito en que la regla "una mesa asignada a un mozo no la
 * puede modificar otro" **es autorización y tiene que valer en el backend, no
 * escondiendo botones**. Un test que mirara la UI, o que llamara al service
 * directamente, no distinguiría una regla real de un `if` en un componente.
 * Así que todos los casos pegan al ENDPOINT REAL (`/v1/space-sessions.php`,
 * `/v1/orders-core.php`) en un subproceso, con un token de device real de
 * realm `pos-app`, contra Postgres real, y afirman sobre el STATUS HTTP.
 *
 * El eje del diseño que se verifica acá: bajo `pos-app` el token identifica la
 * TABLET, no a la persona (todos los mozos del turno mandan el mismo Bearer).
 * La persona viaja aparte, en `X-Operator-Token`, firmado por el server al
 * validar el PIN. Los casos (D), (H) e (I) son los que prueban que esa
 * afirmación no se puede falsificar — sin ellos, la regla sería teatro.
 *
 * Casos:
 *   (A) mozo AJENO no puede cancelar/editar/mover/unir la mesa de otro → 403
 *   (B) el DUEÑO de la mesa sí puede operarla                          → 200
 *   (C) el SUPERVISOR (pos.space.override) puede intervenir            → 200
 *   (D) operador NO identificado (sin X-Operator-Token) no toca mesa ajena → 403
 *   (E) mesa SIN mozo asignado: la opera cualquiera (compatibilidad)   → 200
 *   (F) agregar una ORDEN a una mesa ajena también se rechaza          → 403
 *   (G) mover de verdad mueve (la sesión queda en el espacio destino)
 *   (H) token de operador de OTRA empresa no identifica (multi-tenant)
 *   (I) token con la firma manipulada no identifica
 *   (J) alias: se guarda, vuelve en el payload, y es de la SESIÓN
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_space_exclusivity_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/space_exclusivity_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/PermissionCatalog.php';
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

const MARCA_DEL_ARNES = 'space-exclusivity-test';

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
 * proceso del arnés lo mataría entero) y devuelve status + body.
 * Reusa el shim compartido de `permission_enforcement_test.php`.
 */
function hit(string $endpointRel, string $method, string $query, array $body, string $bearer, string $operatorToken = ''): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $endpointRel, $method, $query, json_encode($body), '', $bearer, $operatorToken,
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

    // El status sale del ENVELOPE canónico: bajo SAPI cli http_response_code()
    // no devuelve de forma confiable lo que seteó apiError(). Mismo criterio
    // que permission_enforcement_test.php.
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

/** Extrae `data` del envelope de una respuesta OK. */
function payload(array $res): array
{
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $res['body'], $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env) && isset($env['data']) && is_array($env['data'])) return $env['data'];
    }
    return [];
}

/** Crea (o reusa) un contacto-usuario del comercio con un rol dado. */
function makeContact(string $name, string $roleId, string $companyId): string
{
    $email = strtolower($name) . '.exclusividad@test.local';
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

/** Crea un espacio de prueba y devuelve su id. */
function makeSpace(string $label, string $sectorId, string $companyId, string $outletId): string
{
    global $db;
    $rs = $db->Execute(
        "INSERT INTO space (tableid, companyid, outletid, sectorid, name, seats, shape, status)
         VALUES (gen_random_uuid(), ?, ?, ?, ?, 4, 'square', 1) RETURNING tableid",
        [$companyId, $outletId, $sectorId, $label]
    );
    return (string) ($rs->fields['tableid'] ?? '');
}

/** Abre una sesión directo en BD (el arnés controla el waiter sin pasar por el guard). */
function openSessionRaw(string $spaceId, ?string $waiterId, string $companyId, string $outletId): string
{
    global $db;
    $rs = $db->Execute(
        "INSERT INTO space_session (sessionid, companyid, outletid, tableid, status, waiterid)
         VALUES (gen_random_uuid(), ?, ?, ?, 'open', ?) RETURNING sessionid",
        [$companyId, $outletId, $spaceId, $waiterId]
    );
    return (string) ($rs->fields['sessionid'] ?? '');
}

$spaceIds   = [];
$sectorId   = '';
$deviceIds  = [];

try {
    RoleService::seedCompanyRoles($companyId);

    // ── Roles: uno sin override (mozo), uno con (supervisor) ───────────────
    // Se usan roles CUSTOM y no los seed para que el arnés no dependa de qué
    // permisos tenga hoy `cashier`/`manager` en el tenant fixture: lo que se
    // prueba es el efecto de LA CLAVE, aislado.
    $roleMozo = RoleService::createRole('exclusividad-mozo-' . bin2hex(random_bytes(3)), [
        'pos.sale.create', 'inventory.item.view',
    ], $companyId, $adminId);
    $roleSupervisor = RoleService::createRole('exclusividad-supervisor-' . bin2hex(random_bytes(3)), [
        'pos.sale.create', 'inventory.item.view', 'pos.space.override',
    ], $companyId, $adminId);

    check(
        'catálogo: pos.space.override existe',
        in_array('pos.space.override', PermissionCatalog::ids(), true),
        'la clave no está en PermissionCatalog',
        $failures, $checks
    );

    $ana   = makeContact('Ana',   $roleMozo,       $companyId);   // dueña de la mesa
    $bruno = makeContact('Bruno', $roleMozo,       $companyId);   // mozo ajeno
    $carla = makeContact('Carla', $roleSupervisor, $companyId);   // encargada

    $tokAna   = OperatorAssertion::issue($companyId, $ana);
    $tokBruno = OperatorAssertion::issue($companyId, $bruno);
    $tokCarla = OperatorAssertion::issue($companyId, $carla);

    // ── Device real (realm pos-app) — la tablet compartida ─────────────────
    $issued = DeviceAuth::issueDeviceToken(
        $companyId, $outletId, $registerId, $adminId,
        'Test device — exclusividad',
        MARCA_DEL_ARNES,
        'test-excl-' . bin2hex(random_bytes(6)),
    );
    $deviceIds[] = $issued['deviceId'];
    $bearer = $issued['token'];

    // ── Espacios ───────────────────────────────────────────────────────────
    global $db;
    $rsSector = $db->Execute(
        "INSERT INTO space_sector (sectorid, companyid, outletid, name, status)
         VALUES (gen_random_uuid(), ?, ?, 'Sector exclusividad', 1) RETURNING sectorid",
        [$companyId, $outletId]
    );
    $sectorId = (string) ($rsSector->fields['sectorid'] ?? '');

    $spaceAna    = makeSpace('EXC-A', $sectorId, $companyId, $outletId);
    $spaceLibre  = makeSpace('EXC-LIBRE', $sectorId, $companyId, $outletId);
    $spaceSinMozo = makeSpace('EXC-SINMOZO', $sectorId, $companyId, $outletId);
    $spaceBruno  = makeSpace('EXC-B', $sectorId, $companyId, $outletId);
    $spaceIds    = [$spaceAna, $spaceLibre, $spaceSinMozo, $spaceBruno];

    // ═══════════════════════════════════════════════════════════════════════
    // (A) El mozo AJENO no puede
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A) mozo ajeno → 403 ===\n";

    $sesAna = openSessionRaw($spaceAna, $ana, $companyId, $outletId);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=cancel", [], $bearer, $tokBruno);
    check('(A1) Bruno NO puede cancelar la mesa de Ana', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=update", ['alias' => 'pirata'], $bearer, $tokBruno);
    check('(A2) Bruno NO puede renombrar la mesa de Ana', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=move", ['targetSpaceId' => $spaceLibre], $bearer, $tokBruno);
    check('(A3) Bruno NO puede mover la mesa de Ana', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=request-bill", [], $bearer, $tokBruno);
    check('(A4) Bruno NO puede pedir la cuenta de la mesa de Ana', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // El 403 tiene que ser el de EXCLUSIVIDAD y no cualquier otro — si no, el
    // arnés daría verde ante un 403 de auth/scope y no probaría nada.
    check('(A5) el 403 explica que la mesa es de otro mozo',
        str_contains($res['body'], 'otro mozo'),
        "el mensaje no menciona el motivo: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (D) Operador NO identificado — el bypass trivial: no mandar el header
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) sin afirmación de operador → 403 ===\n";

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=cancel", [], $bearer, '');
    check('(D1) sin X-Operator-Token no se toca una mesa asignada', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (H)(I) La afirmación no se puede falsificar
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (H)(I) afirmación falsificada → no identifica ===\n";

    $tokOtraEmpresa = OperatorAssertion::issue('11111111-1111-1111-1111-111111111111', $ana);
    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=cancel", [], $bearer, $tokOtraEmpresa);
    check('(H1) token de operador de OTRA empresa no identifica', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // Firma manipulada: se conserva el payload (que dice "soy Ana") y se rompe
    // la firma. Es exactamente el ataque que el HMAC tiene que frenar.
    $partes = explode('.', $tokAna);
    $tokTrucho = $partes[0] . '.' . strrev($partes[1]);
    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=cancel", [], $bearer, $tokTrucho);
    check('(I1) token con firma manipulada no identifica', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    check('(I2) verify() rechaza la firma manipulada a nivel unidad',
        OperatorAssertion::verify($tokTrucho, $companyId) === null,
        'verify() aceptó un token con la firma rota', $failures, $checks);

    check('(I3) verify() acepta el token legítimo y devuelve el contacto',
        OperatorAssertion::verify($tokAna, $companyId) === $ana,
        'verify() no resolvió el contacto correcto', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (F) La orden es otra puerta a la misma mesa
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (F) agregar orden a mesa ajena → 403 ===\n";

    $res = hit('v1/orders-core.php', 'POST', '', [
        'spaceSessionId' => $sesAna,
        'items'          => [['itemId' => '00000000-0000-0000-0000-0000000000aa', 'qty' => 1, 'price' => 1000]],
    ], $bearer, $tokBruno);
    check('(F1) Bruno NO puede agregarle órdenes a la mesa de Ana', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) El dueño sí puede
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) el dueño de la mesa opera normal ===\n";

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=update", ['alias' => 'Los del cumpleaños'], $bearer, $tokAna);
    check('(B1) Ana puede renombrar SU mesa', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // (J) El alias volvió, y volvió en la SESIÓN (no en el espacio).
    $data = payload($res);
    check('(J1) el alias vuelve en el payload de la sesión',
        ($data['alias'] ?? null) === 'Los del cumpleaños',
        'alias devuelto: ' . var_export($data['alias'] ?? null, true), $failures, $checks);

    $nombreEspacio = ncmExecute('SELECT name FROM space WHERE tableid = ?', [$spaceAna]);
    check('(J2) el alias NO pisó el nombre fijo del espacio',
        (string) ($nombreEspacio['name'] ?? '') === 'EXC-A',
        'el nombre del espacio quedó: ' . var_export($nombreEspacio['name'] ?? null, true), $failures, $checks);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=request-bill", [], $bearer, $tokAna);
    check('(B2) Ana puede pedir la cuenta de SU mesa', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (G) Mover mueve de verdad
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (G) mover la mesa ===\n";

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=move", ['targetSpaceId' => $spaceLibre], $bearer, $tokAna);
    check('(G1) Ana puede mover SU mesa a un espacio libre', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $fila = ncmExecute('SELECT tableid FROM space_session WHERE sessionid = ?', [$sesAna]);
    check('(G2) la sesión quedó en el espacio destino',
        (string) ($fila['tableid'] ?? '') === $spaceLibre,
        'tableid quedó en: ' . var_export($fila['tableid'] ?? null, true), $failures, $checks);

    $ocupado = ncmExecute(
        "SELECT sessionid FROM space_session WHERE tableid = ? AND status IN ('open','bill_requested')",
        [$spaceAna]
    );
    check('(G3) el espacio de origen quedó libre', empty($ocupado),
        'el espacio origen sigue con una sesión activa', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (C) El supervisor interviene
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) supervisor con pos.space.override ===\n";

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=update", ['alias' => 'intervenida'], $bearer, $tokCarla);
    check('(C1) Carla (override) puede editar la mesa de Ana', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna&action=cancel", [], $bearer, $tokCarla);
    check('(C2) Carla (override) puede cancelar la mesa de Ana', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (E) Mesa sin mozo: compatibilidad con todo lo que existe hoy
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) mesa sin mozo asignado ===\n";

    $sesLibre = openSessionRaw($spaceSinMozo, null, $companyId, $outletId);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesLibre&action=update", ['alias' => 'de todos'], $bearer, $tokBruno);
    check('(E1) sin mozo asignado, cualquier operador la edita', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesLibre&action=cancel", [], $bearer, '');
    check('(E2) sin mozo asignado, ni siquiera hace falta identificarse', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (A6) Unir toca DOS cuentas — se exige ser dueño de las dos
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A6) unir con una mesa ajena ===\n";

    $sesAna2   = openSessionRaw($spaceAna,   $ana,   $companyId, $outletId);
    $sesBruno  = openSessionRaw($spaceBruno, $bruno, $companyId, $outletId);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna2&action=merge", ['targetSessionId' => $sesBruno], $bearer, $tokAna);
    check('(A6) Ana NO puede unir su mesa a la de Bruno', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit('v1/space-sessions.php', 'POST', "id=$sesAna2&action=merge", ['targetSessionId' => $sesBruno], $bearer, $tokCarla);
    check('(C3) Carla (override) sí puede unir las dos', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $origen = ncmExecute('SELECT status, mergedinto FROM space_session WHERE sessionid = ?', [$sesAna2]);
    check('(C4) la sesión origen quedó closed y con mergedinto al destino',
        (string) ($origen['status'] ?? '') === 'closed'
            && (string) ($origen['mergedinto'] ?? '') === $sesBruno,
        'origen quedó: ' . json_encode($origen), $failures, $checks);
} finally {
    // ── Limpieza ───────────────────────────────────────────────────────────
    // Solo lo que creó este arnés. El orden importa: las sesiones referencian
    // espacios, y los espacios el sector.
    foreach ($spaceIds as $sid) {
        if ($sid === '') continue;
        ncmExecute('DELETE FROM space_session WHERE tableid = ?', [$sid]);
        ncmExecute('DELETE FROM space WHERE tableid = ?', [$sid]);
    }
    if ($sectorId !== '') {
        ncmExecute('DELETE FROM space_sector WHERE sectorid = ?', [$sectorId]);
    }
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?::uuid', [$did]);
        ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$did]);
    }
    ncmExecute("DELETE FROM auth_session WHERE useragent = ?", [MARCA_DEL_ARNES]);
}

harnessFinish($failures, $checks);
