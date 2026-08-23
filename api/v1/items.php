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
 * `pos-app` (token del dispositivo) es GET-only: toda escritura (POST/PUT/
 * DELETE) exige realm `panel`.
 *
 * Servicios:
 *   - `Punto\Api\Items\*`             (panel CRUD: F2 port de panel/lib/items/)
 *   - `Punto\Api\Services\ItemService` (POS info: slice 25 existente, intacto)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/services/ItemService.php';
require_once __DIR__ . '/../lib/Items/ItemsQuery.php';

use Punto\Api\Context\TenantContext;
use function Punto\Api\Items\buildItemsSelectSql;
use function Punto\Api\Items\presentItem;
use function Punto\Api\Items\outletVisibilityClause;

// `buildItemsSelectSql()` y `presentItem()` viven en
// `api/lib/Items/ItemsQuery.php` (extraídas 2026-08-16,
// context/43-sync-incremental.md) para que el delta de
// `/v1/sync?section=items` las comparta sin copiar SQL/mapeo a mano. Mismo
// shape de fila para los TRES casos (listado paginado, bulk-get quirúrgico
// de context/15, y el delta de context/43) — así el reshape del BFF
// (`lib/pos-bff/reshape.ts::reshapeItem`) nunca diverge entre caminos.
// Importadas arriba vía `use function`.

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
    // El mapa vive en Punto\Api\Items\ItemKind: es la fuente única y otros
    // servicios (SignupService y los ítems demo del alta) también lo necesitan.
    // Mientras estaba acá adentro nadie más podía usarlo y el alta insertaba
    // ítems sin itemKind, violando el NOT NULL de mig 15.
    return \Punto\Api\Items\ItemKind::legacyFlags($kind);
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

// Visibilidad por sucursal: solo pos-app queda restringido a su outlet
// (device.outletId, resuelto por apiAuthTenant — nunca de query/body). El
// panel ve el catálogo completo del tenant salvo que el selector global de
// sucursal (dropdown del logo) tenga elegida una puntual (ver
// $panelViewOutletId abajo) — ver outletVisibilityClause().
$deviceOutletId = (($ctx['realm'] ?? '') === 'pos-app') ? (string) ($ctx['outletId'] ?? '') : null;

// View-scope del panel (decisión owner 2026-08-22, context/25 §3/§5): si el
// browser mandó X-Outlet-Id con una sucursal puntual, bootstrap.php ya lo
// validó contra el tenant y lo dejó en VIEW_OUTLET_ID. 'all' (o el header
// ausente porque el usuario no tocó el selector todavía) deja VIEW_OUTLET_ID
// en '' / sin definir → catálogo completo del tenant, comportamiento
// histórico sin cambios.
//
// OPT-IN vía `?respectViewScope=1`, NO ambiente/automático — a propósito:
// `X-Outlet-Id` lo manda `api-client.ts` en TODAS las requests del panel una
// vez que el operador eligió sucursal en el dropdown del logo, y `/v1/items`
// es un endpoint compartido por MÁS que la página de Artículos — el mismo
// `useItems()` alimenta selectores de ítem dentro de Compras
// (`purchase-form-fields.tsx`) y de recetas de combos (`compounds-editor.tsx`).
// Esos pickers necesitan ver el catálogo COMPLETO del tenant siempre (se
// puede comprar/recetar con ítems de cualquier sucursal, no solo la que el
// operador está mirando en ese momento) — si el filtro fuera ambiente por el
// solo hecho de haber un header, esos selectores se quedarían cortos de
// opciones como efecto colateral apenas alguien fijara una sucursal en el
// dropdown. Solo `app/(panel)/items/page.tsx` (la página de Artículos) manda
// el flag. Solo aplica a realm panel: pos-app nunca manda este header
// (bootstrap.php lo restringe) y sigue resolviendo su outlet desde el device
// ($deviceOutletId arriba), no desde el selector del panel.
$panelViewOutletId = null;
if (
    ($ctx['realm'] ?? '') === 'panel'
    && !empty($_GET['respectViewScope'])
    && defined('VIEW_OUTLET_ID')
    && VIEW_OUTLET_ID !== ''
) {
    $panelViewOutletId = (string) VIEW_OUTLET_ID;
}

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// El realm `pos-app` es el token del DISPOSITIVO (una caja), no el de un
// operador del panel: se agregó solo para que el POS pueda CONSULTAR el
// catálogo. La administración del catálogo es del panel, así que una caja
// comprometida no debe poder crear, editar ni archivar ítems del tenant.
// Guard único acá arriba: cubre también los verbos PUT/DELETE del final.
// Excepción explícita `bulk-get`: es POST por tamaño de body (una lista de
// ids no entra en query string), pero semánticamente es una LECTURA — el
// POS necesita poder pedirla (sync realtime quirúrgico, context/15) igual
// que ya puede hacer GET del catálogo completo.
if (($ctx['realm'] ?? '') === 'pos-app' && $method !== 'GET' && ($_GET['resource'] ?? '') !== 'bulk-get') {
    apiError('El dispositivo POS solo puede leer el catálogo', 403);
}

