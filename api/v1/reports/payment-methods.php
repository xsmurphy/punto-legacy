<?php
/**
 * REST canónico (API compartida /api) — Reporte de Ventas por Medios de Pago (raw).
 *
 *   GET /v1/reports/payment-methods?from=&to=
 *       → { detail: [...], summary: [...] } CRUDO. detail = una fila por medio de pago de
 *         cada transacción; summary = agrupado por medio, ordenado por monto desc.
 *
 * El BFF formatea montos, el front arma tablas + chart. Auth: realms `panel` y `api` (lectura programatica: API keys / MCP).
 * Tenant por COMPANY_ID + outlet (ROC sin prefix). Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\PaymentMethodsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Ventas por medio de pago, transacción por transacción.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

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
