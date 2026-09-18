<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración de la ALERTA DE MARGEN de compras (Postgres real).
 *
 * Qué protege:
 *   (A) EL AJUSTE VIAJA: `marginTarget` se guarda en `settingObj` por
 *       `SettingsService::updateGeneral()`, se lee por `general()` y vaciarlo
 *       lo borra (alerta apagada) sin tocar el resto de `settingObj`.
 *   (B) LA COMPRA DEVUELVE LA ALERTA: `PurchasesService::create()` deja en
 *       `marginAlerts()` los artículos cuyo costo SUBIÓ y quedaron bajo el
 *       objetivo, con costo nuevo, precio, margen y sugerido.
 *   (C) LOS PRODUCTOS POR RECETA ENTRAN: un plato de producción directa que
 *       usa el insumo comprado se costea en vivo por `RecipeCosting`, así que
 *       la compra le cambió el costo y aparece en la alerta. La subida sigue
 *       por un combo que contiene al plato.
 *   (D) UN PADRE CON STOCK PROPIO CORTA: el semielaborado de producción previa
 *       tiene el costo de SU ledger, que la compra del insumo no tocó.
 *   (E) SI EL COSTO BAJA NO HAY ALERTA, aunque el margen siga bajo.
 *   (F) PRECIO CERO NO ALERTA.
 *   (G) OBJETIVO VACÍO = SIN ALERTA.
 *
 * Uso: bash api/tests/run_margin_alert_test.sh
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Items\MarginAlertService;
use Punto\Api\Purchases\PurchasesService;
use Punto\Api\Settings\SettingsService;
use Punto\App\Domain\Inventory;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$roleId     = '1';
require API_APP_DIR . '/data.php';

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

function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.01;
}

/** UUID v4 nuevo por corrida: el arnés puede correr dos veces contra la misma base. */
function uuid(): string
{
    $b    = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function upsertItem(string $id, string $name, float $price, float $cost, bool $tracked, bool $production, string $type, string $kind): void
{
    ncmExecute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus,
                           itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind)
         VALUES (?, ?, ?, ?, ?, ?, 1, TRUE, ?, ?, '{}'::jsonb, ?, ?)",
        [$id, $name, 'MA-' . substr($id, 0, 8), $price, $cost, $type, $tracked, $production, COMPANY_ID, $kind]
    );
}

function recipeLine(string $parentId, string $childId, float $qty): void
{
    ncmExecute(
        'INSERT INTO item_compound (parentItemId, childItemId, quantity, sort, companyId) VALUES (?, ?, ?, 0, ?)',
        [$parentId, $childId, $qty, COMPANY_ID]
    );
}

/** @return array<string,array<string,mixed>> alertas indexadas por itemId */
function buy(PurchasesService $svc, string $itemId, float $units, float $unitPrice, string $at): array
{
    global $companyId, $outletId, $userId;
    $svc->create($companyId, [
        'outletId'    => $outletId,
        'userId'      => $userId,
        'condition'   => 'cash',
        'invoiceDate' => $at,
        'items'       => [['itemId' => $itemId, 'units' => $units, 'price' => $unitPrice]],
    ]);
    $out = [];
    foreach ($svc->marginAlerts() as $row) {
        $out[$row['itemId']] = $row;
    }
    return $out;
}

$settings = new SettingsService();
$svc      = new PurchasesService();
$today    = date('Y-m-d');

// ── Fixture ──────────────────────────────────────────────────────────────────
$INSUMO = uuid(); // se vende y se usa en recetas; lleva stock
$PLATO  = uuid(); // producción directa: 2 × insumo
$COMBO  = uuid(); // combo: 1 × plato
$SEMI   = uuid(); // producción previa con stock propio: 1 × insumo
$GRATIS = uuid(); // lleva stock, precio 0

upsertItem($INSUMO, 'MA insumo', 10000, 0, true, false, 'product', 'insumo_stock');
upsertItem($PLATO, 'MA plato', 12000, 0, false, false, 'product', 'produccion_directa');
upsertItem($COMBO, 'MA combo', 30000, 0, false, false, 'combo', 'combo_fijo');
upsertItem($SEMI, 'MA semi', 3000, 0, true, true, 'product', 'produccion_previa');
upsertItem($GRATIS, 'MA sin precio', 0, 0, true, false, 'product', 'insumo_stock');
recipeLine($PLATO, $INSUMO, 2);
recipeLine($COMBO, $PLATO, 1);
recipeLine($SEMI, $INSUMO, 1);

// El semielaborado ya tiene su propio costo en el ledger (se produjo antes).
Inventory::manageStock([
    'itemId' => $SEMI, 'outletId' => $outletId, 'cogs' => 2900, 'count' => 5,
    'source' => 'production', 'userId' => $userId, 'companyId' => $companyId,
    'date' => "$today 00:00:01",
]);

