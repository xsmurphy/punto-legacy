<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de los CAMPOS que las acciones del agente IA le pasan a su Service.
 *
 * ── Qué regresión cubre ──────────────────────────────────────────────────────
 *
 * Las acciones de escritura del agente (`/v1/ai/execute`) arman el payload a
 * mano y, cada vez que se sumó un campo al Service sin sumarlo acá, el dato se
 * perdió EN SILENCIO: la acción devolvía "creado" y el registro quedaba a
 * medias. Ya pasó tres veces —la dirección del contacto, el SKU del ítem
 * (escrito como `sku` y ruteado al JSONB, invisible)— y esta tanda cierra
 * cuatro más. Ninguna fallaba con un error: todas contestaban que sí.
 *
 * El arnés fija las cuatro, cada una por lo que hace INUTILIZABLE al registro:
 *
 *   (1) PIN de la caja (`lockPass`). Un empleado creado por el agente no podía
 *       desbloquear el POS — o sea que el alta no servía justo para lo que se
 *       pide en un onboarding. Se verifica contra la MISMA búsqueda que hace
 *       `/v1/unlock-pin`, no contra la columna: que el hash exista no prueba
 *       que abra la caja.
 *   (2) Numeración inicial de la caja. Es fiscal: el número desde el que emite
 *       sale del timbrado que autorizó la SET, y una caja que arranca en 1
 *       cuando su timbrado autoriza desde 2336 emite comprobantes con
 *       numeración inválida. También se fija el comportamiento del VACÍO
 *       (arranca en 1), que es el del panel y no un invento nuestro.
 *   (3) Impuesto del ítem (`taxId`). Un ítem sin impuesto NO queda con "el que
 *       corresponda por default": se vende EXENTO (`enrichWithTaxes` cae en
 *       rate=0/kind=exempt). En un sistema multi-tasa eso es una factura sin
 *       IVA que nadie nota hasta el Libro de Ventas.
 *   (4) Documento tributario y personal del contacto (`tin`/`ci`). Sin ellos no
 *       se le puede facturar: el receptor de un documento electrónico se
 *       identifica por su RUC o su cédula.
 *
 * Y de paso el resolver de impuestos por nombre, que es donde vive el riesgo
 * nuevo: matchear "IVA 10%" contra un catálogo que tiene una tasa llamada "0".
 *
 * Uso: necesita Postgres migrado + el seed de verify_chain.
 *   bash api/tests/run_agent_action_fields_test.sh
 */

// `TODAY` la definen `api/data.php` y los Services para sellar las fechas. En
// CLI no hay request — mismo patrón que contact_address_test.php.
if (!defined('TODAY')) define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Ai/CatalogResolver.php';
require_once dirname(__DIR__) . '/lib/services/RegisterAdminService.php';

use Punto\Api\Ai\CatalogResolver;
use Punto\Api\Contacts\ContactRepository;
use Punto\Api\Contacts\ContactService;
use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Items\ItemRepository;
use Punto\Api\Items\ItemService;
use Punto\Api\Outlets\OutletsService;
use Punto\Api\Services\RegisterAdminService;
use Punto\Api\Users\UsersService;

$failures = 0;
$checks   = 0;

function check(string $label, mixed $got, mixed $want, int &$failures, int &$checks): void
{
    $checks++;
    if ($got === $want) {
        printf("  OK    %-56s = %s\n", $label, var_export($got, true));
        return;
    }
    $failures++;
    printf("  FALLA %-56s esperado %s, obtenido %s\n", $label, var_export($want, true), var_export($got, true));
}

/** Verifica que una llamada lance, y que el mensaje nombre el dato en cuestión. */
function checkThrows(string $label, callable $fn, string $needle, int &$failures, int &$checks): void
{
    $checks++;
    try {
        $fn();
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) {
            printf("  OK    %-56s -> \"%s\"\n", $label, $e->getMessage());
            return;
        }
        $failures++;
        printf("  FALLA %-56s esperaba un error con \"%s\", obtenido \"%s\"\n", $label, $needle, $e->getMessage());
        return;
    }
    $failures++;
    printf("  FALLA %-56s esperaba que lanzara con \"%s\", no lanzó\n", $label, $needle);
}

global $db;

$companyId = '0ea6c5d8-57e5-4226-8140-ec914deec024'; // tenant "Verify PY" del seed
$outletId  = '1a282724-6073-49c3-8bc3-0114a132e349'; // su única sucursal
$outletNombre = 'Verify PY - Sucursal';

if (!ncmExecute('SELECT companyId FROM company WHERE companyId = ? LIMIT 1', [$companyId])) {
    fwrite(STDERR, "Falta el tenant del seed de verify_chain — corré el runner, no el .php suelto.\n");
    harnessFinish(1, $checks);
}

