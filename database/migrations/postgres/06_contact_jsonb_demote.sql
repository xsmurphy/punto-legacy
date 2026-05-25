-- 06_contact_jsonb_demote.sql
-- Degrada columnas descriptivas estáticas de `contact` al JSONB `data`.
-- Columnas: contactNote, contactCity, contactLocation, contactCountry,
--           contactAddress, contactAddress2
--
-- Criterio (decisión 2026-05-25): solo datos estáticos NO indexables ni usados en
-- cálculos SQL van a JSONB. Estas 6 tienen 0 uso en WHERE/ORDER/JOIN, no tienen índice,
-- y city/location/country/address ya viven de forma canónica en `customerAddress`.
-- Los campos analíticos (precio, costo, storeCredit, loyalty) y los indexados/buscados
-- (name, email, phone, TIN, CI, status, type) SE QUEDAN como columnas.
--
-- ⚠️ DEPLOY COORDINADO — esta migración DEBE correr junto con los cambios de código (Fase 2):
--   1. _getTableSchema() 'contact': quitar las 6 columnas del whitelist `columns`
--      (así ncmInsert/ncmUpdate las enrutan a `data` automáticamente).
--   2. Writers que hoy escriben estas columnas vía AutoExecute / INSERT crudo →
--      migrar a ncmInsert/ncmUpdate:
--        panel/API/add_customer.php, edit_customer.php, add_customers.php, edit_customers.php
--        panel/a_contacts.php:258-259, 407-409, 1419-1424, y el INSERT bulk de :1471
--   3. Readers con SELECT explícito de estas columnas → usar data->>'col':
--        panel/a_contacts.php:2320 (lista generalTable)
--        app/fetchs.php:483
--
-- Razón del orden atómico: `_flattenJsonb` hace que la columna real GANE sobre el JSONB.
-- Si se recorta el whitelist sin dropear la columna, las lecturas devuelven el valor
-- viejo de la columna en vez del nuevo del JSONB → updates rotos. Trim + DROP van juntos.
--
-- Verificación previa al DROP (con el stack arriba): correr el backfill (paso 1), validar
-- que `SELECT data FROM contact WHERE ...` contiene las claves, ejercer alta/edición de
-- cliente + import CSV, y recién entonces aplicar el DROP.
--
-- ⚠️ PRIVILEGIOS: el ALTER TABLE ... DROP COLUMN requiere ser OWNER de la tabla.
-- El usuario de app (POSTGRES_USER=punto) NO es owner → falla con "must be owner of
-- table contact". Correr esta migración como el rol dueño/superuser (en local: el OS user
-- de Postgres.app). El backfill (UPDATE) sí lo puede correr el app-user.
--
-- Estado: aplicada en local 2026-05-25 y verificada end-to-end (v1 + legacy → data JSONB).

BEGIN;

-- 1. Backfill: mover los valores no vacíos a `data` (merge no destructivo).
--    jsonb_strip_nulls evita guardar claves con valor null.
UPDATE contact
SET data = COALESCE(data, '{}'::jsonb) || jsonb_strip_nulls(jsonb_build_object(
        'contactNote',     NULLIF(contactNote, ''),
        'contactCity',     NULLIF(contactCity, ''),
        'contactLocation', NULLIF(contactLocation, ''),
        'contactCountry',  NULLIF(contactCountry, ''),
        'contactAddress',  NULLIF(contactAddress, ''),
        'contactAddress2', NULLIF(contactAddress2, '')
    ))
WHERE contactNote     IS NOT NULL
   OR contactCity     IS NOT NULL
   OR contactLocation IS NOT NULL
   OR contactCountry  IS NOT NULL
   OR contactAddress  IS NOT NULL
   OR contactAddress2 IS NOT NULL;

-- 2. Drop de las columnas degradadas (idempotente).
ALTER TABLE contact DROP COLUMN IF EXISTS contactNote;
ALTER TABLE contact DROP COLUMN IF EXISTS contactCity;
ALTER TABLE contact DROP COLUMN IF EXISTS contactLocation;
ALTER TABLE contact DROP COLUMN IF EXISTS contactCountry;
ALTER TABLE contact DROP COLUMN IF EXISTS contactAddress;
ALTER TABLE contact DROP COLUMN IF EXISTS contactAddress2;

COMMIT;
