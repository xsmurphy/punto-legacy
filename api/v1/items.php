<?php
/**
 * REST canónico (API compartida /api) — Items (catálogo del tenant).
 *
 * Endpoint MULTI-REALM con dos personalidades coexistiendo:
 *
 *   ── Panel CRUD (rama F2) ──────────────────────────────────────────────────
 *   GET    /v1/items                            → lista paginada (q, type, archived, limit, offset)
 *   GET    /v1/items?id=<uuid>                  → detalle
 *   GET    /v1/items?id=<uuid>&resource=locations → depósitos asignados
 *   POST   /v1/items                            → crea blank (body: { type? } + extras opcionales)
 *   PUT    /v1/items?id=<uuid>                  → update parcial
 *   PUT    /v1/items?id=<uuid>&resource=locations body: { locationIds:[], default? }
 *   DELETE /v1/items?id=<uuid>                  → archive (soft-delete)
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
 *
 * Port FIEL de panel/API/v1/items.php (panel CRUD) + preservación del slice 25 (POS info).
 * Cambios respecto al original del panel: `apiMiddleware()` → `apiAuthTenant(['panel','pos-app'])`;
 * `apiNotFound` → `apiError(..., 404)`; services en namespace `Punto\Api\Items`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/services/ItemService.php';

use Punto\Api\Context\TenantContext;

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

$id = $_GET['id'] ?? null;

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
            apiOk($item->toArray());
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
        if (!empty($_GET['type'])) {
            $where[]  = 'itemType = ?';
            $params[] = $_GET['type'];
        }

        $sql = "SELECT itemId, itemName, itemSKU, itemType, itemStatus,
                       itemPrice, itemDate, updated_at, data
                  FROM item
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY itemDate DESC
                 LIMIT $limit OFFSET $offset";
        $rs    = $db->Execute($sql, $params);
        $items = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $row) {
                $items[] = _flattenJsonb($row)->toArray();
            }
        }

        // Total para paginación
        $countSql = "SELECT COUNT(*) AS n FROM item WHERE " . implode(' AND ', $where);
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
        // El plan-max-check y allowUser del handler legacy (?action=insertBtn)
        // requieren constantes (SAAS_ADM, PLAN, plansValues por outlet) que el
        // bootstrap mínimo de apiAuthTenant NO carga. Por ahora la API confía
        // en la autenticación de JWT — todo usuario logueado puede crear items.
        // Plan-limits deberán moverse a un middleware aparte cuando se integren.
        $type   = $_POST['type'] ?? null;
        $newId  = $itemService->createBlank($companyId, $type);
        if ($newId === false) apiError('No se pudo crear el item', 500);

        // Si vinieron más campos en el body, aplicarlos como UPDATE
        $extras = array_diff_key($_POST, ['type' => 1]);
        if (!empty($extras)) {
            $itemService->update($newId, $companyId, $extras);
        }

        $item = $itemService->find($newId, $companyId);
        apiOk($item !== null ? $item->toArray() : ['itemId' => $newId], 201);
        break;

    case 'PUT':
        if ($id === null) apiError('id es requerido para PUT', 422);

        $patch = $_POST;
        // Quitar campos meta del body
        unset($patch['id'], $patch['itemId'], $patch['companyId']);
        if (empty($patch)) apiError('Patch vacío', 422);

        $ok = $itemService->update($id, $companyId, $patch);
        if (!$ok) apiError('Update falló', 500);

        $item = $itemService->find($id, $companyId);
        apiOk($item !== null ? $item->toArray() : ['itemId' => $id]);
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
