<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la DIRECCIÓN de un contacto — el dato que el agente IA decía que no
 * existía.
 *
 * ── Qué regresión cubre ──────────────────────────────────────────────────────
 *
 * Le pidieron al agente "creá 5 contactos con nombre, apellido y direcciones con
 * coordenadas" y contestó que "el sistema de contactos no tiene campos para
 * dirección ni coordenadas". Era falso: `ContactService::create()` crea el
 * contacto Y su dirección default en el mismo paso (`mapToAddress()` mapea
 * address, city, location, lat y lng), y el mapa de clientes de
 * `/reports/customers` vive de esas coordenadas. Lo que no los tenía era la
 * ACCIÓN `create_contact` del agente, que armaba el payload con name/type/
 * phone/email/note y tiraba el resto en silencio.
 *
 * El arnés fija las tres cosas que sostienen el arreglo:
 *
 *   (1) La REGLA DEL PAR de coordenadas (`ContactPayload::coordsError`), que es
 *       lo que `/v1/ai/confirm` corta ANTES de emitir el confirmToken. Sin ese
 *       corte, `mapToAddress()` descarta el par incompleto sin decir nada y el
 *       agente informa "creado con su ubicación" sobre un contacto que nunca va
 *       a aparecer en el mapa.
 *   (2) Que la dirección quede PERSISTIDA y LEGIBLE: escrita en
 *       `customerAddress` como la default del contacto, y devuelta por el shape
 *       público (`presentRow`) que es de donde la lee el panel y el propio
 *       agente vía `get_contacts`.
 *   (3) Que una LONGITUD REAL fuera de Paraguay entre en la columna. Hasta la
 *       mig 185 `customerAddressLng` era DECIMAL(10,8) — tope ±99.99999999 — y
 *       cualquier punto al oeste de -100 (Guadalajara, Denver, la costa oeste
 *       de EEUU) reventaba con "numeric field overflow". No se notaba porque los
 *       tenants cargados están en Paraguay.
 *
 * Uso: necesita Postgres migrado + el seed de verify_chain.
 *   bash api/tests/run_contact_address_test.sh
 */

// `TODAY` la define `api/data.php` dentro de un request autenticado, y
// `ContactService` la usa para sellar contactDate/updated_at. En CLI no hay
// request, así que se define acá — mismo patrón que drawer_cash_count_test.php.
if (!defined('TODAY')) define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Ai/ContactPayload.php';

use Punto\Api\Ai\ContactPayload;
use Punto\Api\Contacts\ContactRepository;
use Punto\Api\Contacts\ContactService;

$failures = 0;
$checks   = 0;

function check(string $label, mixed $got, mixed $want, int &$failures, int &$checks): void
{
    $checks++;
    if ($got === $want) {
        printf("  OK    %-52s = %s\n", $label, var_export($got, true));
        return;
    }
    $failures++;
    printf("  FALLA %-52s esperado %s, obtenido %s\n", $label, var_export($want, true), var_export($got, true));
}

/** Verifica que un mensaje de error exista y nombre el dato que falta. */
function checkErrorMentions(string $label, ?string $got, string $needle, int &$failures, int &$checks): void
{
    $checks++;
    if ($got !== null && str_contains($got, $needle)) {
        printf("  OK    %-52s -> \"%s\"\n", $label, $got);
        return;
    }
    $failures++;
    printf("  FALLA %-52s esperaba un error con \"%s\", obtenido %s\n", $label, $needle, var_export($got, true));
}

// =============================================================================
// PARTE 1 — La regla del par de coordenadas (sin base de datos)
// =============================================================================

echo "=== ContactPayload::coordsError() — las coordenadas van de a par ===\n\n";

// El caso feliz: par completo dentro de rango.
check(
    'par completo (Asunción)',
    ContactPayload::coordsError(['lat' => -25.2867, 'lng' => -57.3333]),
    null,
    $failures,
    $checks
);

