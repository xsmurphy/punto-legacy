<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * ItemOutletService — dueño del vínculo ítem ↔ sucursales (`item_outlet`, mig 170).
 *
 * Regla del owner (textual): "Un ítem NUNCA puede no tener una sucursal
 * asignada, eso es regla básica. Un producto tiene que estar en algún lugar de
 * la empresa, de lo contrario no hay trazabilidad y no tiene sentido que un
 * producto aparezca de la nada. Cuando ingreso un producto por defecto tiene
 * una sucursal asignada; si hay solo una, esa es la sucursal por defecto."
 *
 * De ahí las dos responsabilidades de esta clase:
 *
 *  1. **Mínimo UNA sucursal, siempre.** `replace()` rechaza la lista vacía. Es
 *     el único camino de escritura a `item_outlet` — cualquier write path nuevo
 *     (importador, variantes, bulk-edit) pasa por acá y hereda el invariante,
 *     en vez de repetir la validación y olvidársela en el cuarto call-site.
 *     El invariante NO es un constraint de base: ver mig 170 §4 (lo bloquearía
 *     el hard-delete de sucursales de `OutletsService`).
 *
 *  2. **El default del alta.** `defaultFor()` resuelve la sucursal
 *     preseleccionada, para que un ítem nunca nazca sin sucursal.
 *
 * OJO — diferencia con `contact_outlet` (mig 66, usuarios): allá CERO filas
 * significa "todas las sucursales". Acá cero filas es un estado INVÁLIDO. Es la
 * misma tabla de vínculo con semántica opuesta; no trasladar código ni lecturas
 * de `UsersService::resolveOutletIds()` sin ajustar esto.
 */
