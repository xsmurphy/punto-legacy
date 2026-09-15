<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la SERIE SIFEN (`dSerieNum`) — mig 223, context/28 §F8.
 *
 * SIFEN exige la misma serie en todo documento posterior de un punto de
 * expedición que ya emitió con serie (rechazo `1110 — Serie informada
 * incorrecta`), y al cambiarla la numeración reinicia. Por eso la serie es
 * IDENTIDAD de la serie fiscal de Punto, igual que el timbrado y el punto.
 *
 * Contra Postgres real, con el motor SIMULADO en su interfaz (un arnés que
 * emite documentos fiscales de verdad no es un arnés, es una emisión):
 *
 *   (S1) La caja guarda la serie normalizada; una serie inválida se rechaza al
 *        guardar; vacía es válida y es el default.
 *   (S2) Cambiar la serie ABRE una secuencia nueva y la vieja queda intacta;
 *        la serie de facturas no abre una secuencia de notas de crédito.
 *   (S3) Venta con serie: se congela en la transacción y viaja en el body.
 *   (S4) Venta sin serie: el body NO lleva la clave (`''` sería un 422).
 *   (S5) La serie que DECLARA el device manda sobre la vigente de la caja; sin
 *        declarar (bundle viejo) se congela la vigente.
 *   (S6) La unicidad fiscal es POR SERIE: mismo número en otra serie convive,
 *        en la misma serie es duplicado.
 *   (S7) El caso Balloon Party: numerada sin serie + rechazo 1110 → se
 *        configura la serie → `retry()` manda la serie nueva con el MISMO
 *        número y el mismo código de seguridad, y la congela.
 *   (S8) Un documento que el motor YA tenía aprobado sin serie NO toma la
 *        serie nueva al recuperarse.
 *   (S9) Aislamiento multi-tenant: ni la serie ni la caja de otro tenant se
 *        leen ni se editan.
 *   (S7-bis) El motor devuelve el 1110 como documento recuperado: con serie
 *        configurada se reenvía, no se adopta como rechazado.
 *   (S10) Período cerrado: la adopción se estaciona con mensaje final.
 *
 * Uso: `bash api/tests/run_einvoice_serie_test.sh`.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/EInvoice/EInvoiceProvider.php';
require_once dirname(__DIR__) . '/lib/EInvoice/CredentialVault.php';
require_once dirname(__DIR__) . '/lib/EInvoice/Cdc.php';
require_once dirname(__DIR__) . '/lib/EInvoice/SaleToFePyMapper.php';
require_once dirname(__DIR__) . '/lib/EInvoice/EInvoiceService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Documents\DocumentSeries;
use Punto\Api\EInvoice\Cdc;
use Punto\Api\EInvoice\CredentialVault;
use Punto\Api\EInvoice\EInvoiceProvider;
use Punto\Api\EInvoice\EInvoiceProviderFactory;
use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Sales\Exceptions\DuplicateInvoiceNumberException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Services\RegisterAdminException;
use Punto\Api\Services\RegisterAdminService;

// ── Tenants fixture (api/lib/Sales/verify_chain/seed.sql) ──────────────────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024'; // Verify PY
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$otherCompanyId  = 'fa8cf679-9003-417e-8726-5b772d3b6e88'; // Verify MX
$otherRegisterId = 'e91e3e74-b593-4833-9ee8-25b8ce9e4454';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

const MARCA_DEL_ARNES = 'einvoice-serie-test';
const TIMBRADO        = '80099223';
const PREFIJO         = '001-001';
const TENANT_REF      = '019218f0-0000-7000-8000-000000000223';
const RUC_EMISOR      = '80012345';
const RUC_EMISOR_DV   = '6';

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
 * Motor simulado. Rechaza con 1110 todo documento SIN serie cuando
 * `rejectWithoutSerie` está prendido — que es exactamente lo que hace SIFEN en
 * un punto que ya emitió con serie.
 */
final class SerieFakeProvider implements EInvoiceProvider
{
    /** @var array<int,array<string,mixed>> */
    public array $issued = [];
    public bool $rejectWithoutSerie = false;
    public bool $failWithoutCdc = false;
    public ?string $txnId = null;
    /** @var array<string,mixed>|null */
    public ?array $vigente = null;

