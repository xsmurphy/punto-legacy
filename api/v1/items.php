<?php
/**
 * REST canónico (API compartida /api) — Items (catálogo del tenant).
 *
 * Endpoint MULTI-REALM con dos personalidades coexistiendo:
 *
 *   ── Panel CRUD (rama F2 / Slice A) ───────────────────────────────────────
 *   GET    /v1/items                              → lista paginada (q, kind, archived, limit, offset)
 *   GET    /v1/items?id=<uuid>                    → detalle + categories[]
 *   GET    /v1/items?id=<uuid>&resource=locations → depósitos asignados
 *   POST   /v1/items                              → crea blank (body: { kind? } + extras opcionales)
 *   PUT    /v1/items?id=<uuid>                    → update parcial (kind→ 409 si cambia)
 *   PUT    /v1/items?id=<uuid>&resource=categories body: { categories:[{id,isPrimary}] }
 *   PUT    /v1/items?id=<uuid>&resource=locations  body: { locationIds:[], default? }
 *   DELETE /v1/items?id=<uuid>                    → archive (soft-delete)
 *
 *   ── POS info (slice 25 del desacople de /app) ─────────────────────────────
 *   GET    /v1/items?id=<itemId>&resource=core      → campos del ítem (BFF compone)
 *   GET    /v1/items?id=<itemId>&resource=inventory → stock por outlet
 *   GET    /v1/items?id=<itemId>&resource=info      → detalle composite (legacy)
 *
 * Auth: MULTI-REALM — `apiAuthTenant(['panel','pos-app'])`. Items son recursos
 * compartidos: el panel los administra, el POS los consulta en la caja.
 *
 * Servicios:
 *   - `Punto\Api\Items\*`             (panel CRUD: F2 port de panel/lib/items/)
 *   - `Punto\Api\Services\ItemService` (POS info: slice 25 existente, intacto)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/services/ItemService.php';

use Punto\Api\Context\TenantContext;

/**
 * Mapea una fila de `item` (lowercase desde PG + JSONB flatten) a camelCase
 * canonical para el front. Expone `kind` (desde `itemKind`), `categories[]`,
 * y `tags[]` (desde JSONB data.tags).
 */
function presentItem(array|\CaseInsensitiveArray $row): array
{
    $map = [
        'itemid'              => 'itemId',
        'itemname'            => 'itemName',
        'itemsku'             => 'itemSKU',
        'itemcost'            => 'itemCost',
        'itemprice'           => 'itemPrice',
        'itemisparent'        => 'itemIsParent',
        'itemparentid'        => 'itemParentId',
        'itemtype'            => 'itemType',
        'itemkind'            => 'kind',
        'itemimage'           => 'itemImage',
        'itemstatus'          => 'itemStatus',
        'itemtrackinventory'  => 'itemTrackInventory',
        'itemcansale'         => 'itemCanSale',
        'itemtaxexcluded'     => 'itemTaxExcluded',
        'itemdiscount'        => 'itemDiscount',
        'itemuom'             => 'itemUOM',
        'itemsort'            => 'itemSort',
        'itemproduction'      => 'itemProduction',
        'itemtaxincluded'     => 'itemTaxIncluded',
        'itemdescription'     => 'itemDescription',
        'itemdate'            => 'itemDate',
        'taxid'               => 'taxId',
        'brandid'             => 'brandId',
        'categoryid'          => 'categoryId',
        'supplierid'          => 'supplierId',
        'locationid'          => 'locationId',
        'outletid'            => 'outletId',
        'companyid'           => 'companyId',
        'categoryname'        => 'categoryName',
        'brandname'           => 'brandName',
        'outletname'          => 'outletName',
        'coverimageurl'       => 'coverImageUrl',
        'childcount'          => 'childCount',
        'variantparentid'     => 'variantParentId',
        'hasvariants'         => 'hasVariants',
        'variantattributes'   => 'variantAttributes',
        'variantcount'        => 'variantCount',
    ];
    $out = [];
    foreach ($row as $k => $v) {
        $kLower = strtolower((string) $k);
        $key = $map[$kLower] ?? $k;
        $out[$key] = $v;
    }
    // Normalizar booleanos: PG con PDO puede devolver 't'/'f' string en vez de
    // bool dependiendo del driver/version. Forzamos bool real para que el
    // frontend no tenga que adivinar.
    foreach (['itemIsParent', 'itemTrackInventory', 'itemCanSale', 'itemProduction', 'itemTaxIncluded', 'itemEcom', 'itemFeatured', 'itemImage', 'hasVariants'] as $boolKey) {
        if (array_key_exists($boolKey, $out)) {
            $v = $out[$boolKey];
            if (is_string($v)) {
                $out[$boolKey] = ($v === 't' || $v === 'true' || $v === '1');
            } elseif (is_int($v)) {
                $out[$boolKey] = $v > 0;
            } elseif (is_bool($v)) {
                // ya está bien
            } elseif ($v === null) {
                $out[$boolKey] = false;
            }
        }
    }
    // childCount como int (PG devuelve string para COUNT en algunos drivers).
    if (isset($out['childCount'])) {
        $out['childCount'] = (int) $out['childCount'];
    }
    // tags desde JSONB (data.tags es array o null)
    if (!isset($out['tags'])) {
        $rawTags = $out['tags'] ?? null;
        $out['tags'] = is_array($rawTags) ? $rawTags : [];
    }
    return $out;
}

