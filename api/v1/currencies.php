<?php
/**
 * /api/v1/currencies.php — tasas de cambio del tenant (Slice 12).
 *
 *   GET  → lista de monedas extranjeras con su tasa configurada
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. data = lista (puede ser []).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/CurrencyService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\CurrencyService;

// Multi-realm: panel necesita las monedas para el editor de cotizaciones del
// item; pos-app las usa al cobrar. El default era solo ['pos-app'] → frontend
// recibía 401 silente y la UI mostraba 'No hay monedas configuradas'.
$ctx = apiAuthTenant(['panel', 'pos-app']);

$svc = new CurrencyService(TenantContext::fromAuth($ctx));
apiOk($svc->exchangeList());
