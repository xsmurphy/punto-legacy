<?php
/**
 * REST canónico (API compartida /api) — Reporte de Ventas por Categorías (raw).
 *
 *   GET /v1/reports/categories?from=&to=
 *       → filas crudas [{categoryId, name, usold, total, tax, cogs, comission, discount}]
 *
 * Sin formatear, sin HTML. El BFF compone % + totales. Auth: realm `panel`.
 * Tenant por COMPANY_ID + outlet (ROC b-prefijado para el JOIN con transaction).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\CategoriesService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

// Roc::build con alias `b` (transaction en el JOIN itemSold/transaction/item).
// Roc::build respeta VIEW_OUTLET_ID si está definida (panel-next 2026-06-13).
$roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID, 'b');

$outletId = defined('VIEW_OUTLET_ID') ? (string) VIEW_OUTLET_ID : (string) OUTLET_ID;

if (($_GET['verify'] ?? '') === '1') {
    $rollupData = $svc->salesByCategory($from, $to, $roc, (string) COMPANY_ID, true, $outletId);
    $liveData   = $svc->salesByCategoryLive($from, $to, $roc, (string) COMPANY_ID);
    $diff = [];
    $rollupMap = array_column($rollupData, null, 'categoryId');
    $liveMap   = array_column($liveData,   null, 'categoryId');
    $allCats   = array_unique(array_merge(array_keys($rollupMap), array_keys($liveMap)));
    foreach ($allCats as $catId) {
        $r = $rollupMap[$catId] ?? ['usold'=>0,'total'=>0,'tax'=>0,'cogs'=>0,'comission'=>0,'discount'=>0];
        $l = $liveMap[$catId]   ?? ['usold'=>0,'total'=>0,'tax'=>0,'cogs'=>0,'comission'=>0,'discount'=>0];
        foreach (['usold','total','tax','cogs','comission','discount'] as $fld) {
            $delta = round((float)($r[$fld] ?? 0) - (float)($l[$fld] ?? 0), 4);
            if ($delta !== 0.0) {
                $diff[] = ['categoryId'=>$catId,'field'=>$fld,'rollup'=>$r[$fld],'live'=>$l[$fld],'delta'=>$delta];
            }
        }
    }
    apiOk(['rollup' => $rollupData, 'live' => $liveData, 'diff' => $diff]);
}

apiOk($svc->salesByCategory($from, $to, $roc, (string) COMPANY_ID, false, $outletId));
