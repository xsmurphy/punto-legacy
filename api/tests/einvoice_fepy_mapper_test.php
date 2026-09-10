<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del MAPPER de FE-PY (`SaleToFePyMapper`) — el motor propio, mig 206.
 *
 * ── Por qué NO usa Postgres ──────────────────────────────────────────────
 *
 * A diferencia de `einvoice_emitter_numbering_test.php`, que ejercita el
 * drenaje real y por eso necesita base + fixtures + Docker, acá lo que se
 * verifica es una TRADUCCIÓN PURA: `$sale` (el shape que arma
 * `EInvoiceService::buildSaleArrayForMapper()`) → el JSON del motor xmlgen.
 * No toca la base, no abre sesión contra nadie, y corre en menos de un
 * segundo — que es lo que hace que se corra de verdad antes de cada cambio
 * del payload fiscal.
 *
 * Se requieren las clases sueltas en vez de `bootstrap.php` justo por eso: el
 * bootstrap abre conexión a Postgres y este arnés no la necesita.
 *
 * ── Qué cubre ────────────────────────────────────────────────────────────
 *
 *   (A) Receptor, los TRES casos fiscales: contribuyente con RUC, persona
 *       física con documento, e innominado. Es donde más fácil se declara mal
 *       una venta y donde los dos motores difieren más.
 *   (B) El DV del RUC: xmlgen lo exige con guion y Punto lo guarda de las dos
 *       formas.
 *   (C) IVA por línea: la terna `ivaTipo`/`iva`/`ivaProporcion` tiene que ser
 *       coherente o el motor la rechaza, y una línea exenta no se declara
 *       igual que una gravada.
 *   (D) El invariante del total: Σ(cantidad × unitario) tiene que dar el
 *       total de la venta, incluido el caso canónico de 10.000 en 3 unidades.
 *   (E) Condición de la operación: contado con sus entregas y crédito con su
 *       plazo en TEXTO (no una fecha).
 *   (F) La clave de idempotencia viaja, y el securityCode congelado NO se
 *       regenera — es lo que evita que un reintento cambie el CDC.
 *   (G) Los guards que tienen que CORTAR la emisión en vez de declarar mal:
 *       moneda distinta de PYG, innominado por encima del millón, crédito a
 *       cliente sin identificar, y venta sin correlativo congelado.
 */

$root = dirname(__DIR__);
require_once $root . '/lib/EInvoice/EInvoiceProvider.php';
require_once $root . '/lib/EInvoice/Cdc.php';
require_once $root . '/lib/EInvoice/SaleFiscalRules.php';
require_once $root . '/lib/EInvoice/SaleToFePyMapper.php';
require_once $root . '/lib/EInvoice/FePyProvider.php';

use Punto\Api\EInvoice\FePyProvider;
use Punto\Api\EInvoice\SaleToFePyMapper;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "  OK   $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label — $detail\n";
}

/** Venta mínima válida; cada caso la ajusta con array_merge. */
function baseSale(array $overrides = []): array
{
    return array_merge([
        'total'              => 110000.0,
        'currency'           => 'PYG',
        'transactionDate'    => '2026-09-07 10:00:00',
        'operationCondition' => 0,
        'securityCode'       => '000123456',
        'fiscalNumber'       => 42,
        'fiscalAuth'         => '12558946',
        'items'              => [[
            'description' => 'Producto',
            'quantity'    => 1.0,
            'unitPrice'   => 110000.0,
            'total'       => 110000.0,
            'taxRate'     => 10,
            'isService'   => false,
        ]],
        'client'   => ['nature' => 'innominado', 'idType' => 15, 'name' => 'Consumidor final'],
        'payments' => [['methodId' => null, 'methodKey' => 'cash', 'amount' => 110000.0]],
    ], $overrides);
}

const POINT = ['establecimiento' => '001', 'punto' => '002'];
const ISSUED = '2026-09-07T10:00:00';
const DOC_ID = '11111111-2222-3333-4444-555555555555';

function build(array $sale, array $config = []): array
{
    return (new SaleToFePyMapper())->build($sale, POINT, $config, ISSUED, DOC_ID);
}

