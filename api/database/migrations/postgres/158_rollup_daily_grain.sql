-- 158_rollup_daily_grain.sql
-- D8 de context/48-escalamiento-de-datos.md: "el grano del rollup lo
-- definen los filtros, no las métricas". report_rollup (mig 41) solo podía
-- filtrar por las columnas de su clave (companyId/domain/periodType/
-- periodStart/outletId) — "ventas del año solo contado sin anuladas" no se
-- podía sacar de un agregado que ya sumó contado+crédito en la misma fila.
-- Reemplaza los dominios 'sales'/'item_sales'/'payments'/'returns'/
-- 'item_returns' de report_rollup por tres tablas TIPADAS y anchas en
-- dimensiones, grano día único (mes/año se derivan con SUM, no se
-- almacenan). 'expenses'/'drawer_expenses' quedan intactas en
-- report_rollup — se migran en E2.
--
-- BEGIN/COMMIT explícito (convención migs 156/157, no la de mig 155 que
-- confía en el implicit-transaction de un solo exec() multi-statement):
-- este archivo mezcla DDL (ALTER TABLE, CREATE TABLE, CREATE OR REPLACE
-- FUNCTION) con DML (UPDATE de backfill, DELETE+INSERT de rollup_dirty) —
-- si algo falla a mitad de camino, todo se revierte, no queda estado a medias.

BEGIN;

-- ═══════════════════════════════════════════════════════════════════════
-- Columnas congeladas nuevas en la fact (regla D8: toda dimensión del
-- rollup tiene que estar congelada al momento de la venta, nunca resuelta
-- por JOIN al catálogo actual)
-- ═══════════════════════════════════════════════════════════════════════

-- itemsold.taxrate/taxkind — mismos valores que el motor de impuestos
-- (api/lib/Tax/TaxEngine.php + mig 120_tax_rate_kind.sql): kind es
-- 'rate'|'exempt' (NO 'vat' — verificado contra la constraint real
-- tax_kind_allowed de mig 120), rate es la tasa numérica (10, 5, 0...).
-- itemsold está particionada desde mig 156 (RANGE por itemsolddate):
-- ALTER TABLE sobre el padre alcanza, se propaga a todas las particiones.
ALTER TABLE itemsold ADD COLUMN IF NOT EXISTS taxrate numeric(6,3) NOT NULL DEFAULT 0;
ALTER TABLE itemsold ADD COLUMN IF NOT EXISTS taxkind text NOT NULL DEFAULT 'exempt';

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conname = 'itemsold_taxkind_allowed' AND conrelid = 'itemsold'::regclass
  ) THEN
    ALTER TABLE itemsold ADD CONSTRAINT itemsold_taxkind_allowed CHECK (taxkind IN ('rate', 'exempt'));
  END IF;
END $$;

-- Backfill aproximado (dato de prueba, no hay volumen real en prod aún):
-- reconstruye la tasa desde itemsoldtax/itemsoldtotal. No es exacto para
-- líneas con descuento parcial o redondeo, pero es la mejor aproximación
-- disponible sin la línea original del motor. Filas nuevas (post-deploy)
-- vienen con el valor REAL desde SaleService/ReturnService (ver PHP).
UPDATE itemsold
   SET taxrate = CASE
                   WHEN itemsoldtax > 0 AND (itemsoldtotal - itemsoldtax) <> 0
                     THEN round(itemsoldtax / (itemsoldtotal - itemsoldtax) * 100, 0)
                   ELSE 0
                 END
 WHERE taxrate = 0; -- solo filas sin tocar (recién agregadas con el default)
UPDATE itemsold
   SET taxkind = CASE WHEN taxrate > 0 THEN 'rate' ELSE 'exempt' END
 WHERE taxkind = 'exempt';

