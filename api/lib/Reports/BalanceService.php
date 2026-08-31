<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

require_once __DIR__ . '/OpenInvoicesService.php';
require_once __DIR__ . '/../Finance/ObligationsService.php';

/**
 * Dominio de Reportes — Balance GERENCIAL (B3 de `context/60`).
 *
 * ── Qué NO es, y por qué importa decirlo primero ────────────────────────────
 * NO es un balance contable. Decisión del owner (2026-08-31): *"nosotros no nos
 * metemos en lo contable"*. Acá no hay plan de cuentas, ni asientos, ni partida
 * doble, ni cuentas patrimoniales.
 *
 * El PATRIMONIO NETO es DERIVADO (Activo − Pasivo), nunca capturado. Y el
 * destinatario es el DUEÑO, no el contador: la pregunta que responde es "¿cuánto
 * tengo y cuánto debo?", no "¿cierra mi balance?".
 *
 * `AccountingCode` (mig 167) NO es semilla de un plan de cuentas: existe para
 * copiar tal cual el código que dicta el sistema del contador. No construir nada
 * encima suponiendo lo contrario.
 *
 * ── Es una FOTO, no un rango ────────────────────────────────────────────────
 * A diferencia del resto de los reportes del panel, un balance es a una FECHA.
 * En esta versión esa fecha es HOY: los saldos de cuentas, las cuentas por
 * cobrar/pagar y el inventario se leen de su estado actual. Reconstruirlo a una
 * fecha pasada es posible (los datos están: `fin_movement` tiene fechas y
 * `Inventory::onHandBulk` acepta corte) pero es otro trabajo, y un dueño mira el
 * balance de hoy.
 *
 * ── El hueco declarado ──────────────────────────────────────────────────────
 * NO hay activo fijo. Un comercio tiene heladeras, vitrinas, vehículos, y Punto
 * no los modela en ningún lado, así que el patrimonio derivado queda
 * SUBESTIMADO. `notes.missingFixedAssets` lo expone para que la pantalla lo diga
 * — un número presentado como patrimonio que ignora la mitad de los bienes es
 * peor que no mostrarlo.
 */
final class BalanceService
{
    /**
     * @param string $outletId Sucursal del view-scope; '' = todas.
     */
    public function get(string $companyId, string $outletId = ''): array
    {
        $cash        = $this->cashAccounts($companyId, $outletId);
        $receivables = $this->receivables($companyId, $outletId);
        $inventory   = $this->inventoryValue($companyId, $outletId);

        $payables    = $this->payables($companyId, $outletId);
        $obligations = $this->obligations($companyId);

        $cashTotal = array_sum(array_column($cash, 'balance'));
        $assets    = $cashTotal + $receivables + $inventory;
        $liabs     = $payables + $obligations['total'];

        return [
            'asOf'   => date('Y-m-d H:i:s'),
            'assets' => [
                'cash'        => round($cashTotal, 2),
                'cashByAccount' => $cash,
                'receivables' => round($receivables, 2),
                'inventory'   => round($inventory, 2),
                'total'       => round($assets, 2),
            ],
            'liabilities' => [
                'payables'    => round($payables, 2),
                'obligations' => round($obligations['total'], 2),
                'obligationsByType' => $obligations['byType'],
                'total'       => round($liabs, 2),
            ],
            // Derivado, NUNCA capturado. Ver docblock.
            'equity' => round($assets - $liabs, 2),
            'notes'  => [
                // La pantalla TIENE que mostrar esto (D5 de context/60).
                'missingFixedAssets' => true,
                'managerial'         => true,
            ],
        ];
    }