// Sucursal EXTRA. Sin una segunda, `ItemOutletService::defaultFor()` devuelve la
// única del tenant y el arnés no podría distinguir "el agente eligió la
// sucursal" de "el default acertó por casualidad" — que es exactamente el bug:
// con varias sucursales, defaultFor devuelve UNA SOLA (la primera por nombre).
// Se inserta con su depósito default para no romper el invariante de la cadena
// (`outlet_chain_invariant_test.php`), y se borra al final.
$outletExtraId = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
$outletExtraNombre = 'ARNES-CAMPOS Sucursal Dos';
$depositoExtraId   = 'aaaaaaaa-2222-4222-8222-aaaaaaaaaaaa';

/** Deja el tenant como estaba: el arnés es re-ejecutable. */
function limpiarRastros(string $companyId, string $outletExtraId, string $depositoExtraId, $db): void
{
    // Ítems del arnés (y su vínculo a sucursales).
    $rs = $db->Execute(
        "SELECT itemId FROM item WHERE companyId = ? AND itemName LIKE 'ARNES-CAMPOS%'",
        [$companyId]
    );
    while ($rs && !$rs->EOF) {
        $iid = (string) ($rs->fields['itemid'] ?? $rs->fields['itemId'] ?? '');
        $db->Execute('DELETE FROM item_outlet WHERE itemid = ?', [$iid]);
        $db->Execute('DELETE FROM item WHERE itemId = ?', [$iid]);
        $rs->MoveNext();
    }
    // Cajas del arnés y sus secuencias.
    $rs = $db->Execute(
        "SELECT registerId FROM register WHERE companyId = ? AND registerName LIKE 'ARNES-CAMPOS%'",
        [$companyId]
    );
    while ($rs && !$rs->EOF) {
        $rid = (string) ($rs->fields['registerid'] ?? $rs->fields['registerId'] ?? '');
        $db->Execute('DELETE FROM document_sequence WHERE scopeid = ?', [$rid]);
        $db->Execute('DELETE FROM register WHERE registerId = ?', [$rid]);
        $rs->MoveNext();
    }
    // Contactos y usuarios del arnés.
    $rs = $db->Execute(
        "SELECT contactId FROM contact WHERE companyId = ? AND contactName LIKE 'ARNES-CAMPOS%'",
        [$companyId]
    );
    while ($rs && !$rs->EOF) {
        $cid = (string) ($rs->fields['contactid'] ?? $rs->fields['contactId'] ?? '');
        $db->Execute('DELETE FROM contact_outlet WHERE contactid = ?', [$cid]);
        $db->Execute('DELETE FROM customerAddress WHERE customerId = ?', [$cid]);
        $db->Execute('DELETE FROM contact WHERE contactId = ?', [$cid]);
        $rs->MoveNext();
    }
    // Sucursales que el arnés crea por `OutletsService::create()` (la parte de
    // update_outlet): vienen con id generado, así que se buscan por nombre y se
    // van con toda su cadena — depósito y caja, que `create()` encadena.
    $rs = $db->Execute(
        "SELECT outletId FROM outlet WHERE companyId = ? AND outletName LIKE 'ARNES-CAMPOS%'",
        [$companyId]
    );
    $sucursalesDelArnes = [];
    while ($rs && !$rs->EOF) {
        $sucursalesDelArnes[] = (string) ($rs->fields['outletid'] ?? $rs->fields['outletId'] ?? '');
        $rs->MoveNext();
    }
    foreach ($sucursalesDelArnes as $oid) {
        if ($oid === '') { continue; }
        // Las secuencias van ANTES que las cajas: su scopeId es el registerId.
        $db->Execute('DELETE FROM document_sequence WHERE scopeid IN (SELECT registerId FROM register WHERE outletId = ?)', [$oid]);
        $db->Execute('DELETE FROM register WHERE outletId = ?', [$oid]);
        $db->Execute('DELETE FROM taxonomy WHERE outletId = ?', [$oid]);
        $db->Execute('DELETE FROM item_outlet WHERE outletid = ?', [$oid]);
        $db->Execute('DELETE FROM inventory WHERE outletId = ?', [$oid]);
        $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$oid]);
    }

    // Sucursal extra (y la homónima que el bloque multi-tenant crea en el
    // segundo tenant del seed, por si una corrida anterior abortó antes de
    // borrarla — si sobrevive, el INSERT de la próxima corrida choca).
    $db->Execute('DELETE FROM outlet WHERE outletId = ?', ['bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb']);
    $db->Execute('DELETE FROM item_outlet WHERE outletid = ?', [$outletExtraId]);
    $db->Execute('DELETE FROM taxonomy WHERE taxonomyId = ?', [$depositoExtraId]);
    $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$outletExtraId]);
}

limpiarRastros($companyId, $outletExtraId, $depositoExtraId, $db);

$db->Execute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
    [$outletExtraId, $outletExtraNombre, $companyId]
);
$db->Execute(
    "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName, taxonomyExtra)
     VALUES (?, ?, 'location', ?, 'ARNES-CAMPOS Depósito', '{\"isDefault\": true}')",
    [$depositoExtraId, $companyId, $outletExtraId]
);

