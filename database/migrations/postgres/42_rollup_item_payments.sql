-- 42_rollup_item_payments.sql
-- RB-2: extiende rollup_recompute_period con dominios item_sales, item_returns, payments.

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
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'day' AND periodstart = p_day;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, qty, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'sales', 'day', p_day,
             COUNT(*), COALESCE(SUM(transactiontotal),0),
             COALESCE(SUM(transactiontax),0), COALESCE(SUM(transactiondiscount),0),
             COALESCE(SUM(transactionunitssold),0), now()
      FROM transaction
      WHERE transactiontype IN (0, 3) AND companyid = p_company
        AND transactiondate >= p_day AND transactiondate < p_day + 1
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, qty, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'sales', 'day', p_day,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), SUM(qty), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'sales' AND periodtype = 'day' AND periodstart = p_day
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'expenses' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'day' AND periodstart = p_day;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'expenses', 'day', p_day,
             COUNT(*), COALESCE(SUM(transactiontotal),0),
             COALESCE(SUM(transactiontax),0), COALESCE(SUM(transactiondiscount),0), now()
      FROM transaction
      WHERE transactiontype IN (1, 4) AND companyid = p_company
        AND transactiondate >= p_day AND transactiondate < p_day + 1
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'expenses', 'day', p_day,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'expenses' AND periodtype = 'day' AND periodstart = p_day
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'returns' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'day' AND periodstart = p_day;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'returns', 'day', p_day,
             COUNT(*), COALESCE(ABS(SUM(transactiontotal)),0), now()
      FROM transaction
      WHERE transactiontype = 6 AND companyid = p_company
        AND transactiondate >= p_day AND transactiondate < p_day + 1
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'returns', 'day', p_day,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'returns' AND periodtype = 'day' AND periodstart = p_day
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'drawer_expenses' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'day' AND periodstart = p_day;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'drawer_expenses', 'day', p_day,
             COUNT(*), COALESCE(SUM(expensesamount),0), now()
      FROM expenses
      WHERE companyid = p_company
        AND expensesdate >= p_day AND expensesdate < p_day + 1
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'drawer_expenses', 'day', p_day,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'drawer_expenses' AND periodtype = 'day' AND periodstart = p_day
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'item_sales' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'day' AND periodstart = p_day
        AND entitykind = 'item';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, b.outletid, 'item_sales', 'day', p_day,
             a.itemid, 'item',
             COUNT(*),
             COALESCE(SUM(a.itemsoldunits),0),
             COALESCE(SUM(a.itemsoldtotal),0),
             COALESCE(SUM(a.itemsoldtax),0),
             COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
             COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
             jsonb_build_object(
               'comission',    COALESCE(SUM(a.itemsoldcomission),0),
               'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
               'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
             ),
             now()
      FROM itemsold a
      JOIN transaction b ON a.transactionid = b.transactionid
      WHERE b.transactiontype IN (0, 3) AND b.companyid = p_company
        AND b.transactiondate >= p_day AND b.transactiondate < p_day + 1
      GROUP BY b.outletid, a.itemid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'item_sales', 'day', p_day,
             entityid, entitykind,
             SUM(cnt), SUM(qty), SUM(total), SUM(tax), SUM(cogs), SUM(discount),
             jsonb_build_object(
               'comission',    COALESCE(SUM((extra->>'comission')::numeric),0),
               'cogsAbsFlat',  COALESCE(SUM((extra->>'cogsAbsFlat')::numeric),0),
               'discountFlat', COALESCE(SUM((extra->>'discountFlat')::numeric),0)
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'item_sales' AND periodtype = 'day' AND periodstart = p_day
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'item_returns' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'day' AND periodstart = p_day
        AND entitykind = 'item';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, b.outletid, 'item_returns', 'day', p_day,
             a.itemid, 'item',
             COUNT(*),
             COALESCE(SUM(a.itemsoldunits),0),
             COALESCE(SUM(a.itemsoldtotal),0),
             COALESCE(SUM(a.itemsoldtax),0),
             COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
             COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
             jsonb_build_object(
               'comission',    COALESCE(SUM(a.itemsoldcomission),0),
               'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
               'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
             ),
             now()
      FROM itemsold a
      JOIN transaction b ON a.transactionid = b.transactionid
      WHERE b.transactiontype = 6 AND b.companyid = p_company
        AND b.transactiondate >= p_day AND b.transactiondate < p_day + 1
      GROUP BY b.outletid, a.itemid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'item_returns', 'day', p_day,
             entityid, entitykind,
             SUM(cnt), SUM(qty), SUM(total), SUM(tax), SUM(cogs), SUM(discount),
             jsonb_build_object(
               'comission',    COALESCE(SUM((extra->>'comission')::numeric),0),
               'cogsAbsFlat',  COALESCE(SUM((extra->>'cogsAbsFlat')::numeric),0),
               'discountFlat', COALESCE(SUM((extra->>'discountFlat')::numeric),0)
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'item_returns' AND periodtype = 'day' AND periodstart = p_day
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'payments' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = 'payments'
        AND periodtype = 'day' AND periodstart = p_day;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, total, extra, updatedat)
      SELECT gen_random_uuid(), p_company, t.outletid, 'payments', 'day', p_day,
             md5(elem->>'type')::uuid,
             'paymentType',
             COUNT(*),
             COALESCE(SUM((elem->>'total')::numeric), 0),
             jsonb_build_object(
               'price', COALESCE(SUM((elem->>'price')::numeric), 0),
               'label', MIN(elem->>'type')
             ),
             now()
      FROM transaction t,
           jsonb_array_elements(
             CASE WHEN t.transactionpaymenttype IS NOT NULL
                       AND t.transactionpaymenttype <> ''
                       AND t.transactionpaymenttype <> 'null'
                       AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                  THEN t.transactionpaymenttype::jsonb
                  ELSE '[]'::jsonb
             END
           ) AS elem
      WHERE t.transactiontype IN (0, 5)
        AND t.companyid = p_company
        AND t.transactiondate >= p_day AND t.transactiondate < p_day + 1
        AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
      GROUP BY t.outletid, elem->>'type';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, total, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'payments', 'day', p_day,
             entityid, entitykind,
             SUM(cnt),
             SUM(total),
             jsonb_build_object(
               'price', SUM((extra->>'price')::numeric),
               'label', MIN(extra->>'label')
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'payments' AND periodtype = 'day' AND periodstart = p_day
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;
  END IF;

  -- ── MONTH bucket ──
  IF p_domain = 'sales' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'month' AND periodstart = v_month_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, qty, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'sales', 'month', v_month_start,
             COUNT(*), COALESCE(SUM(transactiontotal),0),
             COALESCE(SUM(transactiontax),0), COALESCE(SUM(transactiondiscount),0),
             COALESCE(SUM(transactionunitssold),0), now()
      FROM transaction
      WHERE transactiontype IN (0, 3) AND companyid = p_company
        AND transactiondate >= v_month_start AND transactiondate < v_month_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, qty, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'sales', 'month', v_month_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), SUM(qty), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'sales' AND periodtype = 'month' AND periodstart = v_month_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'expenses' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'month' AND periodstart = v_month_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'expenses', 'month', v_month_start,
             COUNT(*), COALESCE(SUM(transactiontotal),0),
             COALESCE(SUM(transactiontax),0), COALESCE(SUM(transactiondiscount),0), now()
      FROM transaction
      WHERE transactiontype IN (1, 4) AND companyid = p_company
        AND transactiondate >= v_month_start AND transactiondate < v_month_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'expenses', 'month', v_month_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'expenses' AND periodtype = 'month' AND periodstart = v_month_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'returns' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'month' AND periodstart = v_month_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'returns', 'month', v_month_start,
             COUNT(*), COALESCE(ABS(SUM(transactiontotal)),0), now()
      FROM transaction
      WHERE transactiontype = 6 AND companyid = p_company
        AND transactiondate >= v_month_start AND transactiondate < v_month_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'returns', 'month', v_month_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'returns' AND periodtype = 'month' AND periodstart = v_month_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'drawer_expenses' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'month' AND periodstart = v_month_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'drawer_expenses', 'month', v_month_start,
             COUNT(*), COALESCE(SUM(expensesamount),0), now()
      FROM expenses
      WHERE companyid = p_company
        AND expensesdate >= v_month_start AND expensesdate < v_month_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'drawer_expenses', 'month', v_month_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'drawer_expenses' AND periodtype = 'month' AND periodstart = v_month_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'item_sales' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'month' AND periodstart = v_month_start
        AND entitykind = 'item';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, b.outletid, 'item_sales', 'month', v_month_start,
             a.itemid, 'item',
             COUNT(*),
             COALESCE(SUM(a.itemsoldunits),0),
             COALESCE(SUM(a.itemsoldtotal),0),
             COALESCE(SUM(a.itemsoldtax),0),
             COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
             COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
             jsonb_build_object(
               'comission',    COALESCE(SUM(a.itemsoldcomission),0),
               'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
               'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
             ),
             now()
      FROM itemsold a
      JOIN transaction b ON a.transactionid = b.transactionid
      WHERE b.transactiontype IN (0, 3) AND b.companyid = p_company
        AND b.transactiondate >= v_month_start AND b.transactiondate < v_month_end
      GROUP BY b.outletid, a.itemid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'item_sales', 'month', v_month_start,
             entityid, entitykind,
             SUM(cnt), SUM(qty), SUM(total), SUM(tax), SUM(cogs), SUM(discount),
             jsonb_build_object(
               'comission',    COALESCE(SUM((extra->>'comission')::numeric),0),
               'cogsAbsFlat',  COALESCE(SUM((extra->>'cogsAbsFlat')::numeric),0),
               'discountFlat', COALESCE(SUM((extra->>'discountFlat')::numeric),0)
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'item_sales' AND periodtype = 'month' AND periodstart = v_month_start
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'item_returns' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'month' AND periodstart = v_month_start
        AND entitykind = 'item';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, b.outletid, 'item_returns', 'month', v_month_start,
             a.itemid, 'item',
             COUNT(*),
             COALESCE(SUM(a.itemsoldunits),0),
             COALESCE(SUM(a.itemsoldtotal),0),
             COALESCE(SUM(a.itemsoldtax),0),
             COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
             COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
             jsonb_build_object(
               'comission',    COALESCE(SUM(a.itemsoldcomission),0),
               'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
               'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
             ),
             now()
      FROM itemsold a
      JOIN transaction b ON a.transactionid = b.transactionid
      WHERE b.transactiontype = 6 AND b.companyid = p_company
        AND b.transactiondate >= v_month_start AND b.transactiondate < v_month_end
      GROUP BY b.outletid, a.itemid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'item_returns', 'month', v_month_start,
             entityid, entitykind,
             SUM(cnt), SUM(qty), SUM(total), SUM(tax), SUM(cogs), SUM(discount),
             jsonb_build_object(
               'comission',    COALESCE(SUM((extra->>'comission')::numeric),0),
               'cogsAbsFlat',  COALESCE(SUM((extra->>'cogsAbsFlat')::numeric),0),
               'discountFlat', COALESCE(SUM((extra->>'discountFlat')::numeric),0)
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'item_returns' AND periodtype = 'month' AND periodstart = v_month_start
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'payments' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = 'payments'
        AND periodtype = 'month' AND periodstart = v_month_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, total, extra, updatedat)
      SELECT gen_random_uuid(), p_company, t.outletid, 'payments', 'month', v_month_start,
             md5(elem->>'type')::uuid,
             'paymentType',
             COUNT(*),
             COALESCE(SUM((elem->>'total')::numeric), 0),
             jsonb_build_object(
               'price', COALESCE(SUM((elem->>'price')::numeric), 0),
               'label', MIN(elem->>'type')
             ),
             now()
      FROM transaction t,
           jsonb_array_elements(
             CASE WHEN t.transactionpaymenttype IS NOT NULL
                       AND t.transactionpaymenttype <> ''
                       AND t.transactionpaymenttype <> 'null'
                       AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                  THEN t.transactionpaymenttype::jsonb
                  ELSE '[]'::jsonb
             END
           ) AS elem
      WHERE t.transactiontype IN (0, 5)
        AND t.companyid = p_company
        AND t.transactiondate >= v_month_start AND t.transactiondate < v_month_end
        AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
      GROUP BY t.outletid, elem->>'type';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, total, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'payments', 'month', v_month_start,
             entityid, entitykind,
             SUM(cnt),
             SUM(total),
             jsonb_build_object(
               'price', SUM((extra->>'price')::numeric),
               'label', MIN(extra->>'label')
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'payments' AND periodtype = 'month' AND periodstart = v_month_start
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;
  END IF;

  -- ── YEAR bucket ──
  IF p_domain = 'sales' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'year' AND periodstart = v_year_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, qty, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'sales', 'year', v_year_start,
             COUNT(*), COALESCE(SUM(transactiontotal),0),
             COALESCE(SUM(transactiontax),0), COALESCE(SUM(transactiondiscount),0),
             COALESCE(SUM(transactionunitssold),0), now()
      FROM transaction
      WHERE transactiontype IN (0, 3) AND companyid = p_company
        AND transactiondate >= v_year_start AND transactiondate < v_year_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, qty, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'sales', 'year', v_year_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), SUM(qty), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'sales' AND periodtype = 'year' AND periodstart = v_year_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'expenses' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'year' AND periodstart = v_year_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'expenses', 'year', v_year_start,
             COUNT(*), COALESCE(SUM(transactiontotal),0),
             COALESCE(SUM(transactiontax),0), COALESCE(SUM(transactiondiscount),0), now()
      FROM transaction
      WHERE transactiontype IN (1, 4) AND companyid = p_company
        AND transactiondate >= v_year_start AND transactiondate < v_year_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, tax, discount, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'expenses', 'year', v_year_start,
             SUM(cnt), SUM(total), SUM(tax), SUM(discount), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'expenses' AND periodtype = 'year' AND periodstart = v_year_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'returns' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'year' AND periodstart = v_year_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'returns', 'year', v_year_start,
             COUNT(*), COALESCE(ABS(SUM(transactiontotal)),0), now()
      FROM transaction
      WHERE transactiontype = 6 AND companyid = p_company
        AND transactiondate >= v_year_start AND transactiondate < v_year_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'returns', 'year', v_year_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'returns' AND periodtype = 'year' AND periodstart = v_year_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'drawer_expenses' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'year' AND periodstart = v_year_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, outletid, 'drawer_expenses', 'year', v_year_start,
             COUNT(*), COALESCE(SUM(expensesamount),0), now()
      FROM expenses
      WHERE companyid = p_company
        AND expensesdate >= v_year_start AND expensesdate < v_year_end
      GROUP BY outletid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                cnt, total, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'drawer_expenses', 'year', v_year_start,
             SUM(cnt), SUM(total), now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'drawer_expenses' AND periodtype = 'year' AND periodstart = v_year_start
        AND outletid IS NOT NULL
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'item_sales' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'year' AND periodstart = v_year_start
        AND entitykind = 'item';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, b.outletid, 'item_sales', 'year', v_year_start,
             a.itemid, 'item',
             COUNT(*),
             COALESCE(SUM(a.itemsoldunits),0),
             COALESCE(SUM(a.itemsoldtotal),0),
             COALESCE(SUM(a.itemsoldtax),0),
             COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
             COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
             jsonb_build_object(
               'comission',    COALESCE(SUM(a.itemsoldcomission),0),
               'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
               'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
             ),
             now()
      FROM itemsold a
      JOIN transaction b ON a.transactionid = b.transactionid
      WHERE b.transactiontype IN (0, 3) AND b.companyid = p_company
        AND b.transactiondate >= v_year_start AND b.transactiondate < v_year_end
      GROUP BY b.outletid, a.itemid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'item_sales', 'year', v_year_start,
             entityid, entitykind,
             SUM(cnt), SUM(qty), SUM(total), SUM(tax), SUM(cogs), SUM(discount),
             jsonb_build_object(
               'comission',    COALESCE(SUM((extra->>'comission')::numeric),0),
               'cogsAbsFlat',  COALESCE(SUM((extra->>'cogsAbsFlat')::numeric),0),
               'discountFlat', COALESCE(SUM((extra->>'discountFlat')::numeric),0)
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'item_sales' AND periodtype = 'year' AND periodstart = v_year_start
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'item_returns' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = p_domain
        AND periodtype = 'year' AND periodstart = v_year_start
        AND entitykind = 'item';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, b.outletid, 'item_returns', 'year', v_year_start,
             a.itemid, 'item',
             COUNT(*),
             COALESCE(SUM(a.itemsoldunits),0),
             COALESCE(SUM(a.itemsoldtotal),0),
             COALESCE(SUM(a.itemsoldtax),0),
             COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
             COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
             jsonb_build_object(
               'comission',    COALESCE(SUM(a.itemsoldcomission),0),
               'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
               'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
             ),
             now()
      FROM itemsold a
      JOIN transaction b ON a.transactionid = b.transactionid
      WHERE b.transactiontype = 6 AND b.companyid = p_company
        AND b.transactiondate >= v_year_start AND b.transactiondate < v_year_end
      GROUP BY b.outletid, a.itemid;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'item_returns', 'year', v_year_start,
             entityid, entitykind,
             SUM(cnt), SUM(qty), SUM(total), SUM(tax), SUM(cogs), SUM(discount),
             jsonb_build_object(
               'comission',    COALESCE(SUM((extra->>'comission')::numeric),0),
               'cogsAbsFlat',  COALESCE(SUM((extra->>'cogsAbsFlat')::numeric),0),
               'discountFlat', COALESCE(SUM((extra->>'discountFlat')::numeric),0)
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'item_returns' AND periodtype = 'year' AND periodstart = v_year_start
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;

  ELSIF p_domain = 'payments' THEN
    DELETE FROM report_rollup
      WHERE companyid = p_company AND domain = 'payments'
        AND periodtype = 'year' AND periodstart = v_year_start;
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, total, extra, updatedat)
      SELECT gen_random_uuid(), p_company, t.outletid, 'payments', 'year', v_year_start,
             md5(elem->>'type')::uuid,
             'paymentType',
             COUNT(*),
             COALESCE(SUM((elem->>'total')::numeric), 0),
             jsonb_build_object(
               'price', COALESCE(SUM((elem->>'price')::numeric), 0),
               'label', MIN(elem->>'type')
             ),
             now()
      FROM transaction t,
           jsonb_array_elements(
             CASE WHEN t.transactionpaymenttype IS NOT NULL
                       AND t.transactionpaymenttype <> ''
                       AND t.transactionpaymenttype <> 'null'
                       AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                  THEN t.transactionpaymenttype::jsonb
                  ELSE '[]'::jsonb
             END
           ) AS elem
      WHERE t.transactiontype IN (0, 5)
        AND t.companyid = p_company
        AND t.transactiondate >= v_year_start AND t.transactiondate < v_year_end
        AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
      GROUP BY t.outletid, elem->>'type';
    INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                                entityid, entitykind, cnt, total, extra, updatedat)
      SELECT gen_random_uuid(), p_company, NULL, 'payments', 'year', v_year_start,
             entityid, entitykind,
             SUM(cnt),
             SUM(total),
             jsonb_build_object(
               'price', SUM((extra->>'price')::numeric),
               'label', MIN(extra->>'label')
             ),
             now()
      FROM report_rollup
      WHERE companyid = p_company AND domain = 'payments' AND periodtype = 'year' AND periodstart = v_year_start
        AND outletid IS NOT NULL
      GROUP BY entityid, entitykind
      HAVING COUNT(*) > 0;
  END IF;
END;
$$;

DO $$
BEGIN
  -- ── ITEM_SALES: day outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, b.outletid, 'item_sales', 'day',
           date_trunc('day', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype IN (0, 3)
    GROUP BY b.companyid, b.outletid, date_trunc('day', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_SALES: day consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, NULL, 'item_sales', 'day',
           date_trunc('day', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype IN (0, 3)
    GROUP BY b.companyid, date_trunc('day', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_SALES: month outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, b.outletid, 'item_sales', 'month',
           date_trunc('month', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype IN (0, 3)
    GROUP BY b.companyid, b.outletid, date_trunc('month', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_SALES: month consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, NULL, 'item_sales', 'month',
           date_trunc('month', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype IN (0, 3)
    GROUP BY b.companyid, date_trunc('month', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_SALES: year outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, b.outletid, 'item_sales', 'year',
           date_trunc('year', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype IN (0, 3)
    GROUP BY b.companyid, b.outletid, date_trunc('year', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_SALES: year consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, NULL, 'item_sales', 'year',
           date_trunc('year', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype IN (0, 3)
    GROUP BY b.companyid, date_trunc('year', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_RETURNS: day outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, b.outletid, 'item_returns', 'day',
           date_trunc('day', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype = 6
    GROUP BY b.companyid, b.outletid, date_trunc('day', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_RETURNS: day consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, NULL, 'item_returns', 'day',
           date_trunc('day', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype = 6
    GROUP BY b.companyid, date_trunc('day', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_RETURNS: month outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, b.outletid, 'item_returns', 'month',
           date_trunc('month', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype = 6
    GROUP BY b.companyid, b.outletid, date_trunc('month', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_RETURNS: month consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, NULL, 'item_returns', 'month',
           date_trunc('month', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype = 6
    GROUP BY b.companyid, date_trunc('month', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_RETURNS: year outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, b.outletid, 'item_returns', 'year',
           date_trunc('year', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype = 6
    GROUP BY b.companyid, b.outletid, date_trunc('year', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── ITEM_RETURNS: year consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, qty, total, tax, cogs, discount, extra, updatedat)
    SELECT gen_random_uuid(), b.companyid, NULL, 'item_returns', 'year',
           date_trunc('year', a.itemsolddate)::date,
           a.itemid, 'item',
           COUNT(*),
           COALESCE(SUM(a.itemsoldunits),0),
           COALESCE(SUM(a.itemsoldtotal),0),
           COALESCE(SUM(a.itemsoldtax),0),
           COALESCE(SUM(a.itemsoldcogs * a.itemsoldunits),0),
           COALESCE(SUM(a.itemsolddiscount * a.itemsoldunits),0),
           jsonb_build_object(
             'comission',    COALESCE(SUM(a.itemsoldcomission),0),
             'cogsAbsFlat',  COALESCE(SUM(ABS(a.itemsoldcogs)),0),
             'discountFlat', COALESCE(SUM(a.itemsolddiscount),0)
           ),
           now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype = 6
    GROUP BY b.companyid, date_trunc('year', a.itemsolddate)::date, a.itemid
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── PAYMENTS: day outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, total, extra, updatedat)
    SELECT gen_random_uuid(), t.companyid, t.outletid, 'payments', 'day',
           date_trunc('day', t.transactiondate)::date,
           md5(elem->>'type')::uuid,
           'paymentType',
           COUNT(*),
           COALESCE(SUM((elem->>'total')::numeric), 0),
           jsonb_build_object(
             'price', COALESCE(SUM((elem->>'price')::numeric), 0),
             'label', MIN(elem->>'type')
           ),
           now()
    FROM transaction t,
         jsonb_array_elements(
           CASE WHEN t.transactionpaymenttype IS NOT NULL
                     AND t.transactionpaymenttype <> ''
                     AND t.transactionpaymenttype <> 'null'
                     AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                THEN t.transactionpaymenttype::jsonb
                ELSE '[]'::jsonb
           END
         ) AS elem
    WHERE t.transactiontype IN (0, 5)
      AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
    GROUP BY t.companyid, t.outletid, date_trunc('day', t.transactiondate)::date, elem->>'type'
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── PAYMENTS: day consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, total, extra, updatedat)
    SELECT gen_random_uuid(), t.companyid, NULL, 'payments', 'day',
           date_trunc('day', t.transactiondate)::date,
           md5(elem->>'type')::uuid,
           'paymentType',
           COUNT(*),
           COALESCE(SUM((elem->>'total')::numeric), 0),
           jsonb_build_object(
             'price', COALESCE(SUM((elem->>'price')::numeric), 0),
             'label', MIN(elem->>'type')
           ),
           now()
    FROM transaction t,
         jsonb_array_elements(
           CASE WHEN t.transactionpaymenttype IS NOT NULL
                     AND t.transactionpaymenttype <> ''
                     AND t.transactionpaymenttype <> 'null'
                     AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                THEN t.transactionpaymenttype::jsonb
                ELSE '[]'::jsonb
           END
         ) AS elem
    WHERE t.transactiontype IN (0, 5)
      AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
    GROUP BY t.companyid, date_trunc('day', t.transactiondate)::date, elem->>'type'
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── PAYMENTS: month outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, total, extra, updatedat)
    SELECT gen_random_uuid(), t.companyid, t.outletid, 'payments', 'month',
           date_trunc('month', t.transactiondate)::date,
           md5(elem->>'type')::uuid,
           'paymentType',
           COUNT(*),
           COALESCE(SUM((elem->>'total')::numeric), 0),
           jsonb_build_object(
             'price', COALESCE(SUM((elem->>'price')::numeric), 0),
             'label', MIN(elem->>'type')
           ),
           now()
    FROM transaction t,
         jsonb_array_elements(
           CASE WHEN t.transactionpaymenttype IS NOT NULL
                     AND t.transactionpaymenttype <> ''
                     AND t.transactionpaymenttype <> 'null'
                     AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                THEN t.transactionpaymenttype::jsonb
                ELSE '[]'::jsonb
           END
         ) AS elem
    WHERE t.transactiontype IN (0, 5)
      AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
    GROUP BY t.companyid, t.outletid, date_trunc('month', t.transactiondate)::date, elem->>'type'
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── PAYMENTS: month consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, total, extra, updatedat)
    SELECT gen_random_uuid(), t.companyid, NULL, 'payments', 'month',
           date_trunc('month', t.transactiondate)::date,
           md5(elem->>'type')::uuid,
           'paymentType',
           COUNT(*),
           COALESCE(SUM((elem->>'total')::numeric), 0),
           jsonb_build_object(
             'price', COALESCE(SUM((elem->>'price')::numeric), 0),
             'label', MIN(elem->>'type')
           ),
           now()
    FROM transaction t,
         jsonb_array_elements(
           CASE WHEN t.transactionpaymenttype IS NOT NULL
                     AND t.transactionpaymenttype <> ''
                     AND t.transactionpaymenttype <> 'null'
                     AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                THEN t.transactionpaymenttype::jsonb
                ELSE '[]'::jsonb
           END
         ) AS elem
    WHERE t.transactiontype IN (0, 5)
      AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
    GROUP BY t.companyid, date_trunc('month', t.transactiondate)::date, elem->>'type'
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── PAYMENTS: year outlet-específico ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, total, extra, updatedat)
    SELECT gen_random_uuid(), t.companyid, t.outletid, 'payments', 'year',
           date_trunc('year', t.transactiondate)::date,
           md5(elem->>'type')::uuid,
           'paymentType',
           COUNT(*),
           COALESCE(SUM((elem->>'total')::numeric), 0),
           jsonb_build_object(
             'price', COALESCE(SUM((elem->>'price')::numeric), 0),
             'label', MIN(elem->>'type')
           ),
           now()
    FROM transaction t,
         jsonb_array_elements(
           CASE WHEN t.transactionpaymenttype IS NOT NULL
                     AND t.transactionpaymenttype <> ''
                     AND t.transactionpaymenttype <> 'null'
                     AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                THEN t.transactionpaymenttype::jsonb
                ELSE '[]'::jsonb
           END
         ) AS elem
    WHERE t.transactiontype IN (0, 5)
      AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
    GROUP BY t.companyid, t.outletid, date_trunc('year', t.transactiondate)::date, elem->>'type'
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  -- ── PAYMENTS: year consolidado ──
  INSERT INTO report_rollup (id, companyid, outletid, domain, periodtype, periodstart,
                              entityid, entitykind, cnt, total, extra, updatedat)
    SELECT gen_random_uuid(), t.companyid, NULL, 'payments', 'year',
           date_trunc('year', t.transactiondate)::date,
           md5(elem->>'type')::uuid,
           'paymentType',
           COUNT(*),
           COALESCE(SUM((elem->>'total')::numeric), 0),
           jsonb_build_object(
             'price', COALESCE(SUM((elem->>'price')::numeric), 0),
             'label', MIN(elem->>'type')
           ),
           now()
    FROM transaction t,
         jsonb_array_elements(
           CASE WHEN t.transactionpaymenttype IS NOT NULL
                     AND t.transactionpaymenttype <> ''
                     AND t.transactionpaymenttype <> 'null'
                     AND jsonb_typeof(t.transactionpaymenttype::jsonb) = 'array'
                THEN t.transactionpaymenttype::jsonb
                ELSE '[]'::jsonb
           END
         ) AS elem
    WHERE t.transactiontype IN (0, 5)
      AND (elem->>'type') IS NOT NULL AND (elem->>'type') <> ''
    GROUP BY t.companyid, date_trunc('year', t.transactiondate)::date, elem->>'type'
  ON CONFLICT ON CONSTRAINT uq_report_rollup_grain DO NOTHING;

  RAISE NOTICE 'rollup RB-2: backfill completado.';
EXCEPTION WHEN OTHERS THEN
  RAISE NOTICE 'rollup RB-2: backfill falló (%), continuando.', SQLERRM;
END $$;
