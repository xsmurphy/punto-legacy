<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

use Punto\Api\Support\DbQueryException;

require_once __DIR__ . '/SpaceOwnershipGuard.php';
require_once __DIR__ . '/../Auth/OperatorContext.php';

/**
 * SpaceSessionService — ciclo de vida de la ocupación de un espacio
 * (space_session, mig 80, context/15-espacios-module-plan.md F0+F1).
 *
 * NO implementa el cobro (F2) — close() solo cierra el registro contable de
 * la sesión; el flujo de facturación real (dividir cuenta, transaction) es
 * F2/F3. open()/requestBill()/cancel()/close() son las únicas transiciones
 * de esta fase.
 *
 * El índice único parcial `uq_space_session_active_per_space` (mig 80) es la
 * fuente de verdad de "una sola sesión activa por espacio" — open() confía en
 * la violación 23505 para devolver un error claro ante la carrera de dos
 * aperturas concurrentes (mismo patrón que SaleService, ver
 * api/lib/Sales/SaleService.php:172).
 */
final class SpaceSessionService
{
    /** @var mixed */
    private $db;

    /**
     * Persona que está operando, resuelta por `OperatorContext::resolve()`.
     *
     * Va en el CONSTRUCTOR y no como parámetro de cada método a propósito: es
     * contexto de la request entera, no un dato de la operación. Pasarlo por
     * método invita a que un call-site nuevo se lo olvide y, como el default
     * sería "sin operador", ese olvido se vería igual que "nadie identificado"
     * — un fallo abierto disfrazado de estado normal.
     *
     * Default = no identificado. Los callers internos que orquestan el COBRO
     * (`SpaceSettlementService`, `OrderCoreService`) construyen el service sin
     * operador y eso es correcto: los métodos que usan (`close`,
     * `reopenFromBillRequested`) no están sujetos a exclusividad — ver
     * `assertOwnership()`.
     *
     * @var array{userId: ?string, roleId: ?string, identified: bool}
     */
    private array $operator;

    /**
     * @param array{userId: ?string, roleId: ?string, identified: bool}|null $operator
     */
    public function __construct($db, ?array $operator = null)
    {
        $this->db       = $db;
        $this->operator = $operator ?? ['userId' => null, 'roleId' => null, 'identified' => false];
    }

