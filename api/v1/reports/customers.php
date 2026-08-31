<?php
/**
 * REST canónico (API compartida /api) — Reporte de Clientes (raw).
 *
 *   GET /v1/reports/customers?from=&to=                    → { rows: [...] }
 *   GET /v1/reports/customers?from=&to=&include=dashboard  → { dashboard: {...} }
 *   GET /v1/reports/customers?include=geo                  → { geo: {...} }
 *   GET /v1/reports/customers?from=&to=&include=rows,dashboard,geo
 *
 * SIN `include` la respuesta es exactamente la de siempre (`{ rows }`): hay
 * consumidores programáticos del endpoint (read-tools del agente / MCP) que ya
 * leen ese shape, y ninguno debe empezar a pagar el costo de las secciones
 * nuevas. Con `include` se devuelven SOLO las secciones pedidas — así el tab
 * geográfico, que es el más caro, no se calcula mientras nadie lo abra.
 *
 * `geo` ignora `from`/`to` a propósito: es el padrón de clientes, no el
 * período (ver CustomersService::geography).
 *
 * Read-only. Auth: realms `panel` y `api` (lectura programatica: API keys / MCP).
 * Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\CustomersService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

// Whitelist explícita: `include` viene del request, así que nunca se usa para
// resolver un método ni se concatena a SQL.
$SECCIONES = ['rows', 'dashboard', 'geo'];
$includeIn = trim((string) (validateHttp('include') ?: ''));
if ($includeIn === '') {
    $secciones = ['rows'];
} else {
    $pedidas   = array_map('trim', explode(',', strtolower($includeIn)));
    $secciones = array_values(array_intersect($SECCIONES, $pedidas));
    if (!$secciones) {
        apiError('Sección inválida. Válidas: ' . implode(', ', $SECCIONES), 422);
    }
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$out = [];
if (in_array('rows', $secciones, true)) {
    $out['rows'] = $svc->ranking($from, $to, $roc, (string) COMPANY_ID);
}
if (in_array('dashboard', $secciones, true)) {
    $out['dashboard'] = $svc->dashboard($from, $to, (string) COMPANY_ID, (string) OUTLET_ID);
}
if (in_array('geo', $secciones, true)) {
    $out['geo'] = $svc->geography((string) COMPANY_ID);
}

apiOk($out);
