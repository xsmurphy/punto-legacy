-- 25_contact_jsonb_demote.sql
-- Degrada columnas no-indexables / no-queryables de `contact` al JSONB `data`,
-- y ELIMINA `contactPhone2` (decisión de producto 2026-06-13: el segundo
-- teléfono casi nunca se usa y agrega ruido al listado y al form).
--
-- Mismo criterio que Migración 14 (outlet): solo datos descriptivos sin
-- índice, sin uso en WHERE/JOIN crítico, sin participación en cálculos SQL.
-- Las que tienen índice (contactName, contactEmail, contactPhone, contactTIN,
-- contactStatus) y los timestamps de auditoría se mantienen como columnas.
--
-- ⚠️ SOLAPAMIENTO CON MIGRACIÓN 06 (fix 2026-06-13):
--   La Migración 06 ya degradó+dropeó 6 de estas columnas (contactAddress,
--   contactAddress2, contactNote, contactCity, contactLocation, contactCountry).
--   En BDs donde la 06 ya corrió (prod), esas columnas NO existen → un UPDATE/
--   ALTER estático que las referencie falla al parsear con "column does not
--   exist" y aborta el boot del container (deploy roto — causa real del bug
--   2026-06-13). En BDs fresh el bootstrap de migrate.php SALTEA la 06 (marca
--   01-13 como already-applied al detectar outletAddress), por lo que ahí esas
--   columnas SÍ existen.
--   Para cubrir ambos casos esta migración es existence-aware: construye el
--   backfill y los DROP dinámicamente, solo sobre las columnas que existan.
--
-- Columnas degradadas al JSONB `data` (si existen):
--   contactSecondName, contactCI, contactBirthDay
--   (+ contactAddress/Address2/Note/City/Location/Country en BDs donde la 06
--    no corrió — en prod ya están en `data` desde la 06)
--
-- Columnas eliminadas:
--   contactPhone2 — segundo teléfono (deprecado, sin backfill: dato muerto)
--
-- ⚠️ DEPLOY COORDINADO con cambios de código (ver session log 2026-06-13):
--   1. _getTableSchema() 'contact' en app/ y panel/: quitar las columnas
--      demoted del whitelist + contactPhone2.
--   2. ContactRepository: search/findByCI usan (data->>'contactCI') etc.
--   3. Frontend frontend: tipo Contact + form + listado sin phone2.
--
-- ⚠️ PRIVILEGIOS: ALTER TABLE ... DROP COLUMN requiere ser OWNER de la tabla.
--
-- Idempotente: existence-aware + IF EXISTS. Reaplicar es no-op.

BEGIN;

DO $migrate25$
DECLARE
    -- Columnas de texto a degradar: NULLIF(col,'') → JSONB. Algunas pueden no
    -- existir si la Migración 06 ya las degradó (prod) — se saltean.
    text_cols  text[] := ARRAY[
        'contactSecondName','contactAddress','contactAddress2','contactNote',
        'contactCity','contactLocation','contactCountry','contactCI'
    ];
    col        text;
    build_args text := '';
    where_cond text := '';
BEGIN
    -- 1. Construir el backfill solo con las columnas de texto que existen.
    --    %L = literal (la key camelCase del JSONB), %I = identificador (la
    --    columna real, en minúsculas porque PG fold'ea identifiers sin comillas).
    FOREACH col IN ARRAY text_cols LOOP
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_name = 'contact' AND table_schema = current_schema()
              AND lower(column_name) = lower(col)
        ) THEN
            build_args := build_args || format('%L, NULLIF(%I, %L), ', col, lower(col), '');
            where_cond := where_cond || format('%I IS NOT NULL OR ', lower(col));
        END IF;
    END LOOP;

    -- contactBirthDay es DATE → to_char en vez de NULLIF(,'').
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'contact' AND table_schema = current_schema()
          AND lower(column_name) = 'contactbirthday'
    ) THEN
        build_args := build_args || format('%L, to_char(%I, %L), ', 'contactBirthDay', 'contactbirthday', 'YYYY-MM-DD');
        where_cond := where_cond || format('%I IS NOT NULL OR ', 'contactbirthday');
    END IF;

    -- 2. Backfill + drop si quedó al menos una columna por degradar.
    IF build_args <> '' THEN
        build_args := left(build_args, -2);   -- saca el ', ' final
        where_cond := left(where_cond, -4);   -- saca el ' OR ' final

        EXECUTE format(
            'UPDATE contact SET data = COALESCE(data, ''{}''::jsonb) || '
            || 'jsonb_strip_nulls(jsonb_build_object(%s)) WHERE %s',
            build_args, where_cond
        );

        -- Drop de las columnas degradadas (IF EXISTS → no-op para las ausentes).
        FOREACH col IN ARRAY text_cols LOOP
            EXECUTE format('ALTER TABLE contact DROP COLUMN IF EXISTS %I', lower(col));
        END LOOP;
        EXECUTE 'ALTER TABLE contact DROP COLUMN IF EXISTS contactBirthDay';
    END IF;

    -- 3. contactPhone2: dato muerto, drop sin backfill (idempotente).
    EXECUTE format('ALTER TABLE contact DROP COLUMN IF EXISTS %I', 'contactphone2');
END
$migrate25$;

COMMIT;