// =============================================================================
// PARTE 1 — El resolver de impuestos por nombre (CatalogResolver)
// =============================================================================

echo "=== CatalogResolver::taxByName() — del nombre que dice la persona al taxId ===\n\n";

// El catálogo del seed: '10', '5', '0' (kind=rate), 'Exenta' (kind=exempt) y
// '21'. Ordenado por sortOrder NULLS LAST, name → el PRIMERO es '0'.
$tax10 = CatalogResolver::taxByName('10', $companyId, $db);
check('nombre exacto del catálogo',            $tax10['name'] ?? null, '10', $failures, $checks);

// El caso que obliga a NO matchear por substring: el catálogo tiene una tasa
// llamada "0" y "IVA 10%" la contiene como carácter. Un `str_contains` habría
// devuelto el 0% y el ítem saldría facturado sin IVA.
$taxHablado = CatalogResolver::taxByName('IVA 10%', $companyId, $db);
check('"IVA 10%" resuelve por TASA, no por texto', $taxHablado['id'] ?? null, $tax10['id'] ?? null, $failures, $checks);
check('"IVA 10%" NO cae en la tasa "0"',           $taxHablado['name'] ?? null, '10', $failures, $checks);

// La exenta se pide por nombre: tiene rate 0 igual que la tasa real del 0%, así
// que la segunda pasada (por tasa) la excluye a propósito.
$taxExenta = CatalogResolver::taxByName('Exenta', $companyId, $db);
check('"Exenta" resuelve por nombre',          $taxExenta['name'] ?? null, 'Exenta', $failures, $checks);
$tax0 = CatalogResolver::taxByName('0%', $companyId, $db);
check('"0%" resuelve la tasa real, no la exenta', $tax0['name'] ?? null, '0', $failures, $checks);

check('mayúsculas no importan',                CatalogResolver::taxByName('exenta', $companyId, $db)['name'] ?? null, 'Exenta', $failures, $checks);

// Vacío = default del panel (primer impuesto del comercio), NUNCA "sin
// impuesto": un ítem sin taxId se vende exento y nadie se entera.
check('vacío aplica el PRIMERO del catálogo',  CatalogResolver::taxByName('', $companyId, $db)['name'] ?? null, '0', $failures, $checks);
check('null aplica el PRIMERO del catálogo',   CatalogResolver::taxByName(null, $companyId, $db)['name'] ?? null, '0', $failures, $checks);

// Un impuesto que no existe no se aproxima: se rechaza listando el catálogo
// real, que es de donde sale la repregunta del bot.
checkThrows(
    'impuesto inexistente lista los disponibles',
    fn () => CatalogResolver::taxByName('IVA 99%', $companyId, $db),
    'Impuestos disponibles',
    $failures,
    $checks
);

echo "\n=== CatalogResolver::outletIdsByName() — nombres de sucursal a ids ===\n\n";

check(
    'resuelve por nombre',
    CatalogResolver::outletIdsByName([$outletNombre], $companyId),
    [$outletId],
    $failures,
    $checks
);
check(
    'varias sucursales, sin duplicar',
    CatalogResolver::outletIdsByName([$outletNombre, $outletExtraNombre, $outletNombre], $companyId),
    [$outletId, $outletExtraId],
    $failures,
    $checks
);
checkThrows(
    'sucursal inexistente se rechaza',
    fn () => CatalogResolver::outletIdsByName(['Sucursal Que No Existe'], $companyId),
    'no existe en el comercio',
    $failures,
    $checks
);

// ── Aislamiento multi-tenant de los dos resolvers ───────────────────────────
// Es la superficie de riesgo real de resolver POR NOMBRE: los nombres se
// repiten entre comercios y el id que sale de acá va derecho a una escritura.
// Se ejercita contra el segundo tenant del seed ("Verify MX"), que tiene su
// propia sucursal y su propio catálogo de impuestos.
$companyB = 'fa8cf679-9003-417e-8726-5b772d3b6e88';

// Sucursal del OTRO comercio con el MISMO nombre que la del tenant A: el caso
// que un WHERE sin companyId resolvería al id equivocado.
$outletBHomonimoId = 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb';
$db->Execute('DELETE FROM outlet WHERE outletId = ?', [$outletBHomonimoId]);
$db->Execute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
    [$outletBHomonimoId, $outletExtraNombre, $companyB]
);

check(
    'nombre homónimo resuelve la sucursal PROPIA',
    CatalogResolver::outletIdsByName([$outletExtraNombre], $companyId),
    [$outletExtraId],
    $failures,
    $checks
);
check(
    'y desde el otro comercio, la suya',
    CatalogResolver::outletIdsByName([$outletExtraNombre], $companyB),
    [$outletBHomonimoId],
    $failures,
    $checks
);
checkThrows(
    'la sucursal de otro comercio es invisible',
    fn () => CatalogResolver::outletIdsByName(['Verify MX - Sucursal'], $companyId),
    'no existe en el comercio',
    $failures,
    $checks
);