    public function issue(string $environment, string $tenantRef, string $bearer, array $payload): array
    {
        $this->issued[] = $payload;

        if ($this->failWithoutCdc || ($this->rejectWithoutSerie && !isset($payload['serie']))) {
            return [
                'cdc' => null, 'documentNumber' => null, 'txnId' => $this->txnId,
                'success' => false,
                'statusMessage' => $this->failWithoutCdc
                    ? 'El motor no devolvió CDC (simulado por el arnés).'
                    : '1110 — Serie informada incorrecta',
                'bulkId' => null, 'dCarQR' => null, 'xmlUrl' => null,
                'raw' => ['txnId' => $this->txnId, 'estado' => 'rechazado'],
            ];
        }

        $cdc = $this->cdcFor($payload);
        return [
            'cdc' => $cdc, 'documentNumber' => null, 'txnId' => $this->txnId,
            'success' => true, 'statusMessage' => null, 'bulkId' => $cdc,
            'dCarQR' => null, 'xmlUrl' => null, 'raw' => [],
        ];
    }

    public function cdcFor(array $payload): string
    {
        return Cdc::build([
            'documentType'    => (int) ($payload['tipoDocumento'] ?? 1),
            'ruc'             => RUC_EMISOR,
            'rucCheckDigit'   => RUC_EMISOR_DV,
            'establishment'   => (string) ($payload['establecimiento'] ?? ''),
            'expeditionPoint' => (string) ($payload['punto'] ?? ''),
            'number'          => (int) ($payload['numero'] ?? 0),
            'taxpayerType'    => 1,
            'date'            => substr((string) ($payload['fecha'] ?? ''), 0, 10),
            'emissionType'    => (int) ($payload['tipoEmision'] ?? 1),
            'securityCode'    => (string) ($payload['codigoSeguridadAleatorio'] ?? ''),
        ]);
    }

    public function lookupByTxn(string $e, string $t, string $b, string $txnId): array
    {
        return ['vigente' => $this->vigente, 'intentos' => []];
    }

    public function lookupByNumber(string $e, string $t, string $b, int $tipo, string $est, string $punto, string $numero): array
    {
        return ['vigente' => $this->vigente, 'intentos' => []];
    }

    public function userInfo(string $e, string $t, string $b): array { return []; }
    public function readiness(string $t, string $b): array { return ['ready' => true, 'checks' => [], 'unverifiable' => []]; }
    public function patchTenant(string $e, string $t, string $b, array $f): array { return []; }
    public function emitterTimbrado(string $e, string $t, string $b): array { return []; }
    public function paymentMethods(string $e, string $t, string $b): array { return []; }
    public function cancel(string $e, string $t, string $b, string $cdc, string $r): array { return []; }
    public function kude(string $e, string $t, string $b, string $cdc): string { return ''; }
    public function xml(string $e, string $t, string $b, string $cdc): string { return ''; }
    public function clientByRuc(string $e, string $t, string $b, string $ruc): array { return []; }
    public function getBulk(string $e, string $t, string $b, string $ref): array { return []; }
}

function seedAccount(string $companyId): void
{
    global $db;
    $db->Execute(
        "INSERT INTO einvoice_account
            (companyid, provider, username, password_enc, provider_tenant_ref, status, environment, emitter, config)
         VALUES (?, 'fepy', ?, ?, ?, 'ok', 'test', ?::jsonb, '{}'::jsonb)
         ON CONFLICT (companyid) DO UPDATE SET
            provider = 'fepy', username = EXCLUDED.username, password_enc = EXCLUDED.password_enc,
            provider_tenant_ref = EXCLUDED.provider_tenant_ref, status = 'ok', environment = 'test',
            emitter = EXCLUDED.emitter, config = EXCLUDED.config",
        [
            $companyId, MARCA_DEL_ARNES, CredentialVault::encrypt('no-usada'), TENANT_REF,
            json_encode(['Ruc' => RUC_EMISOR . '-' . RUC_EMISOR_DV]),
        ]
    );
    EInvoiceProviderFactory::forget($companyId);
}

function dropAccount(string $companyId): void
{
    global $db;
    $db->Execute('DELETE FROM einvoice_account WHERE companyid = ?', [$companyId]);
    EInvoiceProviderFactory::forget($companyId);
}

/**
 * Venta contado real. `$serie` = lo que declara el device: string (incluida
 * `''`) o null para "el payload no trae la clave" (bundle anterior a la 223).
 * SIN cuenta de FE conectada, para que `save()` no emita en línea contra el
 * motor real (ver el docblock equivalente en einvoice_emitter_numbering_test).
 */