/**
 * Devuelve el array de categorías (id + isPrimary) de un item
 * desde item_category. Usado solo en el GET detalle.
 */
function fetchItemCategories(string $itemId): array
{
    global $db;
    $rs = $db->Execute(
        'SELECT ic.categoryId, ic.isPrimary, t.taxonomyName AS name
           FROM item_category ic
           JOIN taxonomy t ON t.taxonomyId = ic.categoryId
          WHERE ic.itemId = ?
          ORDER BY ic.isPrimary DESC, t.taxonomyName',
        [$itemId]
    );
    if ($rs === false) return [];
    $cats = [];
    foreach ($rs->GetRows() as $r) {
        $cats[] = [
            'id'        => $r['categoryid'] ?? $r['categoryId'],
            'name'      => $r['taxonomyname'] ?? $r['name'],
            'isPrimary' => (bool) ($r['isprimary'] ?? $r['isPrimary'] ?? false),
        ];
    }
    return $cats;
}

/**
 * Devuelve el array de marcas (id + name + isPrimary) de un item desde item_brand.
 */
function fetchItemBrands(string $itemId): array
{
    global $db;
    $rs = $db->Execute(
        'SELECT ib.brandId, ib.isPrimary, b.name
           FROM item_brand ib
           JOIN brand b ON b.brandId = ib.brandId
          WHERE ib.itemId = ?
          ORDER BY ib.isPrimary DESC, b.name',
        [$itemId]
    );
    if ($rs === false) return [];
    $out = [];
    foreach ($rs->GetRows() as $r) {
        $out[] = [
            'id'        => $r['brandid'] ?? $r['brandId'],
            'name'      => $r['name'] ?? '',
            'isPrimary' => (bool) ($r['isprimary'] ?? $r['isPrimary'] ?? false),
        ];
    }
    return $out;
}

/**
 * Devuelve el array de etiquetas (id + name) de un item desde item_tag.
 * Sin isPrimary — todas las etiquetas son equivalentes.
 */
function fetchItemTags(string $itemId): array
{
    global $db;
    $rs = $db->Execute(
        'SELECT it.tagId, t.name
           FROM item_tag it
           JOIN tag t ON t.tagId = it.tagId
          WHERE it.itemId = ?
          ORDER BY t.name',
        [$itemId]
    );
    if ($rs === false) return [];
    $out = [];
    foreach ($rs->GetRows() as $r) {
        $out[] = [
            'id'   => $r['tagid'] ?? $r['tagId'],
            'name' => $r['name'] ?? '',
        ];
    }
    return $out;
}

/**
 * Mapea un itemKind a los flags legacy (dual-write).
 * El POS y el panel legacy siguen leyendo los flags viejos hasta Slice E.
 */
function kindToLegacyFlags(string $kind): array
{
    $map = [
        'producto'           => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 0],
        'insumo_stock'       => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 1, 'itemProduction' => 0],
        'insumo_sin_stock'   => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'insumo_control'     => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 1, 'itemProduction' => 0],
        'produccion_directa' => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'produccion_previa'  => ['itemType' => 'production', 'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 1],
        'servicio'           => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'servicio_sesiones'  => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'combo_fijo'         => ['itemType' => 'combo',      'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'combo_dinamico'     => ['itemType' => 'combo',      'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'descuento'          => ['itemType' => 'discount',   'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'giftcard'           => ['itemType' => 'giftcard',   'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 0],
    ];
    return $map[$kind] ?? [];
}

const VALID_KINDS = [
    'producto', 'insumo_stock', 'insumo_sin_stock', 'insumo_control',
    'produccion_directa', 'produccion_previa',
    'servicio', 'servicio_sesiones',
    'combo_fijo', 'combo_dinamico',
    'descuento', 'giftcard',
];

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

global $db;

// ── Rama POS info (slice 25): resource=core|inventory|info ────────────────
// id viene cifrado (enc/dec) en estos recursos por compat con /app legacy.
if (in_array($resource, ['core', 'inventory', 'info'], true)) {
    $posSvc = new \Punto\Api\Services\ItemService(TenantContext::fromAuth($ctx));
    $itemId = trim((string) dec($_GET['id'] ?? ''));

    if ($method !== 'GET') {
        apiError('Method not allowed for /items resource=' . $resource, 405);
    }
    if ($itemId === '') {
        apiError('Falta id', 422);
    }

    if ($resource === 'core') {
        $data = $posSvc->getCore($itemId, $companyId);
        if ($data === null) apiError('Ítem no encontrado', 404);
        apiOk($data);
    }
    if ($resource === 'inventory') {
        // Devuelve `[]` si el ítem no trackea / no existe — la clave `inventory` del front.
        apiOk(['inventory' => $posSvc->getInventory($itemId, $companyId)]);
    }
    // resource === 'info'
    $data = $posSvc->getInfo($itemId, $companyId);
    if ($data === null) apiError('Ítem no encontrado', 404);
    apiOk($data);
}

