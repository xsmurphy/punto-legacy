<?php

/**
 * ItemService — orquesta CRUD de item + aplica reglas de negocio.
 *
 * Punto de entrada para a_items.php (handlers HTTP) y panel/API/v1/items/*.
 * Delega persistencia en ItemRepository. NO genera output HTTP — solo retorna data.
 */

require_once __DIR__ . '/ItemRepository.php';

class ItemService
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
    public function createBlank(string $companyId, ?string $type = null)
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
}
