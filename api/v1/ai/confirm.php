<?php
/**
 * POST /v1/ai/confirm
 *
 * Registra un LOTE de una o más acciones propuestas por el agente IA y genera
 * UN token de confirmación que el usuario deberá aprobar antes de ejecutar.
 *
 * Body JSON (formato batch, preferido):
 *   { actions: [{ action, payload }, ...], summary }
 *     actions  — array de 1+ acciones, cada una de las 9 acciones permitidas
 *     summary  — string, descripción legible del LOTE completo para el usuario
 *
 * Body JSON (formato legacy, compat): { action, payload, summary }
 *   Se envuelve automáticamente en actions:[{action,payload}].
 *
 * Response: { ok: true, data: { confirmToken, summary, count } }
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once API_APP_DIR . '/includes/ai_confirm_store.php';

$ctx = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Method not allowed', 405);
}

const AI_CONFIRM_ALLOWED_ACTIONS = [
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

/**
 * Valida una acción individual del lote. Aborta el request (apiError) si la
 * acción o su payload son inválidos. Misma lógica de validación que existía
 * antes por-request, ahora reusable por-item para el batch.
 */
function aiConfirmValidateAction(string $action, mixed $payload): void
{
    if (!in_array($action, AI_CONFIRM_ALLOWED_ACTIONS, true)) {
        apiError('Acción no permitida: ' . $action, 400);
    }

    if (!is_array($payload)) {
        apiError('payload debe ser un objeto (acción: ' . $action . ')', 400);
    }

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
}

// $_POST viene ya hidratado del JSON body por bootstrap.php (todos los verbos
// no-form-encoded). No re-parseamos acá.
$summary    = trim((string) ($_POST['summary'] ?? ''));
$rawActions = $_POST['actions'] ?? null;

if (is_array($rawActions) && array_is_list($rawActions)) {
    // Formato batch.
    $actions = $rawActions;
} else {
    // Formato legacy: { action, payload, summary } → envolver en actions:[...].
    $legacyAction  = trim((string) ($_POST['action'] ?? ''));
    $legacyPayload = $_POST['payload'] ?? null;
    $actions = [['action' => $legacyAction, 'payload' => $legacyPayload]];
}

if (count($actions) < 1) {
    apiError('actions debe tener al menos 1 elemento', 400);
}

$normalized = [];
foreach ($actions as $item) {
    if (!is_array($item)) {
        apiError('Cada elemento de actions debe ser un objeto {action, payload}', 400);
    }
    $action  = trim((string) ($item['action'] ?? ''));
    $payload = $item['payload'] ?? null;

    aiConfirmValidateAction($action, $payload);

    $payload['action'] = $action;
    $normalized[] = $payload;
}

$token = aiConfirmStoreCreate(['actions' => $normalized], $companyId, $userId);
if ($token === null) {
    apiError('No se pudo registrar la confirmación (Redis)', 503);
}

apiOk(['confirmToken' => $token, 'summary' => $summary, 'count' => count($normalized)]);
