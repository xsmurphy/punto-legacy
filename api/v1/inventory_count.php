<?php
/**
 * /api/v1/inventory_count.php — conteo de inventario (toma física).
 *
 * GET  ?action=list[&outletId=<uuid>][&status=<0|1|2>][&limit=50][&offset=0]
 * GET  ?action=get&id=<uuid>
 * POST { action: "create", outletId, locationId?, note?, categoryIds?, includeZeroStock? }
 * POST { action: "preview", outletId, locationId?, categoryIds?, includeZeroStock? }
 *      → { count } — cuántos artículos entrarían, sin crear nada.
 * POST { action: "setQty", id, itemId, qty }
 * POST { action: "bulkSetQty", id, rows: [{itemId, qty}] }
 * POST { action: "finish", id }
 * POST { action: "cancel", id }
 * POST { action: "registerCount", listId, listName?, itemIds?,
 *        rows: [{itemId, qty}], countedAt?, note? }  ← realm pos-app
 *      La SUCURSAL y la CAJA NO viajan en el body: salen del contexto del
 *      dispositivo (ver el bloque de la acción).
 *      → conteo COMPLETO desde la caja, atómico e idempotente por el header
 *        `X-Punto-Op-Id`. Ver InventoryCountService::submitFromRegister().
 *
 * ── Realms: el embudo se abre por ACCIÓN, no de una ─────────────────────────
 *
 * `apiAuthTenant()` ahora acepta `pos-app`, pero eso NO convierte a este
 * endpoint en un endpoint de caja: es la puerta del edificio, no la de cada
 * cuarto. Todo lo que existía sigue siendo panel-only y sigue exigiendo
 * `inventory.stock.adjust`; lo único que la caja puede hacer es
 * `registerCount`, y para eso necesita `pos.stock.count` evaluado contra el
 * OPERADOR del PIN.
 *
 * Por qué así y no abriendo el embudo entero: bajo `pos-app`, `hasPermission()`
 * resuelve contra el rol `device`, que es el mismo para todos los que agarran
 * la tablet. Un gate escrito con ese helper "pasa" en el panel y en la caja da
 * la falsa impresión de estar cerrado (lo documentan `returns.php` y
 * `users.php`). Dejar el GET y los `setQty`/`finish`/`cancel` accesibles al
 * realm de la caja sería exactamente eso, y de paso le daría a la caja el
 * camino para leer el esperado que el conteo ciego le esconde.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/InventoryCountService.php';
require_once __DIR__ . '/../lib/Auth/OperatorContext.php';

use Punto\Api\Auth\OperatorContext;
use Punto\Api\Services\InventoryCountService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$realm     = (string) ($ctx['realm'] ?? '');
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Todo lo que no es el conteo de la caja sigue siendo del panel.
 *
 * Fail-closed y explícito: no alcanza con que el gate de permisos "no pase"
 * bajo `pos-app`, porque ese gate mide el rol equivocado. Acá se rechaza por
 * realm, antes de mirar permisos.
 */
function requirePanelRealm(string $realm): void
{
    if ($realm !== 'panel') {
        apiError('Esta acción no está disponible desde la caja', 403);
    }
}

$svc = new InventoryCountService();

function isValidUuid(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

if ($method === 'GET') {
    // Las lecturas son del panel. La caja no las necesita —cuenta a ciegas
    // sobre una lista que ya trae del bootstrap— y dárselas sería la puerta de
    // atrás al esperado que el modo ciego le esconde.
    requirePanelRealm($realm);

    $action = (string) ($_GET['action'] ?? 'list');

    if ($action === 'list') {
        $outletId = isset($_GET['outletId']) && isValidUuid($_GET['outletId']) ? $_GET['outletId'] : null;
        $status   = isset($_GET['status'])   && is_numeric($_GET['status'])    ? (int) $_GET['status'] : null;
        $limit    = max(1, min(200, (int) ($_GET['limit']  ?? 50)));
        $offset   = max(0, (int) ($_GET['offset'] ?? 0));

        $result = $svc->list($companyId, $outletId, $status, $limit, $offset);
        apiOk($result);
    }

    if ($action === 'get') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }

        $data = $svc->get($id, $companyId);
        if ($data === null) {
            apiError('Sesión no encontrada', 404);
        }

        apiOk($data);
    }

    apiError('action desconocida', 400);
}

