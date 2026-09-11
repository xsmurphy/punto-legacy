-- 221_partition_range_para_historico.sql
-- Particiones mensuales POR RANGO EXPLÍCITO, para el import de histórico
-- (context/77 §17, F2 del migrador ENCOM).
--
-- ── El problema, verificado empíricamente contra Postgres real ──────────
-- `transaction` e `itemsold` están particionadas por rango mensual (mig 156)
-- y tienen partición DEFAULT. Un INSERT con fecha vieja NO falla: cae en la
-- DEFAULT. Eso es correcto para el caso que la mig 156 estaba cubriendo —la
-- regla offline-first, que el back nunca rechace una venta ya emitida— pero
-- deja al import de histórico en el peor lugar posible:
--
--   `ensure_month_partitions()` arranca su cobertura en el mes de la
--   partición mensual MÁS VIEJA YA CREADA y, a propósito, NO se deja empujar
--   hacia atrás por los datos (comentario de la mig 156: una fecha basura no
--   debe poder forzar la creación de años de particiones vacías).
--
-- O sea que un año de ventas importadas caería entero en la DEFAULT y se
-- quedaría ahí PARA SIEMPRE: ninguna corrida futura del cron las reclasifica,
-- y toda consulta por un mes viejo termina escaneando la DEFAULT. El
-- particionado (E1 de context/48) queda anulado justo para el comercio que
-- más filas trajo.
--
-- ── Por qué esto NO contradice a la mig 156 ─────────────────────────────
-- Son dos casos distintos que hasta ahora compartían mecanismo:
--
--   · Una fecha VIEJA SUELTA que aparece sola (un tipeo, una fecha basura):
--     no debe mover la cobertura. Sigue igual — `ensure_month_partitions()`
--     no cambió de semántica ni de resultado.
--   · Un RANGO CONOCIDO Y ACOTADO que un operador pidió importar a
--     sabiendas: sí debe crear sus meses, porque no es un accidente sino el
--     trabajo que se mandó a hacer.
--
-- Lo que se agrega es la segunda puerta, explícita y con tope. La primera no
-- se toca.
--
-- ── Y por qué un refactor y no una función nueva al lado ────────────────
-- Crear las particiones de un mes NO es un `CREATE TABLE`: si la DEFAULT ya
-- tiene filas de ese mes hay que dropear las FK que apuntan al padre,
-- DETACH de la default (con `lock_timeout` acotado para no colgar al
-- cajero), mover las filas, re-attach y recrear las FK. Copiar esa danza en
-- una segunda función es garantizar que las dos diverjan en el primer fix
-- que alguien aplique a una sola.
--
-- Así que el CUERPO vive una sola vez, en la función por rango, y
-- `ensure_month_partitions(p_months_ahead)` pasa a ser lo que siempre fue
-- conceptualmente: el cálculo de un rango (desde la cobertura actual hasta
-- now() + N meses) delegando en el motor común. Sus callers —la mig 156 y el
-- job `partition-ensure` de `api/v1/maintenance.php`— no cambian ni de firma
-- ni de comportamiento.

BEGIN;

-- ═══════════════════════════════════════════════════════════════════════
-- 1. El motor: crear las particiones mensuales de un RANGO explícito
-- ═══════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION ensure_month_partitions_range(
  p_table  regclass,
  p_column name,
  p_from   date,
  p_to     date
)
RETURNS text[] LANGUAGE plpgsql AS $$
DECLARE
  v_schema        text;
  v_bare_name     text;
  v_default_name  text;
  v_default_exists boolean;
  v_start_month   date;
  v_end_month     date;
  v_month         date;
  v_part_name     text;
  v_created       text[] := ARRAY[]::text[];
  v_rows_in_range bigint;
  v_fk            RECORD;
  v_fk_defs       text[];
  v_fk_def        text;
  v_months        int;
  -- Límites del mes como literal con zona EXPLÍCITA. Ver el bloque de abajo.
  v_from_lit      text;
  v_to_lit        text;
