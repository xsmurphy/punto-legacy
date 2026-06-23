<?php
declare(strict_types=1);
namespace Punto\Api\Services;

final class CreditPaymentService
{
    private function generateUID(int $add = 0): string
    {
        return (string) (number_format(microtime(true) * 1000, 0, '.', '') + $add);
    }

    public function create(
        string $companyId,
        string $userId,
        string $parentTransactionId,
        float  $amount,
        string $paymentMethodKey,
        string $paymentMethodName,
        string $registerId,
        ?string $note = null
    ): array {
        global $db;

        $parent = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$parentTransactionId, $companyId]
        );
        if (!$parent) {
            apiError('Transacción no encontrada', 404);
        }

        if ((string)($parent['transactionType'] ?? '') !== '3') {
            apiError('Solo se puede pagar facturas a crédito', 422);
        }

        if ((int)($parent['transactionComplete'] ?? 0) === 1) {
            apiError('Factura ya saldada', 422);
        }

        $total   = (float)($parent['transactionTotal'] ?? 0) - (float)($parent['transactionDiscount'] ?? 0);
        $paidRow = ncmExecute(
            "SELECT COALESCE(SUM(transactionTotal), 0) AS paid FROM transaction WHERE transactionParentId = ? AND transactionType = 5 AND companyId = ?",
            [$parentTransactionId, $companyId]
        );
        $paid = (float)($paidRow['paid'] ?? 0);
        $debt = max(0.0, $total - $paid);

        if ($amount <= 0 || $amount > $debt + 0.001) {
            apiError('Monto inválido', 422);
        }

        $uid = $this->generateUID();

        $db->StartTrans();

        $tPay = [
            'transactionDate'        => TODAY,
            'transactionTotal'       => $amount,
            'transactionType'        => 5,
            'transactionParentId'    => $parentTransactionId,
            'transactionComplete'    => 1,
            'transactionStatus'      => 1,
            'transactionPaymentType' => json_encode([['type' => $paymentMethodKey, 'name' => $paymentMethodName, 'total' => $amount]]),
            'transactionUID'         => $uid,
            'invoiceNo'              => getNextDocNumber(0, 5, $companyId, $parent['registerId']),
            'timestamp'              => time(),
            'customerId'             => $parent['customerId'],
            'registerId'             => $parent['registerId'],
            'userId'                 => $userId,
            'responsibleId'          => $parent['responsibleId'],
            'outletId'               => $parent['outletId'],
            'companyId'              => $companyId,
        ];
        if ($note !== null && $note !== '') {
            $tPay['transactionNote'] = $note;
        }

        $ok = $db->AutoExecute('transaction', $tPay, 'INSERT');
        if (!$ok) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('Error al registrar pago', 500);
        }

        $newId         = (string)$db->Insert_ID();
        $newPaid       = $paid + $amount;
        $debtRemaining = max(0.0, $debt - $amount);
        $parentComplete = false;

        if (round($debtRemaining, 4) <= 0) {
            $db->Execute(
                'UPDATE transaction SET transactionComplete = 1 WHERE transactionId = ? AND companyId = ?',
                [$parentTransactionId, $companyId]
            );
            $parentComplete = true;
        }

        $db->CompleteTrans();

        try {
            realtimePublish('transaction', 'update', null);
        } catch (\Throwable $e) {
            // best-effort
        }

        return [
            'id'             => $newId,
            'parentComplete' => $parentComplete,
            'paid'           => $newPaid,
            'debtRemaining'  => $debtRemaining,
        ];
    }
}
