<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Cuentas por Cobrar/Pagar / Open Invoices (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportOpenInvoicesService.php (Fase 2 batch 3). Único cambio
 * vs el original: namespace + `final` + recibe $companyId por parámetro en vez de leerlo
 * de la constante global (mejor higiene multi-tenant; el endpoint pasa COMPANY_ID).
 *
 * Read-only. Sin ROC (companyId siempre bound en cada SELECT). Helpers globales usados:
 * getContactData, getCustomerName — ambos viven en app/includes/functions.php.
 *
 * Réplica fiel del legacy:
 *  - el "self-heal write" del legacy (marcar transactionComplete=1 durante el GET) se ELIMINA
 *    (convención §16 — nunca escribir en un read; además los contactos totalmente pagados
 *    ya quedan ocultos por el filtro needsTopay).
 *  - estado de vencimiento: vencida si dueDate <= hoy (o sin dueDate); resto "por vencer"
 *    (réplica de la rareza del legacy donde la rama "normal" nunca se daba).
 */
final class OpenInvoicesService
{
    /** @param string $state 'income' (tipo 3, clientes) | 'outcome' (tipo 4, proveedores). */
    public function general($state, $companyId)
    {
        $isToPay    = ($state === 'outcome');
        $type       = $isToPay ? 4 : 3;
        $contactCol = $isToPay ? 'supplierId' : 'customerId';

        $sql = "SELECT $contactCol as cid, transactionId as saleId, transactionDate as date,
                       transactionDueDate as dueDate, invoiceNo as invoice, invoicePrefix as prefix,
                       transactionTotal as total, transactionDiscount as discount,
                       transactionComplete as complete
                FROM transaction
                WHERE transactionComplete = false AND transactionType = ? AND companyId = ?
                ORDER BY transactionDueDate DESC LIMIT 5000";
        $res = ncmExecute($sql, [$type, $companyId], false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => [], 'kpi' => ['totalDebt' => 0, 'accounts' => 0, 'expired' => 0, 'toExpire' => 0]];
        }

        $byContact = [];
        $saleIds = [];
        foreach ($res as $f) {
            $cid = (string) $f['cid'];
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
        $payedMap = $this->payedByParent($saleIds, $companyId);

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

            if (!$needsTopay) { continue; }
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

    /** SUM(ABS) de pagos+devoluciones (tipo 5,6) por venta origen (transaction_link, mig 115), scopeado por companyId. */
    private function payedByParent(array $ids, $companyId)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $derivedByOrigin = (new \Punto\Api\Services\TransactionLinkService())->mapDerivedIdsByOrigins($companyId, $ids);
        $allDerived = [];
        foreach ($derivedByOrigin as $derivedIds) {
            foreach ($derivedIds as $d) {
                $allDerived[] = $d;
            }
        }
        if (!$allDerived) {
            return [];
        }

        $ph  = implode(',', array_fill(0, count($allDerived), '?'));
        $res = ncmExecute(
            "SELECT transactionId,
                    ABS(transactionTotal - COALESCE(transactionDiscount, 0)) as payed
             FROM transaction WHERE transactionType IN (5,6) AND companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $allDerived), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $payedByDerived = [];
        foreach ($res as $r) {
            $payedByDerived[(string) $r['transactionId']] = (float) $r['payed'];
        }

        $map = [];
        foreach ($derivedByOrigin as $originId => $derivedIds) {
            $sum = 0.0;
            foreach ($derivedIds as $d) {
                $sum += $payedByDerived[$d] ?? 0.0;
            }
            $map[$originId] = abs($sum);
        }
        return $map;
    }
}
