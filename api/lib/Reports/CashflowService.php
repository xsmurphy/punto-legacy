<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Flujo de Efectivo (B1 de `context/60`).
 *
 * ── Reescrito el 2026-08-31. Qué estaba mal antes ───────────────────────────
 * La versión anterior (port del panel legacy) se construía sobre `transaction`
 * y tenía tres defectos que la volvían no confiable:
 *
 *  1. `cashSales` NO era efectivo: sumaba `transactionTotal` de los tipos 0 y 6
 *     SIN filtrar medio de pago, así que una venta con tarjeta o transferencia
 *     entraba como caja. En un reporte de flujo de efectivo, ése es el error
 *     central.
 *  2. `initialCash` NO era un saldo de apertura: era el NETO del período
 *     anterior de igual largo. Con un rango de 7 días, el "saldo inicial" era
 *     lo que había pasado los 7 días previos.
 *  3. Ignoraba `fin_account`, que sí lleva saldos reales. El sistema mostraba
 *     dos verdades sobre el efectivo, las dos como ciertas.
 *
 * ── La fuente correcta ──────────────────────────────────────────────────────
 * `fin_movement` (mig 72) registra CADA movimiento de dinero con su cuenta, su
 * categoría, su medio de pago y su signo (`kind`), y `fin_account` lleva
 * `openingbalance`. Con eso el reporte cuadra POR CONSTRUCCIÓN:
 *
 *     saldo inicial + entradas − salidas = saldo final
 *
 * Los tres términos salen de la misma tabla, así que no pueden divergir. El
 * reporte devuelve además `balances.check` con la diferencia: si alguna vez no
 * da cero, hay un bug de datos y se ve en el propio payload en vez de que
 * alguien tenga que sospecharlo.
 *
 * ── Invariantes de `fin_movement` que no se pueden ignorar ──────────────────
 *  - `amount` es SIEMPRE positivo; el signo lo da `kind` ('income'|'expense').
 *    Sumar sin mirar `kind` da un número sin sentido.
 *  - `status = 0` es ANULADO. Sin filtrarlo, los movimientos revertidos se
 *    cuentan como reales.
 *  - Las transferencias entre cuentas propias (`source = 'transfer'`) generan
 *    DOS movimientos: un expense en la cuenta origen y un income en la destino.
 *    A nivel empresa no son flujo —la plata no entró ni salió— así que se
 *    EXCLUYEN de entradas/salidas; siguen afectando el saldo POR CUENTA, que es
 *    donde sí importan.
 *
 * Read-only. Sin formatear: el front formatea.
 */
final class CashflowService
{
    /**
     * @param string $from 'Y-m-d H:i:s'
     * @param string $to   'Y-m-d H:i:s'
     * @param string $outletId Sucursal del view-scope; '' = todas.
     */
    public function getCashFlow(string $from, string $to, string $companyId, string $outletId = ''): array
    {
        $accounts = $this->accountBalances($from, $to, $companyId, $outletId);

        $opening = 0.0;
        $closing = 0.0;
        foreach ($accounts as $a) {
            $opening += $a['opening'];
            $closing += $a['closing'];
        }

        $income  = $this->byCategory($from, $to, $companyId, $outletId, 'income');
        $expense = $this->byCategory($from, $to, $companyId, $outletId, 'expense');

        $incomeTotal  = array_sum(array_column($income, 'amount'));
        $expenseTotal = array_sum(array_column($expense, 'amount'));

        return [
            'from' => $from,
            'to'   => $to,
            'balances' => [
                'opening' => round($opening, 2),
                'closing' => round($closing, 2),
                'net'     => round($incomeTotal - $expenseTotal, 2),
                // Tiene que ser 0. Se expone en vez de asumirse: si algún día no
                // lo es, el reporte lo dice solo.
                'check'   => round($opening + $incomeTotal - $expenseTotal - $closing, 2),
            ],
            'accounts'     => $accounts,
            'income'       => $income,
            'expense'      => $expense,
            'incomeTotal'  => round($incomeTotal, 2),
            'expenseTotal' => round($expenseTotal, 2),
        ];
    }

