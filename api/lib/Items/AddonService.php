<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * AddonService — grupos de add-ons de un producto (context/41, F1).
 *
 * Modelo (D1-D3 del plan, owner 2026-08-14):
 *   - Grupos POR PRODUCTO, no reusables: `addon_group.itemId` es el dueño
 *     directo, sin tabla N:M. "Copiar grupos desde otro producto" es una
 *     COPIA real (copyFromItem), no una referencia compartida.
 *   - Cada opción de un grupo ES un producto real del catálogo
 *     (`addon_group_option.itemId`): hereda stock, receta, costo e
 *     impuestos, y la venta lo descuenta con la misma maquinaria que
 *     cualquier ítem (F3).
 *   - `priceDelta` lo define CADA OPCIÓN (D2): 0 = no suma al precio, >0 =
 *     recarga. No hay % de descuento sobre la suma.
 *   - `isLocked` implica `isDefault` SIEMPRE: un add-on fijo está siempre
 *     elegido. El guard vive acá (server), nunca se confía en lo que mande
 *     la UI — se normaliza en `normalizeGroups()` antes de cualquier INSERT.
 *
 * Tablas nuevas (mig 134): camelCase QUOTED. `item`/`company` son tablas
 * legacy: sus columnas van sin quotes (context/08-convenciones-criticas.md
 * §44) — PG las resuelve a lowercase en ambos lados.
 */
final class AddonService
{
    private const MAX_GROUPS_PER_ITEM  = 20;
    private const MAX_OPTIONS_PER_GROUP = 50;

    /**
     * Grupos + opciones de un ítem, ordenados por sort. Shape camelCase.
     * Scopeado por companyId en el propio JOIN: un itemId de otro tenant
     * simplemente no matchea ninguna fila y devuelve [] (sin 404 — el
     * caller decide si eso es un error).
     *
     * @return array<int,array{
     *   id:string,name:string,minSelect:int,maxSelect:?int,sort:int,status:bool,
     *   options:array<int,array{id:string,itemId:string,itemName:string,itemPrice:float,
     *     priceDelta:float,isDefault:bool,isLocked:bool,maxQty:int,sort:int}>
     * }>
     */
    public function listForItem(string $itemId, string $companyId): array
    {
        $rs = ncmExecute(
            'SELECT
                 ag."groupId"      AS "gId",
                 ag."name"         AS "gName",
                 ag."minSelect"    AS "gMinSelect",
                 ag."maxSelect"    AS "gMaxSelect",
                 ag."sort"         AS "gSort",
                 ag."status"       AS "gStatus",
                 ago."optionId"    AS "oId",
                 ago."itemId"      AS "oItemId",
                 i.itemName        AS "oItemName",
                 i.itemPrice       AS "oItemPrice",
                 ago."priceDelta"  AS "oPriceDelta",
                 ago."isDefault"   AS "oIsDefault",
                 ago."isLocked"    AS "oIsLocked",
                 ago."maxQty"      AS "oMaxQty",
                 ago."sort"        AS "oSort"
             FROM "addon_group" ag
             LEFT JOIN "addon_group_option" ago ON ago."groupId" = ag."groupId"
             LEFT JOIN item i ON i.itemId = ago."itemId"
             WHERE ag."companyId" = ? AND ag."itemId" = ?
             ORDER BY ag."sort" ASC, ag."groupId" ASC, ago."sort" ASC, ago."optionId" ASC',
            [$companyId, $itemId],
            false,
            true // forceObj → recordset (§41 convención)
        );

        $groups = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $gId = (string) ($f['gId'] ?? '');
                if ($gId === '') {
                    $rs->MoveNext();
                    continue;
                }
                if (!isset($groups[$gId])) {
                    $groups[$gId] = [
                        'id'        => $gId,
                        'name'      => (string) ($f['gName'] ?? ''),
                        'minSelect' => (int) ($f['gMinSelect'] ?? 0),
                        'maxSelect' => $f['gMaxSelect'] === null ? null : (int) $f['gMaxSelect'],
                        'sort'      => (int) ($f['gSort'] ?? 0),
                        'status'    => (bool) ($f['gStatus'] ?? true),
                        'options'   => [],
                    ];
                }
                $oId = (string) ($f['oId'] ?? '');
                if ($oId !== '') {
                    $groups[$gId]['options'][] = [
                        'id'         => $oId,
                        'itemId'     => (string) ($f['oItemId'] ?? ''),
                        'itemName'   => (string) ($f['oItemName'] ?? ''),
                        'itemPrice'  => (float) ($f['oItemPrice'] ?? 0),
                        'priceDelta' => (float) ($f['oPriceDelta'] ?? 0),
                        'isDefault'  => (bool) ($f['oIsDefault'] ?? false),
                        'isLocked'   => (bool) ($f['oIsLocked'] ?? false),
                        'maxQty'     => (int) ($f['oMaxQty'] ?? 1),
                        'sort'       => (int) ($f['oSort'] ?? 0),
                    ];
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return array_values($groups);
    }

    /**
     * Reemplazo completo de los grupos de un ítem: DELETE de lo existente +
     * INSERT de lo recibido, en una transacción. `addon_group` tiene CASCADE
     * a `addon_group_option`, así que el DELETE de grupos se lleva sus
     * opciones solo.
     *
     * @param array<int,array{name:string,minSelect?:int,maxSelect?:?int,sort?:int,status?:bool,
     *   options?:array<int,array{itemId:string,priceDelta?:float,isDefault?:bool,isLocked?:bool,
     *     maxQty?:int,sort?:int}>}> $groups
     */
    public function replaceForItem(string $itemId, string $companyId, array $groups): array
    {
        $this->assertItemOwnedByTenant($itemId, $companyId, 'El ítem no pertenece a este comercio');

        $normalized = $this->normalizeGroups($itemId, $companyId, $groups);

        global $db;
        $db->StartTrans();

        $db->Execute(
            'DELETE FROM "addon_group" WHERE "itemId" = ? AND "companyId" = ?',
            [$itemId, $companyId]
        );

        foreach ($normalized as $group) {
            $db->Execute(
                'INSERT INTO "addon_group"
                     ("groupId","companyId","itemId","name","minSelect","maxSelect","sort","status")
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $group['groupId'], $companyId, $itemId, $group['name'],
                    $group['minSelect'], $group['maxSelect'], $group['sort'], $group['status'],
                ]
            );

            foreach ($group['options'] as $opt) {
                $db->Execute(
                    'INSERT INTO "addon_group_option"
                         ("optionId","groupId","itemId","priceDelta","isDefault","isLocked","maxQty","sort")
                     VALUES (?,?,?,?,?,?,?,?)',
                    [
                        $opt['optionId'], $group['groupId'], $opt['itemId'], $opt['priceDelta'],
                        $opt['isDefault'], $opt['isLocked'], $opt['maxQty'], $opt['sort'],
                    ]
                );
            }
        }

        // Se lee dentro de la misma transacción: Postgres ve las filas recién
        // insertadas aunque todavía no haya CompleteTrans.
        $result = $this->listForItem($itemId, $companyId);

        $db->CompleteTrans();

        return $result;
    }

