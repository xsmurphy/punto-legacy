<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Stock e inventario del POS.
 *
 * Reemplaza las funciones globales (Slice 13 del plan PSR-4):
 *   - getCompoundsArray($itemId, $cache)              → Inventory::getCompoundsArray(...)
 *   - displayableCompounds($id)                       → Inventory::displayableCompounds($id)
 *   - getProductionCapacity($compounds, $inv, $waste) → Inventory::getProductionCapacity(...)
 *   - getProductionCOGS($itemId, $wasted)             → Inventory::getProductionCOGS(...)
 *   - getComboCOGS($parent)                           → Inventory::getComboCOGS($parent)
 *   - getItemStock($itemId, $outlet, $inLocation)     → Inventory::getItemStock(...)
 *   - getItemMainStock($itemId, $outletId)            → Inventory::getItemMainStock(...)
 *   - getAllItemStock($outlet, $all)                   → Inventory::getAllItemStock(...)
 *   - manageStock($ops)                               → Inventory::manageStock($ops)
 *   - getAllWasteValue($id, $cache)                   → Inventory::getAllWasteValue(...)
 *   - getNeedWithWaste($need, $wasteP)                → Inventory::getNeedWithWaste(...)
 *
 * CRÍTICO: manageStock es el god node del inventario (27 callers). Cualquier
 * delta aquí afecta al money path. Semántica preservada VERBATIM.
 */
final class Inventory
{
    /**
     * Compuestos de un ítem (receta/ingredientes). Usa $getAssoc=true.
     * Equivalente legacy: `getCompoundsArray($itemId, $cache)`.
     */
    public static function getCompoundsArray(mixed $itemId, mixed $cache = false): mixed
    {
        return ncmExecute(
            'SELECT * FROM toCompound WHERE itemId = ? ORDER BY toCompoundOrder LIMIT 1000',
            [$itemId],
            $cache,
            true,
            true
        );
    }

    /**
     * Compuestos en formato displayable para el front [{id, units, select}].
     * Equivalente legacy: `displayableCompounds($id)`.
     */
    public static function displayableCompounds(mixed $id): array
    {
        $out       = [];
        $compounds = self::getCompoundsArray($id);

        if ($compounds) {
            foreach ($compounds as $key => $value) {
                $out[] = [
                    'id'     => enc($value['compoundId']),
                    'units'  => $value['toCompoundQty'],
                    'select' => $value['toCompoundPreselected'],
                ];
            }
        }

        return $out;
    }

    /**
     * Capacidad de producción: unidades que se pueden fabricar dado el inventario.
     * Retorna el mínimo de unidades posibles por compuesto.
     * Equivalente legacy: `getProductionCapacity($compounds, $inventory, $waste)`.
     */
    public static function getProductionCapacity(mixed $compounds, mixed $inventory, mixed $waste = false): int|float
    {
        if (!$waste) {
            $waste = [];
        }

        if (validity($compounds, 'array') && $inventory) {
            $eachAmount = [];

            foreach ($compounds as $val) {
                $need  = $val['toCompoundQty'];
                $wasteP = 0;
                if (array_key_exists($val['compoundId'], $waste)) {
                    $wasteP = $waste[$val['compoundId']];
                }

                if ($wasteP > 0) {
                    $need = self::getNeedWithWaste($need, $wasteP);
                }

                if ($need > 0) {
                    $have = 0;
                    if (array_key_exists($val['compoundId'], $inventory)) {
                        $have = $inventory[$val['compoundId']]['onHand'];
                    }

                    $divi         = divider($need, $have);
                    $eachAmount[] = round($divi, 3);
                }
            }

            return $eachAmount ? min($eachAmount) : 0;
        }

        return 0;
    }

    /**
     * Costo de producción (COGS) de un ítem a partir de sus compuestos y stock.
     * Equivalente legacy: `getProductionCOGS($itemId, $wasted)`.
     */
    public static function getProductionCOGS(mixed $itemId, bool $wasted = true): int|float
    {
        $total  = 0;
        $result = self::getCompoundsArray($itemId);

        if ($result) {
            $waste = self::getAllWasteValue();

            foreach ($result as $key => $value) {
                $id    = $value['compoundId'];
                $count = (float) $value['toCompoundQty'];

                $wasteP = $waste[$id] ?? '';

                if ($wasteP > 0 && $wasted) {
                    $count = self::getNeedWithWaste($count, $wasteP);
                }

                $avrg  = self::getItemStock($id);
                $avrg  = $avrg['stockOnHandCOGS'] ?? 0;

                $price  = ($avrg * $count);
                $total += $price;
            }
        }

        return $total;
    }

