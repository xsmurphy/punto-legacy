<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de integración (DB real) del CÓDIGO DE BARRAS propio del artículo
 * (`item.barcode`, mig 220 — separado del SKU).
 *
 * ── Qué se rompía antes ─────────────────────────────────────────────────────
 *
 * El scanner del POS matcheaba contra `sku` o `itemId` y nada más. El único
 * lugar donde el comercio podía cargar un código de barras era la matriz de
 * variantes, que lo escribía como `data->>'itemBarcode'` (clave suelta del
 * JSONB): ese dato no viajaba en el SELECT del catálogo, el POS nunca lo veía
 * y ningún escaneo podía pegar contra él. O sea: el código existía en la BD y
 * era invisible para la caja.
 *
 * ── Qué verifica, y por qué cada caso ───────────────────────────────────────
 *
 *   (a) La mig 220 aplicó: existe la columna Y el índice parcial. Sin el
 *       índice el escaneo es un seq scan sobre todo el catálogo del tenant.
 *   (b) El BACKFILL desde el JSONB legado funciona sobre datos reales — se
 *       re-ejecuta el ARCHIVO de la migración (es idempotente a propósito), no
 *       una copia del SQL: una copia se desincroniza del original y el arnés
 *       pasaría a verificar otra cosa. Además chequea que la clave vieja
 *       DESAPAREZCA: dos fuentes de verdad para el mismo dato terminan
 *       divergiendo, y `_flattenJsonb()` aplana `data` a top-level, así que
 *       `itemBarcode` seguiría apareciendo al lado de `barcode`.
 *   (c)/(d) Alta y edición por el camino REAL del panel (`ItemService::update`,
 *       el mismo que usan `POST` y `PUT /v1/items`) escriben la COLUMNA, no el
 *       JSONB. Incluye vaciar el campo: "" tiene que llegar a NULL, o el ítem
 *       queda con dos representaciones de "no tiene código".
 *   (e) El SELECT compartido del catálogo (`fetchItems()`) EXPONE `barcode`.
 *       Ese SELECT es el único que alimenta listado, bulk-get quirúrgico y
 *       delta de `/v1/sync?section=items`: si el campo sale por ahí, sale por
 *       los tres caminos, y el sync incremental arrastra la columna nueva sin
 *       tocar nada más.
 *   (f) La búsqueda encuentra por código de barras (el `q` del listado).
 *   (g) `VariantService` escribe la COLUMNA y vaciar la celda BORRA el código
 *       (antes el `if ($barcode !== null)` dejaba el valor viejo pegado para
 *       siempre).
 *   (h) Chequeo estático de que `api/v1/items.php` sumó `barcode` al `q`: el
 *       WHERE se arma inline en el endpoint, no en un service testeable.
 *
 * Uso (necesita Postgres migrado — Docker, ver run_item_barcode_test.sh):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/item_barcode_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/database/pg_pdo_connect.php';
// `ItemsQuery.php` declara FUNCIONES (fetchItems/presentItem) y las funciones
// no se autoloadean — mismo require que hacen items.php y SyncService.
require_once dirname(__DIR__) . '/lib/Items/ItemsQuery.php';

use Punto\Api\Items\ItemRepository;
use Punto\Api\Items\ItemService;
use Punto\Api\Items\VariantService;

use function Punto\Api\Items\fetchItems;

// `TODAY` la define `api/data.php` dentro de un request autenticado; los
// Services la usan para sellar `updated_at` (que es lo que mira el sync
// incremental). En CLI no existe — mismo preámbulo que el resto de los arneses
// que ejercitan Services de escritura.
if (!defined('TODAY')) define('TODAY', date('Y-m-d H:i:s'));

/** @var \Punto\Api\Database\Query $db */
global $db;

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