// ── Último precio de compra del item ───────────────────────────────────────
// GET /v1/items?id=<itemId>&resource=last-purchase-price
//   → { price: number }   (0 si nunca se compró)
// Usado en el form de /purchase para autorrellenar el precio cuando el
// cajero selecciona un ítem.
if ($resource === 'last-purchase-price') {
    if ($method !== 'GET') {
        apiError('Method not allowed for /items resource=last-purchase-price', 405);
    }
    $itemId = trim((string) ($_GET['id'] ?? ''));
    if ($itemId === '') {
        apiError('Falta id', 422);
    }
    // transactionType = 1 → compras (ver PurchasesService).
    // Precio unitario = total / units. NULLIF evita división por cero.
    $row = ncmExecute(
        "SELECT (s.itemSoldTotal / NULLIF(s.itemSoldUnits, 0)) AS price
           FROM itemSold s
           JOIN transaction t ON s.transactionId = t.transactionId
          WHERE t.companyId = ?
            AND t.transactionType = 1
            AND s.itemId = ?
            AND s.itemSoldUnits > 0
       ORDER BY t.transactionDate DESC
          LIMIT 1",
        [$companyId, $itemId]
    );
    $price = ($row && isset($row['price'])) ? (float) $row['price'] : 0.0;
    apiOk(['price' => $price]);
}

// ── Rama Panel CRUD ───────────────────────────────────────────────────────
$itemService = new \Punto\Api\Items\ItemService(new \Punto\Api\Items\ItemRepository($db));
$locService  = new \Punto\Api\Items\LocationService($db);

// ── Sub-recurso: grupos de items (parent/child via itemIsParent + itemParentId) ──
// POST /v1/items?resource=group         body: { itemIds:[], groupName:"" }
//   → crea un item con itemIsParent=true + setea itemParentId en los hijos
// POST /v1/items?id=X&resource=ungroup
//   → quita itemParentId de los hijos del grupo X (los hijos vuelven al top-level)
if ($resource === 'group' || $resource === 'ungroup') {
    if ($method !== 'POST') apiError('Method not allowed', 405);

    if ($resource === 'group') {
        $itemIds   = $_POST['itemIds'] ?? [];
        $groupName = trim((string) ($_POST['groupName'] ?? ''));
        if (!is_array($itemIds) || count($itemIds) < 2) {
            apiError('Se necesitan al menos 2 items para agrupar', 422);
        }
        if ($groupName === '') apiError('Falta el nombre del grupo', 422);

        // Crear el row del grupo. createBlank usa kind=producto y deja
        // itemStatus=1 (default del schema). Después patchamos name + flags.
        $groupId = $itemService->createBlank($companyId, null, 'producto');
        if ($groupId === false) apiError('No se pudo crear el grupo', 500);

        // PASS BOOLEAN COMO STRING 'true' — el wrapper de DB.php convierte bool
        // PHP → 'true'/'false' string, pero un int 1 NO se convierte y PG con
        // EMULATE_PREPARES=false puede rechazar int contra columna BOOLEAN.
        // Pasamos true (bool real) para que el wrapper lo convierta a 'true'.
        $ok = $itemService->update($groupId, $companyId, [
            'itemName'     => $groupName,
            'itemIsParent' => true,
        ]);
        if (!$ok) {
            // Rollback: archivamos el row huérfano. Sin esto queda visible un
            // "Nuevo Artículo" sin nombre real en el listado.
            $itemService->archive($groupId, $companyId);
            apiError('No se pudo nombrar el grupo', 500);
        }

        $assigned = 0;
        foreach ($itemIds as $childId) {
            if (!is_string($childId) || $childId === '') continue;
            if ($itemService->update($childId, $companyId, ['itemParentId' => $groupId])) {
                $assigned++;
            }
        }
        if ($assigned === 0) {
            $itemService->archive($groupId, $companyId);
            apiError('No se pudo asignar ningún item al grupo', 500);
        }
        apiOk(['groupId' => $groupId, 'childCount' => $assigned], 201);
    }

    if ($id === null) apiError('id del grupo requerido', 422);
    $rs = $db->Execute(
        'SELECT itemId FROM item WHERE itemParentId = ? AND companyId = ?',
        [$id, $companyId]
    );
    $count = 0;
    if ($rs !== false) {
        foreach ($rs->GetRows() as $row) {
            $childId = (string) ($row['itemid'] ?? $row['itemId']);
            if ($itemService->update($childId, $companyId, ['itemParentId' => null])) $count++;
        }
    }
    // El grupo queda vacío — lo archivamos.
    $itemService->archive($id, $companyId);
    apiOk(['ungrouped' => $count]);
}

// ── Sub-recurso: importador CSV ───────────────────────────────────────────
// resource=template (GET, descarga CSV con headers + ejemplos)
// resource=import   (POST multipart con `csv` file, opcional mode=insert|update)
if ($resource === 'template') {
    if ($method !== 'GET') apiError('Method not allowed', 405);
    $importer = new \Punto\Api\Items\ItemImporter($itemService, $db);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="items_plantilla.csv"');
    header('Cache-Control: no-store');
    echo $importer->templateCsv();
    exit;
}
if ($resource === 'import') {
    if ($method !== 'POST') apiError('Method not allowed', 405);
    if (empty($_FILES['csv']['tmp_name'])) apiError('Archivo CSV requerido (campo "csv")', 422);
    $contents = file_get_contents($_FILES['csv']['tmp_name']);
    if ($contents === false) apiError('No se pudo leer el archivo', 500);
    $mode = ($_POST['mode'] ?? 'insert') === 'update' ? 'update' : 'insert';
    $importer = new \Punto\Api\Items\ItemImporter($itemService, $db);
    $report   = $importer->import($contents, $companyId, $mode);
    apiOk($report);
}