-- itemsold.itemsoldcategory (columna YA EXISTÍA desde el schema base, FK a
-- taxonomy) — hallazgo de esta sesión: NINGÚN writer la poblaba nunca
-- (grep confirmó cero INSERTs con esa columna fuera de mig 156 DDL). El
-- rollup por categoría de CategoriesService compensaba re-uniendo contra
-- item.categoryId ACTUAL — exactamente el anti-patrón que D8 prohíbe
-- ("si el rollup por categoría mirara la categoría de HOY del ítem,
-- recategorizar un producto cambiaría el histórico"). Se cierra acá: los
-- writers (ver PHP) congelan item.categoryId en itemsold.itemsoldcategory
-- desde este deploy en adelante. Backfill con la categoría ACTUAL para
-- filas históricas (mejor aproximación disponible, documentado como tal —
-- NO se puede reconstruir la categoría de hace un año).
UPDATE itemsold i SET itemsoldcategory = it.categoryid
  FROM item it
 WHERE i.itemid = it.itemid
   AND i.itemsoldcategory IS NULL
   AND it.categoryid IS NOT NULL;

-- transaction.channel — mostrador/mesa/delivery, congelado al vincular la
-- venta con su canal de origen (ver OrderCoreService::markPaid en PHP).
-- transaction está particionada desde mig 156 (RANGE por transactiondate).
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS channel text NOT NULL DEFAULT 'mostrador';

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conname = 'transaction_channel_allowed' AND conrelid = 'transaction'::regclass
  ) THEN
    ALTER TABLE transaction ADD CONSTRAINT transaction_channel_allowed
      CHECK (channel IN ('mostrador', 'mesa', 'delivery'));
  END IF;
END $$;

-- Backfill: mesa si hay pos_order vinculada (order_transaction_link, mig
-- 115 — pos_order.saletransactionid fue DROPEADA en esa misma migración,
-- no es fuente válida) con source='table' o spacesessionid presente;
-- delivery si source='ecommerce' o la venta tiene toAddress (entrega a
-- domicilio, mig 156 FK toaddress.transactionid); resto queda 'mostrador'
-- (el default).
UPDATE transaction t SET channel = 'mesa'
  FROM order_transaction_link l JOIN pos_order o ON o.orderid = l.orderid
 WHERE l.transactionid = t.transactionid
   AND (o.source = 'table' OR o.spacesessionid IS NOT NULL)
   AND t.channel = 'mostrador';

UPDATE transaction t SET channel = 'delivery'
  FROM order_transaction_link l JOIN pos_order o ON o.orderid = l.orderid
 WHERE l.transactionid = t.transactionid
   AND o.source = 'ecommerce'
   AND t.channel = 'mostrador';

UPDATE transaction t SET channel = 'delivery'
  FROM toaddress ta
 WHERE ta.transactionid = t.transactionid
   AND t.channel = 'mostrador';

-- fn_period_guard (mig 157): channel se escribe con UPDATE DESPUÉS de la
-- venta (OrderCoreService::markPaid, en otra request que la creó) — sin
-- esto, cobrar una orden de mesa/delivery de un período cerrado rompería
-- con 'period_closed' pese a que decisión #1 de mig 157 ya excluye
-- transactioncomplete/updated_at del mismo modo. Mismo criterio: channel
-- es metadata de clasificación, no el hecho económico (montos/fechas/
-- numeración/stock siguen inmutables).
CREATE OR REPLACE FUNCTION fn_period_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
  v_date_col text    := TG_ARGV[0];
  v_mode     text    := TG_ARGV[1];
  v_ts       timestamptz;
  v_company  uuid    := (OLD.companyid);
  v_txtype   int;
  v_period   date;
