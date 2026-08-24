<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\App\Domain\Inventory;
use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Waste\WasteReasonService;

/**
 * StockReversalPolicy — wrapper compartido de D2 (context/40-anulacion-y-nota-credito.md):
 * decide qué es POSIBLE reponer al reversar una línea vendida (clasificación
 * por tipo de ítem), aplica la decisión del CAJERO por línea (con guard de
 * ambigüedad cuando el caller no puede atribuir sin dudas una decisión a una
 * línea concreta), ejecuta la reposición de stock y registra `waste_event`
 * para lo que no se repone.
 *
 * Extraído de `SaleVoidService` (F1+F2 de context/40, 2026-08-21) al
 * implementar D2 en `ReturnService` — regla del proyecto (~/.claude/CLAUDE.md):
 * atacar el wrapper compartido en vez de duplicar el call-site. Los dos
 * consumidores (`SaleVoidService::void()`, `ReturnService::create()`)
 * comparten EXACTAMENTE la misma tabla de clasificación D2 (qué es posible
 * según cómo el ítem descontó stock al venderse) y la misma mecánica de
 * decisión-del-cajero-por-línea con clamp a `canRestock` — la única
 * diferencia real es QUIÉN dispara la reversión (anulación vs devolución),
 * el `source` que queda en el ledger de stock, y el texto de motivo que se
 * graba en la merma.
 *
 * D2 (context/40, tabla completa):
 *   - Ítem con stock propio / producción previa → kind='ownStock'.
 *     `canRestock` siempre true.
 *   - Producción directa / combo (explota receta al vender) → kind=
 *     'ingredientReversal'. `canRestock` solo si el tenant activó
 *     `settingReturnAllowIngredientReversal` (apagado por default — reponer
 *     insumos ya consumidos infla el inventario). Reponer repone los
 *     INSUMOS (multi-nivel, `Inventory::explodeRecipe`), nunca el ítem.
 *   - Servicio / sin stock → kind='service'. `canRestock` siempre false,
 *     no hubo nada que descontar.
 *   - Hija de combo fijo (`meta.compound`) → kind='compoundChild'.
 *     `canRestock` y `hadStockImpact` siempre false: la venta NUNCA movió
 *     stock por esa línea (context/52, G4).
 *
 * Lo que no se repone y tuvo impacto real de stock (`hadStockImpact`) genera
 * `waste_event` con el COGS de la línea — la pérdida queda registrada en vez
 * de evaporarse del inventario.
 */
final class StockReversalPolicy
{
    /** Motivo lazy get-or-create en `taxonomy` (taxonomyType='wasteReason'). */
    private const WASTE_REASON_NAME = 'Devolución de cliente';

    /**
     * D2: habilita OFRECER la reposición de INSUMOS de una producción directa
     * que no llegó a prepararse. Capability switch — amplía el menú de lo
     * posible, no una política que decida por el cajero. `company.config` es
     * JSONB schemaless (mig 154 no requiere DDL propia para esta clave, mismo
     * patrón que `settingSellSoldOut`).
     */
    public function settingAllowIngredientReversal(string $companyId): bool
    {
        $row = ncmExecute(
            "SELECT config->>'settingReturnAllowIngredientReversal' AS val FROM company WHERE companyid = ? LIMIT 1",
            [$companyId]
        );
        return $row !== false && $row !== null && (string) ($row['val'] ?? '') === 'yes';
    }

