<?php
/**
 * REST canónico (API compartida /api) — Movimientos de Caja (motor ERP, raw).
 *
 *   GET  /v1/reports/expenses?from=&to=                          → { rows, users } CRUDO.
 *   POST /v1/reports/expenses (action=update|delete&id=<uuid> [+date/total/note/user]) → muta.
 *
 * Lectura sin formatear/HTML (el front mapea/formatea). Escritura scopeada por COMPANY_ID del
 * JWT. Auth: GET acepta realms `panel` y `api` (lectura programatica: API keys / MCP);
 * el POST (update/delete) sigue siendo solo `panel`. Tenant por COMPANY_ID + outlet (ROC).
 * Ver REGLA RAÍZ 2.
 *
 * Primer endpoint del panel servido por la /api compartida (Fase 1 del desacople de /panel).
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Allowlist por método. Nunca el POS: es un reporte administrativo. La LECTURA
// sí la abre al realm `api` (API keys / MCP); la mutación (update/delete de
// movimientos) sigue siendo exclusiva del panel. El embudo ya corta todo verbo
// distinto de GET/HEAD para `api` (bootstrap.php); esto lo hace explícito en el
// archivo que tiene el POST.
$ctx    = apiAuthTenant($method === 'GET' ? ['panel', 'api'] : ['panel']);
$svc    = new \Punto\Api\Reports\ExpensesService();
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'POST') {
    if (!hasPermission('reports.expenses.view')) {
        apiError('No tenés permiso para esta acción (requiere: reports.expenses.view)', 403);
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

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));

if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

// Franja horaria del reporte (F1 de context/67). Es una dimensión APARTE del
// rango: el rango es un intervalo CONTINUO, así que "del 1 al 30 de 07:00 a
// 11:59" mandado como from/to incluye las noches del medio. `hourFrom`/`hourTo`
// se repiten en cada día del rango. Sin ellos la banda es vacía y la query sale
// byte por byte como salía antes de esta feature.
[$hours, $hoursOk] = \Punto\Api\Reports\HourBand::fromRequest(validateHttp('hourFrom'), validateHttp('hourTo'));
if (!$hoursOk) {
    apiError('Formato de franja horaria inválido (esperado HH:MM o HH:MM:SS)', 422);
}

// Roc::build (frontend 2026-06-13: respeta VIEW_OUTLET_ID si el browser
// mandó el header X-Outlet-Id del dropdown del logo).
try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk([
    'rows'  => $svc->listMovements($from, $to, $roc, COMPANY_ID, $hours),
    'users' => $svc->users(COMPANY_ID),
]);
