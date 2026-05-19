<?php

/**
 * StockService — gestiona stock + stockTrigger asociados a un item.
 *
 * Cuando un item deja de trackear inventario, eliminamos sus filas en
 * `stock` y `stockTrigger` para no dejar datos huérfanos. Cuando sí
 * trackea, aplicamos los triggers (umbrales de reabastecimiento por outlet)
 * que vienen del form.
 */
class StockService
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
            stockTriggerManager($itemId, $value, dec($locations[$key]));
        }
    }
}
