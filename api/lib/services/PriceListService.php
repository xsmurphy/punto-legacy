<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;

/**
 * PriceListService — gestión de listas de precios.
 *
 * Las listas permiten aplicar ajustes globales (%) o precios fijos por ítem
 * a una venta, asociadas al cliente, sucursal o elegidas manualmente por el cajero.
 *
 * Prioridad de resolución de precio:
 *   1. $overridePriceListId (cajero eligió manualmente)
 *   2. contact.data->>'priceListId'
 *   3. outlet.data->>'priceListId'
 *   4. itemPrice base
 *
 * Dentro de la lista elegida:
 *   - fixedPrice del item → usar ese (ignora ajustes)
 *   - itemAdjustment del item → basePrice * (1 + itemAdjustment/100)
 *   - defaultAdjustment de la lista → basePrice * (1 + defaultAdjustment/100)
 *   - ninguno → basePrice
 *
 * Lecturas vía ncmExecute (para evitar Insert_ID). Escrituras vía $db->Execute/$db->Insert.
 */
final class PriceListService
{
    public function __construct(public readonly TenantContext $ctx) {}

    /**
     * Lista todas las listas del tenant para el DataTable.
     * Incluye conteo de ítems configurados.
     */
    public function list(string $companyId): array
    {
        $rows = ncmExecute(
            'SELECT pl.*, COUNT(pli.priceListItemId) AS itemCount
               FROM price_list pl
          LEFT JOIN price_list_item pli ON pli.priceListId = pl.priceListId AND pli.companyId = pl.companyId
              WHERE pl.companyId = ?
           GROUP BY pl.priceListId
           ORDER BY pl.createdAt DESC',
            [$companyId],
            false,
            true
        );

        $out = [];
        if ($rows) {
            while (!$rows->EOF) {
                $out[] = $this->shape($rows->fields);
                $rows->MoveNext();
            }
        }
        return $out;
    }

    /**
     * Detalle de una lista con todos sus ítems y nombres.
     */
    public function get(string $companyId, string $priceListId): ?array
    {
        $row = ncmExecute(
            'SELECT pl.*, COUNT(pli.priceListItemId) AS itemCount
               FROM price_list pl
          LEFT JOIN price_list_item pli ON pli.priceListId = pl.priceListId AND pli.companyId = pl.companyId
              WHERE pl.priceListId = ? AND pl.companyId = ?
           GROUP BY pl.priceListId
              LIMIT 1',
            [$priceListId, $companyId],
            false
        );

        if (!$row) return null;

        $list = $this->shape($row);
        $list['items'] = $this->listItems($companyId, $priceListId);
        return $list;
    }

    /**
     * Crea una lista de precios.
     */
    public function create(string $companyId, array $f): array
    {
        global $db;

        $name             = trim(strip_tags((string) ($f['name'] ?? '')));
        $defaultAdj       = (float) ($f['defaultAdjustment'] ?? 0);
        $validFrom        = $f['validFrom'] ?? null;
        $validTo          = $f['validTo'] ?? null;
        $status           = isset($f['status']) ? (bool) $f['status'] : true;

        if ($name === '') {
            return ['ok' => false, 'error' => 'El nombre es requerido'];
        }

        $ok = $db->Insert('price_list', [
            'priceListName'     => $name,
            'defaultAdjustment' => $defaultAdj,
            'validFrom'         => ($validFrom !== null && $validFrom !== '') ? $validFrom : null,
            'validTo'           => ($validTo !== null && $validTo !== '') ? $validTo : null,
            'status'            => $status,
            'companyId'         => $companyId,
        ]);

        if ($ok === false) {
            return ['ok' => false];
        }

        // Recuperar el registro recién creado (INSERT no retorna UUID en esta DB.php)
        $row = ncmExecute(
            'SELECT pl.*, 0 AS itemCount
               FROM price_list pl
              WHERE pl.companyId = ?
           ORDER BY pl.createdAt DESC
              LIMIT 1',
            [$companyId],
            false
        );

        return ['ok' => true, 'data' => $row ? $this->shape($row) : null];
    }

