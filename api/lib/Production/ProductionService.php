<?php
declare(strict_types=1);

namespace Punto\Api\Production;

use Punto\Api\Documents\DocumentNumber;
use Punto\App\Domain\Inventory;
use Punto\App\Domain\RecipeCosting;

/**
 * ProductionService — órdenes de fabricación (F1, context/23-production-module-plan.md).
 *
 * Modelo: `production_order` (mig 76) con estados draft→in_progress→
 * completed|cancelled. Dos flujos:
 *   - "Producir ahora" (mode='immediate' en create()): crea la orden y la
 *     completa en la misma llamada, atómico.
 *   - Orden con seguimiento: create() (draft) → start() (in_progress,
 *     opcional) → complete().
 *
 * Semántica de completado (v1, SIN completado parcial — ver context/23):
 *   - La base de consumo de insumos es `qtyplanned` (el lote completo
 *     intentado), NO `qtyProduced`. Se asume que el lote se ejecuta
 *     entero — lo que varía es cuántas unidades del lote resultaron OK
 *     (`qtyProduced`, entra a stock) vs falladas (`wasteUnits`, NO entra a
 *     stock, se registra como `waste_event` con source='production').
 *   - Por insumo: `need = qtyPerUnit × qtyplanned`, ajustado por el %
 *     de merma PLANIFICADA del insumo (`item.itemWaste`, vía
 *     `Inventory::getNeedWithWaste`) — separado del `wasteUnits` de la
 *     orden (eso es merma del PRODUCTO terminado, no del insumo). Si
 *     `ingredientAdjustments` trae `actualQty` para ese insumo, ese valor
 *     pisa el cálculo teórico (consumo real medido).
 *   - `unitcogs = ingredientcost / qtyProduced` — costeo real, no el
 *     planificado del catálogo.
 *   - Idempotencia: completar una orden que ya está `completed`/`cancelled`
 *     es un error explícito (nunca dobla el stock).
 *
 * Sub-recetas: depende de si el insumo lleva stock propio.
 *   - Insumo de **producción previa** (lleva su propio stock terminado): se
 *     consume tal cual, no se baja a su receta — ya se descontó al producirlo.
 *   - Insumo **sin stock propio pero con receta** (sub-preparación): SÍ se
 *     explota recursivamente (`Inventory::explodeRecipeDetailed`) hasta las
 *     hojas reales. Recorrer un solo nivel la dejaba sin consumir nada.
 *   (El docblock decía "no hay explosión recursiva en v1" — quedó viejo
 *   respecto del código que ya tenía debajo; corregido 2026-08-22, era un
 *   hallazgo de `context/modules/06-produccion.md` §3.3.)
 *
 * context/52 (G11): `Inventory::manageStock()` LANZA si el INSERT en el ledger
 * falla; `false` quedó reservado para el no-op legítimo (insumo sin
 * `itemTrackInventory`: agua, sal, condimentos). Los guards `$tracked` de esta
 * clase ya evitan llamarlo en ese caso, así que los viejos `=== false` +
 * FailTrans se retiraron — solo podían disparar sobre un no-op.
 *
 * Costeo: la valuación de cada insumo (promedio ponderado del ledger de la
 * sucursal, fallback a `item.itemCost`) NO se implementa acá — sale de
 * `RecipeCosting`, la única fórmula de costo de receta del sistema, así que
 * esta orden y la venta del mismo ítem dan el mismo número.
 */
