<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la NUMERACIÓN DEL EMISOR y del CONTENIDO del documento en la
 * factura electrónica.
 *
 * Nació cubriendo solo la numeración (secciones A-E). La sección (F) se sumó
 * con la auditoría de emisión del 2026-09-07: siete campos del payload que
 * salían mal o inestables y que SIFEN mira. Van en el MISMO arnés porque
 * comparten fixture, motor simulado y el camino real de drenaje — tener dos
 * arneses para el mismo `drain()` significaría mantener dos fixtures.
 *
 * ── QUÉ SE FUE DE ESTE ARNÉS Y POR QUÉ (reescritura 2026-09-10) ───────────
 *
 * El módulo quedó con UN solo motor (FE-PY, mig 214) y con eso desaparecieron
 * los dos guards PRE-emisión que este arnés ejercitaba, porque los dos
 * consultaban el catálogo de timbrados del motor anterior:
 *
 *   - **Timbrado incoherente antes de emitir** (`assertNumberingCoherence()` /
 *     `remoteStampRow()`): comparaba el timbrado congelado en la venta contra
 *     el que el motor tenía provisionado. FE-PY no expone ese catálogo — el
 *     timbrado lo inyecta él, y no hay nada contra qué comparar.
 *   - **Pre-flight del rango del talonario** (`stampSeries()`): leía el último
 *     número usado del lado del motor para cortar antes de pisarlo. Tampoco
 *     existe: FE-PY no publica un `CurrentNumber`.
 *
 * No se reemplazaron por un mock que finja el guard viejo: sería un test
 * verificando código que no existe. Lo que SÍ existe hoy es la detección
 * POST-emisión —`cdcMismatchFor()` sobre el CDC devuelto, anotada en
 * `einvoice_document.numbering_mismatch` (mig 204)— y ESO es lo que cubre
 * ahora la sección (C). También se fue la aserción `stampCalls === 0` de la
 * vieja (E3), que estaba documentada como falla conocida: era falsa porque el
 * que consultaba el timbrado era el verificador de CDC, y hoy ningún camino
 * de emisión le pregunta nada al motor sobre numeración.
 *
 * La decisión que verifica (owner, 2026-09-07): *"desde el inicio nosotros
 * tenemos que ser dueños de la numeración"*. El documento electrónico sale a
 * SIFEN con el correlativo que la CAJA ya congeló en la venta
 * (`transaction.invoiceNo`, mig 145) — el mismo que salió impreso en el
 * ticket, porque el impreso es la representación impresa de la factura
 * electrónica.
 *
 * Lo que verifica, contra Postgres real y con el motor simulado (un arnés que
 * emite documentos fiscales de verdad contra el emisor no es un arnés, es una
 * emisión):
 *
 *   (A) El payload que sale lleva EL NÚMERO CONGELADO DE LA VENTA, en el
 *       `numero` de xmlgen. Es el caso central del slice.
 *   (B) Venta SIN número congelado → el documento queda en `error` y NO se
 *       manda. Nunca un número inventado ni el campo omitido en silencio: eso
 *       le devolvería la numeración al motor justo en el caso que nadie mira,
 *       y el ticket impreso y el documento fiscal quedarían con números
 *       distintos.
 *   (C) Si el motor numera por su cuenta, el CDC devuelto NO describe el
 *       comprobante impreso. El documento ya existe en SIFEN, así que queda
 *       `issued` (marcarlo `error` lo haría elegible para `retry()`, y
 *       reintentar un emitido lo duplica) con la discrepancia anotada en
 *       `numbering_mismatch`, y su CDC deja de ser imprimible.
 *   (D) El kill-switch `config.legacyAutoNumbering` omite el correlativo
 *       propio — el rollback de emergencia funciona, y con él el guard de CDC
 *       deja de exigir un número que ya no defendemos.
 *   (G) RECUPERACIÓN (2026-09-10): un documento que quedó `error` de nuestro
 *       lado pero EXISTE del lado del motor no se vuelve a emitir — se adopta.
 *       Se consulta por `txnId` (persistido en el mismo UPDATE que marcó el
 *       error) y por número+tipo como fallback, y un `vigente` rechazado se
 *       refleja en vez de reintentarse.
 *   (F) El CONTENIDO del documento (auditoría 2026-09-07): cantidad ×
 *       unitario cierra exacto contra el total, `fecha` es la de la
 *       operación, `codigoSeguridadAleatorio` estable entre reintentos,
 *       establecimiento/punto de la CAJA, `tipoTransaccion` derivado de los
 *       ítems, receptor B2B/B2C y con sus datos de contacto.
 *
 * El caso que más importa es (B). (A) es fácil de escribir bien; (B) es el
 * que separa "somos dueños de la numeración" de "somos dueños salvo cuando
 * falla algo", que en un money-path fiscal es la diferencia entre un
 * documento correcto y una multa por comprobante.
 *
 * Uso (ver `run_einvoice_emitter_numbering_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   FEPY_API_KEY=cmp_<32 hex> \
 *   php -d variables_order=EGPCS api/tests/einvoice_emitter_numbering_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/EInvoice/EInvoiceProvider.php';
require_once dirname(__DIR__) . '/lib/EInvoice/CredentialVault.php';
require_once dirname(__DIR__) . '/lib/EInvoice/Cdc.php';
require_once dirname(__DIR__) . '/lib/EInvoice/SaleToFePyMapper.php';
require_once dirname(__DIR__) . '/lib/EInvoice/EInvoiceService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\EInvoice\Cdc;
use Punto\Api\EInvoice\CredentialVault;
use Punto\Api\EInvoice\EInvoiceProvider;
use Punto\Api\EInvoice\EInvoiceProviderFactory;
use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Support\TenantClock;

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
/** Punto de expedición de la caja fixture: `EEE-PPP` (context/29, una caja = un punto). */
const PREFIJO_CAJA    = '001-001';
/** UUID del emisor en el motor (`einvoice_account.provider_tenant_ref`, mig 206). */
const TENANT_REF      = '019218f0-0000-7000-8000-000000000001';
/** RUC del emisor cacheado en `einvoice_account.emitter` — el guard de CDC lo compara. */
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
 * Motor simulado. Se simula EN LA INTERFAZ (`EInvoiceProvider`), que es la
 * costura que el propio módulo declara para no casarse con un motor, así que
 * lo que se ejercita es el código real de punta a punta salvo el HTTP.
 *
 * El CDC NO es un relleno: se arma con `Cdc::build()` a partir del payload que
 * recibió, igual que lo haría el motor real. Es lo que le da sentido al guard
 * de `cdcMismatchFor()` — con un CDC de ceros, "coincide" y "no coincide"
 * darían lo mismo y la sección (C) no probaría nada.
 */
final class FakeFePyProvider implements EInvoiceProvider
{
    /** @var array<int,array<string,mixed>> payloads que se intentaron emitir */
    public array $issued = [];

    /**
     * El motor decide numerar por su cuenta e ignora nuestro `numero`. Es
     * exactamente el escenario que `numbering_mismatch` existe para detectar.
     */
    public ?int $numberOverride = null;

    /** Número que asigna el motor cuando el payload no lleva `numero` (kill-switch, NC). */
    public int $engineNumber = 4242;

    /**
     * La emisión FALLA sin CDC. Es el caso que destapó todo: el motor recibió
     * el documento y devolvió `txnId`, pero el resultado no trae número fiscal
     * — o la respuesta se perdió después. La fila queda en `error` y el `txnId`
     * es lo único con lo que se puede volver a encontrar el documento.
     */
    public bool $failWithoutCdc = false;

    /** `txnId` que devuelve la emisión. Viene con éxito y viene con fallo. */
    public ?string $txnId = null;

    /**
     * Documento VIGENTE que devuelven las consultas de recuperación, o null
     * para "no hay nada, es seguro emitir".
     *
     * @var array<string,mixed>|null
     */
    public ?array $vigente = null;

    /** @var array<int,array<string,mixed>> consultas de recuperación recibidas */
    public array $lookups = [];

    public function issue(string $environment, string $tenantRef, string $bearer, array $payload): array
    {
        $this->issued[] = $payload;

        if ($this->failWithoutCdc) {
            return [
                'cdc'            => null,
                'documentNumber' => null,
                'txnId'          => $this->txnId,
                'success'        => false,
                'statusMessage'  => 'El motor no devolvió CDC (simulado por el arnés).',
                'bulkId'         => null,
                'dCarQR'         => null,
                'xmlUrl'         => null,
                'raw'            => ['txnId' => $this->txnId, 'estado' => 'error'],
            ];
        }

        $cdc = $this->cdcFor($payload);

        return [
            'cdc'            => $cdc,
            'documentNumber' => null,
            'txnId'          => $this->txnId,
            'success'        => true,
            'statusMessage'  => null,
            // En FE-PY la llave de reconciliación ES el CDC (COMMENT de la mig 214).
            'bulkId'         => $cdc,
            'dCarQR'         => null,
            'xmlUrl'         => null,
            'raw'            => [],
        ];
    }

    public function lookupByTxn(string $e, string $t, string $b, string $txnId): array
    {
        $this->lookups[] = ['by' => 'txn', 'txnId' => $txnId];
        return ['vigente' => $this->vigente, 'intentos' => []];
    }

    public function lookupByNumber(string $e, string $t, string $b, int $tipo, string $est, string $punto, string $numero): array
    {
        $this->lookups[] = [
            'by' => 'numero', 'tipoDocumento' => $tipo,
            'establecimiento' => $est, 'punto' => $punto, 'numero' => $numero,
        ];
        return ['vigente' => $this->vigente, 'intentos' => []];
    }

    /**
     * CDC coherente con el documento que se mandó. Pública porque la sección
     * (G) necesita fabricar el CDC del documento RECUPERADO con la misma
     * cuenta que haría el motor: si fuera otro, el guard de `cdcMismatchFor()`
     * marcaría una divergencia que no existe y la recuperación probaría lo
     * contrario de lo que se quiere probar.
     */
    public function cdcFor(array $payload): string
    {
        $numero = $this->numberOverride
            ?? (isset($payload['numero']) ? (int) $payload['numero'] : $this->engineNumber);

        return Cdc::build([
            'documentType'    => (int) ($payload['tipoDocumento'] ?? 1),
            'ruc'             => RUC_EMISOR,
            'rucCheckDigit'   => RUC_EMISOR_DV,
            'establishment'   => (string) ($payload['establecimiento'] ?? ''),
            'expeditionPoint' => (string) ($payload['punto'] ?? ''),
            'number'          => $numero,
            'taxpayerType'    => 1,
            // `fecha` viaja como `YYYY-MM-DDTHH:MM:SS`; al CDC va SOLO la
            // fecha (8 dígitos). Pasar la cadena entera le daría 14 dígitos y
            // `Cdc::build()` corta antes que truncar.
            'date'            => substr((string) ($payload['fecha'] ?? ''), 0, 10),
            'emissionType'    => (int) ($payload['tipoEmision'] ?? 1),
            'securityCode'    => (string) ($payload['codigoSeguridadAleatorio'] ?? ''),
        ]);
    }

    // ── Resto de la interfaz: no participa de este arnés ──────────────────
    public function userInfo(string $e, string $t, string $b): array { return []; }
    public function readiness(string $t, string $b): array { return ['ready' => true, 'checks' => [], 'unverifiable' => []]; }
    public function patchTenant(string $e, string $t, string $b, array $f): array { return []; }
    public function stamps(string $e, string $t, string $b): array { return []; }
    public function paymentMethods(string $e, string $t, string $b): array { return []; }
    public function cancel(string $e, string $t, string $b, string $cdc, string $r): array { return []; }
    public function kude(string $e, string $t, string $b, string $cdc): string { return ''; }
    public function xml(string $e, string $t, string $b, string $cdc): string { return ''; }
    public function clientByRuc(string $e, string $t, string $b, string $ruc): array { return []; }
    public function getBulk(string $e, string $t, string $b, string $ref): array { return []; }
}

// ── Fixture: la caja tiene timbrado y punto de expedición ──────────────────
// Va ANTES de crear las ventas: `SaleService` CONGELA el prefijo en la
// transacción (mig 209) y el documento sale con el congelado, no con el vivo.

$db->Execute(
    "UPDATE register
        SET data = jsonb_set(
                     jsonb_set(coalesce(data, '{}'::jsonb), '{registerInvoiceAuth}', to_jsonb(?::text), true),
                     '{registerInvoicePrefix}', to_jsonb(?::text), true)
      WHERE registerId = ? AND companyId = ?",
    [TIMBRADO_CAJA, PREFIJO_CAJA, $registerId, $companyId]
);

/**
 * Deja `einvoice_account` en el estado "conectada y provisionada" contra FE-PY.
 *
 * Las dos claves que lo hacen emitir, y que son distintas de las del motor
 * anterior: `provider = 'fepy'` (lo lee `EInvoiceProviderFactory`) y
 * `provider_tenant_ref` NO VACÍO (`FePySession::identity()` corta si falta).
 * La credencial ya no es un bearer cacheado por cuenta sino la API key de
 * plataforma (`FEPY_API_KEY`), que exporta el runner.
 *
 * `emitter` lleva el RUC del emisor porque `cdcMismatchFor()` lo compara
 * contra el componente correspondiente del CDC devuelto.
 */
function seedAccount(string $companyId, array $config = []): void
{
    global $db;

    $db->Execute(
        "INSERT INTO einvoice_account
            (companyid, provider, username, password_enc, provider_tenant_ref,
             status, environment, emitter, config)
         VALUES (?, 'fepy', ?, ?, ?, 'ok', 'test', ?::jsonb, ?::jsonb)
         ON CONFLICT (companyid) DO UPDATE SET
            provider = 'fepy', username = EXCLUDED.username, password_enc = EXCLUDED.password_enc,
            provider_tenant_ref = EXCLUDED.provider_tenant_ref, status = 'ok',
            environment = 'test', emitter = EXCLUDED.emitter, config = EXCLUDED.config",
        [
            $companyId,
            MARCA_DEL_ARNES,
            CredentialVault::encrypt('no-usada'),
            TENANT_REF,
            json_encode(['Ruc' => RUC_EMISOR . '-' . RUC_EMISOR_DV], JSON_UNESCAPED_UNICODE),
            json_encode($config, JSON_UNESCAPED_UNICODE),
        ]
    );

    // El factory cachea el motor por companyId dentro del request: sin esto,
    // borrar y recrear la fila en la misma corrida dejaría el valor viejo.
    EInvoiceProviderFactory::forget($companyId);
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

// Receptor contribuyente de la sección (F). Se da de alta ACÁ, junto con las
// ventas, por la misma razón que ellas: su venta también tiene que nacer sin
// cuenta de facturación conectada.
$contactoId = ncmExecute('SELECT gen_random_uuid() AS id')['id'];

// ── Todas las ventas primero, SIN cuenta de FE conectada ──────────────────
// Ver el docblock de crearVenta(): con la cuenta en 'ok', cada save() sale a
// la API real del motor por la red. Se crean todas acá y recién después se
// conecta la cuenta.
$db->Execute('DELETE FROM einvoice_account WHERE companyid = ?', [$companyId]);
EInvoiceProviderFactory::forget($companyId);

$db->Execute(
    "INSERT INTO contact (contactId, companyId, type, contactName, contactTIN, contactEmail, contactPhone, contactDate, data)
     VALUES (?, ?, 1, 'Cliente Con RUC SA', '80012345-6', 'facturacion@cliente.test', '595981222333', now(), ?::jsonb)",
    [$contactoId, $companyId, json_encode(['contactAddress' => 'Avda. Siempreviva 742'])]
);

[$txA, $noA] = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txB, $noB] = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txC, $noC] = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txD, $noD] = crearVenta($service, $itemId, $nextNo++, $companyId);
// (F1) 10.000 Gs en 3 unidades: la división no es exacta en guaraníes.
[$txF1, $noF1] = crearVenta($service, $itemId, $nextNo++, $companyId, [
    'count' => 3, 'total' => 10000, 'unitPrice' => 10000 / 3,
]);
// (F6/F7) venta a un contribuyente con datos de contacto completos.
[$txF6, $noF6] = crearVenta($service, $itemId, $nextNo++, $companyId, ['customer' => $contactoId]);
// (G) recuperación de documentos huérfanos: una venta por desenlace.
[$txG1, $noG1] = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txG2, $noG2] = crearVenta($service, $itemId, $nextNo++, $companyId);
[$txG3, $noG3] = crearVenta($service, $itemId, $nextNo++, $companyId);

/**
 * Guarda una venta contado real y devuelve [transactionId, invoiceNo].
 *
 * OJO — todas las ventas del arnés se crean ANTES de que exista la cuenta de
 * facturación electrónica, y no es cosmético: `SaleService::save()` encola y
 * EMITE en línea post-commit (`enqueueForSale` + `tryIssueInline`) con un
 * `EInvoiceService` propio, o sea con el motor REAL. Con la cuenta ya en
 * `status='ok'`, cada venta de este arnés saldría a la API de FE-PY por la
 * red — un arnés fiscal no puede hacer eso ni aunque la API key sea falsa y
 * rebote en 401. Sin cuenta conectada, `enqueueForSale()` retorna temprano y
 * no se toca la red: la emisión la maneja después `emitir()`, con el motor
 * simulado.
 */
function crearVenta(SaleService $service, string $itemId, int $invoiceNo, string $companyId, array $opts = []): array
{
    // Overrides para los casos de la sección (F): cantidad/total de la línea
    // (para ejercitar el redondeo del unitario) y cliente de la venta (para
    // ejercitar el receptor). El default es la venta de 1 unidad a 1.000 que
    // usan las secciones (A)-(D) — no se les cambia nada.
    $count = (float) ($opts['count'] ?? 1);
    $total = (float) ($opts['total'] ?? 1000);
    $unit  = (float) ($opts['unitPrice'] ?? $total);

    $payload = [
        'uid'       => MARCA_DEL_ARNES . '-' . bin2hex(random_bytes(8)),
        'type'      => 0,
        'invoiceno' => $invoiceNo,
        'sale'      => [[
            'itemId' => $itemId, 'count' => $count, 'name' => 'Test numeración',
            'uniPrice' => $unit, 'price' => $unit, 'total' => $total,
            'tax' => 0, 'discount' => 0, 'totalDiscount' => 0,
            'user' => '', 'type' => '', 'date' => '', 'note' => '',
            'currency' => 'PYG', 'uId' => 0,
        ]],
        'subtotal'  => $total,
        'tax'       => 0,
        'discount'  => 0,
        'currency'  => 'PYG',
        'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => $total]],
        'date'      => date('Y-m-d H:i:s'),
        'timestamp' => time(),
    ];
    // La clave del receptor en el payload de venta es `client` (ver
    // run_sale_chain.php:526), no `customer` — ese es el nombre de la columna.
    if (!empty($opts['customer'])) {
        $payload['client'] = (string) $opts['customer'];
    }
    $result = $service->save(SaleInput::fromPayload($payload, $companyId));
    return [$result->transactionId, $invoiceNo];
}

/**
 * Encola el documento y lo drena con el motor simulado. Devuelve la fila de
 * `einvoice_document` resultante.
 *
 * @return array{status:string, error:string, payload:?array, cdc:string, mismatch:string}
 */
function emitir(FakeFePyProvider $provider, string $companyId, string $transactionId): array
{
    global $db;
    $db->Execute('DELETE FROM einvoice_document WHERE companyid = ? AND transactionid = ?', [$companyId, $transactionId]);

    $svc = new EInvoiceService($provider);
    $svc->enqueueForSale($companyId, $transactionId, 'FC', null);
    $svc->drain(50);

    $row = ncmExecute(
        'SELECT status, error_message, request_payload, cdc, numbering_mismatch
           FROM einvoice_document
          WHERE companyid = ? AND transactionid = ?',
        [$companyId, $transactionId]
    );
    $payloadRaw = $row['request_payload'] ?? null;
    $payload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : (is_array($payloadRaw) ? $payloadRaw : null);

    return [
        'status'   => (string) ($row['status'] ?? ''),
        'error'    => (string) ($row['error_message'] ?? ''),
        'payload'  => is_array($payload) ? $payload : null,
        'cdc'      => (string) ($row['cdc'] ?? ''),
        'mismatch' => (string) ($row['numbering_mismatch'] ?? ''),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// (A) El payload lleva el número congelado de la venta
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (A) el documento sale con el correlativo que congeló la caja ===\n";
seedAccount($companyId);

$provider = new FakeFePyProvider();
$resA = emitir($provider, $companyId, $txA);

// xmlgen pide `numero` como STRING de hasta 7 dígitos (`^\d{1,7}$`), no como
// entero: el mapper lo manda con ceros a la izquierda.
$numeroEsperadoA = str_pad((string) $noA, 7, '0', STR_PAD_LEFT);

check('(A1) el documento se emite', $resA['status'] === 'issued',
    "status={$resA['status']} error={$resA['error']}", $failures, $checks);
check('(A2) el payload lleva el número congelado de la venta',
    ($resA['payload']['numero'] ?? null) === $numeroEsperadoA,
    'numero=' . json_encode($resA['payload']['numero'] ?? null) . " esperaba $numeroEsperadoA", $failures, $checks);
check('(A3) y es la cadena de 7 dígitos que pide el motor (el EEE-PPP va aparte)',
    is_string($resA['payload']['numero'] ?? null)
        && preg_match('/^\d{7}$/', (string) ($resA['payload']['numero'] ?? '')) === 1,
    'numero=' . json_encode($resA['payload']['numero'] ?? null), $failures, $checks);
check('(A4) el número que se declaró es el MISMO que quedó en la venta',
    (int) (ncmExecute(
        'SELECT invoiceNo FROM transaction WHERE transactionId = ? AND companyId = ?',
        [$txA, $companyId]
    )['invoiceNo'] ?? 0) === $noA,
    'la venta y el documento no coinciden', $failures, $checks);
check('(A5) el CDC devuelto describe esta venta: sin discrepancia de numeración',
    $resA['mismatch'] === '',
    "numbering_mismatch={$resA['mismatch']}", $failures, $checks);
check('(A6) y por eso el CDC es imprimible',
    (new EInvoiceService($provider))->printableDocumentFor($companyId, $txA) !== null,
    'printableDocumentFor devolvió null con cdc=' . $resA['cdc'], $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (B) Sin número congelado NO se emite — jamás un número inventado
// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (B) venta sin número congelado: error, nunca se manda ===\n";
$provider = new FakeFePyProvider();
// Simula la venta vieja anterior al B1 de la mig 145 (o el dato corrupto).
$db->Execute('UPDATE transaction SET invoiceno = NULL WHERE transactionid = ?', [$txB]);
$resB = emitir($provider, $companyId, $txB);

check('(B1) el documento queda en error', $resB['status'] === 'error',
    "status={$resB['status']}", $failures, $checks);
check('(B2) NO se mandó nada al motor', $provider->issued === [],
    'se emitieron ' . count($provider->issued) . ' documentos', $failures, $checks);
check('(B3) el error explica que falta el número del comprobante',
    mb_stripos($resB['error'], 'número de comprobante') !== false,
    "error={$resB['error']}", $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (C) El motor numeró por su cuenta: se DETECTA sobre el CDC devuelto
// ═══════════════════════════════════════════════════════════════════════════
// Reemplaza a las viejas (C) y (D) —los dos guards pre-emisión contra el
// catálogo de timbrados del motor anterior, que ya no existe—. Esta es la
// verificación REAL de hoy: el documento ya salió, así que no se puede
// marcar `error` (lo haría elegible para `retry()`, y reintentar un emitido
// lo duplica); queda `issued` con la discrepancia anotada y sin CDC
// imprimible.
echo "\n=== (C) el CDC devuelto no lleva nuestro número ===\n";
$providerC = new FakeFePyProvider();
$providerC->numberOverride = 7654321; // el motor ignora el `numero` que mandamos
$resC = emitir($providerC, $companyId, $txC);

check('(C1) el documento queda issued: ya existe en SIFEN, no se puede reintentar',
    $resC['status'] === 'issued', "status={$resC['status']} error={$resC['error']}", $failures, $checks);
check('(C2) la discrepancia queda anotada en numbering_mismatch',
    $resC['mismatch'] !== '', 'numbering_mismatch vacío', $failures, $checks);
check('(C3) y nombra los DOS números, para poder resolverlo',
    str_contains($resC['mismatch'], '7654321') && str_contains($resC['mismatch'], (string) $noC),
    "numbering_mismatch={$resC['mismatch']}", $failures, $checks);
check('(C4) el CDC NO se imprime ni se publica: describe otro documento',
    (new EInvoiceService($providerC))->printableDocumentFor($companyId, $txC) === null,
    'printableDocumentFor devolvió el CDC de un documento marcado', $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (D) El kill-switch YA NO EXISTE
// ═══════════════════════════════════════════════════════════════════════════
//
// `legacyAutoNumbering` devolvía la numeración al motor: omitía nuestro
// correlativo del payload y apagaba el guard del CDC. Era de cuando numeraba
// el proveedor. Se eliminó, porque Punto es dueño de la numeración fiscal y
// una palanca que lo desactiva en silencio es un agujero esperando a que
// alguien la encienda "por un rato".
//
// Este caso es el guard de REGRESIÓN: aunque la clave siga apareciendo en la
// config de una cuenta vieja, tiene que ser inerte.
echo "\n=== (D) el kill-switch legacyAutoNumbering quedó inerte ===\n";
seedAccount($companyId, ['legacyAutoNumbering' => true]);

$providerD = new FakeFePyProvider();
$providerD->engineNumber = 9111; // el motor intenta numerar como quiere
$resD = emitir($providerD, $companyId, $txD);

check('(D1) el documento se emite igual', $resD['status'] === 'issued',
    "status={$resD['status']} error={$resD['error']}", $failures, $checks);
check('(D2) y el payload SÍ manda nuestro correlativo — la clave vieja no lo apaga',
    is_array($resD['payload']) && array_key_exists('numero', $resD['payload']),
    'numero=' . json_encode($resD['payload']['numero'] ?? null), $failures, $checks);
// El CDC vuelve con NUESTRO número (el fake solo inventa uno cuando el payload
// no lo trae), así que coinciden y no hay nada que marcar. Que el guard SÍ
// marque cuando el motor cambia el número lo prueba el caso (C), con
// `numberOverride`: acá lo que importa es que el guard quedó ACTIVO, y no
// apagado por una clave de config vieja.
check('(D3) el guard de CDC corre y no marca: el número del CDC es el nuestro',
    $resD['mismatch'] === '',
    "numbering_mismatch={$resD['mismatch']}", $failures, $checks);

// ===========================================================================
// (F) Auditoria de emision 2026-09-07: lo que el documento DECLARA
// ===========================================================================
//
// Campos que salian mal o inestables y que SIFEN mira. No son casos de
// numeracion (el resto del arnes) sino del CONTENIDO del documento:
//
//   F1  `cantidad x precioUnitario` tiene que dar el total. El payload no
//       lleva total por item: SIFEN lo recalcula multiplicando, y el unitario
//       redondeado hacia que 10.000 en 3 unidades declarara 9.999,99999999.
//   F2  `fecha` es la de la OPERACION, no la del envio -- una venta offline
//       drenada al otro dia no puede declarar otra fecha que su ticket.
//   F3  `codigoSeguridadAleatorio` estable entre reintentos del MISMO
//       documento: si cambia, cambia el CDC y el reintento es un duplicado
//       ante SIFEN (rechazo 1002).
//   F4  `establecimiento`/`punto` salen de la CAJA que vendio, no de un
//       default (reemplaza a la vieja F4, que miraba la `series` del catalogo
//       de timbrados del motor anterior).
//   F5  `tipoTransaccion` derivado de los items, no fijo en "servicios".
//   F6  `cliente.tipoOperacion` B2B/B2C segun el receptor tenga RUC.
//   F7  El receptor declara direccion/email/telefono cuando el contacto los tiene.
echo "\n=== (F) contenido del documento (auditoria de emision) ===\n";
seedAccount($companyId);

// -- F1: cantidad x unitario cierra exacto --------------------------------
$resF1 = emitir(new FakeFePyProvider(), $companyId, $txF1);

$lineas = $resF1['payload']['items'] ?? [];
$declarado = 0.0;
foreach ($lineas as $l) {
    $declarado += round((float) $l['cantidad'] * (float) $l['precioUnitario']);
}
// El payload de xmlgen no lleva total del documento: el total contra el que
// tiene que cerrar es el de la VENTA, que es lo que el cliente pago.
$totalVenta = (float) (ncmExecute(
    'SELECT transactionTotal FROM transaction WHERE transactionId = ? AND companyId = ?',
    [$txF1, $companyId]
)['transactionTotal'] ?? -1);

check('(F1a) el documento se emite', $resF1['status'] === 'issued',
    "status={$resF1['status']} error={$resF1['error']}", $failures, $checks);
check('(F1b) cantidad x unitario da EXACTO el total de la venta',
    abs($declarado - $totalVenta) < 0.001,
    'declarado=' . $declarado . ' total=' . $totalVenta, $failures, $checks);
check('(F1c) la linea inexacta se parte en dos renglones',
    count($lineas) === 2, 'renglones=' . count($lineas), $failures, $checks);
$unitariosEnteros = true;
foreach ($lineas as $l) {
    if (abs((float) $l['precioUnitario'] - round((float) $l['precioUnitario'])) > 1e-9) {
        $unitariosEnteros = false;
    }
}
check('(F1d) y cada unitario es entero (PYG no tiene decimales)',
    $unitariosEnteros, json_encode(array_column($lineas, 'precioUnitario')), $failures, $checks);

// -- F2: fecha = fecha de la operacion ------------------------------------
// Se retrasa la venta 3 dias: con la fecha del envio el documento diria HOY.
$db->Execute(
    "UPDATE transaction SET transactionDate = now() - interval '3 days'
      WHERE transactionId = ? AND companyId = ?",
    [$txF1, $companyId]
);
$resF2 = emitir(new FakeFePyProvider(), $companyId, $txF1);
$txRow = ncmExecute(
    'SELECT transactionDate FROM transaction WHERE transactionId = ? AND companyId = ?',
    [$txF1, $companyId]
);
// Se compara contra el reloj del TENANT, no contra el del proceso: es lo que
// declara el documento (`EInvoiceService::issuedDateFor()`).
$esperado = str_replace(' ', 'T', TenantClock::atInstant($companyId, (int) strtotime((string) $txRow['transactiondate'])));
$hoyTenant = substr(str_replace(' ', 'T', TenantClock::atInstant($companyId, time())), 0, 10);

check('(F2a) fecha es la de la OPERACION, no la del envio',
    ($resF2['payload']['fecha'] ?? '') === $esperado,
    'fecha=' . json_encode($resF2['payload']['fecha'] ?? null) . " esperado=$esperado",
    $failures, $checks);
check('(F2b) y por lo tanto NO es la de hoy',
    substr((string) ($resF2['payload']['fecha'] ?? ''), 0, 10) !== $hoyTenant,
    'fecha=' . json_encode($resF2['payload']['fecha'] ?? null) . " hoy=$hoyTenant", $failures, $checks);

// -- F3: codigoSeguridadAleatorio estable entre reintentos ----------------
// `emitir()` borra la fila del outbox, asi que para probar el REINTENTO del
// MISMO documento se reusa la fila: se la marca en error con next_retry_at
// vencido y se drena de nuevo.
$primerCodigo = (string) ($resF2['payload']['codigoSeguridadAleatorio'] ?? '');
$db->Execute(
    "UPDATE einvoice_document
        SET status = 'error', next_retry_at = now() - interval '1 hour'
      WHERE companyid = ? AND transactionid = ?",
    [$companyId, $txF1]
);
$svcRetry = new EInvoiceService(new FakeFePyProvider());
$svcRetry->drain(50);
$rowRetry = ncmExecute(
    'SELECT request_payload, security_code FROM einvoice_document
      WHERE companyid = ? AND transactionid = ?',
    [$companyId, $txF1]
);
$payloadRetry = json_decode((string) ($rowRetry['request_payload'] ?? '{}'), true);
$payloadRetry = is_array($payloadRetry) ? $payloadRetry : [];
check('(F3a) el codigo de seguridad queda persistido en la fila del outbox',
    preg_match('/^\d{9}$/', (string) ($rowRetry['security_code'] ?? '')) === 1,
    'security_code=' . json_encode($rowRetry['security_code'] ?? null), $failures, $checks);
check('(F3b) el reintento del MISMO documento reusa el codigo de seguridad',
    $primerCodigo !== '' && ($payloadRetry['codigoSeguridadAleatorio'] ?? '') === $primerCodigo,
    "primero=$primerCodigo reintento=" . json_encode($payloadRetry['codigoSeguridadAleatorio'] ?? null), $failures, $checks);

// -- F4/F5/F6/F7: derivados de la caja, los items y el receptor -----------
// El par establecimiento/punto sale del prefijo congelado de la CAJA que
// vendio (context/29: una caja = un punto de expedicion), no de un default.
[$establecimientoCaja, $puntoCaja] = explode('-', PREFIJO_CAJA);
check('(F4) establecimiento y punto salen de la caja de la venta',
    ($resF2['payload']['establecimiento'] ?? null) === $establecimientoCaja
        && ($resF2['payload']['punto'] ?? null) === $puntoCaja,
    'establecimiento=' . json_encode($resF2['payload']['establecimiento'] ?? null)
        . ' punto=' . json_encode($resF2['payload']['punto'] ?? null), $failures, $checks);

// El item del fixture es un producto => mercaderia (1), no servicios (2).
check('(F5) tipoTransaccion derivado de los items (mercaderia)',
    ($resF2['payload']['tipoTransaccion'] ?? null) === 1,
    'tipoTransaccion=' . json_encode($resF2['payload']['tipoTransaccion'] ?? null), $failures, $checks);

// Venta sin cliente => innominado => B2C.
check('(F6a) sin RUC el receptor es B2C',
    ($resF2['payload']['cliente']['tipoOperacion'] ?? null) === 2,
    'tipoOperacion=' . json_encode($resF2['payload']['cliente']['tipoOperacion'] ?? null), $failures, $checks);

$resF6 = emitir(new FakeFePyProvider(), $companyId, $txF6);
$cli = $resF6['payload']['cliente'] ?? [];

check('(F6b) con RUC el receptor es B2B y contribuyente',
    ($cli['tipoOperacion'] ?? null) === 1 && ($cli['contribuyente'] ?? null) === true,
    'tipoOperacion=' . json_encode($cli['tipoOperacion'] ?? null)
        . ' contribuyente=' . json_encode($cli['contribuyente'] ?? null)
        . " status={$resF6['status']} error={$resF6['error']}",
    $failures, $checks);
check('(F7a) el receptor declara la direccion del contacto',
    ($cli['direccion'] ?? '') === 'Avda. Siempreviva 742',
    'direccion=' . json_encode($cli['direccion'] ?? null), $failures, $checks);
check('(F7b) el receptor declara el email del contacto',
    ($cli['email'] ?? '') === 'facturacion@cliente.test',
    'email=' . json_encode($cli['email'] ?? null), $failures, $checks);
check('(F7c) el receptor declara el telefono, sin el +',
    ($cli['telefono'] ?? '') === '595981222333',
    'telefono=' . json_encode($cli['telefono'] ?? null), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// (G) RECUPERACIÓN: un documento emitido del otro lado no se emite dos veces
// ═══════════════════════════════════════════════════════════════════════════
//
// Los dos casos que llegaron a producción el 2026-09-10 sin que ningún arnés
// los mirara. Los dos empiezan igual: la emisión falla SIN CDC pero el motor
// devuelve `txnId`, o sea que del otro lado puede haber quedado un documento
// fiscal y del nuestro dice `error`.
//
// Lo que se verifica no es que la recuperación "funcione": es que NO SE VUELVE
// A EMITIR. Un documento fiscal emitido dos veces es un rechazo por duplicado
// en el mejor caso y dos comprobantes válidos por la misma venta en el peor.

echo "\n=== (G) recuperación de un documento huérfano ===\n";

/** Reabre la ventana de reintento y vuelve a drenar con el motor dado. */
function redrenar(FakeFePyProvider $provider, string $companyId, string $transactionId): void
{
    global $db;
    $db->Execute(
        "UPDATE einvoice_document SET next_retry_at = now() WHERE companyid = ? AND transactionid = ?",
        [$companyId, $transactionId]
    );
    (new EInvoiceService($provider))->drain(50);
}

/** Fila cruda del documento, para mirar las columnas que la recuperación toca. */
function filaDoc(string $companyId, string $transactionId): array
{
    $row = ncmExecute(
        'SELECT status, cdc, provider_txn_id, provider_number, sifen_status, error_message, attempts
           FROM einvoice_document WHERE companyid = ? AND transactionid = ?',
        [$companyId, $transactionId]
    );
    return [
        'status'   => (string) ($row['status'] ?? ''),
        'cdc'      => (string) ($row['cdc'] ?? ''),
        'txnId'    => (string) ($row['provider_txn_id'] ?? ''),
        'number'   => (string) ($row['provider_number'] ?? ''),
        'sifen'    => (string) ($row['sifen_status'] ?? ''),
        'error'    => (string) ($row['error_message'] ?? ''),
        'attempts' => (int) ($row['attempts'] ?? 0),
    ];
}

// ── (G1) emitido de verdad allá, `error` acá: se ADOPTA, no se reemite ────
// Es el caso de la nota de crédito nº 2: existía con CDC del lado del motor y
// nuestro outbox agotó los ocho intentos marcándola `error`.

const TXN_G1 = '01a08b38-aa32-70dd-b061-eba07a390500';

$provG1 = new FakeFePyProvider();
$provG1->failWithoutCdc = true;
$provG1->txnId = TXN_G1;
$resG1 = emitir($provG1, $companyId, $txG1);
$filaG1 = filaDoc($companyId, $txG1);

check('(G1a) la emisión fallida deja el documento en error',
    $filaG1['status'] === 'error', "status={$filaG1['status']}", $failures, $checks);
check('(G1b) el txnId se persiste EN el mismo UPDATE que marcó el error',
    $filaG1['txnId'] === TXN_G1,
    "provider_txn_id=" . json_encode($filaG1['txnId']), $failures, $checks);

// Segundo intento: el motor SÍ tenía el documento, aprobado por SIFEN.
$cdcG1 = $provG1->cdcFor((array) $resG1['payload']);
$provG1b = new FakeFePyProvider();
$provG1b->vigente = [
    'txnId'  => TXN_G1,
    'cdc'    => $cdcG1,
    'estado' => 'aprobado',
    'numero' => (string) ($resG1['payload']['numero'] ?? ''),
    'sifen'  => ['codigoRespuesta' => '0260', 'mensaje' => 'Autorizado el DE', 'protocoloAutorizacion' => '99887766'],
];
redrenar($provG1b, $companyId, $txG1);
$filaG1b = filaDoc($companyId, $txG1);

check('(G1c) NO se reemitió: el motor no recibió ningún documento nuevo',
    $provG1b->issued === [],
    'emisiones=' . count($provG1b->issued), $failures, $checks);
check('(G1d) se preguntó primero por txnId, que es la llave exacta',
    ($provG1b->lookups[0]['by'] ?? '') === 'txn' && ($provG1b->lookups[0]['txnId'] ?? '') === TXN_G1,
    'lookups=' . json_encode($provG1b->lookups), $failures, $checks);
check('(G1e) el documento queda issued con el CDC que ya existía',
    $filaG1b['status'] === 'issued' && $filaG1b['cdc'] === $cdcG1,
    "status={$filaG1b['status']} cdc={$filaG1b['cdc']} esperado=$cdcG1 error={$filaG1b['error']}", $failures, $checks);
check('(G1f) queda con llave de reconciliación (provider_number = CDC)',
    $filaG1b['number'] === $cdcG1,
    "provider_number={$filaG1b['number']}", $failures, $checks);
check('(G1g) el veredicto de SIFEN que trajo la consulta se persiste ya mismo',
    $filaG1b['sifen'] === 'Aprobado',
    "sifen_status=" . json_encode($filaG1b['sifen']), $failures, $checks);

// ── (G2) el vigente vino RECHAZADO: se refleja y NO se reintenta ──────────
// Reintentar un rechazo idéntico repite el rechazo y crea otra fila del lado
// del motor. El estado correcto es `issued` + `sifen_status='Rechazado'`, que
// es desde donde el comercio corrige la metadata fiscal y REEMITE (reissue()).

const TXN_G2 = '01a08b38-aa32-70dd-b061-eba07a390501';

$provG2 = new FakeFePyProvider();
$provG2->failWithoutCdc = true;
$provG2->txnId = TXN_G2;
$resG2 = emitir($provG2, $companyId, $txG2);
$cdcG2 = $provG2->cdcFor((array) $resG2['payload']);

$provG2b = new FakeFePyProvider();
$provG2b->vigente = [
    'txnId'  => TXN_G2,
    'cdc'    => $cdcG2,
    'estado' => 'rechazado',
    'numero' => (string) ($resG2['payload']['numero'] ?? ''),
    'sifen'  => ['codigoRespuesta' => '1002', 'mensaje' => 'Documento electrónico duplicado'],
];
redrenar($provG2b, $companyId, $txG2);
$filaG2 = filaDoc($companyId, $txG2);

check('(G2a) un rechazo conocido NO se reintenta',
    $provG2b->issued === [],
    'emisiones=' . count($provG2b->issued), $failures, $checks);
check('(G2b) el rechazo se refleja: issued + sifen_status Rechazado',
    $filaG2['status'] === 'issued' && $filaG2['sifen'] === 'Rechazado',
    "status={$filaG2['status']} sifen={$filaG2['sifen']} error={$filaG2['error']}", $failures, $checks);
// HALLAZGO abierto, encontrado al escribir esta sección y NO resuelto acá:
// `printableDocumentFor()` decide qué se imprime mirando
// `status`/`superseded_by`/`numbering_mismatch`/`cdc`, pero NO `sifen_status`
// — así que un documento RECHAZADO por SIFEN sigue imprimiendo su CDC y su QR.
// Es exactamente el mismo hueco que `context/28` §F7 ya anota para
// `kudeAvailable` en el portal del cliente, o sea que no es un descuido
// aislado sino el predicado "documento válido" al que le falta el veredicto
// fiscal en los dos lados.
//
// No se corrige en este slice a propósito: qué ve el cliente final cuando
// SIFEN rechaza es una decisión de producto ya tomada por el owner en §F7
// (R1: al cliente NO se le avisa del rechazo), y cambiar de callado lo que
// sale impreso tocaría comprobantes de ventas YA cobradas. El check fija el
// comportamiento de HOY para que, el día que se decida cambiarlo, el arnés
// avise en vez de quedarse mudo.
check('(G2c) [hallazgo abierto] hoy un rechazado SIGUE siendo imprimible — ver context/28 §F7',
    (new EInvoiceService($provG2b))->printableDocumentFor($companyId, $txG2) !== null,
    'cambió el comportamiento: printableDocumentFor ya filtra por sifen_status — actualizar este check y el hallazgo de context/28',
    $failures, $checks);

// ── (G3) sin txnId: el fallback por número lleva SIEMPRE el tipo ──────────
// Cuando se pierde la respuesta HTTP entera no hay txnId que consultar y lo
// único que identifica al documento es su serie. El `tipoDocumento` no es
// opcional: sin él la búsqueda cruza una factura con una nota de crédito del
// mismo número, que pueden coexistir vigentes.

$provG3 = new FakeFePyProvider();
$provG3->failWithoutCdc = true;
$provG3->txnId = null; // la respuesta se perdió: nunca hubo txnId
$resG3 = emitir($provG3, $companyId, $txG3);
$cdcG3 = $provG3->cdcFor((array) $resG3['payload']);
$filaG3a = filaDoc($companyId, $txG3);

$provG3b = new FakeFePyProvider();
$provG3b->vigente = [
    'txnId'  => '01a08b38-aa32-70dd-b061-eba07a390502',
    'cdc'    => $cdcG3,
    'estado' => 'aprobado',
    'numero' => (string) ($resG3['payload']['numero'] ?? ''),
];
redrenar($provG3b, $companyId, $txG3);
$filaG3 = filaDoc($companyId, $txG3);

[$establecimientoG3, $puntoG3] = explode('-', PREFIJO_CAJA);

check('(G3a) sin txnId la fila no inventa ninguno',
    $filaG3a['txnId'] === '', "provider_txn_id=" . json_encode($filaG3a['txnId']), $failures, $checks);
check('(G3b) se consulta por número, con tipo/establecimiento/punto completos',
    ($provG3b->lookups[0]['by'] ?? '') === 'numero'
        && ($provG3b->lookups[0]['tipoDocumento'] ?? 0) === 1
        && ($provG3b->lookups[0]['establecimiento'] ?? '') === $establecimientoG3
        && ($provG3b->lookups[0]['punto'] ?? '') === $puntoG3
        && ($provG3b->lookups[0]['numero'] ?? '') === (string) ($resG3['payload']['numero'] ?? ''),
    'lookups=' . json_encode($provG3b->lookups), $failures, $checks);
check('(G3c) tampoco acá se reemite, y el documento se adopta',
    $provG3b->issued === [] && $filaG3['status'] === 'issued' && $filaG3['cdc'] === $cdcG3,
    "emisiones=" . count($provG3b->issued) . " status={$filaG3['status']} cdc={$filaG3['cdc']}", $failures, $checks);

// ── Limpieza: la cuenta de FE es del arnés, no del fixture ─────────────────
$db->Execute('DELETE FROM einvoice_account WHERE companyid = ? AND username = ?', [$companyId, MARCA_DEL_ARNES]);
EInvoiceProviderFactory::forget($companyId);

harnessFinish($failures, $checks);