function crearVenta(SaleService $service, string $itemId, int $invoiceNo, string $companyId, ?string $serie): string
{
    $payload = [
        'uid'       => MARCA_DEL_ARNES . '-' . bin2hex(random_bytes(8)),
        'type'      => 0,
        'invoiceno' => $invoiceNo,
        'sale'      => [[
            'itemId' => $itemId, 'count' => 1, 'name' => 'Test serie',
            'uniPrice' => 1000, 'price' => 1000, 'total' => 1000,
            'tax' => 0, 'discount' => 0, 'totalDiscount' => 0,
            'user' => '', 'type' => '', 'date' => '', 'note' => '',
            'currency' => 'PYG', 'uId' => 0,
        ]],
        'subtotal'  => 1000, 'tax' => 0, 'discount' => 0, 'currency' => 'PYG',
        'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => 1000]],
        'date'      => date('Y-m-d H:i:s'),
        'timestamp' => time(),
    ];
    if ($serie !== null) {
        $payload['invoiceserie'] = $serie;
    }
    return $service->save(SaleInput::fromPayload($payload, $companyId))->transactionId;
}

function serieCongelada(string $transactionId, string $companyId): ?string
{
    $row = ncmExecute('SELECT invoiceserie FROM transaction WHERE transactionid = ? AND companyid = ?', [$transactionId, $companyId]);
    $v = $row['invoiceserie'] ?? null;
    return $v === null ? null : (string) $v;
}

/** @return array{docId:string,status:string,error:string,payload:?array,attempts:int} */
function filaDoc(string $companyId, string $transactionId): array
{
    $row = ncmExecute(
        'SELECT einvoicedocid, status, error_message, request_payload, attempts
           FROM einvoice_document WHERE companyid = ? AND transactionid = ?',
        [$companyId, $transactionId]
    );
    $raw = $row['request_payload'] ?? null;
    $payload = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    return [
        'docId'    => (string) ($row['einvoicedocid'] ?? ''),
        'status'   => (string) ($row['status'] ?? ''),
        'error'    => (string) ($row['error_message'] ?? ''),
        'payload'  => is_array($payload) ? $payload : null,
        'attempts' => (int) ($row['attempts'] ?? 0),
    ];
}

function emitir(SerieFakeProvider $provider, string $companyId, string $transactionId): array
{
    global $db;
    $db->Execute('DELETE FROM einvoice_document WHERE companyid = ? AND transactionid = ?', [$companyId, $transactionId]);
    $svc = new EInvoiceService($provider);
    $svc->enqueueForSale($companyId, $transactionId, 'FC', null);
    $svc->drain(50);
    return filaDoc($companyId, $transactionId);
}

/** @return array<string,int> serie => nextnumber de las facturas de la caja */
function secuenciasFactura(string $companyId, string $registerId): array
{
    $rows = ncmRows(
        "SELECT serie, nextnumber FROM document_sequence
          WHERE companyid = ? AND doctype = 'factura' AND scopetype = 'register' AND scopeid = ?
            AND invoiceauth = ? AND prefix = ?",
        [$companyId, $registerId, TIMBRADO, PREFIJO]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['serie']] = (int) $r['nextnumber'];
    }
    return $out;
}

// ── Fixture ────────────────────────────────────────────────────────────────
dropAccount($companyId);
$admin = new RegisterAdminService($companyId);
$admin->update($registerId, [
    'fiscal'    => ['invoiceAuth' => TIMBRADO, 'invoicePrefix' => PREFIJO, 'invoiceSerie' => '', 'creditNoteSerie' => ''],
    'numbering' => ['factura' => '500'],
]);

$itemRow = ncmExecute('SELECT itemId FROM item WHERE companyId = ? LIMIT 1', [$companyId]);
$itemId  = (string) ($itemRow['itemid'] ?? $itemRow['itemId'] ?? '');
if ($itemId === '') {
    fwrite(STDERR, "No hay ítems en el tenant fixture — ¿se cargó verify_chain/seed.sql?\n");
    exit(2);
}
$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S1) la caja guarda la serie; inválida se rechaza al guardar ===\n";

$rechazada = null;
try {
    $admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'A1']]);
} catch (RegisterAdminException $e) {
    $rechazada = $e;
}
check('(S1a) una serie que no son dos letras se rechaza con 422',
    $rechazada !== null && $rechazada->httpCode() === 422,
    'excepción=' . ($rechazada ? $rechazada->getMessage() : 'ninguna'), $failures, $checks);

$rechazada = null;
try {
    $admin->update($registerId, ['fiscal' => ['creditNoteSerie' => 'ABC']]);
} catch (RegisterAdminException $e) {
    $rechazada = $e;
}
check('(S1b) también la de notas de crédito', $rechazada !== null,
    'se guardó una serie de 3 letras', $failures, $checks);