$id = $_GET['id'] ?? null;

// Sub-recurso: grupos de combo dinámico (combo_group + combo_group_item).
if ($id !== null && $resource === 'combo-groups') {
    $cgSvc = new \Punto\Api\Items\ComboGroupService($db);

    if ($method === 'GET') {
        apiOk(['groups' => $cgSvc->listForParent($id, $companyId)]);
    }
    if ($method === 'POST') {
        try {
            $newId = $cgSvc->create($id, $companyId, $_POST);
            apiOk(['groupId' => $newId, 'groups' => $cgSvc->listForParent($id, $companyId)], 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    if ($method === 'PUT') {
        $groupId = (string) ($_GET['groupId'] ?? '');
        if ($groupId !== '') {
            try {
                $cgSvc->update($id, $companyId, $groupId, $_POST);
                apiOk(['groups' => $cgSvc->listForParent($id, $companyId)]);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
        }
        // Sin groupId: reorder bulk
        $order = $_POST['order'] ?? [];
        if (!is_array($order)) apiError('order debe ser array', 422);
        $cgSvc->reorder($id, $companyId, $order);
        apiOk(['groups' => $cgSvc->listForParent($id, $companyId)]);
    }
    if ($method === 'DELETE') {
        $groupId = (string) ($_GET['groupId'] ?? '');
        if ($groupId === '') apiError('groupId requerido', 422);
        try {
            $cgSvc->delete($id, $companyId, $groupId);
            apiOk(['deleted' => true, 'groups' => $cgSvc->listForParent($id, $companyId)]);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    apiError('Method not allowed for /items/combo-groups', 405);
}

// Sub-recurso: recetas / compuestos (ingredientes de items de producción).
if ($id !== null && $resource === 'compounds') {
    $compoundSvc = new \Punto\Api\Items\ItemCompoundService($db);

    if ($method === 'GET') {
        apiOk(['compounds' => $compoundSvc->listForParent($id, $companyId)]);
    }
    if ($method === 'POST') {
        $childItemId = (string) ($_POST['childItemId'] ?? '');
        $quantity    = (float) ($_POST['quantity'] ?? 0);
        if ($childItemId === '') apiError('childItemId requerido', 422);
        try {
            $newId = $compoundSvc->add($id, $companyId, $childItemId, $quantity);
            apiOk(['compoundId' => $newId, 'compounds' => $compoundSvc->listForParent($id, $companyId)], 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    if ($method === 'PUT') {
        // PUT con compoundId → actualiza cantidad. Sin compoundId → reorder bulk.
        $compoundId = (string) ($_POST['compoundId'] ?? '');
        if ($compoundId !== '') {
            $quantity = (float) ($_POST['quantity'] ?? 0);
            try {
                $compoundSvc->updateQuantity($id, $companyId, $compoundId, $quantity);
                apiOk(['compounds' => $compoundSvc->listForParent($id, $companyId)]);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
        }
        $order = $_POST['order'] ?? [];
        if (!is_array($order)) apiError('order debe ser array', 422);
        $compoundSvc->reorder($id, $companyId, $order);
        apiOk(['compounds' => $compoundSvc->listForParent($id, $companyId)]);
    }
    if ($method === 'DELETE') {
        $compoundId = (string) ($_GET['compoundId'] ?? '');
        if ($compoundId === '') apiError('compoundId requerido', 422);
        try {
            $compoundSvc->delete($id, $companyId, $compoundId);
            apiOk(['deleted' => true, 'compounds' => $compoundSvc->listForParent($id, $companyId)]);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    apiError('Method not allowed for /items/compounds', 405);
}

// Sub-recurso: galería de imágenes (max 5 por item, persistido en item_image + DO Spaces).
if ($id !== null && $resource === 'images') {
    $s3 = new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX);
    $imgSvc = new \Punto\Api\Items\ItemImageService($db, $s3);

    if ($method === 'GET') {
        apiOk(['images' => $imgSvc->listForItem($id, $companyId)]);
    }
    if ($method === 'POST') {
        if (empty($_FILES['image']['tmp_name'])) apiError('Falta archivo (campo "image")', 422);
        try {
            $img = $imgSvc->upload($id, $companyId, $_FILES['image']);
            apiOk(['image' => $img], 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    if ($method === 'DELETE') {
        $imageId = (string) ($_GET['imageId'] ?? '');
        if ($imageId === '') apiError('Falta imageId', 422);
        try {
            $imgSvc->delete($id, $companyId, $imageId);
            apiOk(['deleted' => true]);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
    }
    if ($method === 'PUT') {
        $order = $_POST['order'] ?? [];
        if (!is_array($order)) apiError('order debe ser array', 422);
        $imgSvc->reorder($id, $companyId, $order);
        apiOk(['images' => $imgSvc->listForItem($id, $companyId)]);
    }
    apiError('Method not allowed for /items/images', 405);
}

// Sub-recurso: depósitos
if ($id !== null && $resource === 'locations') {
    if ($method === 'GET') {
        apiOk(['locations' => $locService->listForItem($id)]);
    }

    if ($method === 'PUT') {
        $locationIds = $_POST['locationIds'] ?? [];
        if (!is_array($locationIds)) apiError('locationIds must be array', 422);
        $default = $_POST['default'] ?? null;

        $locService->syncForItem($id, $companyId, $locationIds, $default);
        apiOk(['updated' => true, 'count' => count($locationIds)]);
    }

    apiError('Method not allowed for /items/locations', 405);
}

// ── Sub-recurso: stock (tab "Stock" del detalle de ítem) ───────────────────
// GET  /v1/items?id=X&resource=inventory-movements&limit=&offset=
//   → { summary:{qty,avgCost,totalValue}, items:[...], total, limit, offset }
// POST /v1/items?id=X&resource=inventory-movements
//   body: { action:"adjust", outletId, locationId?, type:"+"|"-", qty, unitCost?, reason }
//   → summary actualizado tras aplicar el ajuste (ver StockMovementsService::adjust)
if ($id !== null && $resource === 'inventory-movements') {
    $stockMovementsSvc = new \Punto\Api\Items\StockMovementsService();

    if ($method === 'GET') {
        $limit  = (int) ($_GET['limit'] ?? 20);
        $offset = (int) ($_GET['offset'] ?? 0);
        $page   = $stockMovementsSvc->movements($id, $companyId, $limit, $offset);
        apiOk([
            'summary' => $stockMovementsSvc->summary($id, $companyId),
            ...$page,
        ]);
    }

    if ($method === 'POST') {
        $outletId   = trim((string) ($_POST['outletId'] ?? ''));
        $locationId = trim((string) ($_POST['locationId'] ?? '')) ?: null;
        $type       = (string) ($_POST['type'] ?? '');
        $qty        = (float) ($_POST['qty'] ?? 0);
        $unitCostRaw = $_POST['unitCost'] ?? null;
        $unitCost   = ($unitCostRaw !== null && $unitCostRaw !== '') ? (float) $unitCostRaw : null;
        $reason     = (string) ($_POST['reason'] ?? '');

        if ($outletId === '') {
            apiError('Falta outletId', 422);
        }

        try {
            $summary = $stockMovementsSvc->adjust(
                $companyId,
                $ctx['userId'],
                $id,
                $outletId,
                $locationId,
                $type,
                $qty,
                $unitCost,
                $reason
            );
            apiOk(['summary' => $summary]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
    }

    apiError('Method not allowed for /items/inventory-movements', 405);
}

// ── Sub-recurso: edición masiva ────────────────────────────────────────────
// POST /v1/items?resource=bulk-edit
//   body: { itemIds:[uuid...], patch:{...}, priceAdjustPercent?:number }
// Aplica `patch` (campos canónicos en camelCase, filtrado por whitelist) a
// cada item. Si `priceAdjustPercent` viene, recalcula itemPrice por item.
// Si un item es grupo (itemIsParent=true), propaga el patch a sus hijos.
if ($resource === 'bulk-edit') {
    if ($method !== 'POST') apiError('Method not allowed for /items?resource=bulk-edit', 405);

    $itemIds = $_POST['itemIds'] ?? [];
    if (!is_array($itemIds) || count($itemIds) === 0) {
        apiError('itemIds requerido (array no vacío)', 422);
    }
    if (count($itemIds) > 500) {
        apiError('Máximo 500 items por bulk-edit', 422);
    }

    $patch = $_POST['patch'] ?? [];
    if (!is_array($patch)) apiError('patch debe ser objeto', 422);

    // Si viene `kind` en el patch, lo expandimos a flags legacy en sync.
    if (!empty($patch['kind']) && in_array($patch['kind'], VALID_KINDS, true)) {
        $patch = array_merge($patch, kindToLegacyFlags($patch['kind']));
        $patch['itemKind'] = $patch['kind'];
        unset($patch['kind']);
    } else {
        unset($patch['kind']);
    }

    // priceAdjustPercent es meta-opt fuera del patch.
    $pap = $_POST['priceAdjustPercent'] ?? null;
    $priceAdjustPercent = ($pap !== null && $pap !== '' && is_numeric($pap)) ? (float) $pap : null;

    $report = $itemService->bulkEdit($companyId, $itemIds, $patch, $priceAdjustPercent);
    apiOk($report);
}

// ── Sub-recurso: variantes de producto ────────────────────────────────────────────────────
// GET  /v1/items?resource=variants&parentId=<uuid>  → lista variantes activas del padre
// POST /v1/items { action:"bulkUpsertVariants", parentId, variants:[...] } → bulk insert/update
if ($resource === 'variants') {
    if ($method !== 'GET') apiError('Method not allowed para /items?resource=variants', 405);
    $parentId = trim((string) ($_GET['parentId'] ?? ''));
    if ($parentId === '') apiError('parentId requerido', 422);
    $variantSvc = new \Punto\Api\Items\VariantService($db);
    $variants   = $variantSvc->listVariants($companyId, $parentId);
    apiOk(['variants' => array_map(fn($v) => presentItem($v), $variants)]);
}

if ($method === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'bulkUpsertVariants') {
    $parentId = trim((string) ($_POST['parentId'] ?? ''));
    $variants  = $_POST['variants'] ?? [];
    if ($parentId === '') apiError('parentId requerido', 422);
    if (!is_array($variants)) apiError('variants debe ser array', 422);

    $variantSvc = new \Punto\Api\Items\VariantService($db);
    try {
        $variantSvc->validateParent($companyId, $parentId);
        $result = $variantSvc->bulkUpsertVariants($companyId, $parentId, $variants);
        if (function_exists('emitTenantEvent')) {
            emitTenantEvent('item', ['action' => 'bulk_variant_upsert', 'parentId' => $parentId]);
        }
        apiOk(['variants' => array_map(fn($v) => presentItem($v), $result)]);
    } catch (\RuntimeException $e) {
        $code = $e->getCode() >= 400 ? $e->getCode() : 422;
        apiError($e->getMessage(), $code);
    }
}

// Defense-in-depth: cada case termina por apiOk/apiError (que llaman exit), así que el
// fall-through no ocurre HOY — pero un break; en cada case previene un fall-through silente
// si un futuro edit agrega una branch no-terminante.
switch ($method) {
    case 'GET':
        if ($id !== null) {
            $item = $itemService->find($id, $companyId);
            if ($item === null) apiError('Item no encontrado', 404);
            $presented = presentItem(ncmRow($item));
            $presented['categories'] = fetchItemCategories($id);
            $presented['brands']     = fetchItemBrands($id);
            $presented['tags']       = fetchItemTags($id);
            // Galería: 0..5 imágenes ordenadas. Si el caller solo quiere los datos
            // del item sin tocar S3, esto es solo un SELECT — sin overhead extra.
            $s3 = new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX);
            $imgSvc = new \Punto\Api\Items\ItemImageService($db, $s3);
            $presented['images'] = $imgSvc->listForItem($id, $companyId);
            apiOk($presented);
        }

        $limit  = max(1, min((int) ($_GET['limit'] ?? 50), 200));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $where  = ['companyId = ?', 'itemStatus = ?'];
        $params = [$companyId, (int) ($_GET['archived'] ?? 0) === 1 ? 0 : 1];

        // Filtro por grupo:
        //   ?parentId=X    → solo hijos del grupo X
        //   ?parentId=null → solo top-level (parents O standalone, NUNCA hijos de un grupo)
        //   (sin parentId) → mismo comportamiento que parentId=null (default)
        $parentIdFilter = $_GET['parentId'] ?? null;
        $includeGroupChildren = !empty($_GET['includeGroupChildren']) && $_GET['includeGroupChildren'] === 'true';

        if ($parentIdFilter !== null && $parentIdFilter !== '' && $parentIdFilter !== 'null') {
            $where[]  = 'itemParentId = ?';
            $params[] = $parentIdFilter;
        } elseif (!$includeGroupChildren) {
            $where[] = 'itemParentId IS NULL';
        }

        // Filtro de variantes (variantParentId).
        // Por default, ocultar variantes del listado principal.
        // ?includeVariants=true las incluye (toggle "Mostrar variantes").
        $includeVariants = !empty($_GET['includeVariants']) && $_GET['includeVariants'] === 'true';
        if (!$includeVariants) {
            $where[] = 'variantParentId IS NULL';
        }

        if (!empty($_GET['q'])) {
            $pattern  = '%' . $_GET['q'] . '%';
            $where[]  = '(itemName ILIKE ? OR itemSKU ILIKE ?)';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        // Filtro por kind canónico (reemplaza ?type= del legacy).
        if (!empty($_GET['kind']) && in_array($_GET['kind'], VALID_KINDS, true)) {
            $where[]  = 'itemKind = ?';
            $params[] = $_GET['kind'];
        }
        // Compat legacy: ?type= sigue funcionando si se pasa directamente.
        if (!empty($_GET['type']) && empty($_GET['kind'])) {
            $where[]  = 'itemType = ?';
            $params[] = $_GET['type'];
        }

        // Prefijar el WHERE con el alias `i.` para que pegue en el JOIN.
        $whereSql = preg_replace(
            '/\b(companyId|itemStatus|itemName|itemSKU|itemKind|itemType|itemParentId|variantParentId)\b/',
            'i.$1',
            implode(' AND ', $where)
        );

        // coverImage: subquery LATERAL para tomar la imagen sort=0 (o la primera
        // por created_at). Evita N+1 contra item_image.
        // childCount: count de items con itemParentId = i.itemId (solo cuenta para
        // los grupos — los standalone tendrán 0).
        $sql = "SELECT i.itemId, i.itemName, i.itemSKU, i.itemType, i.itemKind, i.itemStatus,
                       i.itemPrice, i.itemCost, i.itemDate, i.updated_at,
                       i.itemCanSale, i.itemTrackInventory, i.taxId,
                       i.itemIsParent, i.itemParentId,
                       i.variantParentId, i.hasVariants, i.variantAttributes,
                       i.categoryId, i.brandId, i.outletId, i.data,
                       cat.taxonomyName AS categoryName,
                       brand.taxonomyName AS brandName,
                       o.outletName AS outletName,
                       cov.url AS coverImageUrl,
                       COALESCE(ch.cnt, 0) AS childCount,
                       COALESCE(vc.vcnt, 0) AS variantCount
                  FROM item i
             LEFT JOIN taxonomy cat   ON cat.taxonomyId   = i.categoryId
             LEFT JOIN taxonomy brand ON brand.taxonomyId = i.brandId
             LEFT JOIN outlet o       ON o.outletId       = i.outletId
             LEFT JOIN LATERAL (
                  SELECT url FROM item_image
                   WHERE itemId = i.itemId
                   ORDER BY sort ASC, created_at ASC LIMIT 1
             ) cov ON true
             LEFT JOIN LATERAL (
                  SELECT COUNT(*) AS cnt FROM item c
                   WHERE c.itemParentId = i.itemId AND c.itemStatus = 1
             ) ch ON true
             LEFT JOIN LATERAL (
                  SELECT COUNT(*) AS vcnt FROM item v
                   WHERE v.variantParentId = i.itemId AND v.itemStatus = 1
             ) vc ON true
                 WHERE $whereSql
                 ORDER BY i.itemDate DESC
                 LIMIT $limit OFFSET $offset";
        $rs    = $db->Execute($sql, $params);
        $items = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $row) {
                $items[] = presentItem(_flattenJsonb($row));
            }
        }

        $countSql = "SELECT COUNT(*) AS n FROM item i WHERE $whereSql";
        $countRs  = $db->Execute($countSql, $params);
        $total    = ($countRs !== false && !$countRs->EOF) ? (int) $countRs->fields['n'] : 0;

        apiOk([
            'items'  => $items,
            'limit'  => $limit,
            'offset' => $offset,
            'total'  => $total,
        ]);
        break;

    case 'POST':
        $kind = $_POST['kind'] ?? null;

        // Validar kind si se pasa explícitamente.
        if ($kind !== null && !in_array($kind, VALID_KINDS, true)) {
            apiError('kind inválido: ' . $kind, 422);
        }

        // Compat: si viene ?type= (panel legacy), mapear al kind más cercano.
        if ($kind === null && !empty($_POST['type'])) {
            $legacyTypeMap = [
                'discount'   => 'descuento',
                'combo'      => 'combo_fijo',
                'giftcard'   => 'giftcard',
                'production' => 'produccion_previa',
            ];
            $kind = $legacyTypeMap[$_POST['type']] ?? 'producto';
        }
        $kind = $kind ?? 'producto';

        // Construir el record inicial con kind + flags legacy en sync.
        $legacyFlags = kindToLegacyFlags($kind);
        $record = array_merge(['itemKind' => $kind], $legacyFlags);

        $newId = $itemService->createBlank($companyId, $legacyFlags['itemType'] ?? null, $kind);
        if ($newId === false) apiError('No se pudo crear el item', 500);

        // Aplicar kind + extras al row recién creado.
        $extras = array_diff_key($_POST, ['type' => 1, 'kind' => 1]);
        $extras['itemKind'] = $kind;
        $extras = array_merge($extras, $legacyFlags);
        $itemService->update($newId, $companyId, $extras);

        $item = $itemService->find($newId, $companyId);
        $presented = $item !== null
            ? presentItem(ncmRow($item))
            : ['itemId' => $newId];
        if ($item !== null) {
            $presented['categories'] = [];
            $presented['brands']     = [];
            $presented['tags']       = [];
        }
        apiOk($presented, 201);
        break;

    case 'PUT':
        if ($id === null) apiError('id es requerido para PUT', 422);

        // Sub-recurso: categories m2m
        if ($resource === 'categories') {
            $incoming = $_POST['categories'] ?? [];
            if (!is_array($incoming)) apiError('categories debe ser array', 422);

            // Reemplazar todas las categorías del item con las que vienen.
            $db->Execute('DELETE FROM item_category WHERE itemId = ?', [$id]);
            $hasPrimary = false;
            foreach ($incoming as $cat) {
                $catId     = $cat['id'] ?? null;
                $isPrimary = !empty($cat['isPrimary']);
                if (!$catId) continue;
                if ($isPrimary) $hasPrimary = true;
                $db->Execute(
                    'INSERT INTO item_category (itemId, categoryId, isPrimary) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [$id, $catId, $isPrimary ? 'true' : 'false']
                );
            }
            // Mantener item.categoryId en sync con la categoría primaria (legacy compat).
            $primaryId = $hasPrimary ? ($incoming[array_key_first(array_filter($incoming, fn($c) => !empty($c['isPrimary'])))]['id'] ?? null) : null;
            if ($primaryId !== null) {
                $itemService->update($id, $companyId, ['categoryId' => $primaryId]);
            }
            apiOk(['updated' => true, 'categories' => fetchItemCategories($id)]);
        }

        // Sub-recurso: brands m2m (paridad con categories — un item puede tener N marcas).
        if ($resource === 'brands') {
            $incoming = $_POST['brands'] ?? [];
            if (!is_array($incoming)) apiError('brands debe ser array', 422);

            $db->Execute('DELETE FROM item_brand WHERE itemId = ?', [$id]);
            $hasPrimary = false;
            $primaryId = null;
            foreach ($incoming as $b) {
                $bId       = $b['id'] ?? null;
                $isPrimary = !empty($b['isPrimary']);
                if (!$bId) continue;
                if ($isPrimary && !$hasPrimary) {
                    $hasPrimary = true;
                    $primaryId  = $bId;
                }
                $db->Execute(
                    'INSERT INTO item_brand (itemId, brandId, isPrimary) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [$id, $bId, $isPrimary ? 'true' : 'false']
                );
            }
            // Mantener item.brandId en sync con la primaria (legacy compat).
            if ($primaryId !== null) {
                $itemService->update($id, $companyId, ['brandId' => $primaryId]);
            }
            apiOk(['updated' => true, 'brands' => fetchItemBrands($id)]);
        }

        // Sub-recurso: tags m2m. data.tags JSONB se mantiene en sync para no
        // romper consumers legacy (POS / data.tags) durante la transición.
        if ($resource === 'tags') {
            $incoming = $_POST['tags'] ?? [];
            if (!is_array($incoming)) apiError('tags debe ser array', 422);

            $db->Execute('DELETE FROM item_tag WHERE itemId = ?', [$id]);
            $tagIds = [];
            foreach ($incoming as $t) {
                // Acepta tanto {id:"uuid"} como "uuid" directo.
                $tId = is_array($t) ? ($t['id'] ?? null) : $t;
                if (!$tId) continue;
                $db->Execute(
                    'INSERT INTO item_tag (itemId, tagId) VALUES (?, ?) ON CONFLICT DO NOTHING',
                    [$id, $tId]
                );
                $tagIds[] = (string) $tId;
            }
            // Sync data.tags JSONB (consumers legacy lo siguen leyendo).
            $itemService->update($id, $companyId, ['tags' => $tagIds]);
            apiOk(['updated' => true, 'tags' => fetchItemTags($id)]);
        }

        $patch = $_POST;
        unset($patch['id'], $patch['itemId'], $patch['companyId']);
        if (empty($patch)) apiError('Patch vacío', 422);

        // Sincronizar kind + flags legacy. Antes había un lock estricto que
        // bloqueaba si el kind no matcheaba EXACTAMENTE (case-sensitive),
        // lo cual rompía saves legítimos (data corrupta, whitespace, etc).
        // El lock es overengineering — el front no permite editar el kind
        // del item una vez creado (el Select Kind está deshabilitado en
        // ediciones), así que el kind enviado siempre debería matchear el
        // de DB. Si por alguna razón el front mandara uno distinto, lo
        // aceptamos y resincronizamos flags — no rompe nada porque
        // kindToLegacyFlags() devuelve flags consistentes.
        if (!empty($patch['kind'])) {
            $newKind = (string) $patch['kind'];
            if (in_array($newKind, VALID_KINDS, true)) {
                $patch = array_merge($patch, kindToLegacyFlags($newKind));
                $patch['itemKind'] = $newKind;
            }
            unset($patch['kind']);
        }

        try {
            $patch = $itemService->applyVariantRules($companyId, $patch, $id);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 ? $e->getCode() : 422;
            apiError($e->getMessage(), $code);
        }

        $ok = $itemService->update($id, $companyId, $patch);
        if (!$ok) apiError('Update falló', 500);

        $item = $itemService->find($id, $companyId);
        $presented = $item !== null
            ? presentItem(ncmRow($item))
            : ['itemId' => $id];
        if ($item !== null) {
            $presented['categories'] = fetchItemCategories($id);
            $presented['brands']     = fetchItemBrands($id);
            $presented['tags']       = fetchItemTags($id);
        }
        apiOk($presented);
        break;

    case 'DELETE':
        if ($id === null) apiError('id es requerido para DELETE', 422);

        // Validar que no tiene variantes activas antes de archivar.
        $variantCountRow = ncmExecute(
            'SELECT COUNT(*) AS n FROM item WHERE variantParentId = ? AND companyId = ? AND itemStatus = 1',
            [$id, $companyId]
        );
        $variantCount = (int) ($variantCountRow['n'] ?? 0);
        if ($variantCount > 0) {
            apiError("Archivá las $variantCount variantes primero antes de archivar el padre.", 409);
        }

        // hard=1 → hard-delete (solo para items archivados, sin ventas).
        // Default (sin hard) → soft-delete / archive.
        if (!empty($_GET['hard'])) {
            $result = $itemService->delete($id, $companyId);
            if ($result === 'sold') {
                apiError('No se puede eliminar: el artículo tiene ventas registradas. Permanecerá archivado.', 409);
            }
            if ($result === 'referenced') {
                apiError('No se puede eliminar: el artículo está referenciado por otros registros (stock, inventario, compuestos). Permanecerá archivado.', 409);
            }
            if ($result !== true) {
                apiError('No se pudo eliminar. El artículo puede no estar archivado o no pertenecer a esta empresa.', 422);
            }
            apiOk(['deleted' => true, 'itemId' => $id]);
        }

        $ok = $itemService->archive($id, $companyId);
        if (!$ok) apiError('Archive falló', 500);
        apiOk(['archived' => true, 'itemId' => $id]);
        break;

    default:
        apiError('Method not allowed', 405);
}
