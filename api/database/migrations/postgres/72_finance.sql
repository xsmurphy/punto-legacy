BEGIN;

-- ============================================================
-- Módulo de Finanzas — caja simple (single-entry), NO partida doble.
-- Ver context/22-finanzas-module-plan.md (plan cerrado).
--
-- Columnas físicas en MINÚSCULA SIN comillas (nunca camelCase quoted).
-- Razón: el wrapper DB (api/includes/lib/DB.php) arma INSERT/UPDATE con las
-- keys del array de PHP sin comillas; Postgres pliega identificadores sin
-- comillas a minúscula. Si una columna se creara entre comillas mixed-case
-- (ej. "companyId"), ncmInsert/ncmUpdate no la encontrarían → "column does
-- not exist" (bug real de la mig 70, arreglado en la mig 71). Todas las
-- tablas de este módulo se escriben vía ncmInsert/ncmUpdate → minúscula.
--
-- Multi-tenant: toda tabla lleva companyid NOT NULL + índice por companyid.
-- PKs uuid gen_random_uuid(). Montos NUMERIC(14,2). data jsonb para extras.
-- ============================================================

-- ── fin_account — cuentas (dónde está la plata) ──────────────────────────
CREATE TABLE IF NOT EXISTS fin_account (
  accountid       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid       uuid NOT NULL,
  name            varchar(120) NOT NULL,
  type            varchar(16) NOT NULL DEFAULT 'cash',   -- 'cash' | 'bank' | 'wallet'
  openingbalance  numeric(14,2) NOT NULL DEFAULT 0,
  currentbalance  numeric(14,2) NOT NULL DEFAULT 0,       -- cache; recomputable = openingbalance + Σ movimientos activos
  bankname        varchar(120),
  accountnumber   varchar(80),
  outletid        uuid,                                    -- null = cuenta global (todas las sucursales)
  issystem        boolean NOT NULL DEFAULT false,           -- true = "Efectivo" default, no borrable
  status          smallint NOT NULL DEFAULT 1,              -- 1=activa, 0=archivada
  created_at      timestamptz NOT NULL DEFAULT now(),
  data            jsonb NOT NULL DEFAULT '{}'::jsonb,
  CONSTRAINT fin_account_type_chk CHECK (type IN ('cash', 'bank', 'wallet'))
);

CREATE INDEX IF NOT EXISTS idx_fin_account_company ON fin_account (companyid, status);

-- ── fin_category — categorías (plan de cuentas simplificado, 1 nivel) ────
CREATE TABLE IF NOT EXISTS fin_category (
  categoryid   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid    uuid NOT NULL,
  name         varchar(120) NOT NULL,
  kind         varchar(16) NOT NULL,                    -- 'income' | 'expense'
  parentid     uuid REFERENCES fin_category(categoryid),
  sortorder    int NOT NULL DEFAULT 0,
  issystem     boolean NOT NULL DEFAULT false,           -- default no borrable
  status       smallint NOT NULL DEFAULT 1,
  created_at   timestamptz NOT NULL DEFAULT now(),
  data         jsonb NOT NULL DEFAULT '{}'::jsonb,
  CONSTRAINT fin_category_kind_chk CHECK (kind IN ('income', 'expense'))
);

CREATE INDEX IF NOT EXISTS idx_fin_category_company ON fin_category (companyid, kind, status);

-- ── fin_movement — el ledger (entradas/salidas) ──────────────────────────
CREATE TABLE IF NOT EXISTS fin_movement (
  movementid        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         uuid NOT NULL,
  accountid         uuid NOT NULL REFERENCES fin_account(accountid),
  categoryid        uuid REFERENCES fin_category(categoryid),
  kind              varchar(16) NOT NULL,                -- 'income' | 'expense'
  amount            numeric(14,2) NOT NULL,               -- SIEMPRE positivo; el signo lo da kind
  date              timestamptz NOT NULL DEFAULT now(),
  description       text,
  paymentmethod     varchar(40),
  source            varchar(20) NOT NULL DEFAULT 'manual', -- 'manual'|'sale'|'purchase'|'expense'|'credit_payment'|'check'|'transfer'|'opening'
  sourceid          uuid,
  transfergroupid   uuid,
  checkid           uuid,
  reconciliationid  uuid,
  reconciled        boolean NOT NULL DEFAULT false,
  reconciled_at     timestamptz,
  userid            uuid,
  outletid          uuid,
  status            smallint NOT NULL DEFAULT 1,          -- 1=activo, 0=anulado
  created_at        timestamptz NOT NULL DEFAULT now(),
  data              jsonb NOT NULL DEFAULT '{}'::jsonb,
  CONSTRAINT fin_movement_kind_chk CHECK (kind IN ('income', 'expense')),
  CONSTRAINT fin_movement_amount_chk CHECK (amount >= 0)
);

