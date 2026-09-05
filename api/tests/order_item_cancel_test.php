<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la ANULACIÓN DE ÍTEMS DE COMANDA (motivo obligatorio + ventana de
 * tiempo + atribución a la persona).
 *
 * Corre contra Postgres real, con sesiones de auth reales y el endpoint real en
 * subproceso. Lo que verifica no se puede verificar leyendo el código:
 *
 *   (A) Anular sin motivo se rechaza — vacío, en blanco, o ausente.
 *   (B) El permiso se mide contra el OPERADOR del PIN: sin operador
 *       identificado y con un operador sin la clave, 403.
 *   (C) Con la ventana en 0 (default) un ítem viejo se anula igual: la feature
 *       nace apagada.
 *   (D) Con ventana configurada, un ítem fuera de plazo se rechaza con 422 y
 *       `details` accionable (código, ventana, minutos transcurridos).
 *   (E) El MISMO caso pasa si el operador tiene `pos.order.item.cancel.late` —
 *       y el evento queda registrado igual: la elevación no esconde nada.
 *   (F) Dentro de la ventana no hace falta ninguna elevación.
 *   (G) EL PUNTO DE LA FEATURE: el evento queda atribuido a la PERSONA del PIN
 *       —no al dispositivo, ni al usuario que pareó la tablet hace meses— y
 *       conserva el `actor_module` de la caja. Con el motivo guardado.
 *
 * El caso que más importa es (G). Es el único que no se puede comprobar mirando
 * la respuesta HTTP: el endpoint devuelve 200 igual esté bien o mal atribuido,
 * y el dato equivocado recién se descubre meses después, cuando el dueño abre
 * el reporte y ve que "la tablet" borró cuarenta platos.
 *
 * Uso (ver `run_order_item_cancel_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/order_item_cancel_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/OperatorAssertion.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
require_once dirname(__DIR__) . '/lib/Orders/OrderCoreService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Auth\OperatorAssertion;
use Punto\Api\Orders\OrderCoreService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'order-item-cancel-test';

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
 * Llama al endpoint real en subproceso. Subproceso porque `apiError()` hace
 * `exit`: un 403 dentro del proceso del arnés lo mataría entero.
 *
 * @return array{status:int, body:string, data:mixed, details:mixed, message:string}
 */
function hit(string $orderItemId, array $body, string $bearer, string $operatorToken): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        'v1/orders-core.php', 'POST', 'resource=item-status&id=' . $orderItemId,
        json_encode($body), '', $bearer, $operatorToken, '',
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso', 'data' => null, 'details' => null, 'message' => ''];
    }
    fwrite($pipes[0], json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // El status sale del ENVELOPE canónico: bajo SAPI cli http_response_code()
    // no devuelve de forma confiable lo que seteó apiError().
    $status  = 0;
    $data    = null;
    $details = null;
    $message = '';
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status  = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
            $data    = $env['data'] ?? null;
            $details = $env['error']['details'] ?? null;
            $message = (string) ($env['error']['message'] ?? '');
        }
    }
    return ['status' => $status, 'body' => $out . $err, 'data' => $data, 'details' => $details, 'message' => $message];
}

/** Ventana de anulación del comercio, en minutos. 0 = sin límite (default). */
function setWindow(string $companyId, int $minutes): void
{
    global $db;
    $db->Execute(
        "UPDATE company
            SET config = config || jsonb_build_object('settingOrderItemCancelWindowMinutes', ?::int)
          WHERE companyid = ?",
        [$minutes, $companyId]
    );
}

/**
 * Crea una orden de una línea y devuelve [orderId, orderItemId].
 *
 * Sin `itemId`: la línea lleva nombre y precio propios, que es todo lo que este
 * arnés necesita, y así no depende de qué ítems tenga el fixture ni toca stock.
 */
function mkOrder(OrderCoreService $svc, string $companyId, string $outletId, string $registerId): array
{
    $orderId = $svc->create($companyId, [
        'outletId'   => $outletId,
        'registerId' => $registerId,
        'source'     => 'counter',
        'items'      => [['name' => 'Milanesa del arnés', 'qty' => 2, 'price' => 15000]],
    ]);
    $row = ncmExecute(
        'SELECT orderitemid FROM pos_order_item WHERE orderid = ? AND companyid = ? LIMIT 1',
        [$orderId, $companyId]
    );
    return [$orderId, (string) ($row['orderitemid'] ?? '')];
}

/**
 * Envejece la línea. La ventana se cuenta desde `pos_order_item.created_at`, y
 * esperar 37 minutos reales no es una opción para un test.
 */
function backdate(string $orderItemId, int $minutes): void
{
    global $db;
    $db->Execute(
        "UPDATE pos_order_item SET created_at = now() - (? || ' minutes')::interval WHERE orderitemid = ?",
        [$minutes, $orderItemId]
    );
}

/** El último evento de anulación de ese ítem. */
function cancelEvent(string $companyId, string $orderItemId): ?array
{
    $row = ncmExecute(
        "SELECT actor_kind, actor_id, actor_module, reason, from_status, to_status
           FROM pos_order_event
          WHERE companyid = ? AND orderitemid = ? AND scope = 'item' AND to_status = 'cancelled'
          ORDER BY created_at DESC
          LIMIT 1",
        [$companyId, $orderItemId]
    );
    if (!$row) {
        return null;
    }
    return [
        'actor_kind'   => (string) ($row['actor_kind'] ?? ''),
        'actor_id'     => (string) ($row['actor_id'] ?? ''),
        'actor_module' => (string) ($row['actor_module'] ?? ''),
        'reason'       => (string) ($row['reason'] ?? ''),
    ];
}

$deviceIds = [];

try {
    RoleService::seedCompanyRoles($companyId);

    // Roles CUSTOM, no los seed: lo que se prueba es el efecto de LAS CLAVES
    // aisladas, no qué permisos tenga hoy `cashier`.
    $rolSinNada = RoleService::createRole(
        'itemcancel-sin-' . bin2hex(random_bytes(3)),
        ['pos.sale.create'],
        $companyId,
        $adminId
    );
    $rolBase = RoleService::createRole(
        'itemcancel-base-' . bin2hex(random_bytes(3)),
        ['pos.sale.create', 'pos.order.item.cancel'],
        $companyId,
        $adminId
    );
    $rolLate = RoleService::createRole(
        'itemcancel-late-' . bin2hex(random_bytes(3)),
        ['pos.sale.create', 'pos.order.item.cancel', 'pos.order.item.cancel.late'],
        $companyId,
        $adminId
    );

    global $db;
    $mk = function (string $name, string $roleId) use ($companyId, $db): string {
        $email = strtolower($name) . '.itemcancel@test.local';
        $row = ncmExecute(
            'SELECT contactid FROM contact WHERE companyid = ? AND contactemail = ? AND type = 0 LIMIT 1',
            [$companyId, $email]
        );
        if ($row && !empty($row['contactid'])) {
            $id = (string) $row['contactid'];
            ncmExecute('UPDATE contact SET role = ?, contactstatus = 1 WHERE contactid = ?', [$roleId, $id]);
            return $id;
        }
        $rs = $db->Execute(
            'INSERT INTO contact (contactid, companyid, contactname, contactemail, type, contactstatus, role)
             VALUES (gen_random_uuid(), ?, ?, ?, 0, 1, ?) RETURNING contactid',
            [$companyId, $name, $email, $roleId]
        );
        return (string) ($rs->fields['contactid'] ?? '');
    };

    $mozo      = $mk('ItemCancelMozo',      $rolBase);
    $novato    = $mk('ItemCancelNovato',    $rolSinNada);
    $encargado = $mk('ItemCancelEncargado', $rolLate);

    $tokMozo      = OperatorAssertion::issue($companyId, $mozo);
    $tokNovato    = OperatorAssertion::issue($companyId, $novato);
    $tokEncargado = OperatorAssertion::issue($companyId, $encargado);

    // El device lo parea el ADMIN — es justamente el userId que quedaba
    // registrado como autor de todas las anulaciones antes del arreglo.
    $issued = DeviceAuth::issueDeviceToken(
        $companyId, $outletId, $registerId, $adminId,
        'Test device — anulación de ítems',
        MARCA_DEL_ARNES,
        'test-itemcancel-' . bin2hex(random_bytes(6)),
    );
    $deviceIds[] = $issued['deviceId'];
    $bearer = $issued['token'];

    $svc = new OrderCoreService($db);

    // ═══════════════════════════════════════════════════════════════════════
    // (A) Motivo obligatorio
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A) anular un ítem exige motivo ===\n";
    setWindow($companyId, 0);

    [, $itA] = mkOrder($svc, $companyId, $outletId, $registerId);
    $res = hit($itA, ['status' => 'cancelled', 'reason' => ''], $bearer, $tokMozo);
    check('(A1) motivo vacío → 422', $res['status'] === 422,
        "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(A1b) y el mensaje lo dice', str_contains($res['message'], 'Motivo obligatorio'),
        "mensaje: {$res['message']}", $failures, $checks);

    $res = hit($itA, ['status' => 'cancelled', 'reason' => '    '], $bearer, $tokMozo);
    check('(A2) motivo en blanco (espacios) → 422', $res['status'] === 422,
        "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hit($itA, ['status' => 'cancelled'], $bearer, $tokMozo);
    check('(A3) motivo AUSENTE → 422', $res['status'] === 422,
        "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $sigueVivo = ncmExecute('SELECT status FROM pos_order_item WHERE orderitemid = ?', [$itA]);
    check('(A4) el ítem NO quedó anulado por ninguno de los tres intentos',
        (string) ($sigueVivo['status'] ?? '') === 'pending',
        'status: ' . json_encode($sigueVivo), $failures, $checks);

    // Otras transiciones NO piden motivo — la regla es solo para la anulación.
    $res = hit($itA, ['status' => 'preparing'], $bearer, $tokMozo);
    check('(A5) bumpear a preparing sigue sin pedir motivo', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) El permiso se mide contra el OPERADOR del PIN
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) el permiso es de la PERSONA, no de la tablet ===\n";

    [, $itB] = mkOrder($svc, $companyId, $outletId, $registerId);

    $res = hit($itB, ['status' => 'cancelled', 'reason' => 'se arrepintió'], $bearer, '');
    check('(B1) sin operador identificado → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(B1b) el 403 dice qué hacer (desbloquear con el PIN)',
        str_contains($res['message'], 'PIN'),
        "mensaje: {$res['message']}", $failures, $checks);

    $res = hit($itB, ['status' => 'cancelled', 'reason' => 'se arrepintió'], $bearer, $tokNovato);
    check('(B2) operador SIN pos.order.item.cancel → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(B2b) el 403 nombra la clave que falta',
        str_contains($res['message'], 'requiere: pos.order.item.cancel'),
        "mensaje: {$res['message']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (C) Ventana 0 = sin límite, y es el default
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) con la ventana en 0 la feature está apagada ===\n";
    setWindow($companyId, 0);

    [, $itC] = mkOrder($svc, $companyId, $outletId, $registerId);
    backdate($itC, 720); // doce horas
    $res = hit($itC, ['status' => 'cancelled', 'reason' => 'cocina lo quemó'], $bearer, $tokMozo);
    check('(C1) un ítem de hace 12 horas se anula igual', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (D) Ventana vencida sin elevación → 422 con details accionable
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) fuera de la ventana, sin la clave de elevación ===\n";
    setWindow($companyId, 5);

    [, $itD] = mkOrder($svc, $companyId, $outletId, $registerId);
    backdate($itD, 37);
    $res = hit($itD, ['status' => 'cancelled', 'reason' => 'el cliente se fue'], $bearer, $tokMozo);
    check('(D1) ventana vencida → 422', $res['status'] === 422,
        "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(D2) el 422 trae el código del contrato',
        ($res['details']['code'] ?? null) === 'cancel_window_expired',
        'details: ' . json_encode($res['details']), $failures, $checks);
    check('(D3) y los dos números que el POS necesita para explicarlo',
        ($res['details']['windowMinutes'] ?? null) === 5
            && ($res['details']['elapsedMinutes'] ?? null) === 37,
        'details: ' . json_encode($res['details']), $failures, $checks);

    $vivo = ncmExecute('SELECT status FROM pos_order_item WHERE orderitemid = ?', [$itD]);
    check('(D4) el ítem sigue vivo', (string) ($vivo['status'] ?? '') === 'pending',
        'status: ' . json_encode($vivo), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (E) La elevación del encargado pasa — y queda registrada
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) el encargado con .late anula fuera de plazo ===\n";

    $res = hit($itD, ['status' => 'cancelled', 'reason' => 'el cliente se fue'], $bearer, $tokEncargado);
    check('(E1) con pos.order.item.cancel.late → 200', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $ev = cancelEvent($companyId, $itD);
    check('(E2) la elevación NO esconde nada: hay evento de anulación',
        $ev !== null, 'no se registró el evento', $failures, $checks);
    check('(E3) y queda a nombre del ENCARGADO que se identificó',
        $ev !== null && $ev['actor_id'] === $encargado,
        'actor: ' . json_encode($ev), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (F) Dentro de la ventana no hace falta ninguna elevación
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (F) dentro de la ventana alcanza con la clave base ===\n";

    [, $itF] = mkOrder($svc, $companyId, $outletId, $registerId);
    backdate($itF, 2); // dos minutos, ventana de cinco
    $res = hit($itF, ['status' => 'cancelled', 'reason' => 'lo pidió sin sal'], $bearer, $tokMozo);
    check('(F1) el mozo anula lo que acaba de cargar', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (G) EL PUNTO DE LA FEATURE — la atribución es de la persona
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (G) el evento se atribuye al operador del PIN, no al device ===\n";

    $ev = cancelEvent($companyId, $itF);
    check('(G1) hay evento de anulación', $ev !== null, 'no se registró', $failures, $checks);
    check('(G2) actor_kind = user (antes del arreglo era "device")',
        $ev !== null && $ev['actor_kind'] === 'user',
        'actor_kind: ' . json_encode($ev), $failures, $checks);
    check('(G3) actor_id ES EL MOZO del PIN',
        $ev !== null && $ev['actor_id'] === $mozo,
        'esperaba ' . $mozo . ', vino: ' . json_encode($ev), $failures, $checks);
    check('(G4) y NO el usuario que pareó la tablet (el bug que esto arregla)',
        $ev !== null && $ev['actor_id'] !== $adminId,
        'quedó atribuido al que pareó el device: ' . json_encode($ev), $failures, $checks);
    check('(G5) se conserva el módulo de origen (salió de la caja)',
        $ev !== null && $ev['actor_module'] === 'pos',
        'actor_module: ' . json_encode($ev), $failures, $checks);
    check('(G6) el motivo quedó guardado con el evento',
        $ev !== null && $ev['reason'] === 'lo pidió sin sal',
        'reason: ' . json_encode($ev), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (H) El reporte lee lo que la caja escribió
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Se ejercita el SERVICE y no el endpoint: lo que puede romperse acá es la
    // query (cinco JOINs sobre tablas con convenciones de casing distintas), y
    // eso se rompe igual por los dos caminos. El endpoint en sí es rango +
    // gate + `Roc::build`, los tres copiados de sus hermanos de
    // `api/v1/reports/`.
    echo "\n=== (H) el reporte de anulaciones ===\n";

    $rep = new \Punto\Api\Reports\OrderItemCancellationsService();
    $roc = \Punto\Api\Reports\Roc::build($companyId, $outletId, 'e');
    $out = $rep->report(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'), $roc);

    check('(H1) la query corre y devuelve filas', ($out['totals']['count'] ?? 0) > 0,
        'totals: ' . json_encode($out['totals'] ?? null), $failures, $checks);

    $fila = null;
    foreach ($out['rows'] as $r) {
        if (($r['reason'] ?? '') === 'lo pidió sin sal') { $fila = $r; break; }
    }
    check('(H2) aparece la anulación del mozo', $fila !== null,
        'filas: ' . json_encode(array_slice($out['rows'], 0, 3)), $failures, $checks);
    check('(H3) con el NOMBRE de quien la hizo — el dato que motivó la feature',
        $fila !== null && $fila['actorName'] === 'ItemCancelMozo',
        'actorName: ' . json_encode($fila['actorName'] ?? null), $failures, $checks);
    check('(H4) actorKind = user', $fila !== null && $fila['actorKind'] === 'user',
        'actorKind: ' . json_encode($fila['actorKind'] ?? null), $failures, $checks);
    check('(H5) el monto es qty * price (2 x 15000)',
        $fila !== null && abs(((float) $fila['amount']) - 30000) < 0.001,
        'amount: ' . json_encode($fila['amount'] ?? null), $failures, $checks);
    check('(H6) el contrato trae todas las claves acordadas',
        $fila !== null && array_keys($fila) === [
            'eventId', 'at', 'orderId', 'orderNumber', 'spaceName',
            'itemName', 'qty', 'amount', 'reason', 'actorName', 'actorKind',
        ],
        'claves: ' . json_encode(array_keys($fila ?? [])), $failures, $checks);

    // Un rango que no incluye nada tiene que dar cero, no explotar ni traer todo.
    $vacio = $rep->report('2001-01-01 00:00:00', '2001-01-02 00:00:00', $roc);
    check('(H7) un rango sin anulaciones devuelve 0 y lista vacía',
        ($vacio['totals']['count'] ?? -1) === 0 && $vacio['rows'] === [],
        'vacío: ' . json_encode($vacio['totals'] ?? null), $failures, $checks);
} finally {
    // Limpieza: la ventana vuelve a su default (la feature apagada) y se borran
    // las sesiones de esta corrida. El tenant fixture se recarga entero en cada
    // run del `.sh`, así que las órdenes de prueba quedan.
    setWindow($companyId, 0);
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?', [$did]);
    }
    ncmExecute('DELETE FROM auth_session WHERE useragent = ?', [MARCA_DEL_ARNES]);
}

harnessFinish($failures, $checks);
