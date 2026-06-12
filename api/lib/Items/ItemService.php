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

    public function __construct(ItemRepository $repo)
    {
        $this->repo = $repo;
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
    public function createBlank(string $companyId, ?string $type = null, ?string $kind = null)
    {
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

        return $this->repo->create($record);
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
        $patch['updated_at'] = TODAY;
        return $this->repo->update($id, $companyId, $patch);
    }

    public function archive(string $id, string $companyId): bool
    {
        return $this->repo->archive($id, $companyId);
    }

    public function find(string $id, string $companyId): ?CaseInsensitiveArray
    {
        return $this->repo->find($id, $companyId);
    }

    /**
     * Whitelist canónica de columnas que pueden venir en el patch de bulk-edit.
     * Cambios en este set son superficie pública del API — agregar con cuidado.
     */
    private const BULK_EDIT_WHITELIST = [
        'itemPrice', 'itemCost',
        'taxId', 'categoryId', 'brandId', 'outletId',
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

        // Normalizar: outletId 'all' o '' → NULL (compat legacy).
        if (array_key_exists('outletId', $patch) && ($patch['outletId'] === 'all' || $patch['outletId'] === '')) {
            $patch['outletId'] = null;
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
            $arr = $item->toArray();

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
                            $cArr = $cItem->toArray();
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