// Impuestos: el tenant B tiene una tasa del 16% que el A no tiene. Ni por
// nombre ni por tasa puede aparecer del otro lado.
checkThrows(
    'el impuesto de otro comercio no se resuelve por nombre',
    fn () => CatalogResolver::taxByName('16', $companyId, $db),
    'no existe en el comercio',
    $failures,
    $checks
);
checkThrows(
    'ni por tasa ("IVA 16%")',
    fn () => CatalogResolver::taxByName('IVA 16%', $companyId, $db),
    'no existe en el comercio',
    $failures,
    $checks
);

// "Exenta" existe en LOS DOS: cada uno tiene que resolver la suya.
$exentaA = CatalogResolver::taxByName('Exenta', $companyId, $db);
$exentaB = CatalogResolver::taxByName('Exenta', $companyB, $db);
check('impuesto homónimo: cada comercio el suyo', $exentaA['id'] !== $exentaB['id'], true, $failures, $checks);
check('la exenta del tenant A es la del seed A',  $exentaA['id'], 'e16ad2ce-db03-48e3-81d1-653df1c1ab11', $failures, $checks);
check('la exenta del tenant B es la del seed B',  $exentaB['id'], 'eb7e216c-2649-48e1-8bbf-3aba6ad43c69', $failures, $checks);

// El default (catálogo vacío de nombre pedido) también es por tenant.
check('el default del tenant B es SU primer impuesto', CatalogResolver::taxByName('', $companyB, $db)['name'] ?? null, '16', $failures, $checks);

$db->Execute('DELETE FROM outlet WHERE outletId = ?', [$outletBHomonimoId]);

// =============================================================================
// PARTE 2 — El ítem queda con SU impuesto y en SUS sucursales
// =============================================================================

echo "\n=== create_item: impuesto y sucursales del artículo ===\n\n";

$itemSvc = new ItemService(new ItemRepository($db));

/** Replica lo que hace la acción `create_item` de `/v1/ai/execute`. */
function altaDeItemComoElAgente(
    ItemService $svc,
    string $companyId,
    string $nombre,
    ?string $taxName,
    array $outletNames,
    $db,
): string {
    $tax       = CatalogResolver::taxByName($taxName, $companyId, $db);
    $outletIds = CatalogResolver::outletIdsByName($outletNames, $companyId);

    $newId = $svc->createBlank($companyId, 'product', 'producto');
    $patch = ['itemName' => $nombre];
    if ($tax !== null)     $patch['taxId']     = $tax['id'];
    if ($outletIds !== []) $patch['outletIds'] = $outletIds;
    $svc->update((string) $newId, $companyId, $patch);
    return (string) $newId;
}

// ── 2.1 Impuesto nombrado por el usuario ────────────────────────────────────
$idConIva = altaDeItemComoElAgente($itemSvc, $companyId, 'ARNES-CAMPOS Con IVA', 'IVA 10%', [], $db);
$fila = ncmExecute('SELECT taxId FROM item WHERE itemId = ? AND companyId = ?', [$idConIva, $companyId]);
check('el ítem queda con el taxId pedido',     (string) ($fila['taxid'] ?? $fila['taxId'] ?? ''), (string) $tax10['id'], $failures, $checks);

// ── 2.2 Sin impuesto declarado: el default del panel, NO exento ─────────────
// Esta es la mitad que importa del fix: antes el ítem quedaba con taxId NULL y
// `SaleService::enrichWithTaxes()` lo vendía exento sin decir nada.
$idSinDecir = altaDeItemComoElAgente($itemSvc, $companyId, 'ARNES-CAMPOS Sin decir impuesto', null, [], $db);
$fila = ncmExecute('SELECT taxId FROM item WHERE itemId = ? AND companyId = ?', [$idSinDecir, $companyId]);
$taxIdSinDecir = (string) ($fila['taxid'] ?? $fila['taxId'] ?? '');
check('sin impuesto declarado NO queda en NULL', $taxIdSinDecir !== '', true, $failures, $checks);

// Y ese taxId tiene fila en `tax` — que es lo que `enrichWithTaxes()` busca
// para no caer en el fallback exento.
$tieneFila = ncmExecute('SELECT taxId FROM tax WHERE taxId = ? AND companyId = ?', [$taxIdSinDecir, $companyId]);
check('el impuesto aplicado existe en el catálogo', $tieneFila !== false && $tieneFila !== null, true, $failures, $checks);

