<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés (DB real) de `Inventory::getAllItemStock($outlet, $all = true)` — la
 * agregación de stock sobre TODAS las sucursales de la compañía.
 *
 * ── Qué estaba roto ─────────────────────────────────────────────────────────
 *
 * La rama `$all` llamaba a `getAllOutletData()` sin argumento esperando un mapa
 * de sucursales. Esa función SIEMPRE devuelve UNA sucursal (cae a `OUTLET_ID`),
 * así que el `foreach` iteraba los CAMPOS de una fila y usaba cada nombre de
 * campo (`id`, `name`, `status`…) como si fuera un outletId en
 * `WHERE outletId = ?`. Contra PG, con `outletId` UUID, eso ni siquiera devolvía
 * vacío: reventaba. La agregación multi-sucursal nunca funcionó.
 *
 * ── Qué prueba ──────────────────────────────────────────────────────────────
 *
 *   (a) Un tenant con 3 sucursales y el mismo ítem en las tres devuelve la SUMA
 *       correcta — comparada contra un SQL de control escrito APARTE, con otra
 *       formulación (`DISTINCT ON` en vez de `array_agg(...)[1]` + JOIN), para
 *       que un error en el SQL de producción no se replique en el control.
 *   (b) Un ítem presente en UNA sola sucursal aparece una vez, con su saldo.
 *   (c) Un tenant de una sola sucursal da EXACTAMENTE lo mismo con `$all` que
 *       con la rama no-`$all`.
 *   (d) No hay fuga entre tenants en ninguna de las dos direcciones.
 *   (e) El resultado NO depende del `OUTLET_ID` del contexto (se corre el mismo
 *       agregado desde cada una de las 3 sucursales y tiene que dar idéntico).
 *   (f) `cogs` es el promedio PONDERADO por unidades, no el de la última
 *       sucursal iterada; y con saldo total 0 cae al promedio simple.
 *   (g) La implementación VIEJA no producía este resultado (documenta que la
 *       regresión era real, no un matiz).
 *
 * Las invocaciones de la función corren en SUBPROCESOS
 * (`_stock_all_outlets_once_cli.php`): `COMPANY_ID`/`OUTLET_ID` son constantes
 * y los casos (d) y (e) exigen variarlas.
 *
 * Fixtures: reusa los dos tenants de `api/lib/Sales/verify_chain/seed.sql`
 * ("Verify PY" y "Verify MX") y agrega LO SUYO por SQL directo — dos sucursales
 * extra para Verify PY y sus propios ítems/movimientos de stock, con UUIDs
 * fijos y `ON CONFLICT` (re-correr el arnés no duplica nada). NO modifica
 * seed.sql: otros arneses dependen de ese archivo.
 *
 * Uso (ver `run_stock_all_outlets_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/stock_all_outlets_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

// ── Tenants del fixture compartido ──────────────────────────────────────────
const COMPANY_A = '0ea6c5d8-57e5-4226-8140-ec914deec024'; // Verify PY
const OUTLET_A1 = '1a282724-6073-49c3-8bc3-0114a132e349'; // sucursal del seed
const COMPANY_B = 'fa8cf679-9003-417e-8726-5b772d3b6e88'; // Verify MX
const OUTLET_B1 = '6d3cab3a-c040-4428-8090-6790469de3bd'; // única sucursal de B

// ── Fixture propio de este arnés ────────────────────────────────────────────
const OUTLET_A2 = 'c1a20001-0000-4000-8000-000000000002';
const OUTLET_A3 = 'c1a20001-0000-4000-8000-000000000003';

const ITEM_MULTI  = 'c1a20002-0000-4000-8000-00000000000a'; // en A1, A2 y A3
const ITEM_SINGLE = 'c1a20002-0000-4000-8000-00000000000b'; // sólo en A2
const ITEM_ZERO   = 'c1a20002-0000-4000-8000-00000000000c'; // saldos que se cancelan
const ITEM_B      = 'c1b20002-0000-4000-8000-00000000000d'; // del otro tenant

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

global $db;

// ═══════════════════════════════════════════════════════════════════════════
// 1. Fixtures
// ═══════════════════════════════════════════════════════════════════════════

echo "=== fixtures ===\n";

$db->Execute(
    "INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES
        (?, 'Stock-all - Sucursal 2', 1, ?),
        (?, 'Stock-all - Sucursal 3', 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
    [OUTLET_A2, COMPANY_A, OUTLET_A3, COMPANY_A]
);

$db->Execute(
    "INSERT INTO item (itemId, itemName, itemSKU, itemPrice, itemType, itemStatus,
                       itemCanSale, itemTrackInventory, companyId, itemKind)
     VALUES (?, 'Stock-all multi sucursal', 'STOCKALL-MULTI', 1000, 'product', 1, TRUE, TRUE, ?, 'producto'),
            (?, 'Stock-all una sucursal',   'STOCKALL-SINGLE', 1000, 'product', 1, TRUE, TRUE, ?, 'producto'),
            (?, 'Stock-all saldo cero',     'STOCKALL-ZERO',   1000, 'product', 1, TRUE, TRUE, ?, 'producto'),
            (?, 'Stock-all tenant B',       'STOCKALL-B',      1000, 'product', 1, TRUE, TRUE, ?, 'producto')
     ON CONFLICT (itemId) DO UPDATE SET itemName = EXCLUDED.itemName",
    [ITEM_MULTI, COMPANY_A, ITEM_SINGLE, COMPANY_A, ITEM_ZERO, COMPANY_A, ITEM_B, COMPANY_B]
);

/**
 * Movimientos de stock del fixture.
 *
 * Cada fila tiene `stockId` fijo (idempotencia) y `stockDate` explícito: lo que
 * se está probando es "la ÚLTIMA fila por (sucursal, ítem)", así que varias
 * combinaciones tienen una fila vieja que NO debe contar y una nueva que sí.
 *
 * [stockId, itemId, outletId, companyId, offset de días, onHand, onHandCOGS]
 */
$stockRows = [
    // ITEM_MULTI en A1: la vieja (100 @ 1000) queda tapada por la nueva (10 @ 1200)
    ['d0000000-0000-4000-8000-000000000001', ITEM_MULTI, OUTLET_A1, COMPANY_A, -5, 100, 1000],
    ['d0000000-0000-4000-8000-000000000002', ITEM_MULTI, OUTLET_A1, COMPANY_A, -1,  10, 1200],
    // ITEM_MULTI en A2 y A3
    ['d0000000-0000-4000-8000-000000000003', ITEM_MULTI, OUTLET_A2, COMPANY_A, -3,   5,  800],
    ['d0000000-0000-4000-8000-000000000004', ITEM_MULTI, OUTLET_A3, COMPANY_A, -2,   7, 1000],
    // ITEM_SINGLE sólo en A2 (con una fila vieja tapada)
    ['d0000000-0000-4000-8000-000000000005', ITEM_SINGLE, OUTLET_A2, COMPANY_A, -4, 99,  400],
    ['d0000000-0000-4000-8000-000000000006', ITEM_SINGLE, OUTLET_A2, COMPANY_A, -1,  3,  500],
    // ITEM_ZERO: +5 en A1 y -5 en A3 → suma 0 → cogs cae al promedio simple
    ['d0000000-0000-4000-8000-000000000007', ITEM_ZERO, OUTLET_A1, COMPANY_A, -2,   5,  600],
    ['d0000000-0000-4000-8000-000000000008', ITEM_ZERO, OUTLET_A3, COMPANY_A, -2,  -5,  400],
    // Tenant B, sucursal única
    ['d0000000-0000-4000-8000-000000000009', ITEM_B, OUTLET_B1, COMPANY_B, -2,     9,  700],
];

foreach ($stockRows as [$stockId, $itemId, $outletId, $companyId, $dayOffset, $onHand, $cogs]) {
    $db->Execute(
        "INSERT INTO stock (stockId, stockDate, stockSource, stockCount, stockCOGS,
                            stockOnHand, stockOnHandCOGS, itemId, outletId, companyId)
         VALUES (?, now() + (? || ' days')::interval, 'harness', ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (stockId) DO UPDATE SET
            stockDate       = EXCLUDED.stockDate,
            stockOnHand     = EXCLUDED.stockOnHand,
            stockOnHandCOGS = EXCLUDED.stockOnHandCOGS",
        [$stockId, (string) $dayOffset, $onHand, $cogs, $onHand, $cogs, $itemId, $outletId, $companyId]
    );
}

echo "fixtures cargados: 2 sucursales extra en Verify PY, 4 ítems, " . count($stockRows) . " movimientos.\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// 2. Helpers
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Corre la función bajo prueba en un subproceso con el contexto pedido.
 * Devuelve el JSON decodificado (`ok` + `rows`, o `ok:false` + `error`).
 */
function runOnce(string $companyId, string $outletId, string $mode): array
{
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -d variables_order=EGPCS'
        . ' ' . escapeshellarg(__DIR__ . '/_stock_all_outlets_once_cli.php')
        . ' ' . escapeshellarg($companyId)
        . ' ' . escapeshellarg($outletId)
        . ' ' . escapeshellarg($mode)
        . ' 2>/dev/null';

    $out     = [];
    $rc      = 0;
    $lastOut = exec($cmd, $out, $rc);

    $json = json_decode((string) $lastOut, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => "salida no-JSON (rc=$rc): " . substr(implode("\n", $out), -400)];
    }
    return $json;
}

/**
 * SQL de CONTROL — misma pregunta, otra formulación.
 *
 * La de producción usa `array_agg(stockId ORDER BY …)[1]` + JOIN de vuelta
 * contra `stock`; ésta usa `DISTINCT ON (outletId, itemId)`. Si las dos
 * coinciden, el resultado no depende del truco de agregación elegido. El fence
 * de tenant también está escrito distinto a propósito (subquery `IN` sobre
 * `outlet` en vez de JOIN).
 *
 * @return array<string,array{onHand:string,cogs:string|null}>
 */
function controlAggregate(string $companyId): array
{
    global $db;

    $rs = $db->Execute(
        "SELECT itemid,
                SUM(onhand) AS onhand,
                (CASE WHEN SUM(onhand) <> 0
                      THEN SUM(onhand * COALESCE(cogs, 0)) / SUM(onhand)
                      ELSE AVG(cogs) END)::numeric(15,2) AS cogs
         FROM (
             SELECT DISTINCT ON (s.outletid, s.itemid)
                    s.itemid, s.stockonhand AS onhand, s.stockonhandcogs AS cogs
             FROM stock s
             WHERE s.outletid IN (SELECT o.outletid FROM outlet o WHERE o.companyid = ?)
             ORDER BY s.outletid, s.itemid, s.stockdate DESC, s.stockid DESC
         ) ultimas
         GROUP BY itemid",
        [$companyId]
    );

    $out = [];
    while (!$rs->EOF) {
        $out[(string) $rs->fields['itemid']] = [
            'onHand' => (string) $rs->fields['onhand'],
            'cogs'   => $rs->fields['cogs'] === null ? null : (string) $rs->fields['cogs'],
        ];
        $rs->MoveNext();
    }
    $rs->Close();
    ksort($out);
    return $out;
}

/** Compara dos montos decimales por valor, no por string ("22.000" == "22"). */
function sameAmount(mixed $a, mixed $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs((float) $a - (float) $b) < 1e-9;
}

/** Compara dos mapas `itemId => {onHand, cogs}` por valor. Devuelve '' si son iguales. */
function diffMaps(array $expected, array $actual): string
{
    $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
    sort($keys);

    $diffs = [];
    foreach ($keys as $k) {
        $e = $expected[$k] ?? null;
        $a = $actual[$k] ?? null;
        if ($e === null || $a === null) {
            $diffs[] = "$k: " . ($e === null ? 'sólo en el actual' : 'falta en el actual');
            continue;
        }
        if (!sameAmount($e['onHand'], $a['onHand'])) {
            $diffs[] = "$k.onHand esperado={$e['onHand']} actual={$a['onHand']}";
        }
        if (!sameAmount($e['cogs'], $a['cogs'])) {
            $diffs[] = "$k.cogs esperado=" . var_export($e['cogs'], true) . ' actual=' . var_export($a['cogs'], true);
        }
    }
    return implode('; ', $diffs);
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. Casos
// ═══════════════════════════════════════════════════════════════════════════

echo "=== agregación multi-sucursal (tenant Verify PY, 3 sucursales) ===\n";

$allA = runOnce(COMPANY_A, OUTLET_A1, 'all');
check(
    'la rama $all corre sin explotar',
    $allA['ok'] === true,
    'error: ' . ($allA['error'] ?? '(sin detalle)'),
    $failures,
    $checks
);

if ($allA['ok'] !== true) {
    // Sin el resultado base no hay nada que comparar: cortar acá es más honesto
    // que encadenar 10 FAIL derivados del mismo problema.
    harnessFinish($failures, $checks);
}

$rowsA = $allA['rows'];

// (a) contra el SQL de control, sobre TODOS los ítems del tenant (no sólo los del fixture)
$control = controlAggregate(COMPANY_A);
check(
    '(a) el agregado coincide con el SQL de control (DISTINCT ON), ítem por ítem',
    diffMaps($control, $rowsA) === '',
    diffMaps($control, $rowsA),
    $failures,
    $checks
);

// (a bis) y los números son los esperados a mano: 10 (A1) + 5 (A2) + 7 (A3) = 22
check(
    '(a) ITEM_MULTI suma las 3 sucursales: 10 + 5 + 7 = 22',
    isset($rowsA[ITEM_MULTI]) && sameAmount($rowsA[ITEM_MULTI]['onHand'], 22),
    'onHand=' . var_export($rowsA[ITEM_MULTI]['onHand'] ?? null, true) . ' (esperado 22)',
    $failures,
    $checks
);

check(
    '(a) ITEM_MULTI ignora la fila VIEJA de A1 (100 @ 1000)',
    isset($rowsA[ITEM_MULTI]) && !sameAmount($rowsA[ITEM_MULTI]['onHand'], 112),
    'onHand=' . var_export($rowsA[ITEM_MULTI]['onHand'] ?? null, true) . ' — sumó la fila tapada',
    $failures,
    $checks
);

// (f) cogs ponderado: (10*1200 + 5*800 + 7*1000) / 22 = 23000/22 = 1045.4545… → 1045.45
check(
    '(f) ITEM_MULTI.cogs es el promedio PONDERADO (1045.45), no el de la última sucursal',
    isset($rowsA[ITEM_MULTI]) && sameAmount($rowsA[ITEM_MULTI]['cogs'], 1045.45),
    'cogs=' . var_export($rowsA[ITEM_MULTI]['cogs'] ?? null, true) . ' (esperado 1045.45; 800/1000/1200 serían "la última sucursal")',
    $failures,
    $checks
);

// (f bis) saldo total 0 → promedio simple (600 + 400) / 2 = 500
check(
    '(f) ITEM_ZERO (saldos que se cancelan) → onHand 0 y cogs 500.00 por promedio simple',
    isset($rowsA[ITEM_ZERO])
        && sameAmount($rowsA[ITEM_ZERO]['onHand'], 0)
        && sameAmount($rowsA[ITEM_ZERO]['cogs'], 500),
    'onHand=' . var_export($rowsA[ITEM_ZERO]['onHand'] ?? null, true)
        . ' cogs=' . var_export($rowsA[ITEM_ZERO]['cogs'] ?? null, true),
    $failures,
    $checks
);

// (b) ítem en una sola sucursal
check(
    '(b) ITEM_SINGLE (sólo en A2) aparece una vez con su saldo vigente (3 @ 500)',
    isset($rowsA[ITEM_SINGLE])
        && sameAmount($rowsA[ITEM_SINGLE]['onHand'], 3)
        && sameAmount($rowsA[ITEM_SINGLE]['cogs'], 500),
    'onHand=' . var_export($rowsA[ITEM_SINGLE]['onHand'] ?? null, true)
        . ' cogs=' . var_export($rowsA[ITEM_SINGLE]['cogs'] ?? null, true),
    $failures,
    $checks
);

echo "\n=== (e) independencia del OUTLET_ID del contexto ===\n";

$fromA2 = runOnce(COMPANY_A, OUTLET_A2, 'all');
$fromA3 = runOnce(COMPANY_A, OUTLET_A3, 'all');

check(
    '(e) el agregado desde A2 es idéntico al de A1',
    ($fromA2['ok'] ?? false) === true && diffMaps($rowsA, $fromA2['rows']) === '',
    ($fromA2['ok'] ?? false) === true ? diffMaps($rowsA, $fromA2['rows']) : ('error: ' . ($fromA2['error'] ?? '')),
    $failures,
    $checks
);

check(
    '(e) el agregado desde A3 es idéntico al de A1',
    ($fromA3['ok'] ?? false) === true && diffMaps($rowsA, $fromA3['rows']) === '',
    ($fromA3['ok'] ?? false) === true ? diffMaps($rowsA, $fromA3['rows']) : ('error: ' . ($fromA3['error'] ?? '')),
    $failures,
    $checks
);

echo "\n=== (c) tenant de una sola sucursal ===\n";

$allB    = runOnce(COMPANY_B, OUTLET_B1, 'all');
$singleB = runOnce(COMPANY_B, OUTLET_B1, 'single');

check(
    '(c) con una sola sucursal, $all devuelve EXACTAMENTE lo mismo que la rama no-$all',
    ($allB['ok'] ?? false) === true
        && ($singleB['ok'] ?? false) === true
        && diffMaps($singleB['rows'], $allB['rows']) === '',
    'all=' . json_encode($allB) . ' single=' . json_encode($singleB),
    $failures,
    $checks
);

check(
    '(c) y ese resultado tiene el saldo esperado del tenant B (9 @ 700)',
    isset($allB['rows'][ITEM_B])
        && sameAmount($allB['rows'][ITEM_B]['onHand'], 9)
        && sameAmount($allB['rows'][ITEM_B]['cogs'], 700),
    json_encode($allB['rows'][ITEM_B] ?? null),
    $failures,
    $checks
);

echo "\n=== (d) aislamiento multi-tenant ===\n";

$itemsOfB = [ITEM_B];
$leakInA  = array_values(array_intersect(array_keys($rowsA), $itemsOfB));
check(
    '(d) el agregado de Verify PY NO incluye ítems de Verify MX',
    $leakInA === [],
    'fugados: ' . implode(', ', $leakInA),
    $failures,
    $checks
);

$itemsOfA = [ITEM_MULTI, ITEM_SINGLE, ITEM_ZERO];
$leakInB  = array_values(array_intersect(array_keys($allB['rows'] ?? []), $itemsOfA));
check(
    '(d) el agregado de Verify MX NO incluye ítems de Verify PY',
    $leakInB === [],
    'fugados: ' . implode(', ', $leakInB),
    $failures,
    $checks
);

// El fence real: aunque el contexto mienta sobre la sucursal (OUTLET_ID de OTRO
// tenant), el alcance lo fija companyId. Si el filtro fuera por outletId suelto,
// acá se colaría el stock del vecino.
$aWithBsOutlet = runOnce(COMPANY_A, OUTLET_B1, 'all');
check(
    '(d) con COMPANY_A pero OUTLET_ID de otro tenant, el agregado sigue siendo el de A',
    ($aWithBsOutlet['ok'] ?? false) === true && diffMaps($rowsA, $aWithBsOutlet['rows']) === '',
    ($aWithBsOutlet['ok'] ?? false) === true
        ? diffMaps($rowsA, $aWithBsOutlet['rows'])
        : ('error: ' . ($aWithBsOutlet['error'] ?? '')),
    $failures,
    $checks
);

echo "\n=== (g) la implementación vieja no daba esto ===\n";

$legacy = runOnce(COMPANY_A, OUTLET_A1, 'legacy');
$legacyMatches = ($legacy['ok'] ?? false) === true && diffMaps($rowsA, $legacy['rows']) === '';
check(
    '(g) la rama $all previa al fix NO devolvía el agregado correcto',
    !$legacyMatches,
    'la implementación vieja coincide con la nueva — el arnés no está probando el fix',
    $failures,
    $checks
);
echo "     vieja → " . (($legacy['ok'] ?? false) === true
        ? (count($legacy['rows']) . ' ítem(s)')
        : ('error: ' . substr((string) ($legacy['error'] ?? ''), 0, 160)))
    . "\n";
echo "     nueva → " . count($rowsA) . " ítem(s)\n";

harnessFinish($failures, $checks);
