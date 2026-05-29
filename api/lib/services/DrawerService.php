<?php
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

class DrawerService
{
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

    /**
     * Resumen completo de la caja actual: list, date, subtotal, total, tips, returns.
     *
     * @return array|null null si el drawer está cerrado.
     */
    public function getSummary(string $registerId, string $outletId, string $companyId): ?array
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

        $exp = ncmExecute(
            'SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate > ? AND type IS NULL AND registerId = ?',
            [$drwr['drawerOpenDate'], $registerId]
        );

        $inc = ncmExecute(
            'SELECT expensesAmount, expensesDescription FROM expenses WHERE expensesDate > ? AND type = 1 AND registerId = ?',
            [$drwr['drawerOpenDate'], $registerId],
            false,
            true
        );

        $totalIncome = 0;
        $totalTips   = 0;
        if ($inc) {
            while (!$inc->EOF) {
                $f = $inc->fields;
                if ($f['expensesDescription'] === 'PROPINA') {
                    $totalTips += $f['expensesAmount'];
                }
                $totalIncome += $f['expensesAmount'];
                $inc->MoveNext();
            }
            $inc->Close();
        }

        $expenseAmount = $exp['expense'] ? (float) $exp['expense'] : 0.0;
        $cajaInicial   = $drwr['drawerOpenAmount'] ? (float) $drwr['drawerOpenAmount'] : 0.0;
        $cashPrice     = 0.0;
        $total         = 0.0;
        $return        = 0.0;
        $list          = [['name' => 'Caja Inicial', 'amount' => $cajaInicial]];

        $detailArray = getSalesByPayment($drwr['drawerOpenDate'], false, $registerId);

        if (validity($detailArray, 'array')) {
            foreach ($detailArray as $arr) {
                $name  = str_replace('u00e9', 'é', $arr['name']);
                $type  = $arr['type'];
                $price = (float) $arr['price'];

                if ($type === 'cash') {
                    $cashPrice = $price;
                }

                if ($type === 'return') {
                    $return += $price;
                } else {
                    $total += $price;
                    $list[] = ['name' => $name, 'amount' => $price];
                }
            }
        }

        $list[] = ['name' => 'Extracciones (Efectivo)', 'amount' => $expenseAmount];
        $list[] = ['name' => 'Ingresos (Efectivo)',     'amount' => $totalIncome];

        return [
            'list'     => $list,
            'date'     => $drwr['drawerOpenDate'],
            'subtotal' => ($cajaInicial + $cashPrice + $totalIncome) - $expenseAmount,
            'total'    => ($cajaInicial + $total + $totalIncome) - $expenseAmount - $return,
            'tips'     => $totalTips,
            'returns'  => -$return,
        ];
    }
}