    /**
     * Saldo de apertura y cierre POR CUENTA.
     *
     * El saldo se RECOMPUTA (`openingbalance + Σ movimientos`), no se lee de
     * `fin_account.currentbalance`: esa columna es un cache del saldo de HOY y
     * no sirve para un período que termina en el pasado.
     *
     * Acá las transferencias SÍ cuentan: mover plata del efectivo al banco no
     * es flujo de la empresa pero cambia el saldo de las dos cuentas.
     *
     * @return list<array<string,mixed>>
     */
    private function accountBalances(string $from, string $to, string $companyId, string $outletId): array
    {
        $params = [$companyId];
        $accWhere = 'a.companyid = ?::uuid AND a.status = 1';
        if ($outletId !== '') {
            // `outletid IS NULL` = cuenta global de todas las sucursales: se
            // incluye siempre, o el efectivo global desaparecería al filtrar.
            $accWhere .= ' AND (a.outletid = ?::uuid OR a.outletid IS NULL)';
            $params[] = $outletId;
        }

        $rows = \ncmRows(
            "SELECT a.accountid, a.name, a.type, a.openingbalance,
                    COALESCE(SUM(CASE WHEN m.date <  ?::timestamptz AND m.kind = 'income'  THEN m.amount END), 0) AS pre_in,
                    COALESCE(SUM(CASE WHEN m.date <  ?::timestamptz AND m.kind = 'expense' THEN m.amount END), 0) AS pre_out,
                    COALESCE(SUM(CASE WHEN m.date >= ?::timestamptz AND m.date <= ?::timestamptz AND m.kind = 'income'  THEN m.amount END), 0) AS per_in,
                    COALESCE(SUM(CASE WHEN m.date >= ?::timestamptz AND m.date <= ?::timestamptz AND m.kind = 'expense' THEN m.amount END), 0) AS per_out
               FROM fin_account a
               LEFT JOIN fin_movement m
                 ON m.accountid = a.accountid AND m.companyid = a.companyid AND m.status = 1
              WHERE {$accWhere}
              GROUP BY a.accountid, a.name, a.type, a.openingbalance
              ORDER BY a.type, a.name",
            array_merge([$from, $from, $from, $to, $from, $to], $params)
        );

        $out = [];
        foreach ($rows as $r) {
            $opening = (float) $r['openingbalance'] + (float) $r['pre_in'] - (float) $r['pre_out'];
            $movement = (float) $r['per_in'] - (float) $r['per_out'];
            $out[] = [
                'accountId' => (string) $r['accountid'],
                'name'      => (string) $r['name'],
                'type'      => (string) $r['type'],
                'opening'   => round($opening, 2),
                'income'    => round((float) $r['per_in'], 2),
                'expense'   => round((float) $r['per_out'], 2),
                'closing'   => round($opening + $movement, 2),
            ];
        }
        return $out;
    }

    /**
     * Entradas o salidas del período AGRUPADAS POR CATEGORÍA.
     *
     * Excluye `source = 'transfer'`: mover plata entre cuentas propias no es
     * flujo de la empresa. Incluirlo inflaría entradas y salidas por el mismo
     * monto — el neto quedaría bien y los totales mentirían.
     *
     * Los movimientos sin categoría caen en "Sin categoría" en vez de
     * descartarse: son plata real y omitirlos rompería el cuadre.
     *
     * @return list<array{categoryId:?string,name:string,amount:float}>
     */
    private function byCategory(string $from, string $to, string $companyId, string $outletId, string $kind): array
    {
        $where  = "m.companyid = ?::uuid AND m.status = 1 AND m.kind = ?
                   AND m.source <> 'transfer'
                   AND m.date >= ?::timestamptz AND m.date <= ?::timestamptz";
        $params = [$companyId, $kind, $from, $to];
        if ($outletId !== '') {
            $where   .= ' AND (m.outletid = ?::uuid OR m.outletid IS NULL)';
            $params[] = $outletId;
        }

        $rows = \ncmRows(
            "SELECT m.categoryid, COALESCE(c.name, 'Sin categoría') AS name, SUM(m.amount) AS total
               FROM fin_movement m
               LEFT JOIN fin_category c ON c.categoryid = m.categoryid AND c.companyid = m.companyid
              WHERE {$where}
              GROUP BY m.categoryid, c.name
              ORDER BY SUM(m.amount) DESC",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'categoryId' => $r['categoryid'] !== null ? (string) $r['categoryid'] : null,
                'name'       => (string) $r['name'],
                'amount'     => round((float) $r['total'], 2),
            ];
        }
        return $out;
    }
}
