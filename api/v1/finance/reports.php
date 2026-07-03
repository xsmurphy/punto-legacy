<?php
/**
 * REST — Reportes de Finanzas (montos agregados por dimensión).
 *
 *   GET /v1/finance/reports?by=category&from=&to= → ingresos/egresos/neto
 *                                                    del período, agrupados
 *                                                    por categoría (default).
 *   GET /v1/finance/reports?by=account&from=&to=  → ídem, agrupado por cuenta.
 *
 *       from/to default: mes calendario actual (tenant-local, naive — §51),
 *       mismo default que /v1/finance/summary. Este router mapea 1 archivo
 *       .php = 1 URL (sin PATH_INFO — ver api/router.php), por eso la
 *       dimensión viaja en el query string `by`, no en el path.
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$companyId = (string) COMPANY_ID;

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $from)) {
    $from = date('Y-m-01 00:00:00');
}
if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $to)) {
    $to = date('Y-m-d 23:59:59');
}

$by = trim((string) ($_GET['by'] ?? 'category'));

$movementSvc = new \Punto\Api\Finance\MovementService();

if ($by === 'account') {
    apiOk(['rows' => $movementSvc->totalsByAccount($companyId, $from, $to), 'period' => ['from' => $from, 'to' => $to]]);
}

// Default: category.
apiOk(['rows' => $movementSvc->totalsByCategory($companyId, $from, $to), 'period' => ['from' => $from, 'to' => $to]]);
