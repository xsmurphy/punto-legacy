<?php
declare(strict_types=1);

namespace Punto\Api\Items;

use CaseInsensitiveArray;

/**
 * ItemService — orquesta CRUD de item + aplica reglas de negocio (admin panel).
 *
 * Punto de entrada para api/v1/items.php (rama panel) y panel/a_items.php in-process
 * (handlers HTTP del legacy, hasta que F3 migre el front estático).
 * Delega persistencia en ItemRepository. NO genera output HTTP — solo retorna data.
 *
 * Coexiste con `Punto\Api\Services\ItemService` (slice 25 del desacople de /app: get item
 * info + inventory para el POS). Namespaces distintos los separan; ambos viven en /api/lib/.
 *
 * Port FIEL de panel/lib/items/ItemService.php (Fase 2 del desacople de /panel). Cambios:
 * namespace, `final`, `declare(strict_types=1)`, `use CaseInsensitiveArray`. Lógica idéntica.
 *
 * Nota namespace: ItemRepository vive en el mismo namespace — sin `use` necesario.
 * Funciones globales (TODAY) resuelven por fallback de PHP.
 */
final class ItemService
{
    private ItemRepository $repo;
    private ?ItemOutletService $outlets;

    public function __construct(ItemRepository $repo, ?ItemOutletService $outlets = null)
    {
        $this->repo    = $repo;
        $this->outlets = $outlets;
    }

    /**
     * `ItemOutletService` es OBLIGATORIO para sostener el invariante de
     * mínimo-una-sucursal (mig 170), pero se resuelve perezosamente contra el
     * `$db` global si el caller no lo inyectó — así los 5 call-sites que ya
     * construían este servicio con un solo argumento siguen andando Y quedan
     * cubiertos por el invariante, en vez de saltearlo por omisión.
     */
    private function outlets(): ItemOutletService
    {
        if ($this->outlets === null) {
            global $db;
            $this->outlets = new ItemOutletService($db);
        }
        return $this->outlets;
    }

    /**
     * Crear item "en blanco" con defaults por tipo (article/discount/combo/giftcard).
     *
     * Usado por a_items.php?action=insertBtn — crea un placeholder que el usuario
     * después edita en el modal.
     *
     * @param string $companyId
     * @param string|null $type article|discount|combo|giftcard
     * @return string|false itemId del nuevo registro o false si falla.
     */
    public function createBlank(
        string $companyId,
        ?string $type = null,
        ?string $kind = null,
        ?string $preferredOutletId = null
    ) {
        $defaults = self::blankDefaults($type);
        $record = [
            'itemName'      => $defaults['name'],
            'itemDate'      => TODAY,
            'companyId'     => $companyId,
            'updated_at'    => TODAY,
        ];
        if (!empty($defaults['itemType'])) {
            $record['itemType'] = $defaults['itemType'];
        }
        if (!empty($defaults['itemTrackInventory'])) {
            $record['itemTrackInventory'] = $defaults['itemTrackInventory'];
        }
        if (!empty($defaults['itemDescription'])) {
            $record['itemDescription'] = $defaults['itemDescription'];
        }
        // itemKind — columna NOT NULL tras migration 15. Se infiere del type si no se pasa.
        $kindFromType = [
            'discount'   => 'descuento',
            'combo'      => 'combo_fijo',
            'giftcard'   => 'giftcard',
            'production' => 'produccion_previa',
        ];
        $record['itemKind'] = $kind ?? $kindFromType[$type ?? ''] ?? 'producto';
        // itemTaxIncluded vive en data JSONB; ncmInsert lo enruta solo.
        $record['itemTaxIncluded'] = 1;

        $itemId = $this->repo->create($record);
        if (!$itemId) return $itemId;

        // "Cuando ingreso un producto por defecto tiene una sucursal asignada;
        // si hay solo una, esa es la sucursal por defecto" (owner). El ítem
        // nace CON sucursal — nunca en el estado inválido de cero, ni siquiera
        // durante el rato en que es un placeholder que el usuario todavía no
        // editó. Si el tenant no tiene ninguna sucursal, `defaultFor()` devuelve
        // [] y el ítem queda sin vínculo: no hay sucursal que asignarle, y
        // abortar el alta acá dejaría al tenant sin poder crear artículos.
        $defaults = $this->outlets()->defaultFor($companyId, $preferredOutletId);
        if ($defaults !== []) {
            $this->outlets()->replace((string) $itemId, $companyId, $defaults);
        }

        return $itemId;
    }