function _bcUuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** Columna + clave JSONB de un ítem, leídas CRUDAS (sin flatten: acá importa dónde vive el dato). */
function _bcRaw(string $itemId): array
{
    global $db;
    $rs = $db->Execute(
        "SELECT barcode, data->>'itemBarcode' AS legacy FROM item WHERE itemid = ?",
        [$itemId]
    );
    if ($rs === false || $rs->EOF) {
        return ['barcode' => '<no-row>', 'legacy' => '<no-row>'];
    }
    return [
        'barcode' => $rs->fields['barcode'],
        'legacy'  => $rs->fields['legacy'],
    ];
}

// Company/outlet del seed "Verify PY" (mismo criterio que items_tenant_isolation_test).
$companyA = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletA  = '1a282724-6073-49c3-8bc3-0114a132e349';

$itemLegacy  = _bcUuid();  // trae el código en el JSONB legado → lo promueve el backfill
$itemEmpty   = _bcUuid();  // trae la clave legada VACÍA → se limpia sin promover nada
$itemPanel   = _bcUuid();  // alta/edición por ItemService (camino del panel)
$parentId    = _bcUuid();  // padre de variantes
$created     = [];

// Tenant víctima independiente del seed: para el caso de aislamiento
// multi-tenant (i), donde un ítem de OTRA empresa comparte el mismo código de
// barras. Se crea entero (company + outlet) porque `item.companyid` es FK.
$companyB    = _bcUuid();
$outletB     = _bcUuid();
$itemBco     = _bcUuid();
// Código compartido a propósito con `$itemPanel` (company A) — el catálogo NO
// tiene UNIQUE sobre barcode, así que dos empresas pueden tener el mismo.
$sharedBarcode = '7501031311309';

$repo        = new ItemRepository($db);
$itemService = new ItemService($repo);

