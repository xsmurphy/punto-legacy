<?php
/**
 * REST canónico (API compartida /api) — Reporte de Satisfacción / NPS (raw).
 *
 *   GET  /v1/reports/satisfaction?from=&to=                          → filas crudas de votos.
 *   POST /v1/reports/satisfaction (action=delete&id=<uuid>)          → borra un voto.
 *
 * Auth: GET acepta realms `panel` y `api` (lectura programatica: API keys / MCP);
 * el POST (borrado de votos) sigue siendo solo `panel`. Tenant por COMPANY_ID + outlet.
 * DELETE scopeado por companyId
 * (fix IDOR vs el legacy).
 */

require_once __DIR__ . '/../../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Allowlist por método: la lectura la abre al realm `api` (API keys / MCP), el
// borrado de votos sigue siendo exclusivo del panel. El embudo ya corta todo
// verbo distinto de GET/HEAD para `api` (bootstrap.php); esto lo hace explícito
// en el archivo que tiene el POST.
$ctx    = apiAuthTenant($method === 'GET' ? ['panel', 'api'] : ['panel']);
$svc    = new \Punto\Api\Reports\SatisfactionService();
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'POST') {
    if (!hasPermission('reports.satisfaction.view')) {
        apiError('No tenés permiso para esta acción (requiere: reports.satisfaction.view)', 403);
    }
    if (validateHttp('action', 'post') !== 'delete') {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
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

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->listVotes($from, $to, $roc, (string) COMPANY_ID));
