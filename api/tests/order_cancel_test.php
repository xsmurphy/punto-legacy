<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la ANULACIÓN DE COMANDA en sus TRES granos: un ítem suelto, la orden
 * ENTERA y la sesión de una mesa (que cascadea sobre sus órdenes). Motivo
 * obligatorio + ventana de tiempo + atribución a la persona, más el reporte y
 * el bloque del cierre de caja que los muestran.
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
 *   (H) El reporte devuelve la anulación con el nombre de quien la hizo, el
 *       monto que dejó de cobrarse y el contrato completo de claves.
 *   (I) Un ítem de una orden YA COBRADA no se anula, ni siquiera con `.late`:
 *       es una regla de dominio, no de autorización.
 *   (J) LA PUERTA DE AL LADO: cancelar la ORDEN ENTERA pasa por el MISMO gate.
 *       Sin la clave, 403 (antes: `orders-core.php` no tenía una sola llamada a
 *       requirePermission, así que era libre para cualquier device). Fuera de
 *       ventana, 422 con el mismo `details`. Con `.late`, pasa y queda
 *       atribuida a la persona.
 *   (K) LA PUERTA DE AL LADO DE ESA: cancelar la SESIÓN DE UNA MESA cascadea
 *       sobre todas sus órdenes, así que gatear solo la orden dejaba el atajo
 *       abierto justo en el flujo de mesa. Mismo gate, mismo 403.
 *   (L) EL DOBLE CONTEO: el reporte trae los dos granos, y una orden a la que
 *       le anularon líneas una por una y después cancelaron entera NO suma esa
 *       plata dos veces.
 *   (M) El bloque `cancellations` del CIERRE DE CAJA suma lo del turno.
 *   (N) Con control a ciegas, el SERVIDOR no manda esos montos — se verifica
 *       sobre el JSON de la respuesta, no sobre la UI.
 *
 * El caso que más importa es (G). Es el único que no se puede comprobar mirando
 * la respuesta HTTP: el endpoint devuelve 200 igual esté bien o mal atribuido,
 * y el dato equivocado recién se descubre meses después, cuando el dueño abre
 * el reporte y ve que "la tablet" borró cuarenta platos.
 *
 * Uso (ver `run_order_cancel_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/order_cancel_test.php
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

const MARCA_DEL_ARNES = 'order-cancel-test';

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
 * Genérica desde que el gate cubre tres granos: los tres pegan a endpoints
 * distintos con la MISMA credencial y esperan el MISMO contrato de error, así
 * que una sola función es lo que deja comparable el 422 de los tres.
 *
 * @return array{status:int, body:string, data:mixed, details:mixed, message:string}
 */
function call(string $script, string $method, string $query, array $body, string $bearer, string $operatorToken): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $script, $method, $query,
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

/** Anular UN ítem. */
function hit(string $orderItemId, array $body, string $bearer, string $operatorToken): array
{
    return call('v1/orders-core.php', 'POST', 'resource=item-status&id=' . $orderItemId, $body, $bearer, $operatorToken);
}

/** Cancelar la ORDEN ENTERA. */
function hitOrder(string $orderId, array $body, string $bearer, string $operatorToken): array
{
    return call('v1/orders-core.php', 'POST', 'id=' . $orderId . '&action=status', $body, $bearer, $operatorToken);
}

/** Cancelar la SESIÓN de una mesa (cascadea sobre sus órdenes). */
function hitSession(string $sessionId, string $bearer, string $operatorToken): array
{
    return call('v1/space-sessions.php', 'POST', 'id=' . $sessionId . '&action=cancel', [], $bearer, $operatorToken);
}

/** Resumen del turno tal como lo devuelve el endpoint (para el caso a ciegas). */
function hitDrawerSummary(string $bearer, string $operatorToken): array
{
    return call('v1/drawer.php', 'GET', '', [], $bearer, $operatorToken);
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
function mkOrder(OrderCoreService $svc, string $companyId, string $outletId, string $registerId, ?array $items = null): array
{
    $orderId = $svc->create($companyId, [
        'outletId'   => $outletId,
        'registerId' => $registerId,
        'source'     => 'counter',
        'items'      => $items ?? [['name' => 'Milanesa del arnés', 'qty' => 2, 'price' => 15000]],
    ]);
    $row = ncmExecute(
        'SELECT orderitemid FROM pos_order_item WHERE orderid = ? AND companyid = ? LIMIT 1',
        [$orderId, $companyId]
    );
    return [$orderId, (string) ($row['orderitemid'] ?? '')];
}

/**
 * Los ids de las líneas de una orden, en el orden en que se cargaron. Hace
 * falta para el caso del doble conteo, que necesita anular una línea concreta
 * y después mirar qué monto le queda a la orden.
 */
function orderItemIds(string $companyId, string $orderId): array
{
    $out = [];
    foreach (ncmRows(
        'SELECT orderitemid FROM pos_order_item
          WHERE orderid = ? AND companyid = ? AND parentorderitemid IS NULL
          ORDER BY created_at, orderitemid',
        [$orderId, $companyId]
    ) as $r) {
        $out[] = (string) $r['orderitemid'];
    }
    return $out;
}

/** Envejece la ORDEN (la ventana del grano orden sale de `pos_order.created_at`). */
function backdateOrder(string $orderId, int $minutes): void
{
    global $db;
    $db->Execute(
        "UPDATE pos_order SET created_at = now() - (? || ' minutes')::interval WHERE orderid = ?",
        [$minutes, $orderId]
    );
}

/** El último evento de cancelación de la ORDEN. */
function orderCancelEvent(string $companyId, string $orderId): ?array
{
    $row = ncmExecute(
        "SELECT actor_kind, actor_id, actor_module, reason
           FROM pos_order_event
          WHERE companyid = ? AND orderid = ? AND scope = 'order' AND to_status = 'cancelled'
          ORDER BY created_at DESC
          LIMIT 1",
        [$companyId, $orderId]
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

/** Prende/apaga el control a ciegas de la caja del fixture. */
function setBlind(string $registerId, string $companyId, bool $blind): void
{
    global $db;
    $db->Execute(
        "UPDATE register
            SET data = COALESCE(data, '{}'::jsonb) || jsonb_build_object('registerBlindControl', ?::boolean)
          WHERE registerId = ? AND companyId = ?",
        [$blind ? 'true' : 'false', $registerId, $companyId]
    );
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
        'Test device — anulación de comanda',
        MARCA_DEL_ARNES,
        'test-ordercancel-' . bin2hex(random_bytes(6)),
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

    $rep = new \Punto\Api\Reports\OrderCancellationsService();
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
            'eventId', 'at', 'orderId', 'orderNumber', 'spaceName', 'scope',
            'itemName', 'qty', 'itemCount', 'amount', 'reason', 'actorName', 'actorKind',
        ],
        'claves: ' . json_encode(array_keys($fila ?? [])), $failures, $checks);
    check('(H6b) una fila de ítem se rotula scope=item, sin itemCount',
        $fila !== null && $fila['scope'] === 'item' && $fila['itemCount'] === null,
        'fila: ' . json_encode($fila), $failures, $checks);

    // Un rango que no incluye nada tiene que dar cero, no explotar ni traer todo.
    $vacio = $rep->report('2001-01-01 00:00:00', '2001-01-02 00:00:00', $roc);
    check('(H7) un rango sin anulaciones devuelve 0 y lista vacía',
        ($vacio['totals']['count'] ?? -1) === 0 && $vacio['rows'] === [],
        'vacío: ' . json_encode($vacio['totals'] ?? null), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (I) Una orden YA COBRADA no se edita, y no hay permiso que lo habilite
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Es el guard de DOMINIO (no de autorización) de `updateItemStatus`: si el
    // ítem ya viajó en una venta emitida, sacarlo de la comanda deja la comanda
    // y la factura impresa diciendo cosas distintas. Se prueba con el ENCARGADO
    // —el que tiene `.late` y pasa cualquier ventana— justamente para dejar
    // asentado que la elevación por PIN no lo saltea: no es una regla de quién,
    // es una regla de qué.
    echo "\n=== (I) un ítem de una orden ya cobrada no se anula ===\n";

    [$ordI, $itI] = mkOrder($svc, $companyId, $outletId, $registerId);

    // Venta mínima real (FK de order_transaction_link → transaction) y el
    // vínculo de cobro por el mismo camino que usa markPaid().
    $txId = ncmExecute('SELECT gen_random_uuid() AS id')['id'];
    $db->Execute(
        'INSERT INTO transaction (transactionId, transactionTotal, userId, outletId, companyId)
         VALUES (?, ?, ?, ?, ?)',
        [$txId, 30000, $mozo, $outletId, $companyId]
    );
    (new \Punto\Api\Services\TransactionLinkService())->linkOrder($companyId, $ordI, $txId);

    $res = hit($itI, ['status' => 'cancelled', 'reason' => 'el cliente se arrepintió'], $bearer, $tokEncargado);
    check('(I1) ni el encargado con .late puede anularlo → 422', $res['status'] === 422,
        "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(I2) y el mensaje manda a devolución o nota de crédito',
        str_contains($res['message'], 'nota de crédito'),
        "mensaje: {$res['message']}", $failures, $checks);

    $vivoI = ncmExecute('SELECT status FROM pos_order_item WHERE orderitemid = ?', [$itI]);
    check('(I3) el ítem sigue vivo', (string) ($vivoI['status'] ?? '') === 'pending',
        'status: ' . json_encode($vivoI), $failures, $checks);

    check('(I4) bumpear ese mismo ítem sigue permitido (el guard es solo de anulación)',
        hit($itI, ['status' => 'preparing'], $bearer, $tokEncargado)['status'] === 200,
        'una orden cobrada se sigue cocinando y entregando', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (J) LA PUERTA DE AL LADO — cancelar la ORDEN ENTERA
    // ═══════════════════════════════════════════════════════════════════════
    //
    // El agujero que este cambio cierra. `orders-core.php` no tenía UNA SOLA
    // llamada a `requirePermission`/`hasPermission`: anular una línea exigía
    // permiso del operador y respetaba la ventana, y borrar la comanda entera
    // —ocho líneas de un click— era libre para cualquier device, a cualquier
    // hora. Para el modelo de amenaza que motivó la feature (cobrar en mano y
    // hacer desaparecer el registro) la puerta abierta era la más ancha.
    echo "\n=== (J) cancelar la orden entera pasa por el MISMO gate ===\n";
    setWindow($companyId, 0);

    [$ordJ] = mkOrder($svc, $companyId, $outletId, $registerId);
    $res = hitOrder($ordJ, ['status' => 'cancelled', 'reason' => 'no va'], $bearer, $tokNovato);
    check('(J1) operador SIN pos.order.item.cancel → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(J1b) el 403 nombra la clave que falta',
        str_contains($res['message'], 'requiere: pos.order.item.cancel'),
        "mensaje: {$res['message']}", $failures, $checks);

    $res = hitOrder($ordJ, ['status' => 'cancelled', 'reason' => 'no va'], $bearer, '');
    check('(J2) sin operador identificado → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $vivoJ = ncmExecute('SELECT status FROM pos_order WHERE orderid = ?', [$ordJ]);
    check('(J3) la orden sigue viva tras los dos rechazos',
        (string) ($vivoJ['status'] ?? '') !== 'cancelled',
        'status: ' . json_encode($vivoJ), $failures, $checks);

    // Ventana vencida — se cuenta desde `pos_order.created_at`, no desde el
    // ítem más nuevo: si contara desde el ítem, agregarle una línea a una
    // comanda vieja reabriría la ventana para cancelarla entera.
    setWindow($companyId, 5);
    backdateOrder($ordJ, 37);
    $res = hitOrder($ordJ, ['status' => 'cancelled', 'reason' => 'no va'], $bearer, $tokMozo);
    check('(J4) fuera de ventana, sin .late → 422', $res['status'] === 422,
        "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(J5) con el MISMO contrato de details que el ítem',
        ($res['details']['code'] ?? null) === 'cancel_window_expired'
            && ($res['details']['windowMinutes'] ?? null) === 5
            && ($res['details']['elapsedMinutes'] ?? null) === 37,
        'details: ' . json_encode($res['details']), $failures, $checks);

    $res = hitOrder($ordJ, ['status' => 'cancelled', 'reason' => 'se retiró la mesa'], $bearer, $tokEncargado);
    check('(J6) el encargado con .late sí puede → 200', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $evJ = orderCancelEvent($companyId, $ordJ);
    check('(J7) y queda un evento de orden atribuido a la PERSONA del PIN',
        $evJ !== null && $evJ['actor_kind'] === 'user' && $evJ['actor_id'] === $encargado,
        'evento: ' . json_encode($evJ), $failures, $checks);
    check('(J8) con el motivo guardado',
        $evJ !== null && $evJ['reason'] === 'se retiró la mesa',
        'evento: ' . json_encode($evJ), $failures, $checks);

    // Dentro de la ventana alcanza la clave base: la elevación no se pide de más.
    [$ordJ2] = mkOrder($svc, $companyId, $outletId, $registerId);
    $res = hitOrder($ordJ2, ['status' => 'cancelled', 'reason' => 'error de carga'], $bearer, $tokMozo);
    check('(J9) dentro de la ventana el mozo cancela su propia orden', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // El motivo sigue siendo obligatorio — el gate no lo reemplazó.
    [$ordJ3] = mkOrder($svc, $companyId, $outletId, $registerId);
    $res = hitOrder($ordJ3, ['status' => 'cancelled', 'reason' => '   '], $bearer, $tokMozo);
    check('(J10) el gate NO reemplazó al motivo obligatorio → 422', $res['status'] === 422,
        "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // Y una transición que NO es cancelación sigue sin pedir la clave: el gate
    // se aplica a `cancelled`, no a `action=status` entero. `sent` y no
    // `in_progress` porque la orden viene de (J10) en `open`, y
    // `ORDER_TRANSITIONS` solo deja `open → sent|cancelled` — pedir una
    // transición ilegal daría 422 por el motivo equivocado y el arnés no
    // probaría nada del gate.
    $res = hitOrder($ordJ3, ['status' => 'sent'], $bearer, $tokNovato);
    check('(J11) otra transición de orden sigue sin exigir la clave', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (K) LA PUERTA DE AL LADO DE ESA — cancelar la SESIÓN DE UNA MESA
    // ═══════════════════════════════════════════════════════════════════════
    //
    // `SpaceSessionService::cancel()` cascadea sobre TODAS las órdenes vivas de
    // la sesión. Gatear la orden y dejar esto abierto habría movido el atajo un
    // click más allá, justo en el flujo de mesa que es donde el comercio
    // describió el problema.
    echo "\n=== (K) cancelar la mesa cascadea, así que pasa por el mismo gate ===\n";
    setWindow($companyId, 0);

    $sectorId = (new \Punto\Api\Spaces\SpaceSectorService($db))->ensureDefaultSector($companyId, $outletId);
    $tableRs = $db->Execute(
        "INSERT INTO space (tableid, companyid, outletid, sectorid, name, seats, status)
         VALUES (gen_random_uuid(), ?, ?, ?, ?, 4, 1) RETURNING tableid",
        [$companyId, $outletId, $sectorId, 'Mesa arnés ' . bin2hex(random_bytes(2))]
    );
    $tableId  = (string) ($tableRs->fields['tableid'] ?? '');
    $spaceSvc = new \Punto\Api\Spaces\SpaceSessionService($db);
    $sessionK = $spaceSvc->open($companyId, $tableId);
    $sessionKId = (string) ($sessionK['id'] ?? $sessionK['sessionId'] ?? '');

    check('(K0) la mesa del arnés abrió sesión', $sessionKId !== '',
        'sesión: ' . json_encode($sessionK), $failures, $checks);

    $res = hitSession($sessionKId, $bearer, $tokNovato);
    check('(K1) operador SIN pos.order.item.cancel no cancela la mesa → 403',
        $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $vivaK = ncmExecute('SELECT status FROM space_session WHERE sessionid = ?', [$sessionKId]);
    check('(K2) la sesión sigue abierta', (string) ($vivaK['status'] ?? '') === 'open',
        'status: ' . json_encode($vivaK), $failures, $checks);

    $res = hitSession($sessionKId, $bearer, $tokMozo);
    check('(K3) con la clave, sí → 200', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (L) EL DOBLE CONTEO — el caso que obliga a que el reporte piense
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Una orden de tres líneas: se anula UNA y después se cancela la orden
    // ENTERA. Son dos eventos reales y los dos tienen que figurar, pero la
    // plata de la primera línea NO puede contarse dos veces — si se contara, el
    // reporte diría que se anuló más de lo que la orden valía, y ese total es
    // justamente el número que el dueño mira.
    //
    // La invariante: cada línea aporta su plata a la anulación más TEMPRANA que
    // la cubre. Ver `Punto\Api\Orders\OrderCancellationQuery`.
    echo "\n=== (L) dos granos en el mismo reporte, sin contar la plata dos veces ===\n";
    setWindow($companyId, 0);

    $marcaL = 'doble-conteo-' . bin2hex(random_bytes(3));
    [$ordL] = mkOrder($svc, $companyId, $outletId, $registerId, [
        ['name' => 'Línea anulada suelta',         'qty' => 2, 'price' => 10000], // 20.000
        ['name' => 'Línea que se va con la orden', 'qty' => 1, 'price' => 7000],  //  7.000
        ['name' => 'Otra que se va con la orden',  'qty' => 3, 'price' => 1000],  //  3.000
    ]);
    $itemsL = orderItemIds($companyId, $ordL);
    check('(L0) la orden del caso tiene tres líneas', count($itemsL) === 3,
        'ids: ' . json_encode($itemsL), $failures, $checks);

    $res = hit($itemsL[0], ['status' => 'cancelled', 'reason' => $marcaL . ' item'], $bearer, $tokMozo);
    check('(L1) se anula la primera línea', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $res = hitOrder($ordL, ['status' => 'cancelled', 'reason' => $marcaL . ' orden'], $bearer, $tokMozo);
    check('(L2) y después la orden entera', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $rep  = new \Punto\Api\Reports\OrderCancellationsService();
    $roc  = \Punto\Api\Reports\Roc::build($companyId, $outletId, 'e');
    $outL = $rep->report(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'), $roc);

    $filaItemL = null;
    $filaOrdL  = null;
    foreach ($outL['rows'] as $r) {
        if (($r['reason'] ?? '') === $marcaL . ' item')  { $filaItemL = $r; }
        if (($r['reason'] ?? '') === $marcaL . ' orden') { $filaOrdL  = $r; }
    }
    check('(L3) los DOS eventos figuran — el de ítem y el de orden',
        $filaItemL !== null && $filaOrdL !== null,
        'item: ' . json_encode($filaItemL) . ' orden: ' . json_encode($filaOrdL), $failures, $checks);
    check('(L4) la fila de la orden se rotula scope=order, sin nombre ni qty',
        $filaOrdL !== null && $filaOrdL['scope'] === 'order'
            && $filaOrdL['itemName'] === null && $filaOrdL['qty'] === null,
        'fila: ' . json_encode($filaOrdL), $failures, $checks);
    check('(L5) la fila de ítem vale 2 x 10.000 = 20.000',
        $filaItemL !== null && abs(((float) $filaItemL['amount']) - 20000) < 0.001,
        'amount: ' . json_encode($filaItemL['amount'] ?? null), $failures, $checks);
    // EL CHEQUEO QUE IMPORTA: 7.000 + 3.000 = 10.000. Si la orden sumara sus
    // tres líneas darían 30.000 y el reporte contaría 20.000 dos veces.
    check('(L6) la fila de la orden EXCLUYE la línea ya anulada (7.000 + 3.000)',
        $filaOrdL !== null && abs(((float) $filaOrdL['amount']) - 10000) < 0.001,
        'amount: ' . json_encode($filaOrdL['amount'] ?? null)
            . ' — si dice 30000, está contando la línea que ya se había anulado', $failures, $checks);
    check('(L7) y itemCount cuenta 2 líneas, no 3',
        $filaOrdL !== null && $filaOrdL['itemCount'] === 2,
        'itemCount: ' . json_encode($filaOrdL['itemCount'] ?? null), $failures, $checks);
    check('(L8) entre las dos filas suman EXACTAMENTE el valor de la orden (30.000)',
        $filaItemL !== null && $filaOrdL !== null
            && abs(((float) $filaItemL['amount'] + (float) $filaOrdL['amount']) - 30000) < 0.001,
        'suma: ' . json_encode([$filaItemL['amount'] ?? null, $filaOrdL['amount'] ?? null]),
        $failures, $checks);

    // El total del rango se calcula con la MISMA expresión que las filas: si
    // divergieran, la suma de lo que se ve en pantalla no daría el número de
    // arriba y nadie sabría a cuál creerle.
    $sumaFilas = 0.0;
    foreach ($outL['rows'] as $r) { $sumaFilas += (float) $r['amount']; }
    check('(L9) totals.amount = la suma de las filas (misma fórmula, un solo lugar)',
        abs($sumaFilas - (float) $outL['totals']['amount']) < 0.01,
        'filas: ' . $sumaFilas . ' vs totals: ' . json_encode($outL['totals']), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (M) EL BLOQUE DEL CIERRE DE CAJA
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Es el pedido textual del comercio: "¿va a figurar que se borraron esos
    // productos? — Sí, en tu reporte vos vas a ver eso." El reporte por rango
    // del panel no alcanza: el dueño mira el cierre del turno.
    //
    // El turno se abre PRIMERO y las anulaciones se hacen DESPUÉS, a propósito:
    // el bloque acota por `created_at > drawerOpenDate`, así que este orden es
    // el que prueba que la ventana del turno es la que manda.
    echo "\n=== (M) las anulaciones del turno figuran en el cierre de caja ===\n";
    setWindow($companyId, 0);

    $drawerSvc = new \Punto\Api\Services\DrawerService(
        new \Punto\Api\Context\TenantContext(
            companyId: $companyId,
            outletId: $outletId,
            userId: $adminId,
            registerId: $registerId,
            roleId: (string) $roleId,
        )
    );
    // Turno limpio: si el fixture dejó uno abierto de otra corrida, se cierra.
    if ($drawerSvc->isOpen($registerId, $outletId, $companyId)) {
        $drawerSvc->close(0.0, date('Y-m-d H:i:s'), $adminId);
    }
    $abierto = $drawerSvc->open(0.0, date('Y-m-d H:i:s'), $adminId);
    check('(M0) el turno del arnés abrió', $abierto === true,
        'open() devolvió: ' . json_encode($abierto), $failures, $checks);

    $marcaM = 'cierre-' . bin2hex(random_bytes(3));
    [$ordM, $itM] = mkOrder($svc, $companyId, $outletId, $registerId, [
        ['name' => 'Anulado en el turno', 'qty' => 1, 'price' => 5000],
    ]);
    $res = hit($itM, ['status' => 'cancelled', 'reason' => $marcaM], $bearer, $tokMozo);
    check('(M1) se anula un ítem con el turno ya abierto', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $summary = $drawerSvc->getSummary($registerId, $outletId, $companyId);
    check('(M2) el resumen del turno trae el bloque `cancellations`',
        is_array($summary) && isset($summary['cancellations']),
        'resumen: ' . json_encode(array_keys($summary ?? [])), $failures, $checks);
    check('(M3) con al menos la anulación de este turno',
        (int) ($summary['cancellations']['count'] ?? 0) >= 1,
        'bloque: ' . json_encode($summary['cancellations'] ?? null), $failures, $checks);
    check('(M4) y su monto (5.000) suma al total anulado del turno',
        ((float) ($summary['cancellations']['amount'] ?? 0)) >= 5000,
        'bloque: ' . json_encode($summary['cancellations'] ?? null), $failures, $checks);

    $filaM = null;
    foreach (($summary['cancellations']['rows'] ?? []) as $r) {
        if (($r['reason'] ?? '') === $marcaM) { $filaM = $r; break; }
    }
    check('(M5) la fila trae QUIÉN la hizo — que es a lo que va el bloque',
        $filaM !== null && ($filaM['actorName'] ?? null) === 'ItemCancelMozo',
        'fila: ' . json_encode($filaM), $failures, $checks);

    // La plata anulada NO se resta del arqueo: nunca entró al cajón, así que
    // restarla desfasaría el cierre. Es información de control, no un
    // componente del arqueo.
    $summarySinAnul = \Punto\Api\Services\DrawerService::composeSummary(
        ['drawerOpenDate' => (string) $summary['date'], 'drawerOpenAmount' => 0.0],
        ['amount' => 0.0],
        ['total' => 0.0, 'tips' => 0.0],
        []
    );
    check('(M6) el bloque no toca el arqueo: sin anulaciones el total es el mismo',
        abs((float) $summarySinAnul['total'] - 0.0) < 0.001
            && (int) $summarySinAnul['cancellations']['count'] === 0,
        'control: ' . json_encode($summarySinAnul['cancellations']), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (N) CONTROL A CIEGAS — fail-CLOSED, en el SERVIDOR
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Hasta la mig 169 el ocultamiento del resumen vivía ENTERAMENTE en el
    // front y se caía con las devtools abiertas. Un bloque nuevo que se
    // escondiera solo en el cliente reabriría ese agujero — y este es más
    // sensible que un total: nombra personas. Se verifica sobre el JSON de la
    // respuesta del endpoint, no sobre la UI.
    echo "\n=== (N) a ciegas, el SERVIDOR no manda las anulaciones ===\n";

    $res = hitDrawerSummary($bearer, $tokMozo);
    check('(N0) con la caja normal el endpoint sí las manda',
        $res['status'] === 200 && (int) ($res['data']['cancellations']['count'] ?? 0) >= 1,
        'data: ' . json_encode($res['data']['cancellations'] ?? null), $failures, $checks);

    setBlind($registerId, $companyId, true);
    $resCiego = hitDrawerSummary($bearer, $tokMozo);
    check('(N1) a ciegas la respuesta se marca blind',
        $resCiego['status'] === 200 && ($resCiego['data']['blind'] ?? null) === true,
        'data: ' . json_encode($resCiego['data'] ?? null), $failures, $checks);
    check('(N2) y el bloque de anulaciones viene en CERO, sin filas',
        (int) ($resCiego['data']['cancellations']['count'] ?? -1) === 0
            && (float) ($resCiego['data']['cancellations']['amount'] ?? -1) === 0.0
            && ($resCiego['data']['cancellations']['rows'] ?? null) === [],
        'bloque: ' . json_encode($resCiego['data']['cancellations'] ?? null), $failures, $checks);
    // Lo que se prueba acá es que el dato NO VIAJA, no que la UI no lo pinte:
    // el JSON crudo no puede contener el nombre de quien anuló.
    check('(N3) el JSON entero no menciona a quien anuló',
        !str_contains(json_encode($resCiego['data']), 'ItemCancelMozo'),
        'respuesta: ' . json_encode($resCiego['data']), $failures, $checks);
    setBlind($registerId, $companyId, false);

} finally {
    // Limpieza: la ventana vuelve a su default (la feature apagada), la caja
    // sale del control a ciegas y se borran las sesiones de esta corrida. El
    // tenant fixture se recarga entero en cada run del `.sh`, así que las
    // órdenes de prueba quedan.
    //
    // El turno que abrió el caso (M) se cierra: dejarlo abierto haría que la
    // PRÓXIMA corrida contra la misma base arrancara con una caja abierta que
    // no abrió — y `uidx_drawer_register_open` la haría fallar con
    // 'Already Open' en vez de con un mensaje que se entienda.
    setWindow($companyId, 0);
    setBlind($registerId, $companyId, false);
    try {
        $cleanupDrawer = new \Punto\Api\Services\DrawerService(
            new \Punto\Api\Context\TenantContext(
                companyId: $companyId,
                outletId: $outletId,
                userId: $adminId,
                registerId: $registerId,
                roleId: (string) $roleId,
            )
        );
        if ($cleanupDrawer->isOpen($registerId, $outletId, $companyId)) {
            $cleanupDrawer->close(0.0, date('Y-m-d H:i:s'), $adminId);
        }
    } catch (\Throwable $e) {
        // La limpieza NUNCA puede tapar el resultado del arnés.
        echo "[cleanup] no se pudo cerrar el turno del arnés: " . $e->getMessage() . "\n";
    }
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?', [$did]);
    }
    ncmExecute('DELETE FROM auth_session WHERE useragent = ?', [MARCA_DEL_ARNES]);
}

harnessFinish($failures, $checks);
