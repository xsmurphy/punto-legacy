-- 157_period_close.sql
-- E1b de context/48-escalamiento-de-datos.md (D7): cierre de período —
-- lo pasado un corte es inmutable. Decisiones cerradas por el owner
-- 2026-08-22 (ver context/48 §D7 y el brief de esta sesión):
--
--   1. Inmutable = el hecho económico (montos, ítems, fechas, cliente,
--      numeración, stock). Mutable después del cierre:
--      transaction.transactioncomplete y transaction.updated_at (un cobro
--      de hoy salda una factura vieja; una NC de hoy se cuelga vía
--      transaction_link sin tocar la fila vieja).
--   2. Alcance por tipo: aplica a filas de `transaction` con
--      transactiontype IN (0,1,3,4,5,6,7,10,14) — ver
--      api/lib/Sales/SaleType.php (ventas contado/crédito, compras,
--      cobros, devolución, anulada, remisión, NC de compra). Tipos
--      operativos 2,8,9,11,12,13 (guardada, recurrente, presupuesto,
--      mesa, orden, agenda) quedan FUERA del guard. Para `itemsold` el
--      tipo se resuelve por `transaction_registry.transactiontype` (mig
--      156). `stock`, `cpayments`, `expenses` siempre bajo el guard (son
--      hechos económicos por definición, sin tipo que filtrar).
--   3. INSERT nunca se bloquea (offline-first: el back jamás rechaza una
--      venta ya emitida). El guard es BEFORE UPDATE OR DELETE únicamente.
--      Una fila nueva con fecha en período cerrado entra igual y
--      rollupMarkDirty la encola → ese mes se recalcula.
--   4. Ventana abierta: mes en curso + N meses anteriores, default N=1 (2
--      meses totales), configurable por tenant en
--      `company.config->>'settingPeriodCloseMonths'`. Nota de
--      implementación: el brief original decía "mismo blob que
--      settingReturnRefund vía settingObj", pero settingReturnRefund en
--      realidad vive como key TOP-LEVEL de `config` (no anidado en
--      settingObj) — ver `api/v1/bootstrap.php:65`
--      (`config->>'settingReturnRefund'`) y `SettingsService::general()`
--      (lee `$r['settingReturnRefund']` directo, no `$obj[...]`). Se
--      sigue ese precedente real, no la referencia textual del brief.
--
-- Zona horaria: la sesión de PG en este proyecto siempre corre en
-- 'America/Asuncion' (SET TIME ZONE en api/includes/db.php), y el rollup
-- (mig 41/42) trunca día/mes/año confiando en ese ambient session TZ. Acá
-- se hace explícito (`AT TIME ZONE 'America/Asuncion'`) para que
-- period_is_closed() dé el mismo resultado sin importar la sesión que la
-- invoque (criterio robusto, mismo principio que TenantClock /
-- DrawerService::§ comentario sobre AT TIME ZONE explícito) — el
-- resultado coincide exactamente con el corte que usa el rollup porque
-- la sesión de la API SIEMPRE está en esa misma zona.

BEGIN;

-- ═══════════════════════════════════════════════════════════════════════
-- 1. Tabla period_close
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE period_close (
  companyid uuid        NOT NULL,
  period    date        NOT NULL CHECK (period = date_trunc('month', period)::date),
  closedat  timestamptz NOT NULL DEFAULT now(),
  closedby  uuid        NULL, -- NULL cuando cierra el job (source='job')
  source    text        NOT NULL CHECK (source IN ('job', 'manual')),
  PRIMARY KEY (companyid, period)
);

COMMENT ON TABLE period_close IS
  'E1b, context/48 D7: meses cerrados por tenant. Un mes en esta tabla '
  'hace inmutables (vía fn_period_guard) las filas económicas de ese mes '
  'en transaction/itemsold/stock/cpayments/expenses. No hay "reabrir" '
  '(fuera de alcance de este plan).';

-- ═══════════════════════════════════════════════════════════════════════
-- 2. period_is_closed(company, ts) — criterio único de corte de mes
-- ═══════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION period_is_closed(p_company uuid, p_ts timestamptz)
RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT EXISTS (
    SELECT 1 FROM period_close
     WHERE companyid = p_company
       AND period = date_trunc('month', p_ts AT TIME ZONE 'America/Asuncion')::date
  );
$$;

