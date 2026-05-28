<?php
/**
 * /api/v1/customer_address.php — direcciones de cliente (API compartida del sistema).
 *
 *   GET  ?customerId=<uuid>[&addressId=<uuid>]  → lista (o una) dirección
 *   POST op=add|update|delete|setDefault        → ver CustomerAddressService
 *
 * Auth: JWT de tenant (identidad SIEMPRE del token). Envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/CustomerAddressService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

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
