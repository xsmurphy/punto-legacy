<?php
/**
 * POST /v1/ai/confirm
 *
 * Registra una acción propuesta por el agente IA y genera un token de confirmación
 * que el usuario deberá aprobar antes de ejecutar.
 *
 * Body JSON: { action, payload, summary }
 *   action   — string, una de las 8 acciones permitidas
 *   payload  — object con los datos de la acción
 *   summary  — string, descripción legible para el usuario
 *
 * Response: { ok: true, data: { confirmToken, summary } }
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once API_APP_DIR . '/includes/ai_confirm_store.php';

$ctx = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Method not allowed', 405);
}

// $_POST viene ya hidratado del JSON body por bootstrap.php (todos los verbos
// no-form-encoded). No re-parseamos acá.
$action  = trim((string) ($_POST['action']  ?? ''));
$payload = $_POST['payload'] ?? null;
$summary = trim((string) ($_POST['summary'] ?? ''));

$allowed = [
    'create_contact',
    'update_contact',
    'create_item',
    'update_item_price',
    'create_user',
    'create_category',
    'create_brand',
    'create_tag',
    'tabular_import',
];

if (!in_array($action, $allowed, true)) {
    apiError('Acción no permitida: ' . $action, 400);
}

if (!is_array($payload)) {
    apiError('payload debe ser un objeto', 400);
}

// Validación liviana por action
switch ($action) {
    case 'create_contact':
        if (!in_array((int) ($payload['type'] ?? 0), [1, 2], true)) {
            apiError('type debe ser 1 (cliente) o 2 (proveedor)', 400);
        }
        if (empty(trim((string) ($payload['name'] ?? '')))) {
            apiError('name es obligatorio', 400);
        }
        break;

    case 'update_contact':
        if (strlen((string) ($payload['id'] ?? '')) < 30) {
            apiError('id de contacto inválido', 400);
        }
        break;

    case 'create_item':
        if (!in_array($payload['kind'] ?? '', ['producto', 'servicio'], true)) {
            apiError('kind debe ser "producto" o "servicio"', 400);
        }
        if (empty(trim((string) ($payload['name'] ?? '')))) {
            apiError('name es obligatorio', 400);
        }
        if (!is_numeric($payload['price'] ?? null) || (float) $payload['price'] < 0) {
            apiError('price debe ser numérico >= 0', 400);
        }
        break;

    case 'update_item_price':
        if (empty($payload['id'] ?? '')) {
            apiError('id es obligatorio', 400);
        }
        if (!is_numeric($payload['newPrice'] ?? null) || (float) $payload['newPrice'] < 0) {
            apiError('newPrice debe ser numérico >= 0', 400);
        }
        break;

    case 'create_user':
        if (empty(trim((string) ($payload['name'] ?? '')))) {
            apiError('name es obligatorio', 400);
        }
        if (empty(trim((string) ($payload['phone'] ?? '')))) {
            apiError('phone es obligatorio', 400);
        }
        break;

    case 'create_category':
    case 'create_brand':
    case 'create_tag':
        if (empty(trim((string) ($payload['name'] ?? '')))) {
            apiError('name es obligatorio', 400);
        }
        break;

    case 'tabular_import':
        if (empty(trim((string) ($payload['sessionId'] ?? '')))) {
            apiError('sessionId es obligatorio', 400);
        }
        if (!in_array($payload['kind'] ?? '', ['items', 'contacts'], true)) {
            apiError('kind debe ser "items" o "contacts"', 400);
        }
        if (!in_array($payload['mode'] ?? '', ['insert', 'update'], true)) {
            apiError('mode debe ser "insert" o "update"', 400);
        }
        break;
}

$payload['action'] = $action;

$token = aiConfirmStoreCreate($payload, $companyId, $userId);
if ($token === null) {
    apiError('No se pudo registrar la confirmación (Redis)', 503);
}

apiOk(['confirmToken' => $token, 'summary' => $summary]);
