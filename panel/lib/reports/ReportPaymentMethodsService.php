<?php
/**
 * Dominio de Reportes — Ventas por Medios de Pago (capa API, motor ERP).
 *
 * Devuelve datasets CRUDOS (números sin formatear, sin HTML): una lista DETALLE
 * (una fila por medio de pago de cada transacción, con datos ya resueltos: cliente,
 * sucursal, prefijo de factura, nombre del medio) y un RESUMEN agrupado por medio.
 * El formateo de montos vive en el BFF; el markup (tablas + chart) en el front.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2 y § BFF de 3 niveles.
 *
 * La lógica que antes vivía inline en panel/a_report_p_methods.php (action=generalTable,
 * que armaba HTML) se consolida acá devolviendo arrays.
 *
 * Tenant: filtro $roc (getROC()) derivado de COMPANY_ID del JWT — nunca de input.
 */
class ReportPaymentMethodsService
{
    /**
     * Dataset del reporte de medios de pago en un período.
     * @return array{detail: array, summary: array}
     */
    public function report($from, $to, $roc, $companyId)
    {
        $sql = "SELECT transactionId, transactionPaymentType, transactionTotal,
                       invoiceNo, outletId, customerId, registerId
                FROM transaction
                WHERE transactionType IN (0, 5)
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

                // Prefijo de factura del register (cacheado por register).
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
                        ? csvToBankData($meth['extra'] ?? '')
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

        // Resumen: agrupado por medio, ordenado por monto desc.
        usort($group, fn($a, $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));

        $summary = [];
        foreach ($group as $g) {
            $summary[] = [
                'type'  => $g['type'] ?? '',
                'name'  => iftn(getPaymentMethodName($g['type'] ?? '', true), getPaymentMethodName($g['type'] ?? '')),
                'price' => (float) ($g['price'] ?? 0),
            ];
        }

        return ['detail' => $detail, 'summary' => $summary];
    }
}
