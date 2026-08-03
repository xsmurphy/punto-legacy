-- 115_transaction_link.sql
-- Reemplaza `transaction.transactionparentid` (self-FK sobrecargado con 5
-- semánticas distintas) y `pos_order.saletransactionid` (puntero suelto sin
-- tabla puente) por dos tablas puente explícitas y NO polimórficas — FK
-- reales en ambas, una por cada relación (context/35-transaction-link.md).
--
-- Vínculo BINARIO por decisión del owner: la fila dice que A deriva de B y
-- nada más, sin columna `amount` — los montos se siguen infiriendo de los
-- totales de cada documento.
--
-- Todo lowercase sin comillas (mismo criterio que mig 79/108/114).

BEGIN;

-- ============================================================
-- 1. CREATE TABLE
-- ============================================================

-- transaction_link — transacción ↔ transacción. `originid` es el documento
-- del que DERIVA (cotización, factura a crédito, venta original, compra a
-- crédito); `derivedid` es el documento que NACE (factura, pago, nota de
-- crédito). FK reales a transaction(transactionId) — confirmado como target
-- válido de FK: otras tablas (pack_soldpackage mig 31, saas_invoice_sale mig
-- 114) ya referencian transaction(transactionId) sin comillas.
CREATE TABLE IF NOT EXISTS transaction_link (
  linkid     uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid  uuid        NOT NULL,
  originid   uuid        NOT NULL REFERENCES transaction(transactionId),
  derivedid  uuid        NOT NULL REFERENCES transaction(transactionId),
  kind       text        NOT NULL CHECK (kind IN (
                            'quote_to_sale', 'credit_payment', 'purchase_payment',
                            'return', 'package_session', 'table_merge'
                          )),
  created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT chk_transaction_link_no_self CHECK (originid <> derivedid),
  CONSTRAINT uq_transaction_link UNIQUE (companyid, originid, derivedid, kind)
);

CREATE INDEX IF NOT EXISTS idx_transaction_link_origin  ON transaction_link (companyid, originid);
CREATE INDEX IF NOT EXISTS idx_transaction_link_derived ON transaction_link (companyid, derivedid);

-- order_transaction_link — pos_order ↔ transaction. Resuelve "todas las
-- órdenes cobradas con esta factura" (varias órdenes, una sola venta —
-- caso explícito del owner) vía el índice (companyid, transactionid).
CREATE TABLE IF NOT EXISTS order_transaction_link (
  companyid     uuid        NOT NULL,
  orderid       uuid        NOT NULL REFERENCES pos_order(orderid) ON DELETE CASCADE,
  transactionid uuid        NOT NULL REFERENCES transaction(transactionId),
  kind          text        NOT NULL DEFAULT 'order_billed' CHECK (kind IN ('order_billed')),
  created_at    timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (orderid, transactionid)
);

CREATE INDEX IF NOT EXISTS idx_order_transaction_link_tx ON order_transaction_link (companyid, transactionid);

-- ============================================================
-- 2. BACKFILL — explícito por semántica, cruzando transactiontype de
--    origen y derivado (context/35 tabla de `kind`). El único descarte
--    posible es el parent HUÉRFANO (uuid que no resuelve a ninguna fila del
--    mismo company): se cuenta aparte y no aborta. No hay descarte por
--    "formato inválido" — la columna es uuid, no text.
-- ============================================================

DO $$
DECLARE
  dirty_count     integer;
  total_parented  integer;
  inserted_count  integer;
  orders_total    integer;
  orders_inserted integer;
  uncovered_pairs text;
