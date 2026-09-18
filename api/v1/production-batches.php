<?php
/**
 * REST — Lotes de producción multi-plato (context/70-viandas.md, etapa B / F2).
 *
 *   GET  /v1/production-batches                       → lista (filtros: status, outletId, from, to)
 *   GET  /v1/production-batches?id=<uuid>             → detalle (lote + líneas + necesidad si está en draft)
 *   GET  /v1/production-batches?resource=order-demand&outletId=<uuid>[&dateFrom=&dateTo=]
 *                                                     → la cola de órdenes pendientes de esa sucursal,
 *                                                       agregada por producto (alimentador del lote).
 *                                                       `dateFrom`/`dateTo` = rango de días de entrega
 *                                                       (context/79); sin ellos, hoy. `date` = un solo día.
 *   POST /v1/production-batches?resource=estimate     → necesidad consolidada (LECTURA PURA, no escribe)
 *                                                       body: outletId, locationId?, lines:[{itemId, qty}]
 *   POST /v1/production-batches                       → crea el lote en draft + sus N órdenes hijas
 *                                                       body: outletId, locationId?, outputLocationId?,
 *                                                             note?, lines:[{itemId, qty}]
 *   POST /v1/production-batches?id=<uuid>&action=confirm → completa TODAS las líneas (atómico)
 *   POST /v1/production-batches?id=<uuid>&action=cancel  → cancela el lote y sus líneas (solo draft)
 *
 * Auth: panel. Escritura gateada por `production.manage` — la MISMA clave que
 * ya gobierna `production.php`, `waste.php` y `waste-reasons.php`. No se crea
 * una clave nueva: el lote no es una capacidad distinta de "producir", es la
 * misma operación para varios platos a la vez, y una clave más obligaría a
 * cada comercio a re-tildar un permiso para algo que su encargado de
 * producción ya podía hacer de a uno. Sin clave nueva no hace falta migración
 * de backfill.
 *
 * POR QUÉ `estimate` ES POST SIENDO UNA LECTURA
 * ----------------------------------------------
 * Su entrada es una lista de N pares {plato, cantidad}. Serializarla en la
 * query string la vuelve frágil (largo de URL, orden, escaping) y la deja
 * cacheada en logs y en el historial del browser. El verbo es POST por el
 * BODY, no por el efecto: el servicio no escribe una fila. Como POST, cae
 * bajo la misma gate `production.manage` que el resto del módulo, lo cual es
 * deseable: la necesidad consolidada expone saldos de stock del comercio.
 *
 * POR QUÉ `order-demand` SÍ ES GET
 * --------------------------------
 * El contraste con `estimate` es exacto: su entrada es UN outletId, entra en
 * la query string sin fragilidad y no hay nada sensible que esconder de un
 * log. Además se gatea con `production.manage` aunque sea un GET: leer la cola
 * entera agregada por producto es la puerta de entrada al lote, y la gate es
 * ESTRICTAMENTE más restrictiva que la de `/v1/orders.php` (que no pide
 * permiso alguno más allá del tenant), así que no abre ningún hueco.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action'] ?? null;

global $db;
$svc = new \Punto\Api\Production\ProductionBatchService($db);

/*
 * Alcance por sucursal (context/25): un usuario con sucursales asignadas en
 * `contact_outlet` opera SOLO esas — también acá. El endpoint no lo aplicaba
 * en ningún camino (hallazgo del review 2026-09-17): con `production.manage`
 * se podía leer la demanda, estimar, listar y confirmar lotes de cualquier
 * sucursal del tenant pasando su `outletId`.
 *
 * Se resuelve UNA vez y se aplica en el embudo de cada camino, no dentro de
 * cada método del servicio: el servicio también lo llama `ReplenishmentService`
 * (que ya aplica su propio alcance), y un segundo chequeo adentro sería una
 * segunda definición del mismo criterio.
 */
$scope = \Punto\Api\Outlets\OutletScope::forUser($companyId, $userId);

/** Corta con 403 si la sucursal pedida está fuera del alcance del usuario. */
$requireOutlet = static function (string $outletId) use ($scope, $companyId): void {
    if (!\Punto\Api\Outlets\OutletScope::allows($scope, $outletId, $companyId)) {
        apiError('No tenés acceso a esa sucursal', 403);
    }
};

