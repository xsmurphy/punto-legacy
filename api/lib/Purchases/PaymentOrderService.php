<?php
declare(strict_types=1);

namespace Punto\Api\Purchases;

use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Services\CreditPaymentService;
use Punto\Api\Services\TransactionLinkService;

require_once __DIR__ . '/../Documents/DocumentNumber.php';
require_once __DIR__ . '/../services/CreditPaymentService.php';
require_once __DIR__ . '/../services/TransactionLinkService.php';

/**
 * ORDEN DE PAGO A PROVEEDOR — el documento que AUTORIZA el pago (migs 196/197).
 *
 * Se arma agrupando N facturas de compra a crédito pendientes de un proveedor,
 * alguien con `purchases.paymentorder.approve` la aprueba, y recién ahí se
 * ejecuta. Estados: `draft` → `approved` → `paid`, más `cancelled`.
 *
 * ════════════════════════════════════════════════════════════════════════
 * LO QUE ESTA CLASE NO HACE: PAGAR
 * ════════════════════════════════════════════════════════════════════════
 *
 * `execute()` no inserta un recibo, no toca `transaction_link`, no marca
 * facturas como saldadas y no escribe en Finanzas. Traduce sus líneas al array
 * `allocations` y llama a `CreditPaymentService::create(..., isCustomer: false)`
 * — el MISMO servicio que ya usa `api/v1/credit-payments.php` desde el panel.
 * Todo lo que ese servicio garantiza (lock `FOR UPDATE` de las facturas en
 * orden determinístico, cálculo de deuda con `paidForCreditOrigins()` que
 * respeta notas de crédito, un solo recibo para N facturas, marcado de
 * `transactionComplete`, rollup, realtime) se hereda intacto.
 *
 * Por eso `payment_order_line` tiene el shape de una allocation
 * (`transactionid` + `amount`) y no uno propio: ejecutar es TRADUCIR, no
 * recalcular. Si algún día aparece algo que esta orden necesita expresar y no
 * se puede decir en una llamada a ese servicio, la respuesta correcta es
 * extender el servicio de pagos —no fabricar INSERTs acá—. Esa es la línea que
 * separa esta feature de un duplicado del módulo de pagos.
 *
 * Lo que queda AFUERA de `execute()` a propósito: `FinanceLedger::
 * recordPurchasePayment()`. No porque no haga falta, sino porque en este
 * codebase el asiento en Finanzas lo dispara el ENDPOINT post-commit,
 * best-effort, nunca el servicio (ver `credit-payments.php` y
 * `purchases.php`). `execute()` devuelve el `paymentTransactionId` justamente
 * para que `payment-orders.php` haga esa llamada con el mismo patrón. Meterla
 * acá adentro habría creado una segunda forma de poblar el ledger.
 *
 * ════════════════════════════════════════════════════════════════════════
 * TRANSACCIONES ANIDADAS
 * ════════════════════════════════════════════════════════════════════════
 *
 * `execute()` abre su propia TX y llama a `CreditPaymentService::create()`, que
 * abre otra. Es correcto y deliberado: `DB::StartTrans()` lleva contador de
 * anidamiento (`api/includes/lib/DB.php:776`) — solo el nivel más externo hace
 * `beginTransaction`/`commit` real. Así el cambio de estado de la orden y el
 * recibo con sus imputaciones commitean JUNTOS o no commitea ninguno: no hay
 * forma de terminar con una orden `paid` sin recibo, ni con un recibo sin
 * orden marcada.
 *
 * ════════════════════════════════════════════════════════════════════════
 * REVALIDACIÓN: AL APROBAR **Y** AL EJECUTAR
 * ════════════════════════════════════════════════════════════════════════
 *
 * El saldo de una factura NO se congela en la línea (ver el docblock de la mig
 * 196). Entre que se arma la orden y se paga pueden pasar cosas: alguien pagó
 * esa factura desde la ficha del proveedor, entró una nota de crédito de
 * compra, se anuló la compra. Por eso `assertLinesPayable()` corre en las tres
 * puertas —crear, aprobar y ejecutar— contra el saldo REAL del momento.
 *
 * Al ejecutar, además, `CreditPaymentService::create()` vuelve a validar por su
 * cuenta con el lock ya tomado. Esa es la validación que manda; la de acá
 * existe para dar un 422 explicando CUÁL factura y por cuánto, en vez de un
 * mensaje genérico sobre una allocation.
 *
 * ════════════════════════════════════════════════════════════════════════
 * INVARIANTES QUE VIVEN EN LA BASE, NO ACÁ
 * ════════════════════════════════════════════════════════════════════════
 *
 * - Una factura en UNA sola orden viva: índice único parcial
 *   `uidx_payment_order_line_live_invoice` sobre `orderstatus IN
 *   ('draft','approved')`, con `orderstatus` mantenida por trigger. El chequeo
 *   de PHP en `assertLinesPayable()` es para el mensaje de error, no para la
 *   corrección: dos requests concurrentes lo pasarían los dos y es el índice el
 *   que rechaza al segundo.
 * - Una orden `paid` es inmutable: trigger `fn_payment_order_paid_immutable()`.
 * - Cancelar exige motivo: CHECK `chk_payment_order_cancel_reason`.
 * - Atribución de cada estado terminal: los tres CHECK de atribución.
 */
