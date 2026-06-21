<?php
/**
 * REST canónico (API compartida /api) — Reporte de Ventas por Medios de Pago (raw).
 *
 *   GET /v1/reports/payment-methods?from=&to=
 *       → { detail: [...], summary: [...] } CRUDO. detail = una fila por medio de pago de
 *         cada transacción; summary = agrupado por medio, ordenado por monto desc.
 *
 * El BFF formatea montos, el front arma tablas + chart. Auth: realm `panel`.
 * Tenant por COMPANY_ID + outlet (ROC sin prefix). Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\PaymentMethodsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
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

if (($_GET['verify'] ?? '') === '1') {
    $rollupData = $svc->report($from, $to, $roc, (string) COMPANY_ID, true);
    $liveData   = $svc->report($from, $to, $roc, (string) COMPANY_ID, false);
    $diff = [];
    $rollupMap = array_column($rollupData['summary'], null, 'type');
    $liveMap   = array_column($liveData['summary'],   null, 'type');
    $allTypes  = array_unique(array_merge(array_keys($rollupMap), array_keys($liveMap)));
    foreach ($allTypes as $type) {
        $r = $rollupMap[$type] ?? ['price'=>0];
        $l = $liveMap[$type]   ?? ['price'=>0];
        $delta = round((float)($r['price'] ?? 0) - (float)($l['price'] ?? 0), 4);
        if ($delta !== 0.0) {
            $diff[] = ['type'=>$type,'field'=>'price','rollup'=>$r['price'],'live'=>$l['price'],'delta'=>$delta];
        }
    }
    apiOk(['rollup' => $rollupData['summary'], 'live' => $liveData['summary'], 'diff' => $diff]);
}

apiOk($svc->report($from, $to, $roc, (string) COMPANY_ID));
