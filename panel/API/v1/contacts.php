<?php
/**
 * REST canónico para Contactos (clientes/proveedores, type=1).
 *
 *   GET    /API/v1/contacts                   → lista paginada (q, status, limit, offset)
 *   GET    /API/v1/contacts?id=<uuid>         → detalle
 *   POST   /API/v1/contacts                   → crea (body: { fiscalName|name, tin, ci, phone... })
 *   PUT    /API/v1/contacts?id=<uuid>         → update parcial (body: { campos... })
 *   DELETE /API/v1/contacts?id=<uuid>         → archive (soft-delete: contactStatus = 0)
 *
 * Sub-recurso direcciones:
 *   GET    /API/v1/contacts?id=<uuid>&resource=addresses
 *
 * Auth: JWT en cookie _jwt_panel / Authorization: Bearer / POST _jwt
 *       Fallback legacy api_key + company_id, o sesión PHP del panel.
 *
 * Respuesta: envelope canónico { ok, data, meta } / { ok:false, error }.
 */

require_once __DIR__ . '/../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../lib/contacts/ContactService.php';

global $db;

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;
$resource = $_GET['resource'] ?? null;

$service = new ContactService(new ContactRepository($db));

// ── Sub-recurso: direcciones ───────────────────────────────────────────────
if ($id !== null && $resource === 'addresses') {
    if ($method === 'GET') {
        apiOk(['addresses' => $service->addresses($id, COMPANY_ID)]);
    }
    apiError('Method not allowed for /contacts/addresses', 405);
}

// ── Recurso principal ───────────────────────────────────────────────────────
switch ($method) {
    case 'GET':
        if ($id !== null) {
            $contact = $service->getCustomer($id, COMPANY_ID);
            if ($contact === null) apiNotFound('Contacto no encontrado');
            apiOk($contact);
        }

        apiOk($service->listCustomers(COMPANY_ID, [
            'q'      => $_GET['q']      ?? null,
            'status' => $_GET['status'] ?? null,
            'limit'  => $_GET['limit']  ?? 50,
            'offset' => $_GET['offset'] ?? 0,
        ]));

    case 'POST':
        try {
            $newId = $service->create(COMPANY_ID, $_POST);
        } catch (InvalidArgumentException $e) {
            apiUnprocessable($e->getMessage());
        } catch (RuntimeException $e) {
            apiError($e->getMessage(), 500);
        }

        $contact = $service->getCustomer($newId, COMPANY_ID);
        apiOk($contact ?? ['id' => $newId, 'UID' => $newId], 201);

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

    case 'DELETE':
        if ($id === null) apiError('id es requerido para DELETE', 422);
        if (!$service->archive($id, COMPANY_ID)) {
            apiError('Archive falló', 500);
        }
        apiOk(['archived' => true, 'id' => $id]);

    default:
        apiError('Method not allowed', 405);
}
