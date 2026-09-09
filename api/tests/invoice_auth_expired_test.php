<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del TIMBRADO VENCIDO: el corte de emisión y su preaviso.
 *
 * El agujero que tapa, verificado el 2026-09-07: `SaleService` congelaba el
 * timbrado en cada venta desde la mig 145, pero NADIE comparaba
 * `registerInvoiceAuthExpiration` contra nada en el money-path. La caja emitía
 * facturas con el timbrado caído —inválidas ante la autoridad fiscal— sin un
 * solo aviso.
 *
 * Lo que verifica, contra Postgres real y con los endpoints reales:
 *
 *   (A) Venta DIRECTA con el timbrado vencido → 422 con el contrato completo:
 *       `details.code = invoice_auth_expired` y la fecha en que venció.
 *   (B) EL CASO QUE NO SE PUEDE ROMPER: una venta cuya FECHA DE OPERACIÓN cae
 *       DENTRO de la vigencia pasa, aunque el servidor ya esté en un día
 *       posterior. Es la venta de las 22:00 del último día válido que
 *       sincroniza al día siguiente: es legal, y compararla contra `now()` la
 *       habría rechazado.
 *   (C) Caja SIN vencimiento cargado: no se bloquea nada. Hay comercios que
 *       operan sin numeración fiscal y el día del deploy no se les puede parar
 *       la caja por un campo vacío.
 *   (D) La semántica del día: vence al TERMINAR su último día (día 0 todavía
 *       factura), el día siguiente no; `null` y basura no bloquean nunca.
 *   (E) LA MARCA: una venta guardada por el camino de la COLA con el timbrado
 *       ya vencido se ACEPTA —el ticket ya está en la calle, context/08 §53— y
 *       queda con `meta.invoiceAuthExpiredAtEmission = true`.
 *   (F) La contracara de (E): la venta legítima de (B) NO se marca. Sin este
 *       caso, (E) podría estar pasando porque se marca todo.
 *   (G) `/v1/offline-sync.php` no rechaza: el resultado por-venta viene
 *       `ok:true`, no un 422. Es lo que evita trabar la cola entera.
 *   (H) Los AVISOS: el job elige las cajas de las ventanas 7 / 3 / vencido, y
 *       es IDEMPOTENTE — con la marca del ciclo ya escrita, la caja deja de
 *       aparecer. Correrlo dos veces el mismo día no manda dos mails.
 *
 * El caso que más importa es (B). (A) es fácil de escribir bien; (B) es el que
 * separa "comparar contra la fecha de la operación" de "comparar contra now()",
 * y equivocarse ahí le cobra al comercio un día entero de ventas legales.
 *
 * Uso (ver `run_invoice_auth_expired_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/invoice_auth_expired_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';
require_once dirname(__DIR__) . '/lib/Auth/DeviceAuth.php';
require_once dirname(__DIR__) . '/lib/services/RegisterLeaseService.php';
require_once dirname(__DIR__) . '/lib/Notifications/TenantNotice.php';
require_once dirname(__DIR__) . '/lib/Notifications/InvoiceAuthNoticeService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Context\TenantContext;
use Punto\Api\Notifications\InvoiceAuthNoticeService;
use Punto\Api\Sales\InvoiceAuthGate;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Services\RegisterLeaseService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'invoice-auth-expired-test';

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
 * Fija (o borra, con `null`) el vencimiento del timbrado de la caja fixture.
 * `jsonb_set` sobre la subclave para no pisar el resto de `register.data`.
 */
function setExpiration(?string $date, string $registerId, string $companyId): void
{
    global $db;
    if ($date === null) {
        $db->Execute(
            "UPDATE register SET data = data - 'registerInvoiceAuthExpiration'
              WHERE registerId = ? AND companyId = ?",
            [$registerId, $companyId]
        );
        return;
    }
    $db->Execute(
        "UPDATE register
            SET data = jsonb_set(coalesce(data, '{}'::jsonb),
                                 '{registerInvoiceAuthExpiration}', to_jsonb(?::text), true)
          WHERE registerId = ? AND companyId = ?",
        [$date, $registerId, $companyId]
    );
}

/** Borra las marcas de aviso para que cada caso arranque de cero. */
function clearNotices(string $registerId, string $companyId): void
{
    global $db;
    $db->Execute(
        "UPDATE register SET data = data - 'invoiceAuthNotices'
          WHERE registerId = ? AND companyId = ?",
        [$registerId, $companyId]
    );
}

/** Día del tenant desplazado N días (negativo = pasado). */
function tenantDay(int $offset, string $companyId): string
{
    $today = substr(\Punto\Api\Support\TenantClock::now($companyId), 0, 10);
    return (new DateTimeImmutable($today))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
}

/**
 * Llama a un endpoint real en subproceso — `apiError()` hace `exit`, así que un
 * 422 dentro del proceso del arnés lo mataría entero.
 *
 * @return array{status:int, body:string, data:mixed, details:mixed, message:string, ok:bool}
 */
function call(string $script, array $body, string $bearer): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $script, 'POST', '', json_encode($body), '', $bearer, '', '',
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso', 'data' => null,
                'details' => null, 'message' => '', 'ok' => false];
    }
    fwrite($pipes[0], json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $raw = (string) $out;
    $bodyStr = $raw;
    if (preg_match('/BODY:(.*)\nHTTP_STATUS:/s', $raw, $m) === 1) {
        $bodyStr = $m[1];
    }
    $decoded = json_decode(trim($bodyStr), true);
    $status  = 200;
    if (is_array($decoded) && ($decoded['ok'] ?? null) === false) {
        $status = (int) ($decoded['error']['code'] ?? 400);
    }

    return [
        'status'  => $status,
        'body'    => trim($raw) !== '' ? trim($raw) : trim((string) $err),
        'data'    => is_array($decoded) ? ($decoded['data'] ?? null) : null,
        'details' => is_array($decoded) ? ($decoded['error']['details'] ?? null) : null,
        'message' => is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '',
        'ok'      => is_array($decoded) ? (bool) ($decoded['ok'] ?? false) : false,
    ];
}

// ── Un ítem cualquiera del tenant fixture, para armar la venta ─────────────
$itemRow = ncmExecute(
    'SELECT itemId FROM item WHERE companyId = ? LIMIT 1',
    [$companyId]
);
$itemId = is_array($itemRow) || $itemRow instanceof ArrayAccess
    ? (string) ($itemRow['itemid'] ?? $itemRow['itemId'] ?? '')
    : '';
if ($itemId === '') {
    fwrite(STDERR, "No hay ítems en el tenant fixture — ¿se cargó verify_chain/seed.sql?\n");
    exit(2);
}

/** Payload de venta contado mínimo pero VÁLIDO (mismo shape que run_sale_chain). */
function salePayload(string $itemId, string $date, int $invoiceNo): array
{
    return [
        'transaction' => [
            'uid'      => MARCA_DEL_ARNES . '-' . bin2hex(random_bytes(8)),
            'type'     => 0,
            'invoiceno' => $invoiceNo,
            'sale'     => [[
                'itemId' => $itemId, 'count' => 1, 'name' => 'Test',
                'uniPrice' => 1000, 'price' => 1000, 'total' => 1000,
                'tax' => 0, 'discount' => 0, 'totalDiscount' => 0,
                'user' => '', 'type' => '', 'date' => '', 'note' => '',
                'currency' => '', 'uId' => 0,
            ]],
            'subtotal' => 1000,
            'tax'      => 0,
            'discount' => 0,
            'payment'  => [['type' => 'cash', 'name' => 'Efectivo', 'total' => 1000]],
            'date'      => $date . ' 22:00:00',
            'timestamp' => (new DateTimeImmutable($date . ' 22:00:00'))->getTimestamp(),
        ],
    ];
}

// Numeración: bien arriba del último usado, para no chocar con
// uq_transaction_expedition_invoiceno cuando el arnés corre dos veces.
$nextNo = 900000 + random_int(1, 90000);

// Device real (realm pos-app) + tenencia de la caja: sin la tenencia,
// `sales.php` corta con 409 ANTES de que podamos ver nada del timbrado.
$issued = DeviceAuth::issueDeviceToken(
    $companyId, $outletId, $registerId, $adminId,
    'Test device — timbrado vencido',
    MARCA_DEL_ARNES,
    'test-invauth-' . bin2hex(random_bytes(6)),
);
$bearer   = $issued['token'];
$deviceId = $issued['deviceId'];
// `ACQUIRE_OPERATOR`: este device recién pareado toma la caja igual que lo
// haría el cajero con "Tomar caja". `$acquire` dejó de ser booleano el
// 2026-09-09 (veto del admin, ver `RegisterLeaseService::isAdminRevoked()`).
RegisterLeaseService::claim(
    $registerId,
    $companyId,
    $outletId,
    $deviceId,
    RegisterLeaseService::ACQUIRE_OPERATOR
);

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);

$expiredOn = tenantDay(-3, $companyId);   // venció hace 3 días
$validOn   = tenantDay(+30, $companyId);  // vigente

// ═══════════════════════════════════════════════════════════════════════════
// (A) Venta directa con el timbrado vencido → 422 con el contrato
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (A) venta directa con timbrado vencido ===\n";
setExpiration($expiredOn, $registerId, $companyId);

$res = call('v1/sales.php', ['data' => json_encode(salePayload($itemId, tenantDay(0, $companyId), $nextNo++))], $bearer);
check('(A1) la emisión se rechaza con 422', $res['status'] === 422,
    "esperaba 422, vino {$res['status']}: {$res['body']}", $failures, $checks);
check('(A2) el 422 trae el código del contrato',
    ($res['details']['code'] ?? null) === 'invoice_auth_expired',
    'details=' . json_encode($res['details']), $failures, $checks);
check('(A3) y la fecha en que venció, para poder explicarlo',
    ($res['details']['expiredOn'] ?? null) === $expiredOn,
    "esperaba expiredOn={$expiredOn}, vino " . json_encode($res['details']), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (B) La fecha de la OPERACIÓN manda, no el now() del servidor
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (B) operación DENTRO de la vigencia, servidor ya en un día posterior ===\n";
// El timbrado venció AYER; la venta se cobró ANTEAYER y recién sincroniza hoy.
setExpiration(tenantDay(-1, $companyId), $registerId, $companyId);

$res = call('v1/sales.php', ['data' => json_encode(salePayload($itemId, tenantDay(-2, $companyId), $nextNo++))], $bearer);
check('(B1) NO la corta el gate de timbrado',
    ($res['details']['code'] ?? null) !== 'invoice_auth_expired',
    "la rechazó igual: {$res['body']}", $failures, $checks);
check('(B2) y de hecho la venta se guarda', $res['ok'] === true,
    "esperaba ok:true, vino {$res['body']}", $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (C) Sin vencimiento cargado no se bloquea nada
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (C) caja sin vencimiento cargado ===\n";
setExpiration(null, $registerId, $companyId);

$res = call('v1/sales.php', ['data' => json_encode(salePayload($itemId, tenantDay(0, $companyId), $nextNo++))], $bearer);
check('(C1) la venta pasa', $res['ok'] === true,
    "esperaba ok:true, vino {$res['body']}", $failures, $checks);
check('(C2) y no por accidente: el gate ni siquiera opina',
    InvoiceAuthGate::expirationFor($registerId, $companyId) === null,
    'expirationFor devolvió algo con la clave borrada', $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (D) La semántica del día
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (D) vence al TERMINAR su último día ===\n";
check('(D1) el día del vencimiento todavía factura',
    InvoiceAuthGate::isExpiredOn('2026-09-07', '2026-09-07 23:59:59') === false,
    'marcó vencido el propio día del vencimiento', $failures, $checks);
check('(D2) el día siguiente no',
    InvoiceAuthGate::isExpiredOn('2026-09-07', '2026-09-08 00:00:01') === true,
    'no marcó vencido el día posterior', $failures, $checks);
check('(D3) sin vencimiento nunca bloquea',
    InvoiceAuthGate::isExpiredOn(null, '2030-01-01 10:00:00') === false
        && InvoiceAuthGate::isExpiredOn('', '2030-01-01 10:00:00') === false,
    'un vencimiento vacío bloqueó', $failures, $checks);
check('(D4) una fecha ilegible tampoco bloquea (fail-open sobre basura)',
    InvoiceAuthGate::isExpiredOn('no-es-fecha', '2030-01-01 10:00:00') === false,
    'una fecha basura bloqueó la caja', $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (E) La MARCA en la venta que llega por la cola
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (E) venta ya emitida con timbrado vencido: se acepta y se MARCA ===\n";
setExpiration($expiredOn, $registerId, $companyId);

$input  = SaleInput::fromPayload(salePayload($itemId, tenantDay(0, $companyId), $nextNo++), $companyId);
$result = $service->save($input);
$txId   = (string) $result->transactionId;

// `meta::text AS meta_raw` y NO `SELECT meta`: `Query::flattenJsonb` promueve
// cada clave de primer nivel de `meta` a columna virtual y BORRA la columna, así
// que `$row['meta']` sería null y el arnés daría verde por vacío (pasó en la
// primera corrida). `meta_raw` no está en la lista de columnas aplanadas.
$metaRow = ncmExecute('SELECT meta::text AS meta_raw FROM transaction WHERE transactionId = ? LIMIT 1', [$txId], false);
$meta    = json_decode((string) ($metaRow['meta_raw'] ?? '{}'), true) ?: [];
check('(E1) la venta se guarda igual (nunca se rechaza una ya emitida)', $txId !== '',
    'no se guardó la venta', $failures, $checks);
check('(E2) y queda marcada con invoiceAuthExpiredAtEmission',
    ($meta['invoiceAuthExpiredAtEmission'] ?? null) === true,
    'meta=' . json_encode($meta), $failures, $checks);
check('(E3) sin romper el shape histórico de meta (transactionDetails/tags)',
    array_key_exists('transactionDetails', $meta) && array_key_exists('tags', $meta),
    'meta=' . json_encode(array_keys($meta)), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (F) La contracara: la venta legítima NO se marca
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (F) la venta de las 22:00 del último día válido NO se marca ===\n";
setExpiration(tenantDay(-1, $companyId), $registerId, $companyId);

$input2  = SaleInput::fromPayload(salePayload($itemId, tenantDay(-1, $companyId), $nextNo++), $companyId);
$result2 = $service->save($input2);
$meta2Row = ncmExecute('SELECT meta::text AS meta_raw FROM transaction WHERE transactionId = ? LIMIT 1',
    [(string) $result2->transactionId], false);
$meta2 = json_decode((string) ($meta2Row['meta_raw'] ?? '{}'), true) ?: [];
check('(F1) la venta del propio día del vencimiento no lleva marca',
    // El `array_key_exists` de abajo no alcanza solo: sobre un `meta` vacío
    // pasaría siempre. Exigir `transactionDetails` es lo que prueba que
    // estamos MIRANDO el meta real y que la marca no está.
    array_key_exists('transactionDetails', $meta2)
        && !array_key_exists('invoiceAuthExpiredAtEmission', $meta2),
    'meta=' . json_encode($meta2), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (G) offline-sync ACEPTA, no rechaza
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (G) la cola offline no se traba con el timbrado vencido ===\n";
setExpiration($expiredOn, $registerId, $companyId);

$syncNo = $nextNo++;
$res = call('v1/offline-sync.php', [
    'sales' => [[
        'clientTempId' => 'tmp-' . bin2hex(random_bytes(4)),
        'invoiceNo'    => $syncNo,
        'sale'         => salePayload($itemId, tenantDay(0, $companyId), $syncNo)['transaction'],
    ]],
], $bearer);
$first = $res['data']['results'][0] ?? [];
check('(G1) el sync acepta la venta ya emitida', ($first['ok'] ?? null) === true,
    'resultado=' . json_encode($first) . ' body=' . $res['body'], $failures, $checks);
check('(G2) y no la rechaza con el código del gate',
    ($first['error']['code'] ?? null) !== 'invoice_auth_expired',
    'resultado=' . json_encode($first), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (H) Avisos: ventanas 7 / 3 / vencido, e idempotencia
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (H) avisos de vencimiento: ventanas e idempotencia ===\n";
// Dry-run: el arnés no manda mails. Además ejercita el interruptor de apagado.
$_ENV['INVOICE_AUTH_NOTIFY'] = '0';

$notices = new InvoiceAuthNoticeService();

/** ¿El job eligió a este comercio en su corrida? */
$picked = static function (array $run, string $companyId): bool {
    foreach ($run['detail'] as $d) {
        if (($d['companyId'] ?? '') === $companyId) {
            return true;
        }
    }
    return false;
};

foreach ([['d7', 5], ['d3', 2], ['expired', -2]] as [$kind, $offset]) {
    clearNotices($registerId, $companyId);
    setExpiration(tenantDay($offset, $companyId), $registerId, $companyId);

    $run = $notices->run();
    check("(H1/{$kind}) el job elige la caja a {$offset} días", $picked($run, $companyId),
        'detail=' . json_encode($run['detail']), $failures, $checks);
    check("(H2/{$kind}) y no manda nada en dry-run", $run['dryRun'] === true && $run['sent'] === 0,
        'run=' . json_encode(['sent' => $run['sent'], 'dryRun' => $run['dryRun']]), $failures, $checks);

    // Idempotencia: se escribe la marca del ciclo con el MISMO método privado
    // que usa el job tras un envío real (reflection: probar el SQL de verdad,
    // no una copia del SQL en el arnés), y se vuelve a correr.
    $m = new ReflectionMethod(InvoiceAuthNoticeService::class, 'markSent');
    $m->setAccessible(true);
    $m->invoke($notices, $registerId, $companyId, $kind, tenantDay($offset, $companyId));

    $run2 = $notices->run();
    check("(H3/{$kind}) con la marca del ciclo escrita, ya no vuelve a elegirla",
        !$picked($run2, $companyId),
        'detail=' . json_encode($run2['detail']), $failures, $checks);

    // Y un ciclo NUEVO (timbrado renovado) vuelve a avisar sin borrar nada:
    // la marca guarda la fecha del ciclo, no un booleano.
    setExpiration(tenantDay($offset + 1, $companyId), $registerId, $companyId);
    $run3 = $notices->run();
    check("(H4/{$kind}) un vencimiento NUEVO vuelve a disparar el aviso",
        $picked($run3, $companyId),
        'detail=' . json_encode($run3['detail']), $failures, $checks);
}

// Fuera de toda ventana no avisa nada.
clearNotices($registerId, $companyId);
setExpiration(tenantDay(60, $companyId), $registerId, $companyId);
check('(H5) una caja con el timbrado lejos de vencer no entra en ningún aviso',
    !$picked($notices->run(), $companyId),
    'eligió una caja a 60 días', $failures, $checks);

// Un timbrado vencido hace mucho tampoco: el aviso es accionable, no un censo.
clearNotices($registerId, $companyId);
setExpiration(tenantDay(-60, $companyId), $registerId, $companyId);
check('(H6) ni una vencida hace 60 días (piso de la ventana de vencidos)',
    !$picked($notices->run(), $companyId),
    'eligió una caja vencida hace 60 días', $failures, $checks);

// ── Limpieza: la caja fixture vuelve a quedar sin vencimiento ──────────────
clearNotices($registerId, $companyId);
setExpiration(null, $registerId, $companyId);
$db->Execute('DELETE FROM register_lease WHERE registerid = ? AND companyid = ?', [$registerId, $companyId]);

harnessFinish($failures, $checks);