// ── 2.3 Sucursales: el ítem existe donde el usuario dijo ────────────────────
$idEnDos = altaDeItemComoElAgente(
    $itemSvc, $companyId, 'ARNES-CAMPOS En dos sucursales', '10',
    [$outletNombre, $outletExtraNombre], $db
);
$n = ncmExecute('SELECT COUNT(*) AS n FROM item_outlet WHERE itemid = ? AND companyid = ?', [$idEnDos, $companyId]);
check('queda vinculado a las DOS sucursales',  (int) $n['n'], 2, $failures, $checks);

// Contraste: sin nombrar sucursales, `defaultFor()` elige UNA sola. No es un
// bug del default —es su contrato— pero es la razón por la que el agente
// necesita poder decirlas.
$idPorDefault = altaDeItemComoElAgente($itemSvc, $companyId, 'ARNES-CAMPOS Por default', '10', [], $db);
$n = ncmExecute('SELECT COUNT(*) AS n FROM item_outlet WHERE itemid = ? AND companyid = ?', [$idPorDefault, $companyId]);
check('sin nombrarlas, el default deja UNA',   (int) $n['n'], 1, $failures, $checks);

// =============================================================================
// PARTE 3 — El usuario creado con PIN puede desbloquear la caja
// =============================================================================

echo "\n=== create_user: el PIN de la caja (lockPass) ===\n\n";

$usersSvc = new UsersService();

/**
 * La MISMA búsqueda que hace `/v1/unlock-pin`: sha256 del PIN contra `pinhash`,
 * acotada al comercio, a los empleados (type=0) y a los activos. Se replica en
 * vez de mirar la columna porque "el hash está guardado" no prueba que la caja
 * abra — probar el camino real es el punto del arnés.
 */
function usuarioQueDesbloqueaConPin(string $pin, string $companyId): ?string
{
    $hash = hash('sha256', $pin);
    $rs = ncmExecute(
        'SELECT contactid, pinhash FROM contact
          WHERE companyid = ? AND type = 0 AND contactstatus = 1 AND pinhash IS NOT NULL',
        [$companyId],
        false,
        true
    );
    $found = null;
    while ($rs && !$rs->EOF) {
        if ((string) ($rs->fields['pinhash'] ?? '') === $hash) {
            $found = (string) ($rs->fields['contactid'] ?? '');
            break;
        }
        $rs->MoveNext();
    }
    if ($rs) $rs->Close();
    return $found;
}

$pin = '4821';
$idCajero = $usersSvc->create($companyId, [
    'name'     => 'ARNES-CAMPOS Cajero Con PIN',
    'phone'    => '595981000301',
    'password' => 'temporal-del-arnes',
    'lockPass' => $pin,
]);
check('el PIN abre la caja (mismo camino que unlock-pin)', usuarioQueDesbloqueaConPin($pin, $companyId), $idCajero, $failures, $checks);

// Los tres campos que escribe el service. Ninguno lo escribe la acción del
// agente a mano: el hash es responsabilidad de `UsersService`.
$filaU = ncmExecute('SELECT lockPass, lockpasshash, pinhash FROM contact WHERE contactId = ?', [$idCajero]);
check('lockPass guardado',                     (string) ($filaU['lockpass'] ?? $filaU['lockPass'] ?? ''), $pin, $failures, $checks);
check('lockPassHash es un bcrypt verificable', password_verify($pin, (string) ($filaU['lockpasshash'] ?? '')), true, $failures, $checks);
check('pinhash es el sha256 del PIN',          (string) ($filaU['pinhash'] ?? ''), hash('sha256', $pin), $failures, $checks);

// Sin PIN: el alta funciona (no todo usuario opera una caja), pero esa persona
// NO desbloquea el POS. Es la diferencia que el agente tiene que poder contar.
$idSinPin = $usersSvc->create($companyId, [
    'name'     => 'ARNES-CAMPOS Sin PIN',
    'phone'    => '595981000302',
    'password' => 'temporal-del-arnes',
    'lockPass' => '',
]);
$filaSinPin = ncmExecute('SELECT pinhash FROM contact WHERE contactId = ?', [$idSinPin]);
check('sin PIN el usuario igual se crea',      $idSinPin !== '', true, $failures, $checks);
check('sin PIN no hay pinhash',                $filaSinPin['pinhash'] ?? null, null, $failures, $checks);

// Formato y unicidad las aplica el service — la acción del agente no las
// reimplementa, solo le pasa el dato.
checkThrows(
    'PIN de 3 dígitos rechazado',
    fn () => $usersSvc->create($companyId, [
        'name' => 'ARNES-CAMPOS PIN corto', 'password' => 'x', 'lockPass' => '482',
    ]),
    '4 dígitos',
    $failures,
    $checks
);
checkThrows(
    'PIN repetido en el comercio rechazado',
    fn () => $usersSvc->create($companyId, [
        'name' => 'ARNES-CAMPOS PIN repetido', 'password' => 'x', 'lockPass' => $pin,
    ]),
    'ya está en uso',
    $failures,
    $checks
);

