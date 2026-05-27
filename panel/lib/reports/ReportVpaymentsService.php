<?php
/**
 * Dominio de Reportes — Pagos ePOS / vPayments (capa API, motor ERP).
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
 * Tenant: company_id (enc) enviado a la API externa; api_key del entorno.
 */
class ReportVpaymentsService
{
    public function general($from, $to)
    {
        $data = [
            'api_key'    => $this->apiKey(),
            'company_id' => enc(COMPANY_ID),
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
    private function apiKey()
    {
        if (defined('API_KEY')) {
            return API_KEY;
        }
        $row = ncmExecute("SELECT config->>'accountId' as accountid FROM company WHERE companyId = ?", [COMPANY_ID]);
        $accountId = $row ? (string) ($row['accountid'] ?? '') : '';
        return sha1($accountId);
    }
}
