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
-- Columnas demoted al JSONB `data`:
--   contactSecondName  — nombre de persona dentro de empresa (display puro)
--   contactAddress     — dirección texto libre
--   contactAddress2    — dirección 2 (referencia)
--   contactNote        — observaciones internas
--   contactCity        — ciudad
--   contactLocation    — barrio/zona
--   contactCountry     — código de país
--   contactCI          — cédula (a futuro: si se necesita filtrar, queda
--                        accesible vía data->>'contactCI' con GIN index si hace
--                        falta — hoy se consulta vía search del listado)
--   contactBirthDay    — cumpleaños (display + marketing)
--
-- Columnas eliminadas:
--   contactPhone2      — segundo teléfono (deprecado)
--
-- ⚠️ DEPLOY COORDINADO con cambios de código (ver session log 2026-06-13):
--   1. _getTableSchema() 'contact' en app/ y panel/: quitar las 9 columnas
--      demoted del whitelist + contactPhone2. Sin esto, ncmInsert/ncmUpdate
--      siguen intentando escribir columnas que ya no existen → "column does
--      not exist" SQL error en cada save.
--   2. ContactRepository.buildListWhere: el search ahora filtra
--      contactSecondName y contactCI vía (data->>'key') porque las columnas
--      no existen. PG puede usar el GIN index sobre data.
--   3. ContactRepository.findByCI: cambiar `WHERE contactCI = ?` por
--      `WHERE data->>'contactCI' = ?` (o equivalente).
--   4. ContactService.mapToColumns: NO mandar `contactPhone2` al record.
--   5. Frontend panel-next: tipo Contact, form de [id]/page.tsx, hook serialize,
--      y columna del listado contacts/page.tsx — sacar phone2 entero.
--
-- ⚠️ PRIVILEGIOS: ALTER TABLE ... DROP COLUMN requiere ser OWNER de la tabla.
-- En local: el OS user de Postgres.app; en prod: el rol del servicio Postgres
-- gestionado por Coolify.
--
-- Idempotente: backfill usa COALESCE/NULLIF + jsonb_strip_nulls; los
-- DROP COLUMN usan IF EXISTS. Reaplicar es no-op.

BEGIN;

-- 1. Backfill JSONB `data` con valores no vacíos de las columnas demoted.
--    jsonb_strip_nulls limpia keys con NULL para no contaminar el JSONB con
--    valores ausentes. El merge `||` pisa el lado izquierdo con el derecho —
--    correcto: las columnas SQL fueron source-of-truth, ganan sobre cualquier
--    copia previa que haya quedado en `data` durante dual-writes históricos.
UPDATE contact
SET data = COALESCE(data, '{}'::jsonb) || jsonb_strip_nulls(jsonb_build_object(
        'contactSecondName', NULLIF(contactSecondName, ''),
        'contactAddress',    NULLIF(contactAddress,    ''),
        'contactAddress2',   NULLIF(contactAddress2,   ''),
        'contactNote',       NULLIF(contactNote,       ''),
        'contactCity',       NULLIF(contactCity,       ''),
        'contactLocation',   NULLIF(contactLocation,   ''),
        'contactCountry',    NULLIF(contactCountry,    ''),
        'contactCI',         NULLIF(contactCI,         ''),
        -- contactBirthDay es DATE — castear a text para meterlo al JSONB. El
        -- patrón del front (input type=date) ya espera 'YYYY-MM-DD'.
        'contactBirthDay',   to_char(contactBirthDay, 'YYYY-MM-DD')
    ))
WHERE contactSecondName IS NOT NULL
   OR contactAddress    IS NOT NULL
   OR contactAddress2   IS NOT NULL
   OR contactNote       IS NOT NULL
   OR contactCity       IS NOT NULL
   OR contactLocation   IS NOT NULL
   OR contactCountry    IS NOT NULL
   OR contactCI         IS NOT NULL
   OR contactBirthDay   IS NOT NULL;

-- 2. Drop de las columnas demoted (idempotente con IF EXISTS).
ALTER TABLE contact DROP COLUMN IF EXISTS contactSecondName;
ALTER TABLE contact DROP COLUMN IF EXISTS contactAddress;
ALTER TABLE contact DROP COLUMN IF EXISTS contactAddress2;
ALTER TABLE contact DROP COLUMN IF EXISTS contactNote;
ALTER TABLE contact DROP COLUMN IF EXISTS contactCity;
ALTER TABLE contact DROP COLUMN IF EXISTS contactLocation;
ALTER TABLE contact DROP COLUMN IF EXISTS contactCountry;
ALTER TABLE contact DROP COLUMN IF EXISTS contactCI;
ALTER TABLE contact DROP COLUMN IF EXISTS contactBirthDay;

-- 3. DROP contactPhone2 — decisión de producto 2026-06-13. NO se hace
--    backfill al JSONB porque se considera dato muerto: el form ya no lo
--    pide. Si algún tenant pegó valor ahí, se descarta.
ALTER TABLE contact DROP COLUMN IF EXISTS contactPhone2;

COMMIT;
