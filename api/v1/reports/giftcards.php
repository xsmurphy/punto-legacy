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

/* ───────── write: eliminar / actualizar gift card ───────── */
if ($method === 'POST') {
    if (!hasPermission('reports.giftcards.view')) {
        apiError('No tenés permiso para esta acción (requiere: reports.giftcards.view)', 403);
    }
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
    $codeRaw  = validateHttp('code', 'post');
    $valueRaw = validateHttp('value', 'post');
    if (!is_numeric($valueRaw)) {
        apiError('value inválido', 422);
    }
    $benefId  = (string) (validateHttp('beneficiaryId', 'post') ?: '');
    if ($benefId !== '' && !preg_match($uuidRe, $benefId)) {
        apiError('beneficiaryId inválido', 422);
    }
    $expires  = (string) (validateHttp('expires', 'post')  ?: '');
    $sendDate = (string) (validateHttp('sendDate', 'post') ?: '');
    if ($expires  !== '' && !preg_match($dateRe, $expires))  { apiError('expires inválido', 422); }
    if ($sendDate !== '' && !preg_match($dateRe, $sendDate)) { apiError('sendDate inválido', 422); }
    $data = [
        'code'          => (int) ($codeRaw ?? 0),
        'value'         => (float) $valueRaw,
        'expires'       => $expires,
        'note'          => (string) (validateHttp('note', 'post') ?: ''),
        'sendDate'      => $sendDate,
        'beneficiaryId' => $benefId,
    ];
    if (!$svc->update($id, $data, (string) COMPANY_ID)) {
        apiError('No se pudo actualizar', 500);
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

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->detail(['singleRow' => $singleRow], $roc, (string) COMPANY_ID));
