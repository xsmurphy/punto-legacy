<?php
declare(strict_types=1);
namespace Punto\Api\Services;

/**
 * CreditPaymentService — registra cobros parciales/totales de crédito (type=5).
 *
 * Replica la lógica de VPaymentService::settleCreditInvoice pero iniciado
 * manualmente por el operador desde el POS o el panel.
 *
 * Invariantes de seguridad:
 *   - Todas las queries scopeadas por companyId (multi-tenant).
 *   - La fila padre se bloquea con SELECT … FOR UPDATE DENTRO de la TX
 *     para evitar doble-cobro concurrente.
 *   - paymentMethodName se resuelve server-side; el caller solo envía la key.
 */
final class CreditPaymentService
{
    private function generateUID(): string
    {
        return (string) (number_format(microtime(true) * 1000, 0, '.', ''));
    }

    /**
     * Registra un pago de crédito.
     *
     * @param string  $companyId             Tenant (siempre del JWT, nunca del body).
     * @param string  $userId                Operador que cobra (del JWT).
     * @param string  $parentTransactionId   UUID de la factura crédito (type=3).
     * @param float   $amount                Monto a cobrar (validado > 0 y <= deuda).
     * @param string  $paymentMethodKey      Key del método de pago (ej. "efectivo").
     * @return array  {id, parentComplete, paid, debtRemaining}
     */
    public function create(
        string  $companyId,
        string  $userId,
        string  $parentTransactionId,
        float   $amount,
        string  $paymentMethodKey,
        ?string $note = null
    ): array {
        global $db;

        // Pre-validación fuera de TX: verificar existencia y tipo básico.
        // La re-validación definitiva ocurre dentro de la TX con FOR UPDATE.
        $preCheck = ncmExecute(
            'SELECT transactionId, transactionType FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$parentTransactionId, $companyId]
        );
        if (!$preCheck) {
            apiError('Transacción no encontrada', 404);
        }
        if ((string)($preCheck['transactionType'] ?? '') !== '3') {
            apiError('Solo se puede pagar facturas a crédito', 422);
        }

        // Resolver nombre del método de pago server-side (nunca confiar en el body).
        $paymentMethodName = getPaymentMethodName($paymentMethodKey);

        $db->StartTrans();

        // Dentro de la TX: bloquear la fila padre para serializar concurrencia.
        $parent = $db->GetRow(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1 FOR UPDATE',
            [$parentTransactionId, $companyId]
        );
        if (!$parent) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('Transacción no encontrada', 404);
        }

        if ((int)($parent['transactionComplete'] ?? 0) === 1) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('Factura ya saldada', 422);
        }

        // Computar deuda dentro de la TX (después del lock).
        $total   = (float)($parent['transactionTotal'] ?? 0) - (float)($parent['transactionDiscount'] ?? 0);
        $paidRow = $db->GetRow(
            "SELECT COALESCE(SUM(transactionTotal), 0) AS paid FROM transaction WHERE transactionParentId = ? AND transactionType = 5 AND companyId = ?",
            [$parentTransactionId, $companyId]
        );
        $paid = (float)($paidRow['paid'] ?? 0);
        $debt = max(0.0, $total - $paid);

        if ($amount <= 0 || round($amount, 4) > round($debt, 4) + 0.001) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('Monto inválido o supera la deuda actual', 422);
        }

        // registerId siempre del parent (no del operador actual).
        $parentRegisterId = (string)($parent['registerId'] ?? '');

        // Sesión de caja: drawerId de la caja ABIERTA del register del parent.
        // null si no hay caja abierta → el pago se registra igual (recuperable
        // por el fallback de fecha del resumen, mig 70). Helper compartido con
        // SaleService — el wrapper (ncmExecute) es obligatorio, sin DB directa.
        $openDrawerId = DrawerService::resolveOpenDrawerId($parentRegisterId, $companyId);

        $tPay = [
            'transactionDate'        => TODAY,
            'transactionTotal'       => $amount,
            'transactionType'        => 5,
            'transactionParentId'    => $parentTransactionId,
            'transactionComplete'    => 1,
            'transactionStatus'      => 1,
            'transactionPaymentType' => json_encode([[
                'type'  => $paymentMethodKey,
                'name'  => $paymentMethodName,
                'total' => $amount,
            ]]),
            'transactionUID'         => $this->generateUID(),
            'invoiceNo'              => getNextDocNumber(0, 5, $companyId, $parentRegisterId),
            'timestamp'              => time(),
            'customerId'             => $parent['customerId'],
            'registerId'             => $parentRegisterId,
            'userId'                 => $userId,
            'responsibleId'          => $parent['responsibleId'],
            'outletId'               => $parent['outletId'],
            'companyId'              => $companyId,
            'drawerId'               => $openDrawerId,
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

        $newId          = (string)$db->Insert_ID();
        $newPaid        = $paid + $amount;
        $debtRemaining  = max(0.0, $debt - $amount);
        $parentComplete = false;

        if (round($debtRemaining, 4) <= 0) {
            $db->Execute(
                'UPDATE transaction SET transactionComplete = TRUE WHERE transactionId = ? AND companyId = ?',
                [$parentTransactionId, $companyId]
            );
            $parentComplete = true;
        }

        $db->CompleteTrans();

        // Notificación realtime best-effort (post-commit).
        try {
            realtimePublish('transaction', 'update', null);
        } catch (\Throwable $e) {
            // Ignorar — no crítico.
        }

        return [
            'id'             => $newId,
            'parentComplete' => $parentComplete,
            'paid'           => $newPaid,
            'debtRemaining'  => $debtRemaining,
        ];
    }
}
