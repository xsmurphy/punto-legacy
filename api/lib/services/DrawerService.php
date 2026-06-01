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