// Sin coordenadas es LEGÍTIMO: una dirección de texto sin punto en el mapa se
// guarda igual. La regla es del par, no una obligación de geolocalizar.
check(
    'dirección sin coordenadas es válida',
    ContactPayload::coordsError(['address' => 'Av. España 1234', 'city' => 'Asunción']),
    null,
    $failures,
    $checks
);
check(
    'payload sin ningún campo de dirección',
    ContactPayload::coordsError(['name' => 'Juan Pérez']),
    null,
    $failures,
    $checks
);

// El caso que motivó todo esto: media coordenada. El mensaje tiene que nombrar
// la que FALTA, porque de ahí sale la repregunta del bot al usuario.
checkErrorMentions(
    'lat sin lng → rechaza y nombra lng',
    ContactPayload::coordsError(['lat' => -25.2867]),
    'lng',
    $failures,
    $checks
);
checkErrorMentions(
    'lng sin lat → rechaza y nombra lat',
    ContactPayload::coordsError(['lng' => -57.3333]),
    'lat',
    $failures,
    $checks
);

// `''` es "no mandó nada" (formulario vacío), no "media coordenada".
check(
    "lat='' con lng='' → ambas ausentes, válido",
    ContactPayload::coordsError(['lat' => '', 'lng' => '']),
    null,
    $failures,
    $checks
);
checkErrorMentions(
    "lat='' con lng cargada → sigue siendo par incompleto",
    ContactPayload::coordsError(['lat' => '', 'lng' => -57.3333]),
    'lat',
    $failures,
    $checks
);

// No numérico: el modelo mandó texto donde va un decimal.
checkErrorMentions(
    'lat no numérica',
    ContactPayload::coordsError(['lat' => 'Asunción centro', 'lng' => -57.3333]),
    'números decimales',
    $failures,
    $checks
);

// Fuera de rango: típicamente el modelo invirtió lat con lng.
checkErrorMentions(
    'lat 91 fuera de rango',
    ContactPayload::coordsError(['lat' => 91, 'lng' => 0]),
    'lat fuera de rango',
    $failures,
    $checks
);
checkErrorMentions(
    'lng -181 fuera de rango',
    ContactPayload::coordsError(['lat' => 0, 'lng' => -181]),
    'lng fuera de rango',
    $failures,
    $checks
);
// Los bordes exactos entran: -90/180 son coordenadas válidas.
check(
    'bordes exactos (-90, 180) son válidos',
    ContactPayload::coordsError(['lat' => -90, 'lng' => 180]),
    null,
    $failures,
    $checks
);
// Numérico como STRING: es lo que manda un cliente HTTP que serializa todo a
// texto. La regla valida el VALOR, no el tipo de PHP.
check(
    'coordenadas numéricas en string',
    ContactPayload::coordsError(['lat' => '-25.2867', 'lng' => '-57.3333']),
    null,
    $failures,
    $checks
);

// =============================================================================
// PARTE 2 — La dirección se persiste y se puede volver a leer (integración)
// =============================================================================

echo "\n=== ContactService: la dirección default se crea junto al contacto ===\n\n";