$listed = array_values(array_filter($admin->listAll(), fn ($r) => $r['id'] === $registerId))[0] ?? [];
check('(S1c) sin cargar nada la serie es vacía (ningún valor precargado)',
    ($listed['fiscal']['invoiceSerie'] ?? null) === '' && ($listed['fiscal']['creditNoteSerie'] ?? null) === '',
    'fiscal=' . json_encode($listed['fiscal'] ?? null), $failures, $checks);

$rechazada = null;
try {
    $admin->update($registerId, ['fiscal' => ['invoicePrefix' => '', 'invoiceSerie' => 'AA']]);
} catch (RegisterAdminException $e) {
    $rechazada = $e;
}
$listed = array_values(array_filter($admin->listAll(), fn ($r) => $r['id'] === $registerId))[0] ?? [];
check('(S1d) una serie en una caja SIN punto de expedición se rechaza (422) y no se guarda nada',
    $rechazada !== null && $rechazada->httpCode() === 422
        && ($listed['fiscal']['invoicePrefix'] ?? '') === PREFIJO && ($listed['fiscal']['invoiceSerie'] ?? null) === '',
    'excepción=' . ($rechazada ? $rechazada->getMessage() : 'ninguna') . ' fiscal=' . json_encode($listed['fiscal'] ?? null), $failures, $checks);

check('(S1e) DocumentSeries ignora una serie sin punto (no abre la identidad (\'\', \'\', AA))',
    (new DocumentSeries(TIMBRADO, '', 'AA'))->serie === '' && (new DocumentSeries(TIMBRADO, PREFIJO, 'aa'))->serie === 'AA',
    'constructor no descartó la serie', $failures, $checks);

// Serie corrupta cargada por fuera del panel: la venta NO se rechaza.
$db->Execute(
    "UPDATE register SET data = jsonb_set(coalesce(data, '{}'::jsonb), '{registerInvoiceSerie}', '\"A1\"'::jsonb, true)
      WHERE registerId = ? AND companyId = ?",
    [$registerId, $companyId]
);
$ventaCorrupta = null;
try {
    $ventaCorrupta = crearVenta($service, $itemId, 777, $companyId, null);
} catch (\Throwable $e) {
    $errCorrupta = $e->getMessage();
}
check('(S1f) una serie inválida en la config de la caja no rechaza la venta: se emite sin serie',
    $ventaCorrupta !== null && serieCongelada($ventaCorrupta, $companyId) === null
        && DocumentSeries::forRegister($registerId, $companyId, 'factura')->serie === '',
    'error=' . ($errCorrupta ?? '') . ' serie=' . json_encode($ventaCorrupta ? serieCongelada($ventaCorrupta, $companyId) : null), $failures, $checks);
$db->Execute(
    "UPDATE register SET data = data - 'registerInvoiceSerie' WHERE registerId = ? AND companyId = ?",
    [$registerId, $companyId]
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S2) cambiar la serie abre una secuencia nueva ===\n";

$admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'aa']]);
$listed = array_values(array_filter($admin->listAll(), fn ($r) => $r['id'] === $registerId))[0] ?? [];
$seqs   = secuenciasFactura($companyId, $registerId);

check('(S2a) se guarda normalizada a mayúsculas',
    ($listed['fiscal']['invoiceSerie'] ?? null) === 'AA',
    'invoiceSerie=' . json_encode($listed['fiscal']['invoiceSerie'] ?? null), $failures, $checks);
check('(S2b) nace la secuencia de la serie AA arrancando en 1',
    ($seqs['AA'] ?? null) === 1, 'secuencias=' . json_encode($seqs), $failures, $checks);
check('(S2c) la secuencia sin serie queda intacta en 500',
    ($seqs[''] ?? null) === 500, 'secuencias=' . json_encode($seqs), $failures, $checks);
check('(S2d) el panel muestra la serie VIGENTE (próxima factura 1, no 500)',
    ($listed['numbering']['factura'] ?? null) === '1',
    'numbering=' . json_encode($listed['numbering'] ?? null), $failures, $checks);

$ncAA = ncmExecute(
    "SELECT 1 FROM document_sequence WHERE companyid = ? AND doctype = 'nota_credito' AND scopeid = ? AND serie = 'AA' LIMIT 1",
    [$companyId, $registerId]
);
check('(S2e) la serie de FACTURAS no abre una secuencia de notas de crédito con esa serie',
    !$ncAA, 'hay una secuencia nota_credito/AA', $failures, $checks);