-- Idempotencia: un mismo sale/purchase/expense/cheque genera 1 solo movimiento.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_fin_movement_source
  ON fin_movement (companyid, source, sourceid)
  WHERE sourceid IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_fin_movement_company_date ON fin_movement (companyid, date DESC);
CREATE INDEX IF NOT EXISTS idx_fin_movement_account       ON fin_movement (accountid, date DESC);
CREATE INDEX IF NOT EXISTS idx_fin_movement_category      ON fin_movement (categoryid);
CREATE INDEX IF NOT EXISTS idx_fin_movement_transfergroup ON fin_movement (transfergroupid) WHERE transfergroupid IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_fin_movement_reconciliation ON fin_movement (reconciliationid) WHERE reconciliationid IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_fin_movement_checkid ON fin_movement (checkid) WHERE checkid IS NOT NULL;

-- ── fin_check — cheques emitidos/recibidos (Fase 2) ──────────────────────
CREATE TABLE IF NOT EXISTS fin_check (
  checkid       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid     uuid NOT NULL,
  direction     varchar(10) NOT NULL,                    -- 'issued' | 'received'
  accountid     uuid REFERENCES fin_account(accountid),  -- cuenta bancaria propia si emitido
  bankname      varchar(120),
  checknumber   varchar(60),
  amount        numeric(14,2) NOT NULL,
  issuedate     timestamptz NOT NULL DEFAULT now(),
  duedate       timestamptz,
  contactid     uuid,
  partyname     varchar(160),                             -- texto libre si no hay contacto
  categoryid    uuid REFERENCES fin_category(categoryid),
  status        varchar(16) NOT NULL DEFAULT 'pending',   -- pending|deposited|cleared|bounced|cancelled
  cleareddate   timestamptz,
  description   text,
  created_at    timestamptz NOT NULL DEFAULT now(),
  data          jsonb NOT NULL DEFAULT '{}'::jsonb,
  CONSTRAINT fin_check_direction_chk CHECK (direction IN ('issued', 'received')),
  CONSTRAINT fin_check_status_chk CHECK (status IN ('pending', 'deposited', 'cleared', 'bounced', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS idx_fin_check_company ON fin_check (companyid, status, duedate);

-- ── fin_reconciliation — sesiones de conciliación bancaria (Fase 2) ─────
CREATE TABLE IF NOT EXISTS fin_reconciliation (
  reconciliationid  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         uuid NOT NULL,
  accountid         uuid NOT NULL REFERENCES fin_account(accountid),
  statementdate     timestamptz NOT NULL,
  statementbalance  numeric(14,2) NOT NULL,
  status            varchar(10) NOT NULL DEFAULT 'open', -- 'open' | 'closed'
  closed_at         timestamptz,
  userid            uuid,
  created_at        timestamptz NOT NULL DEFAULT now(),
  data              jsonb NOT NULL DEFAULT '{}'::jsonb,
  CONSTRAINT fin_reconciliation_status_chk CHECK (status IN ('open', 'closed'))
);

CREATE INDEX IF NOT EXISTS idx_fin_reconciliation_account ON fin_reconciliation (accountid, status);
CREATE INDEX IF NOT EXISTS idx_fin_reconciliation_company ON fin_reconciliation (companyid);

-- fin_movement.checkid / reconciliationid son referencias lógicas (no FK física
-- a fin_check/fin_reconciliation) porque el movimiento puede crearse antes o
-- ser anulado independientemente del ciclo de vida del cheque/conciliación;
-- se validan a nivel de servicio, no de constraint.

COMMIT;
