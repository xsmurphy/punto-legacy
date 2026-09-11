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
 *   GET /v1/reports/open_invoices?view=summary
 *       → agregados de LAS DOS puntas a la vez (totales, antigüedad, vencido vs por
 *         vencer, top 10 por lado y proyección por vencimiento). Es el dashboard de
 *         `/reports/open-invoices`. NO toma `state` ni `contactId`: por definición mira
 *         cobrar Y pagar en la misma respuesta — el neto entre las dos es justamente
 *         el número que el dueño viene a buscar, y pedirlo en dos requests sería dos
 *         fotos de momentos distintos.
 *
 * Read-only. Auth: realms `panel` y `api` (lectura programatica). Sin ROC (el service bindea companyId y outletId en
 * cada SELECT en vez de interpolarlos como hace `Roc::build`).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\OpenInvoicesService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$state = (validateHttp('state') === 'outcome') ? 'outcome' : 'income';
$isSummary = (validateHttp('view') === 'summary');

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

$contactId = (string) (validateHttp('contactId') ?? '');
if ($contactId !== '' && !preg_match($uuidRe, $contactId)) {
    apiError('contactId inválido', 422);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Este archivo sirve DOS reportes distintos y una tercera cosa que no es un
 * reporte, así que una sola clave sería la equivocada para dos de los tres.
 *
 *   state=income   Cuentas por COBRAR: las ventas a crédito del comercio, con
 *                  cliente y saldo. Es el reporte de ventas visto por lo que
 *                  falta cobrar → `reports.sales.view`.
 *   state=outcome  Cuentas por PAGAR: las compras a crédito a proveedores →
 *                  `reports.purchases.view`, la misma clave que ya exige
 *                  `reports/purchases.php`.
 *
 * Y con `contactId` deja de ser el reporte de la empresa: es la lista de
 * facturas abiertas de UN contacto, que es lo que carga el diálogo de pago
 * multi-factura (`components/domain/transactions/multi-invoice-payment-dialog.tsx`)
 * antes de cobrar o pagar. Ese camino se abre por la capacidad de COBRAR, no
 * por la de ver el reporte, y las claves son las que ya gatean la escritura
 * correspondiente en `credit-payments.php:129` — `pos.sale.creditPayment` para
 * cobrarle a un cliente, `finance.manage` para pagarle a un proveedor. Colgarlo
 * de la clave del reporte le sacaba el cobro de crédito al rol `cashier` del
 * seed, que tiene `pos.sale.creditPayment` y no `reports.sales.view`: el mismo
 * caso que ya resolvió el detalle de `reports/transactions.php`.
 *
 * El gate va DESPUÉS de leer `state` y `contactId` porque depende de ellos —
 * es lo que se pide, no quién pide, lo que decide la clave.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';

if ($isSummary) {
    // `view=summary` devuelve ventas a crédito Y compras a crédito en la misma
    // respuesta, así que pide LAS DOS claves — no "cualquiera de las dos". Con
    // `requireAnyPermission` un rol de ventas se llevaba de regalo la deuda con
    // proveedores, que es exactamente lo que la rama por `state` de acá abajo
    // separa. Son dos `requirePermission` seguidos y no una variante nueva del
    // helper: el AND ya es expresable con lo que hay.
    if ($contactId !== '') {
        apiError('view=summary no acepta contactId: es el agregado de la empresa', 422);
    }
    \Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');
    \Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.purchases.view');
} else {
    $permsLectura = $state === 'outcome'
        ? ['reports.purchases.view']
        : ['reports.sales.view'];
    if ($contactId !== '') {
        $permsLectura[] = $state === 'outcome' ? 'finance.manage' : 'pos.sale.creditPayment';
    }
    \Punto\Api\Auth\OperatorContext::requireAnyPermission($ctx, $permsLectura);
}

// Sucursal efectiva del view-scope — mismo patrón que reports/stock.php y
// reports/dashboard.php. `VIEW_OUTLET_ID` la define bootstrap.php a partir del
// header X-Outlet-Id del selector del panel: '' cuando el usuario eligió "Todas"
// (consolidado), el UUID cuando eligió una. Sin el header definido cae a la
// sucursal del token.
//
// Este reporte NO lo tenía: listaba las cuentas por cobrar/pagar de TODAS las
// sucursales aunque hubiera una elegida (reporte del tester, 2026-08-28).
// `OutletScope::effectiveIds()` unifica el idiom (VIEW_OUTLET_ID → OUTLET_ID →
// conjunto asignado) que estaba copiado en cinco endpoints, y ya devuelve uuids
// validados — el guard a mano que había acá dejó de hacer falta. `[]` ("Todas")
// consolida; 2+ acota al conjunto del usuario, que antes era un 422: este
// reporte lista facturas y suma saldos, y las dos operaciones aceptan un
// conjunto sin ninguna ambigüedad.
$effectiveOutletIds = \Punto\Api\Outlets\OutletScope::effectiveIds();

apiOk($isSummary
    ? $svc->summary(COMPANY_ID, $effectiveOutletIds)
    : $svc->general($state, COMPANY_ID, $contactId !== '' ? $contactId : null, $effectiveOutletIds));
