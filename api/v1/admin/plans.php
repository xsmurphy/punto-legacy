<?php

/**
 * /api/v1/admin/plans.php — CRUD de planes del catálogo SaaS (realm /admin).
 *
 * Gateado por adminMiddleware() (sesión opaca admin). NO apiMiddleware.
 *
 * GET  ?archived=1        → lista TODOS los planes (incl. archivados) con
 *                            conteo de tenants vigentes por plan_code.
 * GET  (sin archived)      → solo planes activos (archived=0).
 * GET  ?code=<int>         → detalle de un plan.
 * POST                     → crea un plan nuevo (plan_code auto-asignado).
 * PATCH ?code=<int>        → edita un plan. Regla de versionado NO retroactiva
 *                            (ver PlanAdminService::update docblock): si el
 *                            body solo trae `name`, edita in-place; si trae
 *                            price, duration_days, límites max_N, features o
 *                            ai_credits_monthly distintos a los actuales,
 *                            crea un plan_code nuevo y archiva el viejo.
 * POST ?code=<int>&action=archive → archiva el plan (no se ofrece más para
 *                            asignar a tenants nuevos; los vigentes siguen igual).
 *
 * Ver context/34-admin-saas-plan.md F4.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/PlanAdminService.php';

adminMiddleware();
adminRequireRole('owner'); // bucket "planes" — owner-only (matriz F6)

$svc    = new PlanAdminService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** `code` es plan_code (smallint) — valida numérico, nunca castea a ciegas (0 = plan default). */
function requireCode(?string $raw): int
{
    if ($raw === null || $raw === '' || !ctype_digit($raw)) {
        apiError('code inválido — debe ser un entero ≥ 0', 400);
    }
    return (int) $raw;
}

if ($method === 'GET') {
    $codeRaw = isset($_GET['code']) ? (string) $_GET['code'] : null;
    if ($codeRaw !== null && $codeRaw !== '') {
        $plan = $svc->get(requireCode($codeRaw));
        if (!$plan) {
            apiNotFound('Plan no encontrado');
        }
        apiOk($plan);
    }

    $includeArchived = !empty($_GET['archived']);
    apiOk(['rows' => $svc->list($includeArchived)]);
}

if ($method === 'POST') {
    $action  = trim((string) ($_GET['action'] ?? ''));
    $codeRaw = isset($_GET['code']) ? (string) $_GET['code'] : null;

    if ($action === 'archive') {
        $code   = requireCode($codeRaw);
        $result = $svc->archive($code);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('archivePlan', 'plan', (string) $code);
        apiOk($result);
    }

    // Crear plan nuevo.
    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        apiError('Body JSON inválido', 400);
    }

    $result = $svc->create($input);
    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }
    adminAudit('createPlan', 'plan', (string) ($result['plan']['code'] ?? ''), $result['plan']['name'] ?? null, [
        'input' => $input,
    ]);
    apiOk($result);
}

if ($method === 'PATCH') {
    $code = requireCode(isset($_GET['code']) ? (string) $_GET['code'] : null);

    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        apiError('Body JSON inválido', 400);
    }

    $result = $svc->update($code, $input);
    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }

    adminAudit(
        $result['versioned'] ? 'versionPlan' : 'updatePlan',
        'plan',
        (string) ($result['plan']['code'] ?? $code),
        $result['plan']['name'] ?? null,
        [
            'fields'       => array_keys($input),
            'versioned'    => $result['versioned'],
            'archivedCode' => $result['archivedCode'] ?? null,
        ]
    );
    apiOk($result);
}

apiError('Método no permitido', 405);
