<?php
/**
 * REST canónico — Reporte de Satisfacción (NPS) (motor ERP, raw).
 *
 *   GET  /API/v1/reports/satisfaction?from=&to=   → filas crudas de votos.
 *   POST /API/v1/reports/satisfaction (action=delete&id=<uuid>) → borra un voto.
 *
 * Lectura sin formatear/HTML (el BFF calcula NPS, el front formatea). Escritura scopeada
 * por COMPANY_ID del JWT (fix IDOR del legacy). Auth: JWT. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportSatisfactionService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$svc    = new ReportSatisfactionService();

if ($method === 'POST') {
    // Permiso de escritura: bloquea el rol read-only (7) — misma convención que
    // limitReportAccess()/isInvoiceEditable(). NOTA: el gate fino por módulo
    // (allowUser('sales','delete')) requiere ROLE_ID, que apiMiddleware no define
    // (usa PANEL_AUTHED_ROLE); wirear ROLE_ID en el middleware es un follow-up que
    // habilita allowUser en TODOS los endpoints de escritura del API v1.
    if ((int) PANEL_AUTHED_ROLE === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    if (validateHttp('action', 'post') !== 'delete') {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        apiError('id inválido', 422);
    }
    if (!$svc->deleteVote($id, COMPANY_ID)) {
        apiError('No se pudo eliminar', 500);
    }
    apiOk(['deleted' => true]);
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

$roc = getROC(1);
apiOk($svc->listVotes($from, $to, $roc));