// La regla de formato es UNA sola: `/v1/ai/confirm` referencia esta constante
// para poder repreguntar antes de emitir el confirmToken, en vez de copiar el
// regex y quedarse viejo.
check('el patrón del PIN es público y compartido', (bool) preg_match(UsersService::LOCK_PASS_PATTERN, '0000'), true, $failures, $checks);
check('el patrón rechaza no-dígitos',              (bool) preg_match(UsersService::LOCK_PASS_PATTERN, '48a1'), false, $failures, $checks);

// =============================================================================
// PARTE 4 — La caja arranca en el número que autorizó el timbrado
// =============================================================================

echo "\n=== create_register: numeración inicial (dato fiscal, no default) ===\n\n";

$regSvc = new RegisterAdminService($companyId);

/**
 * Próximo número + formato de la secuencia de facturas de una caja.
 *
 * Sin tipo de retorno declarado a propósito: `ncmExecute` devuelve un
 * `CaseInsensitiveArray` del wrapper de BD, no un `array` de PHP (context/08),
 * y un `?array` acá tira TypeError en vez de fallar una aserción.
 */
function secuenciaDeFacturaDe(string $registerId, string $companyId): mixed
{
    $row = ncmExecute(
        'SELECT nextnumber, rangeto, padwidth, prefix FROM document_sequence
          WHERE companyid = ? AND doctype = ? AND scopetype = ? AND scopeid = ?',
        [$companyId, 'factura', DocumentNumber::SCOPE_REGISTER, $registerId]
    );
    return $row ?: null;
}

// ── 4.1 El timbrado autoriza desde 2336, con los ceros impresos ─────────────
$res = $regSvc->create($outletId, 'ARNES-CAMPOS Caja con rango', [
    'fiscal'    => ['invoiceAuth' => '16000001', 'invoicePrefix' => '001-901'],
    // STRING, no int: los ceros de adelante son los dígitos que el talonario
    // trae impresos y el service los lee como ancho de impresión.
    'numbering' => ['factura' => '00002336'],
    'range'     => ['facturaTo' => '5000'],
]);
$seq = secuenciaDeFacturaDe((string) $res['id'], $companyId);
check('la caja arranca en el número del timbrado', (int) ($seq['nextnumber'] ?? 0), 2336, $failures, $checks);
check('el fin del rango autorizado se guarda',     (int) ($seq['rangeto'] ?? 0),    5000, $failures, $checks);
check('los ceros tipeados fijan el ancho impreso', (int) ($seq['padwidth'] ?? 0),   8,    $failures, $checks);
check('el punto de expedición viaja a la secuencia', (string) ($seq['prefix'] ?? ''), '001-901', $failures, $checks);

// ── 4.2 Sin numeración declarada: arranca en 1 ──────────────────────────────
// Es el comportamiento del PANEL, cuyo form rotula el campo "Desde qué número
// emite esta caja. Vacío arranca en 1". El agente lo copia en vez de inventar
// uno propio, y por eso el campo es opcional y no obligatorio como el timbrado.
$res2 = $regSvc->create($outletId, 'ARNES-CAMPOS Caja sin rango', [
    'fiscal' => ['invoiceAuth' => '16000002', 'invoicePrefix' => '001-902'],
]);
$seq2 = secuenciaDeFacturaDe((string) $res2['id'], $companyId);
check('sin numeración declarada arranca en 1',  (int) ($seq2['nextnumber'] ?? 0), 1, $failures, $checks);
check('sin rango declarado no hay techo',       $seq2['rangeto'] ?? null,        null, $failures, $checks);

// ── 4.3 El rango no puede terminar antes de empezar ─────────────────────────
checkThrows(
    'fin de rango menor que el inicio, rechazado',
    fn () => $regSvc->create($outletId, 'ARNES-CAMPOS Caja rango invertido', [
        'fiscal'    => ['invoiceAuth' => '16000003', 'invoicePrefix' => '001-903'],
        'numbering' => ['factura' => '5000'],
        'range'     => ['facturaTo' => '100'],
    ]),
    'no puede ser menor',
    $failures,
    $checks
);

// =============================================================================
// PARTE 5 — Documento tributario y personal del contacto
// =============================================================================

echo "\n=== create_contact / update_contact: RUC y documento personal ===\n\n";

$contactSvc = new ContactService(new ContactRepository($db));

$idCliente = $contactSvc->create($companyId, [
    'name'  => 'ARNES-CAMPOS Cliente Facturable',
    'type'  => 1,
    'phone' => '595981000401',
    'tin'   => '80012345-6',
    'ci'    => '3456789',
]);
$leido = $contactSvc->getByType($idCliente, 1, $companyId);
check('el identificador tributario se persiste', $leido['tin'] ?? null, '80012345-6', $failures, $checks);
check('el documento personal se persiste',       $leido['ci']  ?? null, '3456789',    $failures, $checks);

