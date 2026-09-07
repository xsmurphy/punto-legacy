<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) del SALDO DE STOCK EN EL PAYLOAD DEL POS
 * (2026-09-07, cierra el TODO `stock: null` de `lib/pos-bff/reshape.ts` y la
 * regla 7 de `context/modules/05-stock.md`).
 *
 * ── Qué se está probando ────────────────────────────────────────────────────
 *
 * El POS no muestra el saldo de un ítem porque el payload de `/v1/items` no lo
 * traía. El caño realtime ya existía de punta a punta —`manageStock()` publica
 * el evento, el POS repide por bulk-get y mergea (`context/15`)—, así que lo
 * único que faltaba era el número en la fila. Ahora lo pone `fetchItems()`
 * (`api/lib/Items/ItemsQuery.php`) con `Inventory::onHandFor()`, el lector
 * único del ledger (D2 de `context/52`).
 *
 * Lo que este arnés defiende, caso por caso:
 *
 *   (1) El saldo es el de LA SUCURSAL, no el consolidado del tenant. Es la
 *       diferencia que hace útil al número: una caja que ve el stock de toda
 *       la empresa promete mercadería que está en otra sucursal.
 *   (2) Un ítem SIN control de inventario viaja con `null`, no con 0. Un 0 lo
 *       pintaría "sin stock" en la búsqueda y el cajero dejaría de vender un
 *       servicio que siempre se puede vender.
 *   (3) El número ES el del ledger, no una segunda aritmética: se mueve stock
 *       por el camino REAL (`Inventory::manageStock()`, el único choke point)
 *       y el payload tiene que seguirlo exactamente.
 *   (4) Un movimiento BACKDATED (compra cargada con fecha de ayer) no lo
 *       desincroniza — el caso que rompe al snapshot `stock.stockOnHand` de la
 *       última fila y que motivó la mig 130 ("bug del salmón"). Si alguien
 *       cambiara `onHandFor()` por una lectura del snapshot, este caso lo
 *       agarra.
 *   (5) El PANEL sigue viendo el consolidado. El saldo por sucursal es opt-in
 *       del caller; el listado del panel no cambió de significado.
 *   (6) Los TRES caminos que bajan catálogo al POS —listado del bootstrap,
 *       bulk-get quirúrgico y delta de sync— dan el MISMO saldo. Es el
 *       invariante que hace confiable al número: si el bootstrap lo mostrara y
 *       el refresco quirúrgico lo borrara, el POS mentiría cada vez que se
 *       vende algo. El delta se ejercita VIVO (`SyncService::itemsDelta`), y
 *       además se verifica estáticamente que ningún camino se haya quedado con
 *       su propio `buildItemsSelectSql()` + loop a mano.
 *
 * ── Fixture ─────────────────────────────────────────────────────────────────
 * Tenant "Verify PY" (`api/lib/Sales/verify_chain/seed.sql`) + una SEGUNDA
 * sucursal efímera, que el seed no trae (la sucursal MX es de otro tenant y no
 * sirve para probar "dos sucursales de la misma empresa"). Todo lo que este
 * arnés crea lo borra en el `finally`.
 *
 * Uso (necesita Postgres migrado + seed.sql — ver run_pos_item_stock_payload_test.sh):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/pos_item_stock_payload_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla. La señal canónica
 * que lee el runner es la línea `HARNESS RESULT: … -> OK|FAIL`.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
// Funciones, no clases: no se autoloadean (mismo `require_once` que hacen
// `api/v1/items.php` y `api/v1/sync.php`).
require_once dirname(__DIR__) . '/lib/Items/ItemsQuery.php';

use Punto\Api\Sync\SyncService;
use Punto\App\Domain\Inventory;

use function Punto\Api\Items\fetchItems;
use function Punto\Api\Items\outletVisibilityClause;

// ── Tenant fixture "Verify PY" ──────────────────────────────────────────────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

$outletA     = $outletId;                              // sucursal del seed
$stockItemId = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f'; // itemTrackInventory = TRUE
$noTrackId   = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // itemTrackInventory = FALSE

