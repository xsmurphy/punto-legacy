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
            $balance = $this->contactBalance($invoices, $payedMap);

            // El conteo de cuentas/vencidas/por-vencer corre sobre TODAS las
            // facturas del contacto, gated o no (réplica fiel del legacy: un
            // contacto que terminó de pagar igual aportaba a estos contadores
            // mientras estuvo en la lista de "incompletas").
            $outInvoices = [];
            foreach ($balance['invoices'] as $inv) {
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
                    'payed'     => $inv['payed'],
                    'topay'     => $inv['topay'],
                    'dueStatus' => $dueStatus,
                ];
            }

            if (!$balance['needsTopay']) { continue; }
            $kTotalDebt += $balance['totalDebt'];

            $rows[] = [
                'contactId'  => (string) $cid,
                'name'       => $name,
                'tin'        => (string) ($contact['ruc'] ?? '-'),
                'phone'      => (string) ($contact['phone'] ?? ($contact['phone2'] ?? '')),
                'email'      => (string) ($contact['email'] ?? ''),
                'totalSales' => $balance['totalSales'],
                'totalPaid'  => $balance['totalPaid'],
                'totalDebt'  => $balance['totalDebt'],
                'invoices'   => $outInvoices,
            ];
        }

        return ['rows' => $rows, 'kpi' => [
            'totalDebt' => $kTotalDebt, 'accounts' => $kAccounts, 'expired' => $kExpired, 'toExpire' => $kToExpire,
        ]];
    }

    /**
     * Saldo pendiente de UN contacto puntual (cuentas por cobrar si
     * $isCustomer, por pagar si no) — misma fuente de verdad que general():
     * transacciones a crédito incompletas (transactionComplete = false)
     * menos lo saldado vía transaction_link (payedByParent, mig 115/123),
     * decidido por `contactBalance()` (ver docblock ahí — es el mismo método
     * que usa general() para calcular cada fila, no una réplica).
     *
     * Existe para que `ContactAnalyticsService::compute()` (ficha de
     * contacto) no reimplemente el cálculo del saldo con su propio criterio
     * — antes leía la columna muerta `transactionPaid` (nadie la escribe) y
     * divergía en silencio del reporte general.
     */
    public function forContact(string $contactId, string $companyId, bool $isCustomer): float
    {
        $type       = $isCustomer ? 3 : 4;
        $contactCol = $isCustomer ? 'customerId' : 'supplierId';

        $sql = "SELECT transactionId as saleId, transactionTotal as total, transactionDiscount as discount
                FROM transaction
                WHERE transactionComplete = false AND transactionType = ? AND companyId = ? AND $contactCol = ?";
        $res = ncmExecute($sql, [$type, $companyId, $contactId], false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return 0.0;
        }

        $invoices = [];
        $saleIds  = [];
        foreach ($res as $f) {
            $saleId = (string) $f['saleId'];
            // Proveedor: total crudo. Cliente: total menos descuento (misma regla que general()).
            $total = $isCustomer
                ? ((float) $f['total'] - (float) $f['discount'])
                : (float) $f['total'];
            $invoices[] = ['saleId' => $saleId, 'total' => $total];
            $saleIds[]  = $saleId;
        }

        $payedMap = $this->payedByParent($saleIds, $companyId);
        $balance  = $this->contactBalance($invoices, $payedMap);

        return $balance['needsTopay'] ? $balance['totalDebt'] : 0.0;
    }

    /**
     * Núcleo único de "cuánto debe un contacto": dado su listado de facturas
     * abiertas (cada una con al menos `saleId`/`total`) y el `payedMap` ya
     * resuelto (`payedByParent`), resta payed de total factura por factura.
     * `general()` (reporte agregado, todos los contactos) y `forContact()`
     * (ficha de un contacto puntual) pasan AMBOS por acá — es la única resta
     * `total - payed` de la clase. Antes de esta extracción, T6 se debía a
     * que `ContactAnalyticsService` reimplementaba esta cuenta con su propio
     * criterio (columna `transactionPaid`, que nadie escribe) y divergía en
     * silencio del reporte general; ahora no hay una segunda fórmula que
     * pueda volver a desincronizarse.
     *
     * `needsTopay`: alcanza con que UNA factura tenga saldo positivo para que
     * el contacto cuente como deudor, aunque el neto total dé negativo por
     * otra factura sobrepagada (réplica fiel del legacy).
     *
     * @param array<int,array{saleId:string,total:float,dueDate?:string,invoiceNo?:string,date?:string}> $invoices
     * @param array<string,float> $payedMap
     * @return array{needsTopay:bool,totalSales:float,totalPaid:float,totalDebt:float,invoices:array}
     */
    private function contactBalance(array $invoices, array $payedMap): array
    {
        $needsTopay = false;
        $totalSales = 0.0; $totalPaid = 0.0; $totalDebt = 0.0;
        $detail = [];

        foreach ($invoices as $inv) {
            $payed = $payedMap[$inv['saleId']] ?? 0;
            $topay = $inv['total'] - $payed;
            if ($topay > 0) { $needsTopay = true; }

            $totalSales += $inv['total'];
            $totalPaid  += $payed;
            $totalDebt  += $topay;

            $detail[] = $inv + ['payed' => $payed, 'topay' => $topay];
        }

        return [
            'needsTopay' => $needsTopay,
            'totalSales' => $totalSales,
            'totalPaid'  => $totalPaid,
            'totalDebt'  => $totalDebt,
            'invoices'   => $detail,
        ];
    }

    /**
     * Lo que reduce la deuda por documento origen, scopeado por companyId,
     * separado en dos familias porque tienen semántica de monto distinta:
     *
     *   - kind='credit_payment' (tipo 5, cobro a cliente): usa
     *     `TransactionLinkService::mapSumDerivedAmounts()` — mig 123, respeta
     *     el `amount` del vínculo cuando un recibo se repartió entre varias
     *     facturas (`COALESCE(tl.amount, transactionTotal)`, sin restar
     *     discount porque un recibo nunca lo tiene).
     *   - kind='return' (tipo 6, devolución de venta) y
     *     'purchase_credit_note' (tipo 14, mig 122): NUNCA se les asigna
     *     `amount` en `transaction_link` (nadie las reparte entre varios
     *     orígenes) y SÍ tienen `transactionDiscount` real seteado al crearse
     *     (ver `ReturnService`/`PurchaseCreditNoteService`) — se preserva el
     *     cálculo `ABS(transactionTotal - discount)` documento por documento,
     *     igual que antes de este mig.
     *
     * El `COALESCE(transactionStatus, 1) <> 6` excluye SOLO lo anulado: sin el
     * COALESCE, una fila legacy con status NULL daría NULL (no false) y
     * quedaría fuera del SUM — el saldo pendiente saltaría hacia arriba en
     * datos viejos.
     * Usado tanto para `state='income'` (clientes, tipo 3) como `'outcome'`
     * (proveedores, tipo 4) — el filtro real de qué aplica a cada uno lo da
     * `transaction_link`, no un IN de transactionType.
     */
    private function payedByParent(array $ids, $companyId)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $links = new \Punto\Api\Services\TransactionLinkService();

        $map = $links->mapSumDerivedAmounts($companyId, $ids, 'credit_payment');

        // return + purchase_credit_note: discount-aware, documento por
        // documento (amount de transaction_link no aplica — ver docblock).
        // array_merge_recursive (no array_merge): un mismo originId con
        // vínculos de ambos kinds concatena las dos listas de derivedIds en
        // vez de que la segunda pise a la primera (no debería pasar en la
        // práctica — 'return' cuelga de ventas tipo 3 y 'purchase_credit_note'
        // de compras tipo 4 — pero el merge queda correcto igual si algún día
        // coexisten).
        $derivedByOrigin = array_merge_recursive(
            $links->mapDerivedIdsByOrigins($companyId, $ids, 'return'),
            $links->mapDerivedIdsByOrigins($companyId, $ids, 'purchase_credit_note')
        );
        $allDerived = [];
        foreach ($derivedByOrigin as $derivedIds) {
            foreach ($derivedIds as $d) {
                $allDerived[] = $d;
            }
        }
        if ($allDerived) {
            $ph  = implode(',', array_fill(0, count($allDerived), '?'));
            $res = ncmExecute(
                "SELECT transactionId,
                        ABS(transactionTotal - COALESCE(transactionDiscount, 0)) as payed
                 FROM transaction WHERE transactionType IN (6,14) AND COALESCE(transactionStatus, 1) <> 6 AND companyId = ? AND transactionId IN ($ph)",
                array_merge([$companyId], $allDerived), false, false, true
            );
            $res = is_array($res) ? $res : [];
            $payedByDerived = [];
            foreach ($res as $r) {
                $payedByDerived[(string) $r['transactionId']] = (float) $r['payed'];
            }
            foreach ($derivedByOrigin as $originId => $derivedIds) {
                $sum = 0.0;
                foreach ($derivedIds as $d) {
                    $sum += $payedByDerived[$d] ?? 0.0;
                }
                $map[$originId] = ($map[$originId] ?? 0.0) + abs($sum);
            }
        }

        return $map;
    }
}
