<?php
/**
 * REST canónico (API compartida /api) — Reporte de Ventas por Categorías (raw).
 *
 *   GET /v1/reports/categories?from=&to=
 *       → filas crudas [{categoryId, name, usold, total, tax, cogs, comission, discount}]
 *
 * Sin formatear, sin HTML. El BFF compone % + totales. Auth: realms `panel` y `api` (lectura programatica: API keys / MCP).
 * Tenant por COMPANY_ID + outlet (ROC b-prefijado para el JOIN con transaction).
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\CategoriesService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Ventas por categoría — el mismo dato del reporte de ventas, agrupado por categoría.
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

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

// Roc::build con alias `b` (transaction en el JOIN itemSold/transaction/item).
// Roc::build respeta VIEW_OUTLET_ID si está definida (frontend 2026-06-13).
$roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID, 'b');

// Ver el comentario largo en `brands.php`: el outlet que va al ROLLUP sale de
// `OutletScope::single()` y no del idiom viejo, porque `RollupReader` trata `''`
// como "sin filtro" y eso le daba el tenant completo a una key acotada.
$outletId = \Punto\Api\Outlets\OutletScope::single();
if ($outletId === null) {
    apiError(\Punto\Api\Outlets\OutletScope::subsetNotSupportedMessage(), 422);
}

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