BEGIN
  IF v_mode = 'plain' THEN
    v_ts := (to_jsonb(OLD) ->> v_date_col)::timestamptz;
    IF period_is_closed(v_company, v_ts) THEN
      v_period := date_trunc('month', v_ts AT TIME ZONE 'America/Asuncion')::date;
      RAISE EXCEPTION 'period_closed'
        USING ERRCODE = 'PC001',
              DETAIL  = format('tabla=%s companyid=%s período=%s', TG_TABLE_NAME, v_company, v_period);
    END IF;

  ELSIF v_mode = 'tx' THEN
    v_ts     := (to_jsonb(OLD) ->> v_date_col)::timestamptz;
    v_txtype := (OLD.transactiontype)::int;
    IF v_txtype = ANY (ARRAY[0,1,3,4,5,6,7,10,14]) AND period_is_closed(v_company, v_ts) THEN
      IF TG_OP = 'UPDATE'
         AND (to_jsonb(OLD) - 'transactioncomplete' - 'updated_at' - 'channel')
           = (to_jsonb(NEW) - 'transactioncomplete' - 'updated_at' - 'channel')
      THEN
        RETURN NEW; -- solo cambió transactioncomplete/updated_at/channel: permitido
      END IF;
      v_period := date_trunc('month', v_ts AT TIME ZONE 'America/Asuncion')::date;
      RAISE EXCEPTION 'period_closed'
        USING ERRCODE = 'PC001',
              DETAIL  = format('tabla=transaction transactionid=%s período=%s', OLD.transactionid, v_period);
    END IF;

  ELSIF v_mode = 'itemsold' THEN
    v_ts := (to_jsonb(OLD) ->> v_date_col)::timestamptz;
    SELECT transactiontype INTO v_txtype
      FROM transaction_registry WHERE transactionid = OLD.transactionid;
    IF v_txtype IS NOT NULL
       AND v_txtype = ANY (ARRAY[0,1,3,4,5,6,7,10,14])
       AND period_is_closed(v_company, v_ts)
    THEN
      v_period := date_trunc('month', v_ts AT TIME ZONE 'America/Asuncion')::date;
      RAISE EXCEPTION 'period_closed'
        USING ERRCODE = 'PC001',
              DETAIL  = format('tabla=itemsold itemsoldid=%s período=%s', OLD.itemsoldid, v_period);
    END IF;
  END IF;

  IF TG_OP = 'DELETE' THEN
    RETURN OLD;
  END IF;
  RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_period_guard() IS
  'E1b (mig 157) + D8 (mig 158). Genérico via TG_ARGV: [0]=columna fecha, '
  '[1]=modo (tx|itemsold|plain). En modo tx, un UPDATE que solo toca '
  'transactioncomplete/updated_at/channel se permite aunque el período esté '
  'cerrado (metadata de clasificación, no el hecho económico). Levanta '
  'SQLSTATE PC001 con mensaje literal ''period_closed''.';

-- ═══════════════════════════════════════════════════════════════════════
-- Tablas del grano diario (context/48 D8, esquema cerrado con el owner
-- 2026-08-21). Sentinel uuid cero = "sin caja"/"sin vendedor" — NULL no
-- puede formar parte de una PK.
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS rollup_sales_day (
  companyid  uuid          NOT NULL,
  day        date          NOT NULL,
  outletid   uuid          NOT NULL,
  registerid uuid          NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
  userid     uuid          NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
  kind       text          NOT NULL CHECK (kind IN ('contado', 'credito', 'devolucion')),
  status     text          NOT NULL CHECK (status IN ('vigente', 'anulada')),
  channel    text          NOT NULL CHECK (channel IN ('mostrador', 'mesa', 'delivery')),
  cnt        bigint        NOT NULL DEFAULT 0,
  units      numeric(18,4) NOT NULL DEFAULT 0,
  gross      numeric(18,4) NOT NULL DEFAULT 0,
  discount   numeric(18,4) NOT NULL DEFAULT 0,
  net        numeric(18,4) NOT NULL DEFAULT 0,
  tax10      numeric(18,4) NOT NULL DEFAULT 0,
  tax5       numeric(18,4) NOT NULL DEFAULT 0,
  exento     numeric(18,4) NOT NULL DEFAULT 0,
  cogs       numeric(18,4) NOT NULL DEFAULT 0,
  updatedat  timestamptz   NOT NULL DEFAULT now(),
  PRIMARY KEY (companyid, day, outletid, registerid, userid, kind, status, channel)
);

CREATE INDEX IF NOT EXISTS idx_rollup_sales_day_company_day ON rollup_sales_day (companyid, day);

