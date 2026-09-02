<?php
/**
 * REST canónico (API compartida /api) — Reporte de Compras y Gastos (raw).
 *
 *   GET  /v1/reports/purchases?view=general|cobros|detail&from=&to=
 *        [&supId=&itmId=&singleRow=&src=]
 *   POST /v1/reports/purchases (action=deletePayment&id=…)
 *
 * Las 3 vistas de LECTURA + borrado de pagos a proveedor.
 * El CRUD de edición y los fiscales siguen en panel legacy.
 * Auth: GET acepta realms `panel` y `api` (lectura programatica: API keys / MCP);
 * el POST (borrado de pagos) sigue siendo solo `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Allowlist por método: la lectura la abre al realm `api` (API keys / MCP), el
// borrado de pagos sigue siendo exclusivo del panel. El embudo ya corta todo
// verbo distinto de GET/HEAD para `api` (bootstrap.php), así que esto es la
// segunda vuelta de la misma regla — explícita en el archivo que tiene el POST.
$ctx    = apiAuthTenant($method === 'GET' ? ['panel', 'api'] : ['panel']);
$svc    = new \Punto\Api\Reports\PurchasesService();
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── Gate — el mismo permiso, ahora también en la LECTURA ────────────
 *
 * `reports.purchases.view` ya estaba en este archivo, pero SOLO dentro de la
 * rama POST. El GET —que devuelve las compras y gastos del período, con
 * proveedor, comprobante y monto— no chequeaba nada: lo leía cualquier sesión
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
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.purchases.view');

/* ───────── write: eliminar pago a proveedor ───────── */
if ($method === 'POST') {
    $action = (string) (validateHttp('action', 'post') ?: '');
    if ($action !== 'deletePayment') {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    $parentRaw = (string) (validateHttp('parent', 'post') ?: '');
    $parentId  = ($parentRaw !== '' && preg_match($uuidRe, $parentRaw)) ? $parentRaw : null;
    if (!$svc->deletePayment($id, $parentId, (string) COMPANY_ID)) {
        apiError('No se pudo eliminar', 500);
    }
    apiOk(['id' => $id, 'action' => 'deletePayment']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'cobros', 'detail'], true)) {
    apiError('Vista no soportada', 422);
}

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

$uuidOrEmpty = function ($v) use ($uuidRe) {
    $v = (string) ($v ?: '');
    return ($v !== '' && preg_match($uuidRe, $v)) ? $v : '';
};

$filters = [
    'supId'     => $uuidOrEmpty(validateHttp('supId')),
    'itmId'     => $uuidOrEmpty(validateHttp('itmId')),
    'singleRow' => $uuidOrEmpty(validateHttp('singleRow')),
    'src'       => trim((string) (validateHttp('src') ?: '')),
];

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'cobros') {
    apiOk($svc->cobros($filters, $from, $to, $roc, $companyId));
} elseif ($view === 'detail') {
    apiOk($svc->detail($filters, $from, $to, $roc, $companyId));
} else {
    apiOk($svc->general($filters, $from, $to, $roc, $companyId));
}
