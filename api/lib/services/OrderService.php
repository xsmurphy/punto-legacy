<?php
/**
 * OrderService — aceptación y asignación de órdenes del POS (Slice 7).
 *
 * Lógica portada de app/action.php:
 *   acceptOrder    (L1286) — cambia status a 2 ("aceptada")
 *   setUserToOrder (L1354) — asigna userId y actualiza transactionDetails
 *
 * Los side-effects (sendPush, sendWS, sendEmails, sendSMS) quedan en el caller
 * (api/v1/orders.php) para mantener el servicio puro de BD.
 *
 * Bug corregido en acceptOrder: el legacy enviaba enc($id) al WS pero $id no estaba
 * definido en ese scope → el WS recibía null. Se usa $transactionId directamente.
 *
 * Bugs PG corregidos:
 *   - COMPANY_ID, OUTLET_ID interpolados sin comillas en WHERE → bindeados.
 *   - transactionDetails leído y reescrito con JSON válido (no asume tipo TEXT).
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
                SET "transactionStatus" = 2, "updated_at" = NOW()
              WHERE "transactionId" = ? AND "companyId" = ?',
            [$transactionId, $companyId]
        );

        if ($res === false) {
            return ['ok' => false, 'customerId' => null, 'invoiceNo' => null];
        }

        // Leer datos del cliente e invoiceNo para los side-effects del caller.
        $row = ncmExecute(
            'SELECT "customerId", "invoiceNo"
               FROM transaction
              WHERE "transactionId" = ? AND "companyId" = ?
              LIMIT 1',
            [$transactionId, $companyId]
        );

        return [
            'ok'         => true,
            'customerId' => $row['customerId'] ?? null,
            'invoiceNo'  => $row['invoiceNo']  ?? null,
        ];
    }

    /**
     * Asigna un usuario (mozo/responsable) a una orden (transactionType=12).
     * Además actualiza el campo ['user'] dentro de cada item de transactionDetails.
     *
     * @return array{ok:bool, invoiceNo:string|null}
     *   invoiceNo se retorna para el mensaje del push.
     */
    public function assignUser(string $transactionId, string $companyId, string $userId): array
    {
        global $db;

        // 1. Actualizar userId en la orden.
        $res = $db->Execute(
            'UPDATE transaction
                SET "userId" = ?
              WHERE "transactionId" = ? AND "transactionType" = 12 AND "companyId" = ?',
            [$userId, $transactionId, $companyId]
        );

        if ($res === false) {
            return ['ok' => false, 'invoiceNo' => null];
        }

        // 2. Leer transactionDetails e invoiceNo.
        $row = ncmExecute(
            'SELECT "transactionDetails", "invoiceNo"
               FROM transaction
              WHERE "transactionId" = ? AND "companyId" = ?
              LIMIT 1',
            [$transactionId, $companyId]
        );

        if (!$row) {
            return ['ok' => true, 'invoiceNo' => null]; // UPDATE OK pero sin fila (raro)
        }

        // 3. Actualizar ['user'] en cada item de transactionDetails y persistir.
        $details = json_decode((string) ($row['transactionDetails'] ?? '[]'), true);
        if (is_array($details) && !empty($details)) {
            foreach ($details as $k => $_) {
                $details[$k]['user'] = $userId; // enc() = identity, UUID directo
            }
            $db->Execute(
                'UPDATE transaction
                    SET "transactionDetails" = ?
                  WHERE "transactionId" = ? AND "transactionType" = 12 AND "companyId" = ?',
                [json_encode($details), $transactionId, $companyId]
            );
        }

        return ['ok' => true, 'invoiceNo' => $row['invoiceNo'] ?? null];
    }
}
