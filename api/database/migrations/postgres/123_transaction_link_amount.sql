-- 123_transaction_link_amount.sql
-- Un RECIBO (pago de crédito, transactionType=5) hoy solo puede cancelar UNA
-- factura porque `transaction_link` (mig 115) es un vínculo BINARIO a
-- propósito: "A deriva de B" y nada más, sin monto — los montos se inferían
-- de los totales de cada documento. Si un cliente paga 3 facturas con una
-- sola transferencia, el operador tenía que cargar 3 recibos y quemar 3
-- números correlativos para un solo documento real (incorrecto fiscalmente
-- en PY). Esta columna permite UN recibo repartido en N vínculos, uno por
-- factura, cada uno con su monto imputado.
--
-- Semántica de `amount` (NULLABLE A PROPÓSITO, sin backfill):
--   - NULL  → el vínculo vale el TOTAL del documento derivado (comportamiento
--             histórico). Todos los links existentes a la fecha de este mig
--             quedan en NULL — ya significa lo correcto, no hace falta
--             backfillearlos con el total copiado.
--   - valor → el monto imputado a ESE vínculo puntual (ej. de un recibo de
--             300 repartido en 2 facturas, un link queda en 100 y el otro en
--             200 — la suma de vínculos de ese recibo da su transactionTotal,
--             pero cada factura sólo cuenta lo suyo).
-- `TransactionLinkService::sumDerivedAmounts()` es la superficie única que
-- lee esta columna: `SUM(COALESCE(tl.amount, t.transactionTotal))`.
--
-- Convenciones de deploy (precedente migs 74/77/120): nada de operador
-- `?`/`?|`/`?&` de jsonb (no aplica acá, no hay jsonb) — el wrapper PDO lo
-- reescribe a placeholder y rompe el boot. Todo idempotente y re-corrible.

BEGIN;

ALTER TABLE transaction_link ADD COLUMN IF NOT EXISTS amount numeric(15,4);

COMMIT;