COMMENT ON COLUMN rollup_sales_day.gross IS 'Bruto antes de descuento: net + discount (transactiontotal + transactiondiscount).';
COMMENT ON COLUMN rollup_sales_day.net   IS 'Neto cobrado (con IVA, después de descuento) = transactiontotal, verificado contra SaleService::buildTransactionRecord.';
COMMENT ON COLUMN rollup_sales_day.tax10 IS 'SUM(itemsold.itemsoldtax) de líneas con taxrate=10, vía subquery LATERAL por transacción.';
COMMENT ON COLUMN rollup_sales_day.tax5  IS 'SUM(itemsold.itemsoldtax) de líneas con taxrate=5.';
COMMENT ON COLUMN rollup_sales_day.exento IS 'SUM(itemsold.itemsoldtotal) de líneas con taxrate=0 (criterio D8: por tasa, no por taxkind).';
COMMENT ON TABLE  rollup_sales_day IS
  'D8 context/48. Devoluciones (kind=devolucion, transactiontype=6) entran '
  'con signo negativo en todas las métricas — transaction ya las escribe '
  'negativas (ReturnService), no se re-invierte acá. status=anulada '
  '(voidedat/type=7) reemplaza el filtro "excluir anuladas" de mig 155: '
  'antes esas filas NO estaban en el rollup, ahora están pero aisladas por '
  'status — los readers filtran status=''vigente''.';

CREATE TABLE IF NOT EXISTS rollup_item_sales_day (
  companyid    uuid          NOT NULL,
  day          date          NOT NULL,
  outletid     uuid          NOT NULL,
  itemid       uuid          NOT NULL,
  categoryid   uuid          NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
  kind         text          NOT NULL CHECK (kind IN ('contado', 'credito', 'devolucion')),
  status       text          NOT NULL CHECK (status IN ('vigente', 'anulada')),
  qty          numeric(18,4) NOT NULL DEFAULT 0,
  gross        numeric(18,4) NOT NULL DEFAULT 0,
  discount     numeric(18,4) NOT NULL DEFAULT 0,
  net          numeric(18,4) NOT NULL DEFAULT 0,
  tax          numeric(18,4) NOT NULL DEFAULT 0,
  cogs         numeric(18,4) NOT NULL DEFAULT 0,
  cnt          bigint        NOT NULL DEFAULT 0,
  -- Extras del `extra` jsonb viejo (mig 41/155) que NO están en el esquema
  -- D8 pero sí tienen consumer real hoy — preservados como columnas
  -- propias en vez de perderse (comission: CategoriesService; discountflat:
  -- BrandsService, es el discount SIN el *ABS(units) que sí tiene la
  -- columna `discount` de abajo, preservado por compat con el rollup viejo;
  -- cogsabsflat: sin consumer detectado, se preserva igual por si acaso).
  comission    numeric(18,4) NOT NULL DEFAULT 0,
  discountflat numeric(18,4) NOT NULL DEFAULT 0,
  cogsabsflat  numeric(18,4) NOT NULL DEFAULT 0,
  updatedat    timestamptz   NOT NULL DEFAULT now(),
  PRIMARY KEY (companyid, day, outletid, itemid, categoryid, kind, status)
);

CREATE INDEX IF NOT EXISTS idx_rollup_item_sales_day_item     ON rollup_item_sales_day (companyid, itemid, day);
CREATE INDEX IF NOT EXISTS idx_rollup_item_sales_day_category ON rollup_item_sales_day (companyid, categoryid, day);

COMMENT ON COLUMN rollup_item_sales_day.discount IS
  'SUM(itemsolddiscount * ABS(itemsoldunits)) — preserva EXACTO el cómputo '
  '(con lo que probablemente sea un bug de escala: itemsolddiscount ya es '
  'un total de línea, no un valor por-unidad) del dominio item_sales viejo '
  '(mig 41/155) para no romper la paridad numérica de CategoriesService. '
  'discountflat de abajo es el valor plano correcto (SUM(itemsolddiscount), '
  'sin multiplicar) — es lo que consume BrandsService. ABS(itemsoldunits) '
  'en vez de itemsoldunits crudo: dan el MISMO resultado para ventas '
  '(unidades positivas) pero corrige el signo para devoluciones (unidades Y '
  'costo unitario vienen AMBOS negados por flipOnReturn — multiplicar los '
  'dos negativos daría un discount positivo, rompiendo la resta que D8 '
  'espera de las devoluciones).';
COMMENT ON COLUMN rollup_item_sales_day.cogs IS
  'SUM(itemsoldcogs * ABS(itemsoldunits)) — mismo criterio de ABS(units) '
  'que discount, por la misma razón (itemsoldcogs es costo POR UNIDAD y '
  'viene negado en devoluciones; sin el ABS el producto de dos negativos '
  'da cogs positivo en una fila que debe restar).';