if ($method === 'POST') {
    $body   = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = (string) ($body['action'] ?? '');

    // ── Conteo desde la caja (context/63 F1) ────────────────────────────────
    //
    // Único camino abierto al realm `pos-app`, y con su propio permiso. Se
    // resuelve ANTES del gate de panel de abajo porque no comparte ninguna de
    // sus premisas: no exige `inventory.stock.adjust` (que nunca llega a la
    // tablet — `unlock-pin.php` filtra al prefijo `pos.`) y se evalúa contra el
    // rol del OPERADOR del PIN, no contra la credencial.
    //
    // `requirePermission()` es fail-closed sin operador: una tablet sin nadie
    // desbloqueado recibe 403, no cae al rol del device. Es lo que hace que el
    // conteo —y el ajuste de stock que genera— quede atribuido a una PERSONA.
    if ($action === 'registerCount') {
        OperatorContext::requirePermission($ctx, 'pos.stock.count');

        $operator   = OperatorContext::resolve($ctx);
        $operatorId = (string) ($operator['userId'] ?? '');
        if ($operatorId === '') {
            // Inalcanzable: requirePermission() ya cortó sin operador. Queda
            // como red — el startedBy/finishedBy del conteo NO puede caer al
            // usuario que pareó el dispositivo hace tres meses.
            apiError('Desbloqueá la caja con tu PIN para contar', 403);
        }

        // Identidad de la operación: el mismo header que usa el resto de la
        // cola offline (`context/51` §3). Sin él no hay idempotencia posible,
        // así que se exige en vez de inventar uno server-side — un opId que
        // genera el servidor cambia en cada reenvío y no sirve de nada.
        $opId = trim((string) ($_SERVER['HTTP_X_PUNTO_OP_ID'] ?? ''));
        if ($opId === '' || strlen($opId) > 64) {
            apiError('Falta el header X-Punto-Op-Id (o es inválido)', 400);
        }

        // La SUCURSAL y la CAJA salen del contexto del dispositivo, NUNCA del
        // body. `apiAuthTenant()` las resuelve de la fila `device` y falla
        // cerrado si faltan; el body es un dato del cliente.
        //
        // No es formalismo: el conteo termina en un ajuste de stock DE UNA
        // sucursal. Aceptando el `outletId` del payload, cualquier tablet
        // pareada del comercio puede mover el inventario de una sucursal en la
        // que no está — el UUID es del tenant, así que ninguna validación de
        // pertenencia lo atrapa. Es la misma convención que ya documenta
        // `printer_binding.php` ("companyId/outletId SIEMPRE del JWT").
        //
        // El `outletId` que manda la caja se ignora en silencio a propósito:
        // no hay caso legítimo en que difiera, y rechazar la operación por eso
        // tiraría un conteo físico ya hecho por un desajuste que el cajero no
        // puede resolver.
        $outletId   = (string) ($ctx['outletId'] ?? '');
        $registerId = (string) ($ctx['registerId'] ?? '');
        if (!isValidUuid($outletId)) {
            apiError('Este dispositivo no tiene una sucursal asignada', 409);
        }

        $listId = trim((string) ($body['listId'] ?? ''));
        if ($listId === '' || strlen($listId) > 64) {
            apiError('listId inválido', 400);
        }

        $rows = $body['rows'] ?? [];
        if (!is_array($rows) || count($rows) === 0) {
            apiError('rows debe ser un array no vacío', 400);
        }

        $counted = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['itemId'], $r['qty'])
                || !isValidUuid((string) $r['itemId']) || !is_numeric($r['qty'])) {
                apiError('Fila inválida en rows: se requieren itemId (UUID) y qty (numérico)', 400);
            }
            // Una cantidad contada no puede ser negativa: no existe "menos tres
            // hamburguesas en el mostrador". Sin este corte, un typo genera un
            // ajuste que resta el doble.
            $qty = (float) $r['qty'];
            if ($qty < 0) {
                apiError('Las cantidades contadas no pueden ser negativas', 400);
            }
            $counted[strtolower((string) $r['itemId'])] = $qty;
        }

        // Respaldo por si la lista se borró mientras la operación viajaba — ver
        // el docblock de submitFromRegister(). NO es lo que define el alcance
        // cuando la lista existe: ahí manda el servidor.
        $itemIdsFallback = [];
        foreach ((array) ($body['itemIds'] ?? []) as $itemId) {
            $itemId = strtolower(trim((string) $itemId));
            if (isValidUuid($itemId)) {
                $itemIdsFallback[] = $itemId;
            }
        }

        // Momento en que se contó, para resolver el turno sin misatribuir un
        // conteo que esperó en la cola (ver resolveDrawerContext). Se valida el
        // formato acá: va a un cast `::timestamptz` en SQL, y un string
        // arbitrario ahí es un error de query, no un dato faltante.
        $countedAt = trim((string) ($body['countedAt'] ?? ''));
        if ($countedAt !== '' && strtotime($countedAt) === false) {
            apiError('countedAt inválido', 400);
        }

        try {
            apiOk($svc->submitFromRegister(
                $companyId,
                $outletId,
                $operatorId,
                $opId,
                $listId,
                mb_substr(trim((string) ($body['listName'] ?? '')), 0, 60),
                $counted,
                $itemIdsFallback,
                isValidUuid($registerId) ? $registerId : null,
                $countedAt !== '' ? $countedAt : null,
                trim((string) ($body['note'] ?? '')) ?: null,
            ));
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 409);
        }
    }

    // ── De acá para abajo, todo es del panel ────────────────────────────────
    requirePanelRealm($realm);

    // Cerrar un conteo ajusta stock contra lo contado: mismo criterio que
    // /v1/stock_adjustment. La UI ya gateaba con esta clave
    // (panel-auth-guard.tsx) pero el endpoint no — llamando la API directo
    // cualquier usuario del comercio podía cerrar un conteo y mover inventario.
    // El GET queda con la auth de panel (ver production.php/waste.php).
    if (!hasPermission('inventory.stock.adjust')) {
        apiError('No tenés permiso para esta acción (requiere: inventory.stock.adjust)', 403);
    }

    // `create` y `preview` comparten alcance (sucursal/depósito/categorías/
    // includeZeroStock): se parsea UNA vez para que el "vas a contar N" del
    // diálogo no pueda validar distinto que la creación real.
    if ($action === 'create' || $action === 'preview') {
        $outletId   = trim((string) ($body['outletId']   ?? ''));
        $locationId = trim((string) ($body['locationId'] ?? '')) ?: null;

        if (!isValidUuid($outletId)) {
            apiError('outletId inválido', 400);
        }
        if ($locationId !== null && !isValidUuid($locationId)) {
            apiError('locationId inválido', 400);
        }

        $rawCategories = $body['categoryIds'] ?? [];
        if (!is_array($rawCategories)) {
            apiError('categoryIds debe ser un array de UUIDs', 400);
        }
        $categoryIds = [];
        foreach ($rawCategories as $c) {
            $c = trim((string) $c);
            if (!isValidUuid($c)) {
                apiError('categoryIds contiene un UUID inválido', 400);
            }
            $categoryIds[] = $c;
        }
        // La pertenencia al tenant NO se chequea acá: la valida
        // InventoryCountScope, único punto por el que pasan los dos caminos.

        $includeZeroStock = (bool) ($body['includeZeroStock'] ?? false);

        if ($action === 'preview') {
            try {
                apiOk($svc->preview($companyId, $outletId, $locationId, $categoryIds, $includeZeroStock));
            } catch (\InvalidArgumentException $e) {
                apiError($e->getMessage(), 422);
            }
        }

        $note = trim((string) ($body['note'] ?? '')) ?: null;

        try {
            $result = $svc->create(
                $companyId,
                $outletId,
                $locationId,
                $userId,
                $note,
                $categoryIds,
                $includeZeroStock,
            );
            apiOk($result);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        }
    }

    if ($action === 'setQty') {
        $id     = trim((string) ($body['id']     ?? ''));
        $itemId = trim((string) ($body['itemId'] ?? ''));
        $qty    = $body['qty'] ?? null;

        if (!isValidUuid($id) || !isValidUuid($itemId)) {
            apiError('id o itemId inválido', 400);
        }
        if (!is_numeric($qty)) {
            apiError('qty debe ser numérico', 400);
        }

        $ok = $svc->setCountedQty($id, $itemId, (float) $qty, $userId, $companyId);
        if (!$ok) {
            apiError('Sesión no encontrada o no está en progreso', 404);
        }
        apiOk(['ok' => true]);
    }

    if ($action === 'bulkSetQty') {
        $id   = trim((string) ($body['id'] ?? ''));
        $rows = $body['rows'] ?? [];

        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }
        if (!is_array($rows) || count($rows) === 0) {
            apiError('rows debe ser un array no vacío', 400);
        }

        $validated = [];
        foreach ($rows as $r) {
            if (!isset($r['itemId'], $r['qty']) || !isValidUuid((string) $r['itemId']) || !is_numeric($r['qty'])) {
                apiError('Fila inválida en rows: se requieren itemId (UUID) y qty (numérico)', 400);
            }
            $validated[] = ['itemId' => $r['itemId'], 'qty' => (float) $r['qty']];
        }

        $count = $svc->bulkSetCountedQty($id, $validated, $userId, $companyId);
        apiOk(['updatedCount' => $count]);
    }

    if ($action === 'finish') {
        $id = trim((string) ($body['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }

        try {
            $result = $svc->finish($id, $companyId, $userId);
            apiOk($result);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 409);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 404);
        }
    }

    if ($action === 'cancel') {
        $id = trim((string) ($body['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }

        try {
            $ok = $svc->cancel($id, $companyId);
            if (!$ok) {
                apiError('Sesión no encontrada o ya finalizada', 404);
            }
            apiOk(['ok' => true]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 409);
        }
    }

    apiError('action desconocida', 400);
}

apiError('Método no soportado', 405);
