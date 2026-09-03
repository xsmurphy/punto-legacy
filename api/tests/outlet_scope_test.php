<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) del ALCANCE POR SUCURSAL del realm `api`.
 *
 * ── Lo que fija ─────────────────────────────────────────────────────────────
 * La sucursal de una API key sale del USUARIO dueño de la key, no de la key
 * (decisión del owner 2026-09-02). El alcance se deriva de `contact_outlet`:
 * con filas, esas sucursales y solo esas; sin filas, todas.
 *
 * ── Por qué el total va a mano ──────────────────────────────────────────────
 * El caso caro no es el 403 — es el CONSOLIDADO. Un `IN (...)` mal armado
 * devuelve 200 con un número que parece correcto, y nadie lo audita porque no
 * se rompió nada. Por eso el arnés siembra montos elegidos (100, 200, 30, 1000,
 * 7: todas las sumas parciales son distintas entre sí) y compara contra un total
 * CALCULADO ACÁ, nunca contra otra consulta del mismo código — si la derivación
 * del alcance estuviera mal, las dos consultas estarían mal igual y el test
 * pasaría.
 *
 * ── Casos ───────────────────────────────────────────────────────────────────
 *   (a) Usuario con 2 de 4 sucursales → `get_outlets` devuelve 2 y el reporte
 *       consolidado suma SOLO esas 2 (330, no 1337).
 *   (b) Usuario con CERO filas → global: ve las 4 y suma las 4 (1337).
 *   (c) `outletId` de una sucursal NO asignada → 403. NUNCA una lista vacía: un
 *       vacío se lee como "no hubo ventas ahí" y es una mentira.
 *   (d) `outletId` de una asignada → solo esa (30).
 *   (e) `panel` y `pos-app` no cambian de comportamiento (con y sin el header
 *       `X-Outlet-Id`, que sigue siendo exclusivo de `panel`).
 *   (f) `OUTLET_ID` queda DENTRO del conjunto aunque la key se haya emitido con
 *       otra sucursal congelada — es lo que protege a los lectores que bindean
 *       la constante sin pasar por `Roc::build`.
 *
 * Cada caso corre en un proceso propio: `VIEW_OUTLET_IDS` es una constante y no
 * se puede redefinir. Ver `_outlet_scope_once_cli.php`.
 *
 * Uso (necesita Postgres migrado — ver `run_outlet_scope_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/outlet_scope_test.php
 */

$companyId = 'a5c0be00-1111-4a00-9000-0000000000f1';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  'a5c0be00-1111-4a00-9000-0000000000b1');
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/ApiKeyService.php';

global $db;

$failures = 0;
$checks   = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    $ok = $expected === $actual;
    if (!$ok) {
        $failures++;
        echo "  [FAIL] $label\n";
        echo "         esperado: " . json_encode($expected) . "\n";
        echo "         obtenido: " . json_encode($actual) . "\n";
        return;
    }
    echo "  [ok]   $label\n";
}

/**
 * Igualdad NUMÉRICA para los totales.
 *
 * `check()` compara con `===` a propósito (un `'330'` no debe pasar por 330),
 * pero los totales vuelven del subproceso por JSON y `json_decode` entrega `330`
 * como int y `330.5` como float — comparar el tipo ahí es comparar un detalle
 * del transporte, no el número. Se compara con tolerancia porque
 * `transactionTotal` es NUMERIC y el redondeo binario no es asunto de este test.
 */
function checkTotal(string $label, float $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if (!is_numeric($actual) || abs($expected - (float) $actual) > 0.001) {
        $failures++;
        echo "  [FAIL] $label\n";
        echo "         esperado: $expected\n";
        echo "         obtenido: " . json_encode($actual) . "\n";
        return;
    }
    echo "  [ok]   $label\n";
}

// ── Fixture ──────────────────────────────────────────────────────────────────
//
// Cuatro sucursales, montos elegidos para que ninguna suma parcial se pueda
// confundir con otra:
//
//   O1: 100 + 200 = 300      O2: 30      O3: 1000      O4: 7
//
//   consolidado {O1,O2} = 330      tenant completo = 1337
//
// 330 no es prefijo ni múltiplo de 1337, y ninguna sucursal sola da 330: si el
// `IN` se cayera y quedara un `= O1`, el total sería 300 y el test lo vería.
$outlets = [
    'O1' => 'a5c0be00-1111-4a00-9000-0000000000b1',
    'O2' => 'a5c0be00-1111-4a00-9000-0000000000b2',
    'O3' => 'a5c0be00-1111-4a00-9000-0000000000b3',
    'O4' => 'a5c0be00-1111-4a00-9000-0000000000b4',
];
$userRestricted = 'a5c0be00-1111-4a00-9000-0000000000d1'; // asignado a O1 y O2
$userGlobal     = 'a5c0be00-1111-4a00-9000-0000000000d2'; // cero filas → global
$userOneOutlet  = 'a5c0be00-1111-4a00-9000-0000000000d3'; // asignado SOLO a O1

$TOTAL_SCOPE  = 330.0;   // O1 + O2, a mano
$TOTAL_TENANT = 1337.0;  // las cuatro, a mano
$TOTAL_O2     = 30.0;
$TOTAL_O3     = 1000.0;
$TOTAL_O1     = 300.0;

echo "[outlet_scope_test] sembrando fixture...\n";

$db->Execute(
    "INSERT INTO company (companyId, status, plan, balance, isParent, config)
     VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
     ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config, status = 'active'",
    [$companyId, json_encode([
        'settingName'     => 'Alcance Test',
        'settingCountry'  => 'PY',
        'settingTimeZone' => 'America/Asuncion',
        'settingLanguage' => 'es',
    ])]
);

// Limpieza previa: el arnés es re-ejecutable contra la misma base descartable.
$db->Execute('DELETE FROM transaction WHERE companyId = ?', [$companyId]);
$db->Execute('DELETE FROM auth_session WHERE companyid = ?::uuid', [$companyId]);
$db->Execute('DELETE FROM contact_outlet WHERE companyid = ?::uuid', [$companyId]);
$db->Execute('DELETE FROM device WHERE companyid = ?::uuid', [$companyId]);

foreach ($outlets as $name => $id) {
    $db->Execute(
        "INSERT INTO outlet (outletId, outletName, outletStatus, companyId)
         VALUES (?, ?, 1, ?)
         ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName, outletStatus = 1",
        [$id, "Sucursal $name", $companyId]
    );
}

// Los dos usuarios. `contact.outletId` se deja apuntando a O4 A PROPÓSITO en el
// restringido: es la columna LEGACY, y si alguna derivación la mirara en vez de
// `contact_outlet`, el alcance saldría {O4} y todos los totales cambiarían.
// O sea que este valor es una trampa deliberada, no relleno.
foreach ([
    [$userRestricted, 'Usuario Restringido', $outlets['O4'], '595991000001'],
    [$userGlobal,     'Usuario Global',      $outlets['O4'], '595991000002'],
    [$userOneOutlet,  'Usuario Una Sucursal', $outlets['O4'], '595991000003'],
] as [$uid, $uname, $uoutlet, $uphone]) {
    $db->Execute(
        "INSERT INTO contact (contactId, contactName, contactPhone, contactEmail, contactStatus, type, main, role, outletId, companyId)
         VALUES (?, ?, ?, ?, 1, 0, 'admin', 1, ?, ?)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName, outletId = EXCLUDED.outletId",
        [$uid, $uname, $uphone, strtolower(str_replace(' ', '', $uname)) . '@local.test', $uoutlet, $companyId]
    );
}

// El alcance del restringido: O1 y O2. El global NO recibe filas.
foreach ([$outlets['O1'], $outlets['O2']] as $oid) {
    $db->Execute(
        'INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid)
         ON CONFLICT DO NOTHING',
        [$userRestricted, $oid, $companyId]
    );
}
// El de UNA sola sucursal: es el caso del owner (conectó el MCP y vio una).
$db->Execute(
    'INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid)
     ON CONFLICT DO NOTHING',
    [$userOneOutlet, $outlets['O1'], $companyId]
);

// Las ventas.
$ventas = [
    [$outlets['O1'], 100.0],
    [$outlets['O1'], 200.0],
    [$outlets['O2'],  30.0],
    [$outlets['O3'], 1000.0],
    [$outlets['O4'],   7.0],
];
foreach ($ventas as $i => [$oid, $monto]) {
    $db->Execute(
        "INSERT INTO transaction
            (transactionId, transactionDate, transactionTotal, transactionDiscount,
             transactionType, transactionPaymentType, transactionComplete,
             outletId, companyId, userId, meta)
         VALUES (?::uuid, ?, ?, 0, 0, ?, TRUE, ?, ?, ?, '{}'::jsonb)",
        [
            sprintf('a5c0be00-1111-4a00-9000-00000000e%03d', $i),
            date('Y-m-d H:i:s'),
            $monto,
            json_encode([['type' => 'cash', 'name' => 'Efectivo', 'price' => $monto, 'total' => $monto]]),
            $oid, $companyId, $userRestricted,
        ]
    );
}

// ── Credenciales ─────────────────────────────────────────────────────────────
//
// La key del restringido se emite con `outletId = O4` —la sucursal "activa" de
// quien la creó, que NO está en su alcance—. Es exactamente el estado que había
// en producción, y es lo que hace significativo el caso (f).
$svcKeys = new \Punto\Api\Auth\ApiKeyService();
$keyRestricted = $svcKeys->issue([
    'companyId' => $companyId,
    'userId'    => $userRestricted,
    'outletId'  => $outlets['O4'],
    'roleId'    => '1',
], 'Key restringida')['token'];

$keyGlobal = $svcKeys->issue([
    'companyId' => $companyId,
    'userId'    => $userGlobal,
    'outletId'  => $outlets['O3'],
    'roleId'    => '1',
], 'Key global')['token'];

$keyOneOutlet = $svcKeys->issue([
    'companyId' => $companyId,
    'userId'    => $userOneOutlet,
    'outletId'  => $outlets['O4'],
    'roleId'    => '1',
], 'Key de una sucursal')['token'];

// Sesión de panel (realm `panel`), con su outlet activo en O1.
$tokenPanel = authSessionCreate('panel', [
    'companyId' => $companyId,
    'userId'    => $userRestricted,
    'outletId'  => $outlets['O1'],
    'roleId'    => '1',
]);

// La MISMA persona restringida, pero con la sesión apuntando a O4 —una sucursal
// que NO tiene asignada—. Es el estado real de cualquier usuario al que le
// recortaron las sucursales después de haber elegido otra en el selector: el
// `oid` del token sigue donde quedó. Sostiene el caso (P-f).
$tokenPanelOutOfScope = authSessionCreate('panel', [
    'companyId' => $companyId,
    'userId'    => $userRestricted,
    'outletId'  => $outlets['O4'],
    'roleId'    => '1',
]);

// Y el dueño: cero filas en `contact_outlet`, o sea global. Sostiene el (P-g).
$tokenPanelGlobal = authSessionCreate('panel', [
    'companyId' => $companyId,
    'userId'    => $userGlobal,
    'outletId'  => $outlets['O1'],
    'roleId'    => '1',
]);

// Device pos-app pareado a O3, con su fila `device` (el bootstrap la exige).
$deviceId   = 'a5c0be00-1111-4a00-9000-0000000000c9';
$registerId = 'a5c0be00-1111-4a00-9000-0000000000c1';
$db->Execute(
    "INSERT INTO register (registerid, registername, registerstatus,
        registerinvoicenumber, registerticketnumber, registerreturnnumber,
        registerschedulenumber, registerpedidonumber, registerquotenumber, outletid, companyid)
     VALUES (?, 'Caja O3', TRUE, 1, 1, 1, 1, 1, 1, ?, ?)
     ON CONFLICT (registerid) DO UPDATE SET outletid = EXCLUDED.outletid",
    [$registerId, $outlets['O3'], $companyId]
);
$db->Execute(
    "INSERT INTO device (deviceid, companyid, outletid, registerid, userid, devicename, module, status)
     VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 'Tablet O3', 'pos', 1)
     ON CONFLICT (deviceid) DO UPDATE SET outletid = EXCLUDED.outletid, status = 1",
    [$deviceId, $companyId, $outlets['O3'], $registerId, $userRestricted]
);
$tokenDevice = authSessionCreate('pos-app', [
    'companyId'  => $companyId,
    'userId'     => $userRestricted,
    'deviceId'   => $deviceId,
    'outletId'   => $outlets['O3'],
    'registerId' => $registerId,
    'roleId'     => '1',
    'module'     => 'pos',
]);

/** Corre un caso en un proceso limpio y devuelve lo observado. */
function runCase(string $token, string $realm, string $outletParam = '-', string $viewHeader = '-'): array
{
    // Las MISMAS opciones que `_harness_lib.sh` le pasa al arnés padre
    // (`HARNESS_PHP_OPTS`). Sin la supresión de deprecaciones, el
    // `json_decode(null)` de `data.php` sube a excepción y el subproceso muere
    // antes de imprimir su RESULT — un rojo por el entorno, no por el código.
    $cmd = sprintf(
        'php -d variables_order=EGPCS -d %s %s %s %s %s %s 2>&1',
        escapeshellarg('error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING'),
        escapeshellarg(__DIR__ . '/_outlet_scope_once_cli.php'),
        escapeshellarg($token),
        escapeshellarg($realm),
        escapeshellarg($outletParam),
        escapeshellarg($viewHeader)
    );
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^RESULT:(.*)$/m', $out, $m)) {
        throw new RuntimeException("El subproceso ($realm/$outletParam) no imprimió RESULT. Salida:\n$out");
    }
    return json_decode($m[1], true) ?: [];
}

// ── (a) Usuario con 2 de 4 sucursales ────────────────────────────────────────
echo "\n[a] usuario con 2 de 4 sucursales asignadas, sin outletId\n";
$a = runCase($keyRestricted, 'api');
check('(a) no aborta', false, $a['aborted']);
check('(a) el alcance son las 2 asignadas', [$outlets['O1'], $outlets['O2']], $a['scope']);
check('(a) get_outlets devuelve 2', ['Sucursal O1', 'Sucursal O2'], $a['outletNames']);
checkTotal('(a) el consolidado suma SOLO esas 2 (330, a mano)', $TOTAL_SCOPE, $a['total']);
check('(a) son 3 ventas, no 5', 3, $a['rows']);
check('(a) Roc emite IN con las dos', true, str_contains((string) $a['roc'], "outletId IN ('{$outlets['O1']}', '{$outlets['O2']}')"));
// (f): la key se emitió con O4, que NO está asignada. `OUTLET_ID` tiene que
// haber quedado dentro del conjunto — si no, los lectores que la bindean sin
// pasar por Roc leerían una sucursal ajena al usuario.
check('(f) OUTLET_ID quedó dentro del conjunto, no en el O4 congelado en la key', $outlets['O1'], $a['outletId']);

// ── (g) EL VALOR ÚNICO NO PUEDE SER '' PARA UN USUARIO ACOTADO ───────────────
//
// Este check nació de una fuga REAL que el resto del arnés no veía. Los lectores
// que NO pasan por `Roc::build` bindean un outlet ÚNICO, y varios de ellos
// (`RollupReader::itemSalesRange`, `DashboardService::schedule`, el `dashboard`
// de clientes) tratan `''` como "sin filtro de sucursal" = TODO EL TENANT.
//
// El idiom viejo (`defined('VIEW_OUTLET_ID') ? VIEW_OUTLET_ID : OUTLET_ID`)
// devuelve exactamente `''` para el realm `api`, porque este mismo cambio hace
// que `VIEW_OUTLET_ID` esté siempre definida como `''` para habilitar el
// `IN (...)`. O sea: el fragmento `$roc` salía bien acotado y el valor único
// salía abierto, en la MISMA respuesta. Los totales de `transaction` que mide
// el resto del arnés seguían correctos, así que nada se ponía rojo.
//
// La aserción es la invariante que faltaba: para un usuario ACOTADO, el valor
// único NUNCA puede ser `''`. O es su sucursal, o es `null` (y el endpoint
// corta con 422). Un `''` acá es una fuga.
check('(g) el valor único NO es "" para un usuario de 2 sucursales (sería el tenant entero)', null, $a['single']);

echo "\n[g2] usuario con UNA sola sucursal asignada (el caso del owner)\n";
$g = runCase($keyOneOutlet, 'api');
check('(g2) no aborta', false, $g['aborted']);
check('(g2) el alcance es esa sola', [$outlets['O1']], $g['scope']);
check('(g2) el valor único ES su sucursal, no ""', $outlets['O1'], $g['single']);
checkTotal('(g2) suma solo O1 (300, a mano)', $TOTAL_O1, $g['total']);
check('(g2) get_outlets devuelve 1', ['Sucursal O1'], $g['outletNames']);

// ── (b) Usuario global (cero filas) ──────────────────────────────────────────
echo "\n[b] usuario con CERO filas en contact_outlet\n";
$b = runCase($keyGlobal, 'api');
check('(b) no aborta', false, $b['aborted']);
check('(b) alcance vacío = sin restricción', [], $b['scope']);
check('(b) get_outlets devuelve las 4', ['Sucursal O1', 'Sucursal O2', 'Sucursal O3', 'Sucursal O4'], $b['outletNames']);
checkTotal('(b) suma las 4 (1337, a mano)', $TOTAL_TENANT, $b['total']);
check('(b) Roc NO agrega filtro de outlet', false, str_contains((string) $b['roc'], 'outletId'));

// ── (c) Sucursal NO asignada → 403 ───────────────────────────────────────────
echo "\n[c] outletId de una sucursal NO asignada\n";
$c = runCase($keyRestricted, 'api', $outlets['O3']);
check('(c) aborta', true, $c['aborted']);
check('(c) con 403 y no con una lista vacía', 403, $c['status']);
// Y SIN `reason`: el del panel lo lleva porque hay un `localStorage` que
// limpiar. `?outletId=` es explícito y por llamada — no hay estado que curar, y
// un cliente que reintentara solo estaría ignorando el límite.
check('(c) el 403 del realm api NO lleva reason', null, $c['error']['details']['reason'] ?? null);

// ── (d) Sucursal asignada → solo esa ─────────────────────────────────────────
echo "\n[d] outletId de una sucursal asignada\n";
$d = runCase($keyRestricted, 'api', $outlets['O2']);
check('(d) no aborta', false, $d['aborted']);
// `VIEW_OUTLET_IDS` es el LÍMITE (las asignadas), no la selección: pedir una
// sucursal puntual no lo achica. Quien expresa la selección es `VIEW_OUTLET_ID`,
// y de ahí sale `single()`. Antes las dos cosas vivían en la misma constante y
// eso se llevaba puesto al selector del panel, que necesita listar las DOS
// mientras el usuario está parado en UNA.
check('(d) el LÍMITE sigue siendo el conjunto asignado', [$outlets['O1'], $outlets['O2']], $d['scope']);
check('(d) la SELECCIÓN es la pedida', $outlets['O2'], $d['viewOutletId']);
check('(d) y el valor único también', $outlets['O2'], $d['single']);
checkTotal('(d) suma solo esa (30, a mano)', $TOTAL_O2, $d['total']);
check('(d) OUTLET_ID sigue el parámetro', $outlets['O2'], $d['outletId']);

// Un usuario GLOBAL también puede elegir una sucursal puntual.
echo "\n[d2] usuario global pidiendo una sucursal puntual\n";
$d2 = runCase($keyGlobal, 'api', $outlets['O3']);
check('(d2) no aborta', false, $d2['aborted']);
checkTotal('(d2) suma solo O3 (1000, a mano)', $TOTAL_O3, $d2['total']);

// Y una sucursal de OTRO tenant no pasa por ser global.
echo "\n[d3] usuario global pidiendo una sucursal de otro tenant\n";
$d3 = runCase($keyGlobal, 'api', 'a5c0be00-9999-4a00-9000-0000000000ff');
check('(d3) aborta con 403', 403, $d3['status'] ?? 0);

// ── (P) REALM PANEL — el mismo alcance, ahora en la pantalla ─────────────────
//
// `$tokenPanel` es del usuario RESTRINGIDO (O1+O2), con su sucursal activa en
// O1. Es el caso que describe el owner: "si el usuario está asignado solo en 2,
// el panel muestra 2, y si selecciona TODAS = la suma de esas 2".
//
// Hasta el 2026-09-02 este bloque afirmaba lo contrario —que "Todas" daba 1337,
// el tenant entero— porque eso es lo que hacía: `X-Outlet-Id: all` validaba
// pertenencia al TENANT y nada más. El número no cambió por un refactor: cambió
// porque ESE era el bug (P2 de la auditoría del 2026-08-26).
echo "\n[P] realm panel — usuario con 2 de 4 sucursales\n";

// (P-a) sin header: la sucursal ACTIVA del token, no el consolidado. El default
// del panel NO es "todas": el consolidado se pide con el selector.
$p = runCase($tokenPanel, 'panel');
check('(P-a) sin header: el outlet del token (O1)', $outlets['O1'], $p['outletId']);
check('(P-a) sin header NO define VIEW_OUTLET_ID (default = sucursal activa)', null, $p['viewOutletId']);
check('(P-a) el LÍMITE sí queda definido', [$outlets['O1'], $outlets['O2']], $p['viewOutletIds']);
checkTotal('(P-a) filtra por su sucursal activa (300, a mano)', $TOTAL_O1, $p['total']);

// (P-b) el selector del sidebar lista 2, no 4. `outletNames` sale del mismo
// `OutletsService::listAll()` que alimenta el dropdown del logo.
check('(P-b) el selector lista SOLO las 2 asignadas', ['Sucursal O1', 'Sucursal O2'], $p['outletNames']);

// (P-c) "Todas" = la suma de las SUYAS. El total se compara contra 330, que el
// arnés calculó a mano sumando los montos que sembró — no contra otra consulta
// del mismo código, que taparía el error si el filtro se cayera entero.
$pAll = runCase($tokenPanel, 'panel', '-', 'all');
check('(P-c) "Todas" no aborta', false, $pAll['aborted']);
checkTotal('(P-c) "Todas" suma SOLO esas 2 (330, a mano) y NO el tenant (1337)', $TOTAL_SCOPE, $pAll['total']);
check('(P-c) Roc emite IN con las dos', true, str_contains(
    (string) $pAll['roc'],
    "outletId IN ('{$outlets['O1']}', '{$outlets['O2']}')"
));
// La invariante del (g): con el alcance abierto a 2, el valor único no puede
// ser `''` — eso sería el tenant entero para los lectores que no pasan por Roc.
check('(P-c) el valor único es null, nunca ""', null, $pAll['single']);

// ── (P-c2) EL OTRO CAMINO: los lectores que NO pasan por `Roc::build` ────────
//
// El rollup, el inventario y las cuentas arman su filtro con
// `OutletScope::sqlFilter()` en vez del fragmento de `Roc`. Es el camino que
// antes cortaba con 422 para un subconjunto y ahora agrega, así que estos son
// los checks que cubren código nuevo: que el `IN` de dos uuids interpolados sea
// SQL válido y filtre lo mismo que `Roc`.
//
// Se comparan contra los 330 calculados A MANO, no entre sí nada más: si los dos
// caminos se rompieran igual, "coinciden" no diría nada.
checkTotal('(P-c2) sqlFilter suma lo mismo que Roc (330, a mano)', $TOTAL_SCOPE, $pAll['totalFiltered']);
check('(P-c2) y emite el IN con las dos', " AND outletId IN ('{$outlets['O1']}', '{$outlets['O2']}')", $pAll['sqlFilter']);

// (P-d) una sucursal ASIGNADA: solo esa.
$pOwn = runCase($tokenPanel, 'panel', '-', $outlets['O2']);
check('(P-d) no aborta', false, $pOwn['aborted']);
checkTotal('(P-d) filtra por esa (30, a mano)', $TOTAL_O2, $pOwn['total']);
check('(P-d) el valor único es esa sucursal', $outlets['O2'], $pOwn['single']);
// El view-scope NO mueve la sucursal de las ESCRITURAS: `OUTLET_ID` sigue en la
// activa del token (O1). Contrato de 2026-06-13, y es lo que evita que el
// dropdown del logo sea un cambio de sucursal de facturación encubierto.
check('(P-d) OUTLET_ID NO sigue al selector: sigue en la activa (O1)', $outlets['O1'], $pOwn['outletId']);
// El caso de UNA sucursal por el camino de `sqlFilter`: tiene que emitir `=` y
// no un `IN` de un elemento, y dar el mismo número que `Roc`.
checkTotal('(P-d) sqlFilter con una sucursal suma igual (30, a mano)', $TOTAL_O2, $pOwn['totalFiltered']);
check('(P-d) y emite = y no IN', " AND outletId = '{$outlets['O2']}'", $pOwn['sqlFilter']);

// (P-e) una sucursal NO asignada: 403, no un vacío ni un total ajeno. ANTES
// devolvía los 1000 de O3.
$pForeign = runCase($tokenPanel, 'panel', '-', $outlets['O3']);
check('(P-e) sucursal no asignada aborta', true, $pForeign['aborted']);
check('(P-e) con 403', 403, $pForeign['status'] ?? 0);
// El `reason` NO es decorativo: `X-Outlet-Id` sale de `localStorage` y viaja en
// TODAS las requests del panel, así que un 403 pelado dejaría al usuario sin
// panel Y sin forma de arreglarlo (ni `/v1/bootstrap` ni `/v1/outlets`
// contestarían). Con esto `api-client.ts` borra la preferencia vieja y reintenta
// una vez. Si alguien saca este campo, el panel se brickea en el rollout — y
// eso no lo muestra ningún test de totales.
check(
    '(P-e) y con reason, para que el cliente pueda auto-curarse',
    'outlet_out_of_scope',
    $pForeign['error']['details']['reason'] ?? null
);

// (P-f) el arranque: la sesión tiene su outlet activo en O4, que NO está
// asignada. `activeOutletId` del bootstrap es literalmente `OUTLET_ID`, así que
// tiene que haber repuntado al conjunto — un panel que arranca apuntando a una
// sucursal que el usuario no puede ver da 403 en cada pantalla.
$pOut = runCase($tokenPanelOutOfScope, 'panel');
check('(P-f) no aborta', false, $pOut['aborted']);
check('(P-f) activeOutletId repunta al conjunto (O1), no se queda en O4', $outlets['O1'], $pOut['outletId']);
checkTotal('(P-f) y el total es el de O1 (300, a mano), no el de O4', $TOTAL_O1, $pOut['total']);

// (P-g) usuario GLOBAL en el panel: las 4, sin restricción. El caso del dueño.
echo "\n[P-g] realm panel — usuario GLOBAL (cero filas en contact_outlet)\n";
$pg = runCase($tokenPanelGlobal, 'panel');
check('(P-g) alcance vacío = sin restricción', [], $pg['scope']);
check('(P-g) el selector lista las 4', 4, count((array) $pg['outletNames']));
$pgAll = runCase($tokenPanelGlobal, 'panel', '-', 'all');
checkTotal('(P-g) "Todas" es el tenant entero (1337, a mano)', $TOTAL_TENANT, $pgAll['total']);
check('(P-g) Roc NO agrega filtro de outlet', false, str_contains((string) $pgAll['roc'], 'outletId'));
// Y el otro camino tampoco: sin restricción, `sqlFilter` devuelve el fragmento
// VACÍO. Emitir `IS NULL` o cualquier otra cosa acá le borraría los datos al
// dueño, que es el usuario más común.
check('(P-g) sqlFilter no emite fragmento', '', $pgAll['sqlFilter']);
checkTotal('(P-g) y ve el tenant entero (1337, a mano)', $TOTAL_TENANT, $pgAll['totalFiltered']);
// Un global sí puede pararse en cualquier sucursal DEL TENANT.
$pgOne = runCase($tokenPanelGlobal, 'panel', '-', $outlets['O3']);
checkTotal('(P-g) y puede elegir cualquiera (1000, a mano)', $TOTAL_O3, $pgOne['total']);

// ── (e) el header sigue siendo exclusivo de panel, y pos-app sin cambios ─────
echo "\n[e] pos-app sin cambios, y el header no cruza de realm\n";
// El header sigue siendo EXCLUSIVO de panel: una key `api` que lo mande no debe
// poder saltarse su alcance por esa puerta.
$apiHeader = runCase($keyRestricted, 'api', '-', $outlets['O3']);
checkTotal('(e) X-Outlet-Id NO le sirve al realm api: sigue en su consolidado (330)', $TOTAL_SCOPE, $apiHeader['total']);

$dev = runCase($tokenDevice, 'pos-app');
check('(e) pos-app: el outlet de la fila device (O3)', $outlets['O3'], $dev['outletId']);
check('(e) pos-app NO define VIEW_OUTLET_IDS', null, $dev['viewOutletIds']);
checkTotal('(e) pos-app filtra por su sucursal (1000, a mano)', $TOTAL_O3, $dev['total']);
// El device de O3 lo pareó el usuario RESTRINGIDO, que no tiene O3 asignada. Su
// alcance NO se hereda: la caja es de la sucursal del PAREO, no de las del
// contacto que la pareó. Si `realmIsScoped()` dejara entrar a `pos-app`, esta
// caja se repuntaría sola a O1 y facturaría en otra sucursal.
check('(e) pos-app NO hereda el alcance de quien pareó el device', [], $dev['scope']);

// ── (h) El idiom viejo no vuelve ─────────────────────────────────────────────
//
// Guard ESTÁTICO, no de comportamiento, y por eso vale: la fuga del rollup entró
// porque cinco endpoints se migraron a `OutletScope::single()` y tres se
// quedaron con el idiom escrito a mano, cuyo SIGNIFICADO cambió en el mismo
// commit. Ningún test de datos lo iba a ver — los tres seguían devolviendo 200
// con números plausibles.
//
// `items.php` es la excepción legítima: lee `VIEW_OUTLET_ID` gateado a
// `realm === 'panel'`, donde la constante conserva su significado original.
echo "\n[h] el idiom viejo no reaparece en endpoints\n";
$idiomFiles = [];
$v1Dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/v1'));
foreach ($v1Dir as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    // Las DOS constantes: leer `VIEW_OUTLET_IDS` a mano es la misma clase de
    // error que leer `VIEW_OUTLET_ID`, y desde que la primera existe hay dos
    // formas de reimplementar el desempate en vez de pedírselo al helper.
    if (preg_match("/defined\(['\"]VIEW_OUTLET_IDS?['\"]\)/", $src)
        || preg_match("/constant\(['\"]VIEW_OUTLET_IDS?['\"]\)/", $src)
    ) {
        $rel = str_replace(dirname(__DIR__) . '/', '', $file->getPathname());
        if ($rel !== 'v1/items.php') {
            $idiomFiles[] = $rel;
        }
    }
}
sort($idiomFiles);
check(
    '(h) ningún endpoint lee VIEW_OUTLET_ID/IDS a mano (usar effectiveIds()/single())',
    [],
    $idiomFiles
);

harnessFinish($failures, $checks);