    /**
     * Clasifica una línea vendida contra la tabla D2 de context/40. Usa
     * `Inventory::explodeRecipe()` (explosión multi-nivel real) para decidir
     * si hay insumos que reponer.
     *
     * @param array|\ArrayAccess $f Fila con, al menos, `itemid`/`itemsoldunits`/
     *        `itemsoldtotal`/`itemsoldcogs`/`itemname` (y opcionalmente
     *        `itemsoldid` — algunos callers agregan varias filas de `itemSold`
     *        en una sola línea por itemId y no tienen un itemSoldId único que
     *        la represente; en ese caso el caller decide qué poner ahí, esta
     *        clase no lo exige).
     * @return array{itemSoldId:string,itemId:string,name:string,qty:float,unitPrice:float,unitCogs:float,kind:string,canRestock:bool,defaultRestock:bool,hadStockImpact:bool}
     */
    public function classifyLine(array|\ArrayAccess $f, string $companyId, bool $allowIngredientReversal): array
    {
        $itemId = (string) ($f['itemid'] ?? '');
        $units  = abs((float) ($f['itemsoldunits'] ?? 0));
        $total  = abs((float) ($f['itemsoldtotal'] ?? 0));
        $unitCogs = abs((float) ($f['itemsoldcogs'] ?? 0));

        // context/52 (G4) — hija de combo FIJO: NUNCA descontó stock.
        // `SaleService::persistItemsAndStock()` saltea estas líneas con
        // `continue` (son pura trazabilidad de reportes, F6 de context/41); el
        // stock físico lo descontó el PADRE explotando la receta. Si la
        // reversa las clasificara como 'ownStock' acreditaría unidades que
        // nunca se restaron Y ADEMÁS repondría los mismos insumos vía el
        // padre: doble reposición del inventario en cada anulación/devolución
        // de un combo. El discriminante persistido es `itemSold.meta`
        // (`meta.compound`, ver `SaleService::resolveItemSoldMeta()`) — NO
        // `itemSoldParent`, que las hijas de ADD-ON también llevan y esas SÍ
        // descontaron su propio stock (entran al loop de la venta como
        // cualquier otra línea).
        if (self::isCompoundChildRow($f)) {
            return [
                'itemSoldId'     => (string) ($f['itemsoldid'] ?? ''),
                'itemId'         => $itemId,
                'name'           => (string) ($f['itemname'] ?? ''),
                'qty'            => $units,
                'unitPrice'      => $units > 0 ? round($total / $units, 2) : 0.0,
                'unitCogs'       => round($unitCogs, 4),
                'kind'           => 'compoundChild',
                'canRestock'     => false,
                'defaultRestock' => false,
                // Sin impacto de stock propio => tampoco genera waste_event:
                // la merma del combo la registra la línea del PADRE con su
                // COGS completo (el de la hija es NULL a propósito).
                'hadStockImpact' => false,
            ];
        }

        $explodes = Inventory::saleExplodesRecipe($itemId, $companyId);
        $leaves   = $explodes ? Inventory::explodeRecipe($itemId, $companyId, $units) : [];

        if (!$explodes) {
            $kind = 'ownStock';
            $canRestock = true;
            $default    = true;
            $hadStockImpact = true;
        } elseif (is_array($leaves) && $leaves !== []) {
            $kind = 'ingredientReversal';
            $canRestock = $allowIngredientReversal;
            $default    = false; // D2: default visual = "a pérdida" para producción directa/combo.
            $hadStockImpact = true;
        } else {
            $kind = 'service';
            $canRestock = false;
            $default    = false;
            $hadStockImpact = false;
        }

        return [
            'itemSoldId'     => (string) ($f['itemsoldid'] ?? ''),
            'itemId'         => $itemId,
            'name'           => (string) ($f['itemname'] ?? ''),
            'qty'            => $units,
            'unitPrice'      => $units > 0 ? round($total / $units, 2) : 0.0,
            'unitCogs'       => round($unitCogs, 4),
            'kind'           => $kind,
            'canRestock'     => $canRestock,
            'defaultRestock' => $default,
            'hadStockImpact' => $hadStockImpact,
        ];
    }

    /**
     * ¿La fila de `itemSold` es una hija de combo fijo (`meta.compound`)?
     *
     * El marcador vive en la columna JSONB `itemSold.meta`. El caller debe
     * traerla en su SELECT; si no viene, se asume que NO es hija de compound
     * (las ventas anteriores a F6 de context/41 directamente no tienen estas
     * líneas, así que el default preserva su comportamiento).
     *
     * @param array|\ArrayAccess $f
     */
    private static function isCompoundChildRow(array|\ArrayAccess $f): bool
    {
        $meta = $f['meta'] ?? null;
        if ($meta === null || $meta === '') {
            return false;
        }
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        return is_array($meta) && array_key_exists('compound', $meta);
    }