    /**
     * Defaults por tipo de item. Pública para que sea testeable.
     */
    public static function blankDefaults(?string $type): array
    {
        switch ($type) {
            case 'discount':
                return ['name' => 'Nuevo Descuento', 'itemType' => 'discount'];
            case 'combo':
                return ['name' => 'Nuevo Combo', 'itemType' => 'combo'];
            case 'giftcard':
                return [
                    'name'               => 'Gift Card',
                    'itemType'           => 'giftcard',
                    'itemTrackInventory' => 1,
                    'itemDescription'    => '1 year',
                ];
            default:
                return ['name' => 'Nuevo Artículo'];
        }
    }

    /**
     * Actualizar campos arbitrarios de un item (con sanitización mínima).
     */
    public function update(string $id, string $companyId, array $patch): bool
    {
        if (empty($patch)) return false;

        // Sucursales (`item_outlet`, mig 170): NO son una columna de `item`, así
        // que se sacan del patch ANTES de que llegue al writer genérico —
        // `ncmUpdate` intentaría un `SET outletIds = ...` contra una columna que
        // no existe. `resolveFromPayload()` devuelve null si el patch no habla
        // de sucursales (un PATCH de precio no debe tocar el vínculo) y tira
        // InvalidArgumentException si intenta dejarlo en cero — el caller la
        // traduce a 422.
        $outletIds = $this->outlets()->resolveFromPayload($patch, $companyId);
        unset($patch['outletIds'], $patch['outletId']);

        if ($outletIds === null) {
            // El patch no habla de sucursales: camino simple, sin transacción
            // propia (el UPDATE ya es una sola sentencia atómica).
            $patch['updated_at'] = TODAY;
            return $this->repo->update($id, $companyId, $patch);
        }

        // AISLAMIENTO MULTI-TENANT: `replace()` escribe en `item_outlet` con el
        // `$itemId` tal cual viene del caller. El UPDATE de columnas se protege
        // solo (su WHERE lleva companyId), pero un INSERT no tiene dónde
        // filtrar: sin este chequeo, un PUT con el id de un ítem de OTRA empresa
        // insertaría filas (itemid ajeno, outletid mío, companyid mío) — y el
        // ítem de la víctima terminaría mostrando una sucursal que no es suya.
        //
        // Devolver false (no una excepción) hace que el endpoint conteste
        // "Update falló" sin confirmar si el ítem existe: un id ajeno y uno
        // inexistente son indistinguibles desde afuera.
        if ($this->repo->find($id, $companyId) === null) {
            return false;
        }

        // Sucursales y columnas viajan en UNA transacción: si el UPDATE falla
        // después de haber reemplazado las sucursales, el endpoint contesta 500
        // pero el ítem ya habría cambiado de sucursal. O entran los dos cambios
        // o no entra ninguno.
        global $db;
        $db->StartTrans();
        try {
            $this->outlets()->replace($id, $companyId, $outletIds);

            // El patch puede quedar vacío si SOLO traía sucursales: eso es un
            // update exitoso, no un no-op fallido.
            $ok = true;
            if (!empty($patch)) {
                $patch['updated_at'] = TODAY;
                $ok = $this->repo->update($id, $companyId, $patch);
            }
            if (!$ok) {
                $db->FailTrans();
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        return $db->CompleteTrans() && $ok;
    }

    public function archive(string $id, string $companyId): bool
    {
        return $this->repo->archive($id, $companyId);
    }

    /**
     * Hard-delete de un item archivado.
     *
     * @return true                 → eliminado OK
     * @return 'sold'               → tiene ventas asociadas, no se puede borrar
     * @return 'referenced'         → referenciado por otros registros (stock/inventario/compuestos)
     * @return false                → error de DB o item no archivado / no encontrado
     */
    public function delete(string $id, string $companyId)
    {
        return $this->repo->hardDelete($id, $companyId);
    }

    public function find(string $id, string $companyId): array|CaseInsensitiveArray|null
    {
        return $this->repo->find($id, $companyId);
    }

    /**
     * Valida invariantes de variantes en el patch antes de persistir.
     * Lanza \RuntimeException con código HTTP si falla.
     * Retorna el patch mutado (con campos forzados según las reglas).
     *
     * @throws \RuntimeException
     */
    public function applyVariantRules(string $companyId, array $patch, ?string $existingItemId = null): array
    {
        $hasVariantParentId = array_key_exists('variantParentId', $patch);
        $hasVariants        = array_key_exists('hasVariants', $patch) ? (bool) $patch['hasVariants'] : null;

        if ($hasVariantParentId && !empty($patch['variantParentId'])) {
            $parentId = (string) $patch['variantParentId'];

            // Verificar que el padre pertenece al mismo tenant.
            $parentRow = ncmExecute(
                'SELECT companyid, hasvariants, variantparentid FROM item WHERE itemid = ? LIMIT 1',
                [$parentId]
            );
            if (!$parentRow) {
                throw new \RuntimeException('Item padre no encontrado', 404);
            }
            $parentCompany = (string) ($parentRow['companyid'] ?? '');
            if ($parentCompany !== $companyId) {
                throw new \RuntimeException('El item padre pertenece a otro tenant', 403);
            }

            // Padre debe tener hasVariants=true.
            $pHv = $parentRow['hasvariants'] ?? false;
            $parentHV = ($pHv === true || $pHv === 't' || $pHv === '1' || $pHv === 1);
            if (!$parentHV) {
                throw new \RuntimeException('El item padre no tiene hasVariants=true', 422);
            }

            // Padre no puede ser variante (anti-anidamiento).
            $pVP = $parentRow['variantparentid'] ?? null;
            if (!empty($pVP)) {
                throw new \RuntimeException('No se pueden anidar variantes', 422);
            }

            // Una variante no puede ser padre de otras variantes.
            $patch['hasVariants'] = false;
        }

        if ($hasVariants === true) {
            // El padre de variantes no trackea stock ni tiene precio/costo propios.
            $patch['variantParentId']    = null;
            $patch['itemTrackInventory'] = 0;
            $patch['itemCost']           = 0;
            $patch['itemPrice']          = 0;
        }

        // variantAttributes: validar que sea object plano {string: string} si llega.
        if (array_key_exists('variantAttributes', $patch) && $patch['variantAttributes'] !== null) {
            $attrs = $patch['variantAttributes'];
            if (is_string($attrs)) {
                $decoded = json_decode($attrs, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('variantAttributes debe ser un objeto JSON {string: string}', 422);
                }
                $attrs = $decoded;
            }
            if (!is_array($attrs)) {
                throw new \RuntimeException('variantAttributes debe ser un objeto {string: string}', 422);
            }
            // Re-encode para persistir como JSONB.
            $patch['variantAttributes'] = json_encode($attrs);
        }

        return $patch;
    }

    /**
     * Whitelist canónica de columnas que pueden venir en el patch de bulk-edit.
     * Cambios en este set son superficie pública del API — agregar con cuidado.
     */
    private const BULK_EDIT_WHITELIST = [
        'itemPrice', 'itemCost',
        // `outletIds` (array) reemplaza al `outletId` escalar: el bulk-edit
        // asigna el SET COMPLETO de sucursales, y `ItemService::update()` lo
        // desvía a `item_outlet` (mig 170). El escalar legacy ya NO se acepta
        // acá — dejarlo habilitaría un "todas las sucursales" (outletId = null)
        // que el modelo nuevo no tiene.
        'taxId', 'categoryId', 'brandId', 'outletIds',
        'itemDiscount', 'itemUOM', 'itemWaste',
        'itemComissionPercent', 'itemComissionType',
        'itemPricePercent', 'itemPriceType',
        'itemSessions', 'itemDuration',
        'itemEcom', 'itemFeatured',
        'itemKind', 'itemType', 'itemCanSale', 'itemTrackInventory', 'itemProduction',
    ];

    /**
     * Edición masiva: aplica `patch` a cada item de `itemIds`. Si `priceAdjustPercent`
     * llega no-nulo, recalcula `itemPrice` por item (precio actual × (1 + p/100)).
     * Si un item es grupo (itemIsParent=true), propaga a los hijos también.
     *
     * Devuelve recuento de updates/skips/errores. Sigue el patrón del legacy
     * panel/a_items.php?action=bulkUpdate, pero contrato API limpio: el patch
     * trae columnas canónicas en camelCase (no las del form de Bootstrap 3).
     */
    public function bulkEdit(string $companyId, array $itemIds, array $patch, ?float $priceAdjustPercent = null): array
    {
        // Whitelist: dropear cualquier campo que no esté permitido.
        $patch = array_intersect_key($patch, array_flip(self::BULK_EDIT_WHITELIST));

        // Sucursales: un `outletIds` vacío es un intento de dejar los ítems sin
        // sucursal — se descarta en vez de propagarse a N ítems y hacer fallar
        // cada uno por separado. (El front deshabilita el submit en ese caso;
        // esto es la red de seguridad del lado del server.)
        if (array_key_exists('outletIds', $patch)) {
            $ids = is_array($patch['outletIds']) ? array_filter($patch['outletIds']) : [];
            if ($ids === []) {
                unset($patch['outletIds']);
            }
        }
        // itemDiscount: 0 o vacío → NULL.
        if (array_key_exists('itemDiscount', $patch)) {
            $d = $patch['itemDiscount'];
            $patch['itemDiscount'] = (is_numeric($d) && (float) $d > 0) ? (float) $d : null;
        }

        $usePercent = $priceAdjustPercent !== null && $priceAdjustPercent != 0.0;
        $hasPatch   = !empty($patch) || $usePercent;
        if (!$hasPatch) {
            return ['ok' => true, 'updated' => 0, 'skipped' => count($itemIds), 'errors' => []];
        }

        $updated = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($itemIds as $itemId) {
            if (!is_string($itemId) || $itemId === '') { $skipped++; continue; }
            $item = $this->find($itemId, $companyId);
            if ($item === null) { $skipped++; continue; }
            $arr = ncmRow($item);

            $rowPatch = $patch;
            if ($usePercent) {
                $current = (float) ($arr['itemprice'] ?? $arr['itemPrice'] ?? 0);
                $rowPatch['itemPrice'] = round($current * (1 + $priceAdjustPercent / 100), 2);
            }

            if (empty($rowPatch)) { $skipped++; continue; }

            if (!$this->update($itemId, $companyId, $rowPatch)) {
                $errors[] = ['itemId' => $itemId, 'reason' => 'update_failed'];
                continue;
            }
            $updated++;

            // Si es grupo, propagar a hijos. Para hijos con percent ajuste,
            // recalcular sobre el precio individual del hijo.
            $isParent = $arr['itemisparent'] ?? $arr['itemIsParent'] ?? false;
            $isParent = $isParent === true || $isParent === 't' || $isParent === 1 || $isParent === '1';
            if ($isParent) {
                foreach ($this->repo->listChildIds($itemId, $companyId) as $childId) {
                    $childPatch = $patch;
                    if ($usePercent) {
                        $cItem = $this->find($childId, $companyId);
                        if ($cItem !== null) {
                            $cArr = ncmRow($cItem);
                            $cur  = (float) ($cArr['itemprice'] ?? $cArr['itemPrice'] ?? 0);
                            $childPatch['itemPrice'] = round($cur * (1 + $priceAdjustPercent / 100), 2);
                        }
                    }
                    if (empty($childPatch)) continue;
                    if ($this->update($childId, $companyId, $childPatch)) {
                        $updated++;
                    } else {
                        $errors[] = ['itemId' => $childId, 'reason' => 'child_update_failed'];
                    }
                }
            }
        }

        return ['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }
}
