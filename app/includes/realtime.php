<?php
/**
 * Realtime publish — wrapper sobre wsPublish() para invalidaciones de cache
 * tenant-wide. El cliente (panel-next) escucha el canal `<companyId>:invalidate`
 * y mapea el `entity` a queryKeys de TanStack Query.
 *
 * Best-effort: si COMPANY_ID no está definido (jobs CLI, scripts internos),
 * se silencia. Si Redis no responde, wsPublish() ya absorbe el error.
 */

require_once __DIR__ . '/ws_publish.php';

/**
 * Publica un evento de invalidación para todos los browsers del tenant actual.
 *
 * @param string      $entity Tipo de recurso ("item", "contact", "transaction", etc.)
 * @param string      $op     "create" | "update" | "delete"
 * @param string|null $id     UUID del recurso afectado (null si no aplica)
 * @param string      $scope  "all" | "dashboard" — el cliente decide si reacciona
 */
function realtimePublish(string $entity, string $op, ?string $id = null, string $scope = 'all'): void
{
    if (!defined('COMPANY_ID') || !COMPANY_ID) return;

    $channel = COMPANY_ID . ':invalidate';
    wsPublish($channel, 'invalidate', [
        'entity' => $entity,
        'op'     => $op,
        'id'     => $id,
        'scope'  => $scope,
    ]);
}