BEGIN
  -- `transactionparentid` es de tipo UUID (no text): un valor no-uuid NO puede
  -- existir en la columna, así que el chequeo de "dato sucio legacy de mesas"
  -- que había acá era imposible por construcción — y además `uuid !~* text` ni
  -- siquiera tiene operador en PG, que es como se cayó el primer deploy.
  --
  -- El descarte REAL es el huérfano: un parent uuid bien formado que apunta a
  -- una transacción inexistente o de otro company (nunca hubo FK que lo
  -- impidiera). Esas filas no se pueden vincular y se cuentan aparte, sin
  -- hacer fallar la verificación del paso 3.
  SELECT count(*) INTO dirty_count
    FROM transaction d
   WHERE d.transactionparentid IS NOT NULL
     AND NOT EXISTS (
           SELECT 1 FROM transaction o
            WHERE o.transactionid = d.transactionparentid
              AND o.companyid     = d.companyid
         );

  RAISE NOTICE 'transaction_link backfill: % filas con parent huérfano descartadas', dirty_count;

  -- return: derivado 6 (nota de crédito) → origen 0/3 (venta contado/crédito)
  INSERT INTO transaction_link (companyid, originid, derivedid, kind)
  SELECT d.companyid, o.transactionid, d.transactionid, 'return'
    FROM transaction d
    JOIN transaction o
      ON o.transactionid = d.transactionparentid
     AND o.companyid = d.companyid
   WHERE d.transactiontype = 6
     AND o.transactiontype IN (0, 3)
     AND d.transactionparentid IS NOT NULL
  ON CONFLICT DO NOTHING;

  -- credit_payment: derivado 5 (pago) → origen 3 (venta a crédito)
  INSERT INTO transaction_link (companyid, originid, derivedid, kind)
  SELECT d.companyid, o.transactionid, d.transactionid, 'credit_payment'
    FROM transaction d
    JOIN transaction o
      ON o.transactionid = d.transactionparentid
     AND o.companyid = d.companyid
   WHERE d.transactiontype = 5
     AND o.transactiontype = 3
     AND d.transactionparentid IS NOT NULL
  ON CONFLICT DO NOTHING;

  -- purchase_payment: derivado 5 (pago) → origen 4 (compra a crédito)
  INSERT INTO transaction_link (companyid, originid, derivedid, kind)
  SELECT d.companyid, o.transactionid, d.transactionid, 'purchase_payment'
    FROM transaction d
    JOIN transaction o
      ON o.transactionid = d.transactionparentid
     AND o.companyid = d.companyid
   WHERE d.transactiontype = 5
     AND o.transactiontype = 4
     AND d.transactionparentid IS NOT NULL
  ON CONFLICT DO NOTHING;

  -- quote_to_sale: derivado 0/3 (venta) → origen 9/2 (cotización/guardado)
  INSERT INTO transaction_link (companyid, originid, derivedid, kind)
  SELECT d.companyid, o.transactionid, d.transactionid, 'quote_to_sale'
    FROM transaction d
    JOIN transaction o
      ON o.transactionid = d.transactionparentid
     AND o.companyid = d.companyid
   WHERE d.transactiontype IN (0, 3)
     AND o.transactiontype IN (9, 2)
     AND d.transactionparentid IS NOT NULL
  ON CONFLICT DO NOTHING;

  -- package_session: derivado 13 (cita/sesión de paquete) → origen 0/3 (venta)
  INSERT INTO transaction_link (companyid, originid, derivedid, kind)
  SELECT d.companyid, o.transactionid, d.transactionid, 'package_session'
    FROM transaction d
    JOIN transaction o
      ON o.transactionid = d.transactionparentid
     AND o.companyid = d.companyid
   WHERE d.transactiontype = 13
     AND o.transactiontype IN (0, 3)
     AND d.transactionparentid IS NOT NULL
  ON CONFLICT DO NOTHING;

  -- table_merge: derivado 11 (mesa legacy) → origen 11 (mesa legacy) — mesas
  -- unidas (TableService::joinTable). TableService está DEAD (Espacios lo
  -- reemplazó, sin referencias desde el frontend) pero el backfill igual
  -- preserva el historial existente por completitud.
  INSERT INTO transaction_link (companyid, originid, derivedid, kind)
  SELECT d.companyid, o.transactionid, d.transactionid, 'table_merge'
    FROM transaction d
    JOIN transaction o
      ON o.transactionid = d.transactionparentid
     AND o.companyid = d.companyid
   WHERE d.transactiontype = 11
     AND o.transactiontype = 11
     AND d.transactionparentid IS NOT NULL
  ON CONFLICT DO NOTHING;

  -- ==========================================================
  -- 3. VERIFICACIÓN (misma transacción) — total de filas con
  --    transactionparentid válido (uuid + fila padre existente en el mismo
  --    company) vs total insertado en transaction_link. Si no cuadran,
  --    ABORT — sin esto no se hace el DROP.
  -- ==========================================================

  SELECT count(*) INTO total_parented
    FROM transaction d
    JOIN transaction o
      ON o.transactionid = d.transactionparentid
     AND o.companyid = d.companyid
   WHERE d.transactionparentid IS NOT NULL;

  SELECT count(*) INTO inserted_count FROM transaction_link;

  RAISE NOTICE 'transaction_link backfill: % filas con parent válido, % insertadas', total_parented, inserted_count;

  IF total_parented <> inserted_count THEN
    -- Un abort a secas obliga a adivinar QUÉ quedó afuera. Los seis INSERT de
    -- arriba cubren pares (transactiontype derivado, origen) conocidos; si la
    -- base tiene un par que no mapeamos, esto lo nombra para poder decidir su
    -- `kind` en vez de re-deployar a ciegas.
    SELECT string_agg(DISTINCT format('(derivado=%s, origen=%s)', d.transactiontype, o.transactiontype), ', ')
      INTO uncovered_pairs
      FROM transaction d
      JOIN transaction o
        ON o.transactionid = d.transactionparentid
       AND o.companyid = d.companyid
     WHERE d.transactionparentid IS NOT NULL
       AND NOT EXISTS (
             SELECT 1 FROM transaction_link tl
              WHERE tl.derivedid = d.transactionid
                AND tl.originid  = o.transactionid
           );

    RAISE EXCEPTION 'transaction_link backfill: conteo no cuadra (% válidas vs % insertadas) — abortando sin dropear columnas. Pares transactiontype sin cubrir: %', total_parented, inserted_count, COALESCE(uncovered_pairs, 'ninguno (revisar duplicados)');
  END IF;

  -- order_transaction_link: pos_order.saletransactionid → order_billed
  INSERT INTO order_transaction_link (companyid, orderid, transactionid, kind)
  SELECT o.companyid, o.orderid, o.saletransactionid, 'order_billed'
    FROM pos_order o
   WHERE o.saletransactionid IS NOT NULL
  ON CONFLICT DO NOTHING;

  SELECT count(*) INTO orders_total FROM pos_order WHERE saletransactionid IS NOT NULL;
  SELECT count(*) INTO orders_inserted FROM order_transaction_link;

  RAISE NOTICE 'order_transaction_link backfill: % órdenes con saletransactionid, % insertadas', orders_total, orders_inserted;

  IF orders_total <> orders_inserted THEN
    RAISE EXCEPTION 'order_transaction_link backfill: conteo no cuadra (% órdenes vs % insertadas) — abortando migración sin dropear columnas', orders_total, orders_inserted;
  END IF;
END $$;

-- ============================================================
-- 4. DROP — solo se llega acá si las dos verificaciones de arriba pasaron
--    (RAISE EXCEPTION aborta toda la transacción antes de este punto).
-- ============================================================

ALTER TABLE transaction DROP COLUMN IF EXISTS transactionparentid;
ALTER TABLE pos_order    DROP COLUMN IF EXISTS saletransactionid;

COMMIT;
