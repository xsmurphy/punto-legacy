<?php
declare(strict_types=1);

namespace Punto\Api\Production;

use Punto\Api\Documents\DocumentNumber;
use Punto\App\Domain\Inventory;

/**
 * ProductionBatchService — LOTE de producción multi-plato
 * (context/70-viandas.md, etapa B "Producción por lote", fase F2).
 *
 * ── Qué resuelve ────────────────────────────────────────────────────────────
 *
 * `ProductionService` produce UN plato por orden. La pregunta del cliente es
 * otra: *"de 10 pedidos, cuánta pechuga necesito EN TOTAL"*. Eso exige tomar
 * `{plato, cantidad} × N`, explotar todas las recetas, **agregar por insumo**,
 * comparar contra el saldo del depósito y devolver la necesidad consolidada.
 *
 * ── Qué NO reimplementa (D1 de context/70) ──────────────────────────────────
 *
 * Ni el consumo de stock ni el costeo. El lote es un PADRE: sus líneas son
 * `production_order` reales (`production_order.batchid`, mig 194) y se
 * completan por el `ProductionService::complete()` que ya existe. Por eso el
 * COGS, el promedio ponderado del ledger, el `recipesnapshot` congelado, la
 * merma planificada por nivel, el correlativo `produccion` de cada orden y la
 * emisión del evento realtime salen bien sin una sola línea de lógica
 * duplicada — y una orden suelta (`batchid IS NULL`) sigue comportándose
 * EXACTAMENTE igual que antes.
 *
 * Ningún `INSERT` de movimiento de stock vive en esta clase. `Inventory` no se
 * toca desde acá salvo para LEER (explosión y saldo).
 *
 * ── Por qué es un padre persistido y no un cálculo al vuelo ────────────────
 *
 * `context/70` §Arquitecturas rechazadas descarta "el lote como N
 * `production_order` de un ítem cada una": pierde la agregación por insumo,
 * que es el punto entero de la etapa B. El padre es donde el turno de cocina
 * decide UNA vez la sucursal, el depósito de insumos y el destino del
 * terminado, y es el documento que se imprime y va a la cocina.
 *
 * ── `estimate()` es lectura PURA ────────────────────────────────────────────
 *
 * No escribe nada: sirve para preguntar "¿me alcanza?" ANTES de comprometerse.
 * Es la razón por la que no se implementó como "creá el lote y mirá el
 * detalle".
 *
 * ── Degradación honesta cuando no hay control de inventario (D1) ────────────
 *
 * Un insumo con `itemTrackInventory < 1` (agua, sal — y TODOS los insumos de
 * un comercio que lleva el stock a mano) no tiene `onHand` real. Para esos NO
 * hay faltante: hay **necesidad total**, y así se devuelve (`onHand` y
 * `missing` en `null`, `tracked=false`). Inventar un `onHand = 0` y llamarlo
 * faltante sería el mismo número con una etiqueta mentirosa: diría "te faltan
 * 12 kg de sal" a un comercio que tiene el bidón lleno y nunca lo cargó al
 * sistema.
 *
 * ── Atomicidad de `confirm()` ───────────────────────────────────────────────
 *
 * `confirm()` es TODO-O-NADA, a diferencia del `mode='immediate'` de
 * `ProductionService::create()`. El wrapper de DB cuenta profundidad
 * (`DB::StartTrans()`/`CompleteTrans()`, `api/includes/lib/DB.php:776-826`):
 * solo el nivel más EXTERNO confirma o revierte, y `FailTrans()` marca
 * fallidos todos los niveles. Así que abrir la transacción acá hace que los
 * `StartTrans()` internos de cada `complete()` sean niveles anidados de la
 * MISMA transacción de Postgres: si la línea 3 de 5 falla, las dos primeras se
 * revierten con ella. Un lote a medias sería lo peor posible — stock consumido
 * para platos que nadie cocinó.
 *
 * (El docblock de `ProductionService::create()` dice "este DB wrapper no
 * soporta transacciones anidadas". Es impreciso: no soporta SAVEPOINTs —
 * revertir un nivel interno sin revertir el externo—, pero sí cuenta
 * profundidad, que es todo lo que hace falta para que el lote sea atómico.)
 */
final class ProductionBatchService
{
    /** @var mixed */
    private $db;

    private ProductionService $orders;

