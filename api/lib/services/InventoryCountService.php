<?php
declare(strict_types=1);
namespace Punto\Api\Services;

require_once __DIR__ . '/InventoryCountScope.php';
require_once __DIR__ . '/../Settings/StockCountSettings.php';

use Punto\Api\Settings\StockCountSettings;

/**
 * InventoryCountService — toma física de inventario.
 *
 * El método finish() genera movimientos de ajuste via \Punto\App\Domain\Inventory::manageStock()
 * NUNCA hace INSERT/UPDATE directo en la tabla stock.
 *
 * Decisión: countedQty = NULL al finalizar se trata como "no diferencia"
 * (countedQty = expectedQty). El operador que no contó un item no genera
 * movimiento de ajuste. Esto es un default conservador — se puede cambiar
 * si el negocio prefiere ajustar a 0.
 *
 * QUÉ ítems entran en la sesión NO se decide acá: lo decide
 * `InventoryCountScope` (mig 158), compartido con el `action=preview` del
 * endpoint. Antes este método snapshoteaba todo el catálogo del tenant y el
 * outletId solo servía para la cantidad esperada — contar una sucursal traía
 * los ítems de todas las demás en 0.
 */
final class InventoryCountService
{
    /**
     * @param string[] $categoryIds  Vacío = todas las categorías.
     */
    public function create(
        string $companyId,
        string $outletId,
        ?string $locationId,
        string $startedBy,
        ?string $note,
        array $categoryIds = [],
        bool $includeZeroStock = false,
    ): array {
        global $db;

        $scope = InventoryCountScope::forRequest(
            $companyId,
            $outletId,
            $locationId,
            $categoryIds,
            $includeZeroStock,
        );
        $locationId = $scope->locationId();

        // Resolver los ítems ANTES de abrir la TX: si el alcance no incluye
        // ninguno, no queremos gastar un correlativo de documento (allocate()
        // vive dentro de la TX y el rollback lo devuelve, pero el conteo vacío
        // tampoco tiene sentido de negocio — la UI ya muestra el total con
        // action=preview antes de habilitar el botón).
        [$itemsSql, $itemsParams] = $scope->itemsQuery();
        $itemsRs = ncmExecute($itemsSql, $itemsParams, false, true);

        $lines = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $row     = $itemsRs->fields;
                $lines[] = [
                    (string) $row['itemid'],
                    (float) $row['expectedqty'],
                    (float) $row['unitcost'],
                ];
                $itemsRs->MoveNext();
            }
        }

        if ($lines === []) {
            throw new \InvalidArgumentException(
                'El alcance elegido no incluye ningún artículo. Ampliá las categorías '
                . 'o activá "Incluir artículos sin stock en la sucursal".'
            );
        }

        $db->StartTrans();

        // Correlativo del documento (F3, context/37), scope sucursal. Dentro de
        // la TX: si el conteo no persiste, el rollback devuelve el número.
        $docNumber = \Punto\Api\Documents\DocumentNumber::allocate(
            'conteo',
            \Punto\Api\Documents\DocumentNumber::SCOPE_OUTLET,
            $outletId,
            $companyId,
        );

        $sessionRow = ncmExecute(
            'INSERT INTO inventory_count (companyid, outletid, locationid, startedby, "note", docnumber, scope)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb) RETURNING inventorycountid',
            [$companyId, $outletId, $locationId, $startedBy, $note ?: null, $docNumber, $scope->toJson()]
        );

        if (!$sessionRow || empty($sessionRow['inventoryCountId'])) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la sesión de conteo');
        }

        $countId = $sessionRow['inventoryCountId'];

        foreach ($lines as [$itemId, $onHand, $cogs]) {
            ncmExecute(
                'INSERT INTO inventory_count_item (inventorycountid, itemid, expectedqty, unitcost)
                 VALUES (?, ?, ?, ?)',
                [$countId, $itemId, $onHand, $cogs]
            );
        }

        $db->CompleteTrans();

        return ['id' => $countId, 'itemCount' => count($lines)];
    }

    /**
     * Cuántos artículos entrarían con este alcance, sin crear la sesión.
     * Alimenta el "vas a contar N artículos" del diálogo — mismo predicado
     * que usa create(), por construcción.
     *
     * @param string[] $categoryIds
     */
    public function preview(
        string $companyId,
        string $outletId,
        ?string $locationId,
        array $categoryIds = [],
        bool $includeZeroStock = false,
    ): array {
        $scope = InventoryCountScope::forRequest(
            $companyId,
            $outletId,
            $locationId,
            $categoryIds,
            $includeZeroStock,
        );

        [$sql, $params] = $scope->countQuery();
        $row = ncmExecute($sql, $params);

        return ['count' => (int) ($row['total'] ?? 0)];
    }

    public function get(string $id, string $companyId): ?array
    {
        $session = ncmExecute(
            'SELECT ic.*, u1.contactname as "startedByName", u2.contactname as "finishedByName"
             FROM inventory_count ic
             LEFT JOIN contact u1 ON u1.contactid = ic.startedby
             LEFT JOIN contact u2 ON u2.contactid = ic.finishedby
             WHERE ic.inventorycountid = ? AND ic.companyid = ?
             LIMIT 1',
            [$id, $companyId]
        );

        if (!$session) {
            return null;
        }

        // Categoría por línea: el m2m `item_category` manda (con la isPrimary
        // adelante) y la FK legacy `item.categoryid` es el fallback para los
        // ítems viejos que nunca se guardaron por el panel nuevo. Es el mismo
        // doble camino que usa InventoryCountScope para filtrar — si el filtro
        // metió un ítem por la legacy, la fila tiene que poder mostrarla.
        $itemsRs = ncmExecute(
            'SELECT ici.inventorycountitemid, ici.itemid, i.itemname as name, i.itemsku as sku,
                    ici.expectedqty, ici.countedqty, ici."difference", ici.unitcost,
                    ici.countedat, ici.countedby,
                    COALESCE(pc.categoryid, lc.categoryid) as "categoryId",
                    COALESCE(pc.name, lc.name)             as "categoryName"
             FROM inventory_count_item ici
             JOIN item i ON i.itemid = ici.itemid
             LEFT JOIN LATERAL (
                  SELECT c.categoryid, c.name
                    FROM item_category ic
                    JOIN category c ON c.categoryid = ic.categoryid
                   WHERE ic.itemid = i.itemid
                   ORDER BY ic.isprimary DESC NULLS LAST, c.name ASC
                   LIMIT 1
             ) pc ON true
             LEFT JOIN category lc ON lc.categoryid = i.categoryid
             WHERE ici.inventorycountid = ?
             ORDER BY i.itemname ASC',
            [$id],
            false,
            true
        );

        // Conteo ciego (D2 de context/63): mientras la sesión está EN PROGRESO
        // el esperado y la diferencia no salen del servidor. No es que la
        // pantalla no los pinte —eso se evade mirando la respuesta— es que no
        // se mandan, igual que `drawerBlind` con el total del arqueo.
        //
        // Al FINALIZAR sí se muestran, y tiene que ser así: el owner pidió
        // textualmente que "cada conteo finalizado debe quedar detallado en el
        // panel con sus diferencias". Ciego describe el momento de contar, no
        // el registro que queda.
        $blind = StockCountSettings::forCompany($companyId)->blind()
            && (int) $session['status'] === 1;

        $items = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $row      = $itemsRs->fields;
                $items[]  = [
                    'inventoryCountItemId' => $row['inventoryCountItemId'],
                    'itemId'      => $row['itemId'],
                    'name'        => $row['name'],
                    'sku'         => $row['sku'],
                    'categoryId'   => $row['categoryId'],
                    'categoryName' => $row['categoryName'],
                    'expectedQty' => $blind ? null : (float) $row['expectedQty'],
                    'countedQty'  => $row['countedQty'] !== null ? (float) $row['countedQty'] : null,
                    'difference'  => ($blind || $row['difference'] === null) ? null : (float) $row['difference'],
                    // El costo unitario no revela el esperado (es el costo del
                    // artículo, no una cantidad) pero SÍ compone el valor de la
                    // diferencia, que sin diferencia no se puede calcular.
                    'unitCost'    => (float) $row['unitCost'],
                    'countedAt'   => $row['countedAt'],
                ];
                $itemsRs->MoveNext();
            }
        }

        return [
            'session' => [
                'inventoryCountId' => $session['inventoryCountId'],
                // Correlativo del documento (mig 129).
                'docNumber'        => isset($session['docNumber']) ? (int) $session['docNumber'] : null,
                'companyId'        => $session['companyId'],
                'outletId'         => $session['outletId'],
                'locationId'       => $session['locationId'],
                'status'           => (int) $session['status'],
                'note'             => $session['note'],
                'startedAt'        => $session['startedAt'],
                'finishedAt'       => $session['finishedAt'],
                'startedBy'        => $session['startedBy'],
                'startedByName'    => $session['startedByName'],
                'finishedBy'       => $session['finishedBy'],
                'finishedByName'   => $session['finishedByName'],
                // Alcance con el que se abrió (mig 158). El cast a object es
                // necesario: un array PHP vacío serializa como `[]` y el
                // cliente espera un objeto — las sesiones anteriores a la
                // migración devuelven `{}` (alcance desconocido: se
                // snapshoteaba todo el tenant), no una lista vacía.
                'scope'            => (object) InventoryCountScope::decode($session['scope'] ?? null),
                // Por qué el esperado viene en null. Sin este flag la pantalla
                // no puede distinguir "conteo ciego" de "dato faltante", y el
                // copy de finalizar prometería una diferencia que no tiene.
                'blind'            => $blind,
            ],
            'items' => $items,
        ];
    }

    public function setCountedQty(string $countId, string $itemId, float $qty, string $userId, string $companyId): bool
    {
        global $db;

        $session = ncmExecute(
            'SELECT inventorycountid FROM inventory_count WHERE inventorycountid = ? AND companyid = ? AND "status" = 1 LIMIT 1',
            [$countId, $companyId]
        );
        if (!$session) {
            return false;
        }

        $db->Execute(
            'UPDATE inventory_count_item SET countedqty = ?, countedat = NOW(), countedby = ?
             WHERE inventorycountid = ? AND itemid = ?',
            [$qty, $userId, $countId, $itemId]
        );

        return (int) $db->Affected_Rows() > 0;
    }

    public function bulkSetCountedQty(string $countId, array $rows, string $userId, string $companyId): int
    {
        $session = ncmExecute(
            'SELECT inventorycountid FROM inventory_count WHERE inventorycountid = ? AND companyid = ? AND "status" = 1 LIMIT 1',
            [$countId, $companyId]
        );
        if (!$session) {
            return 0;
        }

        global $db;
        $count = 0;
        foreach ($rows as $row) {
            $db->Execute(
                'UPDATE inventory_count_item SET countedqty = ?, countedat = NOW(), countedby = ?
                 WHERE inventorycountid = ? AND itemid = ?',
                [$row['qty'], $userId, $countId, $row['itemId']]
            );
            $count += (int) $db->Affected_Rows();
        }
        return $count;
    }

    public function finish(string $id, string $companyId, string $finishedBy): array
    {
        global $db;

        $db->StartTrans();

        $session = ncmExecute(
            'SELECT * FROM inventory_count WHERE inventorycountid = ? AND companyid = ? FOR UPDATE',
            [$id, $companyId]
        );

        if (!$session) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('Sesión no encontrada');
        }

        if ((int) $session['status'] !== 1) {
            $db->FailTrans();
            $db->CompleteTrans();
            $statusMap = [0 => 'cancelada', 2 => 'ya finalizada'];
            $label     = $statusMap[(int) $session['status']] ?? 'inválida';
            throw new \RuntimeException("La sesión está {$label}", 409);
        }

        $linesRs = ncmExecute(
            'SELECT itemid, expectedqty, countedqty, "difference", unitcost
             FROM inventory_count_item
             WHERE inventorycountid = ? AND countedqty IS NOT NULL AND "difference" != 0',
            [$id],
            false,
            true
        );

        // D9 de context/63 — el comercio puede configurar que el conteo NO
        // toque el stock y quede solo como registro. En ese modo las líneas se
        // recorren igual (el resumen que se devuelve es el mismo dato) pero no
        // se llama a `manageStock()`: las diferencias quedan en
        // `inventory_count_item` para consultarlas en el panel.
        //
        // El flag se lee del COMERCIO, nunca del cliente: ni la caja ni el
        // panel pueden pedir "esta vez no ajustes".
        $recordOnly = StockCountSettings::forCompany($companyId)->recordOnly();

        $adjustmentsCount = 0;
        $totalCostDelta   = 0.0;
        $outletId         = $session['outletId'];
        $locationId       = $session['locationId'] ?: null;

        if ($linesRs) {
            while (!$linesRs->EOF) {
                $row        = $linesRs->fields;
                $itemId     = $row['itemId'];
                $difference = (float) $row['difference'];
                $unitCost   = (float) $row['unitCost'];
                $type       = $difference > 0 ? '+' : '-';

                if (!$recordOnly) {
                    \Punto\App\Domain\Inventory::manageStock([
                        'itemId'        => $itemId,
                        'source'        => 'inventory_count',
                        'count'         => abs($difference),
                        'type'          => $type,
                        'cogs'          => $unitCost,
                        'userId'        => $finishedBy,
                        'transactionId' => null,
                        'outletId'      => $outletId,
                        'locationId'    => $locationId,
                        'note'          => "count #{$id}",
                        'date'          => date('Y-m-d H:i:s'),
                        'companyId'     => $companyId,
                    ]);
                }

                $totalCostDelta   += $difference * $unitCost;
                $adjustmentsCount++;
                $linesRs->MoveNext();
            }
        }

        $db->Execute(
            'UPDATE inventory_count SET "status" = 2, finishedat = NOW(), finishedby = ? WHERE inventorycountid = ?',
            [$finishedBy, $id]
        );

        $db->CompleteTrans();

        // El publish de 'item' ahora vive en Inventory::manageStock() (única
        // puerta de todo movimiento de stock, dedup por request) — este caller
        // ya no necesita avisar a mano. Ver context/15-realtime-sync-plan.md.

        return [
            // Sigue siendo "cuántas líneas difieren", no "cuántos movimientos
            // se escribieron" — por eso viaja `applied`. Sin ese flag la
            // pantalla diría "3 ajustes aplicados" en un comercio donde no se
            // aplicó ninguno.
            'adjustmentsCount' => $adjustmentsCount,
            'totalCostDelta'   => $totalCostDelta,
            'applied'          => !$recordOnly,
        ];
    }

    /**
     * Un conteo COMPLETO hecho desde la caja, en una sola operación.
     *
     * ── Por qué el grano es el conteo entero y no cada cantidad ─────────────
     *
     * Porque la caja cuenta SIN RED y encola (`context/51` §2), y encolar
     * `setQty` una por una no se puede: son *last-write-wins* sin versión ni
     * `opId`, así que reenviarlas rompería la garantía que la cola promete en
     * todo lo demás. Envolverlas tampoco alcanzaría — el problema no es el
     * transporte, es que "poné 7 en este ítem" no tiene identidad.
     *
     * Un conteo ciego sobre una lista fija se carga de una sentada: el cajero
     * recorre el mostrador y confirma. Ese hecho —"conté esto"— es la unidad
     * real de la operación, y es la que se encola. Adentro pasan tres cosas
     * (crear la sesión, cargar las cantidades, finalizar) pero afuera es una
     * sola, atómica: o quedó el conteo entero o no quedó nada.
     *
     * ── Idempotencia ───────────────────────────────────────────────────────
     *
     * El caso a sobrevivir es el silencioso: la request llegó, se aplicó, y la
     * respuesta se perdió. El device reintenta con el MISMO `opId` y tiene que
     * obtener el mismo resultado, no un segundo conteo con un segundo ajuste
     * de stock.
     *
     * El `opId` se persiste en la fila que la operación crea, con un índice
     * único por comercio (mig 186): el reenvío encuentra su propio conteo y
     * devuelve su resumen, recalculado de las líneas que ya están guardadas.
     * No hace falta guardar la respuesta —es derivable— ni una tabla de
     * "operaciones ya vistas".
     *
     * La carrera de dos reenvíos simultáneos la resuelve el índice: uno gana,
     * el otro choca con 23505 y cae en la misma rama que el reenvío tardío.
     *
     * ── Qué manda el cliente y qué manda el servidor ────────────────────────
     *
     * El cliente manda `listId` y las cantidades. La LISTA sale del comercio
     * (`StockCountSettings`), no del payload: la caja no elige qué se cuenta.
     * Si el dueño editó la lista entre que el cajero contó y que la operación
     * sincronizó, manda la del servidor — misma regla de conflicto que
     * `context/51` §5. Un ítem contado que ya no está en la lista se descarta;
     * uno de la lista que no se contó queda en NULL, que `finish()` ya trata
     * como "sin diferencia".
     *
     * La excepción es la lista BORRADA. Ahí se cuenta con los ítems que mandó
     * el device (validados igual contra el tenant) y se deja anotado en el
     * alcance. Rechazar sería tirar a la basura un recuento físico que ya
     * ocurrió por un cambio de configuración posterior — el mismo criterio por
     * el que el back nunca rechaza una venta ya emitida.
     *
     * @param array<string, float> $countedByItemId itemId => cantidad contada
     * @return array{id: string, docNumber: ?int, adjustmentsCount: int,
     *               totalCostDelta: float, applied: bool, duplicate: bool}
     */
    public function submitFromRegister(
        string $companyId,
        string $outletId,
        string $operatorId,
        string $opId,
        string $listId,
        string $listNameFallback,
        array $countedByItemId,
        ?array $itemIdsFallback,
        ?string $registerId,
        ?string $countedAt,
        ?string $note,
    ): array {
        global $db;

        // Reenvío: la operación ya se aplicó. Se contesta ANTES de tocar nada
        // —sin abrir transacción, sin gastar un correlativo— con el resumen
        // recalculado de lo que quedó guardado.
        $existing = $this->findByOpId($companyId, $opId);
        if ($existing !== null) {
            return $existing;
        }

        $settings = StockCountSettings::forCompany($companyId);
        $list     = $settings->findList($listId);

        if ($list !== null) {
            $scopeItemIds = $list['itemIds'];
            $listName     = $list['name'];
        } else {
            // Lista borrada o renombrada fuera de existencia mientras el conteo
            // viajaba. Se conserva el recuento con lo que el device conocía.
            $scopeItemIds = array_values(array_filter(
                array_map(static fn ($v) => trim((string) $v), $itemIdsFallback ?? []),
                static fn (string $v) => $v !== ''
            ));
            $listName = $listNameFallback !== '' ? $listNameFallback : 'Lista eliminada';
            if ($scopeItemIds === []) {
                // Sin lista del servidor ni ítems del device no hay conteo que
                // reconstruir: lo único honesto es decirlo.
                throw new \InvalidArgumentException(
                    'La lista de conteo ya no existe y la operación no trae los artículos que se contaron'
                );
            }
        }

        $scope = InventoryCountScope::forFixedList(
            $companyId,
            $outletId,
            $listId,
            $listName,
            $scopeItemIds,
        );

        // Ítems + esperado + costo, congelados con el MISMO query que usa el
        // conteo del panel. El esperado se resuelve ACÁ, en el servidor y al
        // sincronizar: la caja no lo tiene (cuenta a ciegas) y no debería —
        // el saldo pudo moverse entre que se contó y que llegó la operación,
        // y el que vale es el del momento en que se aplica el ajuste.
        [$itemsSql, $itemsParams] = $scope->itemsQuery();
        $itemsRs = ncmExecute($itemsSql, $itemsParams, false, true);

        $lines = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $row     = $itemsRs->fields;
                $lines[] = [
                    (string) $row['itemid'],
                    (float) $row['expectedqty'],
                    (float) $row['unitcost'],
                ];
                $itemsRs->MoveNext();
            }
        }

        if ($lines === []) {
            throw new \InvalidArgumentException(
                'Ninguno de los artículos de la lista sigue activo y con control de stock'
            );
        }

        $drawerId = $this->resolveDrawerContext($companyId, $registerId, $countedAt);

        $db->StartTrans();

        try {
            $docNumber = \Punto\Api\Documents\DocumentNumber::allocate(
                'conteo',
                \Punto\Api\Documents\DocumentNumber::SCOPE_OUTLET,
                $outletId,
                $companyId,
            );

            $sessionRow = ncmExecute(
                'INSERT INTO inventory_count
                    (companyid, outletid, locationid, startedby, "note", docnumber, scope,
                     opid, registerid, drawerid)
                 VALUES (?, ?, NULL, ?, ?, ?, ?::jsonb, ?, ?, ?)
                 RETURNING inventorycountid',
                [
                    $companyId, $outletId, $operatorId, $note ?: null, $docNumber,
                    $scope->toJson(), $opId, $registerId ?: null, $drawerId ?: null,
                ]
            );

            if (!$sessionRow || empty($sessionRow['inventoryCountId'])) {
                throw new \RuntimeException('No se pudo crear la sesión de conteo');
            }

            $countId = (string) $sessionRow['inventoryCountId'];

            // Las cantidades entran con la línea, no con un UPDATE posterior:
            // el conteo nace completo. `countedby` es el OPERADOR del PIN — el
            // conteo queda atribuido a la persona, nunca al dispositivo.
            foreach ($lines as [$itemId, $expected, $unitCost]) {
                $counted = array_key_exists($itemId, $countedByItemId)
                    ? (float) $countedByItemId[$itemId]
                    : null;

                ncmExecute(
                    'INSERT INTO inventory_count_item
                        (inventorycountid, itemid, expectedqty, unitcost, countedqty, countedat, countedby)
                     VALUES (?, ?, ?, ?, ?, ' . ($counted === null ? 'NULL' : 'NOW()') . ', ?)',
                    [$countId, $itemId, $expected, $unitCost, $counted, $counted === null ? null : $operatorId]
                );
            }

            // Finalizar dentro de la MISMA transacción (StartTrans anida por
            // profundidad, ver DB::StartTrans): el ajuste de stock y la sesión
            // que lo justifica commitean juntos o no commitea ninguno.
            $result = $this->finish($countId, $companyId, $operatorId);

            $db->CompleteTrans();
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();

            // Carrera de dos reenvíos del mismo `opId`: el índice único de la
            // mig 186 dejó pasar uno solo. El perdedor no falló —la operación
            // SÍ se aplicó, la aplicó el otro— así que contesta lo mismo que
            // habría contestado un reenvío tardío.
            if ($this->isOpIdConflict($e)) {
                $applied = $this->findByOpId($companyId, $opId);
                if ($applied !== null) {
                    return $applied;
                }
            }
            throw $e;
        }

        return [
            'id'               => $countId,
            'docNumber'        => $docNumber !== null ? (int) $docNumber : null,
            'adjustmentsCount' => (int) $result['adjustmentsCount'],
            'totalCostDelta'   => (float) $result['totalCostDelta'],
            'applied'          => (bool) $result['applied'],
            'duplicate'        => false,
        ];
    }

    /**
     * En qué TURNO se hizo el conteo. Dato de contexto (mig 186): el conteo no
     * depende del turno ni lo condiciona.
     *
     * Lo resuelve el servidor y no el device por una razón concreta: el device
     * no conoce el `drawerId`, y la caja tiene UN turno abierto a la vez
     * (`uidx_drawer_register_open`), así que el dato está acá. Pero "el turno
     * abierto ahora" sería la respuesta equivocada para un conteo que esperó en
     * la cola hasta después del relevo: se lo colgaría al turno del cajero
     * siguiente, que no contó nada.
     *
     * Por eso el filtro es contra el momento en que se CONTÓ: solo cuenta el
     * turno que ya estaba abierto entonces. Si el conteo llegó tarde y ese
     * turno ya cerró, no hay match y la columna queda en NULL — que es la
     * respuesta honesta, no una pérdida de dato.
     */
    private function resolveDrawerContext(
        string $companyId,
        ?string $registerId,
        ?string $countedAt,
    ): ?string {
        if ($registerId === null || $registerId === '' || $countedAt === null || $countedAt === '') {
            return null;
        }

        $row = ncmExecute(
            'SELECT drawerid FROM drawer
              WHERE companyid = ? AND registerid = ?
                AND drawerclosedate IS NULL
                AND draweropendate <= ?::timestamptz
              LIMIT 1',
            [$companyId, $registerId, $countedAt]
        );

        return ($row && !empty($row['drawerId'])) ? (string) $row['drawerId'] : null;
    }

    /**
     * Resumen de un conteo ya aplicado, buscado por su `opId`. Se RECALCULA de
     * las líneas guardadas en vez de leerse de una respuesta congelada: el
     * dato ya está persistido y duplicarlo sería una segunda fuente de verdad
     * que puede divergir.
     *
     * @return array{id: string, docNumber: ?int, adjustmentsCount: int,
     *               totalCostDelta: float, applied: bool, duplicate: bool}|null
     */
    private function findByOpId(string $companyId, string $opId): ?array
    {
        $row = ncmExecute(
            'SELECT inventorycountid, docnumber FROM inventory_count
              WHERE companyid = ? AND opid = ? LIMIT 1',
            [$companyId, $opId]
        );
        if (!$row || empty($row['inventoryCountId'])) {
            return null;
        }

        $countId = (string) $row['inventoryCountId'];
        $totals  = ncmExecute(
            'SELECT COUNT(*) AS n, COALESCE(SUM("difference" * unitcost), 0) AS delta
               FROM inventory_count_item
              WHERE inventorycountid = ? AND countedqty IS NOT NULL AND "difference" != 0',
            [$countId]
        );

        return [
            'id'               => $countId,
            'docNumber'        => isset($row['docNumber']) ? (int) $row['docNumber'] : null,
            'adjustmentsCount' => (int) ($totals['n'] ?? 0),
            'totalCostDelta'   => (float) ($totals['delta'] ?? 0),
            'applied'          => !StockCountSettings::forCompany($companyId)->recordOnly(),
            // Lo que hace que el cliente pueda distinguir "se aplicó recién" de
            // "ya estaba aplicado". Para la cola las dos son éxito, y esa es la
            // idea; para los logs no son lo mismo.
            'duplicate'        => true,
        ];
    }

    /** ¿El error viene del índice único de `opid` (mig 186)? */
    private function isOpIdConflict(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'uidx_inventory_count_company_opid')
            || ($e instanceof \Punto\Api\Support\DbQueryException && $e->sqlState() === '23505');
    }

    public function cancel(string $id, string $companyId): bool
    {
        $session = ncmExecute(
            'SELECT "status" FROM inventory_count WHERE inventorycountid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );

        if (!$session) {
            return false;
        }

        if ((int) $session['status'] === 2) {
            throw new \RuntimeException('No se puede cancelar una sesión ya finalizada', 409);
        }

        global $db;
        $db->Execute(
            'UPDATE inventory_count SET "status" = 0 WHERE inventorycountid = ? AND companyid = ? AND "status" = 1',
            [$id, $companyId]
        );

        return (int) $db->Affected_Rows() > 0;
    }

    public function list(string $companyId, ?string $outletId, ?int $status, int $limit, int $offset): array
    {
        // Columnas CALIFICADAS con el alias `ic`, y el mismo alias en el COUNT.
        //
        // Sin calificar, este WHERE es válido para el COUNT (una sola tabla) y
        // ROMPE en la query de filas, que joinea `outlet o`: `companyid` y
        // `outletid` existen en las dos tablas y Postgres corta con 42702.
        // Nunca saltó porque el único caller —el listado del panel— no manda
        // `outletId`, así que la rama con el filtro no se ejercitaba; la
        // encontró el arnés del conteo desde la caja.
        $where  = ['ic.companyid = ?'];
        $params = [$companyId];

        if ($outletId !== null) {
            $where[]  = 'ic.outletid = ?';
            $params[] = $outletId;
        }

        if ($status !== null) {
            $where[]  = 'ic."status" = ?';
            $params[] = $status;
        }

        $whereStr = implode(' AND ', $where);

        $totalRow = ncmExecute(
            "SELECT COUNT(*) as total FROM inventory_count ic WHERE {$whereStr}",
            $params
        );
        $total = (int) ($totalRow['total'] ?? 0);

        $countParams = array_merge($params, [$limit, $offset]);
        $rowsRs = ncmExecute(
            "SELECT ic.inventorycountid, ic.docnumber, ic.outletid, ic.locationid, ic.\"status\",
                    ic.startedat, ic.finishedat, ic.\"note\",
                    o.outletname,
                    t.taxonomyname as \"locationName\",
                    (SELECT COUNT(*) FROM inventory_count_item ici WHERE ici.inventorycountid = ic.inventorycountid) as \"totalItems\",
                    (SELECT COUNT(*) FROM inventory_count_item ici WHERE ici.inventorycountid = ic.inventorycountid AND ici.countedqty IS NOT NULL) as \"countedItems\",
                    (SELECT COALESCE(SUM(ici.\"difference\" * ici.unitcost), 0) FROM inventory_count_item ici WHERE ici.inventorycountid = ic.inventorycountid AND ici.countedqty IS NOT NULL AND ici.\"difference\" IS NOT NULL) as \"totalCostDelta\"
             FROM inventory_count ic
             JOIN outlet o ON o.outletid = ic.outletid
             LEFT JOIN taxonomy t ON t.taxonomyid = ic.locationid
             WHERE {$whereStr}
             ORDER BY ic.startedat DESC
             LIMIT ? OFFSET ?",
            $countParams,
            false,
            true
        );

        // Mismo criterio que get(): con conteo ciego, una sesión EN PROGRESO no
        // publica su diferencia acumulada. Publicarla acá sería la puerta de
        // atrás — el cajero abre el listado y deduce el esperado del artículo
        // que acaba de cargar.
        $blind = StockCountSettings::forCompany($companyId)->blind();

        $rows = [];
        if ($rowsRs) {
            while (!$rowsRs->EOF) {
                $r      = $rowsRs->fields;
                $rows[] = [
                    'inventoryCountId' => $r['inventoryCountId'],
                    'docNumber'        => isset($r['docNumber']) ? (int) $r['docNumber'] : null,
                    'outletId'         => $r['outletId'],
                    'outletName'       => $r['outletname'],
                    'locationId'       => $r['locationId'],
                    'locationName'     => $r['locationName'],
                    'status'           => (int) $r['status'],
                    'startedAt'        => $r['startedAt'],
                    'finishedAt'       => $r['finishedAt'],
                    'note'             => $r['note'],
                    'totalItems'       => (int) $r['totalItems'],
                    'countedItems'     => (int) $r['countedItems'],
                    'totalCostDelta'   => ($blind && (int) $r['status'] === 1)
                        ? null
                        : (float) $r['totalCostDelta'],
                ];
                $rowsRs->MoveNext();
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
