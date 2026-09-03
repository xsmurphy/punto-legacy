<?php
/**
 * REST canónico (API compartida /api) — Reporte de Gift Cards.
 *
 *   GET  /v1/reports/giftcards?view=detail[&singleRow=]   → gift cards activadas, CRUDAS.
 *   POST /v1/reports/giftcards (action=delete&id=)        → elimina gift card.
 *   POST /v1/reports/giftcards (action=update&id=…)       → actualiza campos editables.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\GiftcardsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── Gate — el mismo permiso, ahora también en la LECTURA ────────────
 *
 * `reports.giftcards.view` ya estaba en este archivo, pero SOLO dentro de la
 * rama POST. El GET —que devuelve las gift cards vendidas del comercio y su
 * saldo vigente— no chequeaba nada: lo leía cualquier sesión de panel.
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
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.giftcards.view');

/* ───────── write: eliminar / actualizar gift card ───────── */
if ($method === 'POST') {
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['delete', 'update'], true)) {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    if ($action === 'delete') {
        if (!$svc->delete($id, (string) COMPANY_ID)) {
            apiError('No se pudo eliminar', 500);
        }
        apiOk(['id' => $id, 'action' => 'delete']);
    }
    // action === 'update'
    $dateRe   = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
    // code: string alfanumérico (F2 giftcard-issue-flow) — antes era int.
    $codeRaw  = (string) (validateHttp('code', 'post') ?: '');
    $valueRaw = validateHttp('value', 'post');
    if (!is_numeric($valueRaw)) {
        apiError('value inválido', 422);
    }
    $benefId  = (string) (validateHttp('beneficiaryId', 'post') ?: '');
    if ($benefId !== '' && !preg_match($uuidRe, $benefId)) {
        apiError('beneficiaryId inválido', 422);
    }
    $expires  = (string) (validateHttp('expires', 'post')  ?: '');
    if ($expires !== '' && !preg_match($dateRe, $expires)) {
        apiError('expires inválido', 422);
    }
    $data = [
        'code'          => $codeRaw,
        'value'         => (float) $valueRaw,
        'expires'       => $expires,
        'note'          => (string) (validateHttp('note', 'post') ?: ''),
        'beneficiaryId' => $benefId,
    ];
    try {
        if (!$svc->update($id, $data, (string) COMPANY_ID)) {
            apiError('No se pudo actualizar', 500);
        }
    } catch (\InvalidArgumentException $e) {
        // Código duplicado (case-insensitive) — GiftcardsService::update().
        apiError($e->getMessage(), 422);
    }
    apiOk(['id' => $id, 'action' => 'update']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$view = (string) (validateHttp('view') ?: 'detail');
if ($view !== 'detail') {
    apiError('Vista no soportada', 422);
}

$singleRow = (string) (validateHttp('singleRow') ?: '');
if ($singleRow !== '' && !preg_match($uuidRe, $singleRow)) {
    $singleRow = '';
}

// La tabla `giftcard` usa columnas QUOTED mixed-case — no puede usar el
// fragmento sin comillas de Roc::build() (pensado para las tablas legacy
// lowercase-folded). Replicamos acá su MISMA precedencia de outlet (incluido
// el override VIEW_OUTLET_ID del selector de sucursal del frontend) pero el
// filtro se aplica parametrizado dentro de GiftcardsService::detail().
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido (companyId no es UUID)', 500);
}
// El alcance sale de `OutletScope::effectiveIds()`: lista vacía = todas, un
// elemento = esa sucursal, y 2+ = las asignadas al usuario, que este reporte
// resuelve con un `IN (...)` en vez del 422 que devolvía antes (es un listado,
// no un agregado: unir dos sucursales es concatenar filas). Sin guard de uuid:
// `effectiveIds()` ya devuelve uuids validados.
$outletIds = \Punto\Api\Outlets\OutletScope::effectiveIds();

apiOk($svc->detail(['singleRow' => $singleRow], (string) COMPANY_ID, $outletIds));
