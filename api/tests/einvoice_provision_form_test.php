<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del FORMULARIO del alta del emisor — el ESPEJO y el payload.
 *
 * ── Qué regresión cubre ──────────────────────────────────────────────────
 *
 * `einvoice_account.fiscal` es el espejo que hidrata la pantalla de
 * facturación electrónica cuando un alta quedó a medias (`initial=
 * {account.fiscal}`). El provisioning lo reescribe con lo que devuelve
 * `EInvoiceProvisioningService::validateForm()`, que es una WHITELIST: la
 * clave que no nombra, desaparece.
 *
 * Ya pasó (2026-09-08): el formulario empezó a pedir régimen y
 * establecimientos —que el motor propio EXIGE— y `validateForm()` no los
 * nombraba. El borrador crudo se guardaba bien, el upsert siguiente con el
 * fiscal normalizado los borraba, y el comercio volvía a la pantalla con la
 * dirección y los códigos geográficos en blanco. No fallaba nada: guardaba
 * menos, en silencio.
 *
 * ── Por qué NO usa Postgres ──────────────────────────────────────────────
 *
 * Igual que `einvoice_fepy_mapper_test.php`: lo que se verifica son
 * TRANSFORMACIONES PURAS (formulario → fiscal normalizado → payload del
 * motor). No hay base, no hay sesión contra nadie, corre en menos de un
 * segundo — que es lo que hace que se corra de verdad antes de tocar el
 * formulario fiscal.
 *
 * ── Qué cubre ────────────────────────────────────────────────────────────
 *
 *   (A) Round-trip del espejo: lo que el comercio tipea sobrevive a
 *       `validateForm()` + `stripSecrets()`, que es exactamente la
 *       secuencia que se persiste. Y sobrevive DOS veces seguidas: la
 *       reanudación vuelve a mandar el espejo como formulario.
 *   (B) El secreto del CSC NO entra al espejo. Es la otra mitad del mismo
 *       contrato: se conserva todo menos eso.
 *   (C) Normalización: código de establecimiento con padding a 3 dígitos
 *       (la caja declara `1`, SIFEN quiere `001`), códigos geográficos como
 *       entero o null, y sin duplicados por código.
 *   (D) El payload de FE-PY sale de los establecimientos que declaran las
 *       CAJAS, no de los que el formulario mande de más; el email viaja solo
 *       si el comercio lo cargó (su Zod valida formato y un vacío rebotaría
 *       el alta entera); y falta un dato geográfico ⇒ CORTA nombrando el
 *       establecimiento, nunca completa con un default.
 */

$root = dirname(__DIR__);
require_once $root . '/lib/EInvoice/EInvoiceProvider.php';
require_once $root . '/lib/EInvoice/EInvoiceProvisioningService.php';

use Punto\Api\EInvoice\EInvoiceProvisioningService;

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
    echo "  FAIL $label\n";
    echo "       $detail\n";
}

/** `establecimientos()` es privado a propósito — el arnés lo alcanza por reflexión. */
function motorEstablecimientos(array $form, array $fiscal, array $stamps): array
{
    // Sin setAccessible(): desde PHP 8.1 la reflexión ya alcanza lo privado y
    // el método quedó deprecado en 8.5 (ruido de deprecación en cada llamada).
    return (new ReflectionMethod(EInvoiceProvisioningService::class, 'establecimientos'))
        ->invoke(null, $form, $fiscal, $stamps);
}

// ── El formulario tal como lo manda la pantalla ──────────────────────────

$form = [
    'email'        => 'facturacion@comercio.com.py',
    'taxpayerType' => 1,
    'regimeId'     => 8,
    'actividades'  => [['codigo' => 47640, 'nombre' => 'Comercio al por menor']],
    'cscId'        => '0001',
    'cscSecret'    => 'ABCD1234ABCD1234ABCD1234ABCD1234',
    'establecimientos' => [
        [
            // La caja declara "1": el padding a 3 es del normalizador, no de
            // quien tipea.
            'codigo'                  => '1',
            'direccion'               => 'Dr Camacho Dure',
            'numeroCasa'              => '576',
            'departamento'            => '1',
            'departamentoDescripcion' => 'CAPITAL',
            'distrito'                => 1,
            'distritoDescripcion'     => 'ASUNCION (DISTRITO)',
            'ciudad'                  => 1,
            'ciudadDescripcion'       => 'ASUNCION (DISTRITO)',
            'telefono'                => '0994285744',
            'email'                   => 'local@comercio.com.py',
            'denominacion'            => 'MATRIZ',
        ],
        // Duplicado por código: el formulario no lo produce, pero un payload
        // del bot sí puede — y dos filas del mismo establecimiento serían dos
        // domicilios distintos declarados para el mismo local.
        ['codigo' => '001', 'direccion' => 'Otra dirección'],
        // Sin código no se puede atar a ninguna caja: se descarta.
        ['direccion' => 'Sin código'],
    ],
];

$fiscal = EInvoiceProvisioningService::validateForm($form);
$espejo = EInvoiceProvisioningService::stripSecrets($fiscal);

echo "\n=== (A) Round-trip del espejo ===\n";

check(
    'el régimen y el tipo de contribuyente sobreviven a la normalización',
    ($espejo['regimeId'] ?? null) === 8 && ($espejo['taxpayerType'] ?? null) === 1,
    json_encode(['regimeId' => $espejo['regimeId'] ?? null, 'taxpayerType' => $espejo['taxpayerType'] ?? null]),
    $failures,
    $checks
);

