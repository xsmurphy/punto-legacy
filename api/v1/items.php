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
function presentItem(array $row): array
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
    ];
    $out = [];
    foreach ($row as $k => $v) {
        $kLower = strtolower((string) $k);
        $key = $map[$kLower] ?? $k;
        $out[$key] = $v;
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

// ── Rama Panel CRUD ───────────────────────────────────────────────────────
$itemService = new \Punto\Api\Items\ItemService(new \Punto\Api\Items\ItemRepository($db));
$locService  = new \Punto\Api\Items\LocationService($db);

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
    $s3 = new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET);
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

// Defense-in-depth: cada case termina por apiOk/apiError (que llaman exit), así que el
// fall-through no ocurre HOY — pero un break; en cada case previene un fall-through silente
// si un futuro edit agrega una branch no-terminante.
switch ($method) {
    case 'GET':
        if ($id !== null) {
            $item = $itemService->find($id, $companyId);
            if ($item === null) apiError('Item no encontrado', 404);
            $presented = presentItem($item->toArray());
            $presented['categories'] = fetchItemCategories($id);
            // Galería: 0..5 imágenes ordenadas. Si el caller solo quiere los datos
            // del item sin tocar S3, esto es solo un SELECT — sin overhead extra.
            $s3 = new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET);
            $imgSvc = new \Punto\Api\Items\ItemImageService($db, $s3);
            $presented['images'] = $imgSvc->listForItem($id, $companyId);
            apiOk($presented);
        }

        $limit  = max(1, min((int) ($_GET['limit'] ?? 50), 200));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $where  = ['companyId = ?', 'itemStatus = ?'];
        $params = [$companyId, (int) ($_GET['archived'] ?? 0) === 1 ? 0 : 1];

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
            '/\b(companyId|itemStatus|itemName|itemSKU|itemKind|itemType)\b/',
            'i.$1',
            implode(' AND ', $where)
        );

        // coverImage: subquery DISTINCT ON para tomar la imagen sort=0 (o la
        // primera por created_at si no hay sort=0). Evita N+1 contra item_image.
        $sql = "SELECT i.itemId, i.itemName, i.itemSKU, i.itemType, i.itemKind, i.itemStatus,
                       i.itemPrice, i.itemCost, i.itemDate, i.updated_at,
                       i.categoryId, i.brandId, i.outletId, i.data,
                       cat.taxonomyName AS categoryName,
                       brand.taxonomyName AS brandName,
                       o.outletName AS outletName,
                       cov.url AS coverImageUrl
                  FROM item i
             LEFT JOIN taxonomy cat   ON cat.taxonomyId   = i.categoryId
             LEFT JOIN taxonomy brand ON brand.taxonomyId = i.brandId
             LEFT JOIN outlet o       ON o.outletId       = i.outletId
             LEFT JOIN LATERAL (
                  SELECT url FROM item_image
                   WHERE itemId = i.itemId
                   ORDER BY sort ASC, created_at ASC LIMIT 1
             ) cov ON true
                 WHERE $whereSql
                 ORDER BY i.itemDate DESC
                 LIMIT $limit OFFSET $offset";
        $rs    = $db->Execute($sql, $params);
        $items = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $row) {
                $items[] = presentItem(_flattenJsonb($row)->toArray());
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
        $presented = $item !== null ? presentItem($item->toArray()) : ['itemId' => $newId];
        if ($item !== null) $presented['categories'] = [];
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

        $patch = $_POST;
        unset($patch['id'], $patch['itemId'], $patch['companyId']);
        if (empty($patch)) apiError('Patch vacío', 422);

        // Rechazar cambios de kind — un item no puede cambiar de tipo.
        if (!empty($patch['kind'])) {
            $current = $itemService->find($id, $companyId);
            $currentKind = $current !== null ? ($current['itemKind'] ?? $current['itemkind'] ?? null) : null;
            if ($currentKind !== null && $patch['kind'] !== $currentKind) {
                apiError('El kind de un item no se puede cambiar. Archivá este item y creá uno nuevo.', 409);
            }
            // Si el kind es el mismo, sincronizar los flags legacy igual.
            $patch = array_merge($patch, kindToLegacyFlags($patch['kind']));
            $patch['itemKind'] = $patch['kind'];
            unset($patch['kind']);
        }

        $ok = $itemService->update($id, $companyId, $patch);
        if (!$ok) apiError('Update falló', 500);

        $item = $itemService->find($id, $companyId);
        $presented = $item !== null ? presentItem($item->toArray()) : ['itemId' => $id];
        if ($item !== null) $presented['categories'] = fetchItemCategories($id);
        apiOk($presented);
        break;

    case 'DELETE':
        if ($id === null) apiError('id es requerido para DELETE', 422);
        $ok = $itemService->archive($id, $companyId);
        if (!$ok) apiError('Archive falló', 500);
        apiOk(['archived' => true, 'itemId' => $id]);
        break;

    default:
        apiError('Method not allowed', 405);
}