$companyId = '0ea6c5d8-57e5-4226-8140-ec914deec024'; // tenant "Verify PY" del seed
$existsCompany = ncmExecute('SELECT companyId FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
if (!$existsCompany) {
    fwrite(STDERR, "Falta el tenant del seed de verify_chain — corré el runner, no el .php suelto.\n");
    harnessFinish(1, $checks);
}

$service = new ContactService(new ContactRepository($db));

/** Borra los contactos que dejó una corrida anterior (el arnés es re-ejecutable). */
function limpiarContactosDelArnes(string $companyId, $db): void
{
    $ids = [];
    $rs = $db->Execute(
        "SELECT contactId FROM contact WHERE companyId = ? AND contactName LIKE 'ARNES-DIR %'",
        [$companyId]
    );
    while ($rs && !$rs->EOF) {
        $ids[] = (string) $rs->fields['contactid'];
        $rs->MoveNext();
    }
    foreach ($ids as $cid) {
        $db->Execute('DELETE FROM customerAddress WHERE customerId = ?', [$cid]);
        $db->Execute('DELETE FROM contact WHERE contactId = ?', [$cid]);
    }
}

limpiarContactosDelArnes($companyId, $db);

// ── 2.1 Alta con dirección COMPLETA — el caso que el agente decía imposible ──
$idCompleto = $service->create($companyId, [
    'name'     => 'ARNES-DIR Completo',
    'type'     => 1,
    'phone'    => '595981000101',
    'address'  => 'Av. Mariscal López 1234',
    'city'     => 'Asunción',
    'location' => 'Villa Morra',
    'lat'      => -25.28670000,
    'lng'      => -57.57590000,
]);

$leido = $service->getByType($idCompleto, 1, $companyId);
check('contacto creado y legible',              $leido !== null,                    true,                      $failures, $checks);
check('address vuelve en el shape público',     $leido['address'],                  'Av. Mariscal López 1234', $failures, $checks);
check('city vuelve en el shape público',        $leido['city'],                     'Asunción',                $failures, $checks);
check('location vuelve en el shape público',    $leido['location'],                 'Villa Morra',             $failures, $checks);
check('lat vuelve en el shape público',         round((float) $leido['lat'], 5),    -25.2867,                  $failures, $checks);
check('lng vuelve en el shape público',         round((float) $leido['lng'], 5),    -57.5759,                  $failures, $checks);
check('la dirección tiene id propio',           is_string($leido['addressId']) && $leido['addressId'] !== '', true, $failures, $checks);

// La fila REAL en `customerAddress`, no solo el shape: es la que lee el mapa de
// clientes (`Reports\CustomersService`) y la libreta de direcciones del panel.
$fila = ncmExecute(
    'SELECT customerAddressDefault, customerAddressText, customerAddressLat, customerAddressLng
       FROM customerAddress WHERE customerId = ? AND companyId = ?',
    [$idCompleto, $companyId]
);
check('fila persistida en customerAddress',     $fila !== false && $fila !== null,  true,                      $failures, $checks);
check('queda marcada como default',             (bool) $fila['customerAddressDefault'], true,                  $failures, $checks);
check('lat persistida en la columna',           round((float) $fila['customerAddressLat'], 5), -25.2867,       $failures, $checks);

// Y una sola dirección: `syncDefaultAddress` no puede duplicar la default.
$cuantas = ncmExecute(
    'SELECT COUNT(*) AS n FROM customerAddress WHERE customerId = ? AND companyId = ?',
    [$idCompleto, $companyId]
);
check('una sola dirección default',             (int) $cuantas['n'],                1,                         $failures, $checks);

// ── 2.2 Media coordenada: por qué la validación tiene que cortar ANTES ───────
// A nivel service el par incompleto se descarta EN SILENCIO: la dirección de
// texto se guarda y las coordenadas no. Nadie se entera. Es exactamente el
// escenario que `ContactPayload::coordsError()` corta arriba (parte 1) para que
// el agente pueda repreguntar en vez de reportar un éxito a medias.
$idMedia = $service->create($companyId, [
    'name'    => 'ARNES-DIR Media Coordenada',
    'type'    => 1,
    'address' => 'Calle sin punto 999',
    'city'    => 'Luque',
    'lat'     => -25.26670000,
    // lng ausente a propósito
]);
$leidoMedia = $service->getByType($idMedia, 1, $companyId);
check('lat sin lng: la dirección igual se guarda', $leidoMedia['address'],          'Calle sin punto 999',     $failures, $checks);
check('lat sin lng: NO escribe lat',               $leidoMedia['lat'],              null,                      $failures, $checks);
check('lat sin lng: NO escribe lng',               $leidoMedia['lng'],              null,                      $failures, $checks);

// ── 2.2b El CERO es una coordenada, no un "no vino nada" ────────────────────
// `mapToAddress()`/`mapToColumns()` decidían con `!empty()`, y `empty(0)` es
// true: un comercio sobre el meridiano de Greenwich (lng = 0 pasa por Londres y
// por Accra) cargaba su ubicación y las DOS coordenadas se descartaban sin un
// solo mensaje. Es el mismo éxito-a-medias que este arnés cubre para el par
// incompleto, pero disparado por un valor legítimo.
$idCero = $service->create($companyId, [
    'name'    => 'ARNES-DIR Meridiano Cero',
    'type'    => 1,
    'address' => 'Greenwich',
    'lat'     => 51.47780000,
    'lng'     => 0.00000000,
]);
$leidoCero = $service->getByType($idCero, 1, $companyId);
check('lng = 0 se guarda (no se trata como ausente)', round((float) $leidoCero['lng'], 4), 0.0,   $failures, $checks);
check('su latitud sobrevive al par con cero',         round((float) $leidoCero['lat'], 4), 51.4778, $failures, $checks);

// El mismo criterio en el JSONB `contactLatLng`, que es el fallback que lee el
// mapa de clientes cuando no hay fila de dirección. Los dos mappers tienen que
// decidir igual: si discrepan, el contacto queda con punto en un lado y sin
// punto en el otro.
$filaCero = ncmExecute(
    "SELECT data->>'contactLatLng' AS latlng FROM contact WHERE contactId = ? AND companyId = ?",
    [$idCero, $companyId]
);
check('contactLatLng también registra el cero',      $filaCero['latlng'],                 '51.4778,0', $failures, $checks);

// ── 2.3 update(): editar la dirección, sin crear una segunda ────────────────
$service->update($idCompleto, $companyId, [
    'address'  => 'Av. España 555',
    'location' => 'Recoleta',
    'lat'      => -25.29500000,
    'lng'      => -57.58900000,
]);
$editado = $service->getByType($idCompleto, 1, $companyId);
check('update cambia el texto de la dirección', $editado['address'],                'Av. España 555',          $failures, $checks);
check('update cambia el barrio',                $editado['location'],               'Recoleta',                $failures, $checks);
check('update mueve las coordenadas',           round((float) $editado['lat'], 5),  -25.295,                   $failures, $checks);
check('update NO cambia el id de la dirección', $editado['addressId'],              $leido['addressId'],       $failures, $checks);

$cuantasTrasUpdate = ncmExecute(
    'SELECT COUNT(*) AS n FROM customerAddress WHERE customerId = ? AND companyId = ?',
    [$idCompleto, $companyId]
);
check('sigue habiendo una sola dirección',      (int) $cuantasTrasUpdate['n'],      1,                         $failures, $checks);

// La ciudad NO vino en el patch: un patch parcial no puede borrar lo cargado.
check('city ausente del patch se conserva',     $editado['city'],                   'Asunción',                $failures, $checks);

// ── 2.4 Longitud fuera de Paraguay — regresión de la mig 185 ────────────────
// Antes de ampliar la columna a DECIMAL(11,8), este create tiraba
// "numeric field overflow" y se llevaba puesta la creación del contacto.
echo "\n=== Longitud al oeste de -100 (mig 185: DECIMAL(10,8) desbordaba) ===\n\n";

$idOeste = $service->create($companyId, [
    'name'    => 'ARNES-DIR Guadalajara',
    'type'    => 1,
    'address' => 'Av. Chapultepec 100',
    'city'    => 'Guadalajara',
    'lat'     => 20.67670000,
    'lng'     => -103.34750000,
]);
$leidoOeste = $service->getByType($idOeste, 1, $companyId);
check('longitud -103.3475 se persiste entera',  round((float) $leidoOeste['lng'], 4), -103.3475,               $failures, $checks);
check('su latitud queda intacta',               round((float) $leidoOeste['lat'], 4), 20.6767,                 $failures, $checks);

// ── Cleanup ──────────────────────────────────────────────────────────────────
limpiarContactosDelArnes($companyId, $db);

harnessFinish($failures, $checks);