$doc = (new \Punto\Api\Services\RegisterService($ctx))->docNumbers($registerId, $companyId);
check('(S2f) lo que baja al POS es la serie vigente con su contador',
    ($doc['invoiceSerie'] ?? null) === 'AA' && (int) ($doc['invoiceNo'] ?? 0) === 1,
    'docNumbers=' . json_encode(['serie' => $doc['invoiceSerie'] ?? null, 'no' => $doc['invoiceNo'] ?? null]), $failures, $checks);

// Volver a la serie vacía no resetea nada: reencuentra su fila con 500.
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$doc = (new \Punto\Api\Services\RegisterService($ctx))->docNumbers($registerId, $companyId);
check('(S2g) volver a "sin serie" reencuentra su secuencia en 500 (no se reseteó)',
    ($doc['invoiceSerie'] ?? null) === '' && (int) ($doc['invoiceNo'] ?? 0) === 500,
    'docNumbers=' . json_encode(['serie' => $doc['invoiceSerie'] ?? null, 'no' => $doc['invoiceNo'] ?? null]), $failures, $checks);

$admin->update($registerId, ['fiscal' => ['creditNoteSerie' => 'AB']]);
$ncAB = ncmExecute(
    "SELECT nextnumber FROM document_sequence
      WHERE companyid = ? AND doctype = 'nota_credito' AND scopetype = 'register' AND scopeid = ?
        AND invoiceauth = ? AND prefix = ? AND serie = 'AB'",
    [$companyId, $registerId, TIMBRADO, PREFIJO]
);
check('(S2h) la serie de notas de crédito abre SU secuencia y arranca en 1 (lo que dice el diálogo)',
    (int) ($ncAB['nextnumber'] ?? 0) === 1, 'nextnumber=' . json_encode($ncAB['nextnumber'] ?? null), $failures, $checks);
$admin->update($registerId, ['fiscal' => ['creditNoteSerie' => '']]);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S3-S5) la venta congela la serie y el body la lleva ===\n";

$admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'AA']]);

$txS3 = crearVenta($service, $itemId, 101, $companyId, 'AA');
$txS4 = crearVenta($service, $itemId, 501, $companyId, '');
$txS5a = crearVenta($service, $itemId, 102, $companyId, null);   // bundle viejo: vigente AA
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'AB']]);
$txS5b = crearVenta($service, $itemId, 103, $companyId, 'AA');  // el device numeró bajo AA

check('(S3a) la venta con serie la congela en la transacción',
    serieCongelada($txS3, $companyId) === 'AA', 'invoiceserie=' . json_encode(serieCongelada($txS3, $companyId)), $failures, $checks);
check('(S4a) la venta sin serie congela NULL',
    serieCongelada($txS4, $companyId) === null, 'invoiceserie=' . json_encode(serieCongelada($txS4, $companyId)), $failures, $checks);
check('(S5a) sin declararla (bundle viejo) se congela la vigente de la caja',
    serieCongelada($txS5a, $companyId) === 'AA', 'invoiceserie=' . json_encode(serieCongelada($txS5a, $companyId)), $failures, $checks);
check('(S5b) la que declara el device manda sobre la vigente (AA aunque la caja ya diga AB)',
    serieCongelada($txS5b, $companyId) === 'AA', 'invoiceserie=' . json_encode(serieCongelada($txS5b, $companyId)), $failures, $checks);
check('(S5c) el registry la espeja (unicidad y reconciliación leen de ahí)',
    DocumentSeries::forTransaction($txS3, $companyId)->serie === 'AA', 'forTransaction no trae la serie', $failures, $checks);

seedAccount($companyId);
$prov = new SerieFakeProvider();
$resS3 = emitir($prov, $companyId, $txS3);
$resS5b = emitir($prov, $companyId, $txS5b);
// S4 se emite con la caja SIN serie: con una serie configurada, un documento
// numerado sin serie que SIFEN nunca aceptó la ADOPTA (eso lo cubre S7).
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$resS4 = emitir($prov, $companyId, $txS4);

check('(S3b) el documento con serie se emite', $resS3['status'] === 'issued',
    "status={$resS3['status']} error={$resS3['error']}", $failures, $checks);
check('(S3c) el body a FE-PY lleva `serie` junto al número',
    ($resS3['payload']['serie'] ?? null) === 'AA' && ($resS3['payload']['numero'] ?? null) === '0000101'
        && ($resS3['payload']['establecimiento'] ?? null) === '001' && ($resS3['payload']['punto'] ?? null) === '001',
    'payload=' . json_encode(array_intersect_key((array) $resS3['payload'], array_flip(['establecimiento', 'punto', 'numero', 'serie']))), $failures, $checks);
