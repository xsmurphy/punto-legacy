<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * Auto-poblado de `fin_movement` desde la operación real (Fase 3).
 *
 * Cada método re-lee la fila de origen por id — así el MISMO código sirve
 * para el hook post-commit en vivo Y para el backfill histórico
 * (`api/database/seeds/finance_backfill.php`). Todo es best-effort e
 * idempotente: los call-sites en vivo lo envuelven en try/catch para nunca
 * romper la operación real (venta/compra/caja) si Finanzas falla.
 *
 * Pago dividido (split-payment): una venta puede traer varias líneas de pago
 * (`transactionPaymentType`), cada una resolviendo a una cuenta distinta
 * (Efectivo, Tarjeta, etc). Regla: 1 movimiento por (origen, cuenta
 * resuelta) — se agrupan/suman las líneas que caen en la misma cuenta.
 * Idempotencia vía UNIQUE (companyid, source, sourceid, accountid) — mig 73.
 *
 * Multi-tenant: $companyId siempre explícito (§33.2). Columnas físicas
 * lowercase sin comillas (bug case-sensitivity mig 71).
 */
final class FinanceLedger
{
    private MovementService $movements;
    private AccountService $accounts;
    private CategoryService $categories;
    private ConfigService $config;

    public function __construct()
    {
        $this->movements  = new MovementService();
        $this->accounts   = new AccountService();
        $this->categories = new CategoryService();
        $this->config     = new ConfigService();
    }

    /**
     * Venta al contado (transactionType=0). Las ventas a crédito (type=3) NO
     * generan movimiento acá — se genera cuando se cobran (recordCreditPayment).
     */
    public function recordSale(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '0') {
            return;
        }

        $categoryId = $this->categories->ensureSalesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Venta' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        // F1 cheques (context/30): el cheque nace del pago — si alguna línea
        // usa el método con systemKey='check', crea el fin_check (pending)
        // ANTES de recordPaymentLines. Independiente de esa función: el
        // cheque nace siempre, tenga o no la línea una cuenta mapeada.
        // Categoría: la misma "Ventas" que llevaría el movimiento si no fuera
        // cheque — evita que el cheque quede sin clasificar al efectivizarse.
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $this->decodePaymentLines($row),
            'received',
            $this->nullableUuid($row['customerId'] ?? null),
            $categoryId,
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'sale',
            sourceId: $transactionId,
            row: $row,
            kind: 'income',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /** Pago de una factura a crédito (transactionType=5). */
    public function recordCreditPayment(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '5') {
            return;
        }

        $categoryId = $this->categories->ensureSalesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Pago de crédito' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        // F1 cheques (context/30) — ver comentario espejo en recordSale().
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $this->decodePaymentLines($row),
            'received',
            $this->nullableUuid($row['customerId'] ?? null),
            $categoryId,
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'credit_payment',
            sourceId: $transactionId,
            row: $row,
            kind: 'income',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /**
     * Pago a proveedor de una compra a crédito (transactionType=5, mismo tipo
     * que `credit_payment` — se distinguen por `supplierId` seteado en vez de
     * `customerId`, igual que `CreditPaymentService::create()` los arma).
     * Espejo de `recordCreditPayment()` con el signo opuesto: acá SALE plata
     * de la caja (kind='expense'), no entra.
     *
     * NO divide por categoría (F0 gasto dividido, owner 2026-08-20, alcance
     * = compras al CONTADO). La fila type=5 que se paga acá es el PAGO, no
     * la compra original — no trae items propios en `meta.details`, así que
     * no hay de dónde leer categoría por línea sin ir a buscar la compra
     * origen vía `transaction_link`. Queda con el default genérico
     * "Proveedores" de siempre, a propósito — corte de scope, no olvido.
     */
    public function recordPurchasePayment(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '5') {
            return;
        }

