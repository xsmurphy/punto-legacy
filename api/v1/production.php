<?php
/**
 * REST — Órdenes de producción (F1, context/23-production-module-plan.md).
 *
 *   GET  /v1/production                              → lista (filtros: status, outletId, from, to, q)
 *   GET  /v1/production?id=<uuid>                     → detalle
 *   GET  /v1/production?resource=capacity&itemId=<uuid>&outletId=<uuid> → capacidad de producción
 *   GET  /v1/production?resource=producible&itemId=<uuid> → "producibles ahora" POR SUCURSAL
 *                                                        (ficha del artículo; la sucursal NO va en
 *                                                         la query: sale del view-scope, o sea del
 *                                                         header X-Outlet-Id que ya manda el panel)
 *   POST /v1/production                               → crea (body: itemId, outletId, qtyPlanned,
 *                                                        locationId?, outputLocationId?, mode?
 *                                                        'draft'|'immediate', note?; si mode=immediate
 *                                                        también qtyProduced, wasteUnits?, wasteReasonId?,
 *                                                        ingredientAdjustments?)
 *   POST /v1/production?id=<uuid>&action=start        → draft → in_progress
 *   POST /v1/production?id=<uuid>&action=complete      → completa (body: qtyProduced, wasteUnits?,
 *                                                        wasteReasonId?, ingredientAdjustments?)
 *   POST /v1/production?id=<uuid>&action=cancel        → cancela (solo draft/in_progress)
 *
 * Auth: `panel` para todo; `pos-app` (Bearer del dispositivo) ÚNICAMENTE para
 * `resource=producible` — ver el guard debajo. Escritura (POST) gateada por
 * production.manage, que el rol seed `device` no tiene.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action'] ?? null;

// ── Guard de realm: qué alcanza el token de una CAJA ───────────────────────
// El device entra a este archivo por UNA sola puerta: "producibles ahora" de la
// ficha del producto en `/pos` (2026-09-07). Sumar `pos-app` arriba sin este
// corte le abriría además el listado y el detalle de órdenes de producción —
// que son gestión del comercio, no algo que se opere desde el mostrador. Es el
// mismo criterio con el que `items.php` recorta al device a GET + bulk-get: la
// superficie se declara acá, y una rama nueva nace cerrada en vez de heredar
// acceso por el solo hecho de estar en el archivo.
//
// El POST ya estaba cubierto por `production.manage` (el rol seed `device` no
// lo tiene), pero un 403 por permiso es una defensa de segunda línea: lo que
// corresponde es que el realm ni siquiera llegue ahí.
$isDevice = ($ctx['realm'] ?? '') === 'pos-app';
if ($isDevice && !($method === 'GET' && $resource === 'producible')) {
    apiError('No disponible para el POS', 404);
}

global $db;
$svc = new \Punto\Api\Production\ProductionService($db);

switch ($method) {
    case 'GET':
        // "Producibles ahora" de la ficha del artículo. Lectura pura, derivada
        // del catálogo y del ledger: no crea ni modifica nada.
        //
        // Gate `inventory.item.view` y NO `production.manage`, a propósito:
        // quien puede abrir la ficha de un artículo ya ve su receta y el stock
        // de cada insumo por sucursal (tab Stock). Exigir el permiso de
        // ADMINISTRAR producción para VER un número que se deduce de datos que
        // el mismo usuario ya tiene delante no protege nada — solo le esconde
        // la conclusión. El permiso de escritura sigue gateando el POST.
        if ($resource === 'producible') {
            if (!hasPermission('inventory.item.view')) {
                apiError('No tenés permiso para esta acción (requiere: inventory.item.view)', 403);
            }
            $itemId = (string) ($_GET['itemId'] ?? '');
            if ($itemId === '') {
                apiError('itemId es requerido', 422);
            }
            // Alcance por sucursal: `effectiveIds()` devuelve [la sucursal
            // elegida en el selector del panel] o el conjunto asignado al
            // usuario, y `[]` cuando ese conjunto es global (cero filas en
            // `contact_outlet` = todas — context/25). No se lee `?outletId=`:
            // en realm panel la sucursal viaja por `X-Outlet-Id` y bootstrap.php
            // ya la validó contra el conjunto (403 si no pertenece). Aceptar
            // además un parámetro sería una segunda puerta sin ese chequeo.
            //
            // Realm `pos-app`: el alcance es la sucursal del DISPOSITIVO, y sale
            // de la fila `device` que resolvió `apiAuthTenant()` — nunca de la
            // query. Se escribe explícito y no se delega en `effectiveIds()`
            // (que hoy caería en `OUTLET_ID` y daría lo mismo) porque el POS no
            // puede quedar atado a un default: si mañana esa resolución cambia,
            // una caja empezaría a ver los producibles de otra sucursal sin que
            // nada falle. Y si la dimensión falta, se corta — el POS no inventa
            // la sucursal que le falta (misma regla que el bootstrap).
            if ($isDevice) {
                $deviceOutletId = (string) ($ctx['outletId'] ?? '');
                if ($deviceOutletId === '') {
                    apiError('El dispositivo no tiene sucursal asignada', 409);
                }
                $scopeIds = [$deviceOutletId];
            } else {
                $scopeIds = \Punto\Api\Outlets\OutletScope::effectiveIds();
            }
            try {
                $out = $svc->producible($companyId, $itemId, $scopeIds);
                // Realm `pos-app`: viaja SOLO el número. `limiting` e
                // `ingredients` son la RECETA (qué insumo, cuánto lleva por
                // unidad) y la receta nunca se expone en el POS — decisión del
                // owner 2026-09-07, es secreto comercial del negocio. El corte
                // va ACÁ, en el server, y no escondiendo el bloque en la UI:
                // esconderlo en la pantalla y mandarlo igual en el payload es
                // exactamente el bug del control de caja a ciegas pre-mig 169
                // (regla 7 de context/modules/14-caja.md) — se caía con las
                // devtools abiertas. El panel (realm `panel`) sigue recibiendo
                // el desglose completo: ahí la receta ya es visible por
                // permiso de catálogo.
                if ($isDevice) {
                    foreach ($out['outlets'] as &$o) {
                        unset($o['limiting'], $o['ingredients']);
                    }
                    unset($o);
                }
                apiOk($out);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }
        if ($resource === 'capacity') {
            $itemId   = (string) ($_GET['itemId'] ?? '');
            $outletId = (string) ($_GET['outletId'] ?? ($ctx['outletId'] ?? ''));
            if ($itemId === '' || $outletId === '') {
                apiError('itemId y outletId son requeridos', 422);
            }
            try {
                apiOk($svc->capacity($companyId, $itemId, $outletId));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }
        if ($id !== null) {
            $order = $svc->find($companyId, (string) $id);
            if ($order === null) apiError('Orden de producción no encontrada', 404);
            apiOk($order);
            break;
        }
        $filters = [
            'status'   => $_GET['status'] ?? null,
            'outletId' => $_GET['outletId'] ?? null,
            'from'     => $_GET['from'] ?? null,
            'to'       => $_GET['to'] ?? null,
            'q'        => $_GET['q'] ?? null,
        ];
        apiOk(['orders' => $svc->list($companyId, array_filter($filters, static fn ($v) => $v !== null && $v !== ''))]);
        break;

    case 'POST':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }

        if ($id !== null && $action === 'start') {
            try {
                $svc->start($companyId, (string) $id);
                apiOk($svc->find($companyId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'complete') {
            try {
                apiOk($svc->complete($companyId, $userId, (string) $id, $_POST));
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
            apiError('action inválida (esperado: start|complete|cancel)', 422);
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
