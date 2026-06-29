-- 41_report_rollup.sql
-- Infraestructura de rollup pre-agregado para reportes (RB-1: sales/expenses/returns).
-- expenses aquí = transactionType IN (1,4) desde transaction, igual que SummaryYearService.

CREATE TABLE IF NOT EXISTS report_rollup (
  id          UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId   UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletId    UUID,
  domain      TEXT          NOT NULL,
  periodType  TEXT          NOT NULL,
  periodStart DATE          NOT NULL,
  entityId    UUID,
  entityKind  TEXT,
  cnt         BIGINT        NOT NULL DEFAULT 0,
  total       NUMERIC(18,4) NOT NULL DEFAULT 0,
  tax         NUMERIC(18,4) NOT NULL DEFAULT 0,
  discount    NUMERIC(18,4) NOT NULL DEFAULT 0,
  qty         NUMERIC(18,4) NOT NULL DEFAULT 0,
  cogs        NUMERIC(18,4) NOT NULL DEFAULT 0,
  extra       JSONB         NOT NULL DEFAULT '{}',
  updatedAt   TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_report_rollup_grain ON report_rollup (
  companyId, domain, periodType, periodStart,
  COALESCE(outletId, '00000000-0000-0000-0000-000000000000'::uuid),
  COALESCE(entityId, '00000000-0000-0000-0000-000000000000'::uuid)
);

CREATE INDEX IF NOT EXISTS idx_report_rollup_lookup ON report_rollup (companyId, domain, periodType, periodStart);

CREATE TABLE IF NOT EXISTS rollup_dirty (
  companyId  UUID NOT NULL,
  domain     TEXT NOT NULL,
  periodDay  DATE NOT NULL,
  enqueuedAt TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (companyId, domain, periodDay)
);

CREATE OR REPLACE FUNCTION rollup_recompute_period(p_company uuid, p_domain text, p_day date)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
  v_month_start date := date_trunc('month', p_day)::date;
  v_month_end   date := (date_trunc('month', p_day) + interval '1 month')::date;
  v_year_start  date := date_trunc('year',  p_day)::date;
  v_year_end    date := (date_trunc('year',  p_day) + interval '1 year')::date;
BEGIN
  -- ── DAY bucket ──
  IF p_domain = 'sales' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'day' AND periodStart = p_day;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, qty, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'sales', 'day', p_day,
             COUNT(*), COALESCE(SUM(transactionTotal),0),
             COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
             COALESCE(SUM(transactionUnitsSold),0), now()
      FROM transaction
      WHERE transactionType IN (0, 3) AND companyId = p_company
        AND transactionDate >= p_day AND transactionDate < p_day + 1
      GROUP BY outletId;
    -- fila consolidada
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, qty, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'sales', 'day', p_day,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), SUM(qty), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'sales' AND periodType = 'day' AND periodStart = p_day
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'expenses' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'day' AND periodStart = p_day;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'expenses', 'day', p_day,
             COUNT(*), COALESCE(SUM(transactionTotal),0),
             COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
      FROM transaction
      WHERE transactionType IN (1, 4) AND companyId = p_company
        AND transactionDate >= p_day AND transactionDate < p_day + 1
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'expenses', 'day', p_day,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'expenses' AND periodType = 'day' AND periodStart = p_day
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'returns' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'day' AND periodStart = p_day;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'returns', 'day', p_day,
             COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
      FROM transaction
      WHERE transactionType = 6 AND companyId = p_company
        AND transactionDate >= p_day AND transactionDate < p_day + 1
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'returns', 'day', p_day,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'returns' AND periodType = 'day' AND periodStart = p_day
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'drawer_expenses' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'day' AND periodStart = p_day;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'drawer_expenses', 'day', p_day,
             COUNT(*), COALESCE(SUM(expensesAmount),0), now()
      FROM expenses
      WHERE companyId = p_company
        AND expensesDate >= p_day AND expensesDate < p_day + 1
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'drawer_expenses', 'day', p_day,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'drawer_expenses' AND periodType = 'day' AND periodStart = p_day
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;
  END IF;

  -- ── MONTH bucket (reagrega desde fact) ──
  IF p_domain = 'sales' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'month' AND periodStart = v_month_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, qty, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'sales', 'month', v_month_start,
             COUNT(*), COALESCE(SUM(transactionTotal),0),
             COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
             COALESCE(SUM(transactionUnitsSold),0), now()
      FROM transaction
      WHERE transactionType IN (0, 3) AND companyId = p_company
        AND transactionDate >= v_month_start AND transactionDate < v_month_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, qty, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'sales', 'month', v_month_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), SUM(qty), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'sales' AND periodType = 'month' AND periodStart = v_month_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'expenses' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'month' AND periodStart = v_month_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'expenses', 'month', v_month_start,
             COUNT(*), COALESCE(SUM(transactionTotal),0),
             COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
      FROM transaction
      WHERE transactionType IN (1, 4) AND companyId = p_company
        AND transactionDate >= v_month_start AND transactionDate < v_month_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'expenses', 'month', v_month_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'expenses' AND periodType = 'month' AND periodStart = v_month_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'returns' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'month' AND periodStart = v_month_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'returns', 'month', v_month_start,
             COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
      FROM transaction
      WHERE transactionType = 6 AND companyId = p_company
        AND transactionDate >= v_month_start AND transactionDate < v_month_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'returns', 'month', v_month_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'returns' AND periodType = 'month' AND periodStart = v_month_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'drawer_expenses' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'month' AND periodStart = v_month_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'drawer_expenses', 'month', v_month_start,
             COUNT(*), COALESCE(SUM(expensesAmount),0), now()
      FROM expenses
      WHERE companyId = p_company
        AND expensesDate >= v_month_start AND expensesDate < v_month_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'drawer_expenses', 'month', v_month_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'drawer_expenses' AND periodType = 'month' AND periodStart = v_month_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;
  END IF;

  -- ── YEAR bucket (reagrega desde fact) ──
  IF p_domain = 'sales' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'year' AND periodStart = v_year_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, qty, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'sales', 'year', v_year_start,
             COUNT(*), COALESCE(SUM(transactionTotal),0),
             COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
             COALESCE(SUM(transactionUnitsSold),0), now()
      FROM transaction
      WHERE transactionType IN (0, 3) AND companyId = p_company
        AND transactionDate >= v_year_start AND transactionDate < v_year_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, qty, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'sales', 'year', v_year_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), SUM(qty), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'sales' AND periodType = 'year' AND periodStart = v_year_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'expenses' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'year' AND periodStart = v_year_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'expenses', 'year', v_year_start,
             COUNT(*), COALESCE(SUM(transactionTotal),0),
             COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
      FROM transaction
      WHERE transactionType IN (1, 4) AND companyId = p_company
        AND transactionDate >= v_year_start AND transactionDate < v_year_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, tax, discount, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'expenses', 'year', v_year_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'expenses' AND periodType = 'year' AND periodStart = v_year_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'returns' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'year' AND periodStart = v_year_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'returns', 'year', v_year_start,
             COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
      FROM transaction
      WHERE transactionType = 6 AND companyId = p_company
        AND transactionDate >= v_year_start AND transactionDate < v_year_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'returns', 'year', v_year_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'returns' AND periodType = 'year' AND periodStart = v_year_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'drawer_expenses' THEN
    DELETE FROM report_rollup
      WHERE companyId = p_company AND domain = p_domain
        AND periodType = 'year' AND periodStart = v_year_start;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, outletId, 'drawer_expenses', 'year', v_year_start,
             COUNT(*), COALESCE(SUM(expensesAmount),0), now()
      FROM expenses
      WHERE companyId = p_company
        AND expensesDate >= v_year_start AND expensesDate < v_year_end
      GROUP BY outletId;
    INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                                cnt, total, updatedAt)
      SELECT gen_random_uuid(), p_company, NULL, 'drawer_expenses', 'year', v_year_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyId = p_company AND domain = 'drawer_expenses' AND periodType = 'year' AND periodStart = v_year_start
        AND outletId IS NOT NULL
      HAVING COUNT(*) > 0;
  END IF;