check(
    'los establecimientos sobreviven — es el bug que este arnés cierra',
    isset($espejo['establecimientos'])
        && count($espejo['establecimientos']) === 1
        && ($espejo['establecimientos'][0]['direccion'] ?? '') === 'Dr Camacho Dure',
    json_encode($espejo['establecimientos'] ?? null),
    $failures,
    $checks
);

// La reanudación vuelve a mandar el espejo COMO formulario: si la segunda
// pasada perdiera algo, el dato se caería al segundo intento de alta en vez
// del primero — que es peor, porque nadie lo asocia al cambio.
$fiscal2 = EInvoiceProvisioningService::validateForm($espejo);
check(
    'reanudar el alta con el espejo guardado no pierde nada (idempotente)',
    EInvoiceProvisioningService::stripSecrets($fiscal2) === $espejo,
    json_encode(EInvoiceProvisioningService::stripSecrets($fiscal2)),
    $failures,
    $checks
);

echo "\n=== (B) El secreto no entra al espejo ===\n";

check(
    'cscId queda y cscSecret NO — el espejo se guarda en la base',
    ($espejo['cscId'] ?? null) === '0001' && !array_key_exists('cscSecret', $espejo),
    json_encode(array_keys($espejo)),
    $failures,
    $checks
);

echo "\n=== (C) Normalización de los establecimientos ===\n";

$est = $espejo['establecimientos'][0];

check(
    'el código se completa a 3 dígitos (la caja dice "1", SIFEN quiere "001")',
    $est['codigo'] === '001',
    json_encode($est['codigo']),
    $failures,
    $checks
);

check(
    'los códigos geográficos quedan como ENTERO, vengan como string o como número',
    $est['departamento'] === 1 && $est['distrito'] === 1 && $est['ciudad'] === 1,
    json_encode([$est['departamento'], $est['distrito'], $est['ciudad']]),
    $failures,
    $checks
);

$parcial = EInvoiceProvisioningService::normalizeEstablecimientos([
    'establecimientos' => [['codigo' => '002', 'direccion' => 'A medias']],
]);
check(
    'un establecimiento a medias se GUARDA con null en lo que falta — el formulario incompleto no se pierde',
    count($parcial) === 1 && $parcial[0]['departamento'] === null && $parcial[0]['ciudadDescripcion'] === '',
    json_encode($parcial),
    $failures,
    $checks
);

echo "\n=== (D) Payload del motor propio ===\n";

// Dos cajas del MISMO establecimiento: el payload lleva una sola entrada.
$stamps = [
    ['numero' => '18260177', 'establecimiento' => '001', 'puntoExpedicion' => '001', 'fechaInicio' => '2025-08-26'],
    ['numero' => '18260177', 'establecimiento' => '001', 'puntoExpedicion' => '002', 'fechaInicio' => '2025-08-26'],
];

$payload = motorEstablecimientos([], $espejo, $stamps);

check(
    'una entrada por establecimiento DECLARADO POR LAS CAJAS, no por fila del formulario',
    count($payload) === 1 && $payload[0]['codigo'] === '001',
    json_encode($payload),
    $failures,
    $checks
);

check(
    'los códigos geográficos viajan como entero y las descripciones como texto',
    $payload[0]['departamento'] === 1
        && $payload[0]['departamentoDescripcion'] === 'CAPITAL'
        && $payload[0]['ciudad'] === 1,
    json_encode($payload[0]),
    $failures,
    $checks
);

check(
    'el email del local viaja si el comercio lo cargó',
    ($payload[0]['email'] ?? null) === 'local@comercio.com.py',
    json_encode($payload[0]),
    $failures,
    $checks
);

$sinEmail = $espejo;
$sinEmail['establecimientos'][0]['email'] = '';
$payloadSinEmail = motorEstablecimientos([], $sinEmail, $stamps);
check(
    'sin email la clave NO viaja — un string vacío rebota su validación y tira el alta entera',
    !array_key_exists('email', $payloadSinEmail[0]),
    json_encode($payloadSinEmail[0]),
    $failures,
    $checks
);

// Una caja de un establecimiento que el formulario no declaró: tiene que
// CORTAR nombrándolo, nunca completar con un domicilio plausible.
$stampsOtro = [
    ['numero' => '18260177', 'establecimiento' => '002', 'puntoExpedicion' => '001', 'fechaInicio' => '2025-08-26'],
];
$corto = '';
try {
    motorEstablecimientos([], $espejo, $stampsOtro);
} catch (\RuntimeException $e) {
    $corto = $e->getMessage();
}
check(
    'falta el establecimiento de una caja ⇒ corta nombrándolo, sin defaults',
    str_contains($corto, '002') && str_contains($corto, 'valores por defecto'),
    $corto === '' ? 'no cortó' : $corto,
    $failures,
    $checks
);

$incompleto = $espejo;
$incompleto['establecimientos'][0]['ciudad'] = null;
$faltante = '';
try {
    motorEstablecimientos([], $incompleto, $stamps);
} catch (\RuntimeException $e) {
    $faltante = $e->getMessage();
}
check(
    'falta un código geográfico ⇒ corta diciendo CUÁL',
    str_contains($faltante, 'ciudad'),
    $faltante === '' ? 'no cortó' : $faltante,
    $failures,
    $checks
);

echo "\n";
harnessFinish($failures, $checks);
