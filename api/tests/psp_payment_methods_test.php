<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) del refactor de pasarelas de pago (PSP).
 *
 * Qué protege — las cuatro cosas que el refactor no puede romper:
 *
 *   (a) REGRESIÓN BANCARD: `ensureQrMethod()` sigue produciendo exactamente el
 *       mismo medio de pago que antes (nombre "QR", systemKey 'qr', atajo Q,
 *       color indigo, requiresIdentifier=false, al final del sortOrder),
 *       sigue siendo idempotente y sigue ADOPTANDO un "QR" creado a mano.
 *   (b) DOS PASARELAS: dos proveedores activos producen DOS medios de pago
 *       distintos (dos filas de taxonomía, dos systemKeys) y el arqueo los
 *       separa — el rollup diario los deja en buckets distintos.
 *   (c) VENTAS HISTÓRICAS: las ventas viejas con el medio "QR" de siempre
 *       siguen sumando donde sumaban. No se reescribe el pasado: ni las que
 *       guardaron el taxonomyId, ni las MUY viejas que solo guardaron el
 *       nombre.
 *   (d) GRANO DEL ROLLUP: `rollup_payments_day` conserva su PK
 *       (companyid, day, outletid, registerid, method, kind). La separación
 *       por pasarela sale del dato, no de una columna nueva.
 *
 * Más dos guardas estáticas que mantienen enganchadas las dos mitades del
 * refactor (backend PspCatalog ↔ registry del front) y la degradación sin red
 * del POS, que no se puede ejercitar desde PHP.
 *
 * Fixture propio (no depende de verify_chain/seed.sql): tenant, sucursal, caja
 * y usuario con UUIDs fijos de este arnés, creados idempotentemente. Las
 * transacciones se insertan en un día FIJO Y VIEJO (2019-03-07) para que el
 * recompute del rollup no se cruce con lo que otros arneses escriben hoy.
 *
 * Uso (necesita Postgres migrado — ver `run_psp_payment_methods_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/psp_payment_methods_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\PaymentMethods\PaymentMethodService;
use Punto\Api\PaymentMethods\PspCatalog;

$companyId  = 'c0a9f1d2-9f4e-4a55-9d21-1c7b5e6f0a01';
$outletId   = 'c0a9f1d2-9f4e-4a55-9d21-1c7b5e6f0a02';
$registerId = 'c0a9f1d2-9f4e-4a55-9d21-1c7b5e6f0a03';
$userId     = 'c0a9f1d2-9f4e-4a55-9d21-1c7b5e6f0a04';
$day        = '2019-03-07';

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

/** @return array<string,mixed>|null */
function methodByName(array $methods, string $name): ?array
{
    foreach ($methods as $m) {
        if (strcasecmp(trim((string) $m['name']), $name) === 0) return $m;
    }
    return null;
}

/** @return array<string,mixed>|null */
function methodBySystemKey(array $methods, string $systemKey): ?array
{
    foreach ($methods as $m) {
        if (($m['systemKey'] ?? null) === $systemKey) return $m;
    }
    return null;
}

// ── Fixture propio ───────────────────────────────────────────────────────────
$db->Execute(
    "INSERT INTO company (companyId, status, plan, balance, isParent, config)
     VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
     ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
    [$companyId, json_encode([
        'settingName'              => 'PSP Test',
        'settingDecimal'           => 'no',
        'settingThousandSeparator' => 'dot',
        'settingCountry'           => 'PY',
        'settingCurrency'          => 'PYG',
        'settingTimeZone'          => 'America/Asuncion',
        'settingTaxName'           => 'IVA',
        'settingLanguage'          => 'es',
        'settingSocialMedia'       => '{}',
        'settingObj'               => '{}',
    ])]
);
$db->Execute(
    "INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, 'PSP Test - Sucursal', 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
    [$outletId, $companyId]
);
$db->Execute(
    "INSERT INTO register (registerid, registername, registerstatus,
        registerinvoicenumber, registerticketnumber, registerreturnnumber,
        registerschedulenumber, registerpedidonumber, registerquotenumber, outletid, companyid)
     VALUES (?, 'PSP Test - Caja', TRUE, 1, 1, 1, 1, 1, 1, ?, ?)
     ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername",
    [$registerId, $outletId, $companyId]
);
$db->Execute(
    "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId)
     VALUES (?, 'PSP Test Admin', '595991000901', 'psp-test@local.test', 1, 0, 'admin', 1, ?, ?)
     ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName",
    [$userId, $outletId, $companyId]
);

/** Estado limpio: este arnés es la única fuente de medios de pago y ventas de su tenant. */
function resetTenant(string $companyId, string $day): void
{
    global $db;
    $db->Execute(
        "DELETE FROM transaction WHERE companyid = ? AND transactiondate >= ?::date AND transactiondate < (?::date + 1)",
        [$companyId, $day, $day]
    );
    $db->Execute("DELETE FROM rollup_payments_day WHERE companyid = ? AND day = ?::date", [$companyId, $day]);
    $db->Execute("DELETE FROM taxonomy WHERE companyId = ? AND taxonomyType = 'paymentMethod'", [$companyId]);
}

resetTenant($companyId, $day);

$svc = new PaymentMethodService($db);

// ── (a) Regresión Bancard ────────────────────────────────────────────────────
echo "\n=== (a) Bancard se comporta exactamente igual que antes ===\n";

$bancard = PspCatalog::qrProvider('bancard');
check(
    'a0 catálogo: bancard conserva su identidad histórica',
    $bancard !== null
        && $bancard['systemKey'] === 'qr'
        && $bancard['methodName'] === 'QR'
        && $bancard['code'] === 'Q'
        && $bancard['color'] === 'indigo',
    'PspCatalog bancard = ' . json_encode($bancard),
    $failures, $checks
);

$svc->ensureQrMethod($companyId);
$methods = $svc->list($companyId);
$qr = methodByName($methods, 'QR');

check(
    'a1 crea el medio "QR" con el mismo shape de siempre',
    $qr !== null
        && $qr['systemKey'] === 'qr'
        && $qr['code'] === 'Q'
        && $qr['color'] === 'indigo'
        && $qr['requiresIdentifier'] === false
        && $qr['hasChange'] === false,
    'QR = ' . json_encode($qr),
    $failures, $checks
);

// Va al final: sortOrder mayor que el de todos los métodos del seed.
$maxOther = -1;
foreach ($methods as $m) {
    if (($m['systemKey'] ?? null) === 'qr') continue;
    $maxOther = max($maxOther, (int) ($m['sortOrder'] ?? -1));
}
check(
    'a2 el QR queda al final del orden del comercio',
    $qr !== null && (int) $qr['sortOrder'] > $maxOther,
    'sortOrder QR=' . json_encode($qr['sortOrder'] ?? null) . ' vs max otros=' . $maxOther,
    $failures, $checks
);

check(
    'a3 el seed de medios corrió igual (Efectivo presente)',
    methodByName($methods, 'Efectivo') !== null,
    'métodos = ' . json_encode(array_column($methods, 'name')),
    $failures, $checks
);

$svc->ensureQrMethod($companyId);
$svc->ensureQrMethod($companyId);
$afterIdempotent = $svc->list($companyId);
$qrCount = 0;
foreach ($afterIdempotent as $m) {
    if (strcasecmp((string) $m['name'], 'QR') === 0) $qrCount++;
}
check(
    'a4 idempotente: tres llamadas, un solo "QR"',
    $qrCount === 1 && count($afterIdempotent) === count($methods),
    "filas QR=$qrCount, total=" . count($afterIdempotent) . ' (antes ' . count($methods) . ')',
    $failures, $checks
);

// Adopción de un "QR" creado a mano (sin systemKey) — comportamiento original.
resetTenant($companyId, $day);
$svc->ensureSeed($companyId);
$manualId = $svc->create($companyId, ['name' => 'QR', 'code' => 'Z', 'color' => 'rose', 'requiresIdentifier' => true]);
$svc->ensureQrMethod($companyId);
$adopted = $svc->list($companyId);
$adoptedQr = methodByName($adopted, 'QR');
$adoptedCount = 0;
foreach ($adopted as $m) {
    if (strcasecmp((string) $m['name'], 'QR') === 0) $adoptedCount++;
}
check(
    'a5 adopta el "QR" del comercio en vez de crear un segundo',
    $adoptedCount === 1
        && $adoptedQr !== null
        && (string) $adoptedQr['id'] === $manualId
        && $adoptedQr['systemKey'] === 'qr'
        && $adoptedQr['requiresIdentifier'] === false
        // El resto del extra del comercio se preserva.
        && $adoptedQr['code'] === 'Z'
        && $adoptedQr['color'] === 'rose',
    'adoptado = ' . json_encode($adoptedQr) . " (filas QR=$adoptedCount)",
    $failures, $checks
);

// ── (b) Dos pasarelas activas ────────────────────────────────────────────────
echo "\n=== (b) Dos pasarelas producen medios de pago distintos ===\n";

resetTenant($companyId, $day);
$svc->ensureQrMethod($companyId);                                   // Bancard
$svc->ensurePspMethod($companyId, 'upayQr', 'uPay', 'U', 'teal');   // segunda pasarela

$two = $svc->list($companyId);
$mBancard = methodBySystemKey($two, 'qr');
$mUpay    = methodBySystemKey($two, 'upayQr');

check(
    'b1 cada pasarela tiene su propia fila de medio de pago',
    $mBancard !== null && $mUpay !== null && (string) $mBancard['id'] !== (string) $mUpay['id'],
    'bancard=' . json_encode($mBancard) . ' upay=' . json_encode($mUpay),
    $failures, $checks
);
check(
    'b2 la segunda pasarela no pisa el medio de la primera',
    $mBancard !== null && $mBancard['name'] === 'QR' && $mBancard['code'] === 'Q'
        && $mUpay !== null && $mUpay['name'] === 'uPay' && $mUpay['code'] === 'U' && $mUpay['color'] === 'teal',
    'bancard=' . json_encode($mBancard) . ' upay=' . json_encode($mUpay),
    $failures, $checks
);

// Nadie roba un medio ajeno por coincidencia de nombre: una pasarela cuyo
// nombre choca con un medio del sistema falla explícito (el caller lo loguea
// y deja el módulo activo) en vez de reapuntar plata de un medio a otro.
$stealError = null;
try {
    $svc->ensurePspMethod($companyId, 'fakePsp', 'Efectivo', 'X', 'lime');
} catch (\RuntimeException $e) {
    $stealError = $e->getMessage();
}
$afterSteal = $svc->list($companyId);
$cash = methodByName($afterSteal, 'Efectivo');
check(
    'b3 no adopta un medio que ya tiene systemKey de otro flujo (Efectivo)',
    $stealError !== null
        && $cash !== null
        && $cash['systemKey'] === 'cash'
        && methodBySystemKey($afterSteal, 'fakePsp') === null,
    'error=' . json_encode($stealError) . ' Efectivo=' . json_encode($cash),
    $failures, $checks
);

// Resolución del canal (lo que el bootstrap del POS le manda a la caja).
check(
    'b4 canal QR apagado si el módulo está apagado',
    PspCatalog::qrChannelOn($bancard, false, []) === false,
    'qrChannelOn(module=off) devolvió true',
    $failures, $checks
);
check(
    'b5 canal QR con default del catálogo si la key no está escrita',
    PspCatalog::qrChannelOn($bancard, true, []) === true,
    'qrChannelOn(module=on, sin key) no respetó channelDefault=true',
    $failures, $checks
);
check(
    'b6 canal QR apagado explícito manda sobre el default',
    PspCatalog::qrChannelOn($bancard, true, ['qr' => false]) === false,
    'qrChannelOn(module=on, qr=false) devolvió true',
    $failures, $checks
);

// ── (b/c) El arqueo separa por pasarela y no reescribe el pasado ─────────────
echo "\n=== (b+c) Rollup del arqueo: separa por pasarela, respeta lo histórico ===\n";

$bancardMethodId = (string) $mBancard['id'];
$upayMethodId    = (string) $mUpay['id'];

/**
 * Venta contado (type=0) con UN pago. `$paymentType` es lo que el POS
 * persiste en `transactionPaymentType[].type` (el taxonomyId del medio);
 * null = venta MUY vieja que solo guardó el nombre.
 */
function makeSaleWithPayment(
    string $companyId,
    string $outletId,
    string $registerId,
    string $userId,
    string $day,
    ?string $paymentType,
    string $paymentName,
    float $amount
): void {
    global $db;
    $db->AutoExecute('transaction', [
        'transactionTotal'       => $amount,
        'transactionDiscount'    => 0,
        'transactionUnitsSold'   => 1,
        'transactionType'        => 0,
        'transactionComplete'    => true,
        'transactionStatus'      => 1,
        'transactionDate'        => $day . ' 12:00:00',
        'transactionPaymentType' => json_encode([
            array_filter([
                'type'  => $paymentType,
                'name'  => $paymentName,
                'price' => $amount,
                'total' => $amount,
            ], static fn ($v) => $v !== null),
        ]),
        'invoiceNo'              => random_int(1000000, 9999999),
        'timestamp'              => time(),
        'registerId'             => $registerId,
        'userId'                 => $userId,
        'responsibleId'          => $userId,
        'outletId'               => $outletId,
        'companyId'              => $companyId,
    ], 'INSERT');
}

// Histórica con el medio "QR" de siempre (guardó el taxonomyId de Bancard).
makeSaleWithPayment($companyId, $outletId, $registerId, $userId, $day, $bancardMethodId, 'QR', 100.0);
// Nueva, misma pasarela: tiene que sumar en el MISMO bucket que la histórica.
makeSaleWithPayment($companyId, $outletId, $registerId, $userId, $day, $bancardMethodId, 'QR', 50.0);
// Nueva, segunda pasarela: bucket propio.
makeSaleWithPayment($companyId, $outletId, $registerId, $userId, $day, $upayMethodId, 'uPay', 70.0);
// MUY vieja: sin `type`, solo el nombre. Su bucket ('qr' textual) queda como estaba.
makeSaleWithPayment($companyId, $outletId, $registerId, $userId, $day, null, 'QR', 30.0);

$db->Execute("SELECT rollup_recompute_period(?::uuid, 'payments', ?::date)", [$companyId, $day]);

$rs = $db->Execute(
    "SELECT method, kind, amount, cnt FROM rollup_payments_day
      WHERE companyid = ? AND day = ?::date ORDER BY method",
    [$companyId, $day]
);
$buckets = [];
if ($rs !== false) {
    foreach ($rs->GetRows() as $row) {
        $buckets[(string) $row['method']] = [
            'kind'   => (string) $row['kind'],
            'amount' => (float) $row['amount'],
            'cnt'    => (int) $row['cnt'],
        ];
    }
}

check(
    'c1 tres buckets: pasarela 1, pasarela 2 y el medio textual histórico',
    count($buckets) === 3,
    'buckets = ' . json_encode($buckets),
    $failures, $checks
);
check(
    'c2 la pasarela 1 suma histórica + nueva en su bucket (100 + 50)',
    isset($buckets[strtolower($bancardMethodId)])
        && abs($buckets[strtolower($bancardMethodId)]['amount'] - 150.0) < 0.001
        && $buckets[strtolower($bancardMethodId)]['cnt'] === 2
        && $buckets[strtolower($bancardMethodId)]['kind'] === 'contado',
    'bucket bancard = ' . json_encode($buckets[strtolower($bancardMethodId)] ?? null),
    $failures, $checks
);
check(
    'c3 la pasarela 2 va a un bucket separado (70), no se mezcla',
    isset($buckets[strtolower($upayMethodId)])
        && abs($buckets[strtolower($upayMethodId)]['amount'] - 70.0) < 0.001
        && $buckets[strtolower($upayMethodId)]['cnt'] === 1,
    'bucket upay = ' . json_encode($buckets[strtolower($upayMethodId)] ?? null),
    $failures, $checks
);
check(
    'c4 la venta MUY vieja (solo nombre) sigue sumando en su bucket textual (30)',
    isset($buckets['qr'])
        && abs($buckets['qr']['amount'] - 30.0) < 0.001
        && $buckets['qr']['cnt'] === 1,
    'bucket "qr" = ' . json_encode($buckets['qr'] ?? null),
    $failures, $checks
);

// ── (d) Grano del rollup ─────────────────────────────────────────────────────
echo "\n=== (d) El grano de rollup_payments_day no cambió ===\n";

$rs = $db->Execute(
    "SELECT a.attname
       FROM pg_index i
       JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
      WHERE i.indrelid = 'rollup_payments_day'::regclass AND i.indisprimary
      ORDER BY a.attname"
);
$pk = [];
if ($rs !== false) {
    foreach ($rs->GetRows() as $row) $pk[] = (string) $row['attname'];
}
sort($pk);
$expectedPk = ['companyid', 'day', 'kind', 'method', 'outletid', 'registerid'];
check(
    'd1 PK = (companyid, day, outletid, registerid, method, kind) — sin columna nueva',
    $pk === $expectedPk,
    'PK actual = ' . json_encode($pk),
    $failures, $checks
);

// ── Guardas estáticas ────────────────────────────────────────────────────────
echo "\n=== Guardas estáticas (front ↔ backend, degradación sin red) ===\n";

$frontRoot   = dirname(__DIR__, 2) . '/frontend';
$registryTxt = @file_get_contents($frontRoot . '/lib/payments/psp/index.ts') ?: '';
$payDialog   = @file_get_contents($frontRoot . '/components/register/pay-dialog.tsx') ?: '';
$pspDialog   = @file_get_contents($frontRoot . '/components/register/psp-qr-dialog.tsx') ?: '';

$missingAdapters = [];
foreach (PspCatalog::qrProviders() as $provider => $psp) {
    $adapterTxt = @file_get_contents($frontRoot . '/lib/payments/psp/' . $provider . '.ts');
    if ($adapterTxt === false
        || !str_contains($adapterTxt, 'provider: "' . $provider . '"')
        || !str_contains($adapterTxt, 'systemKey: "' . $psp['systemKey'] . '"')
        || !str_contains($registryTxt, './' . $provider)
    ) {
        $missingAdapters[] = $provider;
    }
}
check(
    's1 cada pasarela del catálogo tiene adapter registrado en el front, con el mismo systemKey',
    $missingAdapters === [],
    'sin adapter (o con systemKey distinto): ' . json_encode($missingAdapters),
    $failures, $checks
);

check(
    's2 el POS no saca el botón del QR de la grilla cuando no hay red (posición estable)',
    str_contains($payDialog, 'const pspOffline = !isOnline && isPspQrSystemKey(m.systemKey)')
        && str_contains($payDialog, 'aria-disabled={pspOffline || undefined}')
        // El filtro de la grilla mira el canal del módulo, NUNCA la red: un
        // medio que desaparece de la grilla al caerse el wifi movería los
        // botones de lugar.
        && str_contains($payDialog, '.filter((m) => isPspQrChannelEnabled(m.systemKey, config))'),
    'pay-dialog.tsx no muestra el patrón de apagado en su lugar',
    $failures, $checks
);
check(
    's3 sin red el cobro por QR avisa localmente (no sale a la red a fallar)',
    str_contains($payDialog, 'navigator.onLine === false')
        && str_contains($payDialog, 'Cobrá en efectivo o reintentá')
        && str_contains($pspDialog, 'navigator.onLine === false'),
    'falta el aviso local de "sin conexión" en el camino del cobro por QR',
    $failures, $checks
);
check(
    's4 el efectivo no pasa por el gate de pasarela (la caja sigue cobrando sin red)',
    str_contains($payDialog, 'const pspAdapter = pspQrAdapterForSystemKey(method.systemKey)')
        && str_contains($payDialog, 'if (pspAdapter) {'),
    'el gate offline no está acotado a los medios de pasarela',
    $failures, $checks
);

// ── Limpieza ─────────────────────────────────────────────────────────────────
resetTenant($companyId, $day);

harnessFinish($failures, $checks);
