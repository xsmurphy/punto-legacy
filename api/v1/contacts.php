<?php
/**
 * REST canónico (API compartida /api) — Contactos (clientes/proveedores, type=1).
 *
 *   GET    /v1/contacts                          → lista paginada (q, status, limit, offset)
 *   GET    /v1/contacts?id=<uuid>                → detalle
 *   GET    /v1/contacts?id=<uuid>&resource=addresses → sub-recurso direcciones
 *   POST   /v1/contacts                          → crea (body: { fiscalName|name, tin, ci, phone... })
 *   PUT    /v1/contacts?id=<uuid>                → update parcial (body: { campos... })
 *   DELETE /v1/contacts?id=<uuid>                → archive (soft-delete: contactStatus = 0)
 *
 * Auth: MULTI-REALM — `apiAuthTenant(['panel','pos-app'])`. Contactos son recursos compartidos:
 * el panel administra el catálogo de clientes y el POS también necesita crearlos / consultarlos
 * en la caja. El plan F2 marca este endpoint como el primero con allowlist multi-realm.
 *
 * Tenant: COMPANY_ID del JWT (panel u POS — ambos lo traen). Lectura paginada con cap a 1000.
 * Escritura sin role-guard en este nivel — el panel local todavía aplica `allowUser('contacts',
 * 'edit')` en a_contacts.php legacy; cuando migre el front estático, el guard de role se mueve acá.
 *
 * Respuesta: envelope canónico { ok, data } / { ok:false, error }.
 *
 * Port FIEL de panel/API/v1/contacts.php (Fase 2 del desacople de /panel). Cambios respecto
 * al original: `apiMiddleware()` → `apiAuthTenant(['panel','pos-app'])`; service en namespace
 * `Punto\Api\Contacts`; `apiNotFound()` / `apiUnprocessable()` → `apiError(..., 404/422)` (en /api
 * solo existen apiOk + apiError).
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'pos-app']);

global $db;

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;
$resource = $_GET['resource'] ?? null;

$service = new \Punto\Api\Contacts\ContactService(new \Punto\Api\Contacts\ContactRepository($db));

// ── Sub-recurso: direcciones ───────────────────────────────────────────────
if ($id !== null && $resource === 'addresses') {
    if ($method === 'GET') {
        apiOk(['addresses' => $service->addresses($id, COMPANY_ID)]);
    }
    apiError('Method not allowed for /contacts/addresses', 405);
}

// ── Sub-recurso: analytics (KPIs + comportamiento del contacto) ────────────
// Alimenta el tab "Resumen" / "Comportamiento" del perfil en frontend.
// type=1 (cliente, default) o type=2 (proveedor) — cambia el set de tx_types
// agregados (venta vs compra) dentro del Service.
if ($id !== null && $resource === 'analytics') {
    if ($method !== 'GET') {
        apiError('Method not allowed for /contacts/analytics', 405);
    }
    $aType = (int) ($_GET['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
    if (!in_array($aType, [\Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER], true)) {
        $aType = \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER;
    }
    require_once __DIR__ . '/../lib/Contacts/ContactAnalyticsService.php';
    $svc = new \Punto\Api\Contacts\ContactAnalyticsService();
    $data = $svc->compute($id, $aType, COMPANY_ID);
    if ($data === null) {
        apiError('Contacto no encontrado', 404);
    }
    apiOk($data);
}

// ── JSON body → $_POST (el front manda JSON, PHP no lo parsea automáticamente) ──
$_raw = file_get_contents('php://input');
if (is_string($_raw) && $_raw !== '') {
    $_json = json_decode($_raw, true);
    if (is_array($_json)) {
        $_POST = array_merge($_POST, $_json);
    }
}

// ── Recurso principal ───────────────────────────────────────────────────────
// Defense-in-depth: cada case termina por apiOk/apiError (que llaman exit), así que el
// fall-through no ocurre HOY — pero un break; en cada case previene un fall-through silente
// si un futuro edit agrega una branch no-terminante.
switch ($method) {
    case 'GET':
        // type: 1 = cliente (default), 2 = proveedor. El front pasa ?type=2
        // para el tab proveedores. Valores fuera de [1, 2] caen a cliente.
        $type = (int) ($_GET['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
        if (!in_array($type, [\Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER], true)) {
            $type = \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER;
        }

        if ($id !== null) {
            $contact = $service->getByType($id, $type, COMPANY_ID);
            if ($contact === null) apiError('Contacto no encontrado', 404);
            apiOk($contact);
        }

        apiOk($service->listByType($type, COMPANY_ID, [
            'q'      => $_GET['q']      ?? null,
            'status' => $_GET['status'] ?? null,
            'limit'  => $_GET['limit']  ?? 50,
            'offset' => $_GET['offset'] ?? 0,
        ]));
        break;

    case 'POST':
        try {
            $newId = $service->create(COMPANY_ID, $_POST);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 500);
        }

        // Type del row recién creado: sin filtrar acepta cualquier tipo.
        $newType = (int) ($_POST['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
        $contact = $service->getByType($newId, $newType, COMPANY_ID);
        apiOk($contact ?? ['id' => $newId, 'UID' => $newId], 201);
        break;

    case 'PUT':
        if ($id === null) apiError('id es requerido para PUT', 422);

        $patch = $_POST;
        unset($patch['id'], $patch['contactId'], $patch['companyId'], $patch['type']);
        if (empty($patch)) apiError('Patch vacío', 422);

        if (!$service->update($id, COMPANY_ID, $patch)) {
            apiError('Update falló', 500);
        }

        $contact = $service->getCustomer($id, COMPANY_ID);
        apiOk($contact ?? ['id' => $id, 'UID' => $id]);
        break;

    case 'DELETE':
        if ($id === null) apiError('id es requerido para DELETE', 422);
        if (!$service->archive($id, COMPANY_ID)) {
            apiError('Archive falló', 500);
        }
        apiOk(['archived' => true, 'id' => $id]);
        break;

    default:
        apiError('Method not allowed', 405);
}
