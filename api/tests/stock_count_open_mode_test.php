<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del CONTEO NO CIEGO (context/63 F2).
 *
 * Lo que se prueba es una sola afirmación con varias caras: el modo de conteo
 * lo decide la PERSONA, lo resuelve UN solo lugar (`StockCountMode`), y cuando
 * la respuesta es "ciego" el esperado NO VIAJA — no viaja y se esconde, no se
 * manda para que la pantalla lo tape.
 *
 * Esa última parte es la que justifica un arnés y no una lectura del código:
 * hasta la mig 169 el cierre de caja a ciegas se ocultaba solo en el frontend y
 * se caía con las devtools abiertas (regla 7 de `context/modules/14-caja.md`).
 * Acá se mira el JSON crudo de la respuesta, nunca la UI.
 *
 * Casos:
 *
 *   (A) Fail-CLOSED. Sin operador identificado no hay conteo (403), y el
 *       resolver devuelve CIEGO ante cualquier contexto que no pueda resolver.
 *   (B) `stockCountBlind` PRENDIDO — el piso del comercio:
 *       B1 operador SIN `inventory.count.open` → ciego, y `expectedQty` no
 *          aparece en el cuerpo de la respuesta.
 *       B2 operador CON la clave → abierto, con el esperado del ledger.
 *   (C) `stockCountBlind` APAGADO — el comercio no exige ciego: cuenta abierto
 *       hasta el que NO tiene la clave (primera fila de la tabla de verdad; es
 *       el comportamiento histórico y no se rompe).
 *   (D) El PANEL resuelve con el MISMO resolver: dos usuarios de panel del
 *       mismo comercio, con el flag prendido, ven cosas distintas según su rol.
 *   (E) El teórico que publica la caja es el MISMO que el ledger — si divergiera
 *       el cajero ajustaría contra un número y el servidor contra otro.
 *
 * Uso (ver `run_stock_count_open_mode_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/stock_count_open_mode_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/OperatorAssertion.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
require_once dirname(__DIR__) . '/lib/Auth/PanelAuth.php';
require_once dirname(__DIR__) . '/lib/Settings/StockCountSettings.php';
require_once dirname(__DIR__) . '/lib/Settings/StockCountMode.php';
require_once dirname(__DIR__) . '/lib/services/InventoryCountService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Auth\OperatorAssertion;
use Punto\Api\Auth\PanelAuth;
use Punto\Api\Settings\StockCountMode;
use Punto\Api\Settings\StockCountSettings;
use Punto\Api\Services\InventoryCountService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$itemA = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f';
$itemB = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e60';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'stock-count-open-mode-test';
const LISTA_ID        = 'lista-abierta-test';

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
 * Devuelve también el cuerpo CRUDO: los casos de conteo ciego se verifican
 * sobre el texto de la respuesta, no sobre el array decodificado — la pregunta
 * es "¿la clave viajó?", no "¿vino en null?".
 */
function hitGet(string $query, string $panelToken, string $deviceBearer, string $operatorToken): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        'v1/inventory_count.php', 'GET', $query, '', $panelToken, $deviceBearer, $operatorToken, '',
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso', 'data' => null];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $status = 0;
    $data   = null;
    $json   = '';
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $json = $m[1];
        $env  = json_decode($json, true);
        if (is_array($env)) {
            $status = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
            $data   = $env['data'] ?? null;
        }
    }
    return ['status' => $status, 'body' => $out . $err, 'json' => $json, 'data' => $data];
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

/** Escribe el piso de conteo ciego del comercio y limpia el cache por request. */
function setBlind(string $companyId, bool $blind): void
{
    global $db;
    $rs = ncmExecute("SELECT config->>'settingObj' AS so FROM company WHERE companyid = ?", [$companyId]);
    $obj = json_decode((string) ($rs['so'] ?? ''), true);
    if (!is_array($obj)) $obj = [];
    $obj['stockCountBlind'] = $blind ? 1 : 0;
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
    $lists = [['id' => LISTA_ID, 'name' => 'Mostrador abierto', 'itemIds' => $itemIds]];
    $db->Execute(
        "UPDATE company SET config = config || jsonb_build_object('stockCountLists', ?::text) WHERE companyid = ?",
        [json_encode($lists), $companyId]
    );
    StockCountSettings::forget($companyId);
}

/** El esperado que la caja recibió para un ítem, o null si no vino. */
function esperadoDe(?array $data, string $itemId): ?float
{
    foreach ((array) ($data['items'] ?? []) as $it) {
        if (strtolower((string) ($it['itemId'] ?? '')) === strtolower($itemId)) {
            return isset($it['expectedQty']) ? (float) $it['expectedQty'] : null;
        }
    }
    return null;
}

$deviceIds = [];
$svc = new InventoryCountService();

try {
    RoleService::seedCompanyRoles($companyId);

    // Roles CUSTOM: lo que se prueba es el efecto de LA CLAVE aislada, no qué
    // permisos tenga hoy `cashier` ni `manager`. Los dos pueden contar
    // (`pos.stock.count`); lo único que los separa es ver el teórico.
    $roleCiego = RoleService::createRole(
        'countopen-ciego-' . bin2hex(random_bytes(3)),
        ['pos.sale.create', 'inventory.item.view', 'pos.stock.count'],
        $companyId,
        $adminId
    );
    $roleAbierto = RoleService::createRole(
        'countopen-abierto-' . bin2hex(random_bytes(3)),
        ['pos.sale.create', 'inventory.item.view', 'pos.stock.count', 'inventory.count.open'],
        $companyId,
        $adminId
    );

    global $db;
    $mk = function (string $name, string $roleId) use ($companyId, $db): string {
        $email = strtolower($name) . '.countopen@test.local';
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

    $cajero    = $mk('CountOpenCajero',    $roleCiego);
    $encargado = $mk('CountOpenEncargado', $roleAbierto);
    $tokCajero    = OperatorAssertion::issue($companyId, $cajero);
    $tokEncargado = OperatorAssertion::issue($companyId, $encargado);

    $issued = DeviceAuth::issueDeviceToken(
        $companyId, $outletId, $registerId, $adminId,
        'Test device — conteo abierto',
        MARCA_DEL_ARNES,
        'test-countopen-' . bin2hex(random_bytes(6)),
    );
    $deviceIds[] = $issued['deviceId'];
    $bearer = $issued['token'];

    setLista($companyId, [$itemA, $itemB]);

    $query = 'action=expected&listId=' . LISTA_ID . '&itemIds=' . $itemA . ',' . $itemB;

    // ═══════════════════════════════════════════════════════════════════════
    // (A) Fail-CLOSED
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A) fail-closed: sin poder resolver el modo, es CIEGO ===\n";

    setBlind($companyId, true);

    $res = hitGet($query, '', $bearer, '');
    check('(A1) sin operador identificado → 403', $res['status'] === 403,
        "esperaba 403, vino {$res['status']}: {$res['body']}", $failures, $checks);
    check('(A1b) el 403 dice qué hacer (desbloquear con el PIN)',
        str_contains($res['body'], 'PIN'),
        "el mensaje no menciona el PIN: {$res['body']}", $failures, $checks);

    // El resolver, en aislamiento: un contexto sin companyId no se puede
    // resolver, y lo que devuelve es CIEGO. Es la dirección segura — el error
    // que revela el esperado es una fuga; el que lo esconde de más solo obliga
    // a contar sin ayuda.
    check('(A2) contexto irresoluble (sin companyId) → ciego',
        StockCountMode::isBlind([]) === true,
        'isBlind([]) no devolvió true', $failures, $checks);

    // pos-app sin `X-Operator-Token` en ESTE proceso: no hay persona a la cual
    // medirle el permiso, así que no se le concede.
    check('(A3) pos-app sin operador → ciego (no cae al rol del device)',
        StockCountMode::isBlind(['companyId' => $companyId, 'realm' => 'pos-app']) === true,
        'isBlind() concedió el modo abierto sin operador identificado', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) Piso PRENDIDO: el modo lo decide la persona
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) stockCountBlind prendido: la clave decide ===\n";

    $res = hitGet($query, '', $bearer, $tokCajero);
    check('(B1) operador SIN inventory.count.open → 200 y modo ciego',
        $res['status'] === 200 && ($res['data']['blind'] ?? null) === true,
        "status {$res['status']}, blind=" . json_encode($res['data']['blind'] ?? null)
        . ": {$res['body']}", $failures, $checks);
    check('(B1b) el esperado NO VIAJA en la respuesta',
        !str_contains((string) $res['json'], 'expectedQty')
        && ($res['data']['items'] ?? null) === [],
        'la respuesta trajo expectedQty o items: ' . $res['json'], $failures, $checks);

    $res = hitGet($query, '', $bearer, $tokEncargado);
    check('(B2) operador CON la clave → 200 y modo abierto',
        $res['status'] === 200 && ($res['data']['blind'] ?? null) === false,
        "status {$res['status']}, blind=" . json_encode($res['data']['blind'] ?? null)
        . ": {$res['body']}", $failures, $checks);

    $esperadoA = esperadoDe($res['data'], $itemA);
    check('(B2b) y con el esperado de cada artículo de la lista',
        $esperadoA !== null && count((array) ($res['data']['items'] ?? [])) === 2,
        'items: ' . json_encode($res['data']['items'] ?? null), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (E) El teórico publicado es el del LEDGER
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) el esperado que ve la caja sale del ledger ===\n";

    $saldoA = saldo($itemA, $outletId);
    check('(E1) expectedQty == SUM(stockcount) de la sucursal',
        $esperadoA !== null && abs($esperadoA - $saldoA) < 0.001,
        "el endpoint publicó " . var_export($esperadoA, true) . " y el ledger dice $saldoA",
        $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (C) Piso APAGADO: comportamiento de siempre
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) stockCountBlind apagado: el comercio no exige ciego ===\n";

    setBlind($companyId, false);

    $res = hitGet($query, '', $bearer, $tokCajero);
    check('(C1) sin la clave, pero con el piso apagado → modo abierto',
        $res['status'] === 200 && ($res['data']['blind'] ?? null) === false
        && esperadoDe($res['data'], $itemA) !== null,
        "status {$res['status']}: {$res['json']}", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (D) El panel resuelve con el MISMO resolver
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) el panel y la caja no tienen dos definiciones del modo ===\n";

    setBlind($companyId, true);

    $creado  = $svc->create($companyId, $outletId, null, $adminId, 'arnés F2', [], true);
    $countId = (string) $creado['id'];

    $panelCiego = PanelAuth::issuePanelSession(
        ['companyId' => $companyId, 'contactId' => $cajero, 'role' => $roleCiego],
        $outletId,
    );
    $panelAbierto = PanelAuth::issuePanelSession(
        ['companyId' => $companyId, 'contactId' => $encargado, 'role' => $roleAbierto],
        $outletId,
    );

    $res = hitGet('action=get&id=' . $countId, (string) $panelCiego['token'], '', '');
    $primera = $res['data']['items'][0] ?? [];
    check('(D1) panel sin la clave: el conteo en progreso viene sin esperado',
        $res['status'] === 200
        && ($res['data']['session']['blind'] ?? null) === true
        && array_key_exists('expectedQty', $primera) && $primera['expectedQty'] === null,
        "status {$res['status']}: " . json_encode($res['data']['session'] ?? null)
        . ' / ' . json_encode($primera), $failures, $checks);

    $res = hitGet('action=get&id=' . $countId, (string) $panelAbierto['token'], '', '');
    $primera = $res['data']['items'][0] ?? [];
    check('(D2) panel CON la clave: el mismo conteo sí trae el esperado',
        $res['status'] === 200
        && ($res['data']['session']['blind'] ?? null) === false
        && ($primera['expectedQty'] ?? null) !== null,
        "status {$res['status']}: " . json_encode($res['data']['session'] ?? null)
        . ' / ' . json_encode($primera), $failures, $checks);

    // El listado tampoco puede ser la puerta de atrás, y también por persona.
    $res = hitGet('action=list&status=1&limit=50', (string) $panelCiego['token'], '', '');
    $fila = null;
    foreach ((array) ($res['data']['rows'] ?? []) as $r) {
        if (($r['inventoryCountId'] ?? '') === $countId) { $fila = $r; break; }
    }
    check('(D3) el listado no publica la diferencia acumulada a quien cuenta a ciegas',
        $fila !== null && $fila['totalCostDelta'] === null,
        'totalCostDelta: ' . json_encode($fila), $failures, $checks);

    $svc->cancel($countId, $companyId);
} finally {
    // Limpieza: sesiones y devices de esta corrida. El tenant fixture se
    // recarga entero en cada run del `.sh`, así que los conteos quedan.
    foreach ($deviceIds as $did) {
        ncmExecute('DELETE FROM auth_session WHERE deviceid = ?', [$did]);
    }
    ncmExecute('DELETE FROM auth_session WHERE useragent = ?', [MARCA_DEL_ARNES]);
}

harnessFinish($failures, $checks);