    /**
     * Actualiza el encabezado de una lista.
     */
    public function update(string $companyId, string $priceListId, array $f): array
    {
        global $db;

        $sets   = [];
        $params = [];

        if (isset($f['name'])) {
            $sets[]   = '"priceListName" = ?';
            $params[] = trim(strip_tags((string) $f['name']));
        }
        if (isset($f['defaultAdjustment'])) {
            $sets[]   = '"defaultAdjustment" = ?';
            $params[] = (float) $f['defaultAdjustment'];
        }
        if (array_key_exists('validFrom', $f) && $f['validFrom'] !== '__absent__') {
            $sets[]   = '"validFrom" = ?';
            $params[] = ($f['validFrom'] !== null && $f['validFrom'] !== '') ? $f['validFrom'] : null;
        }
        if (array_key_exists('validTo', $f) && $f['validTo'] !== '__absent__') {
            $sets[]   = '"validTo" = ?';
            $params[] = ($f['validTo'] !== null && $f['validTo'] !== '') ? $f['validTo'] : null;
        }
        if (isset($f['status'])) {
            $sets[]   = '"status" = ?';
            $params[] = (bool) $f['status'];
        }

        if (empty($sets)) {
            return ['ok' => true];
        }

        $sets[]   = '"updatedAt" = now()';
        $params[] = $priceListId;
        $params[] = $companyId;

        $res = $db->Execute(
            'UPDATE price_list SET ' . implode(', ', $sets)
                . ' WHERE "priceListId" = ? AND "companyId" = ?',
            $params
        );

        if ($res === false) {
            return ['ok' => false];
        }

        $updated = $this->get($companyId, $priceListId);
        return ['ok' => true, 'data' => $updated];
    }

    /**
     * Elimina una lista y sus ítems (CASCADE).
     */
    public function delete(string $companyId, string $priceListId): array
    {
        global $db;

        $res = $db->Execute(
            'DELETE FROM price_list WHERE "priceListId" = ? AND "companyId" = ?',
            [$priceListId, $companyId]
        );

        if ($res === false) {
            return ['ok' => false];
        }

        return ['ok' => true];
    }

    /**
     * Reemplaza todos los ítems de una lista (bulk replace).
     * DELETE + INSERT en transacción. Idempotente.
     *
     * $items = [{itemId, fixedPrice?, itemAdjustment?}]
     */
    public function setItems(string $companyId, string $priceListId, array $items): array
    {
        global $db;

        // Verificar que la lista pertenece al tenant
        $exists = ncmExecute(
            'SELECT 1 FROM price_list WHERE "priceListId" = ? AND "companyId" = ? LIMIT 1',
            [$priceListId, $companyId],
            false
        );

        if (!$exists) {
            return ['ok' => false, 'error' => 'Lista no encontrada'];
        }

        $db->StartTrans();

        $db->Execute(
            'DELETE FROM price_list_item WHERE "priceListId" = ? AND "companyId" = ?',
            [$priceListId, $companyId]
        );

        foreach ($items as $item) {
            $itemId       = trim((string) ($item['itemId'] ?? ''));
            $fixedPrice   = isset($item['fixedPrice']) && $item['fixedPrice'] !== null && $item['fixedPrice'] !== ''
                ? (float) $item['fixedPrice']
                : null;
            $itemAdj      = isset($item['itemAdjustment']) && $item['itemAdjustment'] !== null && $item['itemAdjustment'] !== ''
                ? (float) $item['itemAdjustment']
                : null;

            if ($itemId === '') continue;

            // fixedPrice y itemAdjustment son mutuamente excluyentes — fixedPrice tiene prioridad
            if ($fixedPrice !== null) {
                $itemAdj = null;
            }

            $ok = $db->Insert('price_list_item', [
                'priceListId'    => $priceListId,
                'itemId'         => $itemId,
                'fixedPrice'     => $fixedPrice,
                'itemAdjustment' => $itemAdj,
                'companyId'      => $companyId,
            ]);

            if ($ok === false) {
                $db->FailTrans();
                break;
            }
        }

        if (!$db->CompleteTrans()) {
            return ['ok' => false];
        }

        return ['ok' => true, 'items' => $this->listItems($companyId, $priceListId)];
    }

    /**
     * Resuelve el precio final de un ítem dado el contexto de la venta.
     *
     * @return array{price: float, priceListId: string|null, priceListName: string|null}
     */
    public function resolvePrice(
        string  $companyId,
        string  $itemId,
        float   $basePrice,
        ?string $contactId = null,
        ?string $outletId  = null,
        ?string $overridePriceListId = null
    ): array {
        $list = $this->resolveActiveList(
            $companyId,
            $contactId,
            $outletId,
            $overridePriceListId
        );

        if ($list === null) {
            return [
                'price'         => round($basePrice, 2),
                'priceListId'   => null,
                'priceListName' => null,
            ];
        }

        // Obtener override del ítem si existe
        $itemRow = ncmExecute(
            'SELECT "fixedPrice", "itemAdjustment"
               FROM price_list_item
              WHERE "priceListId" = ? AND "itemId" = ? AND "companyId" = ?
              LIMIT 1',
            [$list['priceListId'], $itemId, $companyId],
            false
        );

        $price = $this->applyList($list, $itemRow ?: [], $basePrice);

        return [
            'price'         => round($price, 2),
            'priceListId'   => $list['priceListId'],
            'priceListName' => $list['priceListName'],
        ];
    }

