<?php
/**
 * REST canónico (API compartida /api) — Sucursales (motor ERP, raw).
 *
 *   GET  /v1/outlets                 → lista de sucursales de la company (CRUDA).
 *   GET  /v1/outlets?id=<uuid>        → una sucursal (campos completos para el form).
 *   POST /v1/outlets (action=update&id=<uuid> + campos) → actualiza.
 *
 * Lectura sin formatear/HTML (el front formatea + arma). Escritura scopeada por COMPANY_ID del
 * JWT. Auth: realm `panel` (apiAuthTenant(['panel'])).
 *
 * Port FIEL de panel/API/v1/outlets.php (Fase 2 del desacople de /panel). Cambios respecto al
 * original: `apiMiddleware()` → `apiAuthTenant(['panel'])`; `PANEL_AUTHED_ROLE` → `$ctx['roleId']`;
 * service en namespace `Punto\Api\Outlets`. El contrato (acciones, shape, status codes) es
 * idéntico — el JS de a_outlets.js depende de él (createOutlet/removeOutlet usan action=create
 * y action=delete contra este endpoint vía /bff/outlets.php).
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Outlets\OutletsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'POST') {
    // Permiso de escritura: bloquea el rol read-only (7) — misma convención que los reportes con WRITE.
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['update', 'create', 'delete'], true)) {
        apiError('Acción no soportada', 422);
    }

    if ($action === 'create') {
        $newId = $svc->create(COMPANY_ID);
        if ($newId === null) {
            apiError('No se pudo crear la sucursal', 500);
        }
        apiOk(['id' => $newId]);
    }

    if ($action === 'delete') {
        $id = (string) (validateHttp('id', 'post') ?: '');
        if (!preg_match($uuidRe, $id)) {
            apiError('id inválido', 422);
        }
        if ($id === OUTLET_ID) {
            apiError('No se puede eliminar la sucursal activa', 422);
        }
        if (!$svc->delete($id, COMPANY_ID)) {
            apiError('No se pudo eliminar la sucursal', 500);
        }
        apiOk(['id' => $id]);
    }

    // action === 'update'
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }

    $name = trim((string) (validateHttp('name', 'post') ?: ''));
    if ($name === '') { $name = 'New Location'; }

    // purchaseOrderNo: entero o null. El parseo de locale es presentacional (front); acá solo int.
    $poRaw = (string) (validateHttp('purchaseOrderNo', 'post') ?: '');
    $po    = ($poRaw !== '' && is_numeric($poRaw)) ? (int) $poRaw : null;

    $taxId = (string) (validateHttp('tax', 'post') ?: '');
    if ($taxId !== '' && !preg_match($uuidRe, $taxId)) {
        apiError('impuesto inválido', 422);
    }

    $fields = [
        'name'            => $name,
        'address'         => (string) (validateHttp('address', 'post') ?: ''),
        'phone'           => (string) (validateHttp('phone', 'post') ?: ''),
        'email'           => (string) (validateHttp('email', 'post') ?: ''),
        'description'     => (string) (validateHttp('description', 'post') ?: ''),
        'status'          => validateHttp('status', 'post') ? 1 : 0,
        'billingName'     => (string) (validateHttp('billingName', 'post') ?: ''),
        'ruc'             => (string) (validateHttp('ruc', 'post') ?: ''),
        'whatsApp'        => (string) (validateHttp('whatsApp', 'post') ?: ''),
        'purchaseOrderNo' => $po,
        'latLng'          => (string) (validateHttp('latLng', 'post') ?: ''),
        'taxId'           => $taxId,
        'ecom'            => (bool) validateHttp('ecom', 'post'),
        'taxIncluded'     => (bool) validateHttp('taxIncluded', 'post'),
        'businessHours'   => (string) (validateHttp('businessHours', 'post') ?: ''),
    ];

    if (!$svc->update($id, COMPANY_ID, $fields)) {
        apiError('No se pudo actualizar', 500);
    }
    apiOk(['id' => $id, 'action' => 'update']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$id = (string) (validateHttp('id') ?: '');
if ($id !== '') {
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    $row = $svc->get($id, COMPANY_ID);
    if ($row === null) {
        apiError('Sucursal no encontrada', 404);
    }
    $row['availableTaxes'] = $svc->taxes(COMPANY_ID);
    apiOk($row);
}

apiOk(['rows' => $svc->listAll(COMPANY_ID)]);
