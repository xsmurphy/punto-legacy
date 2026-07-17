<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * DrawerService — operaciones de caja/drawer del POS (Slice 26).
 *
 * Lógica portada de app/load.php:
 *   isOpen  (L1664, branch chk=1) — verifica si el cajón está abierto.
 *   getSummary (L1664)             — resumen completo de la caja (ventas, gastos, ingresos).
 *
 * getSalesByPayment() y helpers relacionados (isParentInternalSale, isInternalSale,
 * groupByPaymentMethod) están disponibles vía api/bootstrap.php → head.php → functions.php.
 * REGISTER_ID, OUTLET_ID, COMPANY_ID son constantes definidas tras apiAuthTenant().
 */

final class DrawerService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * Verifica si el cajón está abierto (hay una fila sin cerrar).
     *
     * @return bool true si hay un drawer abierto para el registro/outlet/company actuales.
     */
    public function isOpen(string $registerId, string $outletId, string $companyId): bool
    {
        $drwr = ncmExecute(
            "SELECT drawerOpenDate FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < '2000-01-01 01:00:00')
             ORDER BY drawerOpenDate DESC LIMIT 1",
            [$registerId, $outletId, $companyId]
        );
        return (bool) $drwr;
    }

    // ========================================================================
    // RECURSOS GRANULARES (patrón BFF-compone — ver §22.12)
    //
    // getSummary() abajo es el composite legacy (la API arma toda la pantalla).
    // Estos métodos exponen las piezas reusables del cierre de caja: cada uno
    // un concepto independiente (drawer abierto, extracciones, ingresos, ventas
    // por método de pago) que un reporte/dashboard puede pedir por separado. El
    // BFF (app/bff/drawer.php) hace fetch del drawer abierto, luego los hijos en
    // paralelo con `since=drawerOpenDate`, y computa el rollup (subtotal/total/…).
    // getSummary() queda como conveniencia/backward-compat.
    // ========================================================================

    /** Drawer abierto actual (la fila sin cerrar). null si la caja está cerrada. */
    public function getOpen(string $registerId, string $outletId, string $companyId): ?array
    {
        // Alias quoted para preservar camelCase post-refactor 28-jun (array
        // plano lowercase). Sin esto $drwr['drawerOpenDate'] devolvía null y
        // getSummary pasaba null a getExpenses(string $since) → TypeError.
        $drwr = ncmExecute(
            'SELECT drawerId         AS "drawerId",
                    drawerOpenDate   AS "drawerOpenDate",
                    drawerOpenAmount AS "drawerOpenAmount"
             FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < \'2000-01-01 01:00:00\')
             ORDER BY drawerOpenDate DESC LIMIT 1',
            [$registerId, $outletId, $companyId]
        );
        if (!$drwr) {
            return null;
        }
        return [
            'drawerId'         => $drwr['drawerId'] ?? null,
            'drawerOpenDate'   => $drwr['drawerOpenDate'],
            'drawerOpenAmount' => $drwr['drawerOpenAmount'] ? (float) $drwr['drawerOpenAmount'] : 0.0,
        ];
    }

    /** Extracciones (efectivo) del registro desde `$since`. */
    public function getExpenses(string $registerId, string $since): array
    {
        $exp = ncmExecute(
            'SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate > ? AND type IS NULL AND registerId = ?',
            [$since, $registerId]
        );
        return ['amount' => ($exp && $exp['expense']) ? (float) $exp['expense'] : 0.0];
    }

    /** Ingresos (efectivo) del registro desde `$since`, separando propinas. */
    public function getIncome(string $registerId, string $since): array
    {
        $inc = ncmExecute(
            'SELECT expensesAmount, expensesDescription FROM expenses WHERE expensesDate > ? AND type = 1 AND registerId = ?',
            [$since, $registerId],
            false,
            true
        );
        $totalIncome = 0.0;
        $totalTips   = 0.0;
        if ($inc) {
            while (!$inc->EOF) {
                $f = $inc->fields;
                if ($f['expensesDescription'] === 'PROPINA') {
                    $totalTips += (float) $f['expensesAmount'];
                }
                $totalIncome += (float) $f['expensesAmount'];
                $inc->MoveNext();
            }
            $inc->Close();
        }
        return ['total' => $totalIncome, 'tips' => $totalTips];
    }

    /**
     * Ventas agrupadas por método de pago del registro de la sesión de caja.
     * Filtra por `$drawerId` (exacto) + fallback por `$since` para filas NULL
     * (mig 70). `$drawerId` null → solo fallback por fecha (backward-compat).
     */
    public function getPaymentBreakdown(string $registerId, string $since, ?string $drawerId = null): array
    {
        $detail = getSalesByPayment($since, false, $registerId, $drawerId);
        $out    = [];
        if (validity($detail, 'array')) {
            foreach ($detail as $arr) {
                $out[] = [
                    'name'  => str_replace('u00e9', 'é', $arr['name']),
                    'type'  => $arr['type'],
                    'price' => (float) $arr['price'],
                ];
            }
        }
        return $out;
    }

    // ========================================================================
    // MUTACIONES — porteadas de app/action.php (handlers openCloseDrawer,
    // expense, drwrIncome). Antes estas acciones iban a /action.php (monolito
    // legacy); ahora van a POST /v1/drawer.php → estos métodos.
    // ========================================================================

    /**
     * Abre la caja. Falla si ya hay una abierta (devuelve 'Already Open').
     *
     * @return string|true 'Already Open' si ya está abierta; true en éxito.
     * @throws \RuntimeException en error de DB.
     */
    public function open(float $amount, string $date, string $userId): string|true
    {
        global $db;

        $existing = $this->findOpenRow($this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId);
        if ($existing !== null) {
            return 'Already Open';
        }

        try {
            $ok = $db->Execute(
                'INSERT INTO drawer
                    (drawerOpenDate, drawerOpenAmount, drawerUserOpen, drawerUID,
                     registerId, outletId, companyId)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$date, $amount, $userId, 0,
                 $this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId]
            );
        } catch (\Throwable $e) {
            // El índice uidx_drawer_register_open (mig 34) protege contra race:
            // si dos requests pasan el findOpenRow simultáneamente, el segundo
            // falla con unique violation acá.
            if (stripos($e->getMessage(), 'uidx_drawer_register_open') !== false
                || stripos($e->getMessage(), 'duplicate key') !== false) {
                return 'Already Open';
            }
            throw new \RuntimeException($e->getMessage());
        }
        if ($ok === false) {
            throw new \RuntimeException($db->ErrorMsg() ?: 'Error al abrir caja');
        }

        return true;
    }

    /**
     * Cierra la caja abierta. Falla si ya está cerrada o si la fecha de cierre
     * es anterior a la de apertura.
     *
     * @return string|true 'Already Closed' / 'Invalid Close Date' / true.
     * @throws \RuntimeException en error de DB.
     */
    public function close(float $amount, string $date, string $userId): string|true
    {
        global $db;

        $row = $this->findOpenRow($this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId);
        if ($row === null) {
            return 'Already Closed';
        }

        if (strtotime((string) $row['drawerOpenDate']) > strtotime($date)) {
            return 'Invalid Close Date';
        }

        $ok = ncmExecute(
            'UPDATE drawer SET drawerCloseDate = ?, drawerCloseAmount = ?, drawerUserClose = ? WHERE drawerId = ?',
            [$date, $amount, $userId, $row['drawerId']]
        );

        if ($ok === false) {
            throw new \RuntimeException('Error al cerrar caja');
        }

        return true;
    }

    /**
     * Registra una extracción de efectivo desde la caja.
     * Idempotente: si ya existe un movimiento con el mismo monto y fecha para
     * este registro, devuelve 'Expense Already Exists' sin crear duplicado.
     *
     * @return string|true 'Expense Already Exists' / true.
     * @throws \RuntimeException en error de DB.
     */
    public function addExpense(float $amount, string $note, string $date): string|true
    {
        $exists = ncmExecute(
            'SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1',
            [$amount, $date, $this->ctx->registerId]
        );
        if ($exists) {
            return 'Expense Already Exists';
        }

        // expensesNameId es NULL para movimientos de caja (mig 33 hace la columna nullable).
        // type IS NULL = extracción (según DrawerService::getExpenses).
        // ncmInsert (no $db->Execute raw): necesitamos el id insertado para
        // engancharlo al ledger de Finanzas (FinanceLedger::recordDrawerExpense,
        // Fase 3) de forma idempotente vía sourceId.
        $expensesId = ncmInsert([
            'records' => [
                'expensesNameId'      => null,
                'expensesAmount'      => $amount,
                'expensesDate'        => $date,
                'expensesDescription' => $note,
                'userId'              => $this->ctx->userId,
                'registerId'          => $this->ctx->registerId,
                'outletId'            => $this->ctx->outletId,
                'companyId'           => $this->ctx->companyId,
            ],
            'table' => 'expenses',
        ]);
        if (!$expensesId) {
            global $db;
            $err = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
            error_log('[DrawerService] addExpense INSERT falló: ' . ($err ?: 'sin detalle'));
            throw new \RuntimeException('Error al registrar extracción' . ($err !== '' ? ": {$err}" : ''));
        }

        \rollupMarkDirty($this->ctx->companyId, ['drawer_expenses'], $date);

        // Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe la extracción.
        try {
            (new \Punto\Api\Finance\FinanceLedger())->recordDrawerExpense($this->ctx->companyId, (string) $expensesId);
        } catch (\Throwable $e) {
            error_log('[FinanceLedger] recordDrawerExpense falló para expensesId=' . $expensesId . ': ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Registra un ingreso de efectivo a la caja.
     * Idempotente: mismo control de duplicado que addExpense.
     *
     * @return string|true 'Income Already Exists' / true.
     * @throws \RuntimeException en error de DB.
     */
    public function addIncome(float $amount, string $note, string $date): string|true
    {
        $exists = ncmExecute(
            'SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1',
            [(float) $amount, $date, $this->ctx->registerId]
        );
        if ($exists) {
            return 'Income Already Exists';
        }

        // type = 1 = ingreso (según DrawerService::getIncome). ncmInsert (no
        // $db->Execute raw): necesitamos el id insertado para engancharlo al
        // ledger de Finanzas (FinanceLedger::recordDrawerIncome, Fase 3).
        $expensesId = ncmInsert([
            'records' => [
                'expensesNameId'      => null,
                'expensesAmount'      => (float) $amount,
                'expensesDate'        => $date,
                'expensesDescription' => $note,
                'type'                => 1,
                'userId'              => $this->ctx->userId,
                'registerId'          => $this->ctx->registerId,
                'outletId'            => $this->ctx->outletId,
                'companyId'           => $this->ctx->companyId,
            ],
            'table' => 'expenses',
        ]);
        if (!$expensesId) {
            global $db;
            $err = method_exists($db, 'ErrorMsg') ? (string) $db->ErrorMsg() : '';
            error_log('[DrawerService] addIncome INSERT falló: ' . ($err ?: 'sin detalle'));
            throw new \RuntimeException('Error al registrar ingreso' . ($err !== '' ? ": {$err}" : ''));
        }

        \rollupMarkDirty($this->ctx->companyId, ['drawer_expenses'], $date);

        // Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe el ingreso.
        try {
            (new \Punto\Api\Finance\FinanceLedger())->recordDrawerIncome($this->ctx->companyId, (string) $expensesId);
        } catch (\Throwable $e) {
            error_log('[FinanceLedger] recordDrawerIncome falló para expensesId=' . $expensesId . ': ' . $e->getMessage());
        }

        return true;
    }

    // ========================================================================
    // HELPERS PRIVADOS
    // ========================================================================

    /**
     * drawerId de la caja ABIERTA de un register (scopeado por company), o null.
     * Helper compartido para sellar `transaction.drawerId` en el momento de la
     * venta/pago (mig 70). Best-effort: cualquier fallo devuelve null → la venta
     * se registra sin drawerId (recuperable por el fallback de fecha del resumen).
     *
     * Por register+company (sin outlet): el register ya determina el outlet, y
     * el money-path no siempre lo tiene a mano (el credit payment toma el
     * register del parent).
     */
    public static function resolveOpenDrawerId(string $registerId, string $companyId): ?string
    {
        try {
            $row = ncmExecute(
                'SELECT drawerId AS "drawerId" FROM drawer
                 WHERE registerId = ? AND companyId = ?
                 AND (drawerCloseDate IS NULL OR drawerCloseDate < \'2000-01-01 00:00:00\')
                 ORDER BY drawerOpenDate DESC LIMIT 1',
                [$registerId, $companyId]
            );
            if (!$row) {
                return null;
            }
            $id = $row['drawerId'] ?? null;
            return ($id !== null && $id !== '') ? (string) $id : null;
        } catch (\Throwable $e) {
            error_log('[DrawerService] resolveOpenDrawerId: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Devuelve la fila del drawer abierto (drawerId + drawerOpenDate) o null.
     * Centraliza la query de "hay caja abierta" usada por open() y close().
     */
    private function findOpenRow(string $registerId, string $outletId, string $companyId): ?array
    {
        $row = ncmExecute(
            'SELECT drawerId       AS "drawerId",
                    drawerOpenDate AS "drawerOpenDate"
             FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < \'2000-01-01 00:00:00\')
             ORDER BY drawerOpenDate DESC LIMIT 1',
            [$registerId, $outletId, $companyId]
        );
        if (!$row) return null;
        return $row instanceof \CaseInsensitiveArray ? $row->toArray() : (array) $row;
    }

    /**
     * Resumen completo de la caja actual: list, date, subtotal, total, tips, returns.
     * Composite legacy/backward-compat — el path vigente es la composición en el BFF
     * (app/bff/drawer.php) a partir de los recursos granulares de arriba.
     *
     * @return array|null null si el drawer está cerrado.
     */
    public function getSummary(string $registerId, string $outletId, string $companyId): ?array
    {
        $open = $this->getOpen($registerId, $outletId, $companyId);
        if ($open === null) {
            return null;
        }
        $since    = $open['drawerOpenDate'];
        $drawerId = $open['drawerId'] ?? null;
        $expenses = $this->getExpenses($registerId, $since);
        $income   = $this->getIncome($registerId, $since);
        $payments = $this->getPaymentBreakdown($registerId, $since, $drawerId);

        return self::composeSummary($open, $expenses, $income, $payments);
    }

    /**
     * Rollup del cierre de caja a partir de las piezas granulares. Pura (sin DB) →
     * reusable por getSummary() (API) y por el BFF (que arma las piezas en paralelo).
     * MANTENER EN SYNC si cambia la fórmula (la usa app/bff/drawer.php).
     *
     * @param array{drawerOpenDate:string,drawerOpenAmount:float} $open
     * @param array{amount:float} $expenses
     * @param array{total:float,tips:float} $income
     * @param array<int,array{name:string,type:string,price:float}> $payments
     */
    public static function composeSummary(array $open, array $expenses, array $income, array $payments): array
    {
        $cajaInicial   = (float) $open['drawerOpenAmount'];
        $expenseAmount = (float) $expenses['amount'];
        $totalIncome   = (float) $income['total'];
        $totalTips     = (float) $income['tips'];

        $cashPrice = 0.0;
        $total     = 0.0;
        $return    = 0.0;
        $list      = [['name' => 'Caja Inicial', 'amount' => $cajaInicial]];

        foreach ($payments as $p) {
            $price = (float) $p['price'];
            if (in_array(strtolower((string) $p['type']), ['cash', 'efectivo'], true)) {
                $cashPrice = $price;
            }
            if ($p['type'] === 'return') {
                $return += $price;
            } else {
                $total += $price;
                $list[] = ['name' => $p['name'], 'amount' => $price];
            }
        }

        $list[] = ['name' => 'Extracciones (Efectivo)', 'amount' => $expenseAmount];
        $list[] = ['name' => 'Ingresos (Efectivo)',     'amount' => $totalIncome];

        return [
            'list'     => $list,
            'date'     => $open['drawerOpenDate'],
            'subtotal' => ($cajaInicial + $cashPrice + $totalIncome) - $expenseAmount,
            'total'    => ($cajaInicial + $total + $totalIncome) - $expenseAmount - $return,
            'tips'     => $totalTips,
            'returns'  => -$return,
        ];
    }
}
