<?php

/**
 * /api/v1/admin/ai-config.php — modelos IA, paquetes de créditos y reporte
 * de consumo (realm /admin). Ver context/34-admin-saas-plan.md F4 §3.
 *
 * Gateado por adminMiddleware(). NO apiMiddleware.
 *
 * GET → { models, packages, consumption } — todo en una sola llamada (la
 *        página admin/ai muestra las tres secciones juntas).
 *
 * POST body {action, ...}:
 *   action=upsertModel    {capability, model, enabled?, creditsPerKToken?}
 *   action=createPackage  {name, credits, price}
 *   action=updatePackage  {packageId, name?, credits?, price?}
 *   action=archivePackage {packageId}
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/AiAdminService.php';

adminMiddleware();

$svc    = new AiAdminService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'GET') {
    apiOk([
        'models'      => $svc->listModels(),
        'packages'    => $svc->listPackages(),
        'consumption' => $svc->consumptionReport(),
    ]);
}

if ($method === 'POST') {
    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        apiError('Body JSON inválido', 400);
    }

    $action = trim((string) ($input['action'] ?? ''));

    if ($action === 'upsertModel') {
        $result = $svc->upsertModel($input);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('upsertAiModel', 'aiModel', $result['model']['capability'] ?? null, $result['model']['model'] ?? null, $result['model'] ?? []);
        apiOk($result);
    }

    if ($action === 'createPackage') {
        $result = $svc->createPackage($input);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('createAiPackage', 'aiPackage', $result['packageId'] ?? null, $input['name'] ?? null, $input);
        apiOk($result);
    }

    if ($action === 'updatePackage') {
        $packageId = trim((string) ($input['packageId'] ?? ''));
        if ($packageId === '' || !preg_match($uuidRe, $packageId)) {
            apiError('packageId inválido', 400);
        }
        $result = $svc->updatePackage($packageId, $input);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('updateAiPackage', 'aiPackage', $packageId, $result['package']['name'] ?? null, ['fields' => array_keys($input)]);
        apiOk($result);
    }

    if ($action === 'archivePackage') {
        $packageId = trim((string) ($input['packageId'] ?? ''));
        if ($packageId === '' || !preg_match($uuidRe, $packageId)) {
            apiError('packageId inválido', 400);
        }
        $result = $svc->archivePackage($packageId);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('archiveAiPackage', 'aiPackage', $packageId);
        apiOk($result);
    }

    apiError('Acción no soportada', 422);
}

apiError('Método no permitido', 405);
