<?php
/**
 * REST canónico — Medios de pago del tenant.
 *
 *   GET    /v1/payment-methods            → lista del tenant (auto-seed si vacío)
 *   GET    /v1/payment-methods?id=<uuid>  → detalle
 *   POST   /v1/payment-methods            → crea  (body: { name, code?, hasChange?,
 *                                            requiresIdentifier?, identifierLabel?,
 *                                            identifierPlaceholder?, accountId? })
 *   PUT    /v1/payment-methods?id=<uuid>       → actualiza (partial)
 *   PUT    /v1/payment-methods?resource=reorder → reordena (body: { orderedIds: [...] })
 *   DELETE /v1/payment-methods?id=<uuid>       → elimina (Efectivo no borrable)
 *
 * Auth: lectura panel + pos-app; escritura solo panel. Identidad estable: el
 * taxonomyId (UUID) es la clave del método que las ventas nuevas guardan y que
 * finAccountMap referencia. El BFF del POS (`/api/pos/bootstrap`) consume este
 * endpoint para armar paymentMethods.
 *
 * Por qué el GET acepta `pos-app`: el bootstrap del POS viaja con el Bearer del
 * device (realm pos-app) y este era el ÚNICO de sus 6 upstreams restringido a
 * panel. Resultado en producción: 401 solo acá, el BFF degradaba a los métodos
 * hardcodeados de fallback y la caja operaba con ids falsos ("efectivo",
 * "tcredito") en vez de los UUID reales del tenant — cualquier flujo que
 * resuelva el medio de pago server-side (cobro de espacios, control de caja)
 * fallaba después con un error sin relación aparente. La lectura de la lista de
 * medios de pago no es privilegiada: la caja necesita exactamente los mismos
 * datos para poder cobrar.
 */

require_once __DIR__ . '/../bootstrap.php';

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Alta/edición/borrado/reorden siguen siendo configuración del tenant: panel.
$ctx       = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app'] : ['panel']);
$companyId = $ctx['companyId'];
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\PaymentMethods\PaymentMethodService($db);

// bootstrap.php ya normaliza el body JSON/form-encoded → $_POST para todos los
// verbos, igual que categories.php.

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $pm = $svc->find($companyId, (string) $id);
            if ($pm === null) apiError('Medio de pago no encontrado', 404);
            apiOk($pm);
        }
        apiOk(['paymentMethods' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $pm    = $svc->find($companyId, $newId);
            apiOk($pm, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        // Reorder: ?resource=reorder con body { orderedIds: [...] }. Scopeado a
        // companyId dentro del service; nunca confía un companyId del cliente.
        if (($_GET['resource'] ?? '') === 'reorder') {
            $orderedIds = $_POST['orderedIds'] ?? [];
            if (!is_array($orderedIds)) apiError('orderedIds debe ser un array', 422);
            try {
                $svc->reorder($companyId, $orderedIds);
                apiOk(['paymentMethods' => $svc->list($companyId)]);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $pm = $svc->find($companyId, (string) $id);
            apiOk($pm);
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