BEGIN
  IF p_from IS NULL OR p_to IS NULL THEN
    RAISE EXCEPTION 'ensure_month_partitions_range: p_from y p_to son obligatorios';
  END IF;

  v_start_month := date_trunc('month', p_from)::date;
  v_end_month   := date_trunc('month', p_to)::date;

  IF v_end_month < v_start_month THEN
    RETURN ARRAY[]::text[];
  END IF;

  -- Tope de cordura. Es la MISMA preocupación que la mig 156 dejó escrita
  -- (que una fecha basura no pueda forzar años de particiones vacías), solo
  -- que acá no se puede resolver ignorando el pedido —el rango es el pedido—
  -- así que se resuelve fallando fuerte. 120 meses = 10 años: mucho más de lo
  -- que cualquier migración real de un comercio necesita, y bastante menos de
  -- lo que una fecha de 1970 mal parseada generaría.
  v_months := (
    (EXTRACT(YEAR FROM v_end_month) - EXTRACT(YEAR FROM v_start_month)) * 12
    + (EXTRACT(MONTH FROM v_end_month) - EXTRACT(MONTH FROM v_start_month))
  )::int;

  IF v_months > 120 THEN
    RAISE EXCEPTION 'ensure_month_partitions_range: el rango pedido abarca % meses (% a %). '
                    'Es casi seguro una fecha mal leida del origen, no un historico real.',
                    v_months, v_start_month, v_end_month;
  END IF;

  SELECT n.nspname, c.relname INTO v_schema, v_bare_name
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
   WHERE c.oid = p_table;

  v_default_name := v_bare_name || '_default';

  SELECT EXISTS (
    SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE c.relname = v_default_name AND n.nspname = v_schema
  ) INTO v_default_exists;
  IF NOT v_default_exists THEN
    EXECUTE format('CREATE TABLE %I PARTITION OF %s DEFAULT', v_default_name, p_table);
  END IF;

  v_month := v_start_month;
  WHILE v_month <= v_end_month LOOP
    v_part_name := v_bare_name || '_y' || to_char(v_month, 'YYYY') || 'm' || to_char(v_month, 'MM');

    -- ── Los límites se anclan en UTC, SIEMPRE ──────────────────────────
    -- Un `date` convertido a `timestamptz` se interpreta en la zona de la
    -- SESIÓN. Las particiones que creó la mig 156 tienen límites en UTC
    -- (`FROM '2026-09-01 00:00:00+00'`), así que crear una nueva desde una
    -- sesión en otra zona la corre unas horas y Postgres la rechaza:
    --
    --   partition "transaction_y2026m08" would overlap "transaction_y2026m09"
    --
    -- No es hipotético: el importador de histórico fija la zona del TENANT
    -- antes de escribir (para que una venta de las 23:30 no cambie de día), y
    -- con America/Asuncion el límite superior de agosto caía 4 horas DENTRO de
    -- septiembre. Lo encontró el arnés.
    --
    -- El mes de una partición es una decisión de ALMACENAMIENTO, no un dato
    -- del negocio: tiene que dar el mismo resultado corra quien corra.
    v_from_lit := to_char(v_month, 'YYYY-MM-DD') || ' 00:00:00+00';
    v_to_lit   := to_char((v_month + interval '1 month')::date, 'YYYY-MM-DD') || ' 00:00:00+00';

    IF NOT EXISTS (
      SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
       WHERE c.relname = v_part_name AND n.nspname = v_schema
    ) THEN
      EXECUTE format(
        'SELECT count(*) FROM %I WHERE %I >= %L AND %I < %L',
        v_default_name, p_column, v_from_lit, p_column, v_to_lit
      ) INTO v_rows_in_range;

      -- Si la default ya tiene filas de este rango, hay que desprenderla
      -- antes de poder declarar la particion mensual explicita (Postgres
      -- no permite crear una partición cuyo rango se superpone con filas
      -- que ya viven en la DEFAULT sin antes desatarla).
      IF v_rows_in_range > 0 THEN
        -- Dropear temporalmente cualquier FK que apunte a p_table (ej.
        -- transaction_registry -> transaction) y recrearla despues del
        -- reattach, con la MISMA definición (pg_get_constraintdef).
        -- Postgres no deja hacer DETACH PARTITION de la default si
        -- CUALQUIER fila ahí -- no solo las del rango que se está por
        -- mover -- sigue referenciada por una FK hacia el padre (probado
        -- en el arnés de la mig 156: "removing partition ... violates
        -- foreign key constraint" con un dato viejo suelto en la default
        -- que la corrida actual no toca). Este DROP/ADD corre dentro de la
        -- misma transacción que todo lo demás, así que nunca queda un
        -- estado sin la FK visible desde afuera.
        v_fk_defs := ARRAY[]::text[];
        FOR v_fk IN
          SELECT conrelid::regclass::text AS tbl, conname AS name, pg_get_constraintdef(oid) AS def
            FROM pg_constraint
           WHERE confrelid = p_table AND contype = 'f'
        LOOP
          v_fk_defs := array_append(v_fk_defs, format('ALTER TABLE %s ADD CONSTRAINT %I %s', v_fk.tbl, v_fk.name, v_fk.def));
          EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', v_fk.tbl, v_fk.name);
        END LOOP;

        -- El DETACH pide ACCESS EXCLUSIVE sobre p_table (transaction/itemsold).
        -- DETACH ... CONCURRENTLY evitaria ese lock pero Postgres NO permite
        -- CONCURRENTLY dentro de una funcion/transaccion (siempre estamos
        -- dentro de una al correr esto), asi que no es una opcion acá. En su
        -- lugar: lock_timeout acotado -- si el POS tiene una transaccion
        -- activa que bloquea el lock, preferimos abortar ESTE mes (recrear
        -- las FK arriba dropeadas y reintentar mas tarde) antes que colgar al
        -- cajero esperando indefinidamente. Ver context/48 D3.
        BEGIN
          SET LOCAL lock_timeout = '5s';
          EXECUTE format('ALTER TABLE %s DETACH PARTITION %I', p_table, v_default_name);
        EXCEPTION WHEN lock_not_available THEN
          FOREACH v_fk_def IN ARRAY v_fk_defs LOOP
            EXECUTE v_fk_def;
          END LOOP;
          RAISE WARNING 'ensure_month_partitions_range: no se pudo tomar el lock de % para desprender % (mes %) -- se salta este mes, se reintenta en la proxima corrida', p_table, v_default_name, v_month;
          v_month := (v_month + interval '1 month')::date;
          CONTINUE;
        END;
      END IF;

      EXECUTE format(
        'CREATE TABLE %I PARTITION OF %s FOR VALUES FROM (%L) TO (%L)',
        v_part_name, p_table, v_from_lit, v_to_lit
      );
      v_created := array_append(v_created, v_part_name);

      IF v_rows_in_range > 0 THEN
        -- Mueve las filas del rango: entran por el padre (ya resuelven a
        -- la particion mensual recien creada), se borran de la copia
        -- desprendida y se vuelve a pegar la default como default.
        --
        -- DISABLE/ENABLE TRIGGER ALL alrededor del DELETE: la copia
        -- desprendida retiene, como tabla independiente, el trigger
        -- interno que implementa cualquier FK "referenced-side" que
        -- apunte al padre (ej. transaction_registry -> transaction ON
        -- DELETE CASCADE) -- sin este guard, el DELETE de acá abajo
        -- cascadearia y borraria la fila de transaction_registry que la
        -- linea de arriba ACABA de reinsertar/sincronizar.
        EXECUTE format(
          'INSERT INTO %s SELECT * FROM %I WHERE %I >= %L AND %I < %L',
          p_table, v_default_name, p_column, v_from_lit, p_column, v_to_lit
        );
        EXECUTE format('ALTER TABLE %I DISABLE TRIGGER ALL', v_default_name);
        EXECUTE format(
          'DELETE FROM %I WHERE %I >= %L AND %I < %L',
          v_default_name, p_column, v_from_lit, p_column, v_to_lit
        );
        EXECUTE format('ALTER TABLE %I ENABLE TRIGGER ALL', v_default_name);
        EXECUTE format('ALTER TABLE %s ATTACH PARTITION %I DEFAULT', p_table, v_default_name);

        -- Recrear las FK dropeadas más arriba (si había alguna). FOREACH
        -- sobre un array vacío no itera (a diferencia de `FOR i IN 1..
        -- array_length(...)`, que explota con NULL cuando el array está
        -- vacío -- array_length() de un array vacío es NULL, no 0).
        FOREACH v_fk_def IN ARRAY v_fk_defs LOOP
          EXECUTE v_fk_def;
        END LOOP;
      END IF;
    END IF;

    v_month := (v_month + interval '1 month')::date;
  END LOOP;

  RETURN v_created;
