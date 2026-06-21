<?php
/**
 * REST canónico (API compartida /api) — Resumen Anual de Ingresos y Egresos (raw).
 *
 *   GET /v1/reports/summary_year?y=<YYYY>
 *       → { year, years:[], months:[...] } CRUDO.
 *   GET /v1/reports/summary_year?y=<YYYY>&verify=1
 *       → { rollup, live, diff:[{month,field,rollup,live,delta}] }
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\SummaryYearService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$year = (string) (validateHttp('y') ?: date('Y'));
if (!preg_match('/^\d{4}$/', $year)) {
    apiError('Año inválido', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$outletId = defined('VIEW_OUTLET_ID') ? (string) VIEW_OUTLET_ID : (string) OUTLET_ID;

if (($_GET['verify'] ?? '') === '1') {
    $rollupData = $svc->yearly($year, $roc, (string) COMPANY_ID, $outletId);
    $liveData   = $svc->yearlyLive($year, $roc, (string) COMPANY_ID);
    $diff = [];
    foreach ($rollupData['months'] as $rm) {
        foreach ($liveData['months'] as $lm) {
            if ($rm['month'] === $lm['month']) {
                $fields = ['usold','count','discount','tax','salesTotal','expensesTotal','returnsTotal'];
                foreach ($fields as $fld) {
                    $delta = round((float)$rm[$fld] - (float)$lm[$fld], 4);
                    if ($delta !== 0.0) {
                        $diff[] = ['month'=>$rm['month'],'field'=>$fld,'rollup'=>$rm[$fld],'live'=>$lm[$fld],'delta'=>$delta];
                    }
                }
            }
        }
    }
    apiOk(['rollup' => $rollupData, 'live' => $liveData, 'diff' => $diff]);
}

apiOk($svc->yearly($year, $roc, (string) COMPANY_ID, $outletId));
