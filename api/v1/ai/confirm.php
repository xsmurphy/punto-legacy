<?php
/**
 * POST /v1/ai/confirm
 *
 * Registra un LOTE de una o más acciones propuestas por el agente IA y genera
 * UN token de confirmación que el usuario deberá aprobar antes de ejecutar.
 *
 * Body JSON (formato batch, preferido):
 *   { actions: [{ action, payload }, ...], summary }
 *     actions  — array de 1+ acciones, cada una de AI_CONFIRM_ALLOWED_ACTIONS
 *     summary  — string, descripción legible del LOTE completo para el usuario
 *
 * Body JSON (formato legacy, compat): { action, payload, summary }
 *   Se envuelve automáticamente en actions:[{action,payload}].
 *
 * Response: { ok: true, data: { confirmToken, summary, count } }
 *
 * Auth: realms `panel` y `pos-app`. En la caja la autorización NO sale de la
 * credencial sino del operador que probó su PIN — ver `AgentActor`.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once API_APP_DIR . '/includes/ai_confirm_store.php';
require_once dirname(__DIR__, 2) . '/lib/Ai/AgentActor.php';
require_once dirname(__DIR__, 2) . '/lib/Ai/ContactPayload.php';

// MISMO gate de entrada que `execute.php`, resuelto por el MISMO objeto. Que
// las dos mitades de la operación compartan la definición de "quién es el actor
// y qué puede" es el punto entero de `AgentActor`: si `confirm` se aflojara sin
// que `execute` se entere, se registrarían lotes que nadie debió poder pedir.
$ctx = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];

$actor = \Punto\Api\Ai\AgentActor::authorize($ctx);
// El token queda a nombre del ACTOR (en la caja, el operador del PIN), y
// `execute` exige que quien lo consuma sea el mismo. Ver ai_confirm_store.php.
$userId = $actor->userId();

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
    // Configuración del comercio (context/66 F1, D1 del owner 2026-09-01: el
    // agente CONFIGURA la cuenta, no solo guía). Las tres se bloquean en la
    // caja — ver AgentActor::POS_BLOCKED_ACTIONS.
    'create_outlet',
    'create_register',
    'assign_role',
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
            // Dirección default: el ContactService la crea junto con el
            // contacto. Lo único que hay que validar acá son las coordenadas,
            // que van de a par — ver ContactPayload::coordsError().
            if ($coordsError = \Punto\Api\Ai\ContactPayload::coordsError($payload)) {
                apiError($coordsError, 400);
            }
            break;

        case 'update_contact':
            if (strlen((string) ($payload['id'] ?? '')) < 30) {
                apiError('id de contacto inválido', 400);
            }
            if ($coordsError = \Punto\Api\Ai\ContactPayload::coordsError($payload)) {
                apiError($coordsError, 400);
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

        case 'create_outlet':
            if (empty(trim((string) ($payload['name'] ?? '')))) {
                apiError('name es obligatorio', 400);
            }
            break;

        case 'create_register':
            // La caja necesita SUCURSAL, NOMBRE y TIMBRADO. El error de acá no
            // es solo defensa: es de donde sale la repregunta del bot al
            // cliente ("dame el nro. de timbrado"), así que nombra el dato que
            // falta en los términos en que se lo va a pedir.
            if (empty(trim((string) ($payload['outletId'] ?? ''))) && empty(trim((string) ($payload['outletName'] ?? '')))) {
                apiError('Falta la sucursal de la caja (outletId u outletName)', 400);
            }
            if (empty(trim((string) ($payload['name'] ?? '')))) {
                apiError('name es obligatorio', 400);
            }
            // Timbrado Y punto de expedición son obligatorios JUNTOS: la
            // unicidad fiscal es del PAR (context/29), y una caja creada sin
            // punto de expedición no puede emitir — el agente estaría dejando
            // configurada a medias justamente la pieza que vino a configurar.
            if (empty(trim((string) ($payload['timbrado'] ?? '')))) {
                apiError('Falta el número de timbrado de la caja', 400);
            }
            if (empty(trim((string) ($payload['expeditionPoint'] ?? '')))) {
                apiError('Falta el punto de expedición de la caja (EEE-PPP, ej. 001-001)', 400);
            }
            // El FORMATO (timbrado numérico, EEE-PPP) y la UNICIDAD del par no
            // se validan acá a propósito: viven en RegisterAdminService, que es
            // la única fuente de esa regla. Duplicarlas es exactamente lo que
            // no puede pasar con la numeración fiscal — la copia que se quede
            // vieja emite facturas duplicadas. Sus mensajes ya son legibles y
            // llegan al usuario tal cual por el resultado de la acción.
            break;

        case 'assign_role':
            // El usuario va por id, no por nombre: "Juan" puede ser dos
            // personas y equivocarse de Juan es darle o quitarle accesos a
            // alguien que nadie nombró. El agente tiene `get_users` para
            // resolver el id antes de proponer la acción.
            if (strlen((string) ($payload['id'] ?? '')) < 30) {
                apiError('id de usuario inválido', 400);
            }
            if (empty(trim((string) ($payload['roleName'] ?? '')))) {
                apiError('roleName es obligatorio', 400);
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

    // Alcance por superficie: hay acciones que no se piden desde una caja (hoy,
    // `create_user`). Se corta en el registro para que el operador reciba el
    // "no" en el acto y no después de confirmar un lote que iba a fallar.
    if (!$actor->allowsAction($action)) {
        apiError('Esa acción no se puede hacer desde la caja — se hace desde el panel', 403);
    }

    $payload['action'] = $action;
    $normalized[] = $payload;
}

$token = aiConfirmStoreCreate(['actions' => $normalized], $companyId, $userId);
if ($token === null) {
    apiError('No se pudo registrar la confirmación (Redis)', 503);
}

apiOk(['confirmToken' => $token, 'summary' => $summary, 'count' => count($normalized)]);
