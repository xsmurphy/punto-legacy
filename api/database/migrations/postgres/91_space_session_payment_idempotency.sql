-- 91_space_session_payment_idempotency.sql
-- Un `transactionid` liquida UNA sola vez.
--
-- Sin esto, un reintento del cliente (timeout que en realidad llegó al
-- server, doble tap del cajero) inserta un segundo renglón en el ledger y el
-- pago se cuenta DOS VECES contra el saldo de la mesa. `kind='items'` ya
-- estaba cubierto por el CAS sobre `pos_order_item.settledpaymentid`, pero
-- 'amount' y 'share' no tenían ninguna defensa.
--
-- `SpaceSettlementService::registerPayment` ya resuelve el reintento como
-- no-op idempotente dentro del lock de la sesión; este índice es el respaldo
-- ESTRUCTURAL para cualquier camino futuro que no pase por ahí.
--
-- Parcial sobre transactionid NOT NULL por si alguna vez se registra un
-- movimiento sin transacción asociada.
CREATE UNIQUE INDEX IF NOT EXISTS uq_space_session_payment_transaction
    ON space_session_payment (companyid, transactionid)
 WHERE transactionid IS NOT NULL;
