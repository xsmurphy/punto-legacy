<?php
/**
 * REST canónico (API compartida /api) — Facturas Recurrentes (raw).
 *
 *   GET  /v1/reports/recurring                                   → { rows: [...] } crudo.
 *   POST /v1/reports/recurring (action=pause|activate|remove&id=<uuid>) → muta una recurrencia.
 *
 * Auth: realm `panel`. Tenant por companyId (la tabla no tiene outletId → sin ROC).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\RecurringService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── Gate — el mismo permiso, ahora también en la LECTURA ────────────
 *
 * `reports.recurring.view` ya estaba en este archivo, pero SOLO dentro de la
 * rama POST. El GET —que devuelve las facturas recurrentes del comercio y sus
 * montos— no chequeaba nada: lo leía cualquier sesión de panel.
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
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.recurring.view');

if ($method === 'POST') {
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['pause', 'activate', 'remove'], true)) {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    if (!$svc->mutate($action, $id, COMPANY_ID)) {
        apiError('No se pudo procesar la acción', 500);
    }
    apiOk(['id' => $id, 'action' => $action]);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

apiOk(['rows' => $svc->listAll(COMPANY_ID)]);
