-- 155_rollup_exclude_voided_sales.sql
-- F4 de context/40-anulacion-y-nota-credito.md: las ventas anuladas
-- (`voidedat`, mig 154) no deben sumar en los rollups pre-agregados que
-- alimentan los reportes (RollupReader). CREATE OR REPLACE completo de
-- rollup_recompute_period (base: 42_rollup_item_payments.sql, la versión
-- más reciente de la función) — se agrega `AND voidedat IS NULL` (o
-- `AND b.voidedat IS NULL` / `AND t.voidedat IS NULL` según el alias) a
-- las 9 ramas derivadas de venta contado/crédito, en los 3 buckets
-- (day/month/year):
--   - dominio 'sales'      (transaction, sin alias)
--   - dominio 'item_sales' (itemsold a JOIN transaction b)
--   - dominio 'payments'   (transaction t — tipos 0 Y 5; voidedat solo lo
--     setea SaleVoidService sobre filas type 0/3, así que en las filas
--     type 5 (recibo) siempre es NULL y el filtro no las afecta)
--
-- NO tocados a propósito (mismo criterio que SaleFilters, ver su
-- docblock): 'returns'/'item_returns' (transactionType=6, notas de
-- crédito — F3-F6 de context/40 siguen sin implementar) y
-- 'drawer_expenses' (turno/caja, no reporte de ventas).
--
-- rollup_reconcile() no cambia — no referencia voidedat, solo llama a
-- rollup_recompute_period() por cada bucket sucio.

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
        AND voidedat IS NULL
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
        AND b.voidedat IS NULL
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
        AND t.voidedat IS NULL
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
        AND voidedat IS NULL
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
        AND b.voidedat IS NULL
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
        AND t.voidedat IS NULL
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
        AND voidedat IS NULL
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
        AND b.voidedat IS NULL
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
        AND t.voidedat IS NULL
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

-- Marca sucios (rollup_dirty) los buckets day/month/year de 'sales',
-- 'item_sales' y 'payments' que contengan una venta ya anulada, para que
-- rollup_reconcile() los recalcule con esta función ya corregida. Hoy
-- (2026-08-21) no hay ventas anuladas en prod (F1 recién aterrizó,
-- commit 5a14447f) — este bloque es no-op en ese caso, pero queda
-- idempotente (ON CONFLICT DO NOTHING sobre la PK de rollup_dirty) para
-- cualquier voidedat que ya exista al aplicar esta migración.
INSERT INTO rollup_dirty (companyid, domain, periodday)
  SELECT DISTINCT companyid, d.domain, date_trunc('day', transactiondate)::date
  FROM transaction, LATERAL (VALUES ('sales'), ('item_sales'), ('payments')) AS d(domain)
  WHERE transactiontype IN (0, 3) AND voidedat IS NOT NULL
ON CONFLICT DO NOTHING;
