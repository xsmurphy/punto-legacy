<?php
/**
 * REST canónico (API compartida /api) — Reportes fiscales de Paraguay (F5,
 * context/38-impuestos-multi-pais.md §E).
 *
 *   GET /v1/reports/fiscal?dataset=rg90|libro-ventas&from=&to=
 *
 *     rg90         → filas listas para importar a Marangatu (SET), 20
 *                    columnas fijas en el orden que exige el formato.
 *     libro-ventas → desglose base/IVA/total por tasa, uso interno.
 *
 * PY-only: gateado por `COUNTRY === 'PY'` (definida por data.php desde
 * `company.config->>'settingCountry'`) — mismo criterio que
 * `ContactService::isPyTenant()`. Un tenant de otro país recibe 403: el
 * reporte no aplica, no es que esté vacío.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet (Roc, igual que el
 * resto de /v1/reports).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

if (!defined('COUNTRY') || COUNTRY !== 'PY') {
    apiError('Este reporte es exclusivo de Paraguay', 403);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido (esperado Y-m-d o Y-m-d H:i:s)', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$svc     = new \Punto\Api\Reports\FiscalService();
$dataset = (string) (validateHttp('dataset') ?: 'rg90');

switch ($dataset) {
    case 'rg90':
        apiOk($svc->rg90($from, $to, $roc, (string) COMPANY_ID));
        break;

    case 'libro-ventas':
        apiOk($svc->libroVentas($from, $to, $roc, (string) COMPANY_ID));
        break;

    default:
        apiError('dataset desconocido: ' . $dataset, 422);
}