    /**
     * Abre una sesión sobre el espacio. Falla si el espacio ya tiene una
     * sesión open|bill_requested (índice único) o está deshabilitado.
     */
    public function open(string $companyId, string $tableId, ?int $guests = null, ?string $waiterId = null, ?string $outletScope = null, ?string $alias = null): array
    {
        $alias = self::normalizeAlias($alias);
        $table = ncmExecute(
            'SELECT tableid, outletid, status, shape FROM space WHERE tableid = ? AND companyid = ? LIMIT 1',
            [$tableId, $companyId]
        );
        if (!$table) {
            throw new \RuntimeException('Espacio no encontrado');
        }
        $outletId = (string) $table['outletid'];
        $this->assertOutletScope($outletId, $outletScope);
        if ((int) $table['status'] === 0) {
            throw new \InvalidArgumentException('El espacio está deshabilitado');
        }
        // Invariante que habilita el hard-delete de decorativos en
        // SpaceService::delete(): un decor JAMÁS tiene sesión — enforcement
        // acá en el write-path, no solo por convención de UI.
        if (in_array((string) ($table['shape'] ?? ''), SpaceService::DECOR_SHAPES, true)) {
            throw new \InvalidArgumentException('Un bloque decorativo no admite sesiones');
        }

        try {
            $rs = $this->db->Execute(
                'INSERT INTO space_session (sessionid, companyid, outletid, tableid, status, guests, waiterid, alias)
                 VALUES (gen_random_uuid(), ?, ?, ?, \'open\', ?, ?, ?)
                 RETURNING sessionid',
                [$companyId, $outletId, $tableId, $guests, $waiterId, $alias]
            );
        } catch (DbQueryException $e) {
            // Carrera contra uq_space_session_active_per_space: otra caja abrió
            // el mismo espacio entre el chequeo y este INSERT. Antes se leía de
            // `ErrorMsg()` tras el `false`; el wrapper ahora lanza, así que la
            // detección va en el catch y usa el SQLSTATE exacto.
            if ($e->sqlState() === '23505'
                || str_contains($e->getMessage(), '23505')
                || str_contains($e->getMessage(), 'uq_space_session_active_per_space')) {
                throw new \RuntimeException('El espacio ya tiene una sesión activa');
            }
            throw new \RuntimeException('No se pudo abrir el espacio', 0, $e);
        }
        $id = (string) ($rs->fields['sessionid'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('No se pudo abrir el espacio');
        }

        $session = $this->find($companyId, $id);
        if ($session !== null) {
            $this->publish($companyId, $outletId, $session);
        }
        return $session ?? [];
    }

    /** open → bill_requested (pidió la cuenta). */
    public function requestBill(string $companyId, string $sessionId, ?string $outletScope = null): array
    {
        $session = $this->lockSession($companyId, $sessionId, $outletScope);
        $this->assertOwnership($session, $companyId, 'pedir la cuenta');
        if ($session['status'] !== 'open') {
            throw new \InvalidArgumentException("Solo se puede pedir la cuenta desde open (actual: {$session['status']})");
        }
        $ok = $this->db->Execute(
            "UPDATE space_session SET status = 'bill_requested' WHERE sessionid = ? AND companyid = ? AND status = 'open'",
            [$sessionId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo pedir la cuenta');
        }
        $result = $this->find($companyId, $sessionId);
        if ($result !== null) {
            $this->publish($companyId, (string) $session['outletid'], $result);
        }
        return $result ?? [];
    }

    /**
     * Cancela la sesión — solo si NO tiene órdenes activas (todas
     * closed/cancelled o sin órdenes). El espacio vuelve a 'free'.
     */
    public function cancel(string $companyId, string $sessionId, ?string $outletScope = null): array
    {
        global $db;
        $db->StartTrans();
        try {
            $session = $this->lockSession($companyId, $sessionId, $outletScope);
            // La cancelación es la operación MÁS destructiva de una mesa
            // (cancela en cascada las órdenes, incluidas las ya en cocina), así
            // que es la que más necesita el guard de exclusividad.
            $this->assertOwnership($session, $companyId, 'cancelar');
            if (in_array($session['status'], ['closed', 'cancelled'], true)) {
                throw new \InvalidArgumentException('La sesión ya está ' . $session['status']);
            }

            // Cascada (decisión owner 2026-07-19): cancelar la sesión cancela
            // sus órdenes activas. El guard anterior ("no se puede cancelar
            // con órdenes activas") dejaba el botón inutilizable con una sola
            // orden enviada. updateStatus() valida la transición, protege
            // órdenes ya cobradas (saletransactionid presente) y publica a
            // KDS/realtime por cada orden — no duplicar esa lógica acá.
            $orders       = new \Punto\Api\Orders\OrderCoreService($this->db);
            $cancelledIds = [];
            $rsActive     = $this->db->Execute(
                "SELECT orderid FROM pos_order
                  WHERE spacesessionid = ? AND companyid = ?
                    AND status NOT IN ('closed','cancelled')",
                [$sessionId, $companyId]
            );
            if ($rsActive !== false) {
                foreach ($rsActive->GetRows() as $row) {
                    $orderId = (string) $row['orderid'];
                    // updateStatus difiere su publish (InTrans) — se emite
                    // abajo, después del commit real de ESTA transacción.
                    // Motivo obligatorio en toda cancelación: acá lo genera el
                    // sistema porque la cancelación no la pidió nadie sobre
                    // ESTA orden, sino sobre la sesión que la contiene.
                    $orders->updateStatus(
                        $companyId,
                        $orderId,
                        'cancelled',
                        null,
                        'Cancelación en cascada: se canceló la sesión del espacio'
                    );
                    $cancelledIds[] = $orderId;
                }
            }

            $ok = $this->db->Execute(
                "UPDATE space_session SET status = 'cancelled', closed_at = now()
                  WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
                [$sessionId, $companyId]
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo cancelar la sesión');
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo cancelar la sesión (transacción abortada)');
        }

        // Publishes diferidos de la cascada — recién ahora que la TX commiteó
        // de verdad (sin phantom notify al KDS si algo hubiera abortado).
        foreach ($cancelledIds as $orderId) {
            $orders->publishOrderStatus($companyId, $orderId);
        }
        $result = $this->find($companyId, $sessionId);
        if ($result !== null) {
            $this->publish($companyId, (string) $session['outletid'], $result);
        }
        return $result ?? [];
    }

    /**
     * Cierra la sesión. `transactionId` opcional (F2 lo pasará al cobrar).
     * En F0+F1, sin flujo de cobro, se usa para cerrar espacios manualmente
     * (ej. espacio abierto por error).
     *
     * Publish diferido si ya veníamos dentro de una TX ajena (mismo fix
     * aplicado a OrderCoreService::markPaid — ver ese comentario): F3
     * (SpaceSettlementService::settleIfCovered) llama a close() ANIDADO
     * dentro de la TX de registerPayment(). Sin este guard, publicaría
     * "sesión cerrada" por WS antes del commit real de esa TX externa — un
     * rollback posterior (ej. el markPaid de una orden falla después)
     * dejaría un phantom notify contra una sesión que en BD sigue abierta.
     * El caller anidado usa publishSessionState() después de SU commit.
     */
    public function close(string $companyId, string $sessionId, ?string $transactionId = null, ?string $outletScope = null, bool $allowPendingBalance = false): array
    {
        global $db;

        // Anidado = nos llamó settleIfCovered dentro de SU transacción. El
        // publish le corresponde al caller externo, después de SU commit.
        $nested = $this->db->InTrans();

        // TX propia (anida sin romper, ver StartTrans/depth counter): el lock
        // de la sesión, la lectura del saldo y el UPDATE tienen que ser UNA
        // unidad. Sin la TX, el `FOR UPDATE` de abajo se liberaría al terminar
        // ese SELECT y el saldo que leemos sería una foto vieja.
        $db->StartTrans();
        $outletIdForPublish = '';
        try {
            $session            = $this->lockSession($companyId, $sessionId, $outletScope, true);
            $outletIdForPublish = (string) $session['outletid'];
            if (in_array($session['status'], ['closed', 'cancelled'], true)) {
                throw new \InvalidArgumentException('La sesión ya está ' . $session['status']);
            }

            // Invariante del plan (context/15 §F3): una sesión NO se cierra
            // con saldo pendiente. Estaba sostenido solo por el cliente
            // (handleSplitCharge relee el saldo antes de elegir el camino) —
            // un bug de UI, un doble tap o un `curl` directo cerraban la mesa
            // dejando plata sin cobrar y sin ningún registro de que faltaba.
            //
            // El saldo se computa con SpaceBalanceService, la MISMA definición
            // que usa el settlement para aceptar parciales y para decidir la
            // liquidación. Que sea la misma es lo que hace que los dos caminos
            // de cobro pasen sin excepciones especiales:
            //
            //   - Cobro atómico de mesa completa (pay-dialog, rama
            //     `sessionParentId`): markPaid() de TODAS las órdenes y recién
            //     después close(). Al llegar acá las órdenes ya están `closed`,
            //     que el balance no cuenta como pendiente (una orden `closed`
            //     está cobrada por construcción: solo markPaid la cierra), así
            //     que total = 0 → saldo cubierto.
            //   - Split (settleIfCovered): markPaid + close corren DESPUÉS del
            //     INSERT del pago que salda, en la misma TX — el saldo ya es
            //     ≤ 0 cuando esta validación lo relee.
            //
            // Y lo que NO pasa es cerrar una mesa con órdenes activas sin
            // cobrar: eso es exactamente el saldo pendiente que hay que frenar.
            //
            // `$allowPendingBalance` es la puerta EXPLÍCITA para el cierre
            // administrativo (mesa que se fue sin pagar): quien la usa está
            // declarando que perdona el saldo, no salteándose el invariante
            // por accidente.
            if (!$allowPendingBalance) {
                $balance = (new SpaceBalanceService($this->db))->compute($companyId, $sessionId)['balance'];
                if (!SpaceBalanceService::isCovered($balance)) {
                    // El formato de miles/decimales sale de la config del
                    // comercio (Money::formatNumber), no de separadores es-PY
                    // cableados: este texto lo lee el cajero y tiene que verse
                    // como el resto de los montos de su POS.
                    throw new \InvalidArgumentException(
                        'La sesión tiene saldo pendiente y no se puede cerrar (falta cobrar '
                        . \Punto\App\Domain\Money::formatNumber($balance) . ')'
                    );
                }
            }

            $ok = $this->db->Execute(
                "UPDATE space_session SET status = 'closed', closed_at = now(), saletransactionid = COALESCE(?, saletransactionid)
                  WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
                [$transactionId, $sessionId, $companyId]
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo cerrar la sesión');
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        // Solo el nivel más externo juzga el resultado: anidados, `transOk` es
        // el estado de TODA la TX del caller y no nos corresponde abortar por
        // algo que él ya está manejando.
        if ($failed && !$nested) {
            throw new \RuntimeException('No se pudo cerrar la sesión (transacción abortada)');
        }

        $result = $this->find($companyId, $sessionId);
        if ($result !== null && !$nested) {
            $this->publish($companyId, $outletIdForPublish, $result);
        }
        return $result ?? [];
    }

    /**
     * Edita los datos de la ocupación en curso: alias, comensales y mozo.
     *
     * Solo campos PRESENTES en `$fields` se tocan — `null` es un valor
     * legítimo ("sacale el alias", "esta mesa ya no tiene mozo") y no se puede
     * distinguir de "no lo mandes" mirando el valor. Por eso el contrato es
     * por PRESENCIA de la clave, no por valor no-nulo.
     *
     * Reasignar el mozo es una operación de exclusividad en sí misma: el dueño
     * de la mesa puede pasársela a un compañero (el pase de turno normal), y
     * quien tenga `pos.space.override` puede reasignar cualquiera. Lo que NO
     * puede pasar es que un tercero se adjudique la mesa de otro — lo corta el
     * mismo guard que todo lo demás, ANTES de mirar los campos.
     *
     * @param array<string,mixed> $fields claves opcionales: alias, guests, waiterId
     */
    public function update(string $companyId, string $sessionId, array $fields, ?string $outletScope = null): array
    {
        global $db;
        $db->StartTrans();
        $outletIdForPublish = '';
        try {
            $session            = $this->lockSession($companyId, $sessionId, $outletScope, true);
            $outletIdForPublish = (string) $session['outletid'];
            $this->assertOwnership($session, $companyId, 'editar');
            if (in_array($session['status'], ['closed', 'cancelled'], true)) {
                throw new \InvalidArgumentException('La sesión ya está ' . $session['status']);
            }

            $sets   = [];
            $params = [];
            if (array_key_exists('alias', $fields)) {
                $sets[]   = 'alias = ?';
                $params[] = self::normalizeAlias($fields['alias'] === null ? null : (string) $fields['alias']);
            }
            if (array_key_exists('guests', $fields)) {
                $guests = $fields['guests'];
                if ($guests !== null && (int) $guests < 0) {
                    throw new \InvalidArgumentException('La cantidad de comensales no puede ser negativa');
                }
                $sets[]   = 'guests = ?';
                $params[] = $guests === null ? null : (int) $guests;
            }
            if (array_key_exists('waiterId', $fields)) {
                $waiterId = $fields['waiterId'];
                $waiterId = ($waiterId === null || trim((string) $waiterId) === '') ? null : (string) $waiterId;
                $sets[]   = 'waiterid = ?';
                $params[] = $waiterId;
            }
            if ($sets === []) {
                throw new \InvalidArgumentException('Nada para actualizar');
            }

            $params[] = $sessionId;
            $params[] = $companyId;
            $ok = $this->db->Execute(
                'UPDATE space_session SET ' . implode(', ', $sets)
                    . " WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
                $params
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo actualizar la sesión');
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo actualizar la sesión (transacción abortada)');
        }

        $result = $this->find($companyId, $sessionId);
        if ($result !== null) {
            $this->publish($companyId, $outletIdForPublish, $result);
        }
        return $result ?? [];
    }

    /**
     * Mueve la ocupación a OTRO espacio libre (los clientes se cambiaron de
     * mesa). La sesión es la misma: cambia dónde está sentada.
     *
     * ── Por qué esto es un UPDATE de una columna y no una migración ─────────
     *
     * Porque nada de lo que cuelga de la mesa cuelga del ESPACIO: las órdenes
     * apuntan a `spacesessionid` (mig 79) y los pagos parciales a `sessionid`
     * (mig 90). Ninguno referencia `tableid`. Mover la sesión los arrastra a
     * todos sin tocarlos, y por eso las tres preguntas difíciles se contestan
     * solas:
     *
     *   - Pedidos ya enviados a cocina: siguen siendo de la misma orden y la
     *     misma sesión. El nombre del espacio que ve el KDS NO está
     *     denormalizado en `pos_order` — sale del JOIN vivo con `space`
     *     (OrderCoreService.php:1170), así que después del UPDATE la comanda
     *     dice la mesa nueva. Lo único necesario es avisarle a las pantallas
     *     que vuelvan a leer: el republish del final.
     *   - Pagos parciales ya registrados: intactos, con su `transactionid` y
     *     su comprobante ya emitido. El saldo de la sesión no se mueve un peso.
     *   - Numeración fiscal: un movimiento no emite ningún documento. No hay
     *     número que consumir ni correlativo que tocar.
     *
     * El espacio ORIGEN queda libre solo, sin UPDATE: su estado es derivado
     * (`SpaceService::listWithState`) y se calcula por la ausencia de sesión
     * activa apuntándole.
     */
    public function move(string $companyId, string $sessionId, string $targetSpaceId, ?string $outletScope = null): array
    {
        global $db;

        $orders          = new \Punto\Api\Orders\OrderCoreService($this->db);
        $movedOrderIds   = [];
        $outletForPublish = '';

        $db->StartTrans();
        try {
            $session          = $this->lockSession($companyId, $sessionId, $outletScope, true);
            $outletForPublish = (string) $session['outletid'];
            $this->assertOwnership($session, $companyId, 'mover');

            if (!in_array($session['status'], ['open', 'bill_requested'], true)) {
                throw new \InvalidArgumentException('Solo se puede mover una mesa abierta (actual: ' . $session['status'] . ')');
            }
            if ((string) $session['tableid'] === $targetSpaceId) {
                throw new \InvalidArgumentException('La mesa destino es la misma que la de origen');
            }

            $targetTableId = $this->assertUsableTarget($companyId, $targetSpaceId, $outletForPublish);

            $ok = $this->db->Execute(
                "UPDATE space_session SET tableid = ?
                  WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
                [$targetTableId, $sessionId, $companyId]
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo mover la mesa');
            }

            $movedOrderIds = $this->activeOrderIds($companyId, $sessionId);
        } catch (DbQueryException $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            // Carrera contra uq_space_session_active_per_space: otra caja abrió
            // el espacio destino entre la validación y el UPDATE. Mismo criterio
            // que open() — el índice único es la fuente de verdad, no el chequeo
            // previo.
            if ($e->sqlState() === '23505' || str_contains($e->getMessage(), 'uq_space_session_active_per_space')) {
                throw new \RuntimeException('El espacio destino se ocupó recién — elegí otro');
            }
            throw new \RuntimeException('No se pudo mover la mesa', 0, $e);
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo mover la mesa (transacción abortada)');
        }

        // Después del commit: la comanda de cocina tiene que decir la mesa
        // nueva. Sin esto el KDS sigue mostrando la vieja hasta el próximo
        // refetch, y un mozo lleva el plato al lugar equivocado.
        foreach ($movedOrderIds as $orderId) {
            $orders->publishOrderStatus($companyId, $orderId);
        }
        $result = $this->find($companyId, $sessionId);
        if ($result !== null) {
            $this->publish($companyId, $outletForPublish, $result);
        }
        return $result ?? [];
    }

    /**
     * Une dos cuentas: la sesión ORIGEN se absorbe en la DESTINO y su espacio
     * queda libre. Todo lo que colgaba de origen (órdenes y pagos parciales)
     * pasa a colgar de destino, que es la cuenta que sigue viva.
     *
     * ── Los tres casos que hacen que esto no sea un UPDATE ──────────────────
     *
     * 1. **Pedidos ya en cocina.** No se cancelan ni se re-emiten: cambian de
     *    sesión y siguen su ciclo de vida. Re-emitirlos duplicaría platos ya
     *    en la plancha; cancelarlos tiraría comida hecha. Como con `move()`,
     *    el nombre del espacio sale del JOIN vivo, así que la comanda pasa a
     *    decir la mesa destino con solo republicar.
     *
     * 2. **Pagos parciales ya registrados.** Las filas del ledger se mueven a
     *    la sesión destino con su `transactionid` intacto. Es lo único
     *    correcto: cada una es una venta REAL con su comprobante ya emitido e
     *    impreso; borrarlas falsearía la caja y re-emitirlas duplicaría el
     *    documento fiscal. Al mudarlas, lo ya cobrado se descuenta del saldo
     *    de la cuenta unificada — que es la definición de unir cuentas.
     *
     *    De ahí sale el guard duro de abajo: el módulo prohíbe MEZCLAR la
     *    familia `items` con `amount`/`share` en una misma sesión, y el motivo
     *    es stock, no plata (`SpaceSettlementService.php:329-356`,
     *    `context/modules/12-espacios.md` regla 2). Unir dos sesiones que
     *    usaron familias distintas produciría exactamente el estado prohibido,
     *    con el agravante de que nadie lo pidió explícitamente. Se rechaza.
     *
     * 3. **Numeración.** La fusión no emite ningún documento: no consume
     *    números ni toca correlativos. Los comprobantes ya emitidos por los
     *    pagos parciales de AMBAS sesiones siguen válidos y sin cambios — la
     *    cuenta unificada no re-factura lo ya cobrado, solo lo reconoce como
     *    pagado.
     */
    public function merge(string $companyId, string $sourceSessionId, string $targetSessionId, ?string $outletScope = null): array
    {
        global $db;

        if ($sourceSessionId === $targetSessionId) {
            throw new \InvalidArgumentException('No se puede unir una mesa consigo misma');
        }

        $orders        = new \Punto\Api\Orders\OrderCoreService($this->db);
        $movedOrderIds = [];
        $outletForPublish = '';

        $db->StartTrans();
        try {
            // Lock en orden determinístico (por id): dos cajas uniendo el mismo
            // par de mesas en sentidos opuestos se bloquearían mutuamente si
            // cada una tomara los locks en el orden que le tocó.
            $first  = strcmp($sourceSessionId, $targetSessionId) < 0 ? $sourceSessionId : $targetSessionId;
            $second = $first === $sourceSessionId ? $targetSessionId : $sourceSessionId;
            $rows   = [
                $first  => $this->lockSession($companyId, $first, $outletScope, true),
                $second => $this->lockSession($companyId, $second, $outletScope, true),
            ];
            $source = $rows[$sourceSessionId];
            $target = $rows[$targetSessionId];

            // Las DOS mesas: unir toca ambas cuentas por igual. Sin el guard
            // sobre la destino, un mozo podría empujarle su mesa a otro; sin el
            // guard sobre la origen, podría llevarse la mesa de un compañero.
            $this->assertOwnership($source, $companyId, 'unir');
            $this->assertOwnership($target, $companyId, 'unir');

            foreach ([['origen', $source], ['destino', $target]] as [$label, $row]) {
                if (!in_array($row['status'], ['open', 'bill_requested'], true)) {
                    throw new \InvalidArgumentException("La mesa $label no está abierta (" . $row['status'] . ')');
                }
            }
            if ((string) $source['outletid'] !== (string) $target['outletid']) {
                throw new \InvalidArgumentException('No se pueden unir mesas de sucursales distintas');
            }
            $outletForPublish = (string) $target['outletid'];

            $this->assertMergeablePaymentFamilies($companyId, $sourceSessionId, $targetSessionId);

            $movedOrderIds = $this->activeOrderIds($companyId, $sourceSessionId);

            $ok = $this->db->Execute(
                'UPDATE pos_order SET spacesessionid = ? WHERE spacesessionid = ? AND companyid = ?',
                [$targetSessionId, $sourceSessionId, $companyId]
            );
            if ($ok === false) throw new \RuntimeException('No se pudieron mover las órdenes');

            $ok = $this->db->Execute(
                'UPDATE space_session_payment SET sessionid = ? WHERE sessionid = ? AND companyid = ?',
                [$targetSessionId, $sourceSessionId, $companyId]
            );
            if ($ok === false) throw new \RuntimeException('No se pudieron mover los pagos parciales');

            // Comensales: se suman, es una sola mesa ahora. Alias: gana el de
            // la destino (es la cuenta que sobrevive) y solo se adopta el de la
            // origen si la destino no tenía — así unir nunca PIERDE la única
            // etiqueta que había, ni pisa la que el mozo eligió.
            $guests = null;
            if ($source['guests'] !== null || $target['guests'] !== null) {
                $guests = (int) ($source['guests'] ?? 0) + (int) ($target['guests'] ?? 0);
            }
            $alias = self::normalizeAlias(
                trim((string) ($target['alias'] ?? '')) !== ''
                    ? (string) $target['alias']
                    : (string) ($source['alias'] ?? '')
            );

            // `status='open'`: si la destino había pedido la cuenta, ese pedido
            // valía para el total de ESE momento y la fusión acaba de cambiarlo.
            // Misma regla que aplica OrderCoreService al agregar una orden a una
            // sesión en bill_requested (context/modules/12-espacios.md §2).
            $ok = $this->db->Execute(
                "UPDATE space_session SET guests = ?, alias = ?, status = 'open'
                  WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
                [$guests, $alias, $targetSessionId, $companyId]
            );
            if ($ok === false) throw new \RuntimeException('No se pudo actualizar la mesa destino');

            // La origen se cierra SIN saldo pendiente que validar: su saldo se
            // mudó entero a la destino. Por eso el UPDATE es directo y no pasa
            // por close(), que releería un saldo que ya no le corresponde.
            // `mergedinto` es lo que después distingue esto de un cierre vacío.
            $ok = $this->db->Execute(
                "UPDATE space_session SET status = 'closed', closed_at = now(), mergedinto = ?
                  WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
                [$targetSessionId, $sourceSessionId, $companyId]
            );
            if ($ok === false) throw new \RuntimeException('No se pudo cerrar la mesa de origen');
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo unir las mesas (transacción abortada)');
        }

        foreach ($movedOrderIds as $orderId) {
            $orders->publishOrderStatus($companyId, $orderId);
        }
        // Las dos: la origen para que el mapa la muestre libre, la destino para
        // que muestre la cuenta unificada.
        foreach ([$sourceSessionId, $targetSessionId] as $id) {
            $row = $this->find($companyId, $id);
            if ($row !== null) $this->publish($companyId, $outletForPublish, $row);
        }
        return $this->find($companyId, $targetSessionId) ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listByOutlet(string $companyId, string $outletId, ?string $status = null): array
    {
        $where  = ['companyid = ?', 'outletid = ?'];
        $params = [$companyId, $outletId];
        if ($status !== null && $status !== '') {
            $where[]  = 'status = ?';
            $params[] = $status;
        }
        $rs = $this->db->Execute(
            'SELECT * FROM space_session WHERE ' . implode(' AND ', $where) . ' ORDER BY opened_at DESC LIMIT 500',
            $params
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM space_session WHERE sessionid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Lee + valida scope. Con `$forUpdate` toma el lock de fila — obligatorio
     * para quien decida una escritura a partir de lo que lee (close() y su
     * validación de saldo), y SOLO sirve dentro de una TX: fuera, el lock se
     * libera al terminar la sentencia. Es el mismo lock que toma
     * SpaceSettlementService::registerPayment, así que cobro y cierre de una
     * misma sesión quedan serializados entre sí.
     */
    private function lockSession(string $companyId, string $sessionId, ?string $outletScope, bool $forUpdate = false): array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM space_session WHERE sessionid = ? AND companyid = ? LIMIT 1'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            [$sessionId, $companyId]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('Sesión no encontrada');
        }
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        $this->assertOutletScope((string) $row['outletid'], $outletScope);
        return $row;
    }

    /**
     * Exclusividad de mesa. Delega la REGLA en SpaceOwnershipGuard (que es
     * quien la documenta y quien la comparte con cualquier otro módulo que la
     * necesite) y acá solo se inyecta el operador de esta request.
     *
     * Los métodos que NO lo llaman están así a propósito:
     *   - `open()`      la mesa todavía no tiene dueño; se lo está poniendo.
     *   - `close()`     lo dispara el COBRO, y quien cobra es la caja, no el
     *                   mozo. Bloquearlo dejaría al cajero sin poder cerrar la
     *                   cuenta de una mesa ajena, que es literalmente su
     *                   trabajo. Además `close()` ya tiene su propio invariante
     *                   duro (no cierra con saldo pendiente).
     *   - `reopenFromBillRequested()`  lo llama OrderCoreService dentro de su
     *                   TX; la orden que lo dispara ya pasó por su propio guard.
     *
     * @param array<string,mixed> $sessionRow
     */
    private function assertOwnership(array $sessionRow, string $companyId, string $action): void
    {
        SpaceOwnershipGuard::assert($sessionRow, $this->operator, $companyId, $action);
    }

    /**
     * Alias: recortado y limitado al ancho de la columna (VARCHAR(60), mig
     * 163). Vacío se guarda como NULL, no como '' — "sin alias" es un solo
     * estado y no dos que después cada lector tiene que saber igualar.
     */
    private static function normalizeAlias(?string $alias): ?string
    {
        if ($alias === null) return null;
        $clean = trim(preg_replace('/\s+/u', ' ', $alias) ?? '');
        if ($clean === '') return null;
        return mb_substr($clean, 0, 60);
    }

    /**
     * Valida que un espacio pueda RECIBIR una sesión. Mismas condiciones que
     * `open()` exige sobre el espacio, más "en la misma sucursal": una mesa no
     * se muda de local.
     *
     * Devuelve el `tableid` y no la fila: `ncmExecute()` no devuelve un `array`
     * sino un `CaseInsensitiveArray` (el wrapper de BD del proyecto — resuelve
     * el nombre de columna sin importar el casing), así que tipar el retorno
     * como `array` explota en runtime. Devolver el único campo que el caller
     * necesita evita el problema de raíz en vez de aflojar el tipo, y de paso
     * no filtra el tipo del wrapper de BD fuera de este método.
     */
    private function assertUsableTarget(string $companyId, string $targetSpaceId, string $expectedOutletId): string
    {
        $target = ncmExecute(
            'SELECT tableid, outletid, status, shape FROM space WHERE tableid = ? AND companyid = ? LIMIT 1',
            [$targetSpaceId, $companyId]
        );
        if (!$target) {
            throw new \InvalidArgumentException('El espacio destino no existe');
        }
        if ((string) $target['outletid'] !== $expectedOutletId) {
            throw new \InvalidArgumentException('El espacio destino es de otra sucursal');
        }
        if ((int) $target['status'] === 0) {
            throw new \InvalidArgumentException('El espacio destino está deshabilitado');
        }
        if (in_array((string) ($target['shape'] ?? ''), SpaceService::DECOR_SHAPES, true)) {
            throw new \InvalidArgumentException('Un bloque decorativo no admite sesiones');
        }
        $busy = ncmExecute(
            "SELECT sessionid FROM space_session
              WHERE tableid = ? AND companyid = ? AND status IN ('open','bill_requested') LIMIT 1",
            [$targetSpaceId, $companyId]
        );
        if ($busy) {
            throw new \InvalidArgumentException('El espacio destino ya está ocupado');
        }
        return (string) $target['tableid'];
    }

    /**
     * Rechaza unir dos sesiones cuyas familias de cobro parcial son
     * incompatibles. Ver el docblock de `merge()` §2 — el invariante que
     * protege es de STOCK: `items` descuenta una vez vía CAS, `amount`/`share`
     * prorratean sobre lo no saldado sin marcar, y convivir hace que un ítem
     * se cobre dos veces con su stock descontado dos veces.
     */
    private function assertMergeablePaymentFamilies(string $companyId, string $sourceId, string $targetId): void
    {
        $families = [];
        foreach ([$sourceId, $targetId] as $id) {
            $rs = $this->db->Execute(
                'SELECT DISTINCT kind FROM space_session_payment WHERE companyid = ? AND sessionid = ?',
                [$companyId, $id]
            );
            if ($rs === false) continue;
            foreach ($rs->GetRows() as $row) {
                $families[(string) $row['kind'] === 'items' ? 'items' : 'prorrateo'] = true;
            }
        }
        if (isset($families['items'], $families['prorrateo'])) {
            throw new \InvalidArgumentException(
                'No se pueden unir estas mesas: una se está cobrando por ítems y la otra por monto/partes. '
                . 'Terminá de cobrar una de las dos antes de unirlas.'
            );
        }
    }

    /**
     * Órdenes que siguen vivas en una sesión — las que hay que republicar tras
     * un movimiento/fusión para que el KDS repinte el nombre de la mesa.
     *
     * @return array<int,string>
     */
    private function activeOrderIds(string $companyId, string $sessionId): array
    {
        $rs = $this->db->Execute(
            "SELECT orderid FROM pos_order
              WHERE spacesessionid = ? AND companyid = ? AND status NOT IN ('closed','cancelled')",
            [$sessionId, $companyId]
        );
        if ($rs === false) return [];
        $ids = [];
        foreach ($rs->GetRows() as $row) {
            $ids[] = (string) $row['orderid'];
        }
        return $ids;
    }

    private function assertOutletScope(string $sessionOutletId, ?string $outletScope): void
    {
        if ($outletScope !== null && $sessionOutletId !== $outletScope) {
            // No revelar existencia cross-outlet (mismo patrón OrderCoreService).
            throw new \RuntimeException('Espacio/sesión no encontrado');
        }
    }

    /**
     * Revierte `bill_requested` → `open`. La llama OrderCoreService::create()
     * cuando se agrega una orden a un espacio que ya había pedido la cuenta:
     * el pedido de cuenta valía para el total de ESE momento — si entra un
     * ítem más, ese total cambió y hay que volver a pedirla.
     *
     * PRECONDICIÓN DURA: el caller DEBE tener la fila de `space_session`
     * bloqueada con `SELECT ... FOR UPDATE` en la TX en curso. Este método no
     * toma lock propio y su CAS (`WHERE status='bill_requested'`) es un no-op
     * silencioso si el status cambió — sin el lock del caller eso sería una
     * carrera invisible. El publish lo dispara el caller después del commit
     * (ver publishSessionState).
     */
    public function reopenFromBillRequested(string $companyId, string $sessionId): void
    {
        $ok = $this->db->Execute(
            "UPDATE space_session SET status = 'open'
              WHERE sessionid = ? AND companyid = ? AND status = 'bill_requested'",
            [$sessionId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo reabrir la sesión del espacio');
        }
    }

    /** Publica el estado actual de una sesión — para orquestadores que la mutan dentro de su propia TX. */
    public function publishSessionState(string $companyId, string $sessionId): void
    {
        $session = $this->find($companyId, $sessionId);
        if ($session !== null) {
            $this->publish($companyId, (string) $session['outletId'], $session);
        }
    }

    private function publish(string $companyId, string $outletId, array $session): void
    {
        wsPublish($companyId . ':spaces:' . $outletId, 'space:state', [
            'outletId' => $outletId,
            'session'  => $session,
        ]);
        realtimePublish('space', 'update');
    }

    private function present(array $row): array
    {
        return [
            'id'                => (string) ($row['sessionid'] ?? ''),
            'companyId'         => (string) ($row['companyid'] ?? ''),
            'outletId'          => (string) ($row['outletid'] ?? ''),
            'spaceId'           => (string) ($row['tableid'] ?? ''),
            'status'            => (string) ($row['status'] ?? ''),
            'guests'            => isset($row['guests']) ? (int) $row['guests'] : null,
            'waiterId'          => $row['waiterid'] ?? null,
            'openedAt'          => $row['opened_at'] ?? null,
            'closedAt'          => $row['closed_at'] ?? null,
            'saleTransactionId' => $row['saletransactionid'] ?? null,
            'note'              => $row['note'] ?? null,
            // Alias de la OCUPACIÓN, no de la mesa (mig 163).
            'alias'             => $row['alias'] ?? null,
            'mergedInto'        => $row['mergedinto'] ?? null,
        ];
    }
}
