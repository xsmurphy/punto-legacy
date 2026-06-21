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
        $drwr = ncmExecute(
            "SELECT drawerOpenDate, drawerOpenAmount FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < '2000-01-01 01:00:00')
             ORDER BY drawerOpenDate DESC LIMIT 1",
            [$registerId, $outletId, $companyId]
        );
        if (!$drwr) {
            return null;
        }
        return [
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

    /** Ventas agrupadas por método de pago del registro desde `$since`. */
    public function getPaymentBreakdown(string $registerId, string $since): array
    {
        $detail = getSalesByPayment($since, false, $registerId);
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
        global $db;

        $exists = ncmExecute(
            'SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1',
            [$amount, $date, $this->ctx->registerId]
        );
        if ($exists) {
            return 'Expense Already Exists';
        }

        // expensesNameId es NULL para movimientos de caja (mig 33 hace la columna nullable).
        // type IS NULL = extracción (según DrawerService::getExpenses).
        $ok = $db->Execute(
            'INSERT INTO expenses
                (expensesNameId, expensesAmount, expensesDate, expensesDescription,
                 userId, registerId, outletId, companyId)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)',
            [$amount, $date, $note,
             $this->ctx->userId, $this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException($db->ErrorMsg() ?: 'Error al registrar extracción');
        }

        \rollupMarkDirty($this->ctx->companyId, ['drawer_expenses'], $date);
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
        global $db;

        $exists = ncmExecute(
            'SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1',
            [(float) $amount, $date, $this->ctx->registerId]
        );
        if ($exists) {
            return 'Income Already Exists';
        }

        // type = 1 = ingreso (según DrawerService::getIncome).
        $ok = $db->Execute(
            'INSERT INTO expenses
                (expensesNameId, expensesAmount, expensesDate, expensesDescription,
                 type, userId, registerId, outletId, companyId)
             VALUES (NULL, ?, ?, ?, 1, ?, ?, ?, ?)',
            [(float) $amount, $date, $note,
             $this->ctx->userId, $this->ctx->registerId, $this->ctx->outletId, $this->ctx->companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException($db->ErrorMsg() ?: 'Error al registrar ingreso');
        }

        \rollupMarkDirty($this->ctx->companyId, ['drawer_expenses'], $date);
        return true;
    }

    // ========================================================================
    // HELPERS PRIVADOS
    // ========================================================================

    /**
     * Devuelve la fila del drawer abierto (drawerId + drawerOpenDate) o null.
     * Centraliza la query de "hay caja abierta" usada por open() y close().
     */
    private function findOpenRow(string $registerId, string $outletId, string $companyId): ?array
    {
        $row = ncmExecute(
            "SELECT drawerId, drawerOpenDate FROM drawer
             WHERE registerId = ? AND outletId = ? AND companyId = ?
             AND (drawerCloseDate IS NULL OR drawerCloseDate < '2000-01-01 00:00:00')
             ORDER BY drawerOpenDate DESC LIMIT 1",
            [$registerId, $outletId, $companyId]
        );
        return $row ?: null;
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
        $expenses = $this->getExpenses($registerId, $since);
        $income   = $this->getIncome($registerId, $since);
        $payments = $this->getPaymentBreakdown($registerId, $since);

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
            if ($p['type'] === 'cash') {
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
