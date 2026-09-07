<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de "PRODUCIBLES AHORA" — cuántas unidades de un ítem con receta salen
 * hoy con los insumos que hay (pedido del owner 2026-09-07).
 *
 * Corre contra Postgres real, sin mocks: las recetas se cargan en
 * `item_compound` y el stock se siembra por `Inventory::manageStock()`, el
 * camino real, así que el saldo que lee `onHandFor()` es el del ledger de
 * verdad y no un número puesto a mano.
 *
 * Lo que verifica, y por qué nada de esto se comprueba leyendo el código:
 *
 *   (A) FLOOR CORRECTO SOBRE UNA RECETA SIMPLE, y el insumo LIMITANTE bien
 *       identificado. Es el número que el dueño va a leer en pantalla; un bug
 *       acá devuelve una cifra creíble y equivocada.
 *   (B) LA MERMA PLANIFICADA ENTRA AL DIVISOR con la fórmula de RENDIMIENTO
 *       (`need / (1 - w/100)`), no `need × (1 + w/100)`. Con 20% de merma, 1
 *       unidad de receta consume 1,25 — y 25 de saldo alcanzan para 20, no 25
 *       ni 24. Las dos fórmulas dan números parecidos y sólo una es la que
 *       después descuenta `complete()`.
 *   (C) UN INSUMO EN 0 DA N=0. El caso que el owner nombró textual.
 *   (D) UN INSUMO SIN CONTROL DE INVENTARIO NO LIMITA (agua, sal). Sin ledger
 *       no hay saldo cero, hay saldo DESCONOCIDO: contarlo como 0 apagaría el
 *       número de toda la receta.
 *   (E) UNA RECETA SIN NINGÚN INSUMO CON CONTROL DE STOCK NO TIENE NÚMERO:
 *       `capacity === null`, nunca 0 ni un infinito inventado.
 *   (F) UN SEMIELABORADO CON STOCK PROPIO SE CONSUME COMO ESTÁ. La explosión
 *       corta ahí: lo que limita es la SALSA, no la leche que la salsa lleva
 *       dentro (ya se descontó cuando se produjo).
 *   (G) LA DIVERGENCIA QUE ESTE SLICE CORRIGE. Una sub-preparación SIN stock
 *       propio (producción directa anidada) SÍ limita, porque lo que se
 *       descuenta está un nivel más abajo. La versión vieja de `capacity()`
 *       usaba `getCompoundsArray()` —un solo nivel— y esa rama caía en
 *       `!tracked`: el plato informaba capacidad de sobra con la harina de su
 *       masa en cero. Es la regresión que hay que impedir que vuelva.
 *   (H) EMPATE: gana el PRIMERO en el orden de la receta.
 *   (I) `producible()` POR SUCURSAL: el mismo ítem da números distintos en dos
 *       sucursales, porque el saldo es por sucursal. Y el alcance acotado a una
 *       sola devuelve una sola.
 *   (J) AISLAMIENTO MULTI-TENANT: el ítem de otra compañía no se calcula.
 *
 * Uso (ver `run_producible_capacity_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/producible_capacity_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Production/RecipeCapacity.php';
require_once dirname(__DIR__) . '/lib/Production/ProductionService.php';

use Punto\Api\Production\ProductionService;
use Punto\App\Domain\Inventory;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId    = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId     = '1a282724-6073-49c3-8bc3-0114a132e349';
$otherCompany = 'fa8cf679-9003-417e-8726-5b772d3b6e88'; // "Verify MX"
$adminId      = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId       = $adminId;
$roleId       = '1';
require API_APP_DIR . '/data.php';

// Segunda sucursal del MISMO tenant: `producible()` responde por sucursal y sin
// una segunda no hay forma de ver que el saldo no se mezcla.
const OUTLET_B = 'bc7c0002-0000-4000-8000-0000000000b1';

// Ítems del arnés — prefijo `bc7c…` para no chocar con el seed ni con los del
// arnés del lote (`ba7c…`).
const IT_LECHE   = 'bc7c0001-0000-4000-8000-000000000001'; // tracked
const IT_CAFE    = 'bc7c0001-0000-4000-8000-000000000002'; // tracked, abundante
const IT_AGUA    = 'bc7c0001-0000-4000-8000-000000000003'; // SIN control de inventario
const IT_SAL     = 'bc7c0001-0000-4000-8000-000000000004'; // SIN control de inventario
const IT_AZUCAR  = 'bc7c0001-0000-4000-8000-000000000005'; // tracked, 20% de merma
const IT_CACAO   = 'bc7c0001-0000-4000-8000-000000000006'; // tracked, saldo 0
const IT_HARINA  = 'bc7c0001-0000-4000-8000-000000000007'; // tracked, hoja de la masa

const IT_SALSA   = 'bc7c0001-0000-4000-8000-000000000010'; // producción PREVIA (stock propio)
const IT_MASA    = 'bc7c0001-0000-4000-8000-000000000011'; // producción DIRECTA anidada (sin stock propio)

const D_SIMPLE   = 'bc7c0001-0000-4000-8000-000000000020';
const D_MERMA    = 'bc7c0001-0000-4000-8000-000000000021';
const D_CERO     = 'bc7c0001-0000-4000-8000-000000000022';
const D_SINCTRL  = 'bc7c0001-0000-4000-8000-000000000023';
const D_SEMI     = 'bc7c0001-0000-4000-8000-000000000024';
const D_ANIDADO  = 'bc7c0001-0000-4000-8000-000000000025';
const D_EMPATE   = 'bc7c0001-0000-4000-8000-000000000026';
const D_SINRCTA  = 'bc7c0001-0000-4000-8000-000000000027';

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

/** Las cantidades son decimales (1,25 de azúcar): comparar con tolerancia. */
function near(float $a, float $b, float $eps = 1e-6): bool
{
    return abs($a - $b) < $eps;
}

/** Un insumo del detalle, por itemId. */
function ing(array $result, string $itemId): ?array
{
    foreach ($result['ingredients'] as $row) {
        if ($row['itemId'] === $itemId) {
            return $row;
        }
    }
    return null;
}

function ids(array $result): string
{
    return json_encode(array_column($result['ingredients'], 'itemId'));
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures — idempotentes: el `.sh` recarga el tenant en cada corrida, pero el
// arnés tiene que poder correr dos veces seguidas contra la misma base.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * `itemtrackinventory` + `itemproduction` son los DOS flags que deciden el
 * modelo de stock (`Inventory::saleExplodesRecipe()`), y no `itemkind` — ver
 * `context/modules/06-produccion.md` §3 regla 1. `itemWaste` va en el JSONB
 * `data`, no es columna: leerlo como columna tira 42703.
 */
function upsertItem(string $id, string $name, string $sku, float $cost, bool $tracked, bool $production, string $dataJson, string $kind): void
{
    ncmExecute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus,
                           itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind)
         VALUES (?, ?, ?, 0, ?, 'product', 1, FALSE, ?, ?, ?::jsonb, ?, ?)
         ON CONFLICT (itemid) DO UPDATE SET
            itemname = EXCLUDED.itemname, itemcost = EXCLUDED.itemcost,
            itemtrackinventory = EXCLUDED.itemtrackinventory,
            itemproduction = EXCLUDED.itemproduction, data = EXCLUDED.data,
            itemstatus = 1",
        [$id, $name, $sku, $cost, $tracked, $production, $dataJson, COMPANY_ID, $kind]
    );
}

function upsertRecipeLine(string $parentId, string $childId, float $qty, int $sort): void
{
    ncmExecute(
        'INSERT INTO item_compound (parentItemId, childItemId, quantity, sort, companyId)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT (parentItemId, childItemId) DO UPDATE SET quantity = EXCLUDED.quantity, sort = EXCLUDED.sort',
        [$parentId, $childId, $qty, $sort, COMPANY_ID]
    );
}

ncmExecute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName, outletStatus = 1',
    [OUTLET_B, 'Verify PY - Sucursal B', COMPANY_ID]
);

// Insumos con ledger.
upsertItem(IT_LECHE,  'PC leche',  'PC-LECHE',  4000, true,  false, '{}', 'insumo_stock');
upsertItem(IT_CAFE,   'PC cafe',   'PC-CAFE',   9000, true,  false, '{}', 'insumo_stock');
upsertItem(IT_AZUCAR, 'PC azucar', 'PC-AZUCAR', 2000, true,  false, '{"itemWaste": 20}', 'insumo_stock');
upsertItem(IT_CACAO,  'PC cacao',  'PC-CACAO',  8000, true,  false, '{}', 'insumo_stock');
upsertItem(IT_HARINA, 'PC harina', 'PC-HARINA', 1500, true,  false, '{}', 'insumo_stock');
// Insumos SIN control de inventario: no limitan (D1 de context/70).
upsertItem(IT_AGUA,   'PC agua',   'PC-AGUA',    100, false, false, '{}', 'insumo_sin_stock');
upsertItem(IT_SAL,    'PC sal',    'PC-SAL',     100, false, false, '{}', 'insumo_sin_stock');

// Semielaborado CON stock propio (producción previa): la explosión corta ahí.
upsertItem(IT_SALSA, 'PC salsa', 'PC-SALSA', 3000, true, true, '{}', 'produccion_previa');
upsertRecipeLine(IT_SALSA, IT_LECHE, 2, 0);

// Sub-preparación SIN stock propio (producción directa anidada): la explosión
// baja hasta la harina. Es el caso (G).
upsertItem(IT_MASA, 'PC masa', 'PC-MASA', 0, false, false, '{}', 'produccion_directa');
upsertRecipeLine(IT_MASA, IT_HARINA, 3, 0);

// Platos, todos de producción DIRECTA (se arman sobre pedido — el escenario
// textual del owner: "a ojo no podés saber cuántas unidades te quedan").
upsertItem(D_SIMPLE,  'PC latte',        'PC-LATTE',  0, false, false, '{}', 'produccion_directa');
upsertItem(D_MERMA,   'PC latte dulce',  'PC-DULCE',  0, false, false, '{}', 'produccion_directa');
upsertItem(D_CERO,    'PC mocha',        'PC-MOCHA',  0, false, false, '{}', 'produccion_directa');
upsertItem(D_SINCTRL, 'PC agua saborizada', 'PC-AGSA', 0, false, false, '{}', 'produccion_directa');
upsertItem(D_SEMI,    'PC bowl',         'PC-BOWL',   0, false, false, '{}', 'produccion_directa');
upsertItem(D_ANIDADO, 'PC medialuna',    'PC-MEDIA',  0, false, false, '{}', 'produccion_directa');
upsertItem(D_EMPATE,  'PC empate',       'PC-EMPATE', 0, false, false, '{}', 'produccion_directa');
upsertItem(D_SINRCTA, 'PC sin receta',   'PC-SINR',   0, false, false, '{}', 'produccion_directa');

upsertRecipeLine(D_SIMPLE, IT_LECHE, 2, 0);   // 2 por unidad
upsertRecipeLine(D_SIMPLE, IT_CAFE,  1, 1);
upsertRecipeLine(D_SIMPLE, IT_AGUA,  5, 2);   // no limita

upsertRecipeLine(D_MERMA, IT_AZUCAR, 1, 0);   // 20% de merma → 1,25 efectivo
upsertRecipeLine(D_MERMA, IT_CAFE,   1, 1);

upsertRecipeLine(D_CERO, IT_CACAO, 1, 0);     // saldo 0
upsertRecipeLine(D_CERO, IT_CAFE,  1, 1);

upsertRecipeLine(D_SINCTRL, IT_AGUA, 5, 0);   // ningún insumo con control
upsertRecipeLine(D_SINCTRL, IT_SAL,  2, 1);

upsertRecipeLine(D_SEMI, IT_SALSA, 1, 0);     // semielaborado con stock propio
upsertRecipeLine(D_SEMI, IT_CAFE,  1, 1);

upsertRecipeLine(D_ANIDADO, IT_MASA, 1, 0);   // sub-preparación sin stock propio
upsertRecipeLine(D_ANIDADO, IT_CAFE, 1, 1);

upsertRecipeLine(D_EMPATE, IT_LECHE, 2, 0);   // 20/2 = 10 ── empate…
upsertRecipeLine(D_EMPATE, IT_CAFE, 10, 1);   // 100/10 = 10 ── …gana el primero

/**
 * Saldo objetivo por el camino REAL (`manageStock`), nunca con un INSERT crudo:
 * así el saldo que lee `onHandFor()` sale del mismo ledger que la operación.
 */
function setStock(string $itemId, float $target, float $cogs, string $outletId): void
{
    $actual = Inventory::onHand($itemId, $outletId);
    $delta  = $target - $actual;
    if (abs($delta) < 1e-9) {
        return;
    }
    Inventory::manageStock([
        'itemId'        => $itemId,
        'source'        => 'adjustment',
        'count'         => abs($delta),
        'type'          => $delta > 0 ? '+' : '-',
        'cogs'          => $cogs,
        'userId'        => USER_ID,
        'transactionId' => null,
        'outletId'      => $outletId,
        'locationId'    => null,
        'note'          => 'producible-test seed',
        'date'          => date('Y-m-d H:i:s'),
        'companyId'     => COMPANY_ID,
    ]);
}

setStock(IT_LECHE,  20,  4000, $outletId);
setStock(IT_CAFE,  100,  9000, $outletId);
setStock(IT_AZUCAR, 25,  2000, $outletId);
setStock(IT_CACAO,   0,  8000, $outletId);
setStock(IT_HARINA,  6,  1500, $outletId);
setStock(IT_SALSA,   7,  3000, $outletId);

// Sucursal B: la mitad de leche, el resto igual. Sirve para (I).
setStock(IT_LECHE,   8,  4000, OUTLET_B);
setStock(IT_CAFE,  100,  9000, OUTLET_B);

global $db;
$svc = new ProductionService($db);

// ─────────────────────────────────────────────────────────────────────────────
// (A) receta simple: floor correcto, limitante correcto, no-tracked no limita
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (A) receta simple: 2 leche (20) + 1 cafe (100) + 5 agua (sin control) ===\n";

$r = $svc->capacity($companyId, D_SIMPLE, $outletId);

check('(A1) capacidad = 10 (floor(20/2), no 50 del cafe ni 0 del agua)',
    $r['capacity'] === 10, 'capacity = ' . json_encode($r['capacity']), $failures, $checks);
check('(A2) el limitante reportado es la leche',
    ($r['limiting']['itemId'] ?? null) === IT_LECHE,
    'limiting = ' . json_encode($r['limiting']['itemId'] ?? null), $failures, $checks);
check('(A3) el limitante trae su saldo y cuántas soporta (2 L, alcanzan para 10)',
    near((float) ($r['limiting']['onHand'] ?? -1), 20.0)
        && ($r['limiting']['unitsSupported'] ?? null) === 10,
    'limiting = ' . json_encode($r['limiting']), $failures, $checks);

$leche = ing($r, IT_LECHE);
check('(A4) la leche necesita 2 por unidad y soporta 10',
    $leche !== null && near((float) $leche['neededPerUnit'], 2.0)
        && $leche['unitsSupported'] === 10 && $leche['limiting'] === true && $leche['tracked'] === true,
    json_encode($leche), $failures, $checks);

$cafe = ing($r, IT_CAFE);
check('(A5) el cafe soporta 100 y NO es el limitante',
    $cafe !== null && $cafe['unitsSupported'] === 100 && $cafe['limiting'] === false,
    json_encode($cafe), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (D) insumo sin control de inventario: aparece, pero no limita ni inventa 0
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (D) el agua (sin control de inventario) no limita ===\n";

$agua = ing($r, IT_AGUA);
check('(D1) el agua aparece en el detalle',
    $agua !== null, 'ingredients = ' . ids($r), $failures, $checks);
check('(D2) el agua no limita, y su saldo es null (desconocido) — no 0',
    $agua !== null && $agua['tracked'] === false && $agua['limiting'] === false
        && $agua['onHand'] === null && $agua['unitsSupported'] === null,
    json_encode($agua), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (B) merma planificada: fórmula de RENDIMIENTO, no "+20%"
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (B) merma: 1 azucar con 20% de merma (saldo 25) ===\n";

$rm     = $svc->capacity($companyId, D_MERMA, $outletId);
$azucar = ing($rm, IT_AZUCAR);

check('(B1) 1 de receta con 20% de merma consume 1,25 (need/(1-w), no need*(1+w)=1,2)',
    $azucar !== null && near((float) $azucar['neededPerUnit'], 1.25),
    'neededPerUnit = ' . json_encode($azucar['neededPerUnit'] ?? null), $failures, $checks);
check('(B2) 25 de saldo alcanzan para 20 unidades (floor(25/1,25))',
    $rm['capacity'] === 20, 'capacity = ' . json_encode($rm['capacity']), $failures, $checks);
check('(B3) el limitante es el azucar',
    ($rm['limiting']['itemId'] ?? null) === IT_AZUCAR,
    json_encode($rm['limiting']['itemId'] ?? null), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (C) insumo en 0 ⇒ N = 0 (el caso textual del owner)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (C) un insumo en 0 apaga la receta entera ===\n";

$rc = $svc->capacity($companyId, D_CERO, $outletId);

check('(C1) capacidad = 0, no null ni el 100 del cafe',
    $rc['capacity'] === 0, 'capacity = ' . json_encode($rc['capacity']), $failures, $checks);
check('(C2) el limitante es el cacao, con saldo 0',
    ($rc['limiting']['itemId'] ?? null) === IT_CACAO
        && near((float) ($rc['limiting']['onHand'] ?? -1), 0.0),
    json_encode($rc['limiting']), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (E) receta sin ningún insumo con control ⇒ SIN número (null), nunca 0
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (E) receta de puros insumos sin control de stock ===\n";

$rs = $svc->capacity($companyId, D_SINCTRL, $outletId);

check('(E1) capacidad = null (no hay número), nunca 0 ni un infinito',
    $rs['capacity'] === null, 'capacity = ' . json_encode($rs['capacity']), $failures, $checks);
check('(E2) no hay limitante',
    $rs['limiting'] === null, json_encode($rs['limiting']), $failures, $checks);
check('(E3) los dos insumos vienen igual en el detalle, ninguno trackeado',
    count($rs['ingredients']) === 2
        && !array_filter($rs['ingredients'], static fn (array $i): bool => $i['tracked'] === true),
    json_encode($rs['ingredients']), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (F) semielaborado CON stock propio: se consume como está, no se re-explota
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (F) la salsa (produccion previa, saldo 7) se consume como esta ===\n";

$rf = $svc->capacity($companyId, D_SEMI, $outletId);

check('(F1) capacidad = 7 — manda la salsa, no la leche que la salsa lleva dentro',
    $rf['capacity'] === 7, 'capacity = ' . json_encode($rf['capacity']), $failures, $checks);
check('(F2) el limitante es la salsa',
    ($rf['limiting']['itemId'] ?? null) === IT_SALSA,
    json_encode($rf['limiting']['itemId'] ?? null), $failures, $checks);
check('(F3) la leche NO aparece: su receta ya se descontó al producir la salsa',
    ing($rf, IT_LECHE) === null, 'ingredients = ' . ids($rf), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (G) sub-preparación SIN stock propio: SÍ limita — la divergencia corregida
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (G) la masa (produccion directa anidada, sin stock propio) baja a la harina ===\n";

$rg = $svc->capacity($companyId, D_ANIDADO, $outletId);

check('(G1) capacidad = 2 (floor(6 harina / 3 por masa)) — la version de UN nivel decia 100',
    $rg['capacity'] === 2, 'capacity = ' . json_encode($rg['capacity']), $failures, $checks);
check('(G2) el limitante es la HARINA, la hoja real, no la masa',
    ($rg['limiting']['itemId'] ?? null) === IT_HARINA,
    json_encode($rg['limiting']['itemId'] ?? null), $failures, $checks);
check('(G3) la masa NO aparece como insumo: no lleva stock, no es hoja',
    ing($rg, IT_MASA) === null, 'ingredients = ' . ids($rg), $failures, $checks);
check('(G4) la harina necesita 3 por unidad del plato (1 masa x 3 harina)',
    near((float) (ing($rg, IT_HARINA)['neededPerUnit'] ?? 0), 3.0),
    json_encode(ing($rg, IT_HARINA)), $failures, $checks);

// `directIngredients` sigue siendo el NIVEL 1: es lo que `complete()` indexa.
$directIds = array_column($rg['directIngredients'], 'itemId');
check('(G5) directIngredients trae la MASA (nivel 1), no la harina — complete() indexa por ahi',
    in_array(IT_MASA, $directIds, true) && !in_array(IT_HARINA, $directIds, true),
    json_encode($directIds), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (H) empate: gana el primero de la receta
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (H) empate en 10: leche (2 de 20) vs cafe (10 de 100) ===\n";

$rh = $svc->capacity($companyId, D_EMPATE, $outletId);

check('(H1) capacidad = 10',
    $rh['capacity'] === 10, 'capacity = ' . json_encode($rh['capacity']), $failures, $checks);
check('(H2) con empate gana el primero de la receta (la leche, sort 0)',
    ($rh['limiting']['itemId'] ?? null) === IT_LECHE,
    json_encode($rh['limiting']['itemId'] ?? null), $failures, $checks);
check('(H3) un solo insumo marcado como limitante',
    count(array_filter($rh['ingredients'], static fn (array $i): bool => $i['limiting'] === true)) === 1,
    json_encode(array_column($rh['ingredients'], 'limiting')), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// Ítem sin receta: la seccion no se renderiza (hasRecipe=false)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (K) item sin receta ===\n";

$rk = $svc->producible($companyId, D_SINRCTA, []);
check('(K1) producible() de un item sin receta devuelve hasRecipe=false y sin sucursales',
    $rk['hasRecipe'] === false && $rk['outlets'] === [],
    json_encode($rk), $failures, $checks);

$rk2 = $svc->capacity($companyId, D_SINRCTA, $outletId);
check('(K2) capacity() de un item sin receta sigue devolviendo 0 con detalle vacio (contrato viejo)',
    $rk2['capacity'] === 0 && $rk2['ingredients'] === [] && $rk2['directIngredients'] === [],
    json_encode($rk2), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (I) producible() por sucursal
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (I) producibles por sucursal: leche 20 en A, 8 en B ===\n";

$ri = $svc->producible($companyId, D_SIMPLE, []);
$byOutlet = [];
foreach ($ri['outlets'] as $o) {
    $byOutlet[$o['outletId']] = $o;
}

check('(I1) hasRecipe=true y vienen las dos sucursales del tenant',
    $ri['hasRecipe'] === true
        && isset($byOutlet[$outletId], $byOutlet[OUTLET_B]),
    'outlets = ' . json_encode(array_column($ri['outlets'], 'outletId')), $failures, $checks);
check('(I2) sucursal A = 10 (leche 20)',
    ($byOutlet[$outletId]['capacity'] ?? null) === 10,
    json_encode($byOutlet[$outletId] ?? null), $failures, $checks);
check('(I3) sucursal B = 4 (leche 8) — el saldo NO se mezcla entre sucursales',
    ($byOutlet[OUTLET_B]['capacity'] ?? null) === 4,
    json_encode($byOutlet[OUTLET_B] ?? null), $failures, $checks);
check('(I4) cada sucursal trae su nombre y su limitante',
    ($byOutlet[OUTLET_B]['outletName'] ?? '') !== ''
        && ($byOutlet[OUTLET_B]['limiting']['itemId'] ?? null) === IT_LECHE,
    json_encode($byOutlet[OUTLET_B] ?? null), $failures, $checks);

$riScoped = $svc->producible($companyId, D_SIMPLE, [OUTLET_B]);
check('(I5) con alcance acotado a una sucursal vuelve UNA sola',
    count($riScoped['outlets']) === 1
        && ($riScoped['outlets'][0]['outletId'] ?? null) === OUTLET_B,
    json_encode(array_column($riScoped['outlets'], 'outletId')), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (J) aislamiento multi-tenant
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== (J) aislamiento multi-tenant ===\n";

check('(J1) capacity() con el companyId de otro tenant lanza, no calcula',
    (static function () use ($svc, $otherCompany, $outletId): bool {
        try {
            $svc->capacity($otherCompany, D_SIMPLE, $outletId);
            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    })(),
    'capacity() con otro companyId no lanzó', $failures, $checks);

check('(J2) producible() con el companyId de otro tenant lanza',
    (static function () use ($svc, $otherCompany): bool {
        try {
            $svc->producible($otherCompany, D_SIMPLE, []);
            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    })(),
    'producible() con otro companyId no lanzó', $failures, $checks);

check('(J3) producible() del otro tenant no devuelve sucursales de este',
    (static function () use ($svc, $otherCompany, $outletId): bool {
        // Un ítem inexistente en el otro tenant también tiene que cortar antes
        // de mirar sucursales — el fence está en el motor, no en el endpoint.
        try {
            $svc->producible($otherCompany, D_SEMI, [$outletId]);
            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    })(),
    'producible() cruzado no lanzó', $failures, $checks);

harnessFinish($failures, $checks);
