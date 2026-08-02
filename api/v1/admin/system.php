<?php

/**
 * /api/v1/admin/system.php — estado del sistema (realm /admin, F6 §4,
 * context/34-admin-saas-plan.md). Solo lectura, solo rol 'owner'.
 *
 *   GET → { version: {appVersion, deployedAt}, migrations: [...últimas 10],
 *            counts: {tenants, users, transactionsToday}, sentry: {configured, link} }
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/SystemStatusService.php';

adminMiddleware();
adminRequireRole('owner');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

apiOk((new SystemStatusService())->status());