    /**
     * COGS de un combo: suma precio×unidades de cada componente.
     * Equivalente legacy: `getComboCOGS($parent)`.
     */
    public static function getComboCOGS(mixed $parent): int|float
    {
        $result    = self::getCompoundsArray($parent);
        $comboCOGS = 0;

        if (validity($result, 'array')) {
            foreach ($result as $resulta) {
                $id    = $resulta['compoundId'];
                $units = number_format($resulta['toCompoundQty'], 2);

                $compData  = ncmExecute('SELECT itemPrice FROM item WHERE itemId = ? LIMIT 1', [$id]);
                $price     = $compData['itemPrice'] ?? 0;

                $comboCOGS += $price * $units;
            }
        }

        return $comboCOGS;
    }

    /**
     * Stock de un ítem en una sucursal (o en una ubicación específica).
     * Equivalente legacy: `getItemStock($itemId, $outlet, $inLocation)`.
     */
    public static function getItemStock(mixed $itemId, mixed $outlet = false, mixed $inLocation = false): mixed
    {
        if (!validity($itemId)) {
            return false;
        }

        if ($inLocation) {
            $location = ncmExecute(
                'SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',
                [$itemId, $inLocation]
            );
            return ($location) ? $location['toLocationCount'] : 0;
        }

        $outletId = $outlet ?: OUTLET_ID;

        return ncmExecute(
            // "Stock actual" = la fila más reciente. stockId es UUID v4 random en PG
            // (no ordenable por tiempo) → ordenar por stockId daría una fila
            // arbitraria. La recencia la da stockDate; stockId DESC sólo desempata.
            'SELECT * FROM stock WHERE itemId = ? AND outletId = ? ORDER BY stockDate DESC, stockId DESC LIMIT 1',
            [$itemId, $outletId]
        );
    }

    /**
     * Stock principal de un ítem (descontando ubicaciones de depósito).
     * Equivalente legacy: `getItemMainStock($itemId, $outletId)`.
     */
    public static function getItemMainStock(mixed $itemId, mixed $outletId): mixed
    {
        $inventory = self::getItemStock($itemId, $outletId);
        $count     = formatQty($inventory['stockOnHand']);

        $depo = ncmExecute(
            "SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC",
            [$outletId],
            false,
            true
        );

        if ($depo) {
            $dTotal = 0;
            while (!$depo->EOF) {
                $dCount   = 0;
                $depCount = ncmExecute(
                    'SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',
                    [$depo->fields['taxonomyId'], $itemId]
                );

                if ($depCount) {
                    $dCount = $depCount['toLocationCount'];
                }

                $dTotal += $dCount;
                $count   = $count - $dTotal;

                $depo->MoveNext();
            }
        }

        return $count;
    }

    /**
     * Stock de todos los ítems para una o todas las sucursales.
     * Equivalente legacy: `getAllItemStock($outlet, $all)`.
     */
    public static function getAllItemStock(mixed $outlet = false, bool $all = false): array
    {
        // "Stock actual" = la fila de stock MÁS RECIENTE por item. El legacy usaba
        // max(stockId) (MySQL: PK autoincrement → max = última). En PG stockId es
        // UUID v4 random (DEFAULT gen_random_uuid()), NO ordenable por tiempo, así
        // que max(stockId)/ORDER BY stockId devolverían una fila ARBITRARIA. La
        // recencia real la da stockDate (TIMESTAMPTZ DEFAULT now()); stockId DESC
        // sólo desempata para determinismo. array_agg(...)[1] reemplaza max(uuid)
        // (inexistente en PG) sin cambiar la estructura del JOIN.
        $sql = 'SELECT t1.itemId as itemId, t1.stockOnHand as onHand, t1.stockOnHandCOGS as cogs
                FROM stock t1
                JOIN (
                    SELECT (array_agg(stockId ORDER BY stockDate DESC, stockId DESC))[1] AS stockId
                    FROM stock
                    WHERE outletId = ?
                    GROUP BY itemId
                ) t2 ON t1.stockId = t2.stockId AND t1.outletId = ?';

        if ($all) {
            $allOutletsArray = getAllOutletData();
            $result          = [];
            foreach ($allOutletsArray as $outletKey => $val) {
                $item = ncmExecute($sql, [$outletKey, $outletKey], false, true, true);
                if ($item) {
                    foreach ($item as $itemId => $values) {
                        $result[$itemId]['itemId']   = $values['itemId'];
                        $result[$itemId]['onHand'] += $values['onHand'];
                        $result[$itemId]['cogs']     = $values['cogs'];
                    }
                }
            }
        } else {
            $outlet = iftn($outlet, OUTLET_ID);
            $result = ncmExecute($sql, [$outlet, $outlet], false, true, true);
        }

        return validity($result) ? $result : [];
    }

