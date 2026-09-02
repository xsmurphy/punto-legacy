<?php
/**
 * REST canónico (API compartida /api) — Reporte de Agendamientos (raw).
 *
 *   GET  /v1/reports/schedule?view=detail|stats|sessions&from=&to=[&ui=&uit=usr|cus]
 *   POST /v1/reports/schedule (action=delete&id=<uuid>) → elimina la cita.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\ScheduleService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── Gate — el mismo permiso, ahora también en la LECTURA ────────────
 *
 * `reports.schedule.view` ya estaba en este archivo, pero SOLO dentro de la
 * rama POST. El GET —que devuelve la agenda del comercio: citas, clientes y
 * responsables— no chequeaba nada: lo leía cualquier sesión de panel.
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
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.schedule.view');

/* ───────── write: eliminar cita ───────── */
if ($method === 'POST') {
    $action = (string) (validateHttp('action', 'post') ?: '');
    if ($action !== 'delete') {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    if (!$svc->deleteAppointment($id, (string) COMPANY_ID)) {
        apiError('No se pudo eliminar', 500);
    }
    apiOk(['id' => $id, 'action' => 'delete']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$view = (string) (validateHttp('view') ?: 'detail');
if (!in_array($view, ['detail', 'stats', 'sessions'], true)) {
    apiError('Vista no soportada', 422);
}

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

$ui  = (string) (validateHttp('ui') ?: '');
$ui  = ($ui !== '' && preg_match($uuidRe, $ui)) ? $ui : '';
$uit = validateHttp('uit') === 'cus' ? 'cus' : 'usr';

$customerId = trim((string) ($_GET['customerId'] ?? '')) ?: null;

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'stats') {
    apiOk($svc->stats(['uit' => $uit, 'ui' => $ui], $from, $to, $roc, $companyId));
} elseif ($view === 'sessions') {
    apiOk($svc->sessions($from, $to, $companyId));
} else {
    apiOk($svc->detail(['ui' => $ui, 'customerId' => $customerId], $from, $to, $roc, $companyId));
}