final class ProductionService
{
    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(string $companyId, array $filters = []): array
    {
        // TODAS las columnas van calificadas con `po.`: `production_order` e
        // `item` comparten `companyid`, `created_at` y `itemid`, así que un
        // `WHERE companyid = ?` suelto es ambiguo y PG aborta la query entera
        // ("column reference companyid is ambiguous"). El listado quedaba
        // SIEMPRE vacío — parecía "no hay órdenes" y era un error de SQL.
        // Mismo criterio que listWaste(), que ya calificaba todo con `we.`.
        $where  = ['po.companyid = ?'];
        $params = [$companyId];

        if (!empty($filters['status'])) {
            $where[]  = 'po.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['outletId'])) {
            $where[]  = 'po.outletid = ?';
            $params[] = (string) $filters['outletId'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'po.created_at >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'po.created_at <= ?';
            $params[] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[]  = 'po.itemid IN (SELECT itemid FROM item WHERE companyid = ? AND itemname ILIKE ?)';
            $params[] = $companyId;
            $params[] = '%' . $filters['q'] . '%';
        }

        $sql = 'SELECT po.*, i.itemname AS itemname
                  FROM production_order po
                  JOIN item i ON i.itemid = po.itemid
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY po.created_at DESC
                 LIMIT 500';

        // Un error de query NO es "no hay órdenes": `Execute` traga la
        // PDOException y devuelve false, y devolver [] acá convertía un SQL
        // roto en un listado vacío perfectamente creíble. Es exactamente lo que
        // escondió el bug de arriba. Que reviente y llegue a GlitchTip.
        $rs = $this->db->Execute($sql, $params);
        if ($rs === false) {
            throw new \RuntimeException(
                'No se pudo listar las órdenes de producción: ' . $this->db->ErrorMsg()
            );
        }
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT po.*, i.itemname AS itemname
               FROM production_order po
               JOIN item i ON i.itemid = po.itemid
              WHERE po.orderid = ? AND po.companyid = ?
              LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    /**
     * Capacidad de producción: mínimo de unidades fabricables dado el stock
     * actual de insumos (respeta merma % planificada por insumo).
     */
    public function capacity(string $companyId, string $itemId, string $outletId): array
    {
        $item = ncmExecute(
            'SELECT itemid FROM item WHERE itemid = ? AND companyid = ? AND itemstatus = 1 LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item) {
            throw new \InvalidArgumentException('itemId inválido para este tenant');
        }

        $recipe = Inventory::getCompoundsArray($itemId);
        if (!is_array($recipe) || $recipe === []) {
            return ['capacity' => 0, 'ingredients' => []];
        }

        $inventory   = [];
        $waste       = [];
        $ingredients = [];
        $trackedRecipe = [];

        foreach ($recipe as $ing) {
            $childId = $ing['compoundId'];

            $childRow = ncmExecute(
                // `itemWaste` vive en el JSONB `data`, no es columna (demote de item):
                // leerlo como columna tira 42703 y aborta la TX entera. El CASE
                // filtra por tipo antes de castear — hay ítems con el valor
                // guardado como booleano y un `::numeric` suelto revienta.
                "SELECT CASE WHEN jsonb_typeof(data->'itemWaste') = 'number'
                          THEN (data->>'itemWaste')::numeric ELSE 0 END AS itemwaste,
                        itemtrackinventory
                   FROM item WHERE itemid = ? AND companyid = ? LIMIT 1",
                [$childId, $companyId]
            );
            $wasteP  = $childRow ? (float) ($childRow['itemwaste'] ?? 0) : 0.0;
            $tracked = $childRow ? !empty($childRow['itemtrackinventory']) : false;

            // Insumo sin control de stock (insumo_sin_stock: agua, sal,
            // condimentos) NO limita la capacidad — su "stock" es infinito a
            // efectos de producción. Se excluye del cálculo (si entrara con
            // onHand=0 forzaría capacidad 0 para toda la receta).
            if (!$tracked) {
                $ingredients[] = [
                    'itemId'       => $childId,
                    'qtyPerUnit'   => (float) $ing['toCompoundQty'],
                    'onHand'       => null,
                    'wastePercent' => $wasteP,
                    'tracked'      => false,
                ];
                continue;
            }

            // F1 de context/52 — el saldo sale del LEDGER (`SUM(stockCount)`),
            // no del snapshot `stockOnHand` de la última fila: con un insumo
            // comprado con fecha retroactiva el snapshot queda viejo y la
            // capacidad se calculaba contra un stock que no existía (o se
            // negaba a producir teniendo insumos). El tab Stock ya mostraba el
            // SUM — la capacidad decía otra cosa para el mismo insumo.
            $onHand = Inventory::onHand($childId, $outletId);
            $inventory[$childId] = ['onHand' => $onHand];
            $waste[$childId]     = $wasteP;
            $trackedRecipe[]     = $ing;

            $ingredients[] = [
                'itemId'       => $childId,
                'qtyPerUnit'   => (float) $ing['toCompoundQty'],
                'onHand'       => $onHand,
                'wastePercent' => $wasteP,
                'tracked'      => true,
            ];
        }

        // Receta compuesta SOLO por insumos no-stockeables → capacidad
        // ilimitada en la práctica; devolvemos null (el front decide cómo
        // presentarlo) en vez de 0 (que significaría "no se puede producir").
        if ($trackedRecipe === []) {
            return ['capacity' => null, 'ingredients' => $ingredients];
        }

        // Inventory::getProductionCapacity ya divide con Math::divide (0 si el
        // denominador es 0) — no hay riesgo de división por cero acá.
        $capacity = Inventory::getProductionCapacity($trackedRecipe, $inventory, $waste);

        return ['capacity' => $capacity, 'ingredients' => $ingredients];
    }

    /**
     * Crea una orden. mode='draft' (default) la deja en status draft;
     * mode='immediate' crea y llama a complete() en la misma llamada a
     * create(). NO es una única transacción de punta a punta — el INSERT
     * de la orden (draft) corre en autocommit y complete() abre su propia
     * TX aparte (este DB wrapper no soporta transacciones anidadas). Si
     * complete() falla, la orden NO queda a medio completar ni con stock
     * a medias (complete() hace rollback de la suya), pero SÍ queda un
     * row en status='draft' huérfano — recuperable (reintentar complete()
     * o cancel()), nunca corrupto. Trade-off documentado, no un bug.
     *
     * @return string orderId
     */
    public function create(string $companyId, string $userId, array $data): string
    {
        $itemId           = (string) ($data['itemId'] ?? '');
        $outletId         = (string) ($data['outletId'] ?? '');
        $qtyPlanned       = (float) ($data['qtyPlanned'] ?? 0);
        $locationId       = !empty($data['locationId']) ? (string) $data['locationId'] : null;
        $outputLocationId = !empty($data['outputLocationId']) ? (string) $data['outputLocationId'] : null;
        $mode             = (string) ($data['mode'] ?? 'draft');
        $note             = isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null;

        if ($itemId === '') {
            throw new \InvalidArgumentException('itemId requerido');
        }
        if ($qtyPlanned <= 0) {
            throw new \InvalidArgumentException('qtyPlanned debe ser > 0');
        }
        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }
        if (!in_array($mode, ['draft', 'immediate'], true)) {
            throw new \InvalidArgumentException("mode inválido (esperado 'draft' o 'immediate')");
        }

        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $item = ncmExecute(
            'SELECT itemid, itemtrackinventory FROM item WHERE itemid = ? AND companyid = ? AND itemstatus = 1 LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item) {
            throw new \InvalidArgumentException('itemId inválido para este tenant');
        }
        if (empty($item['itemtrackinventory'])) {
            throw new \InvalidArgumentException(
                'Este artículo no trackea inventario propio, así que no se puede producir manualmente. '
                . 'Para producirlo, primero cambiá su tipo a "Producción previa" en la ficha del artículo y guardá los cambios.'
            );
        }

        $recipe = Inventory::getCompoundsArray($itemId);
        if (!is_array($recipe) || $recipe === []) {
            throw new \InvalidArgumentException('El item no tiene receta configurada (item_compound vacío)');
        }

        // Correlativo del documento (F3, context/37), scope sucursal: la orden
        // de producción no es un documento fiscal, así que no va por punto de
        // expedición. El INSERT corre en autocommit acá — si falla, el número
        // asignado queda como hueco, que es el mismo trade-off ya documentado
        // arriba para el modo 'immediate'.
        $docNumber = DocumentNumber::allocate(
            'produccion',
            DocumentNumber::SCOPE_OUTLET,
            $outletId,
            $companyId,
        );

        $rs = $this->db->Execute(
            'INSERT INTO production_order
                (orderid, companyid, outletid, locationid, outputlocationid, itemid,
                 qtyplanned, status, note, userid, docnumber)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, \'draft\', ?, ?, ?)
             RETURNING orderid',
            [$companyId, $outletId, $locationId, $outputLocationId, $itemId, $qtyPlanned, $note, $userId ?: null, $docNumber]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear la orden de producción');
        }
        $orderId = (string) ($rs->fields['orderid'] ?? '');
        if ($orderId === '') {
            throw new \RuntimeException('No se pudo crear la orden de producción');
        }

        if ($mode === 'immediate') {
            // Si complete() lanza, la orden queda en draft (no completed, no
            // stock tocado — complete() es su propia TX y hace rollback antes
            // de propagar). El caller puede reintentar complete($orderId) o
            // cancel($orderId); no queda stock a medias en ningún escenario.
            $this->complete($companyId, $userId, $orderId, $data);
        }

        return $orderId;
    }

    public function start(string $companyId, string $id): void
    {
        $order = $this->find($companyId, $id);
        if ($order === null) {
            throw new \RuntimeException('Orden de producción no encontrada');
        }
        if ($order['status'] !== 'draft') {
            throw new \RuntimeException('Solo se puede iniciar una orden en status draft (actual: ' . $order['status'] . ')');
        }
        $ok = $this->db->Execute(
            "UPDATE production_order SET status = 'in_progress', started_at = now()
              WHERE orderid = ? AND companyid = ? AND status = 'draft'",
            [$id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo iniciar la orden de producción');
        }
    }

    /**
     * Completa la orden: consume insumos, acredita el producto terminado y
     * registra merma si hubo unidades falladas. Ver semántica en el docblock
     * de la clase. Atómico — TX propia (StartTrans/HasFailedTrans/CompleteTrans).
     *
     * @param array{qtyProduced: float, wasteUnits?: float, wasteReasonId?: string,
     *              ingredientAdjustments?: list<array{itemId:string, actualQty:float}>} $data
     */
    public function complete(string $companyId, string $userId, string $id, array $data): array
    {
        global $db;

        $db->StartTrans();

        // La TX se cierra SIEMPRE, también si algo lanza: `manageStock()`
        // propaga los fallos reales de escritura (context/52 G11) y
        // `DocumentNumber::allocate()` también puede tirar. Sin este catch la
        // transacción quedaría abierta y envenenada para el resto del
        // request. Los rechazos de negocio de abajo conservan su
        // FailTrans+CompleteTrans explícito: salen por `throw`, y un
        // `CompleteTrans()` sobre una transacción ya cerrada es no-op
        // (transDepth === 0, ver el wrapper).
        try {

        // Lock de fila: evita que dos requests concurrentes completen la
        // misma orden dos veces (doble stock).
        $order = ncmExecute(
            'SELECT * FROM production_order WHERE orderid = ? AND companyid = ? FOR UPDATE',
            [$id, $companyId]
        );
        if (!$order) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('Orden de producción no encontrada');
        }
        if (!in_array($order['status'], ['draft', 'in_progress'], true)) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('La orden ya fue ' . $order['status'] . ' — no se puede completar de nuevo (idempotencia)');
        }

        $qtyProduced = (float) ($data['qtyProduced'] ?? 0);
        if ($qtyProduced <= 0) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('qtyProduced debe ser > 0');
        }

        $wasteUnits = (float) ($data['wasteUnits'] ?? 0);
        if ($wasteUnits < 0) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('wasteUnits no puede ser negativo');
        }