    /**
     * Aplica la decisión del cajero (`$requestedLines`) sobre las opciones
     * calculadas (`$options`, shape de `classifyLine()`), clampeando a lo que
     * `canRestock` habilita — un cajero no puede pedir reponer lo que el
     * sistema marcó imposible. `$requestedLines` vacío = defaults de cada
     * línea.
     *
     * Resuelve SIEMPRE por `itemSoldId` primero (único por fila, sin
     * ambigüedad); el fallback por `itemId` solo se acepta cuando ese itemId
     * es único entre las líneas de `$options` — si hay 2+ opciones con el
     * mismo itemId y el request no distingue por `itemSoldId`, se rechaza con
     * `AmbiguousStockLineException` en vez de aplicar una decisión que no se
     * puede atribuir a una línea sin ambigüedad (P2, code review de F1+F2 de
     * `SaleVoidService`, mismo criterio acá).
     *
     * @param list<array{itemSoldId:string,itemId:string,canRestock:bool,defaultRestock:bool}> $options
     * @param list<array{itemSoldId?:?string,itemId?:?string,restock?:?bool}> $requestedLines
     * @return list<array> Cada opción de `$options` + `restock:bool`.
     * @throws AmbiguousStockLineException
     */
    public function resolveLineDecisions(array $options, array $requestedLines): array
    {
        $itemIdCounts = [];
        foreach ($options as $opt) {
            $itemIdCounts[$opt['itemId']] = ($itemIdCounts[$opt['itemId']] ?? 0) + 1;
        }

        $reqBySoldId = [];
        $reqByItemId = [];
        foreach ($requestedLines as $l) {
            $soldId  = (string) ($l['itemSoldId'] ?? '');
            $itemId  = (string) ($l['itemId'] ?? '');
            $restock = (bool) ($l['restock'] ?? false);

            if ($soldId !== '') {
                $reqBySoldId[$soldId] = $restock;
                // Fallback adicional por itemId, SOLO cuando ese itemId es
                // único entre las opciones (P1, code review de esta sesión):
                // `ReturnService` arma `$options` con UNA sola opción por
                // itemId (agregada, ver `aggregatedParentLines()`), y su
                // `itemSoldId` es un `MIN(itemsoldid)` arbitrario — un
                // cliente que reenvía un `itemSoldId` legítimo pero distinto
                // (ej. el de una fila física puntual, no el representativo)
                // no debe perder en silencio la decisión del cajero y caer a
                // `defaultRestock`. Cuando el itemId SÍ es ambiguo (2+
                // opciones, caso real de `SaleVoidService`), este fallback NO
                // se registra — ahí sigue exigiendo el `itemSoldId` exacto.
                if ($itemId !== '' && ($itemIdCounts[$itemId] ?? 0) <= 1) {
                    $reqByItemId[$itemId] = $restock;
                }
                continue;
            }
            if ($itemId === '') {
                continue;
            }
            if (($itemIdCounts[$itemId] ?? 0) > 1) {
                // NO apiError() acá — los callers corren esto DENTRO de su
                // transacción de BD (después de escrituras previas), así que
                // un `exit` directo saltearía FailTrans()/CompleteTrans().
                // Se tira una excepción catcheable; el caller decide cómo
                // responder.
                throw new AmbiguousStockLineException(
                    "Hay más de una línea del ítem {$itemId}: mandá itemSoldId por línea, itemId solo no alcanza para decidir cuál."
                );
            }
            $reqByItemId[$itemId] = $restock;
        }

        $decisions = [];
        foreach ($options as $opt) {
            if ($requestedLines === []) {
                $restock = $opt['defaultRestock'];
            } else {
                $restock = $reqBySoldId[$opt['itemSoldId']] ?? $reqByItemId[$opt['itemId']] ?? $opt['defaultRestock'];
            }
            if ($restock && !$opt['canRestock']) {
                $restock = false;
            }
            $decisions[] = $opt + ['restock' => $restock];
        }
        return $decisions;
    }

