<?php
/**
 * /API/v1/customer_address.php — direcciones de cliente del POS (slice 1 del desacople de /app).
 *
 * Reemplaza los handlers customerAddress* del monolito action.php/load.php.
 * Gateado por JWT (_jwt). Identidad SIEMPRE del token; nada de client-supplied.
 *
 *   GET  ?customerId=<uuid>[&addressId=<uuid>]  → lista (o una) dirección
 *   POST op=add        { customerId, name, address, location, city, latLng }
 *   POST op=update     { customerId, addressId, name, address, location, city, latLng }
 *   POST op=delete     { customerId, addressId }
 *   POST op=setDefault { customerId, addressId }
 *
 * Envelope canónico { ok, data } / { ok:false, error }. Ver context/05-modulos-clave.md.
 */

session_start();

// Los includes de head.php/data.php son relativos al cwd → fijarlo en /app.
$appDir = dirname(__DIR__, 2);
chdir($appDir);

require_once $appDir . '/includes/cors.php';
require_once $appDir . '/includes/jwt_middleware.php';
require_once __DIR__ . '/../lib/response.php';

// head.php carga simple.config.php (que puebla $_ENV['JWT_SECRET']), db, helpers
// ncm*, enc/dec. DEBE ir ANTES de jwtAuthenticate() — igual orden que action.php.
$rateLimiterId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
require_once $appDir . '/head.php';

if (!jwtAuthenticate()) {
    apiError('Autenticación requerida', 401);
}

$companyId  = AUTHED_COMPANY_ID;
$outletId   = AUTHED_OUTLET_ID;
$userId     = AUTHED_USER_ID;
$registerId = AUTHED_REGISTER_ID;
$roleId     = AUTHED_ROLE_ID;

if (!checkCompanyStatus($companyId)) {
    apiError('Company Blocked', 403);
}

require_once $appDir . '/data.php';
require_once $appDir . '/lib/CustomerAddressService.php';

$svc    = new CustomerAddressService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $customerId = trim((string) ($_GET['customerId'] ?? ''));
    $addressId  = trim((string) ($_GET['addressId'] ?? ''));
    if ($customerId === '' && $addressId === '') {
        apiError('Falta customerId', 422);
    }
    apiOk($svc->listForCustomer($companyId, $customerId, $addressId !== '' ? $addressId : null));
}

if ($method === 'POST') {
    $op         = (string) ($_POST['op'] ?? '');
    $customerId = trim((string) ($_POST['customerId'] ?? ''));
    $addressId  = trim((string) ($_POST['addressId'] ?? ''));
    $fields     = [
        'name'     => $_POST['name']     ?? '',
        'address'  => $_POST['address']  ?? '',
        'location' => $_POST['location'] ?? '',
        'city'     => $_POST['city']     ?? '',
        'latLng'   => $_POST['latLng']   ?? '',
    ];

    if ($customerId === '') {
        apiError('Falta customerId', 422);
    }
    if (in_array($op, ['update', 'delete', 'setDefault'], true) && $addressId === '') {
        apiError('Falta addressId', 422);
    }

    switch ($op) {
        case 'add':
            $res = $svc->add($companyId, $customerId, $fields);
            break;
        case 'update':
            $res = $svc->update($companyId, $customerId, $addressId, $fields);
            break;
        case 'delete':
            $res = $svc->delete($companyId, $customerId, $addressId);
            break;
        case 'setDefault':
            $res = $svc->setDefault($companyId, $customerId, $addressId);
            break;
        default:
            apiError('Operación no soportada', 400);
    }

    if (empty($res['ok'])) {
        apiError('No se pudo procesar la operación', 500);
    }
    apiOk($res);
}

apiError('Método no permitido', 405);