        // `qtyProduced` son las unidades BUENAS del lote y `wasteUnits` las
        // falladas: entre las dos no pueden superar el lote intentado. Sin este
        // guard, un caller que mandara qtyProduced = lote completo Y merma por
        // separado acreditaba a stock el lote entero y además registraba la
        // merma — más unidades de las que se produjeron, con el costo del lote
        // repartido sobre una base inflada.
        // Epsilon por el float: las cantidades pueden ser decimales (0.5 kg).
        $qtyPlanned = (float) ($order['qtyplanned'] ?? 0);
        if ($qtyPlanned > 0 && ($qtyProduced + $wasteUnits) > $qtyPlanned + 1e-9) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException(
                'Las unidades buenas más las falladas (' . ($qtyProduced + $wasteUnits) .
                ') no pueden superar el lote producido (' . $qtyPlanned . ')'
            );
        }

        $wasteReasonId = (isset($data['wasteReasonId']) && $data['wasteReasonId'] !== '')
            ? (string) $data['wasteReasonId']
            : null;
        if ($wasteUnits > 0 && $wasteReasonId === null) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('wasteReasonId requerido cuando wasteUnits > 0');
        }
        // Ownership: el reasonId es un taxonomyId que viaja del cliente — sin
        // este check, un caller podría colar el UUID de un motivo de OTRO
        // tenant (cross-tenant leak: quedaría guardado en waste_event.reasonid
        // y listWaste()/reportes resolverían el nombre de un motivo ajeno).
        if ($wasteReasonId !== null) {
            $reasonRow = ncmExecute(
                "SELECT taxonomyid FROM taxonomy WHERE taxonomyid = ? AND companyid = ? AND taxonomytype = 'wasteReason' LIMIT 1",
                [$wasteReasonId, $companyId]
            );
            if (!$reasonRow) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \InvalidArgumentException('wasteReasonId inválido para este tenant');
            }
        }

        $adjustments = [];
        foreach ((array) ($data['ingredientAdjustments'] ?? []) as $adj) {
            if (!empty($adj['itemId']) && isset($adj['actualQty'])) {
                $adjustments[(string) $adj['itemId']] = (float) $adj['actualQty'];
            }
        }

        $recipe = Inventory::getCompoundsArray($order['itemid']);
        if (!is_array($recipe) || $recipe === []) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('El item ya no tiene receta configurada — no se puede completar');
        }

        $outletId    = $order['outletid'];
        $inLocation  = $order['locationid'] ?: null;
        $outLocation = $order['outputlocationid'] ?: null;
        $basisQty    = (float) $order['qtyplanned'];
        $now         = date('Y-m-d H:i:s');

        $snapshot       = [];
        $ingredientCost = 0.0;

        foreach ($recipe as $ing) {
            $childId    = (string) $ing['compoundId'];
            $qtyPerUnit = (float) $ing['toCompoundQty'];

            // Un solo SELECT por insumo: waste %, ubicación y control de
            // stock. El costo ya NO sale de acá — lo resuelve `RecipeCosting`
            // (avg del ledger con fallback a catálogo), para que no haya dos
            // lugares que decidan cuánto vale un insumo.
            $childRow = ncmExecute(
                // Ver el comentario de `itemWaste` arriba: JSONB, no columna.
                "SELECT CASE WHEN jsonb_typeof(data->'itemWaste') = 'number'
                          THEN (data->>'itemWaste')::numeric ELSE 0 END AS itemwaste,
                        locationid, itemtrackinventory
                   FROM item WHERE itemid = ? AND companyid = ? LIMIT 1",
                [$childId, $companyId]
            );

            if (array_key_exists($childId, $adjustments)) {
                $need = $adjustments[$childId];
            } else {
                $need   = $qtyPerUnit * $basisQty;
                $wasteP = $childRow ? (float) ($childRow['itemwaste'] ?? 0) : 0.0;
                if ($wasteP > 0) {
                    $need = Inventory::getNeedWithWaste($need, $wasteP);
                }
            }

            if ($need <= 0) {
                continue;
            }

            $childLocation = $inLocation ?: ($childRow['locationid'] ?? null);
            $tracked       = $childRow ? !empty($childRow['itemtrackinventory']) : false;

            // Costo real del insumo al momento de consumir. La regla (promedio
            // ponderado del ledger de stock si hubo movimiento, `item.itemCost`
            // de catálogo si nunca lo tuvo) vive en `RecipeCosting` y en ningún
            // otro lado — esta orden y la venta del mismo ítem tienen que
            // valuar idéntico. Antes estaba escrita acá, otra vez en
            // `getComboCOGS()` y a medias en `getProductionCOGS()` (sin el
            // fallback), y las tres daban números distintos.
            $cost = RecipeCosting::unitCost($childId, $companyId, $outletId)['cost'];

            // Insumo sin control de stock (insumo_sin_stock: agua, sal,
            // condimentos): manageStock devolvería false como NO-OP legítimo
            // (guard interno itemTrackInventory < 1), indistinguible de un
            // error real de DB. No llamamos manageStock (no hay movimiento de
            // stock que registrar) pero SÍ contamos su costo en ingredientCost
            // — el costo de la receta incluye todos los insumos, tengan o no
            // ledger de stock. `false` de manageStock queda reservado como
            // fatal SOLO para insumos stockeables.
            // Un insumo que NO lleva stock propio pero SÍ tiene receta es una
            // sub-preparación: lo que hay que descontar está un nivel más
            // abajo. Recorrer un solo nivel la dejaba sin consumir nada — el
            // mismo defecto que tenía la venta de combos.
            //
            // `explodeRecipeDetailed()` y no `explodeRecipe()`: el mapa plano
            // descarta las hojas que no mueven stock, y dentro de una
            // sub-preparación esas hojas (agua, sal, condimentos del nivel de
            // abajo) son costo real que desaparecía de `ingredientCost`. Acá
            // vienen marcadas `stockLeaf=false`: cuestan, no se descuentan.
            $subLeaves = (!$tracked)
                ? Inventory::explodeRecipeDetailed($childId, $companyId, $need)
                : [];

            if ($subLeaves !== []) {
                // El costo de la sub-preparación es la suma de lo que consume,
                // NO su `itemCost` de catálogo: sumar las dos cosas contaría el
                // mismo insumo dos veces.
                // Misma regla de valuación que el insumo directo de arriba, y
                // en UNA consulta para todas las hojas en vez de dos por hoja.
                $leafCosts = RecipeCosting::unitCosts(array_column($subLeaves, 'itemId'), $companyId, $outletId);

                // Costo: TODAS las hojas. Movimiento: solo las que llevan
                // ledger, agregadas por itemId (una hoja alcanzada por dos
                // ramas es UN movimiento, como hacía `explodeRecipe()`).
                $lineCost = 0.0;
                $toMove   = [];
                foreach ($subLeaves as $leaf) {
                    $lineCost += ($leafCosts[$leaf['itemId']]['cost'] ?? 0.0) * $leaf['qty'];
                    if ($leaf['stockLeaf'] === true) {
                        $toMove[$leaf['itemId']] = ($toMove[$leaf['itemId']] ?? 0.0) + $leaf['qty'];
                    }
                }

                foreach ($toMove as $leafId => $leafQty) {
                    $leafRow = ncmExecute(
                        'SELECT locationid, itemtrackinventory FROM item WHERE itemid = ? AND companyid = ? LIMIT 1',
                        [$leafId, $companyId]
                    );
                    if (!$leafRow || empty($leafRow['itemtrackinventory'])) {
                        // Producción previa mal cargada (lleva stock propio
                        // según el predicado pero itemTrackInventory está en
                        // 0): manageStock sería un no-op que devuelve false,
                        // indistinguible de un error de DB. Ya se contó su
                        // costo arriba; no se fuerza un movimiento imposible.
                        continue;
                    }

                    Inventory::manageStock([
                        'itemId'        => $leafId,
                        'source'        => 'production',
                        'count'         => $leafQty,
                        'type'          => '-',
                        'userId'        => $userId,
                        'transactionId' => null,
                        'outletId'      => $outletId,
                        'locationId'    => $inLocation ?: ($leafRow['locationid'] ?? null),
                        'note'          => 'Producción #' . $id,
                        'date'          => $now,
                        'companyId'     => $companyId,
                    ]);
                }
            } else {
                if ($tracked) {
                    Inventory::manageStock([
                        'itemId'        => $childId,
                        'source'        => 'production',
                        'count'         => $need,
                        'type'          => '-',
                        'userId'        => $userId,
                        'transactionId' => null,
                        'outletId'      => $outletId,
                        'locationId'    => $childLocation,
                        'note'          => 'Producción #' . $id,
                        'date'          => $now,
                        'companyId'     => $companyId,
                    ]);
                }
                $lineCost = $cost * $need;
            }

            $ingredientCost += $lineCost;

            $snapshot[] = [
                'itemId'   => $childId,
                'qty'      => $need,
                'unitCost' => $cost,
                'lineCost' => $lineCost,
                'tracked'  => $tracked,
            ];
        }

        // qtyProduced > 0 ya validado arriba — guard explícito igual, división
        // nunca ve denominador 0.
        $unitCogs = $qtyProduced > 0 ? ($ingredientCost / $qtyProduced) : 0.0;

        $outputLocation = $outLocation;
        if (!$outputLocation) {
            $itemRow        = ncmExecute('SELECT locationid FROM item WHERE itemid = ? AND companyid = ? LIMIT 1', [$order['itemid'], $companyId]);
            $outputLocation = $itemRow['locationid'] ?? null;
        }

        // Las unidades FALLADAS (wasteUnits) NO entran al stock — solo se
        // acreditan las qtyProduced (netas, ya "buenas"). Ver docblock.
        Inventory::manageStock([
            'itemId'        => $order['itemid'],
            'source'        => 'production',
            'count'         => $qtyProduced,
            'type'          => '+',
            'cogs'          => $unitCogs,
            'userId'        => $userId,
            'transactionId' => null,
            'outletId'      => $outletId,
            'locationId'    => $outputLocation,
            'note'          => 'Producción #' . $id,
            'date'          => $now,
            'companyId'     => $companyId,
        ]);

        if ($wasteUnits > 0) {
            $wasteCost = $wasteUnits * $unitCogs;
            // La merma de una producción es un documento propio (no un renglón
            // de la orden): lleva su correlativo igual que la merma manual.
            $wasteDocNumber = DocumentNumber::allocate(
                'merma',
                DocumentNumber::SCOPE_OUTLET,
                $outletId,
                $companyId,
            );
            $ok = $this->db->Execute(
                'INSERT INTO waste_event
                    (wasteid, companyid, outletid, locationid, itemid, qty, reasonid, source, orderid, cost, userid, docnumber)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, \'production\', ?, ?, ?, ?)',
                [$companyId, $outletId, $outputLocation, $order['itemid'], $wasteUnits, $wasteReasonId, $id, $wasteCost, $userId ?: null, $wasteDocNumber]
            );
            if ($ok === false) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('No se pudo registrar el evento de merma');
            }
        }

        $updated = $this->db->Execute(
            "UPDATE production_order
                SET status = 'completed', qtyproduced = ?, qtywaste = ?, recipesnapshot = ?::jsonb,
                    ingredientcost = ?, unitcogs = ?, completed_at = now()
              WHERE orderid = ? AND companyid = ? AND status IN ('draft','in_progress')",
            [$qtyProduced, $wasteUnits, json_encode($snapshot), $ingredientCost, $unitCogs, $id, $companyId]
        );
        if ($updated === false) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo actualizar la orden de producción a completed');
        }

        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al completar la orden de producción (transacción abortada)');
        }

        return $this->find($companyId, $id) ?? [];
    }

    public function cancel(string $companyId, string $id): void
    {
        $order = $this->find($companyId, $id);
        if ($order === null) {
            throw new \RuntimeException('Orden de producción no encontrada');
        }
        if (!in_array($order['status'], ['draft', 'in_progress'], true)) {
            throw new \RuntimeException('Solo se puede cancelar una orden draft/in_progress (actual: ' . $order['status'] . ')');
        }
        $ok = $this->db->Execute(
            "UPDATE production_order SET status = 'cancelled'
              WHERE orderid = ? AND companyid = ? AND status IN ('draft','in_progress')",
            [$id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo cancelar la orden de producción');
        }
    }

    /**
     * Merma manual (no ligada a una orden): {itemId, qty, reasonId, outletId,
     * locationId?, note?}. TX propia — descuenta stock y registra el evento
     * de forma atómica.
     */
    public function registerWaste(string $companyId, string $userId, array $data): string
    {
        global $db;

        $itemId     = (string) ($data['itemId'] ?? '');
        $qty        = (float) ($data['qty'] ?? 0);
        $reasonId   = (string) ($data['reasonId'] ?? '');
        $outletId   = (string) ($data['outletId'] ?? '');
        $locationId = !empty($data['locationId']) ? (string) $data['locationId'] : null;
        $note       = isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null;

        if ($itemId === '') {
            throw new \InvalidArgumentException('itemId requerido');
        }
        if ($qty <= 0) {
            throw new \InvalidArgumentException('qty debe ser > 0');
        }
        if ($reasonId === '') {
            throw new \InvalidArgumentException('reasonId requerido');
        }
        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }

        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }
        // Ownership del motivo — mismo razonamiento que en complete(): reasonId
        // es un taxonomyId que viaja del cliente, sin este check se podría
        // colar el motivo de OTRO tenant.
        $reasonRow = ncmExecute(
            "SELECT taxonomyid FROM taxonomy WHERE taxonomyid = ? AND companyid = ? AND taxonomytype = 'wasteReason' LIMIT 1",
            [$reasonId, $companyId]
        );
        if (!$reasonRow) {
            throw new \InvalidArgumentException('reasonId inválido para este tenant');
        }
        $item = ncmExecute(
            'SELECT itemid, itemtrackinventory, locationid FROM item WHERE itemid = ? AND companyid = ? AND itemstatus = 1 LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item) {
            throw new \InvalidArgumentException('itemId inválido para este tenant');
        }
        if (empty($item['itemtrackinventory'])) {
            throw new \InvalidArgumentException('El item no trackea inventario (itemTrackInventory)');
        }

        $db->StartTrans();

        // La TX se cierra SIEMPRE, también si algo lanza: `manageStock()`
        // propaga los fallos reales de escritura (context/52 G11) y
        // `DocumentNumber::allocate()` también puede tirar. Sin este catch la
        // transacción quedaría abierta y envenenada para el resto del
        // request. Los rechazos de negocio de abajo conservan su
        // FailTrans+CompleteTrans explícito: salen por `throw`, y un
        // `CompleteTrans()` sobre una transacción ya cerrada es no-op
        // (transDepth === 0, ver el wrapper).
        try {

        $stock = Inventory::getItemStock($itemId, $outletId);
        $cost  = ($stock && isset($stock['stockOnHandCOGS']) && is_numeric($stock['stockOnHandCOGS']))
            ? (float) $stock['stockOnHandCOGS']
            : 0.0;

        Inventory::manageStock([
            'itemId'        => $itemId,
            'source'        => 'waste',
            'count'         => $qty,
            'type'          => '-',
            'userId'        => $userId,
            'transactionId' => null,
            'outletId'      => $outletId,
            'locationId'    => $locationId ?: ($item['locationid'] ?? null),
            'note'          => $note,
            'date'          => date('Y-m-d H:i:s'),
            'companyId'     => $companyId,
        ]);

        $docNumber = DocumentNumber::allocate(
            'merma',
            DocumentNumber::SCOPE_OUTLET,
            $outletId,
            $companyId,
        );

        $rs = $this->db->Execute(
            "INSERT INTO waste_event
                (wasteid, companyid, outletid, locationid, itemid, qty, reasonid, source, cost, note, userid, docnumber)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, 'manual', ?, ?, ?, ?)
             RETURNING wasteid",
            [$companyId, $outletId, $locationId, $itemId, $qty, $reasonId, $qty * $cost, $note, $userId ?: null, $docNumber]
        );
        if ($rs === false || $rs->EOF) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo registrar el evento de merma');
        }
        $wasteId = (string) ($rs->fields['wasteid'] ?? '');

        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed || $wasteId === '') {
            throw new \RuntimeException('Fallo al registrar la merma (transacción abortada)');
        }

        return $wasteId;
    }

    /** @return array<int,array<string,mixed>> */
    public function listWaste(string $companyId, array $filters = []): array
    {
        $where  = ['we.companyid = ?'];
        $params = [$companyId];

        if (!empty($filters['from'])) {
            $where[]  = 'we.created_at >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'we.created_at <= ?';
            $params[] = (string) $filters['to'];
        }
        if (!empty($filters['reasonId'])) {
            $where[]  = 'we.reasonid = ?';
            $params[] = (string) $filters['reasonId'];
        }
        if (!empty($filters['outletId'])) {
            $where[]  = 'we.outletid = ?';
            $params[] = (string) $filters['outletId'];
        }

        // t.companyid = we.companyid en el JOIN (no solo en el WHERE de we):
        // reasonid es un taxonomyId que en teoría solo debería apuntar a un
        // motivo del mismo tenant (ya validado al escribir, ver
        // complete()/registerWaste()), pero si algún row viejo/corrupto
        // tuviera un reasonid de otro tenant, el JOIN sin scope resolvería
        // (y filtraría) el taxonomyName de ESE otro tenant. Defensa en
        // profundidad — nunca confiar el aislamiento multi-tenant a un solo
        // punto de validación.
        $sql = 'SELECT we.*, i.itemname AS itemname, t.taxonomyname AS reasonname, c.contactname AS username
                  FROM waste_event we
                  JOIN item i ON i.itemid = we.itemid
             LEFT JOIN taxonomy t ON t.taxonomyid = we.reasonid AND t.companyid = we.companyid
             LEFT JOIN contact c ON c.contactid = we.userid
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY we.created_at DESC
                 LIMIT 500';

        // Mismo criterio que list(): un error de query no puede disfrazarse de
        // "no hubo mermas".
        $rs = $this->db->Execute($sql, $params);
        if ($rs === false) {
            throw new \RuntimeException(
                'No se pudo listar las mermas: ' . $this->db->ErrorMsg()
            );
        }
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = [
                'id'         => (string) ($row['wasteid'] ?? ''),
                'docNumber'  => isset($row['docnumber']) ? (int) $row['docnumber'] : null,
                'itemId'     => (string) ($row['itemid'] ?? ''),
                'itemName'   => (string) ($row['itemname'] ?? ''),
                'qty'        => (float) ($row['qty'] ?? 0),
                'reasonId'   => $row['reasonid'] ?? null,
                'reasonName' => $row['reasonname'] ?? null,
                'source'     => (string) ($row['source'] ?? ''),
                'orderId'    => $row['orderid'] ?? null,
                'cost'       => isset($row['cost']) ? (float) $row['cost'] : null,
                'note'       => $row['note'] ?? null,
                'userId'     => $row['userid'] ?? null,
                'userName'   => $row['username'] ?? null,
                'createdAt'  => $row['created_at'] ?? null,
            ];
        }
        return $out;
    }

    private function present(array $row): array
    {
        $snapshot = null;
        $rawSnap  = $row['recipesnapshot'] ?? null;
        if (is_string($rawSnap) && $rawSnap !== '') {
            $decoded  = json_decode($rawSnap, true);
            $snapshot = is_array($decoded) ? $decoded : null;
        } elseif (is_array($rawSnap)) {
            $snapshot = $rawSnap;
        }

        return [
            'id'                => (string) ($row['orderid'] ?? ''),
            // Correlativo del documento. Null en las órdenes anteriores a la
            // mig 129 que no se pudieron numerar (sucursal nula).
            'docNumber'         => isset($row['docnumber']) ? (int) $row['docnumber'] : null,
            'companyId'         => (string) ($row['companyid'] ?? ''),
            'outletId'          => (string) ($row['outletid'] ?? ''),
            'locationId'        => $row['locationid'] ?? null,
            'outputLocationId'  => $row['outputlocationid'] ?? null,
            'itemId'            => (string) ($row['itemid'] ?? ''),
            'itemName'          => $row['itemname'] ?? null,
            'qtyPlanned'        => (float) ($row['qtyplanned'] ?? 0),
            'qtyProduced'       => isset($row['qtyproduced']) ? (float) $row['qtyproduced'] : null,
            'qtyWaste'          => (float) ($row['qtywaste'] ?? 0),
            'status'            => (string) ($row['status'] ?? ''),
            'recipeSnapshot'    => $snapshot,
            'ingredientCost'    => isset($row['ingredientcost']) ? (float) $row['ingredientcost'] : null,
            'unitCogs'          => isset($row['unitcogs']) ? (float) $row['unitcogs'] : null,
            'note'              => $row['note'] ?? null,
            'userId'            => $row['userid'] ?? null,
            'createdAt'         => $row['created_at'] ?? null,
            'startedAt'         => $row['started_at'] ?? null,
            'completedAt'       => $row['completed_at'] ?? null,
        ];
    }
}
