<?php
/**
 * REST canónico (API compartida /api) — Cierres de Caja / Drawers (raw).
 *
 *   GET  /v1/reports/drawers?from=&to=             → { rows } CRUDO.
 *   GET  /v1/reports/drawers?id=<uuid>             → { detail } CRUDO.
 *   POST /v1/reports/drawers (action=close|correct|delete&id=<uuid> …) → muta.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet. Writes scopeados por companyId del JWT.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\DrawersService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

if ($method === 'POST') {
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['close', 'correct', 'delete'], true)) {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }

    if ($action === 'delete') {
        if (!$svc->remove($id, (string) COMPANY_ID)) {
            apiError('No se pudo eliminar', 500);
        }
        apiOk(['id' => $id, 'action' => 'delete']);
    }

    $closeAmount = (string) (validateHttp('closeAmount', 'post') ?: '0');
    if (!is_numeric($closeAmount)) {
        apiError('monto de cierre inválido', 422);
    }
    $closeDate = (string) (validateHttp('closeDate', 'post') ?: '');
    if ($closeDate !== '' && !preg_match($dateRe, $closeDate)) {
        apiError('fecha de cierre inválida', 422);
    }

    if ($action === 'close') {
        if ($closeDate === '') {
            apiError('fecha de cierre requerida', 422);
        }
        // Usuario que cierra = sub del JWT. Si no es UUID → NULL.
        $closer = (string) $ctx['userId'];
        if (!preg_match($uuidRe, $closer)) {
            $closer = '';
        }
        if (!$svc->close($id, (string) COMPANY_ID, $closeDate, (float) $closeAmount, $closer)) {
            apiError('No se pudo cerrar la caja', 500);
        }
        apiOk(['id' => $id, 'action' => 'close']);
    }

    // action === 'correct'
    $openDate   = (string) (validateHttp('openDate', 'post') ?: '');
    $openAmount = (string) (validateHttp('openAmount', 'post') ?: '0');
    if (!preg_match($dateRe, $openDate)) {
        apiError('fecha de apertura inválida', 422);
    }
    if (!is_numeric($openAmount)) {
        apiError('monto de apertura inválido', 422);
    }
    if (!$svc->correct($id, (string) COMPANY_ID, $openDate, $closeDate, (float) $openAmount, (float) $closeAmount)) {
        apiError('No se pudo corregir el cierre', 500);
    }
    apiOk(['id' => $id, 'action' => 'correct']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

// Detalle de una caja.
$id = (string) (validateHttp('id') ?: '');
if ($id !== '') {
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    $detail = $svc->detail($id, (string) COMPANY_ID, $roc);
    if ($detail === null) {
        apiError('Caja no encontrada', 404);
    }
    apiOk(['detail' => $detail]);
}

// Lista por período.
$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

apiOk(['rows' => $svc->listMovements($from, $to, $roc, (string) COMPANY_ID)]);