// Sucursal efímera de ESTE arnés (misma empresa que $outletA).
$outletB = '9f4e1c7a-2b5d-4a86-9e13-7c0d5b8a4f21';

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

/**
 * El `stockOnHand` que le llegaría al POS para un ítem, por el camino que
 * corresponda.
 *
 * `$outlet = null` reproduce al PANEL (sin alcance de sucursal); con outlet
 * reproduce a una CAJA. El WHERE es el mismo que arma el bulk-get de
 * `api/v1/items.php` para un id puntual, incluida la cláusula de visibilidad
 * por sucursal — así lo que se mide es el payload real y no una query de
 * laboratorio.
 *
 * Devuelve `false` si el ítem no vino en el resultado (no visible para esa
 * sucursal), que es distinto de haber venido con saldo `null`.
 */
function payloadOnHand(string $companyId, string $itemId, ?string $outlet): mixed
{
    global $db;

    $whereSql    = 'i.companyId = ? AND i.itemId IN (?)';
    $whereParams = [$companyId, $itemId];
    [$clause, $clauseParams] = outletVisibilityClause($outlet);
    if ($clause !== '') {
        $whereSql   .= " AND {$clause}";
        $whereParams = array_merge($whereParams, $clauseParams);
    }

    $rows = fetchItems($db, $whereSql, $whereParams, '', $outlet);
    if ($rows === []) {
        return false;
    }

    return $rows[0]['stockOnHand'];
}

/** Movimiento de stock por el camino REAL — el único choke point del ledger. */
function moveStock(string $companyId, string $itemId, string $outlet, string $type, float $count, string $date): void
{
    Inventory::manageStock([
        'itemId'        => $itemId,
        'source'        => 'adjustment',
        'count'         => $count,
        'type'          => $type,
        'cogs'          => 1000,
        'transactionId' => '',
        'outletId'      => $outlet,
        'locationId'    => '',
        'date'          => $date,
        'companyId'     => $companyId,
    ]);
}

/** ¿Un archivo contiene este fragmento? (chequeo estático de invariante). */
function fileHas(string $relPath, string $needle): bool
{
    $full = dirname(__DIR__) . '/' . ltrim($relPath, '/');
    $src  = @file_get_contents($full);
    return $src !== false && str_contains($src, $needle);
}

echo "=== Saldo de stock en el payload del POS ===\n\n";

