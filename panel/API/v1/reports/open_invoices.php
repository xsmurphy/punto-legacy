<?php
/**
 * REST canónico — Cuentas por Cobrar/Pagar (motor ERP, raw).
 *
 *   GET /API/v1/reports/open_invoices?state=income|outcome   → contactos+facturas abiertas, CRUDO.
 *
 * Read-only. Sin formatear: el front formatea + arma la tabla anidada + KPIs. Auth: JWT. REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportOpenInvoicesService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$state = (validateHttp('state') === 'outcome') ? 'outcome' : 'income';

$svc = new ReportOpenInvoicesService();
apiOk($svc->general($state));
