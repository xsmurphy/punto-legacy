<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * Obligaciones financieras (egresos futuros) — helper compartido entre
 * `/v1/finance/forecast.php` (F3, context/30-cheques-prevision-creditos.md)
 * y `/v1/notifications/feed.php` (centro de notificaciones,
 * context/31-centro-de-notificaciones.md). Extraído de forecast.php para
 * no duplicar los 3 queries (cheques emitidos, cuotas de crédito, compras
 * con vencimiento) — misma lógica, dos consumidores.
 *
 * `$from = null` → sin límite inferior (incluye TODAS las vencidas hacia
 * atrás, sin importar cuán viejas). forecast.php siempre pasa un `$from`
 * concreto (rango acotado, comportamiento sin cambios); el feed de
 * notificaciones pasa `null` para traer vencidas sin límite + `$to` = hoy+7
 * en una sola pasada.
 */
final class ObligationsService
{
    /**
     * @return array<int, array{id:string,type:string,label:string,party:?string,dueDate:string,amount:float,link:string}>
     */
    public function list(string $companyId, ?string $from, string $to): array
    {
        $obligations = [];

        // ── Cheques emitidos (egreso) ────────────────────────────────────
        [$where, $params] = $this->dueWhere(
            "companyid = ? AND direction = 'issued' AND status IN ('pending', 'deposited') AND duedate IS NOT NULL",
            [$companyId],
            $from,
            $to
        );
        $rs = ncmExecute(
            "SELECT checkid, duedate, amount, partyname, bankname, checknumber
               FROM fin_check
              WHERE {$where}
              ORDER BY duedate ASC",
            $params,
            false,
            true
        );
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $obligations[] = [
                    'id'      => (string) $f['checkid'],
                    'type'    => 'check',
                    'label'   => $this->checkLabel($f, 'Cheque emitido'),
                    'party'   => $f['partyname'] !== null ? (string) $f['partyname'] : null,
                    'dueDate' => (string) $f['duedate'],
                    'amount'  => (float) $f['amount'],
                    'link'    => '/finanzas/cheques',
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        // ── Cuotas de crédito pendientes (egreso) ────────────────────────
        [$where, $params] = $this->dueWhere(
            "i.companyid = ? AND i.status = 'pending'",
            [$companyId],
            $from,
            $to,
            'i.duedate'
        );
        $rs = ncmExecute(
            "SELECT i.installmentid, i.duedate, i.amount, i.seq, l.loanid, l.name
               FROM fin_loan_installment i
               JOIN fin_loan l ON l.loanid = i.loanid
              WHERE {$where}
              ORDER BY i.duedate ASC",
            $params,
            false,
            true
        );
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $obligations[] = [
                    'id'      => (string) $f['installmentid'],
                    'type'    => 'loan_installment',
                    'label'   => 'Cuota ' . (string) $f['seq'] . ' — ' . (string) $f['name'],
                    'party'   => null,
                    'dueDate' => (string) $f['duedate'],
                    'amount'  => (float) $f['amount'],
                    'link'    => '/finanzas/creditos?id=' . (string) $f['loanid'],
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        // ── Compras con vencimiento (cuentas por pagar, egreso) ──────────
        [$where, $params] = $this->dueWhere(
            // type 4 = compra a CRÉDITO pendiente (cuenta por pagar) — el caso
            // canónico de una previsión de egreso. type 1 (contado) se mantiene
            // por compatibilidad con los datos ya cargados: hoy el owner usa el
            // vencimiento de una compra contado como "lo que debo", y sacarlo
            // vaciaría las previsiones existentes. Una compra a crédito ya
            // saldada tiene transactionComplete = true y sale sola del listado.
            'companyId = ? AND transactionType IN (1,4) AND transactionStatus = 1'
                . ' AND transactionDueDate IS NOT NULL'
                . ' AND (transactionType = 1 OR transactionComplete = false)',
            [$companyId],
            $from,
            $to,
            'transactionDueDate'
        );
        $rs = ncmExecute(
            "SELECT transactionId, transactionDueDate, transactionTotal, transactionDiscount, invoiceNo
               FROM transaction
              WHERE {$where}
              ORDER BY transactionDueDate ASC",
            $params,
            false,
            true
        );
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $invoiceNo = $f['invoiceno'] ?? null;
                $obligations[] = [
                    'id'      => (string) $f['transactionid'],
                    'type'    => 'purchase',
                    'label'   => 'Compra' . ($invoiceNo !== null ? ' ' . (string) $invoiceNo : ''),
                    'party'   => null,
                    'dueDate' => (string) $f['transactionduedate'],
                    'amount'  => (float) $f['transactiontotal'] - (float) ($f['transactiondiscount'] ?? 0),
                    'link'    => '/purchase/' . (string) $f['transactionid'],
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $obligations;
    }

    /**
     * Arma el WHERE + params con el rango de vencimiento. `$from = null` omite
     * el límite inferior (usado por el feed de notificaciones para traer
     * vencidas sin tope hacia atrás).
     *
     * @param array<int, string> $baseParams
     * @return array{0:string,1:array<int, string>}
     */
    private function dueWhere(string $baseWhere, array $baseParams, ?string $from, string $to, string $dueCol = 'duedate'): array
    {
        $where  = $baseWhere;
        $params = $baseParams;
        if ($from !== null) {
            $where .= " AND {$dueCol}::date >= ?";
            $params[] = $from;
        }
        $where .= " AND {$dueCol}::date <= ?";
        $params[] = $to;
        return [$where, $params];
    }

    /** Label legible de un cheque: "Banco #nro" con fallback si no hay ninguno cargado. */
    private function checkLabel(array $f, string $fallback): string
    {
        $parts = [];
        if (!empty($f['bankname'])) {
            $parts[] = (string) $f['bankname'];
        }
        if (!empty($f['checknumber'])) {
            $parts[] = '#' . (string) $f['checknumber'];
        }
        return $parts !== [] ? implode(' ', $parts) : $fallback;
    }
}
