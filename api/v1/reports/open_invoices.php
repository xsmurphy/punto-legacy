<?php
/**
 * REST canónico (API compartida /api) — Cuentas por Cobrar/Pagar / Open Invoices (raw).
 *
 *   GET /v1/reports/open_invoices?state=income|outcome
 *       → contactos+facturas abiertas, CRUDO. income (default) = ventas a crédito (tipo 3);
 *         outcome = compras a crédito (tipo 4).
 *
 * Read-only. Auth: realm `panel`. Sin ROC (service bindea companyId en cada SELECT).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\OpenInvoicesService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$state = (validateHttp('state') === 'outcome') ? 'outcome' : 'income';

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

apiOk($svc->general($state, COMPANY_ID));
