<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la NUMERACIÓN DEL EMISOR en la factura electrónica.
 *
 * La decisión que verifica (owner, 2026-09-07): *"desde el inicio nosotros
 * tenemos que ser dueños de la numeración. Factomate no debe llevar la
 * numeración"*. El documento electrónico sale a SIFEN con el correlativo que
 * la CAJA ya congeló en la venta (`transaction.invoiceNo`, mig 145) — el
 * mismo que salió impreso en el ticket, porque el impreso es la
 * representación impresa de la factura electrónica.
 *
 * Hasta este cambio el mapper mandaba `number => -1` con el comentario
 * "SIEMPRE -1: numera la SET, no configurable". Las dos mitades eran falsas:
 * numeraba FACTOMATE (su `CurrentNumber` por `BranchDocumentType`), y sí es
 * configurable.
 *
 * Lo que verifica, contra Postgres real y con el provider simulado (la API
 * de Factomate está caída — PhoneLogin 500 — así que la verificación en vivo
 * queda pendiente; ver el reporte del slice):
 *
 *   (A) El payload que sale lleva EL NÚMERO CONGELADO DE LA VENTA, entero
 *       pelado. Es el caso central del slice.
 *   (B) Venta SIN número congelado → el documento queda en `error` y NO se
 *       manda. Nunca un `-1` silencioso: ese fallback devolvería la
 *       numeración a Factomate justo en el caso que nadie mira, y el ticket
 *       impreso y el documento fiscal quedarían con números distintos.
 *   (C) Timbrado INCOHERENTE (el congelado en la venta ≠ el provisionado en
 *       el emisor) → `error` ANTES de mandar. El número pertenece a un
 *       talonario; mandarlo por otro es un duplicado esperando.
 *   (D) Pre-flight del rango: si el talonario del lado de ELLOS ya tiene
 *       documentos de otros canales (el owner emitió pruebas vía n8n) y su
 *       último usado alcanza o supera nuestro correlativo, el documento
 *       queda en `error` diciendo QUÉ número habría que configurar. Con el
 *       rango por debajo, emite.
 *   (E) El kill-switch `config.legacyAutoNumbering` fuerza `-1` — el
 *       rollback de emergencia funciona y ni siquiera consulta el timbrado.
 *
 * El caso que más importa es (B). (A) es fácil de escribir bien; (B) es el
 * que separa "somos dueños de la numeración" de "somos dueños salvo cuando
 * falla algo", que en un money-path fiscal es la diferencia entre un
 * documento correcto y una multa por comprobante.
 *
 * Uso (ver `run_einvoice_emitter_numbering_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/einvoice_emitter_numbering_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/EInvoice/EInvoiceProvider.php';
require_once dirname(__DIR__) . '/lib/EInvoice/CredentialVault.php';
require_once dirname(__DIR__) . '/lib/EInvoice/SaleToInvoiceMapper.php';
require_once dirname(__DIR__) . '/lib/EInvoice/EInvoiceService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\EInvoice\CredentialVault;
use Punto\Api\EInvoice\EInvoiceProvider;
use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'einvoice-emitter-numbering-test';
const TIMBRADO_CAJA   = '80099117';
const STAMP_ID_FC     = 'STAMP-FC-TEST';

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
 * Provider simulado. La API real está caída (PhoneLogin 500) y, aunque no lo
 * estuviera, un arnés que emite documentos fiscales de verdad contra el
 * emisor no es un arnés — es una emisión. Se simula EN LA INTERFAZ
 * (`EInvoiceProvider`), que es la costura que el propio módulo declara para
 * no casarse con Factomate, así que lo que se ejercita es el código real de
 * punta a punta salvo el HTTP.
 */
final class FakeFactomateProvider implements EInvoiceProvider
{
    /** @var array<int,array<string,mixed>> payloads que se intentaron emitir */
    public array $issued = [];
    /** @var int llamadas a stamps() — el pre-flight tiene que cachear */
    public int $stampCalls = 0;
    /** Último número usado que reporta el timbrado remoto. */
    public int $currentNumber = 0;
    /** Número de timbrado que reporta el emisor para STAMP_ID_FC. */
    public string $stampNumber = TIMBRADO_CAJA;

    public function stamps(string $environment, string $phone, string $bearer): array
    {
        $this->stampCalls++;
        return ['Items' => [[
            'Id'              => STAMP_ID_FC,
            'StampNumber'     => $this->stampNumber,
            'Stablishment'    => '001',
            'ExpeditionPoint' => '001',
            'CurrentNumber'   => $this->currentNumber,
            'Serie'           => '',
            'DocumentTypeId'  => 1,
        ]]];
    }

    public function issue(string $environment, string $phone, string $bearer, array $payload): array
    {
        $this->issued[] = $payload;
        return [
            'cdc'            => str_pad((string) count($this->issued), 44, '0', STR_PAD_LEFT),
            'documentNumber' => null,
            'success'        => true,
            'statusMessage'  => null,
            'bulkId'         => 'bulk-' . count($this->issued),
            'dCarQR'         => null,
            'xmlUrl'         => null,
            'raw'            => [],
        ];
    }

    // ── Resto de la interfaz: no participa de este arnés ──────────────────
    public function token(string $e, string $p, string $u, string $pw): array { return ['token' => 'x', 'expiresAt' => null, 'raw' => []]; }
    public function phoneLogin(string $e, string $p, string $t): array { return ['token' => 'x', 'expiresAt' => null, 'raw' => []]; }
    public function userInfo(string $e, string $p, string $b): array { return []; }
    public function sincroConfig(string $e, string $p, string $b): array { return []; }
    public function paymentMethods(string $e, string $p, string $b): array { return []; }
    public function cancel(string $e, string $p, string $b, string $cdc, string $r): array { return []; }
    public function kude(string $e, string $p, string $b, string $cdc): string { return ''; }
    public function clientByRuc(string $e, string $p, string $b, string $ruc): array { return []; }
    public function getBulk(string $e, string $p, string $b, string $id): array { return []; }
    public function createExternal(string $e, string $l, string $b, array $d): array { return []; }
    public function updateTenant(string $e, string $p, string $b, array $t): array { return []; }
    public function createActivity(string $e, string $p, string $b, int $t, int $i, string $n): array { return []; }
    public function createStamp(string $e, string $p, string $b, array $s): array { return []; }
    public function uploadCert(string $e, string $p, string $b, int $t, string $c, string $pw): array { return []; }
    public function testSet(string $e, string $p, string $b, int $t, string $ruc): array { return []; }
}

// ── Fixture: la caja tiene timbrado, y el tenant cuenta de FE conectada ────

$db->Execute(
    "UPDATE register
        SET data = jsonb_set(
                     jsonb_set(coalesce(data, '{}'::jsonb), '{registerInvoiceAuth}', to_jsonb(?::text), true),
                     '{registerInvoicePrefix}', to_jsonb('001-001'::text), true)
      WHERE registerId = ? AND companyId = ?",
    [TIMBRADO_CAJA, $registerId, $companyId]
);

/**
 * Deja `einvoice_account` en el estado "conectada y provisionada", con el
 * bearer ya cacheado y vigente: así `FactomateSession::getBearer()` lo
 * devuelve del vault sin encadenar Token → PhoneLogin, y el arnés no depende
 * de credenciales reales.
 */
function seedAccount(string $companyId, string $registerId, array $config = []): void
{
    global $db;
    $provisioning = json_encode([
        'stampMap' => [$registerId => ['fc' => STAMP_ID_FC, 'nc' => 'STAMP-NC-TEST']],
    ], JSON_UNESCAPED_UNICODE);

    $db->Execute(
        "INSERT INTO einvoice_account
            (companyid, provider, username, password_enc, phone_enc, token_enc, token_expires_at,
             status, environment, stamp, provisioning, config)
         VALUES (?, 'factomate', ?, ?, ?, ?, now() + interval '1 hour',
                 'ok', 'test', ?::jsonb, ?::jsonb, ?::jsonb)
         ON CONFLICT (companyid) DO UPDATE SET
            username = EXCLUDED.username, password_enc = EXCLUDED.password_enc,
            phone_enc = EXCLUDED.phone_enc, token_enc = EXCLUDED.token_enc,
            token_expires_at = EXCLUDED.token_expires_at, status = 'ok',
            environment = 'test', stamp = EXCLUDED.stamp,
            provisioning = EXCLUDED.provisioning, config = EXCLUDED.config",
        [
            $companyId,
            MARCA_DEL_ARNES,
            CredentialVault::encrypt('no-usada'),
            CredentialVault::encrypt('595990000000'),
            CredentialVault::encrypt('bearer-simulado'),
            json_encode(['Id' => STAMP_ID_FC], JSON_UNESCAPED_UNICODE),
            $provisioning,
            json_encode($config, JSON_UNESCAPED_UNICODE),
        ]
    );
}

// ── Ventas del arnés ──────────────────────────────────────────────────────

$itemRow = ncmExecute('SELECT itemId FROM item WHERE companyId = ? LIMIT 1', [$companyId]);
$itemId = (is_array($itemRow) || $itemRow instanceof ArrayAccess)
    ? (string) ($itemRow['itemid'] ?? $itemRow['itemId'] ?? '')
    : '';
if ($itemId === '') {
    fwrite(STDERR, "No hay ítems en el tenant fixture — ¿se cargó verify_chain/seed.sql?\n");
    exit(2);
}

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);

// Numeración bien arriba del último usado, para no chocar con
// uq_transaction_expedition_invoiceno cuando el arnés corre dos veces.
$nextNo = 800000 + random_int(1, 90000);

// ── Todas las ventas primero, SIN cuenta de FE conectada ──────────────────
// Ver el docblock de crearVenta(): con la cuenta en 'ok', cada save() sale a
// la API real de Factomate por la red. Se crean todas acá y recién después se
// conecta la cuenta.
$db->Execute('DELETE FROM einvoice_account WHERE companyid = ?', [$companyId]);

[$txA,  $noA]  = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txB,  $noB]  = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txC,  $noC]  = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txD,  $noD]  = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txD2, $noD2] = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txD3, $noD3] = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txE,  $noE]  = crearVenta($service, $itemId, $nextNo++, $companyId);

/**
 * Guarda una venta contado real y devuelve [transactionId, invoiceNo].
 *
 * OJO — todas las ventas del arnés se crean ANTES de que exista la cuenta de
 * facturación electrónica, y no es cosmético: `SaleService::save()` encola y
 * EMITE en línea post-commit (`enqueueForSale` + `tryIssueInline`) con un
 * `EInvoiceService` propio, o sea con el FactomateProvider REAL. Con la
 * cuenta ya en `status='ok'`, cada venta de este arnés salía a la API de
 * Factomate por la red — un arnés fiscal no puede hacer eso ni aunque las
 * credenciales sean falsas y rebote en 403. Sin cuenta conectada,
 * `enqueueForSale()` retorna temprano y no se toca la red: la emisión la
 * maneja después `emitir()`, con el provider simulado.
 */
function crearVenta(SaleService $service, string $itemId, int $invoiceNo, string $companyId): array
{
    $payload = [
        'uid'       => MARCA_DEL_ARNES . '-' . bin2hex(random_bytes(8)),
        'type'      => 0,
        'invoiceno' => $invoiceNo,
        'sale'      => [[
            'itemId' => $itemId, 'count' => 1, 'name' => 'Test numeración',
            'uniPrice' => 1000, 'price' => 1000, 'total' => 1000,
            'tax' => 0, 'discount' => 0, 'totalDiscount' => 0,
            'user' => '', 'type' => '', 'date' => '', 'note' => '',
            'currency' => 'PYG', 'uId' => 0,
        ]],
        'subtotal'  => 1000,
        'tax'       => 0,
        'discount'  => 0,
        'currency'  => 'PYG',
        'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => 1000]],
        'date'      => date('Y-m-d H:i:s'),
        'timestamp' => time(),
    ];
    $result = $service->save(SaleInput::fromPayload($payload, $companyId));
    return [$result->transactionId, $invoiceNo];
}

/**
 * Encola el documento y lo drena con el provider simulado. Devuelve la fila
 * de `einvoice_document` resultante.
 *
 * Limpia los checkpoints de numeración (`stampDetails`, `numberingPreflight`)
 * antes de cada caso: son caché por cuenta, y sin resetearlos el segundo caso
 * correría contra el timbrado que leyó el primero.
 *
 * @return array{status:string, error:string, payload:?array}
 */
function emitir(FakeFactomateProvider $provider, string $companyId, string $transactionId): array
{
    global $db;
    $db->Execute(
        "UPDATE einvoice_account
            SET provisioning = provisioning - 'stampDetails' - 'numberingPreflight'
          WHERE companyid = ?",
        [$companyId]
    );
    $db->Execute('DELETE FROM einvoice_document WHERE companyid = ? AND transactionid = ?', [$companyId, $transactionId]);

    $svc = new EInvoiceService($provider);
    $svc->enqueueForSale($companyId, $transactionId, 'FC', null);
    $svc->drain(50);

    $row = ncmExecute(
        'SELECT status, error_message, request_payload FROM einvoice_document
          WHERE companyid = ? AND transactionid = ?',
        [$companyId, $transactionId]
    );
    $payloadRaw = $row['request_payload'] ?? null;
    $payload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : (is_array($payloadRaw) ? $payloadRaw : null);

    return [
        'status'  => (string) ($row['status'] ?? ''),
        'error'   => (string) ($row['error_message'] ?? ''),
        'payload' => is_array($payload) ? $payload : null,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// (A) El payload lleva el número congelado de la venta
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (A) el documento sale con el correlativo que congeló la caja ===\n";
seedAccount($companyId, $registerId);

$provider = new FakeFactomateProvider();
$provider->currentNumber = 0; // talonario virgen del lado del emisor
$resA = emitir($provider, $companyId, $txA);

check('(A1) el documento se emite', $resA['status'] === 'issued',
    "status={$resA['status']} error={$resA['error']}", $failures, $checks);
check('(A2) el payload lleva el número congelado de la venta, no -1',
    ($resA['payload']['number'] ?? null) === $noA,
    'number=' . json_encode($resA['payload']['number'] ?? null) . " esperaba $noA", $failures, $checks);
check('(A3) y es un entero pelado (el EEE-PPP sale del timbrado del emisor)',
    is_int($resA['payload']['number'] ?? null),
    'tipo=' . gettype($resA['payload']['number'] ?? null), $failures, $checks);
check('(A4) el número que se declaró es el MISMO que quedó en la venta',
    (int) (ncmExecute(
        'SELECT invoiceNo FROM transaction WHERE transactionId = ? AND companyId = ?',
        [$txA, $companyId]
    )['invoiceNo'] ?? 0) === $noA,
    'la venta y el documento no coinciden', $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (B) Sin número congelado NO se emite — jamás un -1 silencioso
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (B) venta sin número congelado: error, nunca -1 ===\n";
$provider = new FakeFactomateProvider();
$provider->currentNumber = 0;
// Simula la venta vieja anterior al B1 de la mig 145 (o el dato corrupto).
$db->Execute('UPDATE transaction SET invoiceno = NULL WHERE transactionid = ?', [$txB]);
$resB = emitir($provider, $companyId, $txB);

check('(B1) el documento queda en error', $resB['status'] === 'error',
    "status={$resB['status']}", $failures, $checks);
check('(B2) NO se mandó nada al emisor', $provider->issued === [],
    'se emitieron ' . count($provider->issued) . ' documentos', $failures, $checks);
check('(B3) el error explica que falta el número del comprobante',
    mb_stripos($resB['error'], 'número de comprobante') !== false,
    "error={$resB['error']}", $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (C) Timbrado incoherente: se corta antes de mandarlo a que SIFEN lo rechace
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (C) el timbrado congelado no es el provisionado en el emisor ===\n";
$provider = new FakeFactomateProvider();
$provider->currentNumber = 0;
$provider->stampNumber = '70000000'; // el emisor emite por OTRO talonario
$resC = emitir($provider, $companyId, $txC);

check('(C1) el documento queda en error', $resC['status'] === 'error',
    "status={$resC['status']}", $failures, $checks);
check('(C2) y no se mandó — el rechazo no lo pone SIFEN, lo ponemos nosotros',
    $provider->issued === [],
    'se emitieron ' . count($provider->issued) . ' documentos', $failures, $checks);
check('(C3) el error nombra los DOS timbrados, para poder arreglarlo',
    str_contains($resC['error'], TIMBRADO_CAJA) && str_contains($resC['error'], '70000000'),
    "error={$resC['error']}", $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (D) Pre-flight del rango del talonario
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (D) el talonario del emisor ya tiene historia de otros canales ===\n";
$provider = new FakeFactomateProvider();
$provider->currentNumber = $noD; // el emisor ya usó exactamente este número
$resD = emitir($provider, $companyId, $txD);

check('(D1) con el rango remoto alcanzando nuestro número, no se emite',
    $resD['status'] === 'error', "status={$resD['status']}", $failures, $checks);
check('(D2) no se mandó nada', $provider->issued === [],
    'se emitieron ' . count($provider->issued) . ' documentos', $failures, $checks);
check('(D3) el error dice QUÉ número habría que configurar en la caja',
    str_contains($resD['error'], (string) ($noD + 1)),
    "error={$resD['error']}", $failures, $checks);

$provider2 = new FakeFactomateProvider();
$provider2->currentNumber = $noD2 - 1; // el emisor va justo por debajo
$resD2 = emitir($provider2, $companyId, $txD2);

check('(D4) con el rango remoto por debajo, sí emite',
    $resD2['status'] === 'issued', "status={$resD2['status']} error={$resD2['error']}", $failures, $checks);
check('(D5) y con nuestro número', ($resD2['payload']['number'] ?? null) === $noD2,
    'number=' . json_encode($resD2['payload']['number'] ?? null), $failures, $checks);

// El pre-flight es por timbrado, no por documento: una segunda venta de la
// misma caja NO vuelve a preguntarle el rango al emisor.
$stampCallsAntes = $provider2->stampCalls;
$svcReuso = new EInvoiceService($provider2);
$svcReuso->enqueueForSale($companyId, $txD3, 'FC', null);
$svcReuso->drain(50);
check('(D6) el rango se consulta una vez por timbrado, no por documento',
    $provider2->stampCalls === $stampCallsAntes,
    "llamadas antes=$stampCallsAntes ahora={$provider2->stampCalls}", $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (E) Kill-switch de emergencia
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (E) legacyAutoNumbering devuelve la numeración al proveedor ===\n";
seedAccount($companyId, $registerId, ['legacyAutoNumbering' => true]);

$providerE = new FakeFactomateProvider();
$providerE->currentNumber = 999999999; // rango que normalmente cortaría la emisión
$providerE->stampNumber = '70000000';  // timbrado que normalmente sería incoherente
$resE = emitir($providerE, $companyId, $txE);

check('(E1) el documento se emite igual', $resE['status'] === 'issued',
    "status={$resE['status']} error={$resE['error']}", $failures, $checks);
check('(E2) con number = -1 (numera el proveedor)',
    ($resE['payload']['number'] ?? null) === -1,
    'number=' . json_encode($resE['payload']['number'] ?? null), $failures, $checks);
check('(E3) y sin consultar el timbrado: los guards no corren',
    $providerE->stampCalls === 0,
    "stampCalls={$providerE->stampCalls}", $failures, $checks);

// ── Limpieza: la cuenta de FE es del arnés, no del fixture ─────────────────
seedAccount($companyId, $registerId);
$db->Execute('DELETE FROM einvoice_account WHERE companyid = ? AND username = ?', [$companyId, MARCA_DEL_ARNES]);

harnessFinish($failures, $checks);
