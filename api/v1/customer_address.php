<?php
/**
 * /api/v1/customer_address.php — direcciones de cliente (API compartida). Verbos REST (§22.7).
 *
 *   GET    ?customerId=<uuid>[&addressId=<uuid>]   → lista (o una) dirección
 *   POST   { customerId, ...campos }               → crea una dirección
 *   PUT    ?id=<addressId> { customerId, ...campos }            → actualiza
 *   PUT    ?id=<addressId>&resource=default { customerId }      → marca como default
 *   DELETE ?id=<addressId>&customerId=<uuid>       → elimina
 *
 * Auth: JWT de tenant (identidad SIEMPRE del token). Envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/CustomerAddressService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\CustomerAddressService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];

$svc    = new CustomerAddressService(TenantContext::fromAuth($ctx));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$fieldsFromBody = static fn(): array => [
    'name'     => $_POST['name']     ?? '',
    'address'  => $_POST['address']  ?? '',
    'location' => $_POST['location'] ?? '',
    'city'     => $_POST['city']     ?? '',
    // lat/lng pueden venir como números (cliente nuevo) o como string vacío.
    // latLng se mantiene por back-compat con clientes legacy.
    'lat'      => $_POST['lat']      ?? null,
    'lng'      => $_POST['lng']      ?? null,
    'latLng'   => $_POST['latLng']   ?? '',
];

switch ($method) {
    case 'GET':
        $customerId = trim((string) ($_GET['customerId'] ?? ''));
        $addressId  = trim((string) ($_GET['addressId'] ?? ''));
        if ($customerId === '' && $addressId === '') {
            apiError('Falta customerId', 422);
        }
        apiOk($svc->listForCustomer($companyId, $customerId, $addressId !== '' ? $addressId : null));

    case 'POST':
        $customerId = trim((string) ($_POST['customerId'] ?? ''));
        if ($customerId === '') {
            apiError('Falta customerId', 422);
        }
        $res = $svc->add($companyId, $customerId, $fieldsFromBody());
        break;

    case 'PUT':
        $customerId = trim((string) ($_POST['customerId'] ?? ''));
        $addressId  = trim((string) ($_GET['id'] ?? ''));
        if ($customerId === '' || $addressId === '') {
            apiError('Faltan customerId/id', 422);
        }
        $res = (($_GET['resource'] ?? '') === 'default')
            ? $svc->setDefault($companyId, $customerId, $addressId)
            : $svc->update($companyId, $customerId, $addressId, $fieldsFromBody());
        break;

    case 'DELETE':
        $customerId = trim((string) ($_GET['customerId'] ?? ''));
        $addressId  = trim((string) ($_GET['id'] ?? ''));
        if ($customerId === '' || $addressId === '') {
            apiError('Faltan customerId/id', 422);
        }
        $res = $svc->delete($companyId, $customerId, $addressId);
        break;

    default:
        apiError('Método no permitido', 405);
}

if (empty($res['ok'])) {
    apiError('No se pudo procesar la operación', 500);
}
apiOk($res);