END;
$$;

CREATE OR REPLACE FUNCTION rollup_reconcile(p_max int DEFAULT 500)
RETURNS int LANGUAGE plpgsql AS $$
DECLARE
  rec    record;
  processed int := 0;
BEGIN
  FOR rec IN
    SELECT companyId, domain, periodDay
    FROM rollup_dirty
    ORDER BY enqueuedAt
    LIMIT p_max
  LOOP
    PERFORM rollup_recompute_period(rec.companyId, rec.domain, rec.periodDay);
    DELETE FROM rollup_dirty
      WHERE companyId = rec.companyId AND domain = rec.domain AND periodDay = rec.periodDay;
    processed := processed + 1;
  END LOOP;
  RETURN processed;
END;
$$;

-- Backfill histórico (tolerante — si algo falla, NOTICE y sigue).
DO $$
BEGIN
  -- ── SALES: day ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, qty, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'sales', 'day',
           date_trunc('day', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
           COALESCE(SUM(transactionUnitsSold),0), now()
    FROM transaction
    WHERE transactionType IN (0, 3)
    GROUP BY companyId, outletId, date_trunc('day', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- consolidated day sales
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, qty, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'sales', 'day',
           date_trunc('day', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
           COALESCE(SUM(transactionUnitsSold),0), now()
    FROM transaction
    WHERE transactionType IN (0, 3)
    GROUP BY companyId, date_trunc('day', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── SALES: month ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, qty, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'sales', 'month',
           date_trunc('month', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
           COALESCE(SUM(transactionUnitsSold),0), now()
    FROM transaction
    WHERE transactionType IN (0, 3)
    GROUP BY companyId, outletId, date_trunc('month', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, qty, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'sales', 'month',
           date_trunc('month', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
           COALESCE(SUM(transactionUnitsSold),0), now()
    FROM transaction
    WHERE transactionType IN (0, 3)
    GROUP BY companyId, date_trunc('month', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── SALES: year ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, qty, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'sales', 'year',
           date_trunc('year', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
           COALESCE(SUM(transactionUnitsSold),0), now()
    FROM transaction
    WHERE transactionType IN (0, 3)
    GROUP BY companyId, outletId, date_trunc('year', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, qty, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'sales', 'year',
           date_trunc('year', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0),
           COALESCE(SUM(transactionUnitsSold),0), now()
    FROM transaction
    WHERE transactionType IN (0, 3)
    GROUP BY companyId, date_trunc('year', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── EXPENSES: day ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'expenses', 'day',
           date_trunc('day', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
    FROM transaction
    WHERE transactionType IN (1, 4)
    GROUP BY companyId, outletId, date_trunc('day', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'expenses', 'day',
           date_trunc('day', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
    FROM transaction
    WHERE transactionType IN (1, 4)
    GROUP BY companyId, date_trunc('day', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── EXPENSES: month ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'expenses', 'month',
           date_trunc('month', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
    FROM transaction
    WHERE transactionType IN (1, 4)
    GROUP BY companyId, outletId, date_trunc('month', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'expenses', 'month',
           date_trunc('month', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
    FROM transaction
    WHERE transactionType IN (1, 4)
    GROUP BY companyId, date_trunc('month', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── EXPENSES: year ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'expenses', 'year',
           date_trunc('year', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
    FROM transaction
    WHERE transactionType IN (1, 4)
    GROUP BY companyId, outletId, date_trunc('year', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, tax, discount, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'expenses', 'year',
           date_trunc('year', transactionDate)::date,
           COUNT(*), COALESCE(SUM(transactionTotal),0),
           COALESCE(SUM(transactionTax),0), COALESCE(SUM(transactionDiscount),0), now()
    FROM transaction
    WHERE transactionType IN (1, 4)
    GROUP BY companyId, date_trunc('year', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── RETURNS: day ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'returns', 'day',
           date_trunc('day', transactionDate)::date,
           COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
    FROM transaction
    WHERE transactionType = 6
    GROUP BY companyId, outletId, date_trunc('day', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'returns', 'day',
           date_trunc('day', transactionDate)::date,
           COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
    FROM transaction
    WHERE transactionType = 6
    GROUP BY companyId, date_trunc('day', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── RETURNS: month ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'returns', 'month',
           date_trunc('month', transactionDate)::date,
           COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
    FROM transaction
    WHERE transactionType = 6
    GROUP BY companyId, outletId, date_trunc('month', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'returns', 'month',
           date_trunc('month', transactionDate)::date,
           COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
    FROM transaction
    WHERE transactionType = 6
    GROUP BY companyId, date_trunc('month', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── RETURNS: year ──
  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, updatedAt)
    SELECT gen_random_uuid(), companyId, outletId, 'returns', 'year',
           date_trunc('year', transactionDate)::date,
           COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
    FROM transaction
    WHERE transactionType = 6
    GROUP BY companyId, outletId, date_trunc('year', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  INSERT INTO report_rollup (id, companyId, outletId, domain, periodType, periodStart,
                              cnt, total, updatedAt)
    SELECT gen_random_uuid(), companyId, NULL, 'returns', 'year',
           date_trunc('year', transactionDate)::date,
           COUNT(*), COALESCE(ABS(SUM(transactionTotal)),0), now()
    FROM transaction
    WHERE transactionType = 6
    GROUP BY companyId, date_trunc('year', transactionDate)::date
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  RAISE NOTICE 'report_rollup: backfill histórico completado.';
EXCEPTION WHEN OTHERS THEN
  RAISE NOTICE 'report_rollup: backfill falló (%), continuando sin datos históricos.', SQLERRM;
END $$;