/**
 * Trae un lote SOLO si es de una sucursal del alcance. Afuera responde 404 y
 * no 403, a propósito: un 403 confirmaría que ese id existe en otra sucursal.
 */
$findScoped = static function (string $batchId) use ($svc, $scope, $companyId): array {
    $batch = $svc->find($companyId, $batchId);
    if ($batch === null || !\Punto\Api\Outlets\OutletScope::allows($scope, (string) ($batch['outletId'] ?? ''), $companyId)) {
        apiError('Lote de producción no encontrado', 404);
    }
    return $batch;
};

switch ($method) {
    case 'GET':
        // Alimentador del lote: de la cola de órdenes a las líneas
        // {plato, cantidad} (context/70, etapa B). Es una FOTO del momento —
        // ver el docblock de OrderDemandService.
        if ($resource === 'order-demand') {
            if (!hasPermission('production.manage')) {
                apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
            }
            $outletId = (string) ($_GET['outletId'] ?? ($ctx['outletId'] ?? ''));
            if ($outletId === '') {
                apiError('outletId es requerido', 422);
            }
            $requireOutlet($outletId);
            // Rango de días de entrega a traer (context/79 D2, ampliado a rango
            // el 2026-09-17). Ausente = hoy, que es el comportamiento previo:
            // la cola sin fecha más lo vencido. `date` sigue aceptándose como
            // el rango de UN día que era, para no romper un cliente viejo.
            $demandDate = trim((string) ($_GET['date'] ?? ''));
            $demandFrom = trim((string) ($_GET['dateFrom'] ?? '')) ?: $demandDate;
            $demandTo   = trim((string) ($_GET['dateTo'] ?? '')) ?: $demandDate;
            try {
                apiOk((new \Punto\Api\Orders\OrderDemandService())->pendingByItem(
                    $companyId,
                    $outletId,
                    $demandFrom !== '' ? $demandFrom : null,
                    $demandTo !== '' ? $demandTo : null
                ));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiOk($findScoped((string) $id));
            break;
        }
        $filters = [
            'status'   => $_GET['status'] ?? null,
            'outletId' => $_GET['outletId'] ?? null,
            'from'     => $_GET['from'] ?? null,
            'to'       => $_GET['to'] ?? null,
        ];
        $filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
        // Pedir una sucursal fuera del alcance es un 403, no una lista vacía:
        // "no hay lotes" diría algo falso sobre esa sucursal.
        if (isset($filters['outletId'])) {
            $requireOutlet((string) $filters['outletId']);
        }
        $filters['allowedOutletIds'] = $scope;
        apiOk(['batches' => $svc->list($companyId, $filters)]);
        break;

    case 'POST':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }

        if ($resource === 'estimate') {
            $outletId   = (string) ($_POST['outletId'] ?? ($ctx['outletId'] ?? ''));
            $locationId = !empty($_POST['locationId']) ? (string) $_POST['locationId'] : null;
            if ($outletId === '') {
                apiError('outletId es requerido', 422);
            }
            $requireOutlet($outletId);
            try {
                apiOk($svc->estimate($companyId, $outletId, (array) ($_POST['lines'] ?? []), $locationId));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        // confirm/cancel operan sobre un lote existente: se valida que sea de
        // una sucursal del alcance ANTES de tocarlo.
        if ($id !== null) {
            $findScoped((string) $id);
        }

        if ($id !== null && $action === 'confirm') {
            try {
                apiOk($svc->confirm($companyId, $userId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'cancel') {
            try {
                $svc->cancel($companyId, (string) $id);
                apiOk($svc->find($companyId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiError('action inválida (esperado: confirm|cancel)', 422);
        }

        // Sin outletId lo rechaza el servicio con su 422 de siempre; con uno
        // fuera del alcance, 403 antes de crear nada.
        $createOutlet = (string) ($_POST['outletId'] ?? '');
        if ($createOutlet !== '') {
            $requireOutlet($createOutlet);
        }
        try {
            $newId = $svc->create($companyId, $userId, $_POST);
            apiOk($svc->find($companyId, $newId), 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
