<?php
/**
 * REST canónico (API compartida /api) — Cuentas por Cobrar/Pagar / Open Invoices (raw).
 *
 *   GET /v1/reports/open_invoices?state=income|outcome[&contactId=uuid]
 *       → contactos+facturas abiertas, CRUDO. income (default) = ventas a crédito (tipo 3);
 *         outcome = compras a crédito (tipo 4).
 *       `contactId` (opcional) filtra a un solo contacto — usado por el diálogo de cobro
 *       multi-factura del panel para listar las facturas a crédito pendientes de UN
 *       cliente (evita traer el reporte completo de la empresa para eso).
 *
 * Read-only. Auth: realm `panel`. Sin ROC (el service bindea companyId y outletId en
 * cada SELECT en vez de interpolarlos como hace `Roc::build`).
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

$contactId = (string) (validateHttp('contactId') ?? '');
if ($contactId !== '' && !preg_match($uuidRe, $contactId)) {
    apiError('contactId inválido', 422);
}

// Sucursal efectiva del view-scope — mismo patrón que reports/stock.php y
// reports/dashboard.php. `VIEW_OUTLET_ID` la define bootstrap.php a partir del
// header X-Outlet-Id del selector del panel: '' cuando el usuario eligió "Todas"
// (consolidado), el UUID cuando eligió una. Sin el header definido cae a la
// sucursal del token.
//
// Este reporte NO lo tenía: listaba las cuentas por cobrar/pagar de TODAS las
// sucursales aunque hubiera una elegida (reporte del tester, 2026-08-28).
$effectiveOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;

// Un valor que no sea UUID no filtra — misma tolerancia que `Roc::build`, que
// solo agrega el `AND outletId` cuando matchea el patrón. '' ("Todas") entra
// por acá y consolida, que es el comportamiento correcto para ese modo.
if (!preg_match($uuidRe, $effectiveOutletId)) {
    $effectiveOutletId = '';
}

apiOk($svc->general($state, COMPANY_ID, $contactId !== '' ? $contactId : null, $effectiveOutletId));
