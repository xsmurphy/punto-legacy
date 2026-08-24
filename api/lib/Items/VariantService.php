<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * VariantService — CRUD de variantes de producto.
 *
 * Una variante es un item completo con stock/costo/precio/SKU propios,
 * enlazado al item padre via variantParentId. El padre tiene hasVariants=true
 * y no es vendible ni trackea stock directamente.
 *
 * Invariantes multi-tenant:
 * - Toda operación recibe $companyId y verifica que padre e hija pertenezcan al mismo tenant.
 * - Se prohíbe crear variantes de variantes (anti-anidamiento).
 */
final class VariantService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Lista las variantes activas del padre.
     */
    public function listVariants(string $companyId, string $parentId): array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM item WHERE companyid = ? AND variantparentid = ? AND itemstatus = 1 ORDER BY itemdate ASC, itemname ASC',
            [$companyId, $parentId]
        );
        if ($rs === false) return [];
        $out = [];
        while (!$rs->EOF) {
            $row = $rs->fields;
            if (function_exists('_flattenJsonb')) {
                $out[] = _flattenJsonb($row);
            } else {
                $out[] = $row;
            }
            $rs->MoveNext();
        }
        return $out;
    }

    /**
     * Cuenta variantes activas de un padre.
     */
    public function countVariants(string $companyId, string $parentId): int
    {
        $row = ncmExecute(
            'SELECT COUNT(*) AS n FROM item WHERE companyid = ? AND variantparentid = ? AND itemstatus = 1',
            [$companyId, $parentId]
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Valida que un item padre exista, pertenezca al tenant, y sea hasVariants=true.
     * Retorna el array del padre o lanza excepción con mensaje de negocio.
     *
     * @throws \RuntimeException
     */
    public function validateParent(string $companyId, string $parentId): array
    {
        $rs = $this->db->Execute(
            'SELECT itemid, companyid, hasvariants, variantparentid FROM item WHERE itemid = ? LIMIT 1',
            [$parentId]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('Item padre no encontrado', 404);
        }
        // $rs->fields es CaseInsensitiveArray (DB.php), no un array PHP nativo —
        // devolverlo tal cual contra la firma `: array` tira TypeError ("Return
        // value must be of type array, CaseInsensitiveArray returned").
        // `ncmRow()` es la conversión única del DB layer (ver su docblock).
        $row = ncmRow($rs->fields);
        $rowCompany = $row['companyid'] ?? '';
        if ($rowCompany !== $companyId) {
            throw new \RuntimeException('Acceso denegado al item padre', 403);
        }
        $hv = $row['hasvariants'] ?? false;
        $hasVariants = ($hv === true || $hv === 't' || $hv === '1' || $hv === 1);
        if (!$hasVariants) {
            throw new \RuntimeException('El item padre no tiene hasVariants=true. Activalo primero.', 422);
        }
        $vp = $row['variantparentid'] ?? null;
        if (!empty($vp)) {
            throw new \RuntimeException('No se pueden crear variantes de variantes.', 422);
        }
        return $row;
    }

    /**
     * Bulk upsert de variantes — para el "Generar matriz" del frontend.
     *
     * Recibe array de:
     *   { itemId?, sku, barcode?, price, cost, stock, variantAttributes: {key: value} }
     *
     * Si itemId viene: UPDATE de los campos editables.
     * Si no viene: INSERT de nueva variante con stock inicial via Inventory::manageStock.
     * Todo en una única TX. Retorna el array de variantes finales.
     *
     * @throws \RuntimeException
     */
    public function bulkUpsertVariants(string $companyId, string $parentId, array $variants): array
    {
        // Obtener datos del padre para heredar campos.
        $parentRs = $this->db->Execute(
            'SELECT * FROM item WHERE itemid = ? AND companyid = ? AND itemstatus = 1 LIMIT 1',
            [$parentId, $companyId]
        );
        if ($parentRs === false || $parentRs->EOF) {
            throw new \RuntimeException('Padre no encontrado', 404);
        }
        $parentRow = $parentRs->fields;
        if (function_exists('_flattenJsonb')) {
            $parentRow = _flattenJsonb($parentRow);
        }

        $this->db->BeginTrans();
        try {
            foreach ($variants as $v) {
                $itemId    = isset($v['itemId']) && $v['itemId'] !== '' ? (string) $v['itemId'] : null;
                $sku       = isset($v['sku']) ? (string) $v['sku'] : '';
                $barcode   = isset($v['barcode']) && $v['barcode'] !== '' ? (string) $v['barcode'] : null;
                $price     = isset($v['price']) && is_numeric($v['price']) ? (float) $v['price'] : 0.0;
                $cost      = isset($v['cost']) && is_numeric($v['cost']) ? (float) $v['cost'] : 0.0;
                $stockInit = isset($v['stock']) && is_numeric($v['stock']) ? (float) $v['stock'] : 0.0;
                $attrs     = isset($v['variantAttributes']) && is_array($v['variantAttributes'])
                    ? $v['variantAttributes']
                    : [];

                // Construir nombre de la variante concatenando los valores de atributos.
                $parentName  = $parentRow['itemname'] ?? $parentRow['itemName'] ?? 'Variante';
                $attrStr     = implode(' ', array_values($attrs));
                $variantName = trim($parentName . ($attrStr ? ' — ' . $attrStr : ''));

                if ($itemId !== null) {
                    // UPDATE — verificar que la variante pertenece al padre y al tenant.
                    $checkRs = $this->db->Execute(
                        'SELECT itemid FROM item WHERE itemid = ? AND companyid = ? AND variantparentid = ?',
                        [$itemId, $companyId, $parentId]
                    );
                    if ($checkRs === false || $checkRs->EOF) {
                        throw new \RuntimeException("Variante $itemId no pertenece a este padre o tenant.", 422);
                    }
                    $patch = [
                        'itemSKU'           => $sku ?: null,
                        'itemPrice'         => $price,
                        'itemCost'          => $cost,
                        'variantAttributes' => $attrs ? json_encode($attrs) : null,
                        'updated_at'        => TODAY,
                    ];
                    if ($barcode !== null) $patch['itemBarcode'] = $barcode;
                    ncmUpdate([
                        'table'       => 'item',
                        'records'     => $patch,
                        'where'       => 'itemid = ? AND companyid = ?',
                        'whereParams' => [$itemId, $companyId],
                    ]);
                } else {
                    // INSERT nueva variante.
                    $parentKind = $parentRow['itemkind'] ?? $parentRow['itemKind'] ?? 'producto';
                    $record = [
                        'itemName'           => $variantName,
                        'itemSKU'            => $sku ?: null,
                        'itemPrice'          => $price,
                        'itemCost'           => $cost,
                        'companyId'          => $companyId,
                        'variantParentId'    => $parentId,
                        'hasVariants'        => false,
                        'variantAttributes'  => $attrs ? json_encode($attrs) : null,
                        'itemKind'           => $parentKind,
                        'itemType'           => $parentRow['itemtype'] ?? $parentRow['itemType'] ?? 'product',
                        'itemCanSale'        => true,
                        'itemTrackInventory' => true,
                        'itemProduction'     => false,
                        'itemStatus'         => 1,
                        'itemDate'           => TODAY,
                        'updated_at'         => TODAY,
                        'itemTaxIncluded'    => (bool) ($parentRow['itemtaxincluded'] ?? $parentRow['itemTaxIncluded'] ?? true),
                        'taxId'              => $parentRow['taxid'] ?? $parentRow['taxId'] ?? null,
                        'outletId'           => $parentRow['outletid'] ?? $parentRow['outletId'] ?? null,
                        'categoryId'         => $parentRow['categoryid'] ?? $parentRow['categoryId'] ?? null,
                    ];
                    if ($barcode !== null) $record['itemBarcode'] = $barcode;
                    $newId = ncmInsert(['table' => 'item', 'records' => $record]);
                    if ($newId === false) {
                        throw new \RuntimeException('No se pudo insertar la variante', 500);
                    }
                    // Stock inicial via Inventory::manageStock si stock > 0.
                    if ($stockInit > 0) {
                        \Punto\App\Domain\Inventory::manageStock([
                            'itemId'        => $newId,
                            'source'        => 'adjustment',
                            'type'          => '+',
                            'count'         => $stockInit,
                            'note'          => 'Stock inicial — variante creada',
                            'outletId'      => $parentRow['outletid'] ?? $parentRow['outletId'] ?? null,
                            'locationId'    => null,
                            'transactionId' => null,
                            'date'          => TODAY,
                            'companyId'     => $companyId,
                        ]);
                    }
                }
            }
            $this->db->CommitTrans();
        } catch (\Throwable $e) {
            $this->db->RollbackTrans();
            throw $e;
        }

        return $this->listVariants($companyId, $parentId);
    }
}
