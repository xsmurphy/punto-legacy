<?php
/**
 * REST canónico — Cierres de Caja / Drawers (motor ERP, raw).
 *
 *   GET  /API/v1/reports/drawers?from=&to=          → { rows } CRUDO (lista de cajas).
 *   GET  /API/v1/reports/drawers?id=<uuid>          → { detail } CRUDO (una caja + desglose de pagos).
 *   POST /API/v1/reports/drawers (action=close|correct|delete&id=<uuid> …) → muta.
 *
 * Lectura sin formatear/HTML (el front arma/formatea). Escritura scopeada por COMPANY_ID del JWT.
 * Auth: JWT. Tenant por COMPANY_ID + roc. El detalle se re-consulta por id (no se confía en un
 * blob del cliente, a diferencia del legacy). Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportDrawersService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$svc    = new ReportDrawersService();
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

if ($method === 'POST') {
    // Permiso de escritura: bloquea el rol read-only (7) — misma convención que recurring/expenses.
    if ((int) PANEL_AUTHED_ROLE === 7) {
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
        if (!$svc->remove($id, COMPANY_ID)) {
            apiError('No se pudo eliminar', 500);
        }
        apiOk(['id' => $id, 'action' => 'delete']);
    }

    // montos: número plano (parseo de locale en el front; ver REGLA RAÍZ 2).
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
        // Usuario que cierra = sub del JWT (PANEL_AUTHED_USER). Sólo si es un UUID válido
        // (en la ruta legacy api_key es 0) → si no, se guarda NULL (drawerUserClose es nullable).
        $closer = (string) PANEL_AUTHED_USER;
        if (!preg_match($uuidRe, $closer)) {
            $closer = '';
        }
        if (!$svc->close($id, COMPANY_ID, $closeDate, (float) $closeAmount, $closer)) {
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
    if (!$svc->correct($id, COMPANY_ID, $openDate, $closeDate, (float) $openAmount, (float) $closeAmount)) {
        apiError('No se pudo corregir el cierre', 500);
    }
    apiOk(['id' => $id, 'action' => 'correct']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

// Detalle de una caja.
$id = (string) (validateHttp('id') ?: '');
if ($id !== '') {
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    $detail = $svc->detail($id, COMPANY_ID);
    if ($detail === null) {
        apiNotFound('Caja no encontrada');
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

$roc = getROC(1);
apiOk(['rows' => $svc->listMovements($from, $to, $roc, COMPANY_ID)]);