    public function __construct($db, ?ProductionService $orders = null)
    {
        $this->db     = $db;
        $this->orders = $orders ?? new ProductionService($db);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Necesidad consolidada (lectura pura)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El corazón: dado `{plato, cantidad} × N`, devuelve la necesidad AGREGADA
     * por insumo contra el saldo del depósito.
     *
     * No escribe. Se puede llamar en cada tecleo de la pantalla del lote.
     *
     * La explosión la hace `Inventory::explodeBatch()`, que es un agregador
     * sobre `explodeRecipeDetailed()` — el MISMO motor que después va a
     * consumir el stock. Escribir la agregación acá sería una segunda
     * definición de "qué consume esta producción" y el día que divergiera, la
     * necesidad que ve el cocinero dejaría de ser lo que la producción
     * descuenta.
     *
     * La merma NO se toca: se aplica por nivel como ya lo hace el motor, y es
     * un porcentaje del INSUMO (`item.data->>'itemWaste'`), no de la línea de
     * receta — decisión del owner confirmada 2026-09-06, no una limitación.
     *
     * @param list<array{itemId:string, qty:float|int|string}> $lines
     * @return array{
     *   lines: list<array<string,mixed>>,
     *   ingredients: list<array<string,mixed>>,
     *   batchCapacity: float|null,
     *   producible: bool,
     * }
     */
    public function estimate(string $companyId, string $outletId, array $lines, ?string $locationId = null): array
    {
        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }
        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $normalized = $this->normalizeLines($lines);
        if ($normalized === []) {
            return ['lines' => [], 'ingredients' => [], 'batchCapacity' => null, 'producible' => false];
        }

        // Ownership + estado de cada plato en UNA query. Sin este fence, un
        // itemId de otro tenant se explotaría igual (`getCompoundsArray()`
        // filtra por parentItemId, no por company) y la pantalla mostraría la
        // receta ajena.
        $dishes = $this->loadItems($companyId, array_keys($normalized));

        $explodable = [];
        $outLines   = [];
        $producible = true;

        foreach ($normalized as $itemId => $qty) {
            $dish = $dishes[$itemId] ?? null;
            if ($dish === null) {
                throw new \InvalidArgumentException('itemId inválido para este tenant: ' . $itemId);
            }

            $recipe   = Inventory::getCompoundsArray($itemId);
            $hasRecipe = is_array($recipe) && $recipe !== [];

            // `create()` exige `itemTrackInventory` (lo pide
            // `ProductionService::create()`: sin stock propio no hay dónde
            // acreditar el terminado). `estimate()` NO lo exige — preguntar
            // "cuánta pechuga necesito para 10 milanesas" tiene que funcionar
            // aunque la milanesa sea de producción directa. Lo que se hace es
            // decirlo, por línea, en vez de negarse a calcular.
            $lineProducible = $hasRecipe && $dish['tracked'];
            if (!$lineProducible) {
                $producible = false;
            }

            $outLines[] = [
                'itemId'       => $itemId,
                'itemName'     => $dish['name'],
                'qty'          => $qty,
                'hasRecipe'    => $hasRecipe,
                'tracked'      => $dish['tracked'],
                'producible'   => $lineProducible,
                'reason'       => $lineProducible ? null : ($hasRecipe
                    ? 'No lleva stock propio: cambiá su tipo a "Producción previa" en la ficha para poder producirlo.'
                    : 'No tiene receta configurada.'),
            ];

            if ($hasRecipe) {
                $explodable[] = ['itemId' => $itemId, 'qty' => $qty];
            }
        }

        $leaves = Inventory::explodeBatch($explodable, $companyId);
        if ($leaves === []) {
            return ['lines' => $outLines, 'ingredients' => [], 'batchCapacity' => null, 'producible' => false];
        }

        $ingredientIds = array_column($leaves, 'itemId');
        $names         = $this->loadItems($companyId, $ingredientIds);

        // Saldo de TODOS los insumos en una sola query — sin esto sería un N+1
        // sobre una pantalla que recalcula en cada tecleo.
        $trackedIds = [];
        foreach ($leaves as $leaf) {
            if ($leaf['stockLeaf'] === true) {
                $trackedIds[] = $leaf['itemId'];
            }
        }
        $onHand = $trackedIds === [] ? [] : Inventory::onHandFor($trackedIds, $outletId, $locationId);

        $ingredients   = [];
        $batchCapacity = null;

        foreach ($leaves as $leaf) {
            $id      = $leaf['itemId'];
            $needed  = (float) $leaf['qty'];
            $tracked = $leaf['stockLeaf'] === true;
            $meta    = $names[$id] ?? null;

            if ($tracked) {
                // Ausente del mapa = ese insumo nunca tuvo movimiento en el
                // depósito: saldo 0 REAL, no "desconocido". Es distinto del
                // insumo sin control de inventario, que va abajo.
                $have    = (float) ($onHand[$id] ?? 0.0);
                $missing = $needed > $have ? $needed - $have : 0.0;

                // Cuántos lotes COMPLETOS salen con lo que hay: el mínimo, entre
                // los insumos que sí tienen saldo, de `onHand / necesidad`. Los
                // insumos sin control de inventario NO limitan (su saldo es
                // desconocido, no cero) — el mismo criterio que ya usa
                // `ProductionService::capacity()` para un solo plato.
                $ratio         = $needed > 0 ? $have / $needed : INF;
                $batchCapacity = $batchCapacity === null ? $ratio : min($batchCapacity, $ratio);
            } else {
                // D1: sin `onHand` no hay FALTANTE, hay NECESIDAD TOTAL.
                $have    = null;
                $missing = null;
            }

            $ingredients[] = [
                'itemId'   => $id,
                'itemName' => $meta['name'] ?? null,
                'needed'   => $needed,
                'tracked'  => $tracked,
                'onHand'   => $have,
                'missing'  => $missing,
                // De qué plato salió cuánto. Sin esto la necesidad consolidada
                // es un número que el cocinero no puede auditar.
                'bySource' => array_map(
                    static fn (string $srcId, float $q): array => ['itemId' => $srcId, 'qty' => $q],
                    array_keys($leaf['bySource']),
                    array_values($leaf['bySource']),
                ),
            ];
        }

        return [
            'lines'         => $outLines,
            'ingredients'   => $ingredients,
            // null = ningún insumo con control de inventario limita el lote
            // (mismo contrato que `capacity()`: null NO es 0).
            'batchCapacity' => ($batchCapacity === null || is_infinite($batchCapacity)) ? null : $batchCapacity,
            'producible'    => $producible,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ciclo de vida
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea el lote en `draft` junto con sus N `production_order` hijas, también
     * en `draft`.
     *
     * Las hijas nacen acá y no en `confirm()` porque el lote en borrador YA es
     * el papel que va a la cocina: cada línea es una orden real, con su
     * correlativo `produccion`, que el cocinero puede ver en el listado de
     * producción antes de que se mueva un gramo de stock.
     *
     * Atómico: el lote y sus hijas entran juntos o no entra nada. Cada
     * `ProductionService::create()` corre como nivel anidado de ESTA
     * transacción (ver el docblock de la clase), así que un plato inválido en
     * la línea 4 no deja tres órdenes sueltas ni huecos en el correlativo.
     *
     * @param array{outletId:string, locationId?:?string, outputLocationId?:?string,
     *              note?:?string, lines:list<array{itemId:string, qty:mixed}>} $data
     * @return string batchId
     */
    public function create(string $companyId, string $userId, array $data): string
    {
        global $db;

        $outletId         = (string) ($data['outletId'] ?? '');
        $locationId       = !empty($data['locationId']) ? (string) $data['locationId'] : null;
        $outputLocationId = !empty($data['outputLocationId']) ? (string) $data['outputLocationId'] : null;
        $note             = isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null;

        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }
        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $normalized = $this->normalizeLines($data['lines'] ?? []);
        if ($normalized === []) {
            throw new \InvalidArgumentException('El lote necesita al menos una línea {itemId, qty} con cantidad > 0');
        }

        $batchId = '';

        $db->StartTrans();
        try {
            // `DocumentNumber::allocate()` DENTRO de la transacción del
            // documento, como manda su docblock: si el lote falla, el rollback
            // devuelve el número y no queda hueco en el correlativo.
            $docNumber = DocumentNumber::allocate(
                'lote',
                DocumentNumber::SCOPE_OUTLET,
                $outletId,
                $companyId,
            );

            $rs = $this->db->Execute(
                "INSERT INTO production_batch
                    (batchid, companyid, outletid, locationid, outputlocationid, status, note, userid, docnumber)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?, 'draft', ?, ?, ?)
                 RETURNING batchid",
                [$companyId, $outletId, $locationId, $outputLocationId, $note, $userId ?: null, $docNumber]
            );
            if ($rs === false || $rs->EOF) {
                throw new \RuntimeException('No se pudo crear el lote de producción');
            }
            $batchId = (string) ($rs->fields['batchid'] ?? '');
            if ($batchId === '') {
                throw new \RuntimeException('No se pudo crear el lote de producción');
            }

            foreach ($normalized as $itemId => $qty) {
                // El servicio existente valida ownership del ítem, que trackee
                // inventario y que tenga receta, y asigna el correlativo
                // `produccion`. Nada de eso se reimplementa acá.
                $this->orders->create($companyId, $userId, [
                    'itemId'           => $itemId,
                    'outletId'         => $outletId,
                    'qtyPlanned'       => $qty,
                    'locationId'       => $locationId,
                    'outputLocationId' => $outputLocationId,
                    'mode'             => 'draft',
                    'note'             => $note,
                    'batchId'          => $batchId,
                ]);
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al crear el lote de producción (transacción abortada)');
        }

        return $batchId;
    }

    /**
     * Confirma el lote: completa TODAS sus líneas por
     * `ProductionService::complete()` — el único camino que mueve stock y
     * congela el costeo.
     *
     * Todo-o-nada (ver el docblock de la clase). Un lote a medias dejaría
     * insumos consumidos para platos que nadie cocinó.
     *
     * `qtyProduced` de cada línea es su `qtyplanned`: el lote es el turno de
     * cocina completo. El completado parcial y la merma por plato siguen
     * viviendo donde ya estaban — en la orden individual, que se puede abrir
     * desde el listado de producción. Meterlos acá duplicaría la semántica de
     * `complete()`, que es justamente lo que este servicio evita.
     *
     * @return array<string,mixed> el lote con sus líneas ya completadas
     */
    public function confirm(string $companyId, string $userId, string $batchId): array
    {
        global $db;

        $db->StartTrans();
        try {
            // Lock de fila: dos requests concurrentes no pueden confirmar el
            // mismo lote y doblar el stock. Mismo patrón que `complete()`.
            $batch = ncmExecute(
                'SELECT * FROM production_batch WHERE batchid = ? AND companyid = ? FOR UPDATE',
                [$batchId, $companyId]
            );
            if (!$batch) {
                throw new \RuntimeException('Lote de producción no encontrado');
            }
            if ((string) $batch['status'] !== 'draft') {
                throw new \RuntimeException(
                    'El lote ya fue ' . $batch['status'] . ' — no se puede confirmar de nuevo (idempotencia)'
                );
            }

            $lines = $this->loadLineIds($companyId, $batchId, ['draft', 'in_progress']);
            if ($lines === []) {
                throw new \RuntimeException('El lote no tiene líneas pendientes para confirmar');
            }

            foreach ($lines as $line) {
                $this->orders->complete($companyId, $userId, $line['orderid'], [
                    'qtyProduced' => (float) $line['qtyplanned'],
                ]);
            }

            $updated = $this->db->Execute(
                "UPDATE production_batch SET status = 'confirmed', confirmed_at = now()
                  WHERE batchid = ? AND companyid = ? AND status = 'draft'",
                [$batchId, $companyId]
            );
            if ($updated === false) {
                throw new \RuntimeException('No se pudo actualizar el lote a confirmado');
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al confirmar el lote de producción (transacción abortada)');
        }

        return $this->find($companyId, $batchId) ?? [];
    }

    /**
     * Cancela el lote y sus líneas pendientes. Solo desde `draft` — un lote
     * confirmado ya movió stock y se revierte por la orden individual, no
     * borrando el papel.
     */
    public function cancel(string $companyId, string $batchId): void
    {
        global $db;

        $db->StartTrans();
        try {
            $batch = ncmExecute(
                'SELECT status FROM production_batch WHERE batchid = ? AND companyid = ? FOR UPDATE',
                [$batchId, $companyId]
            );
            if (!$batch) {
                throw new \RuntimeException('Lote de producción no encontrado');
            }
            if ((string) $batch['status'] !== 'draft') {
                throw new \RuntimeException(
                    'Solo se puede cancelar un lote en borrador (actual: ' . $batch['status'] . ')'
                );
            }

            foreach ($this->loadLineIds($companyId, $batchId, ['draft', 'in_progress']) as $line) {
                $this->orders->cancel($companyId, $line['orderid']);
            }

            $ok = $this->db->Execute(
                "UPDATE production_batch SET status = 'cancelled', cancelled_at = now()
                  WHERE batchid = ? AND companyid = ? AND status = 'draft'",
                [$batchId, $companyId]
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo cancelar el lote de producción');
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al cancelar el lote de producción (transacción abortada)');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lectura
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public function find(string $companyId, string $batchId): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM production_batch WHERE batchid = ? AND companyid = ? LIMIT 1',
            [$batchId, $companyId]
        );
        if ($rs === false || $rs->EOF) {
            return null;
        }
        $row = [];
        foreach ($rs->fields as $k => $v) {
            $row[$k] = $v;
        }

        $batch          = $this->present($row);
        $batch['lines'] = $this->lines($companyId, $batchId);

        // La necesidad de un lote CONFIRMADO no se recalcula: cada orden hija
        // ya congeló su `recipesnapshot` al completarse, y recalcular con los
        // precios/mermas de hoy mostraría un consumo que no fue el real.
        // Mismo criterio que el costeo congelado de `production_order`.
        if ($batch['status'] === 'draft' && $batch['lines'] !== []) {
            $batch['estimate'] = $this->estimate(
                $companyId,
                $batch['outletId'],
                array_map(
                    static fn (array $l): array => ['itemId' => $l['itemId'], 'qty' => $l['qtyPlanned']],
                    $batch['lines']
                ),
                $batch['locationId'],
            );
        } else {
            $batch['estimate'] = null;
        }

        return $batch;
    }

    /** @return list<array<string,mixed>> */
    public function list(string $companyId, array $filters = []): array
    {
        // Toda columna calificada con `pb.`: el mismo cuidado que documenta
        // `ProductionService::list()` — un `WHERE companyid = ?` ambiguo aborta
        // la query entera y el listado parece "no hay lotes".
        $where  = ['pb.companyid = ?'];
        $params = [$companyId];

        if (!empty($filters['status'])) {
            $where[]  = 'pb.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['outletId'])) {
            $where[]  = 'pb.outletid = ?';
            $params[] = (string) $filters['outletId'];
        }
        if (!empty($filters['from'])) {
            $where[]  = 'pb.created_at >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[]  = 'pb.created_at <= ?';
            $params[] = (string) $filters['to'];
        }

        // `ORDER BY pb.created_at`, NUNCA `pb.batchid`: `gen_random_uuid()` es
        // v4 random y ordenar por el id no ordena por recencia.
        $sql = 'SELECT pb.*,
                       (SELECT COUNT(*) FROM production_order po WHERE po.batchid = pb.batchid) AS linecount,
                       (SELECT COALESCE(SUM(po.qtyplanned), 0) FROM production_order po WHERE po.batchid = pb.batchid) AS qtytotal
                  FROM production_batch pb
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY pb.created_at DESC
                 LIMIT 500';

        // Un error de query NO es "no hay lotes" — mismo criterio que
        // `ProductionService::list()`, que ya escondió un bug así.
        $rs = $this->db->Execute($sql, $params);
        if ($rs === false) {
            throw new \RuntimeException('No se pudo listar los lotes de producción: ' . $this->db->ErrorMsg());
        }

        $out = [];
        foreach ($rs->GetRows() as $row) {
            $batch              = $this->present($row);
            $batch['lineCount'] = (int) ($row['linecount'] ?? 0);
            $batch['qtyTotal']  = (float) ($row['qtytotal'] ?? 0);
            $out[] = $batch;
        }
        return $out;
    }

    /** Líneas del lote = sus `production_order` hijas. @return list<array<string,mixed>> */
    public function lines(string $companyId, string $batchId): array
    {
        $rs = $this->db->Execute(
            'SELECT po.orderid, po.itemid, po.qtyplanned, po.qtyproduced, po.status,
                    po.docnumber, po.unitcogs, po.ingredientcost, po.completed_at,
                    i.itemname AS itemname
               FROM production_order po
               JOIN item i ON i.itemid = po.itemid
              WHERE po.batchid = ? AND po.companyid = ?
              ORDER BY i.itemname ASC
              LIMIT 500',
            [$batchId, $companyId]
        );
        if ($rs === false) {
            throw new \RuntimeException('No se pudo leer las líneas del lote: ' . $this->db->ErrorMsg());
        }

        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = [
                'orderId'        => (string) ($row['orderid'] ?? ''),
                'docNumber'      => isset($row['docnumber']) ? (int) $row['docnumber'] : null,
                'itemId'         => (string) ($row['itemid'] ?? ''),
                'itemName'       => $row['itemname'] ?? null,
                'qtyPlanned'     => (float) ($row['qtyplanned'] ?? 0),
                'qtyProduced'    => isset($row['qtyproduced']) ? (float) $row['qtyproduced'] : null,
                'status'         => (string) ($row['status'] ?? ''),
                'unitCogs'       => isset($row['unitcogs']) ? (float) $row['unitcogs'] : null,
                'ingredientCost' => isset($row['ingredientcost']) ? (float) $row['ingredientcost'] : null,
                'completedAt'    => $row['completed_at'] ?? null,
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `[{itemId, qty}]` → `itemId => qty`, sumando los duplicados.
     *
     * Sumar y no pisar: si el operador carga "milanesa 4" y más abajo
     * "milanesa 6", quiso 10. Pisar dejaría 6 en silencio, y el lote produciría
     * menos de lo pedido sin decir nada. Además garantiza UNA orden hija por
     * plato, que es lo que hace que las líneas del lote sean legibles.
     *
     * @param  mixed $lines
     * @return array<string,float>
     */
    private function normalizeLines($lines): array
    {
        $out = [];
        foreach ((array) $lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $itemId = (string) ($line['itemId'] ?? '');
            $qty    = (float) ($line['qty'] ?? 0);
            if ($itemId === '' || $qty <= 0) {
                continue;
            }
            $out[$itemId] = ($out[$itemId] ?? 0.0) + $qty;
        }
        return $out;
    }

    /**
     * Nombre + control de inventario de un conjunto de ítems, con fence de
     * tenant, en una query. Es también el chequeo de ownership: un itemId que
     * no vuelve del mapa no pertenece a esta compañía.
     *
     * @param  list<string> $itemIds
     * @return array<string,array{name:string, tracked:bool}>
     */
    private function loadItems(string $companyId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter($itemIds, static fn ($v): bool => (string) $v !== '')));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = $ids;
        $params[]     = $companyId;

        // forceObj=true → recordset: iterar con `while (!$rs->EOF)`.
        $rs  = ncmExecute(
            "SELECT itemid, itemname, itemtrackinventory
               FROM item
              WHERE itemid IN ($placeholders) AND companyid = ?
              LIMIT 1000",
            $params,
            false,
            true
        );

        $out = [];
        if ($rs !== false && is_object($rs)) {
            while (!$rs->EOF) {
                $out[(string) ($rs->fields['itemid'] ?? '')] = [
                    'name'    => (string) ($rs->fields['itemname'] ?? ''),
                    'tracked' => !empty($rs->fields['itemtrackinventory']),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * Ids de las órdenes hijas en los estados dados.
     *
     * @param  list<string> $statuses
     * @return list<array{orderid:string, qtyplanned:float}>
     */
    private function loadLineIds(string $companyId, string $batchId, array $statuses): array
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $params       = array_merge([$batchId, $companyId], $statuses);

        $rs = ncmExecute(
            "SELECT orderid, qtyplanned
               FROM production_order
              WHERE batchid = ? AND companyid = ? AND status IN ($placeholders)
              ORDER BY created_at ASC
              LIMIT 500",
            $params,
            false,
            true
        );

        $out = [];
        if ($rs !== false && is_object($rs)) {
            while (!$rs->EOF) {
                $out[] = [
                    'orderid'    => (string) ($rs->fields['orderid'] ?? ''),
                    'qtyplanned' => (float) ($rs->fields['qtyplanned'] ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function present(array $row): array
    {
        return [
            'id'               => (string) ($row['batchid'] ?? ''),
            'docNumber'        => isset($row['docnumber']) ? (int) $row['docnumber'] : null,
            'companyId'        => (string) ($row['companyid'] ?? ''),
            'outletId'         => (string) ($row['outletid'] ?? ''),
            'locationId'       => $row['locationid'] ?? null,
            'outputLocationId' => $row['outputlocationid'] ?? null,
            'status'           => (string) ($row['status'] ?? ''),
            'note'             => $row['note'] ?? null,
            'userId'           => $row['userid'] ?? null,
            'createdAt'        => $row['created_at'] ?? null,
            'confirmedAt'      => $row['confirmed_at'] ?? null,
            'cancelledAt'      => $row['cancelled_at'] ?? null,
        ];
    }
}
