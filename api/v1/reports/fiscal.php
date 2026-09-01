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

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

if (!defined('COUNTRY') || COUNTRY !== 'PY') {
    apiError('Este reporte es exclusivo de Paraguay', 403);
}

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));

if (!$rangeOk) {
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