END;
$$;

COMMENT ON FUNCTION ensure_month_partitions_range(regclass, name, date, date) IS
  'Crea (si faltan) las particiones mensuales de p_table entre p_from y p_to, '
  'inclusive por mes. Es el MOTOR comun: ensure_month_partitions() delega aca. '
  'A diferencia de aquella, SI crea meses hacia atras -- esta pensada para un '
  'rango conocido y acotado que un operador pidio importar (histórico del '
  'migrador, context/77 F2), no para que un dato viejo suelto mueva la '
  'cobertura. Tope duro de 120 meses: un rango mas grande casi siempre es una '
  'fecha mal leida del sistema de origen.';

-- ═══════════════════════════════════════════════════════════════════════
-- 2. La función de siempre, ahora delegando
-- ═══════════════════════════════════════════════════════════════════════
-- MISMA firma, MISMO resultado. Lo único que hace es lo que ya hacía: decidir
-- el rango. El "desde" sigue siendo el mes de la partición más vieja ya
-- creada (o el mínimo real de la columna en el bootstrap, o el mes actual si
-- la tabla está vacía), así que un dato viejo suelto SIGUE sin mover la
-- cobertura hacia atrás.

CREATE OR REPLACE FUNCTION ensure_month_partitions(p_table regclass, p_column name, p_months_ahead int)
RETURNS text[] LANGUAGE plpgsql AS $$
DECLARE
  v_bare_name     text;
  v_earliest_part date;
  v_min_data      timestamptz;
  v_start_month   date;
  v_end_month     date;
