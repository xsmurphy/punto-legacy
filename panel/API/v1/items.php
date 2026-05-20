<?php
/**
 * REST canónico para Items.
 *
 *   GET    /API/v1/items                   → lista paginada
 *   GET    /API/v1/items?id=<uuid>         → detalle
 *   POST   /API/v1/items                   → crea (body: { type? })
 *   PUT    /API/v1/items?id=<uuid>         → update parcial (body: { campos... })
 *   DELETE /API/v1/items?id=<uuid>         → archive (soft-delete)
 *
 * Sub-recurso depósitos:
 *   GET    /API/v1/items?id=<uuid>&resource=locations
 *   PUT    /API/v1/items?id=<uuid>&resource=locations  body: { locationIds:[], default? }
 *
 * Auth: JWT en cookie _jwt_panel / Authorization: Bearer / POST _jwt
 *       Fallback legacy api_key + company_id.
 *
 * Respuesta: envelope canónico { ok, data, meta } / { ok:false, error }.
 */

require_once __DIR__ . '/../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../lib/items/ItemService.php';
require_once __DIR__ . '/../../lib/items/LocationService.php';

global $db;

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;
$resource = $_GET['resource'] ?? null;

$itemService = new ItemService(new ItemRepository($db));
$locService  = new LocationService($db);

// ── Sub-recurso: depósitos ────────────────────────────────────────────────
if ($id !== null && $resource === 'locations') {
    if ($method === 'GET') {
        apiOk(['locations' => $locService->listForItem($id)]);
    }

    if ($method === 'PUT') {
        $locationIds = $_POST['locationIds'] ?? [];
        if (!is_array($locationIds)) apiError('locationIds must be array', 422);
        $default = $_POST['default'] ?? null;

        $locService->syncForItem($id, COMPANY_ID, $locationIds, $default);
        apiOk(['updated' => true, 'count' => count($locationIds)]);
    }

    apiError('Method not allowed for /items/locations', 405);
}

// ── Recurso principal ─────────────────────────────────────────────────────
switch ($method) {
    case 'GET':
        if ($id !== null) {
            $item = $itemService->find($id, COMPANY_ID);
            if ($item === null) apiNotFound('Item no encontrado');
            apiOk($item->toArray());
        }

        $limit  = max(1, min((int) ($_GET['limit'] ?? 50), 200));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $where  = ['companyId = ?', 'itemStatus = ?'];
        $params = [COMPANY_ID, (int) ($_GET['archived'] ?? 0) === 1 ? 0 : 1];

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

    case 'POST':
        $type   = $_POST['type'] ?? null;
        $newId  = $itemService->createBlank(COMPANY_ID, $type);
        if ($newId === false) apiError('No se pudo crear el item', 500);

        // Si vinieron más campos en el body, aplicarlos como UPDATE
        $extras = array_diff_key($_POST, ['type' => 1]);
        if (!empty($extras)) {
            $itemService->update($newId, COMPANY_ID, $extras);
        }

        $item = $itemService->find($newId, COMPANY_ID);
        apiOk($item !== null ? $item->toArray() : ['itemId' => $newId], 201);

    case 'PUT':
        if ($id === null) apiError('id es requerido para PUT', 422);

        $patch = $_POST;
        // Quitar campos meta del body
        unset($patch['id'], $patch['itemId'], $patch['companyId']);
        if (empty($patch)) apiError('Patch vacío', 422);

        $ok = $itemService->update($id, COMPANY_ID, $patch);
        if (!$ok) apiError('Update falló', 500);

        $item = $itemService->find($id, COMPANY_ID);
        apiOk($item !== null ? $item->toArray() : ['itemId' => $id]);

    case 'DELETE':
        if ($id === null) apiError('id es requerido para DELETE', 422);
        $ok = $itemService->archive($id, COMPANY_ID);
        if (!$ok) apiError('Archive falló', 500);
        apiOk(['archived' => true, 'itemId' => $id]);

    default:
        apiError('Method not allowed', 405);
}
