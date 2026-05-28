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
}