    /**
     * Registra un movimiento de stock (entrada/salida) con auditoría.
     * CRÍTICO — money path: afecta costeo (COGS) de ventas.
     * Equivalente legacy: `manageStock($ops)`.
     *
     * Quirks preservados verbatim:
     * - $transaction/$supplierId/$locationId usan ?: null (NO iftn) para UUIDs vacíos.
     * - UUID entre comillas simples en SQL concat (§22.5).
     * - CaseInsensitiveArray check vía instanceof ArrayAccess.
     */
    public static function manageStock(array $ops): mixed
    {
        global $db;

        $itemId      = $ops['itemId'];
        $source      = iftn($ops['source'], 'adjustment');
        $count       = $ops['count'];
        $type        = iftn($ops['type'] ?? '', '+');
        $COGS        = array_key_exists('cogs', $ops) ? $ops['cogs'] : '';
        $user        = iftn(array_key_exists('userId', $ops) ? $ops['userId'] : '', USER_ID);
        $transaction = $ops['transactionId'];
        $supplier    = array_key_exists('supplierId', $ops) ? $ops['supplierId'] : '';
        $outlet      = $ops['outletId'];
        $location    = $ops['locationId'];
        $note        = array_key_exists('note', $ops) ? $ops['note'] : '';
        $date        = $ops['date'];
        $company     = iftn(array_key_exists('companyId', $ops) ? $ops['companyId'] : '', COMPANY_ID);

        if (!validity($count) || !$itemId) {
            return false;
        }

        $isStockeable = ncmExecute(
            'SELECT itemTrackInventory FROM item WHERE itemStatus = 1 AND itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, COMPANY_ID]
        );

        if (!$isStockeable || $isStockeable['itemTrackInventory'] < 1) {
            return false;
        }

        $stock    = self::getItemStock($itemId);
        $hasStock = ($stock instanceof \ArrayAccess) || is_array($stock);
        $oldStock = ($hasStock && isset($stock['stockOnHand'])     && is_numeric($stock['stockOnHand']))     ? $stock['stockOnHand']     : 0;
        $oldACOGS = ($hasStock && isset($stock['stockOnHandCOGS']) && is_numeric($stock['stockOnHandCOGS'])) ? $stock['stockOnHandCOGS'] : 0;

        if (!validity($COGS)) {
            $COGS = ($hasStock && isset($stock['stockCOGS'])) ? $stock['stockCOGS'] : '';
        }

        if ($type == '+') {
            $newOnHand = $oldStock + $count;

            if ($oldStock < 0) {
                $newCOGS = $COGS * $newOnHand;
            } else {
                $newCOGS = $COGS * $count;
            }

            $newTotalCOGS = (($oldACOGS * $oldStock) + $newCOGS);
            $newTotalCOGS = divider($newTotalCOGS, $newOnHand, true);
        } else {
            $newOnHand    = $oldStock - $count;
            $COGS         = $oldACOGS;
            $newTotalCOGS = ($newOnHand <= 0) ? 0 : $oldACOGS;
        }

        $row['stockSource']     = $source;
        $row['stockNote']       = $note;
        $row['stockCount']      = $type . $count;
        $row['stockCOGS']       = $COGS;
        $row['stockOnHand']     = $newOnHand;
        $row['stockOnHandCOGS'] = $newTotalCOGS;
        $row['itemId']          = $itemId;
        $row['transactionId']   = $transaction ?: null;
        $row['userId']          = $user;
        $row['supplierId']      = $supplier ?: null;
        $row['outletId']        = $outlet;
        $row['locationId']      = $location ?: null;
        $row['companyId']       = $company;

        if ($date) {
            $row['stockDate'] = $date;
        }

        $insert = $db->AutoExecute('stock', $row, 'INSERT');

        if ($insert !== true) {
            return false;
        }

        // PG: UUID entre comillas simples (§22.5).
        updateRowLastUpdate('item', "itemId = '" . $itemId . "'");

        if ($location) {
            $isLocation = ncmExecute(
                'SELECT toLocationId FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',
                [$location, $itemId]
            );
            if ($isLocation) {
                $db->Execute(
                    "UPDATE toLocation SET toLocationCount = toLocationCount" . $type . $count . " WHERE toLocationId = '" . $isLocation['toLocationId'] . "'"
                );
            } else {
                $db->AutoExecute('toLocation', [
                    'locationId'       => $location,
                    'toLocationCount'  => $type . $count,
                    'itemId'           => $itemId,
                ], 'INSERT');
            }
        }

        try {
            $userName     = getValue('contact',  'contactName',  "WHERE contactId = '" . USER_ID . "'");
            $registerName = getValue('register', 'registerName', "WHERE registerId = '" . REGISTER_ID . "'");
            $companyName  = defined('COMPANY_NAME') ? COMPANY_NAME : '';
            $outletName   = getCurrentOutletName(OUTLET_ID);
            $itemName     = getItemName($itemId);

            $auditoriaData = [
                'date'      => $date,
                'user'      => $userName,
                'module'    => 'STOCK',
                'origin'    => 'CAJA',
                'company_id' => COMPANY_ID,
                'data'      => [
                    'action'        => "El usuario $userName ajustó el item $itemName desde la caja " . $registerName,
                    'userId'        => USER_ID,
                    'userName'      => $userName,
                    'itemId'        => $itemId,
                    'itemName'      => $itemName,
                    'operationData' => $row,
                    'registerId'    => REGISTER_ID,
                    'registerName'  => $registerName,
                    'companyID'     => COMPANY_ID,
                    'companyName'   => $companyName,
                    'outletId'      => OUTLET_ID,
                    'outletName'    => $outletName,
                    'timestamp'     => $ops['timestamp'],
                ],
            ];

            sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
        } catch (\Throwable $th) {
            error_log("Error al enviar registro de auditoría de ajuste de stock: \n", 3, './error_log');
            error_log(print_r($th, true), 3, './error_log');
            error_log("data stock: \n", 3, './error_log');
            error_log(print_r($row, true), 3, './error_log');
        }

        return $row;
    }