// ── (A) El ajuste ────────────────────────────────────────────────────────────
$settings->updateGeneral($companyId, ['ignoreInternal' => 1]);
$settings->updateGeneral($companyId, ['marginTarget' => '30']);
$g = $settings->general($companyId);
check('(A) el objetivo se guarda y se lee', near($g['marginTarget'] ?? null, 30.0), json_encode($g['marginTarget'] ?? null), $failures, $checks);
check('(A) guardar el objetivo no pisa los flags de settingObj', ($g['ignoreInternal'] ?? null) === true, json_encode($g['ignoreInternal'] ?? null), $failures, $checks);
check('(A) el servicio lee el mismo objetivo', near((new MarginAlertService())->target($companyId), 30.0), '', $failures, $checks);

// ── (C)(D) Qué artículos afecta la compra del insumo ─────────────────────────
$snap = (new MarginAlertService())->snapshot($companyId, $outletId, [$INSUMO]);
check('(C) el plato por receta está entre los afectados', isset($snap[$PLATO]), json_encode(array_keys($snap)), $failures, $checks);
check('(C) el combo que contiene al plato también', isset($snap[$COMBO]), json_encode(array_keys($snap)), $failures, $checks);
check('(D) el semielaborado con stock propio NO', !isset($snap[$SEMI]), json_encode(array_keys($snap)), $failures, $checks);

// ── (B)(C) Primera compra: 10 × 5.000 ────────────────────────────────────────
// Insumo: costo 5.000, precio 10.000 → 50% (≥ 30, sin alerta).
// Plato: 2 × 5.000 = 10.000, precio 12.000 → 16,7% → alerta, sugerido
// 10.000 / 0,7 = 14.285,7 → 14.300. Combo: 10.000 / 30.000 → 66,7%.
$a = buy($svc, $INSUMO, 10, 5000, "$today 00:00:02");
check('(B) primera compra: el insumo con buen margen no alerta', !isset($a[$INSUMO]), json_encode(array_keys($a)), $failures, $checks);
check('(C) primera compra: el plato alerta', isset($a[$PLATO]), json_encode($a), $failures, $checks);
check('(C) plato: costo nuevo por receta', near($a[$PLATO]['cost'] ?? null, 10000.0), json_encode($a[$PLATO] ?? null), $failures, $checks);
check('(C) plato: margen actual', near($a[$PLATO]['marginPct'] ?? null, 16.7), json_encode($a[$PLATO] ?? null), $failures, $checks);
check('(C) plato: sugerido redondeado hacia arriba', near($a[$PLATO]['suggestedPrice'] ?? null, 14300.0), json_encode($a[$PLATO] ?? null), $failures, $checks);
check('(C) el combo con buen margen no alerta', !isset($a[$COMBO]), json_encode(array_keys($a)), $failures, $checks);
check('(D) el semielaborado no alerta', !isset($a[$SEMI]), json_encode(array_keys($a)), $failures, $checks);

// ── (B) Segunda compra más cara: 10 × 11.000 → promedio 8.000 ────────────────
$b = buy($svc, $INSUMO, 10, 11000, "$today 00:00:03");
check('(B) el insumo que subió a 8.000 alerta', isset($b[$INSUMO]), json_encode(array_keys($b)), $failures, $checks);
check('(B) insumo: costo nuevo = promedio ponderado', near($b[$INSUMO]['cost'] ?? null, 8000.0), json_encode($b[$INSUMO] ?? null), $failures, $checks);
check('(B) insumo: precio actual', near($b[$INSUMO]['price'] ?? null, 10000.0), json_encode($b[$INSUMO] ?? null), $failures, $checks);
check('(B) insumo: margen 20%', near($b[$INSUMO]['marginPct'] ?? null, 20.0), json_encode($b[$INSUMO] ?? null), $failures, $checks);
check('(B) insumo: sugerido 8.000 / 0,7 → 11.500', near($b[$INSUMO]['suggestedPrice'] ?? null, 11500.0), json_encode($b[$INSUMO] ?? null), $failures, $checks);
check('(C) plato: 16.000 contra 12.000 → sugerido 22.900', near($b[$PLATO]['suggestedPrice'] ?? null, 22900.0), json_encode($b[$PLATO] ?? null), $failures, $checks);

// ── (E) Compra más barata: el costo baja, no se vuelve a avisar ──────────────
$c = buy($svc, $INSUMO, 5, 1000, "$today 00:00:04");
check('(E) si el costo baja no hay alerta', $c === [], json_encode($c), $failures, $checks);

// ── (F) Precio cero ──────────────────────────────────────────────────────────
$f = buy($svc, $GRATIS, 3, 4000, "$today 00:00:05");
check('(F) un artículo sin precio no alerta', $f === [], json_encode($f), $failures, $checks);

// ── (G) Objetivo vacío ───────────────────────────────────────────────────────
$settings->updateGeneral($companyId, ['marginTarget' => '']);
$g = $settings->general($companyId);
check('(G) vaciar el objetivo apaga la alerta', array_key_exists('marginTarget', $g) && $g['marginTarget'] === null, json_encode($g['marginTarget'] ?? 'ausente'), $failures, $checks);
check('(G) vaciar el objetivo no pisa los flags', ($g['ignoreInternal'] ?? null) === true, '', $failures, $checks);
$h = buy($svc, $INSUMO, 10, 50000, "$today 00:00:06");
check('(G) sin objetivo, una compra carísima no alerta', $h === [], json_encode($h), $failures, $checks);

echo "\n";
harnessFinish($failures, $checks);
