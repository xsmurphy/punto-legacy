<?php
/**
 * REST canónico (API compartida /api) — Taxonomies (catálogo de categorías,
 * marcas, impuestos, ubicaciones, etc.).
 *
 *   GET /v1/taxonomies                → lista de TODAS las taxonomías del tenant
 *   GET /v1/taxonomies?type=<type>    → filtrado por type (category, brand,
 *                                        tax, location, supplier, ...)
 *
 * Read-only por ahora; las taxonomías se crean en el panel legacy. Cuando
 * frontend implemente CRUD de categorías/marcas, se agregan POST/PUT/DELETE.
 *
 * Auth: realm panel (apiAuthTenant(['panel'])). Multi-tenant scoping via
 * companyId del JWT.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $type   = trim((string) ($_POST['type'] ?? ''));
    if ($type !== 'location') {
        apiError('Solo type=location es editable desde este endpoint', 422);
    }
    if (!in_array($action, ['create', 'update', 'delete'], true)) {
        apiError('Acción inválida', 422);
    }
    global $db;
    $svc = new \Punto\Api\Taxonomies\LocationTaxonomyService($db);

    if ($action === 'create') {
        $outletId = trim((string) ($_POST['outletId'] ?? ''));
        $name     = trim((string) ($_POST['name'] ?? ''));
        if ($outletId === '' || $name === '') {
            apiError('outletId y name son requeridos', 422);
        }
        try {
            $result = $svc->create(COMPANY_ID, $outletId, $name);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 500);
        }
        realtimePublish('location', 'create', $result['id']);
        apiOk(['location' => $result]);
    }

    if ($action === 'update') {
        $id   = trim((string) ($_POST['id'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($id === '' || $name === '') {
            apiError('id y name son requeridos', 422);
        }
        $ok = $svc->update(COMPANY_ID, $id, $name);
        if (!$ok) { apiError('Depósito no encontrado', 404); }
        realtimePublish('location', 'update', $id);
        apiOk(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') { apiError('id es requerido', 422); }
        $result = $svc->delete(COMPANY_ID, $id);
        if ($result['blocked']) {
            apiError(
                'No se puede eliminar: hay ' . $result['items'] . ' artículos asignados a este depósito',
                409
            );
        }
        realtimePublish('location', 'delete', $id);
        apiOk(['ok' => true]);
    }
}

if ($method !== 'GET') {
    apiError('Solo GET o POST con action soportados', 405);
}

$type = trim((string) ($_GET['type'] ?? ''));

$where  = ['companyId = ?'];
$params = [COMPANY_ID];
if ($type !== '') {
    $where[]  = 'taxonomyType = ?';
    $params[] = $type;
}

$sql = "SELECT taxonomyId, taxonomyName, taxonomyType, taxonomyExtra, outletId
          FROM taxonomy
         WHERE " . implode(' AND ', $where) . "
         ORDER BY taxonomyType ASC, taxonomyName ASC";

global $db;
$rs = $db->Execute($sql, $params);
if ($rs === false) {
    apiError('Error consultando taxonomías', 500);
}

$out = [];
foreach ($rs->GetRows() as $row) {
    // Para impuestos, taxonomyName trae el porcentaje como string ("10").
    // Para categorías/marcas, taxonomyName es el nombre user-facing.
    // outletId aplica solo a type='location' — para los demás es null.
    $out[] = [
        'id'       => (string) $row['taxonomyid'],
        'name'     => (string) ($row['taxonomyname'] ?? ''),
        'type'     => (string) ($row['taxonomytype'] ?? ''),
        'extra'    => $row['taxonomyextra'] ?? null,
        'outletId' => $row['outletid'] ?? null,
    ];
}

apiOk(['taxonomies' => $out]);
