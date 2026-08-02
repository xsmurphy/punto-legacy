<?php

/**
 * /API/v1/admin/dashboard.php — agregados cross-tenant para el dashboard y reportes del admin.
 *
 * Gateado por adminMiddleware() (JWT _jwt_admin, aud:"admin"). NO apiMiddleware.
 *
 *   GET                               → overview(): KPIs, MRR/ARR, distribuciones, top IA
 *   GET ?resource=payments&from=&to=  → payments(from,to): pagos del período
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/AdminReportsService.php';

adminMiddleware(); // define ADMIN_AUTHED_ID o mata con 401
adminRequireRole('sales'); // KPIs/analíticas — lectura para todos los roles

$svc    = new AdminReportsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$resource = trim((string) ($_GET['resource'] ?? ''));

if ($resource === 'payments') {
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to']   ?? ''));
    apiOk($svc->payments($from, $to));
}

// Sin ?resource → overview general.
apiOk($svc->overview());