BEGIN
  SELECT c.relname INTO v_bare_name FROM pg_class c WHERE c.oid = p_table;

  -- Punto de partida: el mes de la particion mensual mas vieja YA CREADA
  -- (mirando pg_inherits, no los datos). Si todavia no existe ninguna
  -- (bootstrap / primera corrida), se cae al minimo real de la columna
  -- (o al mes actual si la tabla esta vacia). Una vez que la cobertura
  -- mensual arranca en un mes dado, un dato viejo suelto que aparezca
  -- despues (ej. una fecha de 2019 cargada por error) NO empuja el
  -- arranque mas atras -- se queda en la particion default a proposito
  -- (D3 de context/48: esta funcion no debe poder ser forzada a crear
  -- anios de particiones vacias por una fecha basura). El import de
  -- historico, que SI necesita meses hacia atras, no pasa por aca: pide
  -- su rango explicito con ensure_month_partitions_range().
  SELECT min(to_date(regexp_replace(c.relname, '^' || v_bare_name || '_y(\d{4})m(\d{2})$', '\1\2'), 'YYYYMM'))
    INTO v_earliest_part
    FROM pg_inherits i
    JOIN pg_class c ON c.oid = i.inhrelid
   WHERE i.inhparent = p_table
     AND c.relname ~ ('^' || v_bare_name || '_y[0-9]{4}m[0-9]{2}$');

  IF v_earliest_part IS NOT NULL THEN
    v_start_month := v_earliest_part;
  ELSE
    EXECUTE format('SELECT min(%I) FROM %s', p_column, p_table) INTO v_min_data;
    v_start_month := COALESCE(date_trunc('month', v_min_data)::date, date_trunc('month', now())::date);
  END IF;

  v_end_month := date_trunc('month', now() + (p_months_ahead || ' months')::interval)::date;

  RETURN ensure_month_partitions_range(p_table, p_column, v_start_month, v_end_month);
END;
$$;

COMMENT ON FUNCTION ensure_month_partitions(regclass, name, int) IS
  'Crea (si faltan) las particiones mensuales de p_table entre el mes de '
  'la particion mas vieja ya creada (o el minimo real de p_column si '
  'todavia no hay ninguna) y now() + p_months_ahead meses. Desde la mig 221 '
  'delega el trabajo en ensure_month_partitions_range(): lo que decide aca es '
  'SOLO el rango, para que un dato viejo suelto siga sin mover la cobertura '
  'hacia atras. Usada por la mig 156 (bootstrap) y por el job partition-ensure '
  'de api/v1/maintenance.php.';

COMMIT;
