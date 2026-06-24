<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * VPaymentService — pagos electrónicos del POS (Slice 15, cluster ENCOM→Punto).
 *
 * Port FIEL (verbatim) de panel/API/add_vpayment.php — el endpoint canónico al que los
 * handlers ePOSAddCardTransaction / cajaPOSAddCardAndQrTransaction / PixAddTransaction de
 * app/action.php (L629/L666/L704) hacían proxy curl (roto en dev). Es un PATH DE DINERO:
 * el objetivo es paridad EXACTA con el canónico, no "mejorarlo".
 *
 * ⚠️ eposData (rates de comisión) vive en company.config (jsonb). El canónico hace
 * json_decode($company['eposData']); como _flattenJsonb ya entrega la key decodificada,
 * el comportamiento exacto depende de cómo se serializó eposData en config (no hay datos
 * de prueba para verificarlo). Este port replica el acceso del canónico + guards `?? 0`
 * que coinciden con el outcome null→0 → mismo comportamiento que producción.
 *
 * ⚠️ El branch especial COMPANY_ID === 4456 (auto-unblock de un company al pagar) del
 * canónico está OMITIDO: comparaba un int contra el companyId que hoy es UUID → era dead
 * code (nunca matcheaba) y su propio TODO pedía reemplazar 4456 por el UUID. Re-añadir con
 * el UUID real cuando se necesite.
 *
 * Gotchas PG: identificadores sin comillas, valores bindeados, scope companyId.
 */

