<?php
/**
 * OrderService — órdenes del POS (Slice 7 + cluster meta-JSONB).
 *
 * Lógica portada de app/action.php:
 *   acceptOrder    (L1286) — cambia status a 2 ("aceptada")
 *   transferOrderToOutlet (L575) — mueve la orden a otro outlet
 *   setUserToOrder (L1354) — asigna userId + actualiza ['user'] en transactionDetails (meta jsonb)
 *
 * Los side-effects (sendPush, sendWS, sendEmails, sendSMS) quedan en el caller
 * (api/v1/orders.php) para mantener el servicio puro de BD.
 *
 * Bug corregido en acceptOrder: el legacy enviaba enc($id) al WS pero $id no estaba
 * definido en ese scope → el WS recibía null. Se usa $transactionId directamente.
 *
 * Bugs PG corregidos:
 *   - identificadores SIN comillas (PG pliega a lowercase; las columnas reales son lowercase).
 *   - COMPANY_ID interpolado sin comillas en WHERE → bindeado.
 *   - transactionDetails se lee/escribe vía meta (jsonb) con los helpers txMeta* (§22.6).
 */

require_once __DIR__ . '/../meta_transaction.php';

class OrderService
{
    /**
     * ¿El cliente tiene órdenes abiertas en este outlet? (customerHasOrders, load.php L1634).
     * Orden = transaction type 12 con status != 4 (no finalizada). Sólo chequea existencia.
     */
    public function customerHasOpenOrders(string $companyId, string $outletId, string $customerId): bool
    {
        $row = ncmExecute(
            'SELECT transactionId FROM transaction
              WHERE companyId = ? AND outletId = ? AND transactionType = 12
                AND transactionStatus != 4 AND customerId = ?
              LIMIT 1',
            [$companyId, $outletId, $customerId]
        );
        return (bool) $row;
    }

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

    /**
     * Asigna un usuario (mozo/responsable) a una orden (type 12): setea la columna userId
     * y el campo ['user'] de cada item en transactionDetails (que vive en meta jsonb).
     *
     * @return array{ok:bool, invoiceNo:string|null}  invoiceNo para el mensaje del push.
     */
    public function assignUser(string $transactionId, string $companyId, string $userId): array
    {
        global $db;

        // Verificar que la orden exista (type 12) + obtener invoiceNo para el push.
        $row = ncmExecute(
            'SELECT invoiceNo FROM transaction
              WHERE transactionId = ? AND transactionType = 12 AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return ['ok' => false, 'invoiceNo' => null];
        }

        // 1. Columna userId.
        $db->Execute(
            'UPDATE transaction SET userId = ?
              WHERE transactionId = ? AND transactionType = 12 AND companyId = ?',
            [$userId, $transactionId, $companyId]
        );

        // 2. ['user'] en cada item de transactionDetails (meta jsonb), preservando otras keys.
        $details = txDetailsFromMeta(txMetaRead($transactionId, $companyId, 12));
        if (!empty($details)) {
            foreach ($details as $k => $_) {
                $details[$k]['user'] = $userId;
            }
            txDetailsWrite($transactionId, $companyId, $details, 12);
        }

        return ['ok' => true, 'invoiceNo' => $row['invoiceNo'] ?? null];
    }
}