check('(S4b) sin serie el body NO lleva la clave (vacía sería un 422 del motor)',
    $resS4['status'] === 'issued' && is_array($resS4['payload']) && !array_key_exists('serie', $resS4['payload']),
    "status={$resS4['status']} payload=" . json_encode($resS4['payload']), $failures, $checks);
check('(S5d) una reemisión/reintento manda la CONGELADA, no la vigente (AA, no AB)',
    ($resS5b['payload']['serie'] ?? null) === 'AA', 'serie=' . json_encode($resS5b['payload']['serie'] ?? null), $failures, $checks);
dropAccount($companyId);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S6) la unicidad fiscal es por serie ===\n";

$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$dupOk = true;
try {
    crearVenta($service, $itemId, 101, $companyId, ''); // 101 ya existe bajo AA
} catch (\Throwable $e) {
    $dupOk = false;
    $dupMsg = $e->getMessage();
}
check('(S6a) el 101 sin serie convive con el 101 de la serie AA',
    $dupOk, 'falló: ' . ($dupMsg ?? ''), $failures, $checks);

$dupE = null;
try {
    crearVenta($service, $itemId, 101, $companyId, 'AA');
} catch (DuplicateInvoiceNumberException $e) {
    $dupE = $e;
} catch (\Throwable $e) {
    $dupE = $e;
}
check('(S6b) el 101 otra vez en la serie AA es duplicado',
    $dupE instanceof DuplicateInvoiceNumberException,
    'excepción=' . ($dupE ? get_class($dupE) . ': ' . $dupE->getMessage() : 'ninguna'), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S7) numerada sin serie + rechazo 1110 → retry con la serie configurada ===\n";

// Caja SIN serie, como la 840 de Balloon Party antes del arreglo.
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$tx840 = crearVenta($service, $itemId, 840, $companyId, '');

seedAccount($companyId);
$prov7 = new SerieFakeProvider();
$prov7->rejectWithoutSerie = true;
$prov7->txnId = '01a08b38-aa32-70dd-b061-eba07a390840';
$res7a = emitir($prov7, $companyId, $tx840);

check('(S7a) SIFEN la rechaza con 1110 y queda en error',
    $res7a['status'] === 'error' && str_contains($res7a['error'], '1110'),
    "status={$res7a['status']} error={$res7a['error']}", $failures, $checks);
check('(S7b) el primer envío salió sin serie', is_array($res7a['payload']) && !array_key_exists('serie', $res7a['payload']),
    'payload=' . json_encode($res7a['payload']), $failures, $checks);

// El comercio carga la serie del punto, arrancando la serie AA donde sigue.
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'AA'], 'numbering' => ['factura' => '841']]);

$svc7 = new EInvoiceService($prov7);
$svc7->retry($companyId, $res7a['docId']);
$res7b = filaDoc($companyId, $tx840);
$envios = $prov7->issued;
$ultimo = end($envios) ?: [];

check('(S7c) el reintento se emite', $res7b['status'] === 'issued',
    "status={$res7b['status']} error={$res7b['error']}", $failures, $checks);
check('(S7d) y manda la serie configurada', ($ultimo['serie'] ?? null) === 'AA',
    'serie=' . json_encode($ultimo['serie'] ?? null), $failures, $checks);
check('(S7e) con el MISMO número y el mismo código de seguridad (mismo CDC)',
    count($envios) === 2
        && ($envios[0]['numero'] ?? null) === ($ultimo['numero'] ?? '') && ($ultimo['numero'] ?? null) === '0000840'
        && ($envios[0]['codigoSeguridadAleatorio'] ?? null) === ($ultimo['codigoSeguridadAleatorio'] ?? ''),
    'envíos=' . json_encode(array_map(fn ($p) => [$p['numero'] ?? null, $p['codigoSeguridadAleatorio'] ?? null, $p['serie'] ?? null], $envios)), $failures, $checks);
check('(S7f) la transacción congela la serie adoptada',
    serieCongelada($tx840, $companyId) === 'AA', 'invoiceserie=' . json_encode(serieCongelada($tx840, $companyId)), $failures, $checks);
check('(S7g) y el request_payload archivado dice lo que realmente salió',
    ($res7b['payload']['serie'] ?? null) === 'AA', 'request_payload.serie=' . json_encode($res7b['payload']['serie'] ?? null), $failures, $checks);