final class PaymentOrderService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Tolerancia de comparación de montos — misma que usa CreditPaymentService. */
    private const EPSILON = 0.001;

    /** Tipo de documento en `document_sequence` (context/37). NO es fiscal. */
    public const DOC_TYPE = 'orden_pago';

    /** Tope de facturas por orden — ver `normalizeLines()`. */
    private const MAX_LINES = 200;

    /** Clave del ajuste por comercio en `company.config` (JSONB). */
    public const SETTING_SECOND_APPROVER = 'settingPaymentOrderRequireSecondApprover';

    private TransactionLinkService $links;

    public function __construct()
    {
        $this->links = new TransactionLinkService();
    }

    // ══════════════════════════════════════════════════════════════════
    // Ajuste del comercio
    // ══════════════════════════════════════════════════════════════════

    /**
     * ¿El comercio exige que el aprobador sea distinto del creador?
     *
     * APAGADO por default, y eso no es un detalle: el comercio de una sola
     * persona —el dueño que arma la orden y la aprueba él mismo— tiene que
     * poder trabajar sin fricción, y ninguna cuenta existente puede romperse
     * el día del deploy. Mismo criterio que `settingDrawerRequireClosedOrders`
     * (`ShiftCloseGate::isEnabled()`), de donde sale también la lectura: la
     * clave ausente significa "no está prendida", nunca "asumir que sí".
     *
     * Ojo: esto NO reemplaza al permiso. `purchases.paymentorder.approve` es el
     * gate DURO y aplica siempre; esto es una restricción ADICIONAL sobre quién
     * de los que ya pueden aprobar puede aprobar ESTA orden.
     */
    public function requiresSecondApprover(string $companyId): bool
    {
        if ($companyId === '') {
            return false;
        }
        $row = ncmExecute(
            "SELECT config->>'" . self::SETTING_SECOND_APPROVER . "' AS flag
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false
        );
        $v = strtolower((string) ($row['flag'] ?? ''));
        return in_array($v, ['1', 't', 'true', 'yes', 'on'], true);
    }

    // ══════════════════════════════════════════════════════════════════
    // Lectura
    // ══════════════════════════════════════════════════════════════════

    /**
     * Facturas de compra a crédito de un proveedor con saldo pendiente, listas
     * para armar una orden.
     *
     * El saldo sale de `TransactionLinkService::paidForCreditOrigins()` — la
     * MISMA función que usan `CreditPaymentService::create()` para validar
     * sobrepago y `OpenInvoicesService::payedByParent()` para el reporte de
     * cuentas por pagar. No hay una tercera fórmula acá, y no puede haberla:
     * una orden armada contra un saldo que el pago después no reconoce sería
     * una orden que no se puede ejecutar.
     *
     * Total CRUDO (sin restar `transactionDiscount`) porque es una compra —
     * misma regla que `OpenInvoicesService::openCreditInvoices()` y que el
     * camino de escritura. Para el cliente sería neto; acá no.
     *
     * `$excludeOrderId` deja fuera del "ya comprometida" a la propia orden que
     * se está editando: si no, editar un borrador mostraría sus propias
     * facturas como tomadas por otra orden.
     *
     * @return list<array{transactionId:string,invoiceNo:string,date:string,dueDate:string,total:float,paid:float,debt:float,committed:bool,committedOrderId:?string,committedDocNumber:?int}>
     */
    public function pendingInvoices(string $companyId, string $supplierId, ?string $excludeOrderId = null): array
    {
        if (!preg_match(self::UUID_RE, $supplierId)) {
            apiError('supplierId inválido', 422);
        }

        $rs = ncmExecute(
            "SELECT transactionId, transactionDate, transactionDueDate,
                    invoiceNo, invoicePrefix, transactionTotal, outletId
               FROM transaction
              WHERE companyId = ? AND supplierId = ?
                AND transactionType = 4
                AND transactionComplete = false
                AND COALESCE(transactionStatus, 1) <> 6
              ORDER BY transactionDueDate ASC NULLS LAST, transactionDate ASC",
            [$companyId, $supplierId],
            false, true
        );

        $rows = [];
        while ($rs && !$rs->EOF) {
            $rows[] = $rs->fields;
            $rs->MoveNext();
        }
        if ($rows === []) {
            return [];
        }

        $ids     = array_map(static fn ($r) => (string) $r['transactionId'], $rows);
        $paidMap = $this->links->paidForCreditOrigins($companyId, $ids, false);
        $committed = $this->committedInvoiceMap($companyId, $ids, $excludeOrderId);

        $out = [];
        foreach ($rows as $r) {
            $id    = (string) $r['transactionId'];
            $total = (float) ($r['transactionTotal'] ?? 0);
            $paid  = $paidMap[$id] ?? 0.0;
            $debt  = max(0.0, $total - $paid);
            if ($debt <= self::EPSILON) {
                // Saldada por una NC o un pago que `transactionComplete` no
                // alcanzó a reflejar. No se ofrece: imputarle algo sería un
                // sobrepago que el servicio de pagos rechaza igual.
                continue;
            }
            $c = $committed[$id] ?? null;
            $out[] = [
                'transactionId'      => $id,
                'invoiceNo'          => (string) ($r['invoicePrefix'] ?? '') . (string) ($r['invoiceNo'] ?? ''),
                'date'               => (string) ($r['transactionDate'] ?? ''),
                'dueDate'            => (string) ($r['transactionDueDate'] ?? ''),
                'outletId'           => (string) ($r['outletId'] ?? ''),
                'total'              => $total,
                'paid'               => $paid,
                'debt'               => $debt,
                // Se DEVUELVE la factura ya comprometida en otra orden viva, con
                // la marca — en vez de esconderla. Esconderla dejaría al usuario
                // preguntándose por qué no aparece una factura que sabe que
                // existe; con la marca, la UI la muestra deshabilitada y dice en
                // qué orden está. Mismo criterio que el badge de filtros activos.
                'committed'          => $c !== null,
                'committedOrderId'   => $c['paymentorderid'] ?? null,
                'committedDocNumber' => isset($c['docnumber']) ? (int) $c['docnumber'] : null,
            ];
        }

        return $out;
    }

    /**
     * Facturas de `$ids` que ya están en una orden VIVA (draft/approved).
     *
     * Lee `orderstatus` de la línea —la columna derivada que mantiene el
     * trigger—, no `payment_order.status`: es exactamente el predicado del
     * índice único, así que lo que esta query considera "comprometida" es lo
     * mismo que la base va a rechazar. Consultar la cabecera daría el mismo
     * resultado hoy pero podría divergir si alguien tocara el trigger.
     *
     * @param list<string> $ids
     * @return array<string, array{paymentorderid:string, docnumber:?string}>
     */
    private function committedInvoiceMap(string $companyId, array $ids, ?string $excludeOrderId = null): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($v) => $v !== '')));
        if ($ids === []) {
            return [];
        }
        $ph     = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$companyId], $ids);
        $sql = "SELECT l.transactionid, l.paymentorderid, po.docnumber
                  FROM payment_order_line l
                  JOIN payment_order po ON po.paymentorderid = l.paymentorderid
                 WHERE l.companyid = ?
                   AND l.orderstatus IN ('draft','approved')
                   AND l.transactionid IN ($ph)";
        if ($excludeOrderId !== null && $excludeOrderId !== '') {
            $sql .= ' AND l.paymentorderid <> ?';
            $params[] = $excludeOrderId;
        }

        $rows = ncmExecute($sql, $params, false, false, true);
        $rows = is_array($rows) ? $rows : [];
        $map  = [];
        foreach ($rows as $r) {
            $map[(string) $r['transactionid']] = [
                'paymentorderid' => (string) $r['paymentorderid'],
                'docnumber'      => $r['docnumber'] ?? null,
            ];
        }
        return $map;
    }

    /**
     * Una orden con sus líneas hidratadas (datos de la factura + saldo VIVO).
     *
     * El saldo que devuelve es el de AHORA, no el del momento en que se armó la
     * orden: es lo que la pantalla de detalle tiene que mostrar para que quien
     * aprueba vea si algo cambió desde que se armó.
     */
    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            apiError('id inválido', 422);
        }

        // Los NOMBRES se resuelven acá y no en el front. El detalle es la
        // pantalla donde alguien decide si aprueba un desembolso: mostrar
        // "createdby: 3f2a…" en vez de la persona convierte la atribución —el
        // punto entero de la feature— en un dato ilegible. Y resolverlo en el
        // cliente obligaría a bajar el padrón de contactos para leer una fila.
        //
        // `contactSecondName` NO es columna: la mig 25 la degradó al JSONB
        // `contact.data`. Se lee con `->>` (NUNCA el operador `?` de jsonb, que
        // PDO reescribe a placeholder), con la regla canónica de
        // `ContactDisplayName` — el nombre de la persona y, si no hay, la razón
        // social; nunca concatenados.
        $order = ncmExecute(
            "SELECT po.*,
                    COALESCE(NULLIF(s.data->>'contactSecondName', ''), s.contactName) AS suppliername,
                    o.outletName AS outletname,
                    COALESCE(NULLIF(uc.data->>'contactSecondName', ''), uc.contactName) AS createdbyname,
                    COALESCE(NULLIF(ua.data->>'contactSecondName', ''), ua.contactName) AS approvedbyname,
                    COALESCE(NULLIF(up.data->>'contactSecondName', ''), up.contactName) AS paidbyname,
                    COALESCE(NULLIF(ux.data->>'contactSecondName', ''), ux.contactName) AS cancelledbyname
               FROM payment_order po
               LEFT JOIN contact s  ON s.contactId  = po.supplierid   AND s.companyId = po.companyid
               LEFT JOIN outlet  o  ON o.outletId   = po.outletid     AND o.companyId = po.companyid
               LEFT JOIN contact uc ON uc.contactId = po.createdby    AND uc.companyId = po.companyid
               LEFT JOIN contact ua ON ua.contactId = po.approvedby   AND ua.companyId = po.companyid
               LEFT JOIN contact up ON up.contactId = po.paidby       AND up.companyId = po.companyid
               LEFT JOIN contact ux ON ux.contactId = po.cancelledby  AND ux.companyId = po.companyid
              WHERE po.paymentorderid = ? AND po.companyid = ? LIMIT 1",
            [$id, $companyId]
        );
        if (!$order) {
            return null;
        }

        $rows = ncmExecute(
            "SELECT l.paymentorderlineid, l.transactionid, l.amount,
                    t.invoiceNo, t.invoicePrefix, t.transactionDate, t.transactionDueDate,
                    t.transactionTotal, t.transactionStatus, t.transactionComplete
               FROM payment_order_line l
               LEFT JOIN transaction t
                 ON t.transactionId = l.transactionid AND t.companyId = l.companyid
              WHERE l.paymentorderid = ? AND l.companyid = ?
              ORDER BY t.transactionDueDate ASC NULLS LAST, l.created_at ASC",
            [$id, $companyId],
            false, false, true
        );
        $rows = is_array($rows) ? $rows : [];

        $ids     = array_map(static fn ($r) => (string) $r['transactionid'], $rows);
        $paidMap = $ids === [] ? [] : $this->links->paidForCreditOrigins($companyId, $ids, false);

        $lines = [];
        foreach ($rows as $r) {
            $tid   = (string) $r['transactionid'];
            $total = (float) ($r['transactionTotal'] ?? 0);
            $paid  = $paidMap[$tid] ?? 0.0;
            $lines[] = [
                'lineId'        => (string) $r['paymentorderlineid'],
                'transactionId' => $tid,
                'amount'        => (float) $r['amount'],
                'invoiceNo'     => (string) ($r['invoicePrefix'] ?? '') . (string) ($r['invoiceNo'] ?? ''),
                'date'          => (string) ($r['transactionDate'] ?? ''),
                'dueDate'       => (string) ($r['transactionDueDate'] ?? ''),
                'total'         => $total,
                'paid'          => $paid,
                'debt'          => max(0.0, $total - $paid),
                'voided'        => (int) ($r['transactionStatus'] ?? 1) === 6,
            ];
        }

        return [
            'order' => $this->shapeOrder($order) + [
                'supplierName'    => (string) ($order['suppliername'] ?? ''),
                'outletName'      => (string) ($order['outletname'] ?? ''),
                'createdByName'   => (string) ($order['createdbyname'] ?? ''),
                'approvedByName'  => (string) ($order['approvedbyname'] ?? ''),
                'paidByName'      => (string) ($order['paidbyname'] ?? ''),
                'cancelledByName' => (string) ($order['cancelledbyname'] ?? ''),
            ],
            'lines' => $lines,
        ];
    }

    /**
     * Listado con filtros. Sin paginación por ahora — una orden de pago es un
     * documento de baja frecuencia (no es una venta), y el `<DataTable>` del
     * panel pagina en cliente. Si el volumen lo pidiera, el corte natural es
     * `created_at` con cursor, no OFFSET.
     *
     * @param array{status?:string,supplierId?:string,outletIds?:list<string>,dateFrom?:string,dateTo?:string} $filters
     */
    public function list(string $companyId, array $filters = []): array
    {
        // Nombre del proveedor con la regla canónica del proyecto
        // (`ContactDisplayName`): el NOMBRE de la persona y, si no hay, la
        // razón social. NUNCA concatenados — son datos de distinta naturaleza,
        // y concatenarlos es el bug que originó ese helper.
        //
        // `contactSecondName` NO es una columna: la mig 25 la degradó al JSONB
        // `contact.data` (junto con dirección, nota, ciudad y CI). Se lee con
        // `->>` igual que `ContactDisplayName::batch()`. Ojo: `->>` sí, pero
        // NUNCA el operador `?` de jsonb — PDO lo reescribe a placeholder.
        $sql = "SELECT po.*,
                       COALESCE(NULLIF(c.data->>'contactSecondName', ''), c.contactName) AS suppliername,
                       o.outletName   AS outletname,
                       (SELECT COUNT(*) FROM payment_order_line l
                         WHERE l.paymentorderid = po.paymentorderid) AS linecount
                  FROM payment_order po
                  LEFT JOIN contact c ON c.contactId = po.supplierid AND c.companyId = po.companyid
                  LEFT JOIN outlet  o ON o.outletId  = po.outletid   AND o.companyId = po.companyid
                 WHERE po.companyid = ?";
        $params = [$companyId];

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, ['draft', 'approved', 'paid', 'cancelled'], true)) {
            $sql .= ' AND po.status = ?';
            $params[] = $status;
        }
        $supplierId = (string) ($filters['supplierId'] ?? '');
        if ($supplierId !== '') {
            if (!preg_match(self::UUID_RE, $supplierId)) {
                apiError('supplierId inválido', 422);
            }
            $sql .= ' AND po.supplierid = ?';
            $params[] = $supplierId;
        }
        // Alcance por sucursal (context/25). Se arma con OutletScope::sqlFilter
        // —que interpola uuids re-validados— y NO con placeholders, por el mismo
        // motivo documentado en OpenInvoicesService: pasar de una sucursal a dos
        // agregaría un `?` en el medio y correría los binds siguientes en
        // silencio. Acá abajo todavía quedan dos parámetros por bindear.
        $outletIds = is_array($filters['outletIds'] ?? null) ? $filters['outletIds'] : [];
        $sql .= \Punto\Api\Outlets\OutletScope::sqlFilter('po.outletid', $outletIds);

        $dateFrom = (string) ($filters['dateFrom'] ?? '');
        if ($dateFrom !== '') {
            $sql .= ' AND po.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        $dateTo = (string) ($filters['dateTo'] ?? '');
        if ($dateTo !== '') {
            $sql .= ' AND po.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        // `created_at`, no `paymentorderid`: los UUID de este Postgres son v4
        // random (`gen_random_uuid()` sin redefinir), así que ordenar por id NO
        // da "más reciente primero" — daría un orden arbitrario estable.
        $sql .= ' ORDER BY po.created_at DESC';

        $rows = ncmExecute($sql, $params, false, false, true);
        $rows = is_array($rows) ? $rows : [];

        return array_map(fn ($r) => $this->shapeOrder($r) + [
            'supplierName' => (string) ($r['suppliername'] ?? ''),
            'outletName'   => (string) ($r['outletname'] ?? ''),
            'lineCount'    => (int) ($r['linecount'] ?? 0),
        ], $rows);
    }

    /** Normaliza una fila de `payment_order` al shape que consume el front. Ver lockOrder() por qué `$r` no se tipa. */
    private function shapeOrder($r): array
    {
        return [
            'paymentOrderId'       => (string) ($r['paymentorderid'] ?? ''),
            'outletId'             => (string) ($r['outletid'] ?? ''),
            'supplierId'           => (string) ($r['supplierid'] ?? ''),
            'docNumber'            => isset($r['docnumber']) && $r['docnumber'] !== null ? (int) $r['docnumber'] : null,
            'status'               => (string) ($r['status'] ?? 'draft'),
            'total'                => (float) ($r['total'] ?? 0),
            'paymentDate'          => $r['paymentdate'] !== null ? (string) $r['paymentdate'] : null,
            'notes'                => $r['notes'] !== null ? (string) $r['notes'] : null,
            'createdBy'            => (string) ($r['createdby'] ?? ''),
            'createdAt'            => (string) ($r['created_at'] ?? ''),
            'approvedBy'           => $r['approvedby'] !== null ? (string) $r['approvedby'] : null,
            'approvedAt'           => $r['approved_at'] !== null ? (string) $r['approved_at'] : null,
            'paidBy'               => $r['paidby'] !== null ? (string) $r['paidby'] : null,
            'paidAt'               => $r['paid_at'] !== null ? (string) $r['paid_at'] : null,
            'paymentTransactionId' => $r['paymenttransactionid'] !== null ? (string) $r['paymenttransactionid'] : null,
            'cancelledBy'          => $r['cancelledby'] !== null ? (string) $r['cancelledby'] : null,
            'cancelledAt'          => $r['cancelled_at'] !== null ? (string) $r['cancelled_at'] : null,
            'cancelReason'         => $r['cancelreason'] !== null ? (string) $r['cancelreason'] : null,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // Validación compartida
    // ══════════════════════════════════════════════════════════════════

    /**
     * Normaliza las líneas del payload: `[{transactionId, amount}]` → mapa
     * `transactionId => amount`, con duplicados MERGEADOS (sumados).
     *
     * Se mergea en vez de rechazar por la misma razón que
     * `CreditPaymentService::create()`: un caller que manda la misma factura
     * dos veces está expresando un solo destino con dos montos, no un error. Y
     * si no se mergeara acá, el índice `uidx_payment_order_line_unique_in_order`
     * lo rechazaría con un mensaje del driver en vez de uno de negocio.
     *
     * @return array<string, float>
     */
    private function normalizeLines(array $lines): array
    {
        // Tope duro ANTES de mirar el contenido. `assertLinesPayable()` arma un
        // `IN (?,?,…)` con un placeholder por línea contra `transaction` —que
        // está particionada—, así que un payload de miles de líneas se traduce
        // en una query de miles de binds por partición. Ninguna orden de pago
        // real tiene 200 facturas; el límite es contra el payload absurdo, no
        // contra el uso.
        if (count($lines) > self::MAX_LINES) {
            apiError(
                'Una orden de pago no puede tener más de ' . self::MAX_LINES . ' facturas',
                422
            );
        }

        $merged = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                apiError('Cada línea debe ser un objeto {transactionId, amount}', 422);
            }
            $tid = (string) ($line['transactionId'] ?? '');
            if (!preg_match(self::UUID_RE, $tid)) {
                apiError('transactionId inválido en las líneas', 422);
            }
            $amt = (float) ($line['amount'] ?? 0);
            if ($amt <= 0) {
                apiError('Cada línea necesita un monto mayor a 0', 422);
            }
            $merged[$tid] = ($merged[$tid] ?? 0.0) + $amt;
        }
        if ($merged === []) {
            apiError('La orden de pago necesita al menos una factura', 422);
        }
        return $merged;
    }

    /**
     * La validación de negocio COMPLETA de un conjunto de líneas. Corre al
     * crear, al editar, al APROBAR y al EJECUTAR — no solo al crear.
     *
     * Que corra en las cuatro puertas es el punto: el saldo de una factura es
     * un derivado vivo. Entre que se arma la orden y se aprueba (o se ejecuta)
     * alguien pudo cobrarla desde la ficha del proveedor, aplicarle una nota de
     * crédito de compra o anular la compra entera. Validar solo al crear
     * dejaría aprobar un pago por más de lo que se debe.
     *
     * @param array<string,float> $merged  transactionId => monto imputado
     * @return float total de la orden
     */
    private function assertLinesPayable(
        string $companyId,
        string $supplierId,
        array $merged,
        ?string $excludeOrderId,
        string $verb
    ): float {
        $ids = array_keys($merged);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $rows = ncmExecute(
            "SELECT transactionId, supplierId, transactionType, transactionTotal,
                    transactionStatus, transactionComplete
               FROM transaction
              WHERE transactionId IN ($ph) AND companyId = ?",
            array_merge($ids, [$companyId]),
            false, false, true
        );
        $rows = is_array($rows) ? $rows : [];
        $byId = [];
        foreach ($rows as $r) {
            $byId[(string) $r['transactionId']] = $r;
        }

        $paidMap = $this->links->paidForCreditOrigins($companyId, $ids, false);
        $total   = 0.0;

        foreach ($ids as $tid) {
            if (!isset($byId[$tid])) {
                apiError('Factura de compra no encontrada: ' . $tid, 404);
            }
            $r = $byId[$tid];

            if ((string) ($r['transactionType'] ?? '') !== '4') {
                apiError('Solo se pueden incluir compras a crédito (factura ' . $tid . ')', 422);
            }
            if ((int) ($r['transactionStatus'] ?? 1) === 6) {
                apiError('La compra ' . $tid . ' está anulada y no se puede ' . $verb, 422);
            }
            if ((int) ($r['transactionComplete'] ?? 0) === 1) {
                apiError('La compra ' . $tid . ' ya está saldada', 422);
            }
            // Un recibo es de UN solo proveedor — `CreditPaymentService::create()`
            // lo rechaza explícitamente. Chequearlo acá evita armar una orden que
            // nunca se va a poder ejecutar.
            if ((string) ($r['supplierId'] ?? '') !== $supplierId) {
                apiError('Todas las facturas de una orden de pago deben ser del mismo proveedor', 422);
            }

            // Total CRUDO (compra), no neto de descuento — misma regla que
            // OpenInvoicesService y que CreditPaymentService para proveedores.
            $debt = max(0.0, (float) ($r['transactionTotal'] ?? 0) - ($paidMap[$tid] ?? 0.0));
            if (round($merged[$tid], 4) > round($debt, 4) + self::EPSILON) {
                apiError(
                    'El monto imputado a la factura ' . $tid . ' supera su saldo pendiente ('
                    . \Punto\App\Domain\Money::formatNumber($debt) . ')',
                    422
                );
            }
            $total += $merged[$tid];
        }

        // Ya comprometida en otra orden viva. El índice único de la mig 196 es
        // quien REALMENTE lo impide (dos requests concurrentes pasarían este
        // chequeo las dos); esto existe para poder decir CUÁL orden la tiene.
        $committed = $this->committedInvoiceMap($companyId, $ids, $excludeOrderId);
        if ($committed !== []) {
            $first = array_key_first($committed);
            $doc   = $committed[$first]['docnumber'];
            apiError(
                'La factura ' . $first . ' ya está incluida en otra orden de pago vigente'
                . ($doc !== null ? ' (N.º ' . $doc . ')' : '')
                . '. Cancelala o quitá la factura de esa orden antes de incluirla acá.',
                422
            );
        }

        return $total;
    }

    /**
     * Lockea la orden y devuelve su fila, o corta con el error apropiado.
     *
     * SIN tipo de retorno `array` a propósito: `ncmExecute()` devuelve un
     * `CaseInsensitiveArray` (el wrapper de DB del proyecto, `includes/lib/
     * DB.php:62`), que implementa ArrayAccess pero NO es un `array` de PHP.
     * Declarar `: array` acá tiraba un TypeError en runtime —no en el lint— y
     * rompía aprobar, ejecutar y cancelar. Mismo motivo por el que
     * `assertStatus()` y `shapeOrder()` reciben la fila sin tipar.
     *
     * @return \ArrayAccess|array la fila de payment_order
     */
    private function lockOrder(string $id, string $companyId)
    {
        if (!preg_match(self::UUID_RE, $id)) {
            apiError('id inválido', 422);
        }
        $order = ncmExecute(
            'SELECT * FROM payment_order WHERE paymentorderid = ? AND companyid = ? FOR UPDATE',
            [$id, $companyId]
        );
        if (!$order) {
            global $db;
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('Orden de pago no encontrada', 404);
        }
        return $order;
    }

    /** Corta si la orden no está en uno de los estados esperados. Ver lockOrder() por qué `$order` no se tipa. */
    private function assertStatus($order, array $allowed, string $action): void
    {
        $status = (string) ($order['status'] ?? '');
        if (in_array($status, $allowed, true)) {
            return;
        }
        global $db;
        $db->FailTrans();
        $db->CompleteTrans();
        apiError('No se puede ' . $action . ' una orden ' . self::statusLabel($status), 422);
    }

    private static function statusLabel(string $status): string
    {
        return [
            'draft'     => 'en borrador',
            'approved'  => 'aprobada',
            'paid'      => 'ya ejecutada',
            'cancelled' => 'cancelada',
        ][$status] ?? $status;
    }

    /** Reescribe las líneas de una orden. Solo se llama con la orden lockeada. */
    private function replaceLines(string $orderId, string $companyId, array $merged): void
    {
        global $db;
        $db->Execute(
            'DELETE FROM payment_order_line WHERE paymentorderid = ? AND companyid = ?',
            [$orderId, $companyId]
        );
        foreach ($merged as $tid => $amount) {
            // `orderstatus` NO se manda: el trigger BEFORE INSERT lo resuelve
            // desde la cabecera. Mandarlo sería poder falsificarlo.
            $ok = $db->AutoExecute('payment_order_line', [
                'companyid'      => $companyId,
                'paymentorderid' => $orderId,
                'transactionid'  => $tid,
                'amount'         => $amount,
            ], 'INSERT');
            if (!$ok) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('No se pudo guardar la línea de la factura ' . $tid, 500);
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Escritura
    // ══════════════════════════════════════════════════════════════════

    /**
     * Crea la orden en `draft`.
     *
     * `docnumber` se asigna ACÁ, con `DocumentNumber::allocate()` DENTRO de la
     * transacción (D1 de context/37): si el alta falla, el rollback devuelve el
     * número y no queda hueco. Scope `outlet` — una orden de pago se emite
     * desde el backoffice, sin caja de por medio. NO es documento fiscal: no
     * toca timbrado ni SIFEN.
     */
    public function create(
        string  $companyId,
        string  $userId,
        string  $supplierId,
        string  $outletId,
        array   $lines,
        ?string $paymentDate = null,
        ?string $notes = null
    ): array {
        global $db;

        if (!preg_match(self::UUID_RE, $supplierId)) {
            apiError('supplierId inválido', 422);
        }
        if (!preg_match(self::UUID_RE, $outletId)) {
            apiError('outletId inválido', 422);
        }
        $merged = $this->normalizeLines($lines);

        $db->StartTrans();

        $total = $this->assertLinesPayable($companyId, $supplierId, $merged, null, 'incluir');

        $docNumber = DocumentNumber::allocate(self::DOC_TYPE, DocumentNumber::SCOPE_OUTLET, $outletId, $companyId);

        $ok = $db->AutoExecute('payment_order', [
            'companyid'   => $companyId,
            'outletid'    => $outletId,
            'supplierid'  => $supplierId,
            'docnumber'   => $docNumber,
            'status'      => 'draft',
            'total'       => $total,
            'paymentdate' => self::normalizeDate($paymentDate),
            'notes'       => ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
            'createdby'   => $userId,
        ], 'INSERT');
        if (!$ok) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('No se pudo crear la orden de pago', 500);
        }

        $orderId = (string) $db->Insert_ID();
        $this->replaceLines($orderId, $companyId, $merged);

        $db->CompleteTrans();

        $this->publish();

        return ['paymentOrderId' => $orderId, 'docNumber' => $docNumber, 'total' => $total, 'status' => 'draft'];
    }

    /**
     * Edita una orden — SOLO en borrador.
     *
     * Una vez aprobada, cambiar las facturas o los montos convertiría la
     * aprobación en una firma en blanco: se aprobó una cosa y se pagaría otra.
     * Para cambiar una orden aprobada hay que cancelarla y armar otra, que deja
     * rastro de las dos.
     *
     * No se puede cambiar el proveedor ni la sucursal: el proveedor porque las
     * líneas ya validadas dejarían de pertenecerle, y la sucursal porque el
     * correlativo ya se asignó contra esa secuencia (mover la orden a otra
     * sucursal dejaría un hueco en una y un número fuera de orden en la otra).
     */
    public function update(
        string  $id,
        string  $companyId,
        array   $lines,
        ?string $paymentDate = null,
        ?string $notes = null
    ): array {
        global $db;

        $merged = $this->normalizeLines($lines);

        $db->StartTrans();

        $order = $this->lockOrder($id, $companyId);
        $this->assertStatus($order, ['draft'], 'editar');

        $total = $this->assertLinesPayable(
            $companyId,
            (string) $order['supplierid'],
            $merged,
            $id,
            'incluir'
        );

        $db->Execute(
            'UPDATE payment_order
                SET total = ?, paymentdate = ?, notes = ?, updated_at = now()
              WHERE paymentorderid = ? AND companyid = ?',
            [
                $total,
                self::normalizeDate($paymentDate),
                ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
                $id,
                $companyId,
            ]
        );

        $this->replaceLines($id, $companyId, $merged);

        $db->CompleteTrans();

        $this->publish();

        return ['paymentOrderId' => $id, 'total' => $total, 'status' => 'draft'];
    }

    /**
     * Aprueba la orden: `draft` → `approved`.
     *
     * Dos gates, y son distintos:
     *
     *  1. El PERMISO `purchases.paymentorder.approve` — lo chequea el endpoint
     *     antes de llegar acá, igual que el resto del codebase. Es el gate duro
     *     y no tiene excepciones.
     *  2. El SEGUNDO APROBADOR — `$requireSecondApprover`, que sale del ajuste
     *     del comercio. Prendido, quien creó la orden no puede aprobarla.
     *     Apagado (default), sí. Se recibe como parámetro en vez de leerlo acá
     *     para que el arnés pueda probar los dos caminos sin tocar la config, y
     *     para que el endpoint lo resuelva UNA vez.
     *
     * Revalida las líneas contra el saldo real antes de aprobar: entre que se
     * armó y ahora, alguien pudo pagar esa factura por otro lado.
     */
    public function approve(string $id, string $companyId, string $userId, bool $requireSecondApprover): array
    {
        global $db;

        $db->StartTrans();

        $order = $this->lockOrder($id, $companyId);
        $this->assertStatus($order, ['draft'], 'aprobar');

        if ($requireSecondApprover && (string) $order['createdby'] === $userId) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError(
                'Tu comercio exige que la orden la apruebe alguien distinto de quien la creó.',
                403
            );
        }

        $merged = $this->currentLines($id, $companyId);
        $total  = $this->assertLinesPayable($companyId, (string) $order['supplierid'], $merged, $id, 'aprobar');

        $db->Execute(
            "UPDATE payment_order
                SET status = 'approved', approvedby = ?, approved_at = now(), total = ?, updated_at = now()
              WHERE paymentorderid = ? AND companyid = ?",
            [$userId, $total, $id, $companyId]
        );

        $db->CompleteTrans();

        $this->publish();

        return ['paymentOrderId' => $id, 'status' => 'approved', 'total' => $total];
    }

    /**
     * EJECUTA la orden: `approved` → `paid`.
     *
     * Acá está el corazón de la feature, y lo que hace es DELEGAR. Traduce las
     * líneas al array `allocations` y llama a `CreditPaymentService::create()`
     * con `isCustomer: false` — el mismo camino que ya usa el panel para pagarle
     * a un proveedor. No se inserta un recibo, no se toca `transaction_link`, no
     * se marca ninguna factura: todo eso lo hace ese servicio.
     *
     * Las dos llamadas van en la MISMA transacción (StartTrans anida, ver el
     * docblock de la clase): o queda la orden `paid` CON su recibo, o no queda
     * nada. `paymenttransactionid` NOT NULL cuando `status='paid'` es el CHECK
     * que hace imposible el estado intermedio.
     *
     * Lo que NO hace: el asiento en Finanzas. Lo dispara el endpoint
     * post-commit, best-effort, como en `credit-payments.php` — por eso devuelve
     * `paymentTransactionId`.
     */
    public function execute(
        string  $id,
        string  $companyId,
        string  $userId,
        string  $paymentMethodKey,
        ?string $note = null,
        ?string $identifier = null,
        ?array  $supplierDoc = null
    ): array {
        global $db;

        if (trim($paymentMethodKey) === '') {
            apiError('paymentMethodKey requerido', 422);
        }

        $db->StartTrans();

        $order = $this->lockOrder($id, $companyId);
        // Solo `approved`: un borrador no está autorizado. Que ejecutar exija
        // pasar por aprobación es la feature entera — sin esto, la orden sería
        // un formulario más de pago.
        $this->assertStatus($order, ['approved'], 'ejecutar');

        $merged = $this->currentLines($id, $companyId);
        // Tercera revalidación (crear/aprobar/ejecutar). `CreditPaymentService`
        // vuelve a validar por su cuenta con el lock tomado —esa es la que
        // manda—; esta existe para el mensaje de negocio.
        $this->assertLinesPayable($companyId, (string) $order['supplierid'], $merged, $id, 'pagar');

        $allocations = [];
        foreach ($merged as $tid => $amount) {
            $allocations[] = ['parentTransactionId' => $tid, 'amount' => $amount];
        }

        // ── Delegación. Todo el pago pasa por acá y solo por acá. ──────────
        $receipt = (new CreditPaymentService())->create(
            $companyId,
            $userId,
            $allocations,
            $paymentMethodKey,
            $note,
            $identifier,
            false,          // isCustomer=false → pago a PROVEEDOR (kind purchase_payment)
            $supplierDoc
        );

        $paymentId = (string) ($receipt['id'] ?? '');
        if ($paymentId === '') {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('El pago no devolvió un recibo; la orden no se marcó como pagada', 500);
        }

        $db->Execute(
            "UPDATE payment_order
                SET status = 'paid', paidby = ?, paid_at = now(),
                    paymenttransactionid = ?, updated_at = now()
              WHERE paymentorderid = ? AND companyid = ?",
            [$userId, $paymentId, $id, $companyId]
        );

        $db->CompleteTrans();

        $this->publish();

        return [
            'paymentOrderId'       => $id,
            'status'               => 'paid',
            'paymentTransactionId' => $paymentId,
            'amount'               => (float) ($receipt['amount'] ?? 0),
            'allocations'          => $receipt['allocations'] ?? [],
        ];
    }

    /**
     * Cancela la orden. Exige motivo y registra quién la canceló — mismo
     * criterio que la anulación de ítems de comanda
     * (`OrderCoreService::updateStatus`): un estado terminal que descarta
     * trabajo se explica, o no se aplica.
     *
     * Se puede cancelar en `draft` y en `approved`. En `paid` no: ahí ya se
     * movió plata, y deshacer eso es anular el RECIBO
     * (`DELETE /v1/credit-payments?id=…`, que revierte la imputación y el
     * asiento), no tachar el documento que lo autorizó. El trigger
     * `fn_payment_order_paid_immutable()` lo impide igual.
     *
     * ── Por qué el PERMISO se decide ACÁ y no en el endpoint ──────────────
     *
     * El permiso que hace falta depende del ESTADO: descartar un borrador es
     * deshacer trabajo de carga (`create`), pero anular una orden ya APROBADA
     * revierte una decisión de autoridad (`approve`). El endpoint no puede
     * elegir el gate por su cuenta: tendría que leer el estado antes de que la
     * fila esté lockeada, y entre esa lectura y el lock otra sesión puede
     * aprobar la orden — con lo cual alguien que solo tiene `create` terminaría
     * cancelando una orden aprobada. Ventana chica, agujero real.
     *
     * Por eso el endpoint pasa las DOS capacidades del usuario y la decisión se
     * toma contra la fila que ya está bajo `FOR UPDATE`: el estado que se usa
     * para elegir el permiso es exactamente el estado que se va a cancelar.
     */
    public function cancel(
        string $id,
        string $companyId,
        string $userId,
        string $reason,
        bool   $canCancelDraft,
        bool   $canCancelApproved
    ): array {
        global $db;

        $reason = trim($reason);
        if ($reason === '') {
            apiError('Cancelar una orden de pago exige un motivo', 422);
        }

        $db->StartTrans();

        $order = $this->lockOrder($id, $companyId);
        $this->assertStatus($order, ['draft', 'approved'], 'cancelar');

        $locked  = (string) $order['status'];
        $allowed = $locked === 'approved' ? $canCancelApproved : $canCancelDraft;
        if (!$allowed) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError(
                $locked === 'approved'
                    ? 'No tenés permiso para cancelar una orden ya aprobada '
                      . '(requiere: purchases.paymentorder.approve)'
                    : 'No tenés permiso para esta acción (requiere: purchases.paymentorder.create)',
                403
            );
        }

        $db->Execute(
            "UPDATE payment_order
                SET status = 'cancelled', cancelledby = ?, cancelled_at = now(),
                    cancelreason = ?, updated_at = now()
              WHERE paymentorderid = ? AND companyid = ?",
            [$userId, $reason, $id, $companyId]
        );

        // Las líneas NO se borran: son la evidencia de qué se había propuesto
        // pagar. El trigger de propagación les pone `orderstatus='cancelled'`,
        // lo que las saca del índice único y libera esas facturas para otra
        // orden — que es exactamente lo que se quiere al cancelar.
        $db->CompleteTrans();

        $this->publish();

        return ['paymentOrderId' => $id, 'status' => 'cancelled'];
    }

    /**
     * Líneas persistidas de una orden, como mapa `transactionId => monto`.
     * Fuente de verdad para aprobar y ejecutar: nunca se confía en un array de
     * líneas que mande el caller en esos dos verbos — se aprueba y se paga lo
     * que está GUARDADO, no lo que el cliente dice que está guardado.
     *
     * @return array<string,float>
     */
    private function currentLines(string $orderId, string $companyId): array
    {
        $rows = ncmExecute(
            'SELECT transactionid, amount FROM payment_order_line
              WHERE paymentorderid = ? AND companyid = ?',
            [$orderId, $companyId],
            false, false, true
        );
        $rows = is_array($rows) ? $rows : [];
        $out  = [];
        foreach ($rows as $r) {
            $out[(string) $r['transactionid']] = (float) $r['amount'];
        }
        if ($out === []) {
            global $db;
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('La orden de pago no tiene facturas', 422);
        }
        return $out;
    }

    /**
     * Sync en tiempo real (regla base: toda mutación del tenant emite evento).
     * Best-effort post-commit — nunca rompe la operación ya confirmada.
     */
    private function publish(): void
    {
        try {
            realtimePublish('payment_order', 'update', null);
        } catch (\Throwable $e) {
            // Ignorar — no crítico.
        }
    }

    /** 'YYYY-MM-DD' válido → tal cual; cualquier otra cosa → null. Nunca inventa un default. */
    private static function normalizeDate(?string $val): ?string
    {
        $val = $val !== null ? trim($val) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : null;
    }
}
