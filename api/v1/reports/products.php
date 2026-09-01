<?php
/**
 * REST canónico (API compartida /api) — Reporte de Artículos / Productos (raw).
 *
 *   GET /v1/reports/products?view=general|detail|combos&from=&to=
 *       [&cusId=&usrId=&itmId=&month=&year=&src=]
 *
 * Read-only. Sin formatear/HTML: el BFF calcula utilidad/KPIs/chart, el front formatea.
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\ProductsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'detail', 'combos'], true)) {
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
    'cusId' => $uuidOrEmpty(validateHttp('cusId')),
    'usrId' => $uuidOrEmpty(validateHttp('usrId')),
    'itmId' => $uuidOrEmpty(validateHttp('itmId')),
    'month' => (bool) validateHttp('month'),
    'year'  => (int) (validateHttp('year') ?: 0),
    'src'   => trim((string) (validateHttp('src') ?: '')),
];

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'detail') {
    apiOk($svc->detail($filters, $from, $to, $roc, $companyId));
} elseif ($view === 'combos') {
    apiOk($svc->combos($filters, $from, $to, $roc, $companyId));
} else {
    apiOk($svc->general($filters, $from, $to, $roc, $companyId));
}
