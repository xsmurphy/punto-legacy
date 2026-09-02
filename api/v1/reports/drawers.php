<?php
/**
 * REST canónico (API compartida /api) — Cierres de Caja / Drawers (raw).
 *
 *   GET  /v1/reports/drawers?from=&to=             → { rows } CRUDO.
 *   GET  /v1/reports/drawers?id=<uuid>             → { detail } CRUDO.
 *   POST /v1/reports/drawers (action=close|correct|delete&id=<uuid> …) → muta.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet. Writes scopeados por companyId del JWT.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx    = apiAuthTenant(['panel', 'api']);
$svc    = new \Punto\Api\Reports\DrawersService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

/* ───────── Gate de LECTURA — el que faltaba ────────────────────────────────
 *
 * `reports.drawers.view` ya estaba en este archivo, pero SOLO en la rama POST
 * (abajo, para cerrar/corregir/eliminar un arqueo). El GET —el listado de
 * cierres de caja de la sucursal y el detalle de uno, con montos declarados,
 * diferencias y quién cerró— no chequeaba nada: lo leía cualquier sesión de
 * panel y cualquier API key, incluida la de un cajero. Es el mismo permiso que
 * ya exige la escritura, aplicado donde faltaba (D9 de `context/59`).
 *
 * Este endpoint NO acepta `pos-app` (ver el `apiAuthTenant` de arriba), así que
 * el gate estricto no puede romper la caja: el arqueo de la tablet va por
 * `/v1/drawer.php`, que es otro archivo con su propio gate. Por eso mismo,
 * agregar `get_drawers` al catálogo del asistente del mostrador sigue sin ser
 * posible con solo este gate — antes hay que abrir el realm, que es una
 * decisión aparte (ver el docblock de `frontend/lib/pos/agent-tools.ts`).
 */
if ($method === 'GET') {
    require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
    \Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.drawers.view');
}

if ($method === 'POST') {
    if (!hasPermission('reports.drawers.view')) {
        apiError('No tenés permiso para esta acción (requiere: reports.drawers.view)', 403);
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
        if (!$svc->remove($id, (string) COMPANY_ID)) {
            apiError('No se pudo eliminar', 500);
        }
        apiOk(['id' => $id, 'action' => 'delete']);
    }

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
        // Usuario que cierra = sub del JWT. Si no es UUID → NULL.
        $closer = (string) $ctx['userId'];
        if (!preg_match($uuidRe, $closer)) {
            $closer = '';
        }
        if (!$svc->close($id, (string) COMPANY_ID, $closeDate, (float) $closeAmount, $closer)) {
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
    if (!$svc->correct($id, (string) COMPANY_ID, $openDate, $closeDate, (float) $openAmount, (float) $closeAmount)) {
        apiError('No se pudo corregir el cierre', 500);
    }
    apiOk(['id' => $id, 'action' => 'correct']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

// Detalle de una caja.
$id = (string) (validateHttp('id') ?: '');
if ($id !== '') {
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    $detail = $svc->detail($id, (string) COMPANY_ID, $roc);
    if ($detail === null) {
        apiError('Caja no encontrada', 404);
    }
    // `tolerance`: margen con el que se clasificó el cuadre (mig 164 +
    // CashCountStatus). Viaja con la respuesta para que la UI pueda explicar
    // por qué un cierre con diferencia figura como "cuadra".
    apiOk(['detail' => $detail, 'tolerance' => $svc->tolerance((string) COMPANY_ID)]);
}

// Lista por período.
// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

apiOk([
    'rows'      => $svc->listMovements($from, $to, $roc, (string) COMPANY_ID),
    'tolerance' => $svc->tolerance((string) COMPANY_ID),
]);
