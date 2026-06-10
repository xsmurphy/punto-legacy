<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * UpsellService — gestiona la tabla `upsell` (recomendaciones N-a-N entre items).
 *
 * Un item "padre" puede tener N "hijos" sugeridos en el checkout. La relación
 * vive en `upsell(upsellParentId, upsellChildId, companyId)`.
 *
 * Port FIEL de panel/lib/items/UpsellService.php (Fase 2 del desacople de /panel).
 * Cambios: namespace, `final`, `declare(strict_types=1)`. `dec()` es función global
 * (identity post-UUID) — resuelve por fallback de PHP.
 */
final class UpsellService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Reemplaza la lista de upsells del item padre.
     *
     * @param string $parentId
     * @param string $companyId
     * @param array  $childIds  IDs encriptados de los items hijos
     */
    public function replaceFor(string $parentId, string $companyId, array $childIds): void
    {
        $this->db->Execute('DELETE FROM upsell WHERE upsellParentId = ?', [$parentId]);
        foreach ($childIds as $encId) {
            $this->db->AutoExecute('upsell', [
                'upsellParentId' => $parentId,
                'upsellChildId'  => dec($encId),
                'companyId'      => $companyId,
            ], 'INSERT');
        }
    }
}