// El almacenamiento REAL, no solo el shape: es lo que lee la facturación
// electrónica para identificar al receptor. Cada uno vive en un lado distinto
// —`contactTIN` sigue siendo columna, `contactCI` se demoteó al JSONB `data`
// en la mig 25— y el `data->>` se escribe explícito porque seleccionar la
// columna `data` cruda la aplanaría (Query::flattenJsonb) y taparía el punto
// de la aserción: comprobar dónde quedó guardado cada dato.
$filaC = ncmExecute(
    "SELECT contactTIN, data->>'contactCI' AS ci_jsonb FROM contact WHERE contactId = ? AND companyId = ?",
    [$idCliente, $companyId]
);
check('contactTIN escrito en su columna',        (string) ($filaC['contacttin'] ?? $filaC['contactTIN'] ?? ''), '80012345-6', $failures, $checks);
check('contactCI escrito en el JSONB data',      (string) ($filaC['ci_jsonb'] ?? ''), '3456789',    $failures, $checks);

// El patch de `update_contact` es PARCIAL: corregir el RUC no puede borrar la
// cédula que ya estaba cargada.
$contactSvc->update($idCliente, $companyId, ['tin' => '80099999-1']);
$leido = $contactSvc->getByType($idCliente, 1, $companyId);
check('update corrige el tributario',            $leido['tin'] ?? null, '80099999-1', $failures, $checks);
check('update parcial NO borra el personal',     $leido['ci']  ?? null, '3456789',    $failures, $checks);

// Y al revés: un patch de otra cosa no toca ninguno de los dos.
$contactSvc->update($idCliente, $companyId, ['name' => 'ARNES-CAMPOS Cliente Renombrado']);
$leido = $contactSvc->getByType($idCliente, 1, $companyId);
check('un patch ajeno no toca el tributario',    $leido['tin'] ?? null, '80099999-1', $failures, $checks);
check('un patch ajeno no toca el personal',      $leido['ci']  ?? null, '3456789',    $failures, $checks);

// El documento personal es ÚNICO por tipo de contacto: el service lo bloquea y
// nombra con quién choca, que es el mensaje que el agente le repite al usuario.
checkThrows(
    'documento personal repetido, rechazado',
    fn () => $contactSvc->create($companyId, [
        'name' => 'ARNES-CAMPOS Cliente Duplicado', 'type' => 1, 'ci' => '3456789',
    ]),
    'ARNES-CAMPOS Cliente Renombrado',
    $failures,
    $checks
);

// =============================================================================
// PARTE 5 — update_outlet: cambiar un campo no le cambia nada más a la sucursal
// =============================================================================
//
// La acción nació de un pedido literal del owner: "modifica el nombre de la
// sucursal 'Shopping Mariano' a 'Gastronomía'". Un rename parece inofensivo y
// no lo era: `OutletsService::update()` armaba SIEMPRE las 16 claves leyéndolas
// del payload sin default, así que un patch de una sola clave escribía las
// otras quince en null/0. Entre ellas `itemsTaxIncluded`, que es el régimen
// impositivo de la sucursal (IVA incluido vs. añadido). O sea que renombrar una
// sucursal le cambiaba la fiscalidad al comercio, en silencio.
//
// El mismo bug tenía a `create_outlet` roto desde antes: `create()` se llama a
// sí mismo con `['name', 'status']`, así que TODA sucursal creada por el agente
// nacía en "IVA añadido" pisando el 1 que su propio INSERT acababa de poner.

echo "\n=== update_outlet: el patch parcial NO pisa el resto de la sucursal ===\n\n";

$outletSvc = new OutletsService();

// 5.1 — El alta del agente (`create_outlet`) por su camino real.
$idEditable = (string) $outletSvc->create($companyId, [
    'name'   => 'ARNES-CAMPOS Shopping Mariano',
    'status' => 1,
]);
check('create_outlet devuelve un id', $idEditable !== '', true, $failures, $checks);

$recienCreada = $outletSvc->get($idEditable, $companyId);
check('la sucursal del agente nace con IVA INCLUIDO', $recienCreada['taxIncluded'] ?? null, true, $failures, $checks);
check('la sucursal del agente nace ACTIVA',           (int) ($recienCreada['status'] ?? -1), 1, $failures, $checks);

// 5.2 — Se le cargan datos por el camino del PANEL (payload completo, las 16
// claves), que es como llega hoy toda edición desde /v1/outlets. Ese camino no
// cambia de comportamiento: si están todas las claves, se escriben todas.
$outletSvc->update($idEditable, $companyId, [
    'name'            => 'ARNES-CAMPOS Shopping Mariano',
    'status'          => 1,
    'address'         => 'Av. España 1234',
    'phone'           => '0991742353',
    'email'           => 'shopping@arnes.test',
    'whatsApp'        => '',
    'billingName'     => 'ARNES-CAMPOS SA',
    'ruc'             => '80012345-6',
    'description'     => 'La del shopping',
    'purchaseOrderNo' => null,
    'lat'             => null,
    'lng'             => null,
    'taxId'           => '',
    'ecom'            => false,
    'taxIncluded'     => true,
    'businessHours'   => '',
    'priceListId'     => null,
]);
$antes = $outletSvc->get($idEditable, $companyId);
check('el payload completo del panel escribe la dirección', $antes['address'] ?? null, 'Av. España 1234', $failures, $checks);
check('...y el teléfono queda guardado',                    ($antes['phone'] ?? '') !== '', true, $failures, $checks);

