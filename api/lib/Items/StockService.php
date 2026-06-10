<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * StockService — gestiona stock + stockTrigger asociados a un item.
 *
 * Cuando un item deja de trackear inventario, eliminamos sus filas en
 * `stock` y `stockTrigger` para no dejar datos huérfanos. Cuando sí
 * trackea, aplicamos los triggers (umbrales de reabastecimiento por outlet)
 * que vienen del form.
 *
 * Port FIEL de panel/lib/items/StockService.php (Fase 2 del desacople de /panel).
 * Cambios: namespace, `final`, `declare(strict_types=1)`. El `stockTriggerManager()`
 * global del panel queda INLINEADO acá: era 4 líneas de DELETE+INSERT, no vale la
 * pena portar otra función global a /api solo por esto. Comportamiento idéntico.
 */
final class StockService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Elimina stock + stockTrigger de un item (usado cuando trackInventory = 0).
     */
    public function clear(string $itemId, string $companyId): void
    {
        $this->db->Execute('DELETE FROM stock WHERE itemId = ? AND companyId = ?', [$itemId, $companyId]);
        $this->db->Execute('DELETE FROM stockTrigger WHERE itemId = ?', [$itemId]);
    }

    /**
     * Aplica triggers de reabastecimiento. Cada trigger es par (qty, locationId).
     *
     * @param string $itemId
     * @param array  $triggers   Cantidades indexadas por posición
     * @param array  $locations  IDs de outlet encriptados, indexados por posición
     */
    public function applyTriggers(string $itemId, array $triggers, array $locations): void
    {
        foreach ($triggers as $key => $value) {
            if (!isset($locations[$key])) continue;
            $outletId = dec($locations[$key]);

            // Inline de stockTriggerManager() (panel/includes/functions.php:8330):
            // DELETE existente + INSERT si count > 0. Misma semántica byte-a-byte.
            $this->db->Execute(
                'DELETE FROM stockTrigger WHERE itemId = ? AND outletId = ?',
                [$itemId, $outletId]
            );
            if ($value > 0) {
                $this->db->AutoExecute('stockTrigger', [
                    'itemId'            => $itemId,
                    'stockTriggerCount' => $value,
                    'outletId'          => $outletId,
                ], 'INSERT');
            }
        }
    }
}