final class ItemOutletService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Sucursales actuales de un ítem.
     *
     * @return string[] outletIds (puede venir vacío solo para ítems anteriores
     *                  a la mig 170 en un tenant sin sucursales, o si el hard
     *                  delete de una sucursal se llevó la última — ver §4 de la
     *                  migración).
     */
    public function listFor(string $itemId, string $companyId): array
    {
        return array_column($this->listWithNamesFor($itemId, $companyId), 'outletId');
    }

    /**
     * Igual que `listFor()` pero con el nombre de cada sucursal — mismo shape
     * que el campo `outlets` que arma `presentItem()` desde el SELECT
     * compartido, para que la ficha (que usa `find()`, un `SELECT *` crudo) y
     * el listado devuelvan exactamente la misma forma.
     *
     * @return list<array{outletId: string, outletName: string}>
     */
    public function listWithNamesFor(string $itemId, string $companyId): array
    {
        $rs = $this->db->Execute(
            'SELECT io.outletid, o.outletName
               FROM item_outlet io
               JOIN outlet o ON o.outletId = io.outletid
              WHERE io.itemid = ? AND io.companyid = ?
              ORDER BY o.outletName ASC',
            [$itemId, $companyId]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $id = $row['outletid'] ?? $row['outletId'] ?? null;
            if (!$id) continue;
            $out[] = [
                'outletId'   => (string) $id,
                'outletName' => (string) ($row['outletname'] ?? $row['outletName'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Lee las sucursales del payload de entrada.
     *
     * Devuelve `null` cuando el payload NO menciona sucursales — un PATCH
     * parcial (renombrar un ítem, cambiarle el precio) no debe tocar el vínculo.
     * "No mencionado" y "lista vacía" son cosas distintas: la segunda es un
     * intento explícito de dejar el ítem sin sucursal, y es 422.
     *
     * Acepta `outletId` (escalar, legacy) solo por compatibilidad con clientes
     * viejos que todavía no mandan `outletIds`, y SOLO con un valor concreto.
     *
     * Un `outletId` nulo/vacío/'all' significaba "todas las sucursales" en el
     * modelo viejo, y acá es 422 a propósito: traducirlo a "todas" resucitaría
     * el comodín que este modelo eliminó, y lo haría en el peor momento — un
     * bundle viejo cacheado (o cualquier cliente que siga mandando
     * `outletId: null`, que es exactamente lo que el front mandaba hasta este
     * cambio) ensancharía la visibilidad del ítem A TODAS las sucursales en
     * silencio, con solo apretar Guardar. Mejor un error visible que una fuga
     * de catálogo entre sucursales.
     *
     * @return string[]|null Lista validada y no vacía, o null si no aplica.
     * @throws \InvalidArgumentException lista vacía, o sucursal de otro tenant.
     */
    public function resolveFromPayload(array $in, string $companyId): ?array
    {
        if (array_key_exists('outletIds', $in)) {
            $raw = is_array($in['outletIds']) ? $in['outletIds'] : [];
        } elseif (array_key_exists('outletId', $in)) {
            $legacy = $in['outletId'];
            if ($legacy === null || $legacy === '' || $legacy === 'all') {
                throw new \InvalidArgumentException(
                    '"Todas las sucursales" ya no existe: elegí al menos una sucursal para el artículo.'
                );
            }
            $raw = [$legacy];
        } else {
            return null; // el payload no habla de sucursales — no tocar el vínculo
        }

        $ids = [];
        foreach ($raw as $v) {
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '' && !in_array($v, $ids, true)) $ids[] = $v;
        }

        if ($ids === []) {
            throw new \InvalidArgumentException(
                'El artículo tiene que estar en al menos una sucursal.'
            );
        }

        $this->assertBelongToTenant($ids, $companyId);
        return $ids;
    }

    /**
     * Reemplaza el set completo de sucursales de un ítem.
     *
     * DELETE + INSERT (no upsert selectivo) por la misma razón que
     * `UsersService::syncContactOutlets()`: el cliente manda el set entero, no
     * un diff. El trigger `trg_item_outlet_touch_item` (mig 170) bumpea
     * `item.updated_at` en cada fila tocada, así que el POS se entera — incluso
     * de las sucursales REMOVIDAS, que es el caso que importa (ver
     * `SyncService::itemsNoLongerVisibleTo()`).
     *
     * @param string[] $outletIds
     * @throws \InvalidArgumentException lista vacía o sucursal ajena al tenant.
     * @throws \RuntimeException si el DELETE+INSERT no pudo completarse (el
     *         ítem habría quedado con cero sucursales); la transacción revierte.
     */
    public function replace(string $itemId, string $companyId, array $outletIds): void
    {
        $ids = [];
        foreach ($outletIds as $v) {
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '' && !in_array($v, $ids, true)) $ids[] = $v;
        }

        if ($ids === []) {
            throw new \InvalidArgumentException(
                'El artículo tiene que estar en al menos una sucursal.'
            );
        }
        $this->assertBelongToTenant($ids, $companyId);

        // ATÓMICO — el DELETE + INSERT es la ventana donde el ítem existe con
        // CERO sucursales. Como el invariante NO es un constraint de base (ver
        // §4 de la mig 170: lo haría abortar el borrado de una sucursal), ESTA
        // ventana *es* el invariante, y no puede quedar sin guarda.
        //
        // `$db->Execute()` devuelve `false` ante error, NO lanza: `DB::Execute`
        // atrapa la PDOException y la convierte en `false` (api/includes/lib/
        // DB.php). Sin chequear el retorno, un INSERT fallido dejaba el ítem en
        // cero filas, `replace()` retornaba void, `update()` retornaba true y el
        // endpoint contestaba 200 — el ítem desaparecía de todas las cajas y
        // nadie se enteraba.
        //
        // `StartTrans()` es anidable (lleva un contador de profundidad), así que
        // esto funciona igual si el caller ya abrió una transacción: ahí el
        // `CompleteTrans()` de acá solo decrementa y el rollback lo hace el
        // nivel externo.
        $this->db->StartTrans();

        $deleted = $this->db->Execute(
            'DELETE FROM item_outlet WHERE itemid = ? AND companyid = ?',
            [$itemId, $companyId]
        );
        if ($deleted === false) {
            $this->db->FailTrans();
            $this->db->CompleteTrans();
            throw new \RuntimeException(
                "No se pudieron actualizar las sucursales del artículo {$itemId} (falló el borrado previo)."
            );
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '(?, ?, ?)'));
        $params = [];
        foreach ($ids as $oid) {
            $params[] = $itemId;
            $params[] = $oid;
            $params[] = $companyId;
        }
        $inserted = $this->db->Execute(
            "INSERT INTO item_outlet (itemid, outletid, companyid)
             VALUES {$placeholders} ON CONFLICT DO NOTHING",
            $params
        );
        if ($inserted === false) {
            $this->db->FailTrans();
            $this->db->CompleteTrans();
            throw new \RuntimeException(
                "No se pudieron asignar las sucursales del artículo {$itemId}."
            );
        }

        if (!$this->db->CompleteTrans()) {
            throw new \RuntimeException(
                "No se pudieron actualizar las sucursales del artículo {$itemId} (la transacción no confirmó)."
            );
        }
    }

    /**
     * Sucursal(es) por defecto para un ítem nuevo.
     *
     * "Si hay solo una, esa es la sucursal por defecto" (owner). Con varias, se
     * usa la del contexto (view-scope del panel, o la sucursal del device) y si
     * no hay contexto, la primera por nombre — determinista, para que dos altas
     * seguidas no caigan en sucursales distintas.
     *
     * Devuelve `[]` solo si el tenant no tiene NINGUNA sucursal; el caller
     * decide si eso es un 422 o un ítem sin vínculo (ver `ItemService::createBlank()`).
     *
     * @return string[]
     */
    public function defaultFor(string $companyId, ?string $preferredOutletId = null): array
    {
        $all = $this->allOutletsOf($companyId);
        if ($all === []) return [];
        if (count($all) === 1) return $all;

        if ($preferredOutletId !== null && $preferredOutletId !== ''
            && in_array($preferredOutletId, $all, true)) {
            return [$preferredOutletId];
        }
        return [$all[0]];
    }

    /**
     * Todas las sucursales del tenant, ordenadas por nombre (determinista).
     *
     * Incluye las inactivas a propósito, mismo criterio que el backfill (b) de
     * la mig 170: el modelo viejo de `outletId IS NULL` = "todas" no miraba
     * `outletStatus`, y filtrar acá cambiaría en silencio la visibilidad de un
     * ítem al reactivarse una sucursal.
     *
     * @return string[]
     */
    public function allOutletIdsOf(string $companyId): array
    {
        return $this->allOutletsOf($companyId);
    }

    private function allOutletsOf(string $companyId): array
    {
        $rs = $this->db->Execute(
            'SELECT outletId FROM outlet WHERE companyId = ? ORDER BY outletName ASC',
            [$companyId]
        );
        if ($rs === false) return [];
        $ids = [];
        foreach ($rs->GetRows() as $row) {
            $id = $row['outletid'] ?? $row['outletId'] ?? null;
            if ($id) $ids[] = (string) $id;
        }
        return $ids;
    }

    /**
     * Aislamiento multi-tenant: una sucursal de OTRA empresa en el body es 422,
     * nunca un INSERT silencioso. Mismo criterio que
     * `UsersService::resolveOutletIds()`.
     *
     * @param string[] $ids
     * @throws \InvalidArgumentException
     */
    private function assertBelongToTenant(array $ids, string $companyId): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rs = $this->db->Execute(
            "SELECT outletId FROM outlet WHERE outletId IN ({$placeholders}) AND companyId = ?",
            array_merge($ids, [$companyId])
        );
        $found = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $row) {
                $id = $row['outletid'] ?? $row['outletId'] ?? null;
                if ($id) $found[] = (string) $id;
            }
        }
        foreach ($ids as $oid) {
            if (!in_array($oid, $found, true)) {
                throw new \InvalidArgumentException("La sucursal '{$oid}' no pertenece a esta empresa");
            }
        }
    }
}