    /**
     * Copia REAL (UUIDs nuevos) de los grupos de `sourceItemId` a
     * `targetItemId`. Misma semántica que replaceForItem: reemplaza los
     * grupos que el target ya tuviera. Reusa TODAS las validaciones de
     * replaceForItem — incluida "una opción no puede apuntar al propio ítem
     * padre", que acá cobra sentido si el source incluía al propio target
     * como opción de alguno de sus grupos.
     */
    public function copyFromItem(string $targetItemId, string $sourceItemId, string $companyId): array
    {
        $this->assertItemOwnedByTenant($targetItemId, $companyId, 'El ítem destino no pertenece a este comercio');
        $this->assertItemOwnedByTenant($sourceItemId, $companyId, 'El ítem origen no pertenece a este comercio');

        $sourceGroups = $this->listForItem($sourceItemId, $companyId);

        // Reempaqueta al shape de entrada de replaceForItem (sin ids viejos:
        // se generan de nuevo adentro de normalizeGroups).
        $payload = array_map(static function (array $g): array {
            return [
                'name'      => $g['name'],
                'minSelect' => $g['minSelect'],
                'maxSelect' => $g['maxSelect'],
                'sort'      => $g['sort'],
                'status'    => $g['status'],
                'options'   => array_map(static function (array $o): array {
                    return [
                        'itemId'     => $o['itemId'],
                        'priceDelta' => $o['priceDelta'],
                        'isDefault'  => $o['isDefault'],
                        'isLocked'   => $o['isLocked'],
                        'maxQty'     => $o['maxQty'],
                        'sort'       => $o['sort'],
                    ];
                }, $g['options']),
            ];
        }, $sourceGroups);

        return $this->replaceForItem($targetItemId, $companyId, $payload);
    }

