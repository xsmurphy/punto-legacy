<?php

/**
 * /API/v1/admin/health.php — semáforo de salud/adopción por tenant (F2,
 * ver context/34-admin-saas-plan.md).
 *
 * Gateado por adminMiddleware() (JWT _jwt_admin, aud:"admin"). NO apiMiddleware.
 *
 *   GET                          → computeAll() (recomputa stale >6h) + listado
 *                                   [{companyId, name, score, level, computedAt, topIssue}]
 *   GET ?companyId=<uuid>        → detalle: signals por dimensión, checklist, history (12 semanas)
 *   POST ?action=recompute&companyId=<uuid> → fuerza recómputo de una empresa
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/TenantHealthService.php';

adminMiddleware(); // define ADMIN_AUTHED_ID o mata con 401

$svc    = new TenantHealthService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'GET') {
    $companyId = trim((string) ($_GET['companyId'] ?? ''));

    if ($companyId !== '') {
        if (!preg_match($uuidRe, $companyId)) {
            apiError('companyId inválido', 400);
        }
        $detail = $svc->getDetail($companyId);
        if (!$detail) {
            apiNotFound('Empresa no encontrada');
        }
        apiOk($detail);
    }

    apiOk($svc->computeAll());
}

if ($method === 'POST') {
    $action    = trim((string) ($_GET['action'] ?? ''));
    $companyId = trim((string) ($_GET['companyId'] ?? ''));

    if ($action === 'recompute') {
        if ($companyId === '' || !preg_match($uuidRe, $companyId)) {
            apiError('Parámetros inválidos: se requiere ?action=recompute&companyId=<uuid>', 400);
        }
        $result = $svc->computeFor($companyId);
        if (isset($result['ok']) && $result['ok'] === false) {
            apiNotFound($result['error'] ?? 'Empresa no encontrada');
        }
        adminAudit('recomputeTenantHealth', 'company', $companyId, null, [
            'score' => $result['score'] ?? null,
            'level' => $result['level'] ?? null,
        ]);
        apiOk($result);
    }

    apiError('Acción no soportada', 422);
}

apiError('Método no permitido', 405);
