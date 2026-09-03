<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

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
        $sql = "SELECT transactionId, transactionPaymentType, transactionTotal,
                       invoiceNo, outletId, customerId, registerId
                FROM transaction
                WHERE transactionType IN (0, 5)
                AND " . SaleFilters::notVoidedSql() . "
                AND transactionDate BETWEEN ? AND ?" . $roc;

        $res = ncmExecute($sql, [$from, $to], false, true);

        $detail      = [];
        $group       = [];
        $prefixCache = [];

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

                $regId = $f['registerId'] ?? '';
                if (!array_key_exists($regId, $prefixCache)) {
                    $reg = $regId
                        ? ncmExecute('SELECT registerInvoicePrefix FROM register WHERE registerId = ? AND companyId = ?', [$regId, $companyId], true)
                        : null;
                    $prefixCache[$regId] = $reg['registerInvoicePrefix'] ?? '';
                }
                $invoicePrefix = $prefixCache[$regId];

                foreach ($methods as $meth) {
                    $extra = ($meth['type'] ?? '') === 'check'
                        ? $this->csvToBankData($meth['extra'] ?? '')
                        : ($meth['extra'] ?? '');

                    $detail[] = [
                        'transactionId' => (string) $f['transactionId'],
                        'invoiceNo'     => $invoicePrefix . ($f['invoiceNo'] ?? ''),
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
