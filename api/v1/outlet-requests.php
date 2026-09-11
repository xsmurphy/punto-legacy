<?php
declare(strict_types=1);

/**
 * /v1/outlet-requests — solicitudes de alta de sucursal (realm `panel`).
 *
 *   GET  → estado para el panel: { pending, outletCount, plan, currentMonthly, nextMonthly }
 *   POST → crea la solicitud (no la sucursal). body { name, address? }
 *
 * Permiso: `settings.outlet.manage` — la MISMA clave que gatea el alta y la
 * edición de sucursales en `/v1/outlets.php`. Quien puede administrar
 * sucursales puede pedir una nueva; nadie más.
 *
 * El alta real NO pasa por acá: la crea /admin al aprobar, por
 * `Outlets\OutletsService::create()`. Ver `api/lib/Outlets/OutletRequestService.php`.
 *
 * El GET también devuelve el PRECIO del plan para el diálogo del paywall. Está
 * acá y no en `/v1/billing` a propósito: ese endpoint exige `billing.view`, un
 * permiso que el encargado de sucursales puede no tener. Ver el docblock de
 * `OutletRequestService::status()`.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Outlets\OutletRequestService;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Solo realm panel: es una acción de administración del comercio, no de la
// caja ni de una API key.
$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'] ?? null;

if (!hasPermission('settings.outlet.manage')) {
    apiError('No tenés permiso para esta acción (requiere: settings.outlet.manage)', 403);
}

$svc = new OutletRequestService();

if ($method === 'GET') {
    apiOk($svc->status($companyId));
}

if ($method === 'POST') {
    $name    = (string) ($_POST['name'] ?? '');
    $address = isset($_POST['address']) ? (string) $_POST['address'] : null;

    $res = $svc->create($companyId, $userId !== null ? (string) $userId : null, $name, $address);

    if (!$res['ok']) {
        apiError($res['error'] ?? 'No se pudo registrar la solicitud', $res['code'] ?? 422);
    }

    // Auditoría del PEDIDO, no solo del hit al endpoint.
    //
    // `apiAuthTenant()` ya escribió la fila automática del embudo
    // (`bootstrap.php`), pero esa fila se arma ANTES de que corra el handler y
    // no puede saber qué sucursal se pidió. Esta segunda fila es la que deja el
    // hecho de negocio consultable: qué nombre pidió el comercio y qué
    // solicitud generó. Es el mismo criterio de `context/66` D2 — auditar el
    // pedido, no solo el efecto.
    tenantAudit(
        [
            'companyId' => $companyId,
            'outletId'  => $ctx['outletId'] ?? null,
            'userId'    => $userId,
            'realm'     => $ctx['realm'] ?? 'panel',
        ],
        'POST',
        'outlet-request.create',
        (string) $res['requestId'],
        ['name' => $name, 'address' => $address]
    );

    // El estado nuevo viaja de vuelta: el switcher pinta "Solicitud pendiente"
    // sin un segundo round-trip.
    apiOk(['requestId' => $res['requestId'], 'status' => $svc->status($companyId)], 201);
}

apiError('Method not allowed', 405);
