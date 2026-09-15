<?php

/**
 * /api/v1/admin/plans.php — CRUD de planes del catálogo SaaS (realm /admin).
 *
 * Gateado por adminMiddleware() (sesión opaca admin). NO apiMiddleware.
 *
 * GET                      → lista TODOS los planes con conteo de tenants por
 *                            plan_code (`archived` viaja como historial, no filtra).
 * GET  ?code=<int>         → detalle de un plan.
 * POST                     → crea un plan nuevo (plan_code auto-asignado).
 * PATCH ?code=<int>        → edita el plan EN EL LUGAR: el cambio aplica a todos
 *                            los tenants con ese plan_code (owner 2026-09-15; el
 *                            versionado de F4 quedó SUPERSEDED — ver el docblock
 *                            de PlanAdminService).
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

    apiOk(['rows' => $svc->list()]);
}

if ($method === 'POST') {
    // Ya no hay acciones sobre un plan existente: archivar era parte del
    // versionado de F4 y nada escribe `plans.archived` desde 2026-09-15.
    if (trim((string) ($_GET['action'] ?? '')) !== '') {
        apiError('Acción no soportada', 400);
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

    adminAudit('updatePlan', 'plan', (string) $code, $result['plan']['name'] ?? null, [
        // El input completo, no solo los nombres: un cambio de precio o de
        // límites aplica a todos los tenants del plan y tiene que quedar qué
        // valor se puso.
        'input'   => $input,
        'tenants' => $result['tenants'] ?? null,
    ]);
    apiOk($result);
}

apiError('Método no permitido', 405);