$seqs7 = secuenciasFactura($companyId, $registerId);
check('(S7h) la secuencia AA no queda por detrás del 840 que ahora le pertenece',
    ($seqs7['AA'] ?? 0) >= 841, 'secuencias=' . json_encode($seqs7), $failures, $checks);
dropAccount($companyId);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S8) aprobado sin serie: NO toma la serie nueva ===\n";

// Numerada y ENVIADA sin serie (la caja todavía no tenía), y se perdió la
// respuesta del motor. Recién después el comercio carga la serie AA.
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$txS8 = crearVenta($service, $itemId, 900, $companyId, '');
seedAccount($companyId);
$prov8 = new SerieFakeProvider();
$prov8->failWithoutCdc = true;              // se perdió la respuesta…
$prov8->txnId = '01a08b38-aa32-70dd-b061-eba07a390900';
$res8a = emitir($prov8, $companyId, $txS8);
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'AA']]);

$prov8b = new SerieFakeProvider();
$prov8b->txnId = $prov8->txnId;
$prov8b->vigente = [                         // …pero el motor lo tenía APROBADO, sin serie
    'txnId'  => $prov8->txnId,
    'cdc'    => $prov8->cdcFor((array) $res8a['payload']),
    'estado' => 'aprobado',
    'numero' => '0000900',
    'sifen'  => ['codigoRespuesta' => '0260', 'mensaje' => 'Autorizado el DE', 'protocoloAutorizacion' => '1'],
];
$db->Execute("UPDATE einvoice_document SET next_retry_at = now() WHERE companyid = ? AND transactionid = ?", [$companyId, $txS8]);
(new EInvoiceService($prov8b))->drain(50);
$res8b = filaDoc($companyId, $txS8);

check('(S8a) el documento se recupera sin reemitirse',
    $res8b['status'] === 'issued' && $prov8b->issued === [],
    "status={$res8b['status']} emisiones=" . count($prov8b->issued), $failures, $checks);
check('(S8b) y NO toma la serie AA de la caja: queda sin serie',
    serieCongelada($txS8, $companyId) === null, 'invoiceserie=' . json_encode(serieCongelada($txS8, $companyId)), $failures, $checks);
dropAccount($companyId);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S9) aislamiento multi-tenant ===\n";

// El otro tenant tiene una caja con el MISMO timbrado y punto, y serie ZZ.
(new RegisterAdminService($otherCompanyId))->update($otherRegisterId, [
    'fiscal' => ['invoiceAuth' => TIMBRADO, 'invoicePrefix' => PREFIJO, 'invoiceSerie' => 'ZZ'],
]);

check('(S9a) la serie de una caja no se lee con el companyId de otro tenant',
    DocumentSeries::forRegister($registerId, $otherCompanyId, 'factura')->isFiscal() === false,
    'forRegister cruzó tenants', $failures, $checks);

$cruzado = null;
try {
    (new RegisterAdminService($otherCompanyId))->update($registerId, ['fiscal' => ['invoiceSerie' => 'XX']]);
} catch (RegisterAdminException $e) {
    $cruzado = $e;
}
check('(S9b) un tenant no puede cambiar la serie de la caja de otro (404)',
    $cruzado !== null && $cruzado->httpCode() === 404,
    'excepción=' . ($cruzado ? $cruzado->getMessage() : 'ninguna'), $failures, $checks);

// La adopción busca la caja que tiene el par (timbrado, punto) SOLO en el
// tenant del documento: con la caja propia sin serie, no puede tomar la ZZ.
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$txS9 = crearVenta($service, $itemId, 950, $companyId, '');
seedAccount($companyId);
$prov9 = new SerieFakeProvider();
$res9 = emitir($prov9, $companyId, $txS9);
check('(S9c) la emisión no adopta la serie ZZ del otro tenant',
    $res9['status'] === 'issued' && !array_key_exists('serie', (array) $res9['payload']) && serieCongelada($txS9, $companyId) === null,
    "status={$res9['status']} payload.serie=" . json_encode($res9['payload']['serie'] ?? null), $failures, $checks);

$otherSeq = ncmExecute(
    "SELECT nextnumber FROM document_sequence WHERE companyid = ? AND scopeid = ? AND doctype = 'factura' AND serie = 'ZZ'",
    [$otherCompanyId, $otherRegisterId]
);
check('(S9d) la secuencia del otro tenant no se tocó',
    (int) ($otherSeq['nextnumber'] ?? 0) === 1, 'nextnumber=' . json_encode($otherSeq['nextnumber'] ?? null), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S7-bis) el motor devuelve el rechazo 1110 como documento recuperado ===\n";

