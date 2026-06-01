<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)
/**
 * SyncService — checks de sincronización offline del POS (Slice 8).
 *
 * Lógica portada de app/action.php:
 *   chkDeletedItems     (L166) — de un set de itemIds locales, cuáles ya no están activos
 *   chkDeletedCustomers (L196) — idem para contactos (clientes)
 *
 * El front (offline-first) guarda items/clientes localmente y periódicamente pregunta
 * "¿cuáles de estos IDs ya fueron borrados?" para purgarlos de su cache local.
 *
 * MEJORA vs legacy: el legacy traía TODOS los IDs activos (LIMIT 50000/10000) y los
 * diffeaba en PHP. Acá se hace un `IN (...)` parametrizado sólo con los IDs del cliente
 * → semánticamente idéntico (un ID está "borrado" si no está en el set activo) pero sin
 * traer decenas de miles de filas. Identificadores SIN comillas (ver §22.5).
 */

final class SyncService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * Dado un set de itemIds del cliente, retorna cuáles ya NO están activos (borrados).
     * Excluye falsy e 'intCred' (igual que el legacy).
     *
     * @param string[] $clientIds
     * @return string[] IDs borrados (subconjunto de $clientIds)
     */
    public function deletedItems(string $companyId, array $clientIds): array
    {
        $candidates = array_values(array_filter(
            $clientIds,
            static fn($id) => $id !== null && $id !== '' && $id !== 'intCred'
        ));
        if (empty($candidates)) {
            return [];
        }

        // itemCanSale es BOOLEAN en PG → comparar con true, no con 1 (ver §22.3).
        $active = $this->activeSubset(
            'item',
            'itemId',
            'itemStatus = 1 AND itemCanSale = true',
            $companyId,
            $candidates
        );

        return array_values(array_diff($candidates, $active));
    }

    /**
     * Dado un set de contactIds del cliente, retorna cuáles ya NO están activos (borrados).
     * Excluye sólo falsy (el legacy no filtra 'intCred' acá).
     *
     * @param string[] $clientIds
     * @return string[] IDs borrados (subconjunto de $clientIds)
     */
    public function deletedCustomers(string $companyId, array $clientIds): array
    {
        $candidates = array_values(array_filter(
            $clientIds,
            static fn($id) => $id !== null && $id !== ''
        ));
        if (empty($candidates)) {
            return [];
        }

        $active = $this->activeSubset(
            'contact',
            'contactId',
            'contactStatus > 0 AND type = 1',
            $companyId,
            $candidates
        );

        return array_values(array_diff($candidates, $active));
    }

    /**
     * Retorna el subconjunto de $ids que SIGUE activo en $table según $extraWhere.
     * $table/$idCol/$extraWhere son literales internos (no input) → seguro interpolarlos.
     * Los $ids van bindeados vía placeholders.
     *
     * @param string[] $ids
     * @return string[]
     */
    private function activeSubset(
        string $table,
        string $idCol,
        string $extraWhere,
        string $companyId,
        array $ids
    ): array {
        global $db;

        $col    = strtolower($idCol);
        $active = [];

        // Chunk para no exceder el límite de bind params de PG (65535). Una cache
        // offline grande podría enviar miles de IDs. 20000/lote deja margen holgado.
        foreach (array_chunk(array_values($ids), 20000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $params       = array_merge([$companyId], $chunk);

            $res = $db->Execute(
                "SELECT $idCol FROM $table
                  WHERE companyId = ? AND $extraWhere AND $idCol IN ($placeholders)",
                $params
            );

            if ($res !== false) {
                while (!$res->EOF) {
                    $active[] = $res->fields[$col] ?? reset($res->fields);
                    $res->MoveNext();
                }
            }
        }

        return $active;
    }
}