    /**
     * Valida una selección de add-ons contra el modelo (F3, caller:
     * `SaleService::expandAddonSelections`). El precio NUNCA viaja del
     * cliente: se recalcula acá desde `priceDelta` en BD.
     *
     * Los `isLocked` se agregan solos con qty=1 si el caller no los mandó —
     * un add-on fijo está siempre elegido, no depende de que el POS lo haya
     * incluido en el payload.
     *
     * Errores: `InvalidAddonSelectionException`, NO `apiError()` como el
     * resto de la clase — ver el docblock de la excepción (offline-sync
     * procesa un lote de ventas y un `exit` se llevaría puestas las que
     * faltan). Los métodos de CRUD siguen cortando con `apiError`.
     *
     * `itemName` viaja en cada línea porque el caller lo necesita para el
     * detalle de la venta (`meta.transactionDetails`, ticket y comanda) y ya
     * está en memoria acá — evita una query por opción del lado de la venta.
     *
     * @param array<int,array{optionId:string,qty:int}> $selections
     * @return array{ok:true,priceDelta:float,lines:array<int,array{optionId:string,itemId:string,itemName:string,qty:int,priceDelta:float}>}
     *
     * @throws Exceptions\InvalidAddonSelectionException
     */
    public function validateSelections(string $itemId, string $companyId, array $selections): array
    {
        $groups = $this->listForItem($itemId, $companyId);

        // Índice optionId → [option, group] para validar en O(1).
        $byOption = [];
        foreach ($groups as $group) {
            foreach ($group['options'] as $opt) {
                $byOption[$opt['id']] = ['option' => $opt, 'group' => $group];
            }
        }

        // qty por optionId recibido; error si el caller repite un optionId
        // (ambigüedad de cuál qty vale — mejor rechazar que adivinar).
        $qtyByOption = [];
        foreach ($selections as $sel) {
            $optionId = (string) ($sel['optionId'] ?? '');
            if ($optionId === '' || !isset($byOption[$optionId])) {
                throw new Exceptions\InvalidAddonSelectionException('Opción inválida para este ítem: ' . $optionId);
            }
            if (isset($qtyByOption[$optionId])) {
                throw new Exceptions\InvalidAddonSelectionException('Opción repetida en la selección: ' . $optionId);
            }
            $qty = (int) ($sel['qty'] ?? 1);
            if ($qty < 1) {
                throw new Exceptions\InvalidAddonSelectionException('La cantidad debe ser al menos 1');
            }
            $qtyByOption[$optionId] = $qty;
        }

        // Los isLocked se agregan solos si no vinieron.
        foreach ($byOption as $optionId => $entry) {
            if ($entry['option']['isLocked'] && !isset($qtyByOption[$optionId])) {
                $qtyByOption[$optionId] = 1;
            }
        }

        $lines      = [];
        $totalDelta = 0.0;
        $selectedByGroup = [];

        foreach ($qtyByOption as $optionId => $qty) {
            $entry  = $byOption[$optionId];
            $option = $entry['option'];
            $group  = $entry['group'];

            if (!$group['status']) {
                throw new Exceptions\InvalidAddonSelectionException(
                    'El grupo "' . $group['name'] . '" ya no está disponible'
                );
            }
            if ($qty > $option['maxQty']) {
                throw new Exceptions\InvalidAddonSelectionException(
                    'La opción "' . $option['itemName'] . '" admite como máximo ' . $option['maxQty']
                );
            }

            $selectedByGroup[$group['id']] = ($selectedByGroup[$group['id']] ?? 0) + 1;

            $lineDelta = round($option['priceDelta'] * $qty, 2);
            $totalDelta += $lineDelta;
            $lines[] = [
                'optionId'   => $optionId,
                'itemId'     => $option['itemId'],
                'itemName'   => $option['itemName'],
                'qty'        => $qty,
                'priceDelta' => $lineDelta,
            ];
        }

        // min/max por grupo: cuenta OPCIONES DISTINTAS elegidas, no la suma
        // de qty (max/minSelect son sobre variedad, maxQty es sobre repetición
        // de una misma opción).
        foreach ($groups as $group) {
            if (!$group['status']) {
                continue;
            }
            $selectedCount = $selectedByGroup[$group['id']] ?? 0;
            if ($selectedCount < $group['minSelect']) {
                throw new Exceptions\InvalidAddonSelectionException(
                    'El grupo "' . $group['name'] . '" requiere al menos ' . $group['minSelect'] . ' opción(es)'
                );
            }
            if ($group['maxSelect'] !== null && $selectedCount > $group['maxSelect']) {
                throw new Exceptions\InvalidAddonSelectionException(
                    'El grupo "' . $group['name'] . '" admite como máximo ' . $group['maxSelect'] . ' opción(es)'
                );
            }
        }

        return [
            'ok'         => true,
            'priceDelta' => round($totalDelta, 2),
            'lines'      => $lines,
        ];
    }