try {
    // ── (a) La migración 220 aplicó ───────────────────────────────────────────
    $colRs = $db->Execute(
        "SELECT data_type FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = 'item' AND column_name = 'barcode'"
    );
    check(
        '(a) existe la columna item.barcode',
        $colRs !== false && !$colRs->EOF,
        'information_schema no la encontró — ¿corrió la mig 220?',
        $failures,
        $checks
    );

    $idxRs = $db->Execute(
        "SELECT indexdef FROM pg_indexes
          WHERE schemaname = 'public' AND tablename = 'item' AND indexname = 'idx_item_barcode'"
    );
    $indexDef = ($idxRs !== false && !$idxRs->EOF) ? (string) $idxRs->fields['indexdef'] : '';
    check(
        '(a2) existe el índice parcial idx_item_barcode (companyid, barcode)',
        $indexDef !== ''
            && str_contains(strtolower($indexDef), 'companyid')
            && str_contains(strtolower($indexDef), 'barcode is not null'),
        'indexdef=' . var_export($indexDef, true),
        $failures,
        $checks
    );

    // ── (b) Backfill desde el JSONB legado ────────────────────────────────────
    // Fixtures con el dato en el lugar VIEJO, tal cual lo dejaba
    // VariantService antes de la mig 220.
    $db->Execute(
        "INSERT INTO item (itemid, itemname, companyid, data)
         VALUES (?, ?, ?, '{\"itemBarcode\":\"7790001112233\"}'::jsonb)",
        [$itemLegacy, 'BC legacy', $companyA]
    );
    $created[] = $itemLegacy;
    $db->Execute(
        "INSERT INTO item (itemid, itemname, companyid, data)
         VALUES (?, ?, ?, '{\"itemBarcode\":\"\"}'::jsonb)",
        [$itemEmpty, 'BC legacy vacio', $companyA]
    );
    $created[] = $itemEmpty;

    // Se re-ejecuta el ARCHIVO real de la migración (PDO directo: lleva
    // BEGIN/COMMIT multi-statement, igual que migrate.php). Es idempotente por
    // construcción — ADD COLUMN IF NOT EXISTS + UPDATE acotado + CREATE INDEX
    // IF NOT EXISTS — así que correrla de nuevo sobre una base ya migrada es
    // exactamente lo que hace el runner de migraciones al re-deployar.
    $migFile = dirname(__DIR__) . '/database/migrations/postgres/220_item_barcode.sql';
    $migSql  = (string) @file_get_contents($migFile);
    $migOk   = false;
    $migErr  = '';
    if ($migSql === '') {
        $migErr = "no se pudo leer $migFile";
    } else {
        try {
            $pdo = pgConnectFromEnv(dirname(dirname(__DIR__)));
            $pdo->exec($migSql);
            $migOk = true;
        } catch (\Throwable $e) {
            $migErr = $e->getMessage();
        }
    }
    check(
        '(b) la mig 220 vuelve a correr sin error (idempotente)',
        $migOk,
        $migErr,
        $failures,
        $checks
    );

    $rawLegacy = _bcRaw($itemLegacy);
    check(
        '(b2) el backfill promueve data.itemBarcode a la columna barcode',
        $rawLegacy['barcode'] === '7790001112233',
        'barcode=' . var_export($rawLegacy['barcode'], true),
        $failures,
        $checks
    );
    check(
        '(b3) el backfill BORRA la clave legada del JSONB (una sola fuente de verdad)',
        $rawLegacy['legacy'] === null,
        'data.itemBarcode quedó en ' . var_export($rawLegacy['legacy'], true),
        $failures,
        $checks
    );

    $rawEmpty = _bcRaw($itemEmpty);
    check(
        '(b4) la clave legada VACÍA se limpia sin inventar un código',
        $rawEmpty['barcode'] === null && $rawEmpty['legacy'] === null,
        'barcode=' . var_export($rawEmpty['barcode'], true) . ' legacy=' . var_export($rawEmpty['legacy'], true),
        $failures,
        $checks
    );

    // ── (c) Alta por el camino del panel ──────────────────────────────────────
    $db->Execute(
        'INSERT INTO item (itemid, itemname, companyid, itemstatus, itemcansale) VALUES (?, ?, ?, 1, TRUE)',
        [$itemPanel, 'BC panel', $companyA]
    );
    $created[] = $itemPanel;
    $db->Execute(
        'INSERT INTO item_outlet (itemid, outletid, companyid) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$itemPanel, $outletA, $companyA]
    );

    $itemService->update($itemPanel, $companyA, ['barcode' => '7501031311309']);
    $rawPanel = _bcRaw($itemPanel);
    check(
        '(c) ItemService::update escribe barcode en la COLUMNA, no en el JSONB',
        $rawPanel['barcode'] === '7501031311309' && $rawPanel['legacy'] === null,
        'barcode=' . var_export($rawPanel['barcode'], true) . ' data.itemBarcode=' . var_export($rawPanel['legacy'], true),
        $failures,
        $checks
    );

    // ── (d) Edición y vaciado ─────────────────────────────────────────────────
    $itemService->update($itemPanel, $companyA, ['barcode' => '  7501031311316  ']);
    check(
        '(d) la edición pisa el código anterior y recorta espacios',
        _bcRaw($itemPanel)['barcode'] === '7501031311316',
        'barcode=' . var_export(_bcRaw($itemPanel)['barcode'], true),
        $failures,
        $checks
    );

    $itemService->update($itemPanel, $companyA, ['barcode' => '']);
    check(
        '(d2) vaciar el campo guarda NULL, no una cadena vacía',
        _bcRaw($itemPanel)['barcode'] === null,
        'barcode=' . var_export(_bcRaw($itemPanel)['barcode'], true),
        $failures,
        $checks
    );

    // Se deja un código cargado para los casos de catálogo/búsqueda de abajo,
    // el MISMO que tendrá el ítem de la otra empresa en (i).
    $itemService->update($itemPanel, $companyA, ['barcode' => $sharedBarcode]);

    // ── (e) El SELECT compartido del catálogo lo expone ───────────────────────
    $rows = fetchItems($db, 'i.itemId = ? AND i.companyId = ?', [$itemPanel, $companyA]);
    $presented = $rows[0] ?? [];
    check(
        '(e) fetchItems()/presentItem() exponen `barcode` (listado + bulk-get + delta de sync)',
        array_key_exists('barcode', $presented) && $presented['barcode'] === '7501031311309',
        'presentado=' . var_export($presented['barcode'] ?? '<ausente>', true),
        $failures,
        $checks
    );

    // ── (f) Búsqueda por código de barras ─────────────────────────────────────
    // Mismo OR que arma `api/v1/items.php` para `?q=` (el chequeo de que el
    // endpoint lo tiene es el (h), estático).
    $hits = fetchItems(
        $db,
        'i.companyId = ? AND (i.itemName ILIKE ? OR i.itemSKU ILIKE ? OR i.barcode ILIKE ?)',
        [$companyA, '%031311309%', '%031311309%', '%031311309%']
    );
    $hitIds = array_column($hits, 'itemId');
    check(
        '(f) la búsqueda encuentra el ítem por su código de barras',
        in_array($itemPanel, $hitIds, true),
        'devolvió ' . count($hits) . ' filas, ninguna era el ítem buscado',
        $failures,
        $checks
    );

    // ── (g) VariantService escribe la columna ─────────────────────────────────
    $db->Execute(
        'INSERT INTO item (itemid, itemname, companyid, itemstatus, hasvariants, itemcansale)
         VALUES (?, ?, ?, 1, TRUE, TRUE)',
        [$parentId, 'BC padre', $companyA]
    );
    $created[] = $parentId;
    $db->Execute(
        'INSERT INTO item_outlet (itemid, outletid, companyid) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$parentId, $outletA, $companyA]
    );

    $variantSvc = new VariantService($db);
    $variantSvc->bulkUpsertVariants($companyA, $parentId, [[
        'sku'               => 'BC-VAR-1',
        'barcode'           => '7790001999999',
        'price'             => 1000,
        'cost'              => 500,
        'stock'             => 0,
        'variantAttributes' => ['Talle' => 'M'],
    ]]);
    $variants = $variantSvc->listVariants($companyA, $parentId);
    $variantId = (string) ($variants[0]['itemid'] ?? $variants[0]['itemId'] ?? '');
    if ($variantId !== '') $created[] = $variantId;

    $rawVariant = $variantId !== '' ? _bcRaw($variantId) : ['barcode' => '<sin-variante>', 'legacy' => null];
    check(
        '(g) VariantService escribe barcode en la COLUMNA (antes iba a data.itemBarcode)',
        $rawVariant['barcode'] === '7790001999999' && $rawVariant['legacy'] === null,
        'barcode=' . var_export($rawVariant['barcode'], true) . ' data.itemBarcode=' . var_export($rawVariant['legacy'], true),
        $failures,
        $checks
    );

    if ($variantId !== '') {
        $variantSvc->bulkUpsertVariants($companyA, $parentId, [[
            'itemId'            => $variantId,
            'sku'               => 'BC-VAR-1',
            'barcode'           => '',
            'price'             => 1000,
            'cost'              => 500,
            'stock'             => 0,
            'variantAttributes' => ['Talle' => 'M'],
        ]]);
    }
    check(
        '(g2) vaciar el código en la matriz de variantes lo BORRA (no deja el viejo pegado)',
        $variantId !== '' && _bcRaw($variantId)['barcode'] === null,
        'barcode=' . var_export($variantId !== '' ? _bcRaw($variantId)['barcode'] : '<sin-variante>', true),
        $failures,
        $checks
    );

    // ── (h) El endpoint sumó barcode al filtro `q` ────────────────────────────
    $src = (string) @file_get_contents(dirname(__DIR__) . '/v1/items.php');
    check(
        '(h) api/v1/items.php incluye barcode en el OR del filtro ?q=',
        str_contains($src, 'itemName ILIKE ? OR itemSKU ILIKE ? OR barcode ILIKE ?'),
        'el WHERE del listado no menciona barcode',
        $failures,
        $checks
    );
    // El WHERE se prefija con el alias `i.` por regex; si `barcode` no está en
    // esa lista, el COUNT y el SELECT rompen con "column barcode is ambiguous"
    // en cuanto el JOIN de taxonomy tenga una columna homónima.
    check(
        '(h2) el prefijador de alias conoce `barcode`',
        (bool) preg_match('/\\\\b\(companyId\|itemStatus\|itemName\|itemSKU\|barcode\|/', $src),
        'la regex que antepone `i.` no lista barcode',
        $failures,
        $checks
    );

    // ── (i) AISLAMIENTO multi-tenant de la búsqueda por barcode ───────────────
    // El código de barras NO es único entre empresas (no hay UNIQUE), así que
    // dos tenants pueden tener el MISMO. La búsqueda tiene que devolver solo el
    // ítem de la empresa que consulta — el aislamiento lo da el `i.companyId =
    // ?` del WHERE, no el barcode. Sin ese filtro, escanear en una caja del
    // tenant A traería (y podría vender) el artículo del tenant B: fuga
    // cross-tenant por un dato que el atacante ni siquiera necesita adivinar,
    // porque los códigos EAN son públicos y se repiten entre comercios.
    //
    // `$itemPanel` (company A) quedó con `$sharedBarcode` tras el caso (d).
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"BC Test B\"}'::jsonb)",
        [$companyB]
    );
    $db->Execute(
        'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
        [$outletB, 'BC Test B - Sucursal', $companyB]
    );
    $db->Execute(
        'INSERT INTO item (itemid, itemname, companyid, itemstatus, itemcansale, barcode)
         VALUES (?, ?, ?, 1, TRUE, ?)',
        [$itemBco, 'BC ítem de otra empresa', $companyB, $sharedBarcode]
    );

    $searchWhere = 'i.companyId = ? AND (i.itemName ILIKE ? OR i.itemSKU ILIKE ? OR i.barcode ILIKE ?)';
    $pat         = '%' . $sharedBarcode . '%';

    // A busca su propio código: encuentra SOLO su ítem, nunca el de B.
    $hitsA   = fetchItems($db, $searchWhere, [$companyA, $pat, $pat, $pat]);
    $hitAIds = array_column($hitsA, 'itemId');
    check(
        '(i) la búsqueda por barcode de la empresa A NO devuelve el ítem homónimo de B',
        in_array($itemPanel, $hitAIds, true) && !in_array($itemBco, $hitAIds, true),
        'A vio: ' . implode(',', $hitAIds) . ' (esperado incluir ' . $itemPanel . ' y excluir ' . $itemBco . ')',
        $failures,
        $checks
    );

    // Y el recíproco: B ve el suyo y no el de A.
    $hitsB   = fetchItems($db, $searchWhere, [$companyB, $pat, $pat, $pat]);
    $hitBIds = array_column($hitsB, 'itemId');
    check(
        '(i2) la búsqueda por barcode de la empresa B NO devuelve el ítem homónimo de A',
        in_array($itemBco, $hitBIds, true) && !in_array($itemPanel, $hitBIds, true),
        'B vio: ' . implode(',', $hitBIds) . ' (esperado incluir ' . $itemBco . ' y excluir ' . $itemPanel . ')',
        $failures,
        $checks
    );

} finally {
    // Company B y sus dependencias (item → outlet → company, respetando FKs).
    try { $db->Execute('DELETE FROM item WHERE itemid = ?', [$itemBco]); } catch (\Throwable) {}
    try { $db->Execute('DELETE FROM outlet WHERE outletId = ?', [$outletB]); } catch (\Throwable) {}
    try { $db->Execute('DELETE FROM company WHERE companyId = ?', [$companyB]); } catch (\Throwable) {}

    // Variantes primero (FK variantparentid → item).
    foreach (array_reverse($created) as $it) {
        try { $db->Execute('DELETE FROM item_outlet WHERE itemid = ?', [$it]); } catch (\Throwable) {}
        try { $db->Execute('DELETE FROM stock WHERE itemId = ?', [$it]); } catch (\Throwable) {}
        try { $db->Execute('DELETE FROM item WHERE itemid = ?', [$it]); } catch (\Throwable) {}
    }
}

harnessFinish($failures, $checks);