COMMENT ON COLUMN rollup_item_sales_day.net IS
  'itemsoldtotal - itemsolddiscount por línea (económicamente correcto, '
  'sin el *ABS(units) de discount/cogs) — sin consumer previo, definido '
  'limpio para D8 desde cero.';

CREATE TABLE IF NOT EXISTS rollup_payments_day (
  companyid  uuid          NOT NULL,
  day        date          NOT NULL,
  outletid   uuid          NOT NULL,
  registerid uuid          NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
  method     text          NOT NULL,
  kind       text          NOT NULL CHECK (kind IN ('contado', 'cobro', 'devolucion')),
  amount     numeric(18,4) NOT NULL DEFAULT 0,
  cnt        bigint        NOT NULL DEFAULT 0,
  updatedat  timestamptz   NOT NULL DEFAULT now(),
  PRIMARY KEY (companyid, day, outletid, registerid, method, kind)
);

CREATE INDEX IF NOT EXISTS idx_rollup_payments_day_company_day ON rollup_payments_day (companyid, day);

COMMENT ON TABLE rollup_payments_day IS
  'D8 context/48. method = COALESCE(elem->>''type'', elem->>''name'', '
  '''sin_especificar'') en minúsculas/trim — mismo fallback que '
  'groupByPaymentMethod() (api/includes/functions.php:967) para no divergir '
  'del reporte live. amount = ABS(price) si price>0, si no ABS(total) — '
  'IDEM groupByPaymentMethod (api/includes/functions.php:958-966): ambos '
  'campos se toman en valor absoluto ahí, por eso acá también (si no, '
  'PaymentMethodsService dejaría de matchear la rama live byte a byte). '
  'Anuladas (voidedat) EXCLUIDAS — no hubo plata real. kind=devolucion '
  '(transactiontype=6) queda en la tabla para consumers futuros '
  '(context/47) pero RollupReader::paymentsRange lo excluye a propósito '
  '(PaymentMethodsService nunca leyó pagos de devoluciones).';

-- ═══════════════════════════════════════════════════════════════════════
-- rollup_recompute_period — CREATE OR REPLACE completo. sales/item_sales/
-- payments: SOLO grano día (nada de bucket month/year — D8 los deriva con
-- SUM). returns/item_returns: eliminados, absorbidos por kind=devolucion.
-- expenses/drawer_expenses: EXACTAMENTE igual que mig 155 (E2 los migra).
-- ═══════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION rollup_recompute_period(p_company uuid, p_domain text, p_day date)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
  v_month_start date := date_trunc('month', p_day)::date;
  v_month_end   date := (date_trunc('month', p_day) + interval '1 month')::date;
  v_year_start  date := date_trunc('year',  p_day)::date;
  v_year_end    date := (date_trunc('year',  p_day) + interval '1 year')::date;
  v_sentinel    uuid := '00000000-0000-0000-0000-000000000000';
BEGIN
  -- ── sales (grano día, D8) ──
  IF p_domain = 'sales' THEN
    DELETE FROM rollup_sales_day WHERE companyid = p_company AND day = p_day;
    INSERT INTO rollup_sales_day (
      companyid, day, outletid, registerid, userid, kind, status, channel,
      cnt, units, gross, discount, net, tax10, tax5, exento, cogs, updatedat
    )
    SELECT
      t.companyid, p_day, t.outletid,
      COALESCE(t.registerid, v_sentinel),
      t.userid,
      CASE t.transactiontype WHEN 0 THEN 'contado' WHEN 3 THEN 'credito' WHEN 6 THEN 'devolucion' END,
      CASE WHEN t.voidedat IS NOT NULL OR t.transactiontype = 7 THEN 'anulada' ELSE 'vigente' END,
      t.channel,
      COUNT(*),
      COALESCE(SUM(t.transactionunitssold), 0),
      COALESCE(SUM(t.transactiontotal + t.transactiondiscount), 0),
      COALESCE(SUM(t.transactiondiscount), 0),
      COALESCE(SUM(t.transactiontotal), 0),
      COALESCE(SUM(ib.tax10), 0),
      COALESCE(SUM(ib.tax5), 0),
      COALESCE(SUM(ib.exento), 0),
      COALESCE(SUM(ib.cogs), 0),
      now()
    FROM transaction t
    LEFT JOIN LATERAL (
      SELECT
        SUM(a.itemsoldtax)   FILTER (WHERE a.taxrate = 10) AS tax10,
        SUM(a.itemsoldtax)   FILTER (WHERE a.taxrate = 5)  AS tax5,
        SUM(a.itemsoldtotal) FILTER (WHERE a.taxrate = 0)  AS exento,
        SUM(a.itemsoldcogs * ABS(a.itemsoldunits))         AS cogs
      FROM itemsold a
      WHERE a.transactionid = t.transactionid
    ) ib ON true
    WHERE t.transactiontype IN (0, 3, 6) AND t.companyid = p_company
      AND t.transactiondate >= p_day AND t.transactiondate < p_day + 1
    -- GROUP BY la EXPRESIÓN de status, no t.voidedat crudo: dos filas
    -- anuladas con voidedat DISTINTO deben caer en el MISMO grupo
    -- ('anulada') — agrupar por la columna cruda las separaba en filas
    -- distintas con la misma PK (companyid,day,outlet,register,user,kind,
    -- status,channel) y el INSERT fallaba por duplicate key.
    GROUP BY t.companyid, t.outletid, t.registerid, t.userid, t.transactiontype,
             (CASE WHEN t.voidedat IS NOT NULL OR t.transactiontype = 7 THEN 'anulada' ELSE 'vigente' END),
             t.channel;

  -- ── item_sales (grano día, D8) ──
  ELSIF p_domain = 'item_sales' THEN
    DELETE FROM rollup_item_sales_day WHERE companyid = p_company AND day = p_day;
    INSERT INTO rollup_item_sales_day (
      companyid, day, outletid, itemid, categoryid, kind, status,
      qty, gross, discount, net, tax, cogs, cnt, comission, discountflat, cogsabsflat, updatedat
    )
    SELECT
      b.companyid, p_day, b.outletid, a.itemid,
      COALESCE(a.itemsoldcategory, v_sentinel),
      CASE b.transactiontype WHEN 0 THEN 'contado' WHEN 3 THEN 'credito' WHEN 6 THEN 'devolucion' END,
      CASE WHEN b.voidedat IS NOT NULL OR b.transactiontype = 7 THEN 'anulada' ELSE 'vigente' END,
      COALESCE(SUM(a.itemsoldunits), 0),
      COALESCE(SUM(a.itemsoldtotal), 0),
      COALESCE(SUM(a.itemsolddiscount * ABS(a.itemsoldunits)), 0),
      COALESCE(SUM(a.itemsoldtotal - a.itemsolddiscount), 0),
      COALESCE(SUM(a.itemsoldtax), 0),
      COALESCE(SUM(a.itemsoldcogs * ABS(a.itemsoldunits)), 0),
      COUNT(*),
      COALESCE(SUM(a.itemsoldcomission), 0),
      COALESCE(SUM(a.itemsolddiscount), 0),
      COALESCE(SUM(ABS(a.itemsoldcogs)), 0),
      now()
    FROM itemsold a
    JOIN transaction b ON a.transactionid = b.transactionid
    WHERE b.transactiontype IN (0, 3, 6) AND b.companyid = p_company
      AND b.transactiondate >= p_day AND b.transactiondate < p_day + 1
    -- Mismo criterio que la rama 'sales': GROUP BY la EXPRESIÓN de status,
    -- no b.voidedat crudo (ver comentario gemelo arriba).
    GROUP BY b.companyid, b.outletid, a.itemid, COALESCE(a.itemsoldcategory, v_sentinel),
             b.transactiontype,
             (CASE WHEN b.voidedat IS NOT NULL OR b.transactiontype = 7 THEN 'anulada' ELSE 'vigente' END);

  -- ── payments (grano día, D8) ──
  ELSIF p_domain = 'payments' THEN
    DELETE FROM rollup_payments_day WHERE companyid = p_company AND day = p_day;
    INSERT INTO rollup_payments_day (companyid, day, outletid, registerid, method, kind, amount, cnt, updatedat)
      SELECT
        t.companyid, p_day, t.outletid,
        COALESCE(t.registerid, v_sentinel),
        COALESCE(NULLIF(lower(trim(elem->>'type')), ''), NULLIF(lower(trim(elem->>'name')), ''), 'sin_especificar'),
        CASE t.transactiontype WHEN 0 THEN 'contado' WHEN 5 THEN 'cobro' WHEN 6 THEN 'devolucion' END,
        SUM(
          CASE WHEN ABS(COALESCE((elem->>'price')::numeric, 0)) > 0
               THEN ABS((elem->>'price')::numeric)
               ELSE ABS(COALESCE((elem->>'total')::numeric, 0))
          END
        ),
        COUNT(*),
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
      WHERE t.transactiontype IN (0, 5, 6)
        AND t.companyid = p_company
        AND t.transactiondate >= p_day AND t.transactiondate < p_day + 1
        AND t.voidedat IS NULL
      GROUP BY t.companyid, t.outletid, t.registerid, t.transactiontype,
               COALESCE(NULLIF(lower(trim(elem->>'type')), ''), NULLIF(lower(trim(elem->>'name')), ''), 'sin_especificar');

  -- ── expenses (day/month/year) — EXACTO mig 155, sin cambios ──
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

  -- ── drawer_expenses (day/month/year) — EXACTO mig 155, sin cambios ──
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
  END IF;
END;
$$;

COMMENT ON FUNCTION rollup_recompute_period(uuid, text, date) IS
  'D8 context/48 (mig 158). sales/item_sales/payments: grano día único en '
  'rollup_sales_day/rollup_item_sales_day/rollup_payments_day — mes/año se '
  'derivan con SUM sobre day, no se almacenan. returns/item_returns '
  'absorbidos (kind=devolucion). expenses/drawer_expenses: sin cambios '
  '(report_rollup, día/mes/año), se migran en E2.';

-- period_close_run (mig 157): dominios vigentes tras esta migración.
CREATE OR REPLACE FUNCTION period_close_run(p_company uuid, p_period date, p_by uuid, p_source text)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
  v_period    date := date_trunc('month', p_period)::date;
  v_month_end date := (v_period + interval '1 month')::date;
  v_domains   text[] := ARRAY['sales', 'item_sales', 'payments', 'expenses', 'drawer_expenses'];
  v_domain    text;
BEGIN
  IF p_source NOT IN ('job', 'manual') THEN
    RAISE EXCEPTION 'period_close_run: source inválido % (esperado job|manual)', p_source;
  END IF;

  INSERT INTO period_close (companyid, period, closedat, closedby, source)
    VALUES (p_company, v_period, now(), p_by, p_source)
  ON CONFLICT (companyid, period) DO NOTHING;

  FOREACH v_domain IN ARRAY v_domains LOOP
    INSERT INTO rollup_dirty (companyid, domain, periodday)
      SELECT p_company, v_domain, d::date
        FROM generate_series(v_period, v_month_end - interval '1 day', interval '1 day') AS d
    ON CONFLICT (companyid, domain, periodday) DO NOTHING;
  END LOOP;
END;
$$;

COMMENT ON FUNCTION period_close_run(uuid, date, uuid, text) IS
  'E1b (mig 157) + D8 (mig 158). Dominios vigentes tras el grano diario: '
  'sales/item_sales/payments (rollup_*_day) + expenses/drawer_expenses '
  '(report_rollup, sin migrar). returns/item_returns quitados — absorbidos '
  'por kind=devolucion.';

-- ═══════════════════════════════════════════════════════════════════════
-- Limpieza del rollup viejo + backfill de rollup_dirty para recomputar
-- todo desde cero con las funciones ya reescritas.
-- ═══════════════════════════════════════════════════════════════════════

DELETE FROM report_rollup WHERE domain IN ('sales', 'returns', 'item_sales', 'item_returns', 'payments');

INSERT INTO rollup_dirty (companyid, domain, periodday)
  SELECT DISTINCT companyid, d.domain, date_trunc('day', transactiondate)::date
  FROM transaction, LATERAL (VALUES ('sales'), ('item_sales'), ('payments')) AS d(domain)
  WHERE transactiontype IN (0, 3, 5, 6, 7)
ON CONFLICT DO NOTHING;

COMMIT;