        $categoryId = $this->categories->ensurePurchasesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Pago a proveedor' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        // F1 cheques (context/30) — pago a proveedor con cheque = cheque
        // EMITIDO por el comercio (espejo de recordPurchase()).
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $this->decodePaymentLines($row),
            'issued',
            $this->nullableUuid($row['supplierId'] ?? null),
            $categoryId,
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'purchase_payment',
            sourceId: $transactionId,
            row: $row,
            kind: 'expense',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /** Compra al contado (transactionType=1). */
    public function recordPurchase(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        // Solo compras al CONTADO (type 1) generan egreso al crearse. Una
        // compra a CRÉDITO (type 4) no mueve plata todavía: es una cuenta por
        // pagar, y el movimiento nace cuando se registra el pago a proveedor
        // (type 5, vinculado vía transaction_link kind='purchase_payment', mig
        // 115). Sin este corte el crédito
        // debitaría la caja dos veces (al comprar y al pagar).
        if ((string) ($row['transactionType'] ?? '') !== '1') {
            return;
        }

        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Compra' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        // F0 gasto dividido por categoría (owner 2026-08-20): cada línea de
        // la compra ya trae su categoría RESUELTA (PurchasesService::create()
        // aplicó la precedencia línea > cabecera > ítem antes de persistir en
        // meta.details) — acá solo agrupamos por categoría y prorrateamos el
        // descuento de cabecera en porciones EXACTAS (no estimadas). null
        // cuando NINGUNA línea/cabecera/ítem trajo categoría — ese caso sigue
        // funcionando como antes de este cambio (1 movimiento, categoría
        // default "Proveedores").
        $categorySplit = $this->resolveCategorySplit($row);
        $defaultCategoryId = $this->categories->ensurePurchasesCategoryId($companyId);
        // Un cheque es UN documento por el importe TOTAL — no se puede
        // repartir por categoría (compras no soportan pago dividido, ver
        // PurchasesService::create(): siempre 1 sola línea de pago). Usa la
        // categoría de cabecera si el comercio la puso; si el split resolvió
        // a una sola categoría real, esa; si no, el default de siempre.
        $checkCategoryId = $this->resolveHeaderCategoryId($row)
            ?? (is_array($categorySplit) && count($categorySplit) === 1 ? $categorySplit[0][0] : null)
            ?? $defaultCategoryId;

        $lines = $this->decodePaymentLines($row);
        if (empty($lines)) {
            // Sin líneas de pago detalladas: dato legacy previo a la mig 102
            // (Parte 2 del incidente 2026-07 — 198 compras, ~737M debitados
            // erróneamente de Efectivo por un fallback que asumía cash — ya
            // resuelta: el form de compra persiste método+cuenta como ventas,
            // y `_getTableSchema()` ya no enruta transactionPaymentType al
            // JSONB `meta`). NO hay una cuenta real a la que imputar acá — se
            // salta el movimiento en vez de adivinar.
            error_log("[FinanceLedger] recordPurchase: compra sin línea de pago (legacy pre-102) — transactionId={$transactionId}");
            return;
        }

        // F1 cheques (context/30) — ver comentario espejo en recordSale().
        // Compra pagada con cheque = cheque EMITIDO por el comercio.
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $lines,
            'issued',
            $this->nullableUuid($row['supplierId'] ?? null),
            $checkCategoryId,
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'purchase',
            sourceId: $transactionId,
            row: $row,
            kind: 'expense',
            categoryId: $defaultCategoryId,
            description: $description,
            categorySplit: $categorySplit,
            costCenterId: $this->resolveCostCenterId($row),
        );
    }

    /**
     * Devolución (transactionType=6). Sale plata de la caja → kind='expense'.
     *
     * ReturnService persiste la línea de pago en NEGATIVO (`total` = -neto);
     * `recordPaymentLines` toma abs() y el signo contable lo define $kind, así
     * que el movimiento queda como egreso por el neto devuelto.
     *
     * Devolución acreditada al cliente (`refundMode='credit'` → línea
     * `type='storeCredit'`): NO genera movimiento. No sale plata de ninguna
     * cuenta — sube `contact.contactStoreCredit`, que es un pasivo con el
     * cliente, no caja. El filtro vive en `recordPaymentLines` (compartido con
     * ventas: una venta pagada con crédito interno / puntos / gift card tampoco
     * mueve plata).
     */
    public function recordReturn(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '6') {
            return;
        }

        // El nro de comprobante de una devolución vive en la venta original
        // (la fila type=6 se inserta sin invoiceNo) — se trae por el origen
        // (transaction_link kind='return', mig 115, reemplaza transactionParentId)
        // para que el movimiento sea rastreable hasta la venta que se devolvió.
        $parentId = (new \Punto\Api\Services\TransactionLinkService())->listOriginIds($companyId, $transactionId, 'return')[0] ?? null;
        $invoiceNo = '';
        if ($parentId !== null) {
            $parent = ncmExecute(
                'SELECT invoiceNo FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
                [$parentId, $companyId]
            );
            $invoiceNo = $parent ? (string) ($parent['invoiceNo'] ?? '') : '';
        }

        $categoryId = $this->categories->ensureReturnsCategoryId($companyId);
        $description = 'Devolución' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'return',
            sourceId: $transactionId,
            row: $row,
            kind: 'expense',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /**
     * Nota de crédito de compra en modo 'cash' (transactionType=14). El
     * proveedor nos devuelve plata → entra a la cuenta como INGRESO. Modo
     * 'credit' (reduce saldo pendiente de una compra a crédito, no mueve
     * caja) nunca llega acá con datos: `PurchaseCreditNoteService::create()`
     * no escribe `transactionPaymentType` en ese modo, así que
     * `decodePaymentLines`/`recordPaymentLines` no encuentran nada que
     * registrar y el método es un no-op — mismo mecanismo de filtro que usa
     * `recordReturn` para su `refundMode='credit'`.
     */
    public function recordPurchaseCreditNote(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '14') {
            return;
        }

        // El nro de comprobante de la compra original vive en la compra padre
        // (la fila type=14 se inserta sin invoiceNo) — se trae por el origen
        // (transaction_link kind='purchase_credit_note', mig 122) para que el
        // movimiento sea rastreable hasta la compra que se acreditó.
        $parentId = (new \Punto\Api\Services\TransactionLinkService())->listOriginIds($companyId, $transactionId, 'purchase_credit_note')[0] ?? null;
        $invoiceNo = '';
        // El centro de costo se hereda de la COMPRA ORIGINAL, no de la fila
        // type=14: `PurchaseCreditNoteService` inserta la NC con `meta = '{}'`
        // (no copia la meta del padre), así que acá es el único lugar donde el
        // dato está disponible. Y corresponde heredarlo: la NC es el espejo
        // contable de la compra — si el gasto se imputó a "Obra Norte", la
        // devolución al proveedor tiene que DESCONTARSE de "Obra Norte", no
        // quedar sin centro. Si quedara null, el total por centro mostraría el
        // gasto inflado (la compra suma, la devolución no resta ahí).
        $ccFromParent = null;
        if ($parentId !== null) {
            $parent = ncmExecute(
                'SELECT invoiceNo, meta FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
                [$parentId, $companyId]
            );
            $invoiceNo    = $parent ? (string) ($parent['invoiceNo'] ?? '') : '';
            $ccFromParent = $parent ? $this->resolveCostCenterId($parent) : null;
        }

        $categoryId  = $this->categories->ensurePurchaseCreditNoteCategoryId($companyId);
        $description = 'Nota de crédito compra' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'purchase_credit_note',
            sourceId: $transactionId,
            row: $row,
            kind: 'income',
            categoryId: $categoryId,
            description: $description,
            costCenterId: $ccFromParent,
        );
    }

    /**
     * Extracción de caja (`expenses`, type NULL = extracción). $expensesId es
     * el id de la fila insertada — DrawerService::addExpense debe pasarlo
     * (usa ncmInsert en vez de $db->Execute raw para poder recuperarlo).
     */
    public function recordDrawerExpense(string $companyId, string $expensesId): void
    {
        $this->recordDrawerRow($companyId, $expensesId, 'expense');
    }

    /** Ingreso de caja (`expenses`, type=1). */
    public function recordDrawerIncome(string $companyId, string $expensesId): void
    {
        $this->recordDrawerRow($companyId, $expensesId, 'income');
    }

    /**
     * Anula (soft-void) todos los movimientos derivados de un origen +
     * revierte el saldo. Se llama cuando la operación de origen se anula
     * (venta/compra → transactionStatus=6, etc).
     */
    public function voidBySource(string $companyId, string $source, string $sourceId): void
    {
        $this->movements->voidBySource($companyId, $source, $sourceId);
    }

    // ── internos ──────────────────────────────────────────────────────────

    private function recordDrawerRow(string $companyId, string $expensesId, string $kind): void
    {
        $row = ncmExecute(
            'SELECT * FROM expenses WHERE expensesId = ? AND companyId = ? LIMIT 1',
            [$expensesId, $companyId]
        );
        if (!$row) {
            return;
        }
        $amount = abs((float) ($row['expensesAmount'] ?? 0));
        if ($amount <= 0) {
            return;
        }
        $accountId = $this->accounts->ensureCashAccountId($companyId);
        $categoryId = $kind === 'income'
            ? $this->categories->ensureSalesCategoryId($companyId)
            : $this->categories->ensurePurchasesCategoryId($companyId);

        $this->movements->recordDerivedMovement($companyId, 'expense', $expensesId, [
            'accountId'     => $accountId,
            'categoryId'    => $categoryId,
            'kind'          => $kind,
            'amount'        => $amount,
            'date'          => (string) ($row['expensesDate'] ?? ''),
            'description'   => (string) ($row['expensesDescription'] ?? '') ?: ($kind === 'income' ? 'Ingreso de caja' : 'Extracción de caja'),
            'paymentMethod' => 'efectivo',
            'userId'        => $this->nullableUuid($row['userId'] ?? null),
            'outletId'      => $this->nullableUuid($row['outletId'] ?? null),
        ]);
    }

    /**
     * Decodifica `transactionPaymentType`, agrupa por cuenta resuelta y
     * crea 1 movimiento por (origen, cuenta) con el monto sumado. Núcleo del
     * soporte de pago dividido.
     */
    /**
     * $categorySplit (F0 gasto dividido, owner 2026-08-20): opcional, lista
     * de `[categoryId|null, amount]` que suma EXACTO el total de las líneas
     * de pago — cuando viene, reemplaza el movimiento único por N movimientos
     * (uno por porción de categoría) en vez de uno solo con `$categoryId`.
     * Solo lo usa `recordPurchase()` hoy: es la única fuente con categoría
     * por LÍNEA DE ÍTEM (ninguna venta/pago/devolución la tiene todavía).
     *
     * Asume 1 sola cuenta de pago — cierto siempre para compras
     * (`PurchasesService::create()` nunca arma más de una línea de pago). Si
     * algún día una compra soporta pago dividido, aplicar el MISMO split de
     * categoría a cada cuenta multiplicaría el total — por eso el guard de
     * abajo cae a `$categoryId` sin partir en vez de arriesgar un saldo
     * incorrecto.
     *
     * $costCenterId (mig 167): centro de costo de la compra ENTERA. A
     * diferencia de $categorySplit NO parte nada — se copia igual en cada
     * movimiento generado, sea el único o cada porción del split. Es un dato
     * de imputación, no de importe: sumarlo a la clave de unicidad del ledger
     * rompería la idempotencia del hook (ver `resolveCostCenterId()`).
     *
     * @param list<array{0:?string,1:float}>|null $categorySplit
     */
    private function recordPaymentLines(
        string $companyId,
        string $source,
        string $sourceId,
        array|\CaseInsensitiveArray $row,
        string $kind,
        ?string $categoryId,
        string $description,
        ?array $categorySplit = null,
        ?string $costCenterId = null
    ): void {
        $lines = $this->decodePaymentLines($row);
        if (empty($lines)) {
            return;
        }

        $date      = (string) ($row['transactionDate'] ?? '');
        $userId    = $this->nullableUuid($row['userId'] ?? null);
        $outletId  = $this->nullableUuid($row['outletId'] ?? null);

        // Agrupar por accountId: [accountId => ['amount' => sum, 'methodKey' => representativo]]
        $byAccount = [];
        foreach ($lines as $line) {
            $methodKey = (string) ($line['type'] ?? $line['name'] ?? '');
            if ($this->isNonCashMethod($companyId, $methodKey)) {
                continue;
            }
            $amount = abs((float) ($line['price'] ?? $line['total'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $accountId = $this->config->resolveAccountId($companyId, $methodKey);

            if (!isset($byAccount[$accountId])) {
                $byAccount[$accountId] = ['amount' => 0.0, 'methodKey' => $methodKey];
            }
            $byAccount[$accountId]['amount'] += $amount;
        }

        if ($categorySplit !== null && count($byAccount) > 1) {
            // Ver docblock: dividir por categoría asume 1 sola cuenta. Nunca
            // debería pasar hoy — si pasa, no arriesgamos duplicar/perder
            // saldo: logueamos y caemos al movimiento único de siempre.
            error_log("[FinanceLedger] recordPaymentLines: categorySplit con " . count($byAccount) . " cuentas ({$source}/{$sourceId}) — ignorando split, no soportado");
            $categorySplit = null;
        }

        foreach ($byAccount as $accountId => $agg) {
            if ($agg['amount'] <= 0) {
                continue;
            }

            if ($categorySplit !== null) {
                foreach ($categorySplit as [$sliceCategoryId, $sliceAmount]) {
                    if ($sliceAmount <= 0) {
                        continue;
                    }
                    $this->movements->recordDerivedMovement($companyId, $source, $sourceId, [
                        'accountId'     => $accountId,
                        'categoryId'    => $sliceCategoryId,
                        // Mismo centro en TODAS las porciones — la compra se
                        // divide por categoría, no por destino.
                        'costCenterId'  => $costCenterId,
                        'kind'          => $kind,
                        'amount'        => $sliceAmount,
                        'date'          => $date,
                        'description'   => $description,
                        'paymentMethod' => $agg['methodKey'] ?: null,
                        'userId'        => $userId,
                        'outletId'      => $outletId,
                    ]);
                }
                continue;
            }

            $this->movements->recordDerivedMovement($companyId, $source, $sourceId, [
                'accountId'     => $accountId,
                'categoryId'    => $categoryId,
                'costCenterId'  => $costCenterId,
                'kind'          => $kind,
                'amount'        => $agg['amount'],
                'date'          => $date,
                'description'   => $description,
                'paymentMethod' => $agg['methodKey'] ?: null,
                'userId'        => $userId,
                'outletId'      => $outletId,
            ]);
        }
    }

    /**
     * Medios que NO mueven plata de una cuenta real DIRECTAMENTE desde la
     * línea de pago: crédito interno del cliente, puntos de loyalty y gift
     * card (la plata entró cuando se vendió la gift card, no cuando se
     * canjea) — mismo error de clase que el fallback viejo de
     * `recordPurchase` (incidente 2026-07). El vocabulario es el de
     * `Reports\NonAddingSales` (ventas que no suman).
     *
     * `check` (F1, context/30) entra en la misma lista por una razón
     * distinta: la plata del cheque NO es real hasta que se efectiviza
     * (`CheckService::changeStatus` a 'cleared') — si la línea de pago
     * generara un movimiento acá TAMBIÉN, el cheque duplicaría el ingreso/
     * egreso (una vez al nacer, otra al aclarar). Exclusión incondicional,
     * tenga o no `finAccountMap` asignado — nunca "cae a Efectivo".
     *
     * Aplica a TODOS los orígenes (venta, pago de crédito, compra,
     * devolución): la regla es del wrapper, no del call-site. En
     * devoluciones es lo que hace que `refundMode='credit'` no toque la caja.
     */
    private const NON_CASH_METHODS = ['storecredit', 'incredit', 'points', 'giftcard', 'check'];

    /**
     * La clave del pago puede venir como slug legacy ('storeCredit') o como
     * taxonomyId del método (ventas nuevas) — para el segundo caso el
     * vocabulario estable es el `systemKey` del método.
     */
    private function isNonCashMethod(string $companyId, string $methodKey): bool
    {
        $key = strtolower(trim($methodKey));
        if ($key === '') {
            return false;
        }
        if (in_array($key, self::NON_CASH_METHODS, true)) {
            return true;
        }

        $resolver = new \Punto\Api\PaymentMethods\PaymentMethodResolver();
        $methodId = $resolver->resolveMethodId($companyId, $methodKey);
        if ($methodId === null) {
            return false;
        }
        foreach ($resolver->methods($companyId) as $method) {
            if (strcasecmp($method['id'], $methodId) === 0) {
                $systemKey = strtolower((string) ($method['systemKey'] ?? ''));
                return $systemKey !== '' && in_array($systemKey, self::NON_CASH_METHODS, true);
            }
        }
        return false;
    }

    /**
     * F1 cheques (context/30): si alguna línea de pago corresponde al método
     * con `systemKey='check'`, crea el `fin_check` asociado (pending). A lo
     * sumo un cheque por transacción — el primer match gana (mismo criterio
     * que el UNIQUE `(companyid, transactionid)` de `fin_check`, mig 102); un
     * pago dividido con dos líneas "cheque" no está soportado en v1.
     *
     * Best-effort por línea: un fallo acá NUNCA bloquea el resto del ledger
     * (movimiento derivado de la venta/compra sigue su curso normal).
     *
     * $categoryId: la misma categoría que llevaría el movimiento derivado si
     * la línea no fuera cheque (Ventas/Proveedores) — así el movimiento que
     * `CheckService::ensureMovement()` genera al efectivizarse no queda sin
     * clasificar (antes se creaba con `categoryid = null` siempre).
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private function createCheckFromLines(
        string $companyId,
        string $transactionId,
        array $lines,
        string $direction,
        ?string $contactId,
        ?string $categoryId = null
    ): void {
        foreach ($lines as $line) {
            $methodKey = (string) ($line['type'] ?? $line['name'] ?? '');
            if (!$this->isCheckMethod($companyId, $methodKey)) {
                continue;
            }
            try {
                (new CheckService())->createFromPayment(
                    companyId: $companyId,
                    transactionId: $transactionId,
                    line: $line,
                    direction: $direction,
                    contactId: $contactId,
                    categoryId: $categoryId,
                );
            } catch (\Throwable $e) {
                error_log("[FinanceLedger] createCheckFromLines falló para transactionId={$transactionId}: " . $e->getMessage());
            }
            return; // a lo sumo un cheque por transacción
        }
    }

    /** Análogo a isNonCashMethod pero discrimina específicamente el método Cheque. */
    private function isCheckMethod(string $companyId, string $methodKey): bool
    {
        $key = strtolower(trim($methodKey));
        if ($key === '') {
            return false;
        }
        if ($key === 'check' || $key === 'cheque') {
            return true;
        }

        $resolver = new \Punto\Api\PaymentMethods\PaymentMethodResolver();
        $methodId = $resolver->resolveMethodId($companyId, $methodKey);
        if ($methodId === null) {
            return false;
        }
        foreach ($resolver->methods($companyId) as $method) {
            if (strcasecmp($method['id'], $methodId) === 0) {
                return strtolower((string) ($method['systemKey'] ?? '')) === 'check';
            }
        }
        return false;
    }

    /**
     * `meta` JSONB de la fila decodificado a array, o `[]` si no hay nada
     * (fila legacy, o `$row` sin el side-channel de `rawJsonb()`).
     *
     * rawJsonb() exige un objeto (side-channel WeakMap por identidad de
     * fila) — un $row llegado como array plano (nunca pasa hoy: el único
     * caller lo obtiene de un SELECT de una fila, que Query::execute()
     * siempre devuelve como CaseInsensitiveArray) no tiene ese side-channel.
     * Degradar a `[]` en vez de un TypeError si algún día cambia el caller.
     */
    private function decodeMeta(array|\CaseInsensitiveArray $row): array
    {
        if (!is_object($row)) {
            return [];
        }
        $raw = \Punto\App\Database\Query::rawJsonb($row, 'meta');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Divide el neto pagado de una compra en porciones EXACTAS por categoría
     * de gasto (owner 2026-08-20, "el gasto se DIVIDE por categoría, no es
     * prorrateo estimado"). Cada línea de `meta.details` ya trae su
     * `expenseCategoryId` RESUELTO por `PurchasesService::create()`
     * (precedencia línea > cabecera > ítem) — acá solo se agrupa por
     * categoría y se le suma a cada una su parte proporcional del ÚNICO
     * importe que no pertenece a ninguna línea en este sistema: el descuento
     * de cabecera (`transactionDiscount`). No hay impuesto ni recargo de
     * cabecera que prorratear — el IVA ya se calcula y persiste POR LÍNEA
     * (`TaxEngine::computeTaxes`, ver `PurchasesService::create()`), y
     * `price` de cada línea ya lo incluye (tax-inclusive por default), así
     * que sumar `price` por categoría ya arrastra el IVA de esas líneas sin
     * ningún reparto adicional.
     *
     * Redondeo: cada porción se redondea al `$decimals` del tenant: la suma
     * de las porciones redondeadas puede diferir del neto pagado por
     * fracciones de centavo acumuladas independientemente. La diferencia
     * (nunca más de 1 unidad mínima) se ajusta ENTERA en la categoría de
     * mayor monto de líneas — así la suma da EXACTO el neto pagado, sin
     * inventar ni perder nada; nunca se reparte el resto entre varias.
     *
     * Devuelve `null` cuando NINGUNA línea/cabecera/ítem trajo categoría —
     * ese caso sigue funcionando como antes de este cambio (el caller usa el
     * default genérico "Proveedores", "una compra sin ninguna categoría
     * sigue funcionando igual que hoy" — owner).
     *
     * @return list<array{0:?string,1:float}>|null
     */
    private function resolveCategorySplit(array|\CaseInsensitiveArray $row): ?array
    {
        $meta    = $this->decodeMeta($row);
        $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
        if (empty($details)) {
            return null;
        }

        $discount = (float) ($row['transactionDiscount'] ?? 0);

        // Agrupar por categoría — clave '' = sin categoría (línea sin línea/
        // cabecera/ítem que la clasifique).
        $buckets = [];
        $total   = 0.0;
        foreach ($details as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineTotal = (float) ($line['price'] ?? 0);
            if ($lineTotal <= 0) {
                continue;
            }
            $total += $lineTotal;
            $catId = (string) ($line['expenseCategoryId'] ?? '');
            $key   = ($catId !== '' && preg_match(self::UUID_RE, $catId)) ? $catId : '';
            $buckets[$key] = ($buckets[$key] ?? 0.0) + $lineTotal;
        }
        if ($total <= 0) {
            return null;
        }

        $keys = array_keys($buckets);
        if (count($keys) === 1 && $keys[0] === '') {
            return null; // nada categorizado en ninguna línea de esta compra
        }

        $decimals = $this->currencyDecimals((string) ($row['companyId'] ?? ''));
        $netTotal = round($total - $discount, $decimals);

        $slices     = [];
        $sumRounded = 0.0;
        $maxKey     = null;
        $maxLineSum = -INF;
        foreach ($buckets as $key => $lineSum) {
            $share = ($discount != 0.0 && $total > 0) ? ($lineSum / $total) * $discount : 0.0;
            $net   = round($lineSum - $share, $decimals);
            $slices[$key] = $net;
            $sumRounded  += $net;
            if ($lineSum > $maxLineSum) {
                $maxLineSum = $lineSum;
                $maxKey     = $key;
            }
        }
        // Centavo de redondeo: entero a la categoría de mayor monto de
        // líneas — nunca repartido, nunca perdido. Se aplica sin comparar
        // contra 0.0 (comparación de floats frágil): si no hay diferencia
        // real, sumar un `$diff` casi nulo y volver a redondear da el mismo
        // valor de antes — inofensivo.
        $diff = round($netTotal - $sumRounded, $decimals);
        if ($maxKey !== null) {
            $slices[$maxKey] = round($slices[$maxKey] + $diff, $decimals);
        }

        $result = [];
        foreach ($slices as $key => $amount) {
            if ($amount <= 0) {
                continue; // porción que redondeó a 0 — sin movimiento vacío
            }
            $result[] = [$key === '' ? null : $key, $amount];
        }
        return empty($result) ? null : $result;
    }

    /**
     * Categoría de CABECERA elegida para toda la compra (`meta.expenseCategoryId`,
     * ver `PurchasesService::create()`), o null si el comercio no la puso.
     * Es la que atajo por default hereda cada línea que no eligió la suya
     * propia — ver precedencia en `resolveCategorySplit()`/`PurchasesService`.
     */
    private function resolveHeaderCategoryId(array|\CaseInsensitiveArray $row): ?string
    {
        $meta  = $this->decodeMeta($row);
        $catId = (string) ($meta['expenseCategoryId'] ?? '');
        return ($catId !== '' && preg_match(self::UUID_RE, $catId)) ? $catId : null;
    }

    /**
     * Centro de costo al que se imputa la compra ENTERA (`meta.costCenterId`,
     * ver `PurchasesService::create()`), o null si el comercio no lo eligió.
     *
     * Hermano de `resolveHeaderCategoryId()` pero con una diferencia que hace
     * a la integridad del ledger: la categoría PARTE la compra en N
     * movimientos (uno por porción), el centro NO. Es un destino único que
     * viaja idéntico en todas esas porciones, porque `costcenterid` no entra
     * en la clave del `ON CONFLICT` de la mig 153 — si entrara, un reintento
     * del hook con otro centro insertaría filas nuevas en vez de actualizar
     * las existentes y el saldo se duplicaría.
     */
    private function resolveCostCenterId(array|\CaseInsensitiveArray $row): ?string
    {
        $meta  = $this->decodeMeta($row);
        $ccId  = (string) ($meta['costCenterId'] ?? '');
        return ($ccId !== '' && preg_match(self::UUID_RE, $ccId)) ? $ccId : null;
    }

    /**
     * Decimales de moneda del tenant — mismo criterio que
     * `PurchasesService::currencyDecimals()` (duplicado a propósito: 6
     * líneas, un solo SELECT, evita acoplar Finanzas a Compras por un
     * helper de 2 líneas).
     */
    private function currencyDecimals(string $companyId): int
    {
        if ($companyId === '') {
            return 0;
        }
        $row  = ncmExecute(
            "SELECT config->>'settingDecimal' AS decimalflag FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $flag = $row ? (string) ($row['decimalflag'] ?? '') : '';
        return $flag === 'yes' ? 2 : 0;
    }

    /** @return array<int,array<string,mixed>> */
    private function decodePaymentLines(array|\CaseInsensitiveArray $row): array
    {
        $raw = $row['transactionPaymentType'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // Shape esperado: array de objetos. Si viniera un solo objeto (legacy),
        // normalizamos a lista de 1.
        if (isset($decoded['type']) || isset($decoded['name'])) {
            return [$decoded];
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function nullableUuid($val): ?string
    {
        $val = (string) ($val ?? '');
        return ($val !== '' && preg_match(self::UUID_RE, $val)) ? $val : null;
    }
}