    /**
     * Saldo por cuenta. Acá SÍ se lee `currentbalance`: el balance es a HOY, y
     * esa columna es exactamente el saldo de hoy (`openingbalance` + Σ
     * movimientos activos). Para una fecha pasada habría que recomputar — ver
     * `CashflowService::accountBalances()`, que sí lo hace.
     *
     * @return list<array{accountId:string,name:string,type:string,balance:float}>
     */
    private function cashAccounts(string $companyId, string $outletId): array
    {
        $where  = 'companyid = ?::uuid AND status = 1';
        $params = [$companyId];
        if ($outletId !== '') {
            // `outletid IS NULL` = cuenta global: se incluye siempre, o el
            // efectivo compartido desaparecería al filtrar por sucursal.
            $where   .= ' AND (outletid = ?::uuid OR outletid IS NULL)';
            $params[] = $outletId;
        }
        $rows = \ncmRows(
            "SELECT accountid, name, type, currentbalance FROM fin_account
              WHERE {$where} ORDER BY type, name",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'accountId' => (string) $r['accountid'],
                'name'      => (string) $r['name'],
                'type'      => (string) $r['type'],
                'balance'   => round((float) $r['currentbalance'], 2),
            ];
        }
        return $out;
    }

    /** Lo que los clientes deben: `OpenInvoicesService`, la MISMA fuente que el reporte de cuentas por cobrar. */
    private function receivables(string $companyId, string $outletId): float
    {
        $r = (new OpenInvoicesService())->general('income', $companyId, null, $outletId);
        return (float) ($r['kpi']['totalDebt'] ?? 0);
    }

    /** Lo que se le debe a proveedores. Misma fuente que cuentas por pagar. */
    private function payables(string $companyId, string $outletId): float
    {
        $r = (new OpenInvoicesService())->general('outcome', $companyId, null, $outletId);
        return (float) ($r['kpi']['totalDebt'] ?? 0);
    }

    /**
     * Inventario valorizado: Σ (onHand × costo promedio).
     *
     * El costo va CON IVA incluido — decisión del owner, no un bug: es el valor
     * REAL pagado por la mercadería (`project_inventory_cost_iva_included`,
     * `context/52`). Desglosar el impuesto es trabajo del contador.
     */
    private function inventoryValue(string $companyId, string $outletId): float
    {
        $balances = \Punto\App\Domain\Inventory::onHandBulk($companyId, $outletId);
        $total = 0.0;
        foreach ($balances as $b) {
            $onHand = (float) ($b['onHand'] ?? 0);
            // El stock NEGATIVO no se valoriza como activo negativo: es un
            // desvío de inventario, no plata que la empresa deba. Contarlo
            // restaría del activo por un error de conteo.
            if ($onHand <= 0) { continue; }
            $total += $onHand * (float) ($b['cogs'] ?? 0);
        }
        return $total;
    }

    /**
     * Obligaciones futuras: cheques emitidos y cuotas de crédito. Reusa
     * `ObligationsService`, el mismo helper que alimentan la previsión de
     * `/v1/finance/forecast.php` y el centro de notificaciones — una tercera
     * consulta propia divergiría de las otras dos.
     *
     * ── Se EXCLUYE `type = 'purchase'`, y no es un detalle ──────────────────
     * `ObligationsService` incluye compras `transactionType IN (1,4)` con
     * `transactionComplete = false`, que es EXACTAMENTE lo que ya cuenta
     * `payables()` vía `OpenInvoicesService`. Sumar las dos duplicaría cada
     * compra a crédito en el pasivo.
     *
     * Y la duplicación sería peor que un doble conteo limpio: obligaciones
     * reporta el TOTAL del documento (`transactionTotal - discount`) mientras
     * que cuentas por pagar reporta el SALDO neto de pagos. Una compra pagada a
     * medias entraría dos veces con montos distintos, y ningún total cerraría
     * contra nada.
     *
     * `payables()` gana porque es el número correcto: lo que falta pagar.
     *
     * Sin límite inferior (`$from = null`): incluye las VENCIDAS. Un cheque
     * impago de hace tres meses sigue siendo pasivo.
     *
     * @return array{total:float,byType:array<string,float>}
     */
    private function obligations(string $companyId): array
    {
        $rows = (new \Punto\Api\Finance\ObligationsService())
            ->list($companyId, null, date('Y-m-d', strtotime('+10 years')));

        $total  = 0.0;
        $byType = [];
        foreach ($rows as $o) {
            $type = (string) ($o['type'] ?? 'otros');
            if ($type === 'purchase') {
                continue; // ya está en payables(), y ahí con el saldo correcto
            }
            $amount = (float) ($o['amount'] ?? 0);
            $total += $amount;
            $byType[$type] = round(($byType[$type] ?? 0) + $amount, 2);
        }
        return ['total' => $total, 'byType' => $byType];
    }
}
