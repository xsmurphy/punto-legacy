<?php
/**
 * REST canónico — Impuestos.
 *
 *   GET    /v1/taxes              → lista del tenant
 *   GET    /v1/taxes?id=<uuid>    → detalle
 *   POST   /v1/taxes              → crea  (body: { name, rate?, kind?, extra? })
 *   PUT    /v1/taxes?id=<uuid>    → actualiza (partial)
 *   PUT    /v1/taxes?resource=reorder → reordena (body: { orderedIds: [...] })
 *   DELETE /v1/taxes?id=<uuid>    → elimina
 *
 * rate/kind son opcionales: si no vienen se derivan de `name` (primer
 * número → kind='rate'; sin número → kind='exempt', rate=0). Ver
 * TaxService::resolveRateKind().
 *
 * Auth: GET panel + pos-app (F2b — el carrito del POS lee las tasas para el
 * IVA mostrado); POST/PUT/DELETE solo panel (admin del catálogo). Los
 * lectores legacy de `taxonomy` siguen vivos vía el trigger PG bidireccional
 * — getTaxValue() lee taxonomyName, que sigue sincronizado.
 *
 * F0 del plan de impuestos multi-país (context/38). Tabla `tax`
 * (migration 23 + 120).
 */

require_once __DIR__ . '/../bootstrap.php';

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// F2b (context/38): el carrito del POS lee las tasas del tenant para calcular
// el IVA mostrado (mismo criterio que TaxEngine server-side) — el device del
// POS solo tiene Bearer realm `pos-app`, sin cookie `_jwt_panel`. Mismo
// patrón que /v1/payment-methods: se abre SOLO el método de lectura; altas/
// bajas/ediciones de impuestos siguen siendo exclusivas del panel.
$ctx       = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app'] : ['panel']);
$companyId = $ctx['companyId'];
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Taxes\TaxService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $tax = $svc->find($companyId, (string) $id);
            if ($tax === null) apiError('Impuesto no encontrado', 404);
            apiOk($tax);
        }
        apiOk(['taxes' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $tax   = $svc->find($companyId, $newId);
            apiOk($tax, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        // Reorder: ?resource=reorder con body { orderedIds: [...] }. Scopeado a
        // companyId dentro del service; nunca confía un companyId del cliente.
        // Mismo patrón que /v1/payment-methods.
        if (($_GET['resource'] ?? '') === 'reorder') {
            $orderedIds = $_POST['orderedIds'] ?? [];
            if (!is_array($orderedIds)) apiError('orderedIds debe ser un array', 422);
            try {
                $svc->reorder($companyId, $orderedIds);
                apiOk(['taxes' => $svc->list($companyId)]);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $tax = $svc->find($companyId, (string) $id);
            apiOk($tax);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'DELETE':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->delete($companyId, (string) $id);
            apiOk(['deleted' => true]);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
