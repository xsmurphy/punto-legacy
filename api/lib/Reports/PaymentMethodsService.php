<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Documents\DocumentNumber;

/**
 * Dominio de Reportes — Ventas por Medios de Pago (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportPaymentMethodsService.php (Fase 2 batch 3).
 * Cambios vs el original: namespace + `final` + `csvToBankData()` portado como método
 * privado (sólo existe en panel/includes/functions.php). El resto de helpers
 * (getCurrentOutletName, getCustomerData, getPaymentMethodName, groupByPaymentMethod,
 * iftn, getTaxonomyName) ya viven en app/includes/functions.php → resuelven por global.
 *
 * Tenant: filtro $roc derivado de COMPANY_ID del JWT — nunca de input.
 *
 * DEUDA documentada: csvToBankData() devuelve markup HTML (badge span) — viola REGLA RAÍZ 2
 * (API no emite presentación). Se preserva para mantener byte-diff con el legacy; el refactor
 * a "devolver parts crudos + componer span en el front" es deuda separada.
 */
final class PaymentMethodsService
{
    /**
     * Dataset del reporte de medios de pago en un período.
     * @return array{detail: array, summary: array}
     */
    public function report($from, $to, $roc, $companyId, bool $forceRollup = false): array
    {
        // `invoicePrefix` y `transactionType` viajan en el SELECT: el prefijo
        // está CONGELADO en la transacción desde la mig 209 y es el que hay
        // que mostrar — resolverlo contra la caja daría el punto de expedición
        // ACTUAL, así que al cambiarlo todo el historial cambiaría de número
        // ante el cliente y ante la SET.
        $sql = "SELECT transactionId, transactionPaymentType, transactionTotal,
                       invoiceNo, invoicePrefix, transactionType,
                       outletId, customerId, registerId
                FROM transaction
                WHERE transactionType IN (0, 5)
                AND " . SaleFilters::notVoidedSql() . "
                AND transactionDate BETWEEN ? AND ?" . $roc;

        $res = ncmExecute($sql, [$from, $to], false, true);

        $detail      = [];
        $group       = [];
        // Se recolectan primero y las cajas se resuelven en UNA query después
        // del bucle (ver más abajo), en vez de una por fila.
        $pending     = [];

        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f       = $res->fields;
                $methods = json_decode($f['transactionPaymentType'] ?? '', true);
                if (!is_array($methods) || empty($methods)) {
                    $res->MoveNext();
                    continue;
                }

                $outletName = getCurrentOutletName($f['outletId']);
                $customer   = getCustomerData($f['customerId'], 'uid', true);
                $customerTin  = $customer['ruc']  ?? '-';
                $customerName = $customer['name'] ?? '';


                foreach ($methods as $meth) {
                    $extra = ($meth['type'] ?? '') === 'check'
                        ? $this->csvToBankData($meth['extra'] ?? '')
                        : ($meth['extra'] ?? '');

                    $detail[] = [
                        'transactionId' => (string) $f['transactionId'],
                        // Se completa después del bucle, con el prefijo y el
                        // ancho de talonario ya resueltos en batch.
                        'invoiceNo'     => '',
                        '_registerId'   => (string) ($f['registerId'] ?? ''),
                        '_invoiceNo'    => (string) ($f['invoiceNo'] ?? ''),
                        '_prefix'       => (string) ($f['invoicePrefix'] ?? ''),
                        '_txType'       => $f['transactionType'] ?? null,
                        'customerName'  => $customerName,
                        'customerTin'   => $customerTin,
                        'methodType'    => $meth['type'] ?? ($meth['name'] ?? ''),
                        'methodName'    => iftn(getPaymentMethodName($meth['type'] ?? ''), $meth['name'] ?? ''),
                        'extra'         => (string) $extra,
                        'outletName'    => (string) $outletName,
                        'price'         => (float) ($meth['price'] ?? 0),
                        'total'         => (float) ($meth['total'] ?? 0),
                        'txnTotal'      => (float) ($f['transactionTotal'] ?? 0),
                    ];
                }

                $group = groupByPaymentMethod($methods, $group);
                $res->MoveNext();
            }
            $res->Close();
        }

        // ── Número de comprobante, resuelto en batch ────────────────────────
        //
        // Antes esto era un `SELECT registerInvoicePrefix FROM register` por
        // caja dentro del bucle. Dos problemas: la columna NO EXISTE —el
        // prefijo vive en `register.data->>'registerInvoicePrefix'`, así que
        // el reporte tiraba 500 en producción— y además resolverlo contra la
        // caja daba el punto de expedición ACTUAL en vez del que tenía la
        // venta.
        //
        // Mismo criterio que `FiscalService` y `TransactionsService::detail()`:
        // se usa el prefijo CONGELADO de la transacción y solo se cae al de la
        // caja cuando está vacío (ventas anteriores a la mig 209). El número
        // sale de `DocumentNumber::format()`, el mismo formateador del ticket
        // y del detalle, para que el comprobante se vea igual en todos lados.
        $txnSvc    = new TransactionsService();
        $registers = $txnSvc->registerInfo(array_column($detail, '_registerId'), $companyId);
        foreach ($detail as $i => $row) {
            $reg    = $registers[$row['_registerId']] ?? [];
            $prefix = $row['_prefix'] !== '' ? $row['_prefix'] : (string) ($reg['invoicePrefix'] ?? '');
            $detail[$i]['invoiceNo'] = DocumentNumber::format(
                $row['_invoiceNo'],
                $prefix,
                $txnSvc->padWidthFor($reg, $row['_txType'])
            );
            unset(
                $detail[$i]['_registerId'],
                $detail[$i]['_invoiceNo'],
                $detail[$i]['_prefix'],
                $detail[$i]['_txType']
            );
        }

        usort($group, fn($a, $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));

        if (!$forceRollup && empty($_ENV['REPORTS_ROLLUP_ENABLED'])) {
            $summary = [];
            foreach ($group as $g) {
                $summary[] = [
                    'type'  => $g['type'] ?? '',
                    'name'  => iftn(getPaymentMethodName($g['type'] ?? '', true), getPaymentMethodName($g['type'] ?? '')),
                    'price' => (float) ($g['price'] ?? 0),
                    'total' => (float) ($g['price'] ?? 0),
                    'count' => (int) ($g['count'] ?? 0),
                ];
            }
        } else {
            $reader  = new RollupReader();
            $payRows = $reader->paymentsRange($companyId, $from, $to, []);
            $summary = [];
            foreach ($payRows as $pr) {
                $summary[] = [
                    'type'  => $pr['type'],
                    'name'  => iftn(getPaymentMethodName($pr['type'], true), getPaymentMethodName($pr['type'])),
                    'price' => $pr['price'],
                    'total' => (float) $pr['price'],
                    'count' => (int) ($pr['count'] ?? 0),
                ];
            }
        }

        return ['detail' => $detail, 'summary' => $summary];
    }

    /**
     * Port fiel del csvToBankData del panel (no existe en /app). Devuelve markup HTML
     * (deuda documentada arriba; mantiene byte-diff). validity() del panel chequea no-vacío
     * — replicado inline para no arrastrar otra dependencia.
     */
    private function csvToBankData(string $data): string
    {
        $parts   = explode(';', $data);
        $bankId  = $parts[0] ?? '';
        $bank    = $bankId !== '' ? getTaxonomyName($bankId) : '';
        $numChk  = $parts[1] ?? '';
        $dueChck = $parts[2] ?? '';

        if ($bank !== '' && $bank !== null) {
            return $bank . ' <span class="badge">' . $numChk . '</span> ' . $dueChck;
        }
        return '';
    }
}