$resource = (string) ($_GET['resource'] ?? '');

global $db;

// ── Bulk-get quirúrgico (sync realtime, context/15) ────────────────────────
// POST /v1/items?resource=bulk-get  body: { ids: [uuid, ...] }
//
// Por qué POST: la lista de ids puede ser grande (bulk edit de miles de
// productos desde el panel) — un GET con esa cantidad en query string pega
// el límite de largo de URL. Efecto lateral buscado: un POST no lo cachea
// el browser ni un proxy intermedio, así que el device siempre recibe el
// dato fresco (justo el punto de un trigger "esto cambió recién").
//
// companyId SIEMPRE del JWT (aislamiento multi-tenant) — un id que no es
// del tenant simplemente no aparece en el resultado, nunca se confía en
// que los ids del body ya vienen filtrados por tenant.
//
// Devuelve TODOS los ítems de la lista sin filtrar por status/canSale — el
// caller (BFF `/api/pos/items`) decide: un item inactivo/no-vendible se
// saca del store del POS en vez de mostrarse fantasma. Ids que no existen
// en absoluto (borrados) simplemente no vienen en la respuesta — el caller
// interpreta la ausencia como "borrado, sacalo del store".
if ($resource === 'bulk-get') {
    if ($method !== 'POST') {
        apiError('Method not allowed for /items resource=bulk-get (usar POST)', 405);
    }
    $body = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $rawIds = is_array($body['ids'] ?? null) ? $body['ids'] : [];
    $ids = array_values(array_unique(array_filter(
        array_map(static fn($v) => trim((string) $v), $rawIds),
        static fn($v) => $v !== ''
    )));
    if (empty($ids)) {
        apiOk(['items' => []]);
    }
    // Techo por request — no es el mecanismo normal (el caller debe mandar
    // tandas de tamaño razonable, ver `MAX_BULK_ITEMS` en el BFF), es una
    // guarda de borde para no dejar un IN() de tamaño arbitrario pegarle a
    // Postgres ante un caller con un bug o un replay raro.
    $ids = array_slice($ids, 0, 2000);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $whereSql = "i.companyId = ? AND i.itemId IN ({$placeholders})";
    $whereParams = array_merge([$companyId], $ids);
    // Un device (pos-app) no puede usar bulk-get para pedir la ficha de un
    // ítem de otra sucursal aunque conozca el id (ej. eco de un evento
    // realtime disparado por otra caja del tenant) — mismo criterio que el
    // listado paginado de abajo.
    [$outletClause, $outletParams] = outletVisibilityClause($deviceOutletId);
    if ($outletClause !== '') {
        $whereSql .= " AND {$outletClause}";
        $whereParams = array_merge($whereParams, $outletParams);
    }
    $sql = buildItemsSelectSql($whereSql);
    $rs  = $db->Execute($sql, $whereParams);
    $items = [];
    if ($rs !== false) {
        foreach ($rs->GetRows() as $row) {
            $items[] = presentItem(_flattenJsonb($row));
        }
    }
    apiOk(['items' => $items]);
}

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
//
// @deprecated F5 (context/41, 2026-08-15) — reemplazado por `addon_group` /
// `addon_group_option` y `AddonService` (sub-recurso `resource=addons`). El
// combo dinámico se unificó con add-ons: es "un producto con grupos", sin
// maquinaria propia. La mig 136 copió las filas existentes al modelo nuevo y
// el panel ya no monta `ComboGroupsEditor`.
//
// Sigue respondiendo a propósito: es una API pública y romperla en silencio a
// un integrador es peor que mantener un endpoint sin consumidores. NO agregar
// features acá — todo lo nuevo va por AddonService.
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
        apiOk($compoundSvc->recipe($id, $companyId));
    }
    if ($method === 'POST') {
        $childItemId = (string) ($_POST['childItemId'] ?? '');
        $quantity    = (float) ($_POST['quantity'] ?? 0);
        if ($childItemId === '') apiError('childItemId requerido', 422);
        try {
            $newId = $compoundSvc->add($id, $companyId, $childItemId, $quantity);
            apiOk(['compoundId' => $newId] + $compoundSvc->recipe($id, $companyId), 201);
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
                apiOk($compoundSvc->recipe($id, $companyId));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
        }
        $order = $_POST['order'] ?? [];
        if (!is_array($order)) apiError('order debe ser array', 422);
        $compoundSvc->reorder($id, $companyId, $order);
        apiOk($compoundSvc->recipe($id, $companyId));
    }
    if ($method === 'DELETE') {
        $compoundId = (string) ($_GET['compoundId'] ?? '');
        if ($compoundId === '') apiError('compoundId requerido', 422);
        try {
            $compoundSvc->delete($id, $companyId, $compoundId);
            apiOk(['deleted' => true] + $compoundSvc->recipe($id, $companyId));
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
            // Cuánto hay y dónde. Viaja con el resumen y no como recurso
            // aparte: es el dato principal del tab, así que pedirlo en una
            // segunda request lo dejaría llegando después de las cifras
            // secundarias.
            'breakdown' => $stockMovementsSvc->breakdown($id, $companyId),
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
            // Esta rama es la ficha COMPLETA del panel CRUD (ver docblock del
            // archivo). Un device (pos-app) también puede llegar acá — el BFF
            // de la ficha del POS (`frontend/app/api/pos/items/route.ts`) la
            // usa sin `resource=` — así que aplica el mismo criterio de
            // visibilidad que `resource=core|inventory|info`
            // (`ItemService::isForeignOutletItem`) y que el listado/bulk-get
            // de más abajo: un ítem de otra sucursal se trata como "no
            // encontrado", nunca se confirma su existencia a la caja.
            if ($deviceOutletId !== null && $deviceOutletId !== '') {
                $itemOutletId = (string) ($item['outletId'] ?? '');
                if ($itemOutletId !== '' && $itemOutletId !== $deviceOutletId) {
                    apiError('Item no encontrado', 404);
                }
            }
            $presented = presentItem(ncmRow($item));
            $presented['categories'] = fetchItemCategories($id);
            $presented['brands']     = fetchItemBrands($id);
            $presented['tags']       = fetchItemTags($id);
            // Galería: 0..5 imágenes ordenadas. Si el caller solo quiere los datos
            // del item sin tocar S3, esto es solo un SELECT — sin overhead extra.
            $s3 = new \Punto\Api\Storage\S3Client(S3_ENDPOINT, S3_REGION, S3_BUCKET, S3_KEY, S3_SECRET, S3_KEY_PREFIX);
            $imgSvc = new \Punto\Api\Items\ItemImageService($db, $s3);
            $presented['images'] = $imgSvc->listForItem($id, $companyId);
            // F5 (context/41): descuento implícito del combo fijo. Derivado de
            // item_compound vs itemPrice, sin columna ni migración detrás. Solo
            // para combo_fijo — es el único kind cuyo precio es fijo mientras su
            // contenido tiene precio propio; en el resto la comparación no
            // significa nada. `null` (sin componentes) no viaja: la ficha
            // distingue "no hay combo que comparar" por la ausencia de la key.
            if (($presented['kind'] ?? '') === 'combo_fijo') {
                $comboPricing = (new \Punto\Api\Items\ItemCompoundService($db))
                    ->comboPricing($id, $companyId, (float) ($presented['itemPrice'] ?? 0));
                if ($comboPricing !== null) {
                    $presented['comboPricing'] = $comboPricing;
                }
            }
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
            // Incluye la categoría (cat.taxonomyName — mismo alias del LEFT
            // JOIN taxonomy que arma buildItemsSelectSql()): el buscador de
            // Artículos no encontraba nada al tipear el nombre de una
            // categoría (ej. "materia prima") porque el WHERE solo miraba
            // itemName/itemSKU. El countSql de abajo necesita el mismo JOIN
            // porque ahora referencia `cat.` en el WHERE.
            $where[]  = '(itemName ILIKE ? OR itemSKU ILIKE ? OR cat.taxonomyName ILIKE ?)';
            $params[] = $pattern;
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

        // Catálogo ofrecido a la venta: una caja (pos-app) solo ve lo de su
        // sucursal (+ ítems globales, outletId NULL). Es el filtro central
        // del bug "el POS ofrece artículos de otras sucursales" — antes no
        // existía acá, así que el bootstrap bajaba el catálogo del tenant
        // entero.
        //
        // Panel: por default administra el catálogo completo del tenant
        // (Scope=Tenant), pero si el selector global de sucursal tiene
        // elegida una puntual ($panelViewOutletId), el listado de Artículos
        // respeta ese scope con el MISMO criterio de visibilidad que pos-app
        // — asignado a esa sucursal o global (outletId NULL) — decisión
        // owner 2026-08-22 (context/25 §3/§5). `$deviceOutletId` y
        // `$panelViewOutletId` son mutuamente excluyentes (uno por realm),
        // así que reusar `outletVisibilityClause()` con cualquiera de los
        // dos que esté seteado no cambia el comportamiento de pos-app.
        $effectiveViewOutletId = $deviceOutletId ?? $panelViewOutletId;
        [$outletClause, $outletParams] = outletVisibilityClause($effectiveViewOutletId);
        if ($outletClause !== '') {
            $whereSql .= " AND {$outletClause}";
            $params = array_merge($params, $outletParams);
        }

        $sql = buildItemsSelectSql($whereSql, "ORDER BY i.itemDate DESC LIMIT $limit OFFSET $offset");
        $rs    = $db->Execute($sql, $params);
        $items = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $row) {
                $items[] = presentItem(_flattenJsonb($row));
            }
        }

        // LEFT JOIN taxonomy cat: el WHERE puede referenciar `cat.taxonomyName`
        // (búsqueda por categoría, arriba) — sin el JOIN el COUNT rompe con
        // "missing FROM-clause entry for table cat".
        $countSql = "SELECT COUNT(*) AS n FROM item i LEFT JOIN taxonomy cat ON cat.taxonomyId = i.categoryId WHERE $whereSql";
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