// Variante de la 840: el primer envío dejó `txnId` y el motor, al consultarlo,
// devuelve el documento RECHAZADO. Sin serie para corregir se reflejaría como
// rechazado (sección G del arnés de numeración); CON serie configurada tiene
// que reenviarse con la serie y el mismo número y código de seguridad.
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$tx860 = crearVenta($service, $itemId, 860, $companyId, '');
seedAccount($companyId);
$prov7b = new SerieFakeProvider();
$prov7b->rejectWithoutSerie = true;
$prov7b->txnId = '01a08b38-aa32-70dd-b061-eba07a390860';
$res7ba = emitir($prov7b, $companyId, $tx860);
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'AA']]);

$prov7b->vigente = [
    'txnId'  => $prov7b->txnId,
    'cdc'    => $prov7b->cdcFor((array) $res7ba['payload']),
    'estado' => 'rechazado',
    'numero' => '0000860',
    'sifen'  => ['codigoRespuesta' => '1110', 'mensaje' => 'Serie informada incorrecta'],
];
(new EInvoiceService($prov7b))->retry($companyId, $res7ba['docId']);
$res7bb = filaDoc($companyId, $tx860);
$envios7b = $prov7b->issued;
$ultimo7b = end($envios7b) ?: [];

check('(S7-bis a) el rechazado recuperado NO se adopta: se reenvía con la serie y queda emitido',
    $res7bb['status'] === 'issued' && count($envios7b) === 2 && ($ultimo7b['serie'] ?? null) === 'AA',
    "status={$res7bb['status']} envíos=" . count($envios7b) . ' serie=' . json_encode($ultimo7b['serie'] ?? null), $failures, $checks);
check('(S7-bis b) con el mismo número y código de seguridad',
    ($envios7b[0]['numero'] ?? null) === ($ultimo7b['numero'] ?? '')
        && ($envios7b[0]['codigoSeguridadAleatorio'] ?? null) === ($ultimo7b['codigoSeguridadAleatorio'] ?? ''),
    'envíos=' . json_encode(array_map(fn ($p) => [$p['numero'] ?? null, $p['codigoSeguridadAleatorio'] ?? null], $envios7b)), $failures, $checks);
dropAccount($companyId);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (S10) período cerrado: se estaciona con un mensaje final ===\n";

$admin->update($registerId, ['fiscal' => ['invoiceSerie' => '']]);
$tx870 = crearVenta($service, $itemId, 870, $companyId, '');
seedAccount($companyId);
$prov10 = new SerieFakeProvider();
$prov10->rejectWithoutSerie = true;
$prov10->txnId = '01a08b38-aa32-70dd-b061-eba07a390870';
$res10a = emitir($prov10, $companyId, $tx870);
$admin->update($registerId, ['fiscal' => ['invoiceSerie' => 'AA']]);

$db->Execute(
    "INSERT INTO period_close (companyid, period, source)
     SELECT ?, date_trunc('month', transactiondate AT TIME ZONE 'America/Asuncion')::date, 'manual'
       FROM transaction WHERE transactionid = ? AND companyid = ?
     ON CONFLICT DO NOTHING",
    [$companyId, $tx870, $companyId]
);
$enviosAntes = count($prov10->issued);
(new EInvoiceService($prov10))->retry($companyId, $res10a['docId']);
$res10b = filaDoc($companyId, $tx870);

check('(S10a) no se manda nada al motor', count($prov10->issued) === $enviosAntes,
    'envíos=' . count($prov10->issued), $failures, $checks);
check('(S10b) queda en error con un mensaje FINAL (período cerrado, sin "se reintenta")',
    $res10b['status'] === 'error' && str_contains($res10b['error'], 'período') && !str_contains($res10b['error'], 'Se reintenta'),
    "status={$res10b['status']} error={$res10b['error']}", $failures, $checks);
check('(S10c) estacionado: los intentos automáticos quedan agotados (el drainer no lo repite)',
    $res10b['attempts'] >= 8, "attempts={$res10b['attempts']}", $failures, $checks);
check('(S10d) la serie NO quedó congelada', serieCongelada($tx870, $companyId) === null,
    'invoiceserie=' . json_encode(serieCongelada($tx870, $companyId)), $failures, $checks);

$db->Execute("DELETE FROM period_close WHERE companyid = ?", [$companyId]);
dropAccount($companyId);

// ── Limpieza ─────────────────────────────────────────────────────────────
dropAccount($companyId);

harnessFinish($failures, $checks);
