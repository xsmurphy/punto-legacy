<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Pagos ePOS / vPayments (API compartida, motor ERP).
 *
 * UNA vista de lectura: general() — pagos electrónicos (ePOS / Bancard / Dinelco) del período.
 * Es un GATEWAY: los datos vienen de la API legacy `get_vpayments` (que a su vez consulta a los
 * proveedores de pago externos). La capa API es la única que habla con servicios externos.
 *
 * Devuelve datos CRUDOS (registros + totales); el front formatea + arma tabla/donut/KPIs. REGLA RAÍZ 2.
 *
 * ⚠️ NO verificable con datos en dev (sin credenciales Bancard/Dinelco) → se verifica a nivel
 *    estructural (la cadena ejecuta, devuelve `success` vacío sin romper). En prod devuelve registros.
 *
 * Tenant: company_id (enc) enviado a la API externa; api_key del entorno (sha1 del accountId).
 *
 * Port FIEL de panel/lib/reports/ReportVpaymentsService.php (Fase 2 del desacople de /panel —
 * último reporte pendiente). Cambios respecto al original: namespace `Punto\Api\Reports`
 * (cae el prefijo `Report`, igual que ExpensesService y los otros 21 ya migrados), `final`,
 * `declare(strict_types=1)`.
 *
 * Sigue siendo proxy a `panel/API/get_vpayments.php` (legacy) vía HTTP — esa migración a un
 * endpoint nativo en /api se planifica en F5 (donde se inventarían y migran los 73 endpoints
 * legacy de panel/API/).
 *
 * Nota namespace: funciones globales (curlContents, enc, ncmExecute) y constantes
 * (API_URL, API_KEY) resuelven por fallback de PHP.
 */
final class VPaymentsService
{
    public function general($from, $to, $companyId)
    {
        $data = [
            'api_key'    => $this->apiKey($companyId),
            'company_id' => enc($companyId),
            'from'       => $from,
            'to'         => $to,
            'cache'      => 60,
        ];

        $raw = curlContents(API_URL . '/get_vpayments', 'POST', $data);
        $res = json_decode($raw, true);

        $rows = [];
        $totalSold = 0.0; $totalDeposited = 0.0; $totalApproved = 0.0; $totalCount = 0;

        if (!empty($res['success']) && is_array($res)) {
            foreach ($res['success'] as $f) {
                if (($f['status'] ?? '') === 'DENIED') {
                    continue;
                }
                $deposited = !empty($f['deposited']);
                $payoutAmount = (float) ($f['payoutAmount'] ?? 0);
                $amount = (float) ($f['amount'] ?? 0);
                if ($deposited) { $totalDeposited += $payoutAmount; }
                $totalSold     += $amount;
                $totalApproved += $payoutAmount;
                $totalCount++;

                $pdata = $f['data'] ?? [];
                $rows[] = [
                    'status'        => (string) ($f['status'] ?? ''),
                    'deposited'     => $deposited,
                    'date'          => (string) ($f['date'] ?? ''),
                    'payoutDate'    => (string) ($f['payoutDate'] ?? ''),
                    'depositedDate' => (string) ($f['depositedDate'] ?? ''),
                    'authCode'      => (string) ($f['authCode'] ?? ''),
                    'operationNo'   => (string) ($f['operationNo'] ?? ''),
                    'source'        => (string) ($f['source'] ?? ''),
                    'accountType'   => (string) ($pdata['account_type'] ?? ''),
                    'brand'         => (string) ($pdata['brand'] ?? ''),
                    'outletName'    => (string) ($f['outletName'] ?? ''),
                    'amount'        => $amount,
                    'payoutAmount'  => $payoutAmount,
                    'eUID'          => (string) ($f['eUID'] ?? ''),
                ];
            }
        }

        return [
            'rows' => $rows,
            'kpi'  => [
                'sold'           => $totalSold,
                'deposited'      => $totalDeposited,
                'pendingDeposit' => $totalApproved - $totalDeposited,
                'count'          => $totalCount,
            ],
        ];
    }

    /**
     * api_key del gateway: sha1(company.config.accountId) — igual que config.php (define API_KEY).
     * Se computa acá porque el middleware de la API v1 carga simple.config.php, no config.php, así
     * que la constante API_KEY no existe en este contexto.
     */
    private function apiKey($companyId)
    {
        if (defined('API_KEY')) {
            return API_KEY;
        }
        $row = ncmExecute("SELECT config->>'accountId' as accountid FROM company WHERE companyId = ?", [$companyId]);
        $accountId = $row ? (string) ($row['accountid'] ?? '') : '';
        return sha1($accountId);
    }
}