COMMENT ON FUNCTION period_is_closed(uuid, timestamptz) IS
  'true si p_ts cae en un mes ya cerrado para p_company. Trunca en '
  'America/Asuncion explícito — coincide con el corte de mes que usa el '
  'rollup (mig 41) porque la sesión de PG del API siempre corre en esa '
  'misma zona (api/includes/db.php).';

-- ═══════════════════════════════════════════════════════════════════════
-- 3. fn_period_guard() — trigger genérico BEFORE UPDATE OR DELETE
--    TG_ARGV[0] = columna de fecha (ej. 'transactiondate')
--    TG_ARGV[1] = modo: 'tx' | 'itemsold' | 'plain'
-- ═══════════════════════════════════════════════════════════════════════

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
    -- stock / cpayments / expenses: siempre bajo el guard, sin filtro de tipo.
    v_ts := (to_jsonb(OLD) ->> v_date_col)::timestamptz;
    IF period_is_closed(v_company, v_ts) THEN
      v_period := date_trunc('month', v_ts AT TIME ZONE 'America/Asuncion')::date;
      RAISE EXCEPTION 'period_closed'
        USING ERRCODE = 'PC001',
              DETAIL  = format('tabla=%s companyid=%s período=%s', TG_TABLE_NAME, v_company, v_period);
    END IF;

  ELSIF v_mode = 'tx' THEN
    -- transaction: filtra por tipo económico (decisión #2). En UPDATE, se
    -- permite si las ÚNICAS columnas que cambian son transactioncomplete
    -- y/o updated_at (decisión #1). DELETE siempre bloqueado si aplica.
    v_ts     := (to_jsonb(OLD) ->> v_date_col)::timestamptz;
    v_txtype := (OLD.transactiontype)::int;
    IF v_txtype = ANY (ARRAY[0,1,3,4,5,6,7,10,14]) AND period_is_closed(v_company, v_ts) THEN
      IF TG_OP = 'UPDATE'
         AND (to_jsonb(OLD) - 'transactioncomplete' - 'updated_at')
           = (to_jsonb(NEW) - 'transactioncomplete' - 'updated_at')
      THEN
        RETURN NEW; -- solo cambió transactioncomplete y/o updated_at: permitido
      END IF;
      v_period := date_trunc('month', v_ts AT TIME ZONE 'America/Asuncion')::date;
      RAISE EXCEPTION 'period_closed'
        USING ERRCODE = 'PC001',
              DETAIL  = format('tabla=transaction transactionid=%s período=%s', OLD.transactionid, v_period);
    END IF;

  ELSIF v_mode = 'itemsold' THEN
    -- itemsold no tiene transactiontype propio: se resuelve por
    -- transaction_registry (mig 156). UPDATE/DELETE siempre bloqueados
    -- (no hay excepción tipo transactioncomplete acá).
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
  'E1b, context/48 D7. Genérico via TG_ARGV: [0]=columna fecha, '
  '[1]=modo (tx|itemsold|plain). Levanta SQLSTATE PC001 con mensaje '
  'literal ''period_closed'' — api/includes/lib/DB.php::Execute() lo '
  'detecta (único choke point de escritura) y lo convierte en '
  'PeriodClosedException; el mapeo a HTTP 409 vive en api/bootstrap.php.';

-- ═══════════════════════════════════════════════════════════════════════
-- 4. Triggers — se crean sobre las tablas PADRE. transaction/itemsold
--    están particionadas desde mig 156: un trigger row-level en el padre
--    se propaga automáticamente a todas las particiones existentes y
--    futuras (Postgres >= 11), no hace falta repetirlo por partición.
-- ═══════════════════════════════════════════════════════════════════════

CREATE TRIGGER trg_period_guard_transaction
  BEFORE UPDATE OR DELETE ON transaction
  FOR EACH ROW EXECUTE FUNCTION fn_period_guard('transactiondate', 'tx');

CREATE TRIGGER trg_period_guard_itemsold
  BEFORE UPDATE OR DELETE ON itemsold
  FOR EACH ROW EXECUTE FUNCTION fn_period_guard('itemsolddate', 'itemsold');

CREATE TRIGGER trg_period_guard_stock
  BEFORE UPDATE OR DELETE ON stock
  FOR EACH ROW EXECUTE FUNCTION fn_period_guard('stockdate', 'plain');

CREATE TRIGGER trg_period_guard_cpayments
  BEFORE UPDATE OR DELETE ON cpayments
  FOR EACH ROW EXECUTE FUNCTION fn_period_guard('cpaymentsdate', 'plain');

CREATE TRIGGER trg_period_guard_expenses
  BEFORE UPDATE OR DELETE ON expenses
  FOR EACH ROW EXECUTE FUNCTION fn_period_guard('expensesdate', 'plain');

-- ═══════════════════════════════════════════════════════════════════════
-- 5. period_close_run(company, period, by, source) — ejecuta el cierre
-- ═══════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION period_close_run(p_company uuid, p_period date, p_by uuid, p_source text)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
  v_period    date := date_trunc('month', p_period)::date;
  v_month_end date := (v_period + interval '1 month')::date;
  -- Mismo set de dominios que rollup_recompute_period (mig 41/42) —
  -- ver grep "rollupMarkDirty(" en api/lib. Hardcodeado a propósito: si
  -- aparece un dominio nuevo hay que sumarlo acá también.
  v_domains   text[] := ARRAY['sales', 'expenses', 'returns', 'drawer_expenses',
                               'item_sales', 'item_returns', 'payments'];
  v_domain    text;
BEGIN
  IF p_source NOT IN ('job', 'manual') THEN
    RAISE EXCEPTION 'period_close_run: source inválido % (esperado job|manual)', p_source;
  END IF;

  INSERT INTO period_close (companyid, period, closedat, closedby, source)
    VALUES (p_company, v_period, now(), p_by, p_source)
  ON CONFLICT (companyid, period) DO NOTHING;

  -- Encola TODOS los días del mes para TODOS los dominios: rollup-reconcile
  -- (mig 41, ya corre en el cron) lo procesa y deja el rollup definitivo.
  -- NO se borra ni se bloquea rollup_dirty para meses cerrados (decisión
  -- #3): si algo entra después vía INSERT, se re-encola solo y este mes
  -- vuelve a pasar por acá en el próximo período_close_run... no, el mes
  -- YA cerrado no vuelve a cerrarse, pero SÍ puede seguir recibiendo
  -- INSERTs (nunca bloqueados) que marcan rollup_dirty normalmente — eso
  -- es responsabilidad de rollupMarkDirty en cada INSERT, no de esta
  -- función.
  FOREACH v_domain IN ARRAY v_domains LOOP
    INSERT INTO rollup_dirty (companyid, domain, periodday)
      SELECT p_company, v_domain, d::date
        FROM generate_series(v_period, v_month_end - interval '1 day', interval '1 day') AS d
    ON CONFLICT (companyid, domain, periodday) DO NOTHING;
  END LOOP;
END;
$$;

COMMENT ON FUNCTION period_close_run(uuid, date, uuid, text) IS
  'E1b, context/48 D7. Inserta el cierre (idempotente) y re-encola el mes '
  'completo en rollup_dirty para las 7 domains conocidas — el rollup '
  'queda definitivo la próxima vez que corra rollup-reconcile.';

-- ═══════════════════════════════════════════════════════════════════════
-- 6. period_close_due(months) — meses vencidos de cierre automático
-- ═══════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION period_close_due(p_months int DEFAULT 1)
RETURNS TABLE(companyid uuid, period date) LANGUAGE plpgsql AS $$
DECLARE
  v_cutoff date := date_trunc('month', now() AT TIME ZONE 'America/Asuncion')::date;
BEGIN
  RETURN QUERY
  SELECT DISTINCT t.companyid, m.period
  FROM transaction t
  JOIN company c ON c.companyid = t.companyid
  CROSS JOIN LATERAL (
    SELECT date_trunc('month', t.transactiondate AT TIME ZONE 'America/Asuncion')::date AS period
  ) m
  CROSS JOIN LATERAL (
    -- Ventana propia del tenant si está configurada (settingPeriodCloseMonths,
    -- 1..12), si no el default del parámetro p_months (también clampeado).
    SELECT LEAST(GREATEST(COALESCE((c.config ->> 'settingPeriodCloseMonths')::int, p_months), 1), 12) AS months
  ) cfg
  WHERE t.transactiontype = ANY (ARRAY[0,1,3,4,5,6,7,10,14])
    AND m.period < (v_cutoff - (cfg.months || ' months')::interval)::date
    AND NOT EXISTS (
      SELECT 1 FROM period_close pc
       WHERE pc.companyid = t.companyid AND pc.period = m.period
    );
END;
$$;

COMMENT ON FUNCTION period_close_due(int) IS
  'E1b, context/48 D7. Meses con transacciones económicas, fuera de la '
  'ventana abierta del tenant (o del default p_months si no configuró), '
  'y aún no cerrados. Usado por el job period-close (maintenance.php).';

COMMIT;
