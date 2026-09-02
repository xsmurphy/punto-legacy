<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del CONTEO DE STOCK DESDE LA CAJA (context/63 F0 + F1).
 *
 * Corre contra Postgres real, con sesiones de auth reales y el endpoint real
 * en subproceso. Lo que verifica no se puede verificar leyendo el código:
 *
 *   (A) F0 — `stockCountBlind` deja de ser un flag muerto. Con el flag
 *       encendido, el esperado NO sale del servidor mientras el conteo está en
 *       progreso; apagado, sale como siempre. Y al FINALIZAR vuelve a salir,
 *       porque ciego describe el momento de contar, no el registro.
 *   (B) Un conteo completo desde `pos-app` CON el permiso aplica el ajuste y
 *       queda atribuido al OPERADOR del PIN, no al usuario que pareó la tablet.
 *   (C) El MISMO `opId` reenviado no duplica: ni el conteo, ni el ajuste.
 *   (D) Sin operador identificado → 403 (fail-closed), y con un operador SIN
 *       la clave también.
 *   (E) D9 — en modo "solo registro" el conteo se cierra y el ledger NO se
 *       toca; las diferencias quedan igual en `inventory_count_item`.
 *
 * El caso que más importa es (C): es el único de la cola de operaciones que no
 * puede volverse idempotente solo (crear la sesión inserta una fila y consume
 * un correlativo; finalizarla mueve el ledger). Un reenvío duplicado ahí deja
 * el inventario con el DOBLE de la diferencia y nadie se entera.
 *
 * Uso (ver `run_stock_count_from_register_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/stock_count_from_register_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/OperatorAssertion.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
require_once dirname(__DIR__) . '/lib/Settings/StockCountSettings.php';
require_once dirname(__DIR__) . '/lib/services/InventoryCountService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Auth\OperatorAssertion;
use Punto\Api\Settings\StockCountSettings;
use Punto\Api\Services\InventoryCountService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
// Los dos ítems trackeables del fixture — los únicos que sirven para ejercitar
// `Inventory::manageStock()` de verdad.
$itemA = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f';
$itemB = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e60';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'stock-count-from-register-test';
const LISTA_ID        = 'lista-mostrador-test';

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
 */
function hit(string $method, array $body, string $bearer, string $operatorToken, string $opId): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        'v1/inventory_count.php', $method, '', json_encode($body), '', $bearer, $operatorToken, $opId,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso', 'data' => null];
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
    $status = 0;
    $data   = null;
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
            $data   = $env['data'] ?? null;
        }
    }
    return ['status' => $status, 'body' => $out . $err, 'data' => $data];
}

/** Saldo del ledger para un ítem en la sucursal — la definición de D1 (context/52). */
function saldo(string $itemId, string $outletId): float
{
    $row = ncmExecute(
        'SELECT COALESCE(SUM(stockcount), 0) AS q FROM stock WHERE itemid = ? AND outletid = ?',
        [$itemId, $outletId]
    );
    return (float) ($row['q'] ?? 0);
}

/** Escribe los flags de conteo en `company.config.settingObj` y limpia el cache. */
function setFlags(string $companyId, bool $blind, bool $recordOnly): void
{
    global $db;
    $rs = ncmExecute("SELECT config->>'settingObj' AS so FROM company WHERE companyid = ?", [$companyId]);
    $obj = json_decode((string) ($rs['so'] ?? ''), true);
    if (!is_array($obj)) $obj = [];
    $obj['stockCountBlind']      = $blind ? 1 : 0;
    $obj['stockCountRecordOnly'] = $recordOnly ? 1 : 0;
    $db->Execute(
        "UPDATE company SET config = config || jsonb_build_object('settingObj', ?::text) WHERE companyid = ?",
        [json_encode($obj), $companyId]
    );
    StockCountSettings::forget($companyId);
}

/** Deja UNA lista fija con los dos ítems trackeables del fixture. */
function setLista(string $companyId, array $itemIds): void
{
    global $db;
    $lists = [['id' => LISTA_ID, 'name' => 'Mostrador test', 'itemIds' => $itemIds]];
    $db->Execute(
        "UPDATE company SET config = config || jsonb_build_object('stockCountLists', ?::text) WHERE companyid = ?",
        [json_encode($lists), $companyId]
    );
    StockCountSettings::forget($companyId);
}

$deviceIds = [];
$svc = new InventoryCountService();

try {
    RoleService::seedCompanyRoles($companyId);

    // Roles CUSTOM, no los seed: lo que se prueba es el efecto de LA CLAVE
    // aislada, no qué permisos tenga hoy `cashier`.
    $roleSin = RoleService::createRole(
        'stockcount-sin-' . bin2hex(random_bytes(3)),
        ['pos.sale.create', 'inventory.item.view'],
        $companyId,
        $adminId
    );
    $roleCon = RoleService::createRole(
        'stockcount-con-' . bin2hex(random_bytes(3)),
        ['pos.sale.create', 'inventory.item.view', 'pos.stock.count'],
        $companyId,
        $adminId
    );

    global $db;
    $mk = function (string $name, string $roleId) use ($companyId, $db): string {
        $email = strtolower($name) . '.stockcount@test.local';
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

    $cajero    = $mk('StockCountCajero',    $roleSin);
    $encargado = $mk('StockCountEncargado', $roleCon);
    $tokCajero    = OperatorAssertion::issue($companyId, $cajero);
    $tokEncargado = OperatorAssertion::issue($companyId, $encargado);

    $issued = DeviceAuth::issueDeviceToken(
        $companyId, $outletId, $registerId, $adminId,
        'Test device — conteo de stock',
        MARCA_DEL_ARNES,
        'test-stockcount-' . bin2hex(random_bytes(6)),
    );
    $deviceIds[] = $issued['deviceId'];
    $bearer = $issued['token'];

    setLista($companyId, [$itemA, $itemB]);

    // ═══════════════════════════════════════════════════════════════════════
    // (A) F0 — stockCountBlind deja de ser letra muerta
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A) F0: el conteo respeta stockCountBlind ===\n";

    setFlags($companyId, blind: false, recordOnly: false);
    $creado = $svc->create($companyId, $outletId, null, $adminId, 'arnés F0', [], true);
    $countId = (string) $creado['id'];

    $verNoCiego = $svc->get($countId, $companyId);
    $primera    = $verNoCiego['items'][0] ?? [];
    check('(A1) con el flag APAGADO el esperado se publica',
        array_key_exists('expectedQty', $primera) && $primera['expectedQty'] !== null,
        'expectedQty vino null con el flag apagado: ' . json_encode($primera), $failures, $checks);
    check('(A1b) y la sesión lo declara (blind=false)',
        ($verNoCiego['session']['blind'] ?? null) === false,
        'blind no vino false: ' . json_encode($verNoCiego['session']['blind'] ?? null), $failures, $checks);

    setFlags($companyId, blind: true, recordOnly: false);
    $verCiego = $svc->get($countId, $companyId);
    $todosNull = true;
    foreach ($verCiego['items'] as $it) {
        if ($it['expectedQty'] !== null || $it['difference'] !== null) { $todosNull = false; break; }
    }
    check('(A2) con el flag ENCENDIDO el esperado NO sale del servidor', $todosNull,
        'alguna línea trajo expectedQty/difference: ' . json_encode($verCiego['items'][0] ?? []), $failures, $checks);
    check('(A2b) la sesión declara que se está contando a ciegas',
        ($verCiego['session']['blind'] ?? null) === true,
        'blind no vino true', $failures, $checks);

    // El listado tampoco puede ser la puerta de atrás al esperado.
    $lista = $svc->list($companyId, $outletId, 1, 50, 0);
    $filaEnProgreso = null;
    foreach ($lista['rows'] as $r) {
        if ($r['inventoryCountId'] === $countId) { $filaEnProgreso = $r; break; }
    }
    check('(A3) el listado no publica la diferencia de un conteo ciego en progreso',
        $filaEnProgreso !== null && $filaEnProgreso['totalCostDelta'] === null,
        'totalCostDelta no vino null: ' . json_encode($filaEnProgreso), $failures, $checks);

    // Al FINALIZAR sí se ve todo: el owner pidió que cada conteo finalizado
    // quede detallado en el panel con sus diferencias.
    $svc->finish($countId, $companyId, $adminId);
    $verFinal = $svc->get($countId, $companyId);
    check('(A4) finalizado, el esperado vuelve a verse aunque el flag siga encendido',
        ($verFinal['items'][0]['expectedQty'] ?? null) !== null,
        'expectedQty siguió null después de finalizar', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (D) El gate — fail-closed sin operador, 403 sin la clave
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) el permiso se mide contra el OPERADOR del PIN ===\n";

    // Sin `outletId` ni `registerId`: los resuelve el servidor del contexto del
    // dispositivo. Que el body NO pueda nombrarlos es la mitad del punto — ver
    // el caso (D4) más abajo.
    $payload = [
        'action'   => 'registerCount',
        'listId'   => LISTA_ID,
        'listName' => 'Mostrador test',
        'itemIds'  => [$itemA, $itemB],
        'rows'     => [['itemId' => $itemA, 'qty' => 7]],
        'countedAt' => date('c'),
    ];

    $res = hit('POST', $payload, $bearer, '', 'op-sin-operador-' . bin2hex(random_bytes(4)));
    check('(D1) sin operador identificado → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(D1b) el 403 dice qué hacer (desbloquear con el PIN)',
        str_contains($res['body'], 'PIN'),
        "el mensaje no menciona el PIN: {$res['body']}", $failures, $checks);

    $res = hit('POST', $payload, $bearer, $tokCajero, 'op-sin-clave-' . bin2hex(random_bytes(4)));
    check('(D2) operador SIN pos.stock.count → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(D2b) el 403 nombra la clave que falta',
        str_contains($res['body'], 'requiere: pos.stock.count'),
        "el mensaje no nombra la clave: {$res['body']}", $failures, $checks);

    // El GET sigue siendo del panel aunque el embudo acepte `pos-app`.
    $resGet = hit('GET', [], $bearer, $tokEncargado, '');
    check('(D3) la caja no puede LEER conteos aunque tenga la clave',
        $resGet['status'] === 403,
        "esperaba 403, vino {$resGet['status']}: {$resGet['body']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) Conteo completo desde la caja: aplica y queda atribuido a la persona
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) un conteo completo desde pos-app aplica el ajuste ===\n";

    setFlags($companyId, blind: true, recordOnly: false);

    $antesA = saldo($itemA, $outletId);
    $contado = $antesA + 5; // fuerza una diferencia de +5, sea cual sea el saldo
    $opId    = 'op-conteo-' . bin2hex(random_bytes(6));

    $res = hit('POST', array_merge($payload, [
        'rows' => [['itemId' => $itemA, 'qty' => $contado]],
    ]), $bearer, $tokEncargado, $opId);

    check('(B1) operador CON la clave cuenta desde la caja', $res['status'] === 200,
        "esperaba 200, vino {$res['status']}: {$res['body']}", $failures, $checks);

    $despuesA = saldo($itemA, $outletId);
    check('(B2) el ajuste llegó al ledger', abs($despuesA - $contado) < 0.001,
        "saldo esperado $contado, quedó $despuesA", $failures, $checks);
    check('(B3) el servidor declara que aplicó', ($res['data']['applied'] ?? null) === true,
        'applied no vino true: ' . json_encode($res['data']), $failures, $checks);

    $creadoId = (string) ($res['data']['id'] ?? '');
    $fila = ncmExecute(
        'SELECT startedby, finishedby, opid, registerid FROM inventory_count WHERE inventorycountid = ?',
        [$creadoId]
    );
    check('(B4) el conteo queda atribuido al OPERADOR del PIN, no al que pareó la tablet',
        (string) ($fila['startedBy'] ?? '') === $encargado
        && (string) ($fila['finishedBy'] ?? '') === $encargado,
        'startedBy/finishedBy: ' . json_encode([$fila['startedBy'] ?? null, $fila['finishedBy'] ?? null])
        . " — se esperaba $encargado (adminId del pareo: $adminId)", $failures, $checks);
    check('(B5) la caja queda registrada como contexto, resuelta del dispositivo',
        (string) ($fila['registerid'] ?? $fila['registerId'] ?? '') === $registerId,
        'registerid: ' . json_encode($fila), $failures, $checks);

    // El body NO decide la sucursal. Sin este corte, cualquier tablet pareada
    // del comercio puede mover el inventario de una sucursal en la que no está
    // — y el UUID es del tenant, así que ninguna validación de pertenencia lo
    // atraparía.
    //
    // La segunda sucursal la crea el arnés: el fixture trae una sola, y con una
    // sola este caso no se puede ejercitar — quedaría en SKIP, que no verifica
    // nada. Es idempotente (id fijo + ON CONFLICT) para poder recorrer.
    $otroOutletId = '2c9d4e6a-1111-4222-8333-444455556666';
    $db->Execute(
        "INSERT INTO outlet (outletid, outletname, outletstatus, companyid)
         VALUES (?, 'Sucursal ajena (arnés)', 1, ?)
         ON CONFLICT (outletid) DO NOTHING",
        [$otroOutletId, $companyId]
    );

    if ($otroOutletId !== '') {
        $opIdX = 'op-outlet-ajeno-' . bin2hex(random_bytes(6));
        $resX = hit('POST', array_merge($payload, [
            'outletId' => $otroOutletId,           // el cliente MIENTE
            'registerId' => '00000000-0000-4000-8000-000000000000',
            'rows' => [['itemId' => $itemA, 'qty' => 1]],
        ]), $bearer, $tokEncargado, $opIdX);

        $filaX = ncmExecute(
            'SELECT outletid, registerid FROM inventory_count WHERE companyid = ? AND opid = ?',
            [$companyId, $opIdX]
        );
        check('(B7) el outletId del body se IGNORA — manda el del dispositivo',
            $resX['status'] === 200
            && (string) ($filaX['outletid'] ?? $filaX['outletId'] ?? '') === $outletId,
            'el conteo cayó en: ' . json_encode($filaX) . " (se esperaba $outletId)", $failures, $checks);
        check('(B8) y el registerId del body tampoco manda',
            (string) ($filaX['registerid'] ?? $filaX['registerId'] ?? '') === $registerId,
            'registerid: ' . json_encode($filaX), $failures, $checks);
    } else {
        echo "SKIP (B7/B8) el fixture tiene una sola sucursal\n";
    }

    // El ítem que NO se contó no genera ajuste: countedQty NULL es "sin
    // diferencia", no "ajustar a cero".
    $lineaB = ncmExecute(
        'SELECT countedqty FROM inventory_count_item WHERE inventorycountid = ? AND itemid = ?',
        [$creadoId, $itemB]
    );
    check('(B6) el artículo de la lista que no se contó queda en NULL',
        $lineaB !== null && ($lineaB['countedQty'] ?? null) === null,
        'countedQty no vino null: ' . json_encode($lineaB), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (C) Idempotencia — el mismo opId no duplica el ajuste
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) el reenvío del mismo opId no duplica nada ===\n";

    // Entre el envío y el reenvío se VENDE algo. Este paso es el que le da
    // sentido al caso: sin él, reenviar un conteo es inofensivo por accidente
    // —el esperado se recalcula, ya coincide con lo contado, y la diferencia da
    // cero—, así que el arnés estaría verde con la idempotencia rota.
    //
    // Con una venta en el medio, un reenvío NO idempotente "corrige" el saldo
    // de vuelta a lo contado y borra la venta del inventario. Eso es corrupción
    // real de stock, y es exactamente lo que el `opId` evita.
    // Se toma el saldo AHORA y no se asume el de (B): los casos anteriores
    // también movieron el ledger, y una premisa escrita a ojo se rompe cada vez
    // que se agrega un caso arriba.
    $saldoPreVenta = saldo($itemA, $outletId);
    \Punto\App\Domain\Inventory::manageStock([
        'itemId'        => $itemA,
        'source'        => 'sale',
        'count'         => 2,
        'type'          => '-',
        'cogs'          => 0,
        'userId'        => $adminId,
        'transactionId' => null,
        'outletId'      => $outletId,
        'locationId'    => null,
        'note'          => 'arnés: venta entre el envío y el reenvío',
        'date'          => date('Y-m-d H:i:s'),
        'companyId'     => $companyId,
    ]);

    $saldoAntesReenvio = saldo($itemA, $outletId);
    check('(C0) la venta intermedia movió el saldo (premisa del caso)',
        abs($saldoAntesReenvio - ($saldoPreVenta - 2)) < 0.001,
        "saldo esperado " . ($saldoPreVenta - 2) . ", quedó $saldoAntesReenvio", $failures, $checks);

    $res2 = hit('POST', array_merge($payload, [
        'rows' => [['itemId' => $itemA, 'qty' => $contado]],
    ]), $bearer, $tokEncargado, $opId);

    check('(C1) el reenvío responde OK (no es un error para la cola)', $res2['status'] === 200,
        "esperaba 200, vino {$res2['status']}: {$res2['body']}", $failures, $checks);
    check('(C2) y se declara duplicado', ($res2['data']['duplicate'] ?? null) === true,
        'duplicate no vino true: ' . json_encode($res2['data']), $failures, $checks);
    check('(C3) devuelve EL MISMO conteo, no uno nuevo',
        (string) ($res2['data']['id'] ?? '') === $creadoId,
        "id devuelto: " . json_encode($res2['data']['id'] ?? null) . " vs $creadoId", $failures, $checks);
    check('(C4) EL LEDGER NO SE MOVIÓ — sin esto el reenvío borra la venta del medio',
        abs(saldo($itemA, $outletId) - $saldoAntesReenvio) < 0.001,
        'el saldo cambió con el reenvío: ' . $saldoAntesReenvio . ' → ' . saldo($itemA, $outletId),
        $failures, $checks);

    $cuantos = ncmExecute(
        'SELECT COUNT(*) AS n FROM inventory_count WHERE companyid = ? AND opid = ?',
        [$companyId, $opId]
    );
    check('(C5) hay UNA sola sesión con ese opId', (int) ($cuantos['n'] ?? 0) === 1,
        'sesiones con el mismo opId: ' . json_encode($cuantos), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (E) D9 — modo registro: se cierra el conteo y el ledger no se toca
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) D9: en modo registro el conteo no toca el stock ===\n";

    setFlags($companyId, blind: true, recordOnly: true);

    $antesE  = saldo($itemA, $outletId);
    $opIdE   = 'op-registro-' . bin2hex(random_bytes(6));
    $res3 = hit('POST', array_merge($payload, [
        'rows' => [['itemId' => $itemA, 'qty' => $antesE + 3]],
    ]), $bearer, $tokEncargado, $opIdE);

    check('(E1) el conteo se registra igual', $res3['status'] === 200,
        "esperaba 200, vino {$res3['status']}: {$res3['body']}", $failures, $checks);
    check('(E2) el servidor declara que NO aplicó', ($res3['data']['applied'] ?? null) === false,
        'applied no vino false: ' . json_encode($res3['data']), $failures, $checks);
    check('(E3) el ledger quedó intacto', abs(saldo($itemA, $outletId) - $antesE) < 0.001,
        "saldo antes $antesE, después " . saldo($itemA, $outletId), $failures, $checks);

    $idE = (string) ($res3['data']['id'] ?? '');
    $sesionE = ncmExecute('SELECT "status" FROM inventory_count WHERE inventorycountid = ?', [$idE]);
    check('(E4) la sesión igual queda FINALIZADA', (int) ($sesionE['status'] ?? 0) === 2,
        'status: ' . json_encode($sesionE), $failures, $checks);

    $difE = ncmExecute(
        'SELECT "difference" FROM inventory_count_item WHERE inventorycountid = ? AND itemid = ?',
        [$idE, $itemA]
    );
    check('(E5) y la diferencia queda registrada para consultarla',
        $difE !== null && abs((float) ($difE['difference'] ?? 0) - 3) < 0.001,
        'difference: ' . json_encode($difE), $failures, $checks);
} finally {
    // Limpieza: sesiones y devices de esta corrida. El tenant fixture se
    // recarga entero en cada run del `.sh`, así que los conteos quedan.
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?', [$did]);
    }
    ncmExecute('DELETE FROM auth_session WHERE useragent = ?', [MARCA_DEL_ARNES]);
}

harnessFinish($failures, $checks);
