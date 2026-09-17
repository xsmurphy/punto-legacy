<?php
/**
 * /api/v1/replenishment-needs.php — necesidades de reposición
 * (context/70 §B.5, D6-D8, mig 228).
 *
 * GET  [?status=open|covered|closed][&outletId=<uuid>]  → { needs: [...] }
 * GET  ?id=<uuid>                                        → necesidad
 * POST { action: "produce",  id, qty? }                   → orden de producción en borrador vinculada
 * POST { action: "transfer", id, fromOutletId, fromLocationId?, toLocationId?, qty? }
 * POST { action: "close",    id, reason }                 → cierre manual (motivo obligatorio)
 * POST { action: "from-batch", outletId, locationId?, lines:[{itemId, qty}] }
 *                                                         → una necesidad por insumo FALTANTE de ese lote
 *
 * Las necesidades nacen solas (stock mínimo, conteo) o a pedido desde el lote
 * de producción (`from-batch`, mig 229), que es el único alta manual — y aun
 * así la CANTIDAD la calcula el servidor explotando las recetas, nunca llega
 * del cliente.
 *
 * Alcance por sucursal: un usuario con sucursales asignadas
 * (`contact_outlet`) ve y opera SOLO las necesidades de esas sucursales —
 * también por id, no solo en el listado.
 *
 * Permisos: leer, `inventory.item.view` (mismo criterio que el GET de
 * producción). Producir, `production.manage`; transferir,
 * `inventory.transfer`; cerrar, cualquiera de los dos (quien puede cubrirla).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Outlets\OutletScope;
use Punto\Api\Services\ReplenishmentService;

$ctx       = apiAuthTenant(['panel']);
$companyId = (string) $ctx['companyId'];
$userId    = (string) $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$scope   = OutletScope::forUser($companyId, $userId);
$allowed = $scope === [] ? null : $scope;

$svc = new ReplenishmentService();

/** Traduce las excepciones del servicio a HTTP sin filtrar mensajes de SQL. */
$fail = static function (\Throwable $e): never {
    if ($e instanceof \InvalidArgumentException) {
        $code = (int) $e->getCode();
        // 403 incluido: el servicio rechaza una sucursal fuera del alcance del
        // usuario con ese código, y degradarlo a 422 le diría "pedido mal
        // armado" a lo que en realidad es "no tenés acceso".
        apiError($e->getMessage(), in_array($code, [403, 404, 422], true) ? $code : 422);
    }
    if ($e instanceof \RuntimeException && (int) $e->getCode() === 409) {
        apiError($e->getMessage(), 409);
    }
    if ($e instanceof \Punto\Api\Support\DbQueryException || $e instanceof \PDOException) {
        throw $e;
    }
    // ProductionService / StockTransferService expresan sus rechazos de
    // negocio como RuntimeException sin código (p. ej. "no tiene receta").
    apiError($e->getMessage(), 422);
};

if ($method === 'GET') {
    if (!hasPermission('inventory.item.view')) {
        apiError('No tenés permiso para esta acción (requiere: inventory.item.view)', 403);
    }

    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        if (!preg_match($uuidRe, $id)) {
            apiError('id inválido', 400);
        }
        $need = $svc->get($companyId, $id, $allowed);
        if ($need === null) {
            apiError('Necesidad no encontrada', 404);
        }
        apiOk($need);
    }

    $filters = [];
    $status  = (string) ($_GET['status'] ?? '');
    if ($status !== '' && in_array($status, ReplenishmentService::STATUSES, true)) {
        $filters['status'] = $status;
    }
    $outletId = (string) ($_GET['outletId'] ?? '');
    if ($outletId !== '' && preg_match($uuidRe, $outletId)) {
        $filters['outletId'] = $outletId;
    }

    apiOk(['needs' => $svc->list($companyId, $filters, $allowed)]);
}

if ($method === 'POST') {
    $raw  = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($raw) ? $raw : (array) $_POST;

    $action = (string) ($body['action'] ?? '');

    // La única acción que NO opera sobre una necesidad existente: las CREA a
    // partir del faltante de un lote de producción, así que no lleva `id` y va
    // antes de exigirlo. La gate es la MISMA que gobierna el lote
    // (`production-batches.php`): quien puede armar el lote puede pedir lo que
    // le falta, y no es una capacidad nueva que cada comercio deba re-tildar.
    if ($action === 'from-batch') {
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }
        $outletId   = trim((string) ($body['outletId'] ?? ''));
        $locationId = trim((string) ($body['locationId'] ?? ''));
        if (!preg_match($uuidRe, $outletId)) {
            apiError('Elegí la sucursal', 422);
        }
        try {
            apiOk($svc->createFromBatch(
                $companyId,
                $userId,
                $outletId,
                (array) ($body['lines'] ?? []),
                preg_match($uuidRe, $locationId) ? $locationId : null,
                $allowed,
            ), 201);
        } catch (\Throwable $e) {
            $fail($e);
        }
    }

    $id = trim((string) ($body['id'] ?? ''));
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 400);
    }

    $qty = null;
    if (isset($body['qty']) && $body['qty'] !== '' && $body['qty'] !== null) {
        if (!is_numeric($body['qty'])) {
            apiError('Cantidad inválida', 422);
        }
        $qty = (float) $body['qty'];
    }

    try {
        if ($action === 'produce') {
            if (!hasPermission('production.manage')) {
                apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
            }
            apiOk($svc->produce($companyId, $userId, $id, $qty, $allowed), 201);
        }

        if ($action === 'transfer') {
            if (!hasPermission('inventory.transfer')) {
                apiError('No tenés permiso para esta acción (requiere: inventory.transfer)', 403);
            }
            $from = (string) ($body['fromOutletId'] ?? '');
            if (!preg_match($uuidRe, $from)) {
                apiError('Elegí de dónde sale la mercadería', 422);
            }
            // El ORIGEN también tiene que estar en el alcance del usuario:
            // sacar mercadería de una sucursal que no ve sería operar fuera de
            // su alcance por la puerta de atrás.
            if ($allowed !== null && !in_array($from, $allowed, true)) {
                apiError('No tenés acceso a esa sucursal', 403);
            }
            $fromLoc = (string) ($body['fromLocationId'] ?? '');
            $toLoc   = (string) ($body['toLocationId'] ?? '');
            apiOk($svc->transfer(
                $companyId,
                $userId,
                $id,
                $from,
                preg_match($uuidRe, $fromLoc) ? $fromLoc : null,
                preg_match($uuidRe, $toLoc) ? $toLoc : null,
                $qty,
                $allowed,
            ), 201);
        }

        if ($action === 'close') {
            if (!hasPermission('production.manage') && !hasPermission('inventory.transfer')) {
                apiError('No tenés permiso para esta acción', 403);
            }
            apiOk($svc->close($companyId, $userId, $id, (string) ($body['reason'] ?? ''), $allowed));
        }
    } catch (\Throwable $e) {
        $fail($e);
    }

    apiError('action desconocida (produce|transfer|close)', 400);
}

apiError('Method not allowed', 405);
