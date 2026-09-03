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

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\SummaryYearService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Resumen anual de ingresos y egresos del comercio, mes por mes.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

$year = (string) (validateHttp('y') ?: date('Y'));
if (!preg_match('/^\d{4}$/', $year)) {
    apiError('Año inválido', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

// Ver el comentario largo en `brands.php`: el alcance que va al ROLLUP sale de
// `OutletScope::effectiveIds()` y no del idiom viejo, porque `RollupReader` trata
// la lista vacía como "sin filtro" y eso le daba el tenant completo a una key
// acotada. Es una LISTA porque el rollup agrupa y suma: un usuario con dos
// sucursales asignadas recibe su consolidado en vez del 422 de antes.
$outletIds = \Punto\Api\Outlets\OutletScope::effectiveIds();

if (($_GET['verify'] ?? '') === '1') {
    // forceRollup=true: ignora el flag REPORTS_ROLLUP_ENABLED para que el diff
    // compare el rollup REAL contra el live (sin esto, con el flag off yearly()
    // delegaría a live y el diff daría siempre vacío).
    $rollupData = $svc->yearly($year, $roc, (string) COMPANY_ID, $outletIds, true);
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

apiOk($svc->yearly($year, $roc, (string) COMPANY_ID, $outletIds));