// 5.3 — El rename tal como lo manda la acción: UNA sola clave.
$outletSvc->update($idEditable, $companyId, ['name' => 'ARNES-CAMPOS Gastronomía']);
$despues = $outletSvc->get($idEditable, $companyId);

check('el rename aplica',                            $despues['name'] ?? null, 'ARNES-CAMPOS Gastronomía', $failures, $checks);
// El de abajo es EL check de esta parte: el régimen impositivo de la sucursal
// no es algo que un cambio de nombre pueda tocar.
check('el rename NO cambia el régimen de impuestos', $despues['taxIncluded'] ?? null, true, $failures, $checks);
check('el rename NO desactiva la sucursal',          (int) ($despues['status'] ?? -1), 1, $failures, $checks);
check('el rename NO borra la dirección',             $despues['address'] ?? null, $antes['address'] ?? null, $failures, $checks);
check('el rename NO borra el teléfono',              $despues['phone'] ?? null, $antes['phone'] ?? null, $failures, $checks);
check('el rename NO borra el email',                 $despues['email'] ?? null, $antes['email'] ?? null, $failures, $checks);
check('el rename NO borra la razón social',          $despues['billingName'] ?? null, $antes['billingName'] ?? null, $failures, $checks);
check('el rename NO borra el RUC',                   $despues['ruc'] ?? null, $antes['ruc'] ?? null, $failures, $checks);
check('el rename NO borra la descripción',           $despues['description'] ?? null, $antes['description'] ?? null, $failures, $checks);

// 5.4 — Y al revés: cambiar la dirección no toca el nombre.
$outletSvc->update($idEditable, $companyId, ['address' => 'Mcal. López 500']);
$otraVez = $outletSvc->get($idEditable, $companyId);
check('el patch de dirección aplica',        $otraVez['address'] ?? null, 'Mcal. López 500', $failures, $checks);
check('...y NO toca el nombre',              $otraVez['name'] ?? null, 'ARNES-CAMPOS Gastronomía', $failures, $checks);
check('...ni el régimen de impuestos',       $otraVez['taxIncluded'] ?? null, true, $failures, $checks);

// =============================================================================
// PARTE 6 — Nombre de sucursal AMBIGUO: se repregunta, no se adivina
// =============================================================================
//
// `outlet` no tiene unicidad de nombre por comercio, así que dos sucursales
// pueden llamarse igual (la vieja dada de baja y la nueva, el caso de una
// mudanza). El `LIMIT 1` que tenía el resolver elegía una sin decirlo, y ese id
// va DERECHO a una escritura: el rename le caía a la sucursal equivocada.

echo "\n=== outletIdsByName: el nombre ambiguo se rechaza ===\n\n";

$idHomonimaMisma = 'cccccccc-1111-4111-8111-cccccccccccc';
$db->Execute('DELETE FROM outlet WHERE outletId = ?', [$idHomonimaMisma]);
$db->Execute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
    [$idHomonimaMisma, 'ARNES-CAMPOS Gastronomía', $companyId]
);

checkThrows(
    'dos sucursales del mismo nombre: no elige una',
    fn () => CatalogResolver::outletIdsByName(['ARNES-CAMPOS Gastronomía'], $companyId),
    'más de una sucursal',
    $failures,
    $checks
);
// El mensaje trae los ids: es de donde sale la repregunta del bot, que después
// vuelve con `id` en el payload. Sin ellos el usuario no tiene con qué
// desempatar y la acción queda en un callejón sin salida.
checkThrows(
    'el error nombra el id para desempatar',
    fn () => CatalogResolver::outletIdsByName(['ARNES-CAMPOS Gastronomía'], $companyId),
    $idEditable,
    $failures,
    $checks
);

// La homónima se va acá y no en el cleanup del final: el check que sigue
// necesita que ya no esté.
$db->Execute('DELETE FROM outlet WHERE outletId = ?', [$idHomonimaMisma]);

// Sin la homónima vuelve a resolver sola: el rechazo es por ambigüedad real, no
// una regresión que dejó al resolver sin encontrar nada.
check(
    'sin la homónima, el nombre resuelve de nuevo',
    CatalogResolver::outletIdsByName(['ARNES-CAMPOS Gastronomía'], $companyId),
    [$idEditable],
    $failures,
    $checks
);

// =============================================================================

limpiarRastros($companyId, $outletExtraId, $depositoExtraId, $db);

harnessFinish($failures, $checks);