    /**
     * Repone stock de una línea ya decidida por el cajero (`$d['restock'] ===
     * true`, clampeado a `canRestock` por `resolveLineDecisions()`).
     * `$source` queda en `stock.stocksource` — cada caller usa el suyo
     * ('void' | 'return') para que el ledger distinga el origen del
     * movimiento.
     *
     * @param array{itemId:string,kind:string,qty:float,unitCogs:float} $d
     */
    public function restockLine(
        array  $d,
        string $companyId,
        string $outletId,
        string $transactionId,
        string $userId,
        string $source
    ): void {
        if ($d['kind'] === 'compoundChild') {
            // No debería llegar acá (canRestock=false lo clampea antes), pero
            // el guard es explícito: reponer una hija de combo ES la doble
            // reposición que context/52 vino a cerrar.
            return;
        }

        if ($d['kind'] === 'ownStock') {
            $locRow = ncmExecute('SELECT locationid FROM item WHERE itemid = ? AND companyid = ? LIMIT 1', [$d['itemId'], $companyId]);
            Inventory::manageStock([
                'itemId'        => $d['itemId'],
                'outletId'      => $outletId,
                'date'          => date('Y-m-d'),
                'locationId'    => $locRow['locationid'] ?? null,
                'count'         => $d['qty'],
                'type'          => '+',
                'source'        => $source,
                'transactionId' => $transactionId,
                'cogs'          => $d['unitCogs'],
                'userId'        => $userId,
                'companyId'     => $companyId,
            ]);
        } elseif ($d['kind'] === 'ingredientReversal') {
            $leaves = Inventory::explodeRecipe($d['itemId'], $companyId, $d['qty']);
            foreach ((array) $leaves as $leafItemId => $leafQty) {
                if ((float) $leafQty <= 0) {
                    continue;
                }
                $locRow = ncmExecute('SELECT locationid FROM item WHERE itemid = ? AND companyid = ? LIMIT 1', [$leafItemId, $companyId]);
                Inventory::manageStock([
                    'itemId'        => $leafItemId,
                    'outletId'      => $outletId,
                    'date'          => date('Y-m-d'),
                    'locationId'    => $locRow['locationid'] ?? null,
                    'count'         => abs((float) $leafQty),
                    'type'          => '+',
                    'source'        => $source,
                    'transactionId' => $transactionId,
                    'userId'        => $userId,
                    'companyId'     => $companyId,
                ]);
            }
        }
        // 'service': nada que reponer (ya filtrado por canRestock=false antes de llegar acá).
    }

    /**
     * Registra la merma de una línea que tuvo impacto real de stock
     * (`hadStockImpact`) y no se repuso. `$note` es el texto completo que
     * queda en `waste_event.note` — cada caller arma el suyo ("Anulación de
     * venta: …" | "Devolución de cliente: …").
     *
     * @param array{itemId:string,qty:float,unitCogs:float} $d
     */
    public function recordWaste(
        array   $d,
        string  $companyId,
        string  $outletId,
        string  $wasteReasonId,
        string  $userId,
        string  $note
    ): void {
        global $db;

        $locRow = ncmExecute('SELECT locationid FROM item WHERE itemid = ? AND companyid = ? LIMIT 1', [$d['itemId'], $companyId]);
        $cost   = round($d['unitCogs'] * $d['qty'], 2);

        $docNumber = null;
        try {
            $docNumber = DocumentNumber::allocate('merma', DocumentNumber::SCOPE_OUTLET, $outletId, $companyId);
        } catch (\Throwable $e) {
            // Sin timbrado/rango configurado para 'merma' en este outlet — la
            // merma igual se registra, sin correlativo (mismo criterio que
            // ProductionService cuando el outlet no numera mermas).
            error_log('[StockReversalPolicy] DocumentNumber::allocate(merma) falló: ' . $e->getMessage());
        }

        $db->Execute(
            "INSERT INTO waste_event (wasteid, companyid, outletid, locationid, itemid, qty, reasonid, source, cost, note, userid, docnumber)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, 'manual', ?, ?, ?, ?)",
            [
                $companyId,
                $outletId,
                $locRow['locationid'] ?? null,
                $d['itemId'],
                $d['qty'],
                $wasteReasonId,
                $cost,
                $note,
                $userId,
                $docNumber,
            ]
        );
    }

    /**
     * Get-or-create del wasteReason "Devolución de cliente" — lazy, por
     * tenant, mismo criterio que `WasteReasonService::ensureSeed()`. Se
     * asegura primero de que el catálogo default del tenant exista (así
     * convive con los 5 defaults en vez de ser la única fila), y solo si
     * después de eso "Devolución de cliente" no está, la crea.
     */
    public function getOrCreateReturnWasteReasonId(string $companyId, \DB $db): string
    {
        $existing = ncmExecute(
            "SELECT taxonomyid FROM taxonomy WHERE companyid = ? AND taxonomytype = 'wasteReason' AND taxonomyname = ? LIMIT 1",
            [$companyId, self::WASTE_REASON_NAME]
        );
        if ($existing) {
            return (string) $existing['taxonomyid'];
        }

        (new WasteReasonService($db))->ensureSeed($companyId);

        $rs = $db->Execute(
            "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
             VALUES (gen_random_uuid(), ?, 'wasteReason', ?, ?::jsonb)
             RETURNING taxonomyId",
            [$companyId, self::WASTE_REASON_NAME, json_encode(['sortOrder' => 999])]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear el motivo de merma "Devolución de cliente"');
        }
        $id = (string) ($rs->fields['taxonomyid'] ?? $rs->fields['taxonomyId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('No se pudo crear el motivo de merma "Devolución de cliente"');
        }
        return $id;
    }
}
