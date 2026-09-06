<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del LOTE DE PRODUCCIÓN MULTI-PLATO (context/70-viandas.md, etapa B).
 *
 * Corre contra Postgres real, sin mocks: las recetas se cargan en
 * `item_compound`, el stock se siembra por `Inventory::manageStock()` (el
 * camino real) y el lote se confirma por el mismo `ProductionService::complete()`
 * que usa una orden suelta.
 *
 * Lo que verifica, y por qué ninguna de las cinco cosas se puede comprobar
 * leyendo el código:
 *
 *   (A) DOS PLATOS QUE COMPARTEN UN INSUMO SUMAN. Es la pregunta textual del
 *       cliente: "de 10 pedidos, cuánta pechuga necesito en total". El motor
 *       ya agregaba DENTRO de un ítem; el agregador entre ítems es lo nuevo, y
 *       un bug acá devuelve un número creíble pero equivocado.
 *   (B) LA MERMA SE APLICA POR NIVEL. La sal lleva 20% planificado: 10
 *       unidades de necesidad teórica tienen que salir 12,5, no 10.
 *   (C) UN SUBPRODUCTO INTERMEDIO NO SE RE-EXPLOTA. La salsa lleva stock
 *       propio (producción previa) y su receta ya se descontó cuando se
 *       produjo: el lote tiene que pedir SALSA, no la pechuga que la salsa
 *       lleva dentro. Si se re-explotara, la necesidad de pechuga saldría
 *       inflada y el comercio compraría de más — silenciosamente.
 *   (D) UN INSUMO SIN CONTROL DE INVENTARIO DEVUELVE NECESIDAD, NO FALTANTE
 *       (D1 de context/70). Sin `onHand` real, un `missing` calculado contra
 *       cero le diría "te faltan 12,5 de sal" a un comercio que tiene el
 *       paquete lleno y nunca lo cargó al sistema.
 *   (E) CONFIRMAR EL LOTE MUEVE EL STOCK POR EL CAMINO DE `complete()` Y DEJA
 *       EL COGS CORRECTO. Es lo que garantiza que el lote no reimplementó el
 *       consumo ni el costeo: se compara el ledger antes/después y el
 *       `unitcogs` congelado contra el costo calculado a mano.
 *
 *   (+) `confirm()` es TODO-O-NADA: con una línea que no puede completarse, ni
 *       la que sí podía movió stock. Un lote a medias deja insumos consumidos
 *       para platos que nadie cocinó.
 *   (+) Una orden SUELTA (`batchid IS NULL`) sigue funcionando igual — la
 *       comprobación de no-regresión del camino de un solo plato.
 *
 * Uso (ver `run_production_batch_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/production_batch_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Production/ProductionService.php';
require_once dirname(__DIR__) . '/lib/Production/ProductionBatchService.php';

use Punto\Api\Production\ProductionBatchService;
use Punto\Api\Production\ProductionService;
use Punto\App\Domain\Inventory;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

// Ítems propios del arnés — prefijo `ba7c…` para no chocar con los del seed.
const IT_PECHUGA  = 'ba7c0001-0000-4000-8000-000000000001'; // insumo compartido, con stock
const IT_SAL      = 'ba7c0001-0000-4000-8000-000000000002'; // sin control de inventario, 20% de merma
const IT_SALSA    = 'ba7c0001-0000-4000-8000-000000000003'; // subproducto: producción PREVIA con receta propia
const IT_MILANESA = 'ba7c0001-0000-4000-8000-000000000004'; // plato A
const IT_SUPREMA  = 'ba7c0001-0000-4000-8000-000000000005'; // plato B
const IT_SUELTO   = 'ba7c0001-0000-4000-8000-000000000006'; // plato de la orden suelta (no-regresión)

const COSTO_PECHUGA = 5000.0;
const COSTO_SAL     = 100.0;
const COSTO_SALSA   = 3000.0;

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

/** Comparación de floats: las cantidades son decimales (12,5 de sal). */
function near(float $a, float $b, float $eps = 1e-6): bool
{
    return abs($a - $b) < $eps;
}

/** Un insumo del resultado de `estimate()`, por itemId. */
function ing(array $estimate, string $itemId): ?array
{
    foreach ($estimate['ingredients'] as $row) {
        if ($row['itemId'] === $itemId) {
            return $row;
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures — idempotentes: el `.sh` recarga el tenant entero en cada corrida,
// pero el arnés tiene que poder correr dos veces seguidas contra la misma base.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * `itemtrackinventory` + `itemproduction` son los DOS flags que deciden el
 * modelo de stock (`Inventory::saleExplodesRecipe()`), y no `itemkind` —
 * ver `context/modules/06-produccion.md` §3 regla 1.
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
         ON CONFLICT (parentItemId, childItemId) DO UPDATE SET quantity = EXCLUDED.quantity',
        [$parentId, $childId, $qty, $sort, COMPANY_ID]
    );
}

// Insumo compartido, con ledger.
upsertItem(IT_PECHUGA, 'Batch pechuga', 'BATCH-PECHUGA', COSTO_PECHUGA, true, false, '{}', 'insumo_stock');
// Insumo SIN control de inventario y con 20% de merma planificada: cubre (B) y (D)
// con el mismo ítem. `itemWaste` vive en el JSONB `data`, no es columna.
upsertItem(IT_SAL, 'Batch sal', 'BATCH-SAL', COSTO_SAL, false, false, '{"itemWaste": 20}', 'insumo_sin_stock');
// Subproducto de PRODUCCIÓN PREVIA: lleva stock propio y además tiene receta.
// Es el caso de (C) — su receta ya se descontó cuando se produjo.
upsertItem(IT_SALSA, 'Batch salsa', 'BATCH-SALSA', COSTO_SALSA, true, true, '{}', 'produccion_previa');
upsertItem(IT_MILANESA, 'Batch milanesa', 'BATCH-MILA', 0, true, true, '{}', 'produccion_previa');
upsertItem(IT_SUPREMA, 'Batch suprema', 'BATCH-SUPR', 0, true, true, '{}', 'produccion_previa');
upsertItem(IT_SUELTO, 'Batch plato suelto', 'BATCH-SUELTO', 0, true, true, '{}', 'produccion_previa');

// Recetas.
upsertRecipeLine(IT_SALSA, IT_PECHUGA, 2, 0);      // la salsa lleva 2 de pechuga — NO debe re-explotarse
upsertRecipeLine(IT_MILANESA, IT_PECHUGA, 2, 0);   // A: 2 pechuga + 1 sal
upsertRecipeLine(IT_MILANESA, IT_SAL, 1, 1);
upsertRecipeLine(IT_SUPREMA, IT_PECHUGA, 3, 0);    // B: 3 pechuga + 1 salsa
upsertRecipeLine(IT_SUPREMA, IT_SALSA, 1, 1);
upsertRecipeLine(IT_SUELTO, IT_PECHUGA, 1, 0);

// Stock inicial por el camino REAL (`manageStock`), no con un INSERT crudo:
// así el `stockOnHandCOGS` que después lee `RecipeCosting` queda calculado por
// el mismo promedio ponderado que usa producción.
function seedStock(string $itemId, float $qty, float $cogs, string $outletId): void
{
    Inventory::manageStock([
        'itemId'        => $itemId,
        'source'        => 'adjustment',
        'count'         => $qty,
        'type'          => '+',
        'cogs'          => $cogs,
        'userId'        => USER_ID,
        'transactionId' => null,
        'outletId'      => $outletId,
        'locationId'    => null,
        'note'          => 'batch-test seed',
        'date'          => date('Y-m-d H:i:s'),
        'companyId'     => COMPANY_ID,
    ]);
}

$pechugaAntes = Inventory::onHand(IT_PECHUGA, $outletId);
$salsaAntes   = Inventory::onHand(IT_SALSA, $outletId);
seedStock(IT_PECHUGA, 100 - $pechugaAntes > 0 ? 100 - $pechugaAntes : 100, COSTO_PECHUGA, $outletId);
seedStock(IT_SALSA, 20 - $salsaAntes > 0 ? 20 - $salsaAntes : 20, COSTO_SALSA, $outletId);

$pechugaOnHand = Inventory::onHand(IT_PECHUGA, $outletId);
$salsaOnHand   = Inventory::onHand(IT_SALSA, $outletId);

global $db;
$batches = new ProductionBatchService($db);
$orders  = new ProductionService($db);

// ─────────────────────────────────────────────────────────────────────────────
// (A)(B)(C)(D) — la necesidad consolidada
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== (A-D) necesidad consolidada: 10 milanesas + 5 supremas ===\n";

$est = $batches->estimate($companyId, $outletId, [
    ['itemId' => IT_MILANESA, 'qty' => 10],
    ['itemId' => IT_SUPREMA,  'qty' => 5],
]);

// (A) 2×10 de la milanesa + 3×5 de la suprema = 35. Ni 20 ni 15: la suma.
$pechuga = ing($est, IT_PECHUGA);
check('(A1) la pechuga aparece UNA sola vez, consolidada entre los dos platos',
    $pechuga !== null, 'no vino en ingredients: ' . json_encode(array_column($est['ingredients'], 'itemId')),
    $failures, $checks);
check('(A2) necesidad total de pechuga = 35 (2×10 + 3×5)',
    $pechuga !== null && near((float) $pechuga['needed'], 35.0),
    'needed = ' . json_encode($pechuga['needed'] ?? null), $failures, $checks);
check('(A3) el desglose dice de qué plato salió cuánto (20 + 15)',
    $pechuga !== null && count($pechuga['bySource']) === 2,
    'bySource = ' . json_encode($pechuga['bySource'] ?? null), $failures, $checks);

// (B) La sal lleva 20% de merma PLANIFICADA sobre el insumo: 1×10 = 10 teórico
// → 10 / (1 - 0.20) = 12,5. Es merma del INSUMO, no de la línea de receta.
$sal = ing($est, IT_SAL);
check('(B1) la merma del 20% se aplica por nivel: 10 teóricos → 12,5 de necesidad',
    $sal !== null && near((float) $sal['needed'], 12.5),
    'needed = ' . json_encode($sal['needed'] ?? null), $failures, $checks);

// (C) La salsa lleva stock propio: el lote pide SALSA (5), no las 10 de pechuga
// que la salsa lleva dentro. Si se re-explotara, la pechuga daría 45, no 35.
$salsa = ing($est, IT_SALSA);
check('(C1) el subproducto se pide como tal (5 de salsa), no se re-explota',
    $salsa !== null && near((float) $salsa['needed'], 5.0),
    'needed = ' . json_encode($salsa['needed'] ?? null), $failures, $checks);
check('(C2) y por eso la pechuga NO se infla a 45 (35 + las 10 de la salsa)',
    $pechuga !== null && !near((float) $pechuga['needed'], 45.0),
    'needed = ' . json_encode($pechuga['needed'] ?? null), $failures, $checks);

// (D) Sin control de inventario NO hay faltante — hay necesidad total.
check('(D1) la sal viene marcada como sin control de inventario',
    $sal !== null && $sal['tracked'] === false,
    'tracked = ' . json_encode($sal['tracked'] ?? null), $failures, $checks);
check('(D2) y NO trae onHand inventado: viene null, no 0',
    $sal !== null && $sal['onHand'] === null,
    'onHand = ' . json_encode($sal['onHand'] ?? 'ausente'), $failures, $checks);
check('(D3) ni faltante: `missing` es null, no la necesidad disfrazada',
    $sal !== null && $sal['missing'] === null,
    'missing = ' . json_encode($sal['missing'] ?? 'ausente'), $failures, $checks);

// El insumo que SÍ trackea sí compara contra el saldo del depósito.
check('(D4) la pechuga, que sí trackea, trae el onHand real de la sucursal',
    $pechuga !== null && $pechuga['onHand'] !== null && near((float) $pechuga['onHand'], $pechugaOnHand),
    'onHand = ' . json_encode($pechuga['onHand'] ?? null) . ", esperaba $pechugaOnHand", $failures, $checks);
check('(D5) con 100 en stock y 35 de necesidad, no falta nada',
    $pechuga !== null && near((float) $pechuga['missing'], 0.0),
    'missing = ' . json_encode($pechuga['missing'] ?? null), $failures, $checks);

// Capacidad multi-ítem: el mínimo sobre la necesidad YA consolidada.
// pechuga 100/35 = 2,857…; salsa 20/5 = 4 → el lote entra 2,857 veces.
check('(A4) batchCapacity = min(100/35, 20/5) = 2,857… (el mínimo sobre la necesidad consolidada)',
    $est['batchCapacity'] !== null && near((float) $est['batchCapacity'], 100 / 35, 1e-4),
    'batchCapacity = ' . json_encode($est['batchCapacity']), $failures, $checks);

// Faltante real: subir la cantidad hasta pasarse del stock.
$estFalta = $batches->estimate($companyId, $outletId, [['itemId' => IT_MILANESA, 'qty' => 80]]);
$pechugaF = ing($estFalta, IT_PECHUGA);
check('(A5) con 160 de necesidad y 100 en stock, el faltante es 60',
    $pechugaF !== null && near((float) $pechugaF['missing'], 160.0 - $pechugaOnHand),
    'missing = ' . json_encode($pechugaF['missing'] ?? null), $failures, $checks);

// `estimate()` es LECTURA PURA: no puede haber dejado un lote atrás.
$lotesTrasEstimar = ncmExecute('SELECT COUNT(*) AS n FROM production_batch WHERE companyid = ?', [$companyId]);
check('(A6) estimar no escribió ningún lote (es lectura pura)',
    (int) ($lotesTrasEstimar['n'] ?? -1) === 0,
    'lotes en la tabla: ' . json_encode($lotesTrasEstimar), $failures, $checks);

// Dos líneas del MISMO plato se suman, no se pisan.
$estDup = $batches->estimate($companyId, $outletId, [
    ['itemId' => IT_MILANESA, 'qty' => 4],
    ['itemId' => IT_MILANESA, 'qty' => 6],
]);
$pechugaD = ing($estDup, IT_PECHUGA);
check('(A7) dos líneas del mismo plato suman (4 + 6 = 10 → 20 de pechuga), no se pisan',
    $pechugaD !== null && near((float) $pechugaD['needed'], 20.0),
    'needed = ' . json_encode($pechugaD['needed'] ?? null), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// (E) — confirmar el lote mueve stock por `complete()` y deja el COGS correcto
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== (E) confirmar el lote: stock y COGS ===\n";

$pechugaPre = Inventory::onHand(IT_PECHUGA, $outletId);
$milaPre    = Inventory::onHand(IT_MILANESA, $outletId);
$salsaPre   = Inventory::onHand(IT_SALSA, $outletId);
$supremaPre = Inventory::onHand(IT_SUPREMA, $outletId);

$batchId = $batches->create($companyId, $userId, [
    'outletId' => $outletId,
    'note'     => 'lote del arnés',
    'lines'    => [
        ['itemId' => IT_MILANESA, 'qty' => 2],
        ['itemId' => IT_SUPREMA,  'qty' => 1],
    ],
]);

$creado = $batches->find($companyId, $batchId);
check('(E1) el lote nace en draft con sus dos líneas como órdenes hijas',
    $creado !== null && $creado['status'] === 'draft' && count($creado['lines']) === 2,
    json_encode($creado === null ? null : ['status' => $creado['status'], 'lines' => count($creado['lines'])]),
    $failures, $checks);
check('(E2) el lote lleva su propio correlativo',
    $creado !== null && is_int($creado['docNumber']) && $creado['docNumber'] > 0,
    'docNumber = ' . json_encode($creado['docNumber'] ?? null), $failures, $checks);
check('(E3) cada línea es una `production_order` real, con su propio correlativo `produccion`',
    $creado !== null && count(array_filter($creado['lines'], static fn (array $l): bool => is_int($l['docNumber']) && $l['docNumber'] > 0)) === 2,
    json_encode(array_column($creado['lines'] ?? [], 'docNumber')), $failures, $checks);
check('(E4) crear el lote NO movió stock todavía',
    near(Inventory::onHand(IT_PECHUGA, $outletId), $pechugaPre),
    'pechuga = ' . Inventory::onHand(IT_PECHUGA, $outletId) . ", esperaba $pechugaPre", $failures, $checks);

$confirmado = $batches->confirm($companyId, $userId, $batchId);

check('(E5) el lote queda confirmado',
    ($confirmado['status'] ?? '') === 'confirmed',
    'status = ' . json_encode($confirmado['status'] ?? null), $failures, $checks);
check('(E6) y sus dos líneas quedan completadas',
    count(array_filter($confirmado['lines'] ?? [], static fn (array $l): bool => $l['status'] === 'completed')) === 2,
    json_encode(array_column($confirmado['lines'] ?? [], 'status')), $failures, $checks);

// Consumo esperado: 2 milanesas × 2 pechuga = 4, más 1 suprema × 3 = 3 → 7.
// La salsa se consume como subproducto (1), y su propia receta NO se toca.
$pechugaPost = Inventory::onHand(IT_PECHUGA, $outletId);
check('(E7) el stock de pechuga bajó exactamente 7 (4 de las milanesas + 3 de la suprema)',
    near($pechugaPost, $pechugaPre - 7.0),
    "antes $pechugaPre, después $pechugaPost", $failures, $checks);
check('(E8) la salsa se consumió como subproducto: bajó 1, y su receta no se volvió a explotar',
    near(Inventory::onHand(IT_SALSA, $outletId), $salsaPre - 1.0),
    'salsa después = ' . Inventory::onHand(IT_SALSA, $outletId) . ", antes $salsaPre", $failures, $checks);
check('(E9) el terminado entró a stock: +2 milanesas, +1 suprema',
    near(Inventory::onHand(IT_MILANESA, $outletId), $milaPre + 2.0)
        && near(Inventory::onHand(IT_SUPREMA, $outletId), $supremaPre + 1.0),
    'milanesa = ' . Inventory::onHand(IT_MILANESA, $outletId) . ', suprema = ' . Inventory::onHand(IT_SUPREMA, $outletId),
    $failures, $checks);

// COGS de la milanesa: 4 de pechuga × 5000 + (1×2 / (1-0.20) = 2,5) de sal × 100
// = 20.000 + 250 = 20.250, sobre 2 unidades → 10.125 por unidad.
$lineaMila = null;
foreach (($confirmado['lines'] ?? []) as $l) {
    if ($l['itemId'] === IT_MILANESA) $lineaMila = $l;
}
$cogsEsperado = (4 * COSTO_PECHUGA + 2.5 * COSTO_SAL) / 2;
check('(E10) el COGS unitario de la milanesa incluye la sal SIN ledger, con su merma: ' . $cogsEsperado,
    $lineaMila !== null && $lineaMila['unitCogs'] !== null && near((float) $lineaMila['unitCogs'], $cogsEsperado, 0.01),
    'unitCogs = ' . json_encode($lineaMila['unitCogs'] ?? null) . ", esperaba $cogsEsperado", $failures, $checks);

// El movimiento tiene que haber salido por el camino de producción, no por un
// INSERT propio del lote: `stockSource` lo delata.
$mov = ncmExecute(
    "SELECT stockSource FROM stock
      WHERE itemId = ? AND outletId = ? AND stockSource = 'production'
      ORDER BY stockDate DESC, stockId DESC LIMIT 1",
    [IT_PECHUGA, $outletId]
);
check('(E11) el movimiento quedó con source=production (salió por complete(), no por un INSERT del lote)',
    ($mov['stocksource'] ?? $mov['stockSource'] ?? '') === 'production',
    json_encode($mov), $failures, $checks);

check('(E12) confirmar de nuevo es un error explícito (idempotencia, nunca dobla stock)',
    (static function () use ($batches, $companyId, $userId, $batchId): bool {
        try { $batches->confirm($companyId, $userId, $batchId); return false; }
        catch (\Throwable $e) { return true; }
    })(),
    'el segundo confirm() no lanzó', $failures, $checks);
check('(E13) y el stock no se movió por ese segundo intento',
    near(Inventory::onHand(IT_PECHUGA, $outletId), $pechugaPost),
    'pechuga = ' . Inventory::onHand(IT_PECHUGA, $outletId) . ", esperaba $pechugaPost", $failures, $checks);

// El lote confirmado no recalcula la necesidad con los datos de hoy: cada hija
// ya congeló su `recipesnapshot`.
// `array_key_exists` y NO `?? 'ausente'`: la clave existe y vale null, que es
// exactamente lo que se está afirmando — el `??` colapsa "está y es null" con
// "no está" y daba un falso rojo.
check('(E14) un lote confirmado no recalcula la necesidad (el consumo real quedó congelado en cada hija)',
    array_key_exists('estimate', $confirmado) && $confirmado['estimate'] === null,
    'estimate = ' . json_encode($confirmado['estimate'] ?? 'AUSENTE'), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// confirm() es TODO-O-NADA
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== atomicidad de confirm() ===\n";

$pechugaAtomPre = Inventory::onHand(IT_PECHUGA, $outletId);
$batchRoto = $batches->create($companyId, $userId, [
    'outletId' => $outletId,
    'lines'    => [
        ['itemId' => IT_MILANESA, 'qty' => 1],
        ['itemId' => IT_SUELTO,   'qty' => 1],
    ],
]);

// Se le saca la receta al SEGUNDO plato DESPUÉS de crear el lote: `complete()`
// rechaza una orden cuyo ítem ya no tiene receta ("El item ya no tiene receta
// configurada"). Es un fallo a mitad de camino, con la primera línea ya
// procesada.
ncmExecute('DELETE FROM item_compound WHERE parentItemId = ?', [IT_SUELTO]);

$explotó = false;
try {
    $batches->confirm($companyId, $userId, $batchRoto);
} catch (\Throwable $e) {
    $explotó = true;
}
check('(F1) un lote con una línea que no se puede completar falla entero',
    $explotó, 'confirm() no lanzó', $failures, $checks);
check('(F2) y NO consumió el insumo de la línea que sí podía: todo-o-nada',
    near(Inventory::onHand(IT_PECHUGA, $outletId), $pechugaAtomPre),
    'pechuga = ' . Inventory::onHand(IT_PECHUGA, $outletId) . ", esperaba $pechugaAtomPre", $failures, $checks);

$rotoEstado = ncmExecute('SELECT status FROM production_batch WHERE batchid = ?', [$batchRoto]);
check('(F3) el lote sigue en draft, recuperable (no queda en un estado a medias)',
    (string) ($rotoEstado['status'] ?? '') === 'draft',
    json_encode($rotoEstado), $failures, $checks);

upsertRecipeLine(IT_SUELTO, IT_PECHUGA, 1, 0); // se restaura para el bloque siguiente

// ─────────────────────────────────────────────────────────────────────────────
// No-regresión: una orden SUELTA sigue funcionando igual
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== no-regresión: orden suelta (batchid NULL) ===\n";

$sueltaPre = Inventory::onHand(IT_PECHUGA, $outletId);
$orderId   = $orders->create($companyId, $userId, [
    'itemId'     => IT_SUELTO,
    'outletId'   => $outletId,
    'qtyPlanned' => 3,
    'mode'       => 'immediate',
    'qtyProduced' => 3,
]);
$suelta = $orders->find($companyId, $orderId);

// Mismo cuidado que en (E14): la clave TIENE que estar y valer null.
check('(G1) una orden creada sin lote expone batchId y vale null',
    array_key_exists('batchId', $suelta) && $suelta['batchId'] === null,
    'batchId = ' . json_encode($suelta['batchId'] ?? 'AUSENTE'), $failures, $checks);
check('(G2) y se completa igual que antes: consumió 3 de pechuga',
    ($suelta['status'] ?? '') === 'completed' && near(Inventory::onHand(IT_PECHUGA, $outletId), $sueltaPre - 3.0),
    'status = ' . json_encode($suelta['status'] ?? null) . ', pechuga = ' . Inventory::onHand(IT_PECHUGA, $outletId),
    $failures, $checks);

// Cancelación del lote en draft.
echo "\n=== cancelar ===\n";
$batches->cancel($companyId, $batchRoto);
$cancelado = $batches->find($companyId, $batchRoto);
check('(H1) cancelar el lote lo deja en cancelled',
    ($cancelado['status'] ?? '') === 'cancelled',
    'status = ' . json_encode($cancelado['status'] ?? null), $failures, $checks);
check('(H2) y arrastra a sus líneas pendientes',
    count(array_filter($cancelado['lines'] ?? [], static fn (array $l): bool => $l['status'] === 'cancelled')) === 2,
    json_encode(array_column($cancelado['lines'] ?? [], 'status')), $failures, $checks);
check('(H3) un lote ya confirmado no se puede cancelar',
    (static function () use ($batches, $companyId, $batchId): bool {
        try { $batches->cancel($companyId, $batchId); return false; }
        catch (\Throwable $e) { return true; }
    })(),
    'cancel() sobre un lote confirmado no lanzó', $failures, $checks);

// Aislamiento multi-tenant: un lote de otra compañía no se lee ni se toca.
check('(H4) el lote no se lee desde otro tenant',
    $batches->find('fa8cf679-9003-417e-8726-5b772d3b6e88', $batchId) === null,
    'find() con otro companyId devolvió algo', $failures, $checks);

harnessFinish($failures, $checks);
