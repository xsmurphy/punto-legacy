<?php
/**
 * REST canónico — Reporte de Gift Cards (motor ERP, raw).
 *
 *   GET /API/v1/reports/giftcards?view=detail[&singleRow=]   → gift cards activadas, CRUDAS.
 *
 * SOLO la vista de LECTURA. El form de edición (`giftcard`) y los writes (`update`/`delete`)
 * siguen sirviéndose por el PHP legacy vía ?action=. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportGiftcardsService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$view = (string) (validateHttp('view') ?: 'detail');
if ($view !== 'detail') {
    apiError('Vista no soportada', 422);
}

$singleRow = (string) (validateHttp('singleRow') ?: '');
if ($singleRow !== '' && !preg_match($uuidRe, $singleRow)) {
    $singleRow = '';
}

$svc = new ReportGiftcardsService();
apiOk($svc->detail(['singleRow' => $singleRow]));