try {
    // ── Fixture: segunda sucursal de la MISMA empresa ───────────────────────
    // El seed solo trae una sucursal por tenant, y sin dos no se puede
    // distinguir "saldo de esta sucursal" de "saldo de la empresa" — que es
    // justamente el caso (1).
    $db->Execute(
        'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
         ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName',
        [$outletB, 'Verify PY - Sucursal B (arnés)', $companyId]
    );
    // Bajo el modelo N-a-N (mig 170) un ítem sin fila en `item_outlet` es
    // INVISIBLE para esa sucursal: sin esto el payload de B vendría vacío y el
    // arnés mediría otra cosa.
    foreach ([$stockItemId, $noTrackId] as $fixtureItem) {
        $db->Execute(
            'INSERT INTO item_outlet (itemid, outletid, companyid) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
            [$fixtureItem, $outletB, $companyId]
        );
    }

    // ── Caso 1: el saldo es el de LA SUCURSAL, no el consolidado ────────────
    // Se parte de los saldos que haya (el seed y otros arneses pudieron dejar
    // movimientos) y se suman deltas conocidos: lo que se afirma es la
    // RELACIÓN con el ledger, no un número absoluto frágil.
    $baseA = Inventory::onHandFor([$stockItemId], $outletA)[$stockItemId] ?? 0.0;
    $baseB = Inventory::onHandFor([$stockItemId], $outletB)[$stockItemId] ?? 0.0;

    moveStock($companyId, $stockItemId, $outletA, '+', 10.0, date('Y-m-d H:i:s'));
    moveStock($companyId, $stockItemId, $outletB, '+', 3.0, date('Y-m-d H:i:s'));

    $payloadA = payloadOnHand($companyId, $stockItemId, $outletA);
    $payloadB = payloadOnHand($companyId, $stockItemId, $outletB);

    check(
        '1a. La caja de la sucursal A recibe el saldo de A',
        is_numeric($payloadA) && abs((float) $payloadA - ($baseA + 10.0)) < 0.0001,
        "esperado " . ($baseA + 10.0) . ", recibido " . var_export($payloadA, true),
        $failures,
        $checks
    );
    check(
        '1b. La caja de la sucursal B recibe el saldo de B',
        is_numeric($payloadB) && abs((float) $payloadB - ($baseB + 3.0)) < 0.0001,
        "esperado " . ($baseB + 3.0) . ", recibido " . var_export($payloadB, true),
        $failures,
        $checks
    );
    check(
        '1c. Los dos saldos son DISTINTOS (no es el consolidado disfrazado)',
        is_numeric($payloadA) && is_numeric($payloadB) && abs((float) $payloadA - (float) $payloadB) > 0.0001,
        "A={$payloadA} B={$payloadB} — si son iguales, alguien está mandando el total del tenant",
        $failures,
        $checks
    );

    // ── Caso 2: ítem sin control de inventario → null, nunca 0 ──────────────
    $payloadNoTrack = payloadOnHand($companyId, $noTrackId, $outletA);
    check(
        '2. Un ítem sin control de stock viaja con null (no con 0)',
        $payloadNoTrack === null,
        'recibido ' . var_export($payloadNoTrack, true) . ' — un 0 lo pintaría "sin stock" en la búsqueda',
        $failures,
        $checks
    );

    // ── Caso 3: el payload SIGUE al ledger ──────────────────────────────────
    // Se mueve stock por el camino real y el número tiene que cambiar
    // exactamente por el delta, en la sucursal correcta y solo en ella.
    moveStock($companyId, $stockItemId, $outletA, '-', 4.0, date('Y-m-d H:i:s'));

    $payloadA2 = payloadOnHand($companyId, $stockItemId, $outletA);
    $payloadB2 = payloadOnHand($companyId, $stockItemId, $outletB);

    check(
        '3a. Un egreso en A baja el saldo de A exactamente por el delta',
        is_numeric($payloadA2) && abs((float) $payloadA2 - ($baseA + 6.0)) < 0.0001,
        "esperado " . ($baseA + 6.0) . ", recibido " . var_export($payloadA2, true),
        $failures,
        $checks
    );
    check(
        '3b. Ese egreso NO toca el saldo de B',
        is_numeric($payloadB2) && abs((float) $payloadB2 - ($baseB + 3.0)) < 0.0001,
        "esperado " . ($baseB + 3.0) . ", recibido " . var_export($payloadB2, true),
        $failures,
        $checks
    );
    check(
        '3c. El payload coincide con `Inventory::onHandFor()` (definición única del saldo)',
        is_numeric($payloadA2)
            && abs((float) $payloadA2 - (Inventory::onHandFor([$stockItemId], $outletA)[$stockItemId] ?? 0.0)) < 0.0001,
        'el payload y el lector canónico del ledger discrepan — hay una segunda aritmética de saldo',
        $failures,
        $checks
    );

    // ── Caso 4: movimiento BACKDATED ────────────────────────────────────────
    // Una compra cargada con fecha de ayer entra ANTES de la última fila del
    // ledger. El snapshot `stock.stockOnHand` de la última fila queda viejo;
    // la SUMA no. Si este caso falla, alguien cambió el lector por el snapshot.
    moveStock($companyId, $stockItemId, $outletA, '+', 7.0, date('Y-m-d H:i:s', strtotime('-3 days')));

    $payloadA3 = payloadOnHand($companyId, $stockItemId, $outletA);
    check(
        '4. Un movimiento con fecha retroactiva se refleja igual (SUM, no snapshot)',
        is_numeric($payloadA3) && abs((float) $payloadA3 - ($baseA + 13.0)) < 0.0001,
        "esperado " . ($baseA + 13.0) . ", recibido " . var_export($payloadA3, true),
        $failures,
        $checks
    );

    // ── Caso 5: el PANEL sigue viendo el consolidado ─────────────────────────
    $payloadPanel  = payloadOnHand($companyId, $stockItemId, null);
    $consolidado   = ($baseA + 13.0) + ($baseB + 3.0);
    check(
        '5. Sin alcance de sucursal (panel) el saldo sigue siendo el consolidado del tenant',
        is_numeric($payloadPanel) && abs((float) $payloadPanel - $consolidado) < 0.0001,
        "esperado {$consolidado}, recibido " . var_export($payloadPanel, true)
            . ' — el listado del panel no debe cambiar de significado',
        $failures,
        $checks
    );

    // ── Caso 6: los tres caminos coinciden ──────────────────────────────────
    // El delta de sync, VIVO: es el camino por el que un device que estuvo
    // offline vuelve a poblar su cache. `since` bien atrás para que el ítem
    // caiga seguro dentro de la ventana.
    $sync  = new SyncService($db);
    $delta = $sync->itemsDelta($companyId, date('Y-m-d H:i:s', strtotime('-1 day')), $outletA);

    $deltaRow = null;
    foreach (($delta['items'] ?? []) as $row) {
        if (($row['itemId'] ?? '') === $stockItemId) {
            $deltaRow = $row;
            break;
        }
    }

    check(
        '6a. El delta de sync trae el ítem con saldo (no viene sin el campo)',
        $deltaRow !== null && array_key_exists('stockOnHand', $deltaRow),
        $deltaRow === null
            ? 'el ítem no vino en el delta — revisar la ventana o la visibilidad por sucursal'
            : 'el ítem vino sin `stockOnHand`',
        $failures,
        $checks
    );
    check(
        '6b. El delta de sync da el MISMO saldo que el bootstrap/bulk-get',
        $deltaRow !== null
            && is_numeric($deltaRow['stockOnHand'] ?? null)
            && abs((float) $deltaRow['stockOnHand'] - (float) $payloadA3) < 0.0001,
        'el delta y el listado difieren: el POS mostraría un número distinto según por dónde llegó',
        $failures,
        $checks
    );

    // Estático: que los tres caminos sigan pasando por el mismo embudo. Un
    // camino nuevo que se arme su propio SELECT + loop reintroduce la
    // divergencia sin romper ningún caso de arriba.
    check(
        '6c. El bulk-get y el listado de /v1/items usan `fetchItems()`',
        substr_count((string) @file_get_contents(dirname(__DIR__) . '/v1/items.php'), 'fetchItems(') >= 2,
        'algún camino de /v1/items volvió a armar su propio SELECT + presentItem a mano',
        $failures,
        $checks
    );
    check(
        '6d. El delta de sync usa `fetchItems()`',
        fileHas('lib/Sync/SyncService.php', 'fetchItems('),
        'SyncService::itemsDelta dejó de pasar por el embudo compartido',
        $failures,
        $checks
    );
    check(
        '6e. El saldo sale de `Inventory::onHandFor()` y no de una copia del SQL',
        fileHas('lib/Items/ItemsQuery.php', 'Inventory::onHandFor('),
        'apareció una segunda aritmética de saldo en ItemsQuery.php (D2 de context/52)',
        $failures,
        $checks
    );
} finally {
    // ── Limpieza ────────────────────────────────────────────────────────────
    // Se borra íntegra la sucursal efímera (sus movimientos, su pertenencia de
    // ítems y la fila `outlet`). Los movimientos que el arnés dejó en la
    // sucursal A del seed NO se borran: `manageStock()` no devuelve el id de la
    // fila que escribió, y borrar "los del día" por fecha barrería también los
    // de otros arneses contra la misma base. No hace falta: cada caso mide
    // contra el saldo leído AL EMPEZAR ESTA CORRIDA (`$baseA`/`$baseB`), así
    // que el arnés es idempotente aunque quede saldo acumulado de corridas
    // anteriores.
    $db->Execute('DELETE FROM stock WHERE outletId = ?', [$outletB]);
    $db->Execute('DELETE FROM item_outlet WHERE outletid = ?', [$outletB]);
    $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$outletB]);
}

harnessFinish($failures, $checks);