/** Devuelve el mensaje de la excepción, o null si NO lanzó. */
function buildError(array $sale, array $config = []): ?string
{
    try {
        build($sale, $config);
        return null;
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

echo "\n=== (A) receptor — los tres casos fiscales ===\n";

$innominado = build(baseSale())['cliente'];
check(
    'innominado: contribuyente=false, documentoTipo=5, numero "0", razón social "Sin Nombre"',
    $innominado['contribuyente'] === false
        && $innominado['documentoTipo'] === 5
        && $innominado['documentoNumero'] === '0'
        && $innominado['razonSocial'] === 'Sin Nombre'
        && $innominado['tipoOperacion'] === 2,
    json_encode($innominado, JSON_UNESCAPED_UNICODE),
    $failures,
    $checks
);

$fisica = build(baseSale(['client' => [
    'nature' => 'fisica', 'ci' => '1.234.567', 'idType' => 12, 'name' => 'Juan Perez',
]]))['cliente'];
check(
    'persona física: documentoTipo=1 (cédula), B2C, y el número sin puntos',
    $fisica['contribuyente'] === false
        && $fisica['documentoTipo'] === 1
        && $fisica['documentoNumero'] === '1234567'
        && $fisica['tipoOperacion'] === 2,
    json_encode($fisica, JSON_UNESCAPED_UNICODE),
    $failures,
    $checks
);

$extranjera = build(baseSale(['client' => [
    'nature' => 'fisica', 'ci' => '9876543', 'idType' => 14, 'name' => 'Maria Silva',
]]))['cliente'];
check(
    'cédula extranjera (SET 14) → documentoTipo 3 — se factura sin problema',
    $extranjera['documentoTipo'] === 3,
    json_encode($extranjera, JSON_UNESCAPED_UNICODE),
    $failures,
    $checks
);

$contribuyente = build(baseSale(['client' => [
    'nature' => 'contribuyente', 'ruc' => '80012345-6', 'idType' => 11, 'name' => 'Cliente S.A.',
]]))['cliente'];
check(
    'contribuyente: contribuyente=true, B2B y sin bloque de documento de identidad',
    $contribuyente['contribuyente'] === true
        && $contribuyente['tipoOperacion'] === 1
        && $contribuyente['ruc'] === '80012345-6'
        && !isset($contribuyente['documentoTipo']),
    json_encode($contribuyente, JSON_UNESCAPED_UNICODE),
    $failures,
    $checks
);

echo "\n=== (B) dígito verificador del RUC ===\n";

$sinDv = build(baseSale(['client' => [
    'nature' => 'contribuyente', 'ruc' => '80069563', 'name' => 'Cliente S.A.',
]]))['cliente'];
check(
    'RUC guardado SIN guion → se completa con el DV de la SET (80069563 → -1)',
    $sinDv['ruc'] === '80069563-1',
    'llegó ' . $sinDv['ruc'],
    $failures,
    $checks
);

check(
    'RUC con caracteres no numéricos → corta, no adivina dónde termina el cuerpo',
    str_contains(
        (string) buildError(baseSale(['client' => [
            'nature' => 'contribuyente', 'ruc' => '800A9563', 'name' => 'Cliente S.A.',
        ]])),
        'no son números'
    ),
    'no lanzó el error esperado',
    $failures,
    $checks
);

echo "\n=== (C) IVA por línea ===\n";

$gravado = build(baseSale())['items'][0];
check(
    'gravado 10%: ivaTipo=1, iva=10, ivaProporcion=100',
    $gravado['ivaTipo'] === 1 && $gravado['iva'] === 10 && $gravado['ivaProporcion'] === 100,
    json_encode($gravado),
    $failures,
    $checks
);

$exento = build(baseSale(['items' => [[
    'description' => 'Exento', 'quantity' => 1.0, 'unitPrice' => 110000.0,
    'total' => 110000.0, 'taxRate' => 0, 'isService' => false,
]]]))['items'][0];
check(
    'exento: ivaTipo=3, iva=0 y ivaProporcion=0 (el motor cruza los tres campos)',
    $exento['ivaTipo'] === 3 && $exento['iva'] === 0 && $exento['ivaProporcion'] === 0,
    json_encode($exento),
    $failures,
    $checks
);

$mixta = build(baseSale([
    'total' => 220000.0,
    'items' => [
        ['description' => 'Diez', 'quantity' => 1.0, 'unitPrice' => 110000.0, 'total' => 110000.0, 'taxRate' => 10, 'isService' => false],
        ['description' => 'Cinco', 'quantity' => 1.0, 'unitPrice' => 110000.0, 'total' => 110000.0, 'taxRate' => 5, 'isService' => true],
    ],
    'payments' => [['methodId' => null, 'methodKey' => 'cash', 'amount' => 220000.0]],
]));
check(
    'venta mixta: cada línea declara SU tasa, y tipoTransaccion=3 (mixto)',
    $mixta['items'][0]['iva'] === 10 && $mixta['items'][1]['iva'] === 5 && $mixta['tipoTransaccion'] === 3,
    json_encode([$mixta['items'][0]['iva'], $mixta['items'][1]['iva'], $mixta['tipoTransaccion']]),
    $failures,
    $checks
);

check(
    'tasa no admitida (21%) → corta antes de mandar nada',
    str_contains((string) buildError(baseSale(['items' => [[
        'description' => 'Raro', 'quantity' => 1.0, 'unitPrice' => 110000.0,
        'total' => 110000.0, 'taxRate' => 21, 'isService' => false,
    ]]])), 'taxRate inválido'),
    'no lanzó el error esperado',
    $failures,
    $checks
);

echo "\n=== (D) el invariante del total ===\n";

$partida = build(baseSale([
    'total' => 10000.0,
    'items' => [[
        'description' => 'Tres unidades', 'quantity' => 3.0, 'unitPrice' => 3333.33333333,
        'total' => 10000.0, 'taxRate' => 10, 'isService' => false,
    ]],
    'payments' => [['methodId' => null, 'methodKey' => 'cash', 'amount' => 10000.0]],
]));
$declarado = 0.0;
foreach ($partida['items'] as $line) {
    $declarado += $line['cantidad'] * $line['precioUnitario'];
}
check(
    'el caso canónico (10.000 en 3 unidades) se parte y Σ(cantidad × unitario) da exacto',
    abs($declarado - 10000.0) < 0.0001,
    "declaró $declarado y la venta es 10000",
    $failures,
    $checks
);
check(
    'todos los unitarios son enteros — PYG no admite decimales',
    array_reduce(
        $partida['items'],
        static fn (bool $c, array $l): bool => $c && abs($l['precioUnitario'] - round($l['precioUnitario'])) < 1e-9,
        true
    ),
    json_encode(array_column($partida['items'], 'precioUnitario')),
    $failures,
    $checks
);

echo "\n=== (E) condición de la operación ===\n";

$contado = build(baseSale([
    'payments' => [
        ['methodId' => 'm-cash', 'methodKey' => 'Efectivo', 'amount' => 60000.0],
        ['methodId' => 'm-card', 'methodKey' => 'Tarjeta',  'amount' => 50000.0],
    ],
]), ['paymentMethodMap' => ['m-cash' => 1, 'm-card' => 3], 'defaultPaymentMethodCode' => 1]);
$entregas = $contado['condicion']['entregas'];
$montos = array_map(static fn (array $e): int => (int) $e['monto'], $entregas);
check(
    'contado: tipo=1, una entrega por medio, y la suma cierra contra el total',
    $contado['condicion']['tipo'] === 1 && count($entregas) === 2 && array_sum($montos) === 110000,
    json_encode($entregas, JSON_UNESCAPED_UNICODE),
    $failures,
    $checks
);
$tarjeta = null;
foreach ($entregas as $e) {
    if ($e['tipo'] === 3) {
        $tarjeta = $e;
    }
}
check(
    'la tarjeta lleva infoTarjeta (el motor lo exige) declarada como "Otro" con la descripción real',
    is_array($tarjeta) && ($tarjeta['infoTarjeta']['tipo'] ?? null) === 99
        && ($tarjeta['infoTarjeta']['tipoDescripcion'] ?? '') === 'Tarjeta',
    json_encode($tarjeta, JSON_UNESCAPED_UNICODE),
    $failures,
    $checks
);

$credito = build(baseSale([
    'operationCondition' => 1,
    'client'  => ['nature' => 'contribuyente', 'ruc' => '80012345-6', 'name' => 'Cliente S.A.'],
    'credit'  => ['creditOperationCondition' => 0, 'creditDeadline' => '2026-10-07'],
    'payments' => [],
]));
check(
    'crédito: tipo=2 con bloque credito, plazo en TEXTO ("30 dias") y no una fecha',
    $credito['condicion']['tipo'] === 2
        && ($credito['condicion']['credito']['tipo'] ?? null) === 1
        && ($credito['condicion']['credito']['plazo'] ?? '') === '30 dias',
    json_encode($credito['condicion'], JSON_UNESCAPED_UNICODE),
    $failures,
    $checks
);

echo "\n=== (F) idempotencia y securityCode congelado ===\n";

$doc = build(baseSale());
check(
    'la clave de idempotencia (einvoicedocid) viaja en el payload para que issue() la ponga como header',
    ($doc[FePyProvider::IDEMPOTENCY_PAYLOAD_KEY] ?? null) === DOC_ID,
    json_encode($doc[FePyProvider::IDEMPOTENCY_PAYLOAD_KEY] ?? null),
    $failures,
    $checks
);
check(
    'el securityCode congelado se REUSA tal cual — regenerarlo cambiaría el CDC entre reintentos',
    $doc['codigoSeguridadAleatorio'] === '000123456',
    'llegó ' . $doc['codigoSeguridadAleatorio'],
    $failures,
    $checks
);
check(
    'la fecha declarada es la de la OPERACIÓN, naive y sin sufijo de zona',
    $doc['fecha'] === ISSUED && !str_contains($doc['fecha'], 'Z'),
    $doc['fecha'],
    $failures,
    $checks
);
check(
    'establecimiento y punto van como STRING de 3 dígitos (su Zod rechaza enteros)',
    $doc['establecimiento'] === '001' && $doc['punto'] === '002'
        && is_string($doc['establecimiento']) && is_string($doc['punto']),
    json_encode([$doc['establecimiento'], $doc['punto']]),
    $failures,
    $checks
);
check(
    'el correlativo congelado de la venta viaja con padding a 7 dígitos',
    ($doc['numero'] ?? null) === '0000042',
    json_encode($doc['numero'] ?? null),
    $failures,
    $checks
);
check(
    'el kill-switch `legacyAutoNumbering` ya NO existe: el número viaja igual aunque venga en la config',
    (build(baseSale(), ['legacyAutoNumbering' => true])['numero'] ?? null) === '0000042',
    'numero=' . json_encode(build(baseSale(), ['legacyAutoNumbering' => true])['numero'] ?? null),
    $failures,
    $checks
);

// ── La Idempotency-Key: identidad del documento + huella del body ─────────
//
// La regresión que cierra es un documento fiscal REAL. La nota de crédito nº 2
// (2026-09-10) se emitió con el payload de antes de que existiera la serie
// propia de NC; en el medio se deployó, el payload pasó a llevar `numero`, y
// cada reintento chocó contra el body cacheado del motor —"Idempotency-Key was
// reused with a different request body", 409— hasta agotar los ocho intentos.
// El documento existía del otro lado y del nuestro decía `error`.
//
// Lo que hay que fijar son las dos mitades a la vez: MISMO body ⇒ MISMA key
// (que es la protección que de verdad importa, la del reintento tras un
// timeout), y OTRO body ⇒ OTRA key (que es lo que impide envenenar el
// documento). Que el documento no se emita dos veces cuando el body cambió ya
// no depende de la key: lo garantiza el paso de recuperación (sección (G) de
// einvoice_emitter_numbering_test.php).

$payloadK = build(baseSale());
unset($payloadK[FePyProvider::IDEMPOTENCY_PAYLOAD_KEY]);
$keyK = FePyProvider::idempotencyKey(DOC_ID, $payloadK);

check(
    'MISMO body ⇒ MISMA key: el reintento de un timeout sigue siendo un replay, no una segunda emisión',
    FePyProvider::idempotencyKey(DOC_ID, $payloadK) === $keyK,
    $keyK,
    $failures,
    $checks
);

$payloadK2 = $payloadK;
$payloadK2['numero'] = '0000043'; // exactamente lo que cambió el deploy de la NC
check(
    'OTRO body ⇒ OTRA key: un cambio de payload ya no envenena al documento con 409 para siempre',
    FePyProvider::idempotencyKey(DOC_ID, $payloadK2) !== $keyK,
    'las dos dieron ' . $keyK,
    $failures,
    $checks
);

check(
    'documentos distintos con el mismo body no comparten key',
    FePyProvider::idempotencyKey('99999999-2222-3333-4444-555555555555', $payloadK) !== $keyK,
    'colisión entre documentos: ' . $keyK,
    $failures,
    $checks
);

check(
    'la key mide 36 caracteres — el único largo probado contra el motor, y su máximo no está documentado',
    strlen($keyK) === 36,
    'largo=' . strlen($keyK) . ' key=' . $keyK,
    $failures,
    $checks
);

check(
    'la key conserva el prefijo del einvoicedocid: la fila nuestra sigue siendo greppable en los logs de ellos',
    str_starts_with($keyK, substr(DOC_ID, 0, 24)),
    $keyK,
    $failures,
    $checks
);

echo "\n=== (G) guards que tienen que CORTAR ===\n";

check(
    'moneda distinta de PYG → no se emite',
    str_contains((string) buildError(baseSale(['currency' => 'USD'])), 'solo está implementada para guaraníes'),
    'no lanzó el error esperado',
    $failures,
    $checks
);
check(
    'sin moneda → no se emite (no se asume PYG)',
    str_contains((string) buildError(baseSale(['currency' => ''])), 'no declara moneda'),
    'no lanzó el error esperado',
    $failures,
    $checks
);
check(
    'innominado por encima de Gs. 1.000.000 → no se emite',
    str_contains((string) buildError(baseSale([
        'total' => 1_500_000.0,
        'items' => [['description' => 'Caro', 'quantity' => 1.0, 'unitPrice' => 1_500_000.0, 'total' => 1_500_000.0, 'taxRate' => 10, 'isService' => false]],
        'payments' => [['methodId' => null, 'methodKey' => 'cash', 'amount' => 1_500_000.0]],
    ])), 'consumidor final sin identificar'),
    'no lanzó el error esperado',
    $failures,
    $checks
);
check(
    'crédito a cliente innominado → no se emite',
    str_contains((string) buildError(baseSale([
        'operationCondition' => 1,
        'credit'   => ['creditOperationCondition' => 0, 'creditDeadline' => '2026-10-07'],
        'payments' => [],
    ])), 'no se puede emitir a un cliente innominado'),
    'no lanzó el error esperado',
    $failures,
    $checks
);
check(
    'venta sin correlativo congelado → no se emite (nunca un fallback silencioso al número del proveedor)',
    str_contains((string) buildError(baseSale(['fiscalNumber' => null])), 'número de comprobante propio congelado'),
    'no lanzó el error esperado',
    $failures,
    $checks
);
check(
    'crédito EN CUOTAS → corta en vez de declararlo como plazo simple',
    str_contains((string) buildError(baseSale([
        'operationCondition' => 1,
        'client'  => ['nature' => 'contribuyente', 'ruc' => '80012345-6', 'name' => 'Cliente S.A.'],
        'credit'  => ['creditOperationCondition' => 1, 'feeNumbers' => 3, 'fees' => [1, 2, 3]],
        'payments' => [],
    ])), 'cuotas'),
    'no lanzó el error esperado',
    $failures,
    $checks
);
check(
    'caja sin punto de expedición válido → corta con el mensaje que dice dónde cargarlo',
    (static function (): bool {
        try {
            (new SaleToFePyMapper())->build(baseSale(), ['establecimiento' => '', 'punto' => ''], [], ISSUED, DOC_ID);
            return false;
        } catch (\RuntimeException $e) {
            return str_contains($e->getMessage(), 'Sucursales');
        }
    })(),
    'no lanzó el error esperado',
    $failures,
    $checks
);

echo "\n=== (H) traducción del estado fiscal (FePyProvider::toBulkShape) ===\n";

$aprobado = FePyProvider::toBulkShape([
    'estado' => 'aprobado',
    'sifen'  => ['codigoRespuesta' => '0260', 'mensaje' => 'Autorizado el DE', 'protocoloAutorizacion' => '123'],
]);
check(
    'aprobado → dEstResField "Aprobado" (es lo que dispara la entrega del KuDE)',
    ($aprobado['Items'][0]['SifenResult']['rRetEnviDe']['rProtDeField']['dEstResField'] ?? null) === 'Aprobado',
    json_encode($aprobado),
    $failures,
    $checks
);

$rechazado = FePyProvider::toBulkShape([
    'estado' => 'rechazado',
    'sifen'  => ['codigoRespuesta' => '1002', 'mensaje' => 'Documento duplicado'],
]);
$prot = $rechazado['Items'][0]['SifenResult']['rRetEnviDe']['rProtDeField'];
check(
    'rechazado → "Rechazado" + el motivo en gResProcField, que es de donde sale el texto del panel',
    ($prot['dEstResField'] ?? null) === 'Rechazado'
        && ($prot['gResProcField'][0]['dCodResField'] ?? null) === '1002'
        && ($prot['gResProcField'][0]['dMsgResField'] ?? null) === 'Documento duplicado',
    json_encode($prot),
    $failures,
    $checks
);

$pendiente = FePyProvider::toBulkShape(['estado' => 'pendiente']);
check(
    'pendiente NO llena dEstResField — un veredicto a medias no puede mandarle el KuDE al cliente',
    !isset($pendiente['Items'][0]['SifenResult'])
        && ($pendiente['Items'][0]['StatusString'] ?? null) === 'pendiente'
        && ($pendiente['Items'][0]['Success'] ?? null) === false,
    json_encode($pendiente),
    $failures,
    $checks
);
check(
    'el payload nativo se conserva íntegro para trazabilidad',
    ($rechazado['FePy']['sifen']['mensaje'] ?? null) === 'Documento duplicado',
    json_encode($rechazado['FePy'] ?? null),
    $failures,
    $checks
);

echo "\n";
harnessFinish($failures, $checks);