    /**
     * Porcentaje de merma (waste) por itemId. Con $id retorna solo ese ítem.
     * Equivalente legacy: `getAllWasteValue($id, $cache)`.
     */
    public static function getAllWasteValue(mixed $id = false, mixed $cache = false): array
    {
        $andId = ' LIMIT 500';

        if ($id) {
            $andId = ' AND itemId = ' . $id . ' LIMIT 1';
        }

        $sql    = 'SELECT itemWaste, itemId FROM item WHERE itemWaste > 0 AND companyId = ? ' . $andId;
        $result = ncmExecute($sql, [COMPANY_ID], $cache, true);
        $out    = [];

        if ($result) {
            while (!$result->EOF) {
                $fields                = $result->fields;
                $out[$fields['itemId']] = $fields['itemWaste'];
                $result->MoveNext();
            }
            $result->Close();
        }

        return $out;
    }

    /**
     * Cantidad necesaria de un insumo contando su porcentaje de merma.
     * Equivalente legacy: `getNeedWithWaste($need, $wasteP)`.
     */
    public static function getNeedWithWaste(mixed $need, mixed $wasteP): int|float
    {
        $wasteFactor = $wasteP / 100;
        $wasteValue  = $need * $wasteFactor;
        return $need + $wasteValue;
    }
}