    // ── Internos ─────────────────────────────────────────────────────────

    /**
     * Valida + normaliza el payload de entrada de replaceForItem/copyFromItem
     * a filas listas para INSERT (con UUIDs nuevos y el guard isLocked⇒isDefault
     * aplicado). Nunca confía en lo que mande el caller: revalida tenant,
     * estado y límites acá, no en la UI.
     */
    private function normalizeGroups(string $itemId, string $companyId, array $groups): array
    {
        if (count($groups) > self::MAX_GROUPS_PER_ITEM) {
            apiError('Máximo ' . self::MAX_GROUPS_PER_ITEM . ' grupos por producto', 422);
        }

        $out = [];
        foreach ($groups as $group) {
            $name = trim((string) ($group['name'] ?? ''));
            if ($name === '') {
                apiError('El nombre del grupo es requerido', 422);
            }

            $minSelect = (int) ($group['minSelect'] ?? 0);
            if ($minSelect < 0) {
                apiError('minSelect no puede ser negativo', 422);
            }
            $maxSelectRaw = $group['maxSelect'] ?? null;
            $maxSelect    = $maxSelectRaw === null ? null : (int) $maxSelectRaw;
            if ($maxSelect !== null && $maxSelect < max($minSelect, 1)) {
                apiError('maxSelect debe ser mayor o igual que minSelect (y al menos 1)', 422);
            }

            $options = (array) ($group['options'] ?? []);
            if (count($options) > self::MAX_OPTIONS_PER_GROUP) {
                apiError('Máximo ' . self::MAX_OPTIONS_PER_GROUP . ' opciones por grupo', 422);
            }

            $outOptions = [];
            foreach ($options as $opt) {
                $optItemId = trim((string) ($opt['itemId'] ?? ''));
                if ($optItemId === '') {
                    apiError('Cada opción requiere itemId', 422);
                }
                if ($optItemId === $itemId) {
                    apiError('Una opción no puede apuntar al propio producto', 422);
                }
                $this->assertItemOwnedByTenant(
                    $optItemId,
                    $companyId,
                    'La opción apunta a un ítem que no pertenece a este comercio',
                    requireActive: true
                );

                $priceDelta = (float) ($opt['priceDelta'] ?? 0);
                if ($priceDelta < 0) {
                    apiError('priceDelta no puede ser negativo', 422);
                }
                $maxQty = (int) ($opt['maxQty'] ?? 1);
                if ($maxQty < 1) {
                    apiError('maxQty debe ser al menos 1', 422);
                }

                $isLocked  = (bool) ($opt['isLocked'] ?? false);
                // Guard: isLocked SIEMPRE fuerza isDefault=true, sin importar
                // lo que haya mandado el caller.
                $isDefault = $isLocked ? true : (bool) ($opt['isDefault'] ?? false);

                $outOptions[] = [
                    'optionId'   => generateUuidV7(),
                    'itemId'     => $optItemId,
                    'priceDelta' => $priceDelta,
                    'isDefault'  => $isDefault,
                    'isLocked'   => $isLocked,
                    'maxQty'     => $maxQty,
                    'sort'       => (int) ($opt['sort'] ?? 0),
                ];
            }

            $out[] = [
                'groupId'   => generateUuidV7(),
                'name'      => $name,
                'minSelect' => $minSelect,
                'maxSelect' => $maxSelect,
                'sort'      => (int) ($group['sort'] ?? 0),
                'status'    => (bool) ($group['status'] ?? true),
                'options'   => $outOptions,
            ];
        }

        return $out;
    }

    /**
     * Guard de tenant: el ítem existe, pertenece a `companyId`, y (opcional)
     * está activo (`itemStatus=1`). `item` es tabla legacy → columnas sin
     * quotes (§44).
     */
    private function assertItemOwnedByTenant(
        string $itemId,
        string $companyId,
        string $errorMessage,
        bool $requireActive = false
    ): void {
        $sql = 'SELECT 1 FROM item WHERE itemId = ? AND companyId = ?';
        $params = [$itemId, $companyId];
        if ($requireActive) {
            $sql .= ' AND itemStatus = 1';
        }
        $sql .= ' LIMIT 1';

        $row = ncmExecute($sql, $params);
        if (!$row) {
            apiError($errorMessage, 422);
        }
    }
}
