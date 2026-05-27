<?php
/**
 * Dominio de Reportes — Cuentas por Cobrar/Pagar / Open Invoices (capa API, motor ERP).
 *
 * UNA vista de lectura: general($state) — facturas a crédito sin completar (transactionComplete < 1)
 * agrupadas por contacto, con sus facturas, total comprado/pagado/adeudado y estado de vencimiento.
 *   - state='income'  (default): ventas a crédito (tipo 3) → cuentas por COBRAR (clientes).
 *   - state='outcome':           compras a crédito (tipo 4) → cuentas por PAGAR (proveedores).
 *
 * Read-only. Devuelve datos CRUDOS (números + estructura contacto→facturas); el front formatea +
 * arma la tabla anidada + KPIs. Ver REGLA RAÍZ 2.
 *
 * El "self-heal write" del legacy (marcar transactionComplete=1 durante el GET si la deuda ~0)
 * se ELIMINA por convención §16 (nunca escribir en un read; además los contactos totalmente
 * pagados ya quedan ocultos por el filtro needsTopay).
 *
 * Estado de vencimiento — réplica fiel del legacy (incluida su rareza): vencida si dueDate <= hoy
 * (o sin dueDate); en caso contrario "por vencer" (el legacy comparaba un timestamp contra una
 * duración → la rama "normal" nunca se daba, así que toda no-vencida es "por vencer").
 *
 * Tenant: companyId bound; getAllToPayTransactions / contactos scopeados por companyId.
 */
class ReportOpenInvoicesService
{
    /** @param string $state 'income' (tipo 3, clientes) | 'outcome' (tipo 4, proveedores). */
    public function general($state)
    {
        $isToPay = ($state === 'outcome');
        $type    = $isToPay ? 4 : 3;
        $contactCol = $isToPay ? 'supplierId' : 'customerId';

        // Facturas a crédito abiertas (complete < 1) del tipo correspondiente.
        $sql = "SELECT $contactCol as cid, transactionId as saleId, transactionDate as date,
                       transactionDueDate as dueDate, invoiceNo as invoice, invoicePrefix as prefix,
                       transactionTotal as total, transactionDiscount as discount,
                       transactionParentId as parent, transactionComplete as complete
                FROM transaction
                WHERE transactionComplete = false AND transactionType = ? AND companyId = ?
                ORDER BY transactionDueDate DESC LIMIT 5000";
        $res = ncmExecute($sql, [$type, COMPANY_ID], false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => [], 'kpi' => ['totalDebt' => 0, 'accounts' => 0, 'expired' => 0, 'toExpire' => 0]];
        }

        // Agrupar por contacto + recolectar saleIds para el SUM de pagos (batch).
        $byContact = [];
        $saleIds = [];
        foreach ($res as $f) {
            $cid = (string) $f['cid'];
            // total: en cuentas por pagar es el total; en cobrar es total − descuento (igual que el legacy).
            $total = $isToPay ? (float) $f['total'] : ((float) $f['total'] - (float) $f['discount']);
            $byContact[$cid][] = [
                'invoiceNo' => (string) ($f['prefix'] ?? '') . (string) ($f['invoice'] ?? ''),
                'saleId'    => (string) $f['saleId'],
                'date'      => (string) ($f['date'] ?? ''),
                'dueDate'   => (string) ($f['dueDate'] ?? ''),
                'total'     => $total,
            ];
            $saleIds[] = (string) $f['saleId'];
        }
        $payedMap = $this->payedByParent($saleIds);

        $today  = strtotime(date('Y-m-d 00:00:00'));
        $rows = [];
        $kTotalDebt = 0.0; $kAccounts = 0; $kExpired = 0; $kToExpire = 0;

        foreach ($byContact as $cid => $invoices) {
            $contact = getContactData($cid, $isToPay ? 'id' : 'uid', true);
            $name = $contact ? getCustomerName($contact) : 'Sin Contacto Asociado';
            $totalSales = 0.0; $totalPaid = 0.0; $totalDebt = 0.0; $needsTopay = false;
            $outInvoices = [];

            foreach ($invoices as $inv) {
                $payed = $payedMap[$inv['saleId']] ?? 0;
                $topay = $inv['total'] - $payed;
                if ($topay > 0) { $needsTopay = true; }

                $strDue = $inv['dueDate'] ? strtotime($inv['dueDate']) : 0;
                // Réplica fiel: vencida si dueDate <= hoy (o sin fecha); si no, "por vencer".
                $dueStatus = ($strDue <= $today) ? 'expired' : 'toExpire';
                if ($dueStatus === 'expired') { $kExpired++; } else { $kToExpire++; }
                $kAccounts++;

                $outInvoices[] = [
                    'invoiceNo' => $inv['invoiceNo'],
                    'saleId'    => $inv['saleId'],
                    'date'      => $inv['date'],
                    'dueDate'   => $inv['dueDate'],
                    'total'     => $inv['total'],
                    'payed'     => $payed,
                    'topay'     => $topay,
                    'dueStatus' => $dueStatus,
                ];
                $totalSales += $inv['total']; $totalPaid += $payed; $totalDebt += $topay;
            }

            if (!$needsTopay) { continue; }   // contactos totalmente pagados: ocultos (como el legacy)
            $kTotalDebt += $totalDebt;

            $rows[] = [
                'contactId'  => (string) $cid,
                'name'       => $name,
                'tin'        => (string) ($contact['ruc'] ?? '-'),
                'phone'      => (string) ($contact['phone'] ?? ($contact['phone2'] ?? '')),
                'email'      => (string) ($contact['email'] ?? ''),
                'totalSales' => $totalSales,
                'totalPaid'  => $totalPaid,
                'totalDebt'  => $totalDebt,
                'invoices'   => $outInvoices,
            ];
        }

        return ['rows' => $rows, 'kpi' => [
            'totalDebt' => $kTotalDebt, 'accounts' => $kAccounts, 'expired' => $kExpired, 'toExpire' => $kToExpire,
        ]];
    }

    /** SUM(ABS) de pagos+devoluciones (tipo 5,6) por transactionParentId, scopeado por companyId. */
    private function payedByParent(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionParentId, SUM(ABS(transactionTotal)) as payed
             FROM transaction WHERE transactionType IN (5,6) AND companyId = ? AND transactionParentId IN ($ph)
             GROUP BY transactionParentId",
            array_merge([COMPANY_ID], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r['transactionParentId']] = abs((float) $r['payed']);
        }
        return $map;
    }
}
