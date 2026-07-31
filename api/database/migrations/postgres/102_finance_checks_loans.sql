BEGIN;

-- ============================================================
-- Cheques integrados + Créditos básicos (F1+F2, ver
-- context/30-cheques-prevision-creditos.md — plan cerrado 2026-07-30).
--
-- F1: el cheque nace del pago (venta/pago de crédito/compra), no de un form
-- aparte. fin_check gana `transactionid` con UNIQUE parcial por companyid:
-- una transacción genera a lo sumo un cheque (createFromPayment es
-- idempotente por esta constraint).
--
-- F2: créditos básicos (fin_loan + fin_loan_installment) — total / cuotas
-- iguales / primera fecha, mensual, sin interés calculado.
--
-- Mismas convenciones que 72_finance.sql: columnas físicas lowercase sin
-- comillas (ncmInsert/ncmUpdate arman SQL con las keys del array PHP tal
-- cual), companyid explícito en todo, PKs uuid gen_random_uuid().
-- ============================================================

-- ── fin_check.transactionid — el cheque nace del pago ────────────────────
ALTER TABLE fin_check ADD COLUMN IF NOT EXISTS transactionid uuid;

-- Idempotencia: CheckService::createFromPayment se llama desde FinanceLedger
-- (best-effort, puede reintentarse en retries/reprocesos) — a lo sumo un
-- cheque por transacción. Referencia lógica (no FK) a `transaction`, mismo
-- criterio que fin_movement.checkid/reconciliationid (72_finance.sql).
CREATE UNIQUE INDEX IF NOT EXISTS uidx_fin_check_transaction
  ON fin_check (companyid, transactionid)
  WHERE transactionid IS NOT NULL;

-- ── fin_loan — créditos/préstamos (acreedor, total, cuotas) ──────────────
CREATE TABLE IF NOT EXISTS fin_loan (
  loanid            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         uuid NOT NULL,
  name              varchar(160) NOT NULL,             -- acreedor / descripción
  principal         numeric(14,2) NOT NULL,
  installmentcount  int NOT NULL,
  firstduedate      timestamptz NOT NULL,
  frequency         varchar(16) NOT NULL DEFAULT 'monthly', -- fijo v1
  status            varchar(16) NOT NULL DEFAULT 'active',  -- active|settled|cancelled
  created_at        timestamptz NOT NULL DEFAULT now(),
  data              jsonb NOT NULL DEFAULT '{}'::jsonb,
  CONSTRAINT fin_loan_status_chk CHECK (status IN ('active', 'settled', 'cancelled')),
  CONSTRAINT fin_loan_frequency_chk CHECK (frequency IN ('monthly')),
  CONSTRAINT fin_loan_principal_chk CHECK (principal > 0),
  CONSTRAINT fin_loan_installmentcount_chk CHECK (installmentcount > 0)
);

CREATE INDEX IF NOT EXISTS idx_fin_loan_company ON fin_loan (companyid, status);

-- ── fin_loan_installment — cuotas ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fin_loan_installment (
  installmentid  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  loanid         uuid NOT NULL REFERENCES fin_loan(loanid),
  companyid      uuid NOT NULL,
  seq            int NOT NULL,
  duedate        timestamptz NOT NULL,
  amount         numeric(14,2) NOT NULL,
  status         varchar(16) NOT NULL DEFAULT 'pending', -- pending|paid
  paiddate       timestamptz,
  movementid     uuid,                                    -- referencia lógica a fin_movement
  CONSTRAINT fin_loan_installment_status_chk CHECK (status IN ('pending', 'paid')),
  CONSTRAINT fin_loan_installment_amount_chk CHECK (amount > 0),
  CONSTRAINT uq_fin_loan_installment_seq UNIQUE (loanid, seq)
);

CREATE INDEX IF NOT EXISTS idx_fin_loan_installment_company ON fin_loan_installment (companyid, status, duedate);
CREATE INDEX IF NOT EXISTS idx_fin_loan_installment_loan ON fin_loan_installment (loanid, seq);

-- ============================================================
-- Rescate de datos: `transaction.transactionPaymentType` de COMPRAS
-- (transactionType=1) quedó atrapado en el JSONB `meta` en vez de la
-- columna física — `_getTableSchema()` (api/includes/functions.php) no
-- listaba esa columna para la tabla `transaction`, así que ncmInsert (usado
-- por PurchasesService::create, a diferencia de SaleService/CreditPaymentService
-- que usan AutoExecute directo) la enrutaba a `meta` en vez de escribirla en
-- la columna real. Root-cause de la "Parte 2" del incidente 737M: sin el dato
-- en la columna física, FinanceLedger::recordPurchase la veía vacía y
-- saltaba el movimiento SIEMPRE, aunque el form ya mandara un método.
-- Mismo patrón que las migs 99 (item) y 100 (contact): promover del JSONB a
-- la columna real, con el JSONB ganando el valor más reciente porque nunca
-- se sincronizó de vuelta a la columna.
-- ============================================================

UPDATE transaction
   SET transactionpaymenttype = meta->>'transactionPaymentType'
 WHERE transactiontype = 1
   AND transactionpaymenttype IS NULL
   AND jsonb_exists(meta, 'transactionPaymentType')
   AND meta->>'transactionPaymentType' IS NOT NULL;

UPDATE transaction
   SET meta = meta - 'transactionPaymentType'
 WHERE transactiontype = 1
   AND jsonb_exists(meta, 'transactionPaymentType');

COMMIT;
