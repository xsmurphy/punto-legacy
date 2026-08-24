<?php
/**
 * REST canónico (API compartida /api) — Taxonomies (catálogo de categorías,
 * marcas, impuestos, ubicaciones, etc.).
 *
 *   GET /v1/taxonomies                → lista de TODAS las taxonomías del tenant
 *   GET /v1/taxonomies?type=<type>    → filtrado por type (category, brand,
 *                                        tax, location, supplier, ...)
 *
 *   POST /v1/taxonomies (action=create|update|setDefault|delete)
 *                                     → SOLO type=location (depósitos). El
 *                                        resto de las taxonomías se sigue
 *                                        creando en el panel legacy.
 *
 * Auth: realm panel (apiAuthTenant(['panel'])). Multi-tenant scoping via
 * companyId del JWT.
 *
 * Autorización de escritura: por TIPO, vía `$taxonomyWritePermission` más
 * abajo. Autenticar no es autorizar — sin ese gate cualquier sesión de panel
 * (un cajero, por ejemplo) podía borrar depósitos del tenant.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Permiso de ESCRITURA por tipo de taxonomía.
 *
 * Hasta acá el POST no chequeaba ningún permiso: bastaba una sesión de panel
 * —la de un cajero incluida— para crear, renombrar, marcar por defecto o
 * borrar depósitos del tenant. `apiAuthTenant(['panel'])` autentica, no
 * autoriza.
 *
 * `settings.outlet.manage` y no `settings.tax.manage`: el depósito NO es una
 * tasa de impuesto, es parte de la configuración de la SUCURSAL que lo
 * contiene (`taxonomy.outletId`, y `LocationTaxonomyService` valida que el
 * outlet sea del tenant). Se administra desde la pantalla de sucursales y su
 * borrado mueve la asignación de artículos, así que le corresponde el MISMO
 * permiso con el que ya se crea o se borra la sucursal entera en
 * `/v1/outlets` (outlets.php:45). El rol seed `manager` lo tiene
 * (`RoleService::SEED_PERMISSIONS`); `cashier` no.
 *
 * Mapa y no un `if` suelto: hoy solo `location` es editable por acá, pero
 * cuando se abra otro tipo (categorías, marcas) el default fail-closed lo
 * rechaza hasta que alguien le asigne su propio permiso — un gate único
 * heredaría en silencio el permiso equivocado.
 */
$taxonomyWritePermission = [
    'location' => 'settings.outlet.manage',
];

if ($method === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $type   = trim((string) ($_POST['type'] ?? ''));
    $perm   = $taxonomyWritePermission[$type] ?? null;
    if ($perm === null) {
        apiError('Solo type=location es editable desde este endpoint', 422);
    }
    if (!hasPermission($perm)) {
        apiError("No tenés permiso para esta acción (requiere: {$perm})", 403);
    }
    if (!in_array($action, ['create', 'update', 'delete', 'setDefault'], true)) {
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

    if ($action === 'setDefault') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') { apiError('id es requerido', 422); }
        $ok = $svc->setDefault(COMPANY_ID, $id);
        if (!$ok) { apiError('Depósito no encontrado', 404); }
        realtimePublish('location', 'update', $id);
        apiOk(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') { apiError('id es requerido', 422); }
        $result = $svc->delete(COMPANY_ID, $id);
        if ($result['blocked']) {
            if (($result['reason'] ?? '') === 'default') {
                apiError(
                    'No se puede eliminar el depósito por defecto de la sucursal. '
                    . 'Marcá otro como predeterminado primero.',
                    409
                );
            }
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

// Filtro por sucursal (aplica a type='location'). Antes el front se traía
// TODAS las taxonomías del tenant y filtraba por `outletId` en el cliente —
// un filtro que le corresponde a la BD, que además ya tiene el índice
// `idx_taxonomy_type_outlet` para resolverlo.
$outletFilter = trim((string) ($_GET['outletId'] ?? ''));
if ($outletFilter !== '') {
    // Validar la forma ANTES de mandarlo a PG: un valor no-UUID hace que
    // Postgres tire 22P02, el wrapper lanza DbQueryException y el request
    // termina en un 500 sin JSON en vez de un 422 legible.
    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $outletFilter)) {
        apiError('outletId inválido', 422);
    }
    $where[]  = 'outletId = ?';
    $params[] = $outletFilter;
}

// `isDefault` sale de la MISMA función que usa el índice único y los lectores
// del ledger (mig 165) — no de un `->> 'isDefault'` inline acá, para que
// "cuál es el depósito por defecto" tenga una sola definición en todo el
// sistema. Aplica solo a type='location'; para el resto es siempre false.
// Se emite 1/0 y no un boolean de PG a propósito: PDO_PGSQL devuelve las
// columnas booleanas como los strings 't'/'f', y 't' NO está en la lista que
// FILTER_VALIDATE_BOOLEAN considera verdadera ("1"/"true"/"on"/"yes") → el
// flag habría llegado siempre en false al front.
$sql = "SELECT taxonomyId, taxonomyName, taxonomyType, taxonomyExtra, outletId,
               CASE WHEN fn_taxonomy_is_default_location(taxonomyType, taxonomyExtra)
                    THEN 1 ELSE 0 END AS isdefault
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
        // Solo tiene sentido para type='location': marca el depósito
        // preseleccionado de esa sucursal.
        'isDefault' => (bool) (int) ($row['isdefault'] ?? 0),
    ];
}

apiOk(['taxonomies' => $out]);
