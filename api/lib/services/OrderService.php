<?php
/**
 * OrderService — aceptación de órdenes del POS (Slice 7).
 *
 * Lógica portada de app/action.php:
 *   acceptOrder (L1286) — cambia status a 2 ("aceptada")
 *
 * Los side-effects (sendPush, sendWS, sendEmails, sendSMS) quedan en el caller
 * (api/v1/orders.php) para mantener el servicio puro de BD.
 *
 * Bug corregido en acceptOrder: el legacy enviaba enc($id) al WS pero $id no estaba
 * definido en ese scope → el WS recibía null. Se usa $transactionId directamente.
 *
 * NOTA — setUserToOrder (action.php L1354) NO está aquí: escribe transactionDetails,
 * que en PG vive dentro de la columna `meta` (jsonb). El write-path legacy (AutoExecute)
 * no mapea columnas virtuales → meta, así que ese handler está roto post-migración.
 * Se difiere al slice dedicado de meta-JSONB (junto a removeItemfromOrder,
 * processOrderItems*, moveOrderItems, updateSchedule, scheduleSession).
 *
 * Bugs PG corregidos:
 *   - identificadores SIN comillas (PG pliega a lowercase; las columnas reales son lowercase).
 *   - COMPANY_ID interpolado sin comillas en WHERE → bindeado.
 */

class OrderService
{
    /**
     * Acepta una orden: transactionStatus → 2.
     *
     * @return array{ok:bool, customerId:string|null, invoiceNo:string|null}
     *   Si ok=true, retorna customerId e invoiceNo para que el caller envíe
     *   push/WS/email/SMS al cliente.
     */
    public function accept(string $transactionId, string $companyId): array
    {
        global $db;

        $res = $db->Execute(
            'UPDATE transaction
                SET transactionStatus = 2, updated_at = NOW()
              WHERE transactionId = ? AND companyId = ?',
            [$transactionId, $companyId]
        );

        if ($res === false) {
            return ['ok' => false, 'customerId' => null, 'invoiceNo' => null];
        }

        // Leer datos del cliente e invoiceNo para los side-effects del caller.
        // Si la fila no existe (transactionId foráneo o de otro tenant), retorna ok:false
        // para evitar disparar notificaciones sobre datos inexistentes.
        $row = ncmExecute(
            'SELECT customerId, invoiceNo
               FROM transaction
              WHERE transactionId = ? AND companyId = ?
              LIMIT 1',
            [$transactionId, $companyId]
        );

        if (!$row) {
            return ['ok' => false, 'customerId' => null, 'invoiceNo' => null];
        }

        return [
            'ok'         => true,
            'customerId' => $row['customerId'] ?? null,
            'invoiceNo'  => $row['invoiceNo']  ?? null,
        ];
    }

    /**
     * Transfiere una orden a otro outlet (transferOrderToOutlet, action.php L575).
     * Sólo toca la columna real `outletId` (sin transactionDetails).
     *
     * Valida que la orden y el outlet destino existan y pertenezcan al tenant antes
     * de mover, para no dejar la orden en un outlet inexistente.
     *
     * @return array{ok:bool, reason:string|null}
     *   reason ∈ {null, 'order_not_found', 'outlet_not_found', 'update_failed'}
     */
    public function transferToOutlet(string $orderId, string $targetOutletId, string $companyId): array
    {
        global $db;

        $order = ncmExecute(
            'SELECT transactionId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$orderId, $companyId]
        );
        if (!$order) {
            return ['ok' => false, 'reason' => 'order_not_found'];
        }

        $outlet = ncmExecute(
            'SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
            [$targetOutletId, $companyId]
        );
        if (!$outlet) {
            return ['ok' => false, 'reason' => 'outlet_not_found'];
        }

        $res = $db->Execute(
            'UPDATE transaction SET outletId = ? WHERE transactionId = ? AND companyId = ?',
            [$targetOutletId, $orderId, $companyId]
        );
        if ($res === false) {
            return ['ok' => false, 'reason' => 'update_failed'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
