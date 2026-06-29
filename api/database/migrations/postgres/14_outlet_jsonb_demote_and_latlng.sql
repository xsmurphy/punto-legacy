-- 14_outlet_jsonb_demote_and_latlng.sql
-- Degrada columnas descriptivas estáticas de `outlet` al JSONB `data`,
-- y splittea `outletLatLng` (VARCHAR "lat,lng") en dos columnas numéricas
-- `lat` y `lng` para cálculos de distancia haversine en SQL.
--
-- Columnas demoted al JSONB `data`:
--   outletAddress, outletPhone, outletWhatsApp, outletEmail,
--   outletBillingName, outletRUC, outletDescription
--
-- Columnas nuevas:
--   lat NUMERIC(10,7), lng NUMERIC(10,7)
--   → backfill desde outletLatLng (formato "lat,lng" o "lat, lng")
--   → outletLatLng se elimina (string ya no aporta vs cols numéricas)
--
-- Criterio (decisión 2026-06-11): solo datos estáticos NO indexables, NO
-- usados en cálculos SQL, NO filtrables van a JSONB. Address/phone/email
-- son metadata 100% display — nadie filtra outlets por dirección. Lat/lng
-- sí van a columnas porque entran en cálculos SQL (haversine para
-- "sucursal más cercana al cliente").
--
-- ⚠️ DEPLOY COORDINADO con los cambios de código:
--   1. _getTableSchema() 'outlet': quitar las 7 columnas demoted del whitelist
--      `columns` y agregar lat, lng. Sin esto, ncmInsert/ncmUpdate intentan
--      escribir las columnas viejas → "column does not exist".
--   2. api/lib/Outlets/OutletsService.php: shape/update/create migrados a
--      ncmUpdate (que respeta el schema whitelist y rutea al JSONB
--      automáticamente). El UPDATE explícito de update() ya no aplica.
--   3. panel/API/edit_outlet.php sigue funcionando — escribe via ncmUpdate
--      con dual-write (data JSONB + columnas). Tras esta migración la
--      copia a columnas se ignora (no están en el whitelist), va al JSONB.
--   4. Readers `SELECT *` (la mayoría) siguen leyendo via _flattenJsonb que
--      ahora trae outletAddress/Phone/etc desde data JSONB. Cero cambios.
--   5. Lectores de outletLatLng: hay que migrarlos a leer lat+lng o
--      reconstruir la string. Buscar `outletLatLng` en panel/screens/app.
--
-- ⚠️ PRIVILEGIOS: el ALTER TABLE ... DROP COLUMN requiere ser OWNER.
-- Correr esta migración como el rol dueño/superuser (en local: el OS user
-- de Postgres.app; en prod via Coolify: el rol del servicio Postgres).
--
-- Idempotente: el backfill usa COALESCE/NULLIF + jsonb_strip_nulls; los
-- DROP COLUMN usan IF EXISTS. Reaplicar es no-op.

BEGIN;

-- 1. Agregar columnas lat/lng (precision suficiente para ~1cm de resolución).
ALTER TABLE outlet ADD COLUMN IF NOT EXISTS lat NUMERIC(10,7);
ALTER TABLE outlet ADD COLUMN IF NOT EXISTS lng NUMERIC(10,7);

-- 2. Backfill lat/lng desde outletLatLng (formato "lat,lng" con espacio opcional).
--    SUBSTRING captura las dos partes numéricas. Si no matchea (string vacío,
--    formato distinto, link de Google Maps), deja lat/lng NULL — el usuario
--    los re-tipea en el form nuevo.
UPDATE outlet
SET lat = NULLIF(TRIM(SPLIT_PART(outletLatLng, ',', 1)), '')::NUMERIC(10,7),
    lng = NULLIF(TRIM(SPLIT_PART(outletLatLng, ',', 2)), '')::NUMERIC(10,7)
WHERE outletLatLng IS NOT NULL
  AND outletLatLng ~ '^\s*-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*$';

-- 3. Backfill JSONB `data` con los valores no vacíos de las columnas demoted.
--    jsonb_strip_nulls evita guardar keys con valor null (NULLIF convierte
--    string vacío a null). El merge `||` PISA keys del lado izquierdo con
--    los valores del lado derecho — exactamente lo deseado: la columna SQL
--    fue source-of-truth hasta esta migración, así que su valor gana sobre
--    cualquier copia previa que panel/API/edit_outlet.php hubiera escrito
--    en `data` durante el período de dual-write.
UPDATE outlet
SET data = COALESCE(data, '{}'::jsonb) || jsonb_strip_nulls(jsonb_build_object(
        'outletAddress',     NULLIF(outletAddress,     ''),
        'outletPhone',       NULLIF(outletPhone,       ''),
        'outletWhatsApp',    NULLIF(outletWhatsApp,    ''),
        'outletEmail',       NULLIF(outletEmail,       ''),
        'outletBillingName', NULLIF(outletBillingName, ''),
        'outletRUC',         NULLIF(outletRUC,         ''),
        'outletDescription', NULLIF(outletDescription, '')
    ))
WHERE outletAddress     IS NOT NULL
   OR outletPhone       IS NOT NULL
   OR outletWhatsApp    IS NOT NULL
   OR outletEmail       IS NOT NULL
   OR outletBillingName IS NOT NULL
   OR outletRUC         IS NOT NULL
   OR outletDescription IS NOT NULL;

-- 4. Drop de las columnas degradadas (idempotente).
ALTER TABLE outlet DROP COLUMN IF EXISTS outletAddress;
ALTER TABLE outlet DROP COLUMN IF EXISTS outletPhone;
ALTER TABLE outlet DROP COLUMN IF EXISTS outletWhatsApp;
ALTER TABLE outlet DROP COLUMN IF EXISTS outletEmail;
ALTER TABLE outlet DROP COLUMN IF EXISTS outletBillingName;
ALTER TABLE outlet DROP COLUMN IF EXISTS outletRUC;
ALTER TABLE outlet DROP COLUMN IF EXISTS outletDescription;
ALTER TABLE outlet DROP COLUMN IF EXISTS outletLatLng;

COMMIT;