final class VPaymentService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /** generateUID() del panel inlineado (no existe en app/includes): timestamp en ms. */
    private function generateUID(int $add = 0): string
    {
        return (string) (number_format(microtime(true) * 1000, 0, '.', '') + $add);
    }

    /**
     * Registra un pago electrónico (vPayment). Idempotente por authCode dentro del último mes.
     *
     * @param array $in  Campos del pago: UID, saleAmount, source, status, authCode, operationNo,
     *                   order, data, parentId, clientPayCommission, date, customer.
     * @return array  success: {success:msg, UID, ID, authCode, operationNo, payNow}
     *                duplicado/fallo: {error:'already_exists', success:false}
     */
    public function add(string $companyId, string $outletId, ?string $userId, array $in): array
    {
        global $db;

        $UID                 = $in['UID']                 ?? null;
        $saleAmount          = $in['saleAmount']          ?? 0;
        $parentId            = $in['parentId']            ?? null;
        $clientPayCommission = $in['clientPayCommission'] ?? null;
        $order               = $in['order']               ?? null;
        $authCode            = trim((string) ($in['authCode'] ?? ''));
        $operationNo         = $in['operationNo']         ?? null;
        $data                = $in['data']                ?? null;
        $source              = $in['source']              ?? null;
        $status              = !empty($in['status']) ? $in['status'] : 'PENDING';
        $date                = !empty($in['date'])   ? $in['date']   : TODAY;
        $customer            = $in['customer']            ?? null;
        $msg                 = 'Orden de pago generada';
        $payNow              = false;

        // Validaciones del canónico.
        if ($authCode === '' && (!empty($source) && $source !== 'PIX')) {
            return ['error' => 'auth_code_required', 'success' => false];
        }

        // Idempotencia: mismo authCode en el último mes → no duplicar.
        $existing = ncmExecute(
            'SELECT id FROM vPayments
              WHERE authCode = ? AND date(date) BETWEEN ? AND ? AND companyId = ?
              LIMIT 1',
            [
                $authCode,
                date('Y-m-d', strtotime('-1 month', strtotime($date))),
                date('Y-m-d', strtotime($date)),
                $companyId,
            ]
        );

        if ($existing) {
            // Ya existe → no insertar (igual que el canónico).
            return ['error' => 'already_exists', 'success' => false];
        }

        // Config de rates de comisión del company (eposData en config jsonb).
        $company  = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        $eposData = is_string($company['eposData'] ?? null)
            ? (json_decode($company['eposData'], true) ?: [])
            : (is_array($company['eposData'] ?? null) ? $company['eposData'] : []);
        $processorData = is_string($data) ? (json_decode($data, true) ?: []) : (is_array($data) ? $data : []);

        $isOnlinePayment = false;
        $rate            = $eposData['rate'] ?? 0;            // crédito (default)
        $tax             = $eposData['tax']  ?? 0;
        $depositDays     = 0;

        if (in_array($source, ['bancardQROnline', 'dinelcoVPOS', 'bancardAutoDebit', 'bancardVPOS'], true)) {
            $isOnlinePayment = true;
            $rate            = $eposData['rateOnline'] ?? 0;
        }

        if (!$isOnlinePayment) {
            if (in_array($source, ['bancardPOS', 'dinelcoPOS', 'bancardQR', 'PIX'], true)) {
                $acct = $processorData['account_type'] ?? null;
                if (in_array($acct, ['DC', 'TD'], true)) {       // débito
                    $rate = $eposData['rateDebit'] ?? 0;
                } elseif (in_array($acct, ['TC'], true)) {        // crédito
                    $rate = $eposData['rate'] ?? 0;
                } else {
                    $rate = 0;
                }
            }
        }

        // Cálculo de comisión + impuesto (verbatim del canónico).
        $comission = round(($rate / 100) * $saleAmount);
        $comTax    = round(($tax / 100) * $comission);
        if (!empty($clientPayCommission)) {
            // Divergencia INTENCIONAL del canónico: éste hacía `$rate / $tax` sin guarda →
            // DivisionByZeroError (fatal PHP 8) si tax=0, dejando la fila vPayments ya
            // insertada + request 500 (estado inconsistente). El guard evita el fatal.
            // tax=0 es misconfig (el IVA siempre es >0 para un company real).
            $comissionTmp = $tax ? round(($saleAmount / (($rate + ($rate / $tax)) + 100)), 2) : 0;
            $comission    = round(($comissionTmp * $rate), 2);
            $comTax       = round((($tax / 100) * $comission), 2);
        }

        // Fecha de payout (lógica de días hábiles del canónico).
        if (date('D') === 'Sat') {
            if ((int) date('H') > 11) {
                $paymentDate = date('Y-m-d 00:00:00', strtotime(TODAY . ' +' . $depositDays . ' weekdays'));
            } else {
                $depositDays += 2;
                $paymentDate = date('Y-m-d 00:00:00', strtotime(TODAY . ' +' . $depositDays . ' days'));
            }
        } else {
            if ((int) date('H') > 17) {
                $addDays = (date('D') === 'Fri') ? '+1 days' : '+1 weekdays';
                $paymentDate = date('Y-m-d 00:00:00', strtotime(TODAY . ' ' . $addDays));
            } else {
                $paymentDate = date('Y-m-d 00:00:00', strtotime(TODAY . ' +' . $depositDays . ' weekdays'));
            }
        }

        $payoutAmount = !empty($eposData['customerPays'])
            ? $saleAmount
            : $saleAmount - ($comission + $comTax);

        if ($depositDays < 1) {
            $payNow = true;
        }

        // Armar el registro vPayments.
        $record = [];
        if ($authCode !== '')      { $record['authCode']    = $authCode; }
        if ($operationNo)          { $record['operationNo'] = $operationNo; }
        if ($data)                 { $record['data']        = is_string($data) ? $data : json_encode($data); }
        if ($source)               { $record['source']      = $source; }
        if (!empty($customer))     { $record['customerId']  = $customer; }

        $record['date']         = $date;
        $record['payoutDate']   = $paymentDate;
        $record['amount']       = $saleAmount;
        $record['payoutAmount'] = $payoutAmount;
        $record['comission']    = $comission;
        $record['tax']          = $comTax;
        $record['orderNo']      = $order;
        $record['status']       = $status;
        $record['UID']          = $UID;
        $record['userId']       = $userId;
        $record['outletId']     = $outletId;
        $record['companyId']    = $companyId;
        if ($parentId) {
            $record['transactionId'] = $parentId;
        }

        $insert = $db->AutoExecute('vPayments', $record, 'INSERT');
        if ($insert === false) {
            return ['error' => 'already_exists', 'success' => false];
        }
        $invId = $db->Insert_ID();

        // Cascada: si el UID corresponde a una factura a CRÉDITO (type 3) no completada,
        // registrar el pago como transacción type 5 y marcar la factura como completa.
        $this->settleCreditInvoice($companyId, $UID, $saleAmount);

        return [
            'success'     => $msg,
            'UID'         => $UID,
            'ID'          => $invId,
            'authCode'    => $authCode,
            'operationNo' => $operationNo,
            'payNow'      => $payNow,
        ];
    }

    /**
     * Verifica un authCode aprobado (port de get_vpayment_verification.php). Lectura pura.
     * (El handler /app verifyQRPaymentCode estaba muerto, pero el método queda disponible.)
     */
    public function verify(string $companyId, string $code): ?string
    {
        $row = ncmExecute(
            "SELECT authCode FROM vPayments
              WHERE authCode = ? AND companyId = ? AND status = 'APPROVED'
              LIMIT 1",
            [$code, $companyId]
        );
        return $row['authCode'] ?? null;
    }

    /**
     * Si el vPayment salda una factura a crédito (type 3) pendiente, crea el pago (type 5)
     * y marca la factura como completa. Porta el bloque de cascada del canónico.
     * transactionPaymentType es columna real (text); no toca meta/transactionDetails.
     */
    private function settleCreditInvoice(string $companyId, ?string $UID, $saleAmount): void
    {
        global $db;
        if (!$UID) {
            return;
        }

        $invoice = ncmExecute(
            'SELECT * FROM transaction WHERE transactionUID = ? AND companyId = ? LIMIT 1',
            [$UID, $companyId]
        );

        if (!$invoice || (int) ($invoice['transactionType'] ?? 0) !== 3 || (int) ($invoice['transactionComplete'] ?? 0) === 1) {
            return; // no es factura a crédito pendiente
        }

        $tPay = [
            'transactionDate'        => TODAY,
            'transactionTotal'       => $saleAmount,
            'transactionType'        => 5,
            'transactionParentId'    => $invoice['transactionId'],
            'transactionComplete'    => 1,
            'transactionStatus'      => 1,
            'transactionPaymentType' => json_encode([['type' => 'epos', 'name' => 'ePOS', 'total' => $saleAmount]]),
            'transactionUID'         => $this->generateUID(),
            // getNextDocNumber en /app es 4-arg ($number,$in,$company,$register) — distinto
            // del panel (3-arg) que usaba el canónico. El bootstrap de /api carga el de /app.
            'invoiceNo'              => getNextDocNumber(0, 5, $companyId, $invoice['registerId']),
            'timestamp'              => time(),
            'customerId'             => $invoice['customerId'],
            'registerId'             => $invoice['registerId'],
            'userId'                 => $invoice['userId'],
            'responsibleId'          => $invoice['responsibleId'],
            'outletId'               => $invoice['outletId'],
            'companyId'              => $invoice['companyId'],
        ];

        $insert = $db->AutoExecute('transaction', $tPay, 'INSERT');
        if ($insert !== false) {
            $db->Execute(
                'UPDATE transaction SET transactionComplete = TRUE WHERE transactionId = ? AND companyId = ?',
                [$invoice['transactionId'], $companyId]
            );
        }
    }

    // =========================================================================
    // Slice 42 — ePOS read-ops (strangler de load.php?load=ePOSPending|verifyTransactionEPOS)
    // =========================================================================

    /**
     * Pagos ePOS pendientes (sin UID asignado) del tenant.
     *
     * Paridad con load.php?load=ePOSPending: proxy a panel/API/get_vpayments con
     * company_id y api_key, luego filtraba !validity($value['UID']). Acá query directa.
     * El legacy devolvía `{success: [...]}` — el shape de cada item se preserva al máximo
     * (mismas columnas que panel/API/get_vpayments devolvía). El front itera `data.success`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPending(string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT ID, date, payoutDate, payoutAmount, depositedDate, comission, tax,
                    orderNo, operationNo, authCode, status, amount, UID, method, source, outletId
               FROM vPayments
              WHERE companyId = ?
                AND (UID IS NULL OR UID = '')
              ORDER BY date DESC
              LIMIT 200",
            [$companyId],
            false,
            true
        );

        $out = [];
        if ($rs) {
            while (!$rs->EOF) {
                $f     = $rs->fields;
                $out[] = [
                    'ID'            => enc($f['ID']),
                    'date'          => $f['date'],
                    'payoutDate'    => $f['payoutDate']    ?? null,
                    'payoutAmount'  => $f['payoutAmount']  ?? null,
                    'depositedDate' => $f['depositedDate'] ?? null,
                    'comission'     => $f['comission']     ?? null,
                    'tax'           => $f['tax']           ?? null,
                    'orderNo'       => $f['orderNo']       ?? null,
                    'operationNo'   => $f['operationNo']   ?? null,
                    'authCode'      => $f['authCode']      ?? null,
                    'status'        => $f['status']        ?? null,
                    'amount'        => $f['amount']        ?? null,
                    'UID'           => $f['UID']            ?? null,
                    'method'        => $f['method']        ?? null,
                    'source'        => $f['source']        ?? null,
                    'outletId'      => enc($f['outletId'] ?? ''),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * Busca un pago ePOS por UID (usado para verificar resultado de una operación).
     *
     * Paridad con load.php?load=verifyTransactionEPOS: proxy a panel/API/get_vpayments
     * con `?UID=` — retornaba `{success: <resultado>}`. Acá query directa.
     * El front chequea `result.success` como truthy.
     *
     * @return array|null null si no se encontró.
     */
    public function getByUID(string $companyId, string $uid): ?array
    {
        $row = ncmExecute(
            "SELECT ID, date, orderNo, operationNo, authCode, status, amount, UID, method
               FROM vPayments
              WHERE UID = ? AND companyId = ?
              LIMIT 1",
            [$uid, $companyId]
        );
        if (!$row) {
            return null;
        }
        return [
            'ID'          => enc($row['ID']),
            'date'        => $row['date'],
            'orderNo'     => $row['orderNo']     ?? null,
            'operationNo' => $row['operationNo'] ?? null,
            'authCode'    => $row['authCode']    ?? null,
            'status'      => $row['status']      ?? null,
            'amount'      => $row['amount']      ?? null,
            'UID'         => $row['UID']          ?? null,
            'method'      => $row['method']      ?? null,
        ];
    }
}