    /**
     * Resuelve precios para múltiples ítems en una sola consulta.
     * Carga la lista activa una vez y aplica a todos.
     *
     * @param array $items [{itemId, basePrice}]
     * @return array [{itemId, price, originalPrice, priceListId?, priceListName?}]
     */
    public function resolvePriceBatch(
        string  $companyId,
        array   $items,
        ?string $contactId = null,
        ?string $outletId  = null,
        ?string $overridePriceListId = null
    ): array {
        $list = $this->resolveActiveList(
            $companyId,
            $contactId,
            $outletId,
            $overridePriceListId
        );

        $out = [];

        if ($list === null) {
            foreach ($items as $item) {
                $base  = (float) ($item['basePrice'] ?? 0);
                $out[] = [
                    'itemId'        => $item['itemId'],
                    'price'         => round($base, 2),
                    'originalPrice' => round($base, 2),
                    'priceListId'   => null,
                    'priceListName' => null,
                ];
            }
            return $out;
        }

        // Cargar todos los overrides de la lista para los ítems solicitados en una query
        $itemIds = array_map(fn($i) => $i['itemId'], $items);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

        $listItemRows = [];
        if (!empty($itemIds)) {
            $rows = ncmExecute(
                'SELECT "itemId", "fixedPrice", "itemAdjustment"
                   FROM price_list_item
                  WHERE "priceListId" = ? AND "companyId" = ? AND "itemId" IN (' . $placeholders . ')',
                array_merge([$list['priceListId'], $companyId], $itemIds),
                false,
                true
            );
            if ($rows) {
                while (!$rows->EOF) {
                    $listItemRows[$rows->fields['itemId']] = $rows->fields;
                    $rows->MoveNext();
                }
            }
        }

        foreach ($items as $item) {
            $base      = (float) ($item['basePrice'] ?? 0);
            $itemId    = $item['itemId'];
            $itemRow   = $listItemRows[$itemId] ?? [];
            $resolved  = $this->applyList($list, $itemRow, $base);

            $out[] = [
                'itemId'        => $itemId,
                'price'         => round($resolved, 2),
                'originalPrice' => round($base, 2),
                'priceListId'   => $list['priceListId'],
                'priceListName' => $list['priceListName'],
            ];
        }

        return $out;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Resuelve la lista activa según prioridad:
     *   1. $overridePriceListId (manual del cajero)
     *   2. contact.data->>'priceListId'
     *   3. outlet.data->>'priceListId'
     *
     * Verifica vigencia (validFrom/validTo). Si la lista está fuera de vigencia,
     * cae al siguiente nivel de prioridad.
     *
     * @return array|null row de price_list o null si no hay lista activa
     */
    private function resolveActiveList(
        string  $companyId,
        ?string $contactId,
        ?string $outletId,
        ?string $overridePriceListId
    ): ?array {
        // Candidatos en orden de prioridad
        $candidates = array_filter([
            $overridePriceListId,
            $contactId  ? $this->getContactPriceListId($companyId, $contactId) : null,
            $outletId   ? $this->getOutletPriceListId($companyId, $outletId)   : null,
        ]);

        foreach ($candidates as $candidateId) {
            if (!$candidateId) continue;

            $list = ncmExecute(
                'SELECT "priceListId", "priceListName", "defaultAdjustment", "validFrom", "validTo"
                   FROM price_list
                  WHERE "priceListId" = ? AND "companyId" = ? AND "status" = true
                    AND ("validFrom" IS NULL OR "validFrom" <= now())
                    AND ("validTo"   IS NULL OR "validTo"  >= now())
                  LIMIT 1',
                [$candidateId, $companyId],
                false
            );

            if ($list) {
                return (array) $list;
            }
            // Si la lista no es válida/vigente → siguiente nivel de prioridad
        }

        return null;
    }

    /** Lee priceListId del JSONB del contacto. */
    private function getContactPriceListId(string $companyId, string $contactId): ?string
    {
        $row = ncmExecute(
            'SELECT data->>\'priceListId\' AS "priceListId"
               FROM contact
              WHERE "contactId" = ? AND "companyId" = ?
              LIMIT 1',
            [$contactId, $companyId],
            false
        );
        return ($row && !empty($row['priceListId'])) ? (string) $row['priceListId'] : null;
    }

    /** Lee priceListId del JSONB del outlet. */
    private function getOutletPriceListId(string $companyId, string $outletId): ?string
    {
        $row = ncmExecute(
            'SELECT data->>\'priceListId\' AS "priceListId"
               FROM outlet
              WHERE "outletId" = ? AND "companyId" = ?
              LIMIT 1',
            [$outletId, $companyId],
            false
        );
        return ($row && !empty($row['priceListId'])) ? (string) $row['priceListId'] : null;
    }

    /**
     * Aplica la lógica de precio de la lista para un ítem.
     *
     * @param array $list        row de price_list
     * @param array $itemRow     row de price_list_item (puede estar vacío si no hay override)
     * @param float $basePrice   precio base del ítem
     */
    private function applyList(array $list, array $itemRow, float $basePrice): float
    {
        // 1. Precio fijo absoluto → ignorar todo lo demás
        $fixedPrice = isset($itemRow['fixedPrice']) && $itemRow['fixedPrice'] !== null
            ? (float) $itemRow['fixedPrice']
            : null;
        if ($fixedPrice !== null) {
            return $fixedPrice;
        }

        // 2. Ajuste individual del ítem (% que reemplaza al defaultAdjustment)
        $itemAdj = isset($itemRow['itemAdjustment']) && $itemRow['itemAdjustment'] !== null
            ? (float) $itemRow['itemAdjustment']
            : null;
        if ($itemAdj !== null) {
            return $basePrice * (1 + $itemAdj / 100);
        }

        // 3. Ajuste global de la lista
        $defaultAdj = (float) ($list['defaultAdjustment'] ?? 0);
        if ($defaultAdj != 0.0) {
            return $basePrice * (1 + $defaultAdj / 100);
        }

        // 4. Sin ajuste → precio base
        return $basePrice;
    }

    /** Items de una lista con datos del ítem. */
    private function listItems(string $companyId, string $priceListId): array
    {
        $rows = ncmExecute(
            'SELECT pli.*, i."itemName", i."itemSKU", i."itemPrice"
               FROM price_list_item pli
               JOIN item i ON i."itemId" = pli."itemId" AND i."companyId" = pli."companyId"
              WHERE pli."priceListId" = ? AND pli."companyId" = ?
           ORDER BY i."itemName" ASC',
            [$priceListId, $companyId],
            false,
            true
        );

        // Load the list to compute resolvedPrice
        $list = ncmExecute(
            'SELECT "defaultAdjustment" FROM price_list WHERE "priceListId" = ? AND "companyId" = ? LIMIT 1',
            [$priceListId, $companyId],
            false
        );

        $out = [];
        if ($rows) {
            while (!$rows->EOF) {
                $out[] = $this->shapeItem($rows->fields, $list ?: []);
                $rows->MoveNext();
            }
        }
        return $out;
    }

    private function shape(array $row): array
    {
        return [
            'priceListId'       => (string) ($row['priceListId'] ?? ''),
            'priceListName'     => (string) ($row['priceListName'] ?? ''),
            'defaultAdjustment' => (float)  ($row['defaultAdjustment'] ?? 0),
            'validFrom'         => $row['validFrom'] ?? null,
            'validTo'           => $row['validTo'] ?? null,
            'status'            => (bool) ($row['status'] ?? true),
            'itemCount'         => (int) ($row['itemCount'] ?? 0),
            'createdAt'         => $row['createdAt'] ?? null,
            'updatedAt'         => $row['updatedAt'] ?? null,
        ];
    }

    private function shapeItem(array $row, array $listRow): array
    {
        $basePrice  = (float) ($row['itemPrice'] ?? 0);
        $fixedPrice = isset($row['fixedPrice']) && $row['fixedPrice'] !== null
            ? (float) $row['fixedPrice']
            : null;
        $itemAdj    = isset($row['itemAdjustment']) && $row['itemAdjustment'] !== null
            ? (float) $row['itemAdjustment']
            : null;
        $defaultAdj = (float) ($listRow['defaultAdjustment'] ?? 0);

        $resolved = $this->applyList(
            ['defaultAdjustment' => $defaultAdj],
            ['fixedPrice' => $fixedPrice, 'itemAdjustment' => $itemAdj],
            $basePrice
        );

        return [
            'priceListItemId' => (string) ($row['priceListItemId'] ?? ''),
            'priceListId'     => (string) ($row['priceListId'] ?? ''),
            'itemId'          => (string) ($row['itemId'] ?? ''),
            'itemName'        => (string) ($row['itemName'] ?? ''),
            'itemSKU'         => $row['itemSKU'] ?? null,
            'basePrice'       => round($basePrice, 2),
            'fixedPrice'      => $fixedPrice,
            'itemAdjustment'  => $itemAdj,
            'resolvedPrice'   => round($resolved, 2),
        ];
    }
}
