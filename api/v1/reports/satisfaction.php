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

use Punto\App\Helpers\Date;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Allowlist por método: la lectura la abre al realm `api` (API keys / MCP), el
// borrado de votos sigue siendo exclusivo del panel. El embudo ya corta todo
// verbo distinto de GET/HEAD para `api` (bootstrap.php); esto lo hace explícito
// en el archivo que tiene el POST.
$ctx    = apiAuthTenant($method === 'GET' ? ['panel', 'api'] : ['panel']);
$svc    = new \Punto\Api\Reports\SatisfactionService();
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── Gate — el mismo permiso, ahora también en la LECTURA ────────────
 *
 * `reports.satisfaction.view` ya estaba en este archivo, pero SOLO dentro de
 * la rama POST. El GET —que devuelve las calificaciones que dejaron los
 * clientes, con sus comentarios— no chequeaba nada: lo leía cualquier sesión
 * de panel y cualquier API key.
 *
 * Es el mismo agujero que tenían los otros veinte reportes, y exactamente la
 * misma forma que el de `drawers.php` (D9 de `context/59`): la clave existía,
 * gobernaba la escritura, y la lectura que le da nombre pasaba de largo. Un
 * scan que buscara `hasPermission(` en el archivo lo daba por gateado.
 *
 * Sube al tope porque la clave es la MISMA para los dos verbos, así que un
 * solo gate los cubre — el `hasPermission()` que estaba dentro del POST quedó
 * redundante y se fue con este cambio. Va por
 * `OperatorContext::requirePermission()` como todo el directorio (el porqué,
 * en el docblock de `api/lib/Auth/OperatorContext.php`).
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.satisfaction.view');

if ($method === 'POST') {
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

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->listVotes($from, $to, $roc, (string) COMPANY_ID));
