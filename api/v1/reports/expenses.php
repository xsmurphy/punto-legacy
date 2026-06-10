<?php
/**
 * REST canónico (API compartida /api) — Movimientos de Caja (motor ERP, raw).
 *
 *   GET  /v1/reports/expenses?from=&to=                          → { rows, users } CRUDO.
 *   POST /v1/reports/expenses (action=update|delete&id=<uuid> [+date/total/note/user]) → muta.
 *
 * Lectura sin formatear/HTML (el front mapea/formatea). Escritura scopeada por COMPANY_ID del
 * JWT. Auth: realm `panel` (apiAuthTenant(['panel'])). Tenant por COMPANY_ID + outlet (ROC).
 * Ver REGLA RAÍZ 2.
 *
 * Primer endpoint del panel servido por la /api compartida (Fase 1 del desacople de /panel).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']); // solo realm panel (no POS): es un reporte administrativo
$svc    = new \Punto\Api\Reports\ExpensesService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'POST') {
    // Permiso de escritura: bloquea el rol read-only (7) — misma convención que el panel local.
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['update', 'delete'], true)) {
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

    // action === 'update'
    $date = (string) (validateHttp('date', 'post') ?: '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $date)) {
        apiError('Formato de fecha inválido', 422);
    }
    // El monto llega ya como número plano: el parseo de locale (separadores) es presentacional
    // → lo hace el front (REGLA RAÍZ 2). La API solo valida que sea numérico.
    $totalRaw = (string) (validateHttp('total', 'post') ?: '0');
    if (!is_numeric($totalRaw)) {
        apiError('total inválido', 422);
    }
    $amount = (float) $totalRaw;
    $note   = (string) (validateHttp('note', 'post') ?: '');
    $user   = (string) (validateHttp('user', 'post') ?: '');
    if ($user !== '' && !preg_match($uuidRe, $user)) {
        apiError('usuario inválido', 422);
    }

    if (!$svc->update($id, COMPANY_ID, $date, $amount, $note, $user)) {
        apiError('No se pudo actualizar', 500);
    }
    apiOk(['id' => $id, 'action' => 'update']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

// ROC: fragmento de aislamiento de tenant. Reproduce getROC(1) del panel (que NO existe en
// el contexto /api): companyId SIEMPRE + outletId del JWT. COMPANY_ID/OUTLET_ID vienen de
// data.php (claims firmados, no del request) → interpolación segura. Mismo guard UUID que getROC.
// COMPANY_ID/OUTLET_ID son claims firmados (no del request), pero validamos UUID igual
// (defense-in-depth, simétrico) antes de interpolar — la query SIEMPRE queda con scope de company.
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}
$roc = " AND companyId = '" . COMPANY_ID . "'";
if (preg_match($uuidRe, (string) OUTLET_ID)) {
    $roc .= " AND outletId = '" . OUTLET_ID . "'";
}

apiOk([
    'rows'  => $svc->listMovements($from, $to, $roc, COMPANY_ID),
    'users' => $svc->users(COMPANY_ID),
]);
