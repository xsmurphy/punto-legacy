<?php
/**
 * Realtime publish — wrapper sobre wsPublish() para invalidaciones de cache
 * tenant-wide. El cliente (frontend) escucha el canal `<companyId>:invalidate`
 * y mapea el `entity` a queryKeys de TanStack Query.
 *
 * Best-effort: si COMPANY_ID no está definido (jobs CLI, scripts internos),
 * se silencia. Si Redis no responde, wsPublish() ya absorbe el error.
 */

require_once __DIR__ . '/ws_publish.php';

/**
 * Publica un evento de invalidación para todos los browsers del tenant actual.
 *
 * @param string      $entity    Tipo de recurso ("item", "contact", "transaction", etc.)
 * @param string      $op        "create" | "update" | "delete"
 * @param string|null $id        UUID del recurso afectado (null si no aplica)
 * @param string      $scope     "all" | "dashboard" — el cliente decide si reacciona
 * @param string|null $companyId Override explícito del tenant. Default null → usa la
 *                                constante global COMPANY_ID (caso normal: request HTTP
 *                                autenticado). Callers que ya resolvieron su propio
 *                                companyId por otra vía (ej. `manageStock()`, que acepta
 *                                un `companyId` en `$ops` para jobs/CLI sin request HTTP)
 *                                DEBEN pasarlo acá explícito — la constante global puede
 *                                estar vacía o ser la de otro tenant en ese contexto.
 */
function realtimePublish(string $entity, string $op, ?string $id = null, string $scope = 'all', ?string $companyId = null): void
{
    $companyId = $companyId ?: (defined('COMPANY_ID') ? COMPANY_ID : null);
    if (!$companyId) return;

    $channel = $companyId . ':invalidate';
    wsPublish($channel, 'invalidate', [
        'entity' => $entity,
        'op'     => $op,
        'id'     => $id,
        'scope'  => $scope,
    ]);
}
