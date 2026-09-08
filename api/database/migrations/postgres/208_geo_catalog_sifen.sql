-- 208_geo_catalog_sifen.sql
-- El catálogo geográfico fiscal pasa a tener UNA sola fuente: SIFEN.
--
-- POR QUÉ. La mig 207 creó el catálogo sincronizándolo de Factomate, que era
-- el proveedor de facturación electrónica de entonces. Punto ya no factura por
-- ahí: el motor es FE-PY, propio (`einvoice_account.provider = 'fepy'`), y
-- FE-PY VALIDA los códigos geográficos contra el catálogo de la SET antes de
-- armar el XML. La fuente de verdad de un código fiscal es quien lo valida —
-- un código que sale de un catálogo y que el validador no reconoce es un
-- documento RECHAZADO. Y no es teórico: Factomate devolvía 6.419 ciudades,
-- SIFEN tiene 6.766.
--
-- QUÉ PASA CON LAS FILAS QUE YA ESTABAN (y por qué). Se marcan `active = FALSE`
-- — no se borran y no se dejan como estaban. Las tres opciones y el motivo de
-- la elección:
--
--   - DEJARLAS ACTIVAS sería lo peor: el sync NUNCA borra (invariante de la
--     mig 207), así que un código que existe en Factomate y no en SIFEN
--     quedaría vivo y OFRECIBLE en el selector. Un comercio elegiría de una
--     lista un código que su propio motor de emisión va a rechazar. Ese es
--     exactamente el bug que este cambio viene a cerrar.
--   - BORRARLAS rompe la otra invariante de la 207 —y con razón: si un
--     comercio ya tenía un código guardado en su domicilio fiscal, borrar la
--     fila deja ese código sin nombre en la pantalla para siempre.
--   - DESACTIVARLAS es la semántica que la tabla ya define para "esto existió
--     pero no se ofrece más": fuera del selector, pero `GeoCatalog::resolve()`
--     las sigue resolviendo a su nombre. Es la elegida.
--
-- Y no deja el catálogo vacío: la carga del seed de SIFEN corre en el MISMO
-- boot, inmediatamente después de las migraciones
-- (`docker-entrypoint.sh` → `database/seed_geo_catalog.php`), y su upsert
-- REACTIVA (`active = TRUE`) todo código que exista en los dos catálogos. Lo
-- único que queda inactivo es lo que SIFEN no tiene — que es justo lo que no
-- se debe poder elegir.
--
-- Verificado en producción antes de escribir esta migración (2026-09-08):
-- ningún tenant tiene códigos geográficos guardados. La única cuenta de
-- facturación electrónica (provider `fepy`) tiene su establecimiento con
-- departamento/distrito/ciudad en NULL, y las tablas `geo_*` ni siquiera
-- existen todavía en prod (la 207 no llegó a deployarse). O sea que hoy este
-- UPDATE no toca ninguna fila; está para las bases de desarrollo que sí
-- alcanzaron a sincronizar de Factomate, y para que el estado final del
-- catálogo sea el mismo en todas.
--
-- SE ELIMINA `providerid`. Era la PK interna de Factomate, guardada solo para
-- rastrear una fila hasta su origen; nunca se mostró ni se mandó a un
-- documento. Con Factomate fuera como fuente geográfica, nada puede volver a
-- poblarla: quedaría NULL para siempre mientras el comentario del schema
-- afirma que sirve para rastrear el origen. Ese rol pasa a `source`, que a
-- partir de ahora el sync ESCRIBE en cada upsert (antes vivía de su DEFAULT y
-- una fila no podía decir de qué catálogo salió su código).
--
-- Todo lowercase sin comillas (convención del repo). Idempotente: se puede
-- correr dos veces.

BEGIN;

-- ── 1. La fuente por defecto es SIFEN ──────────────────────────────────────
ALTER TABLE geo_department ALTER COLUMN source SET DEFAULT 'sifen';
ALTER TABLE geo_district   ALTER COLUMN source SET DEFAULT 'sifen';
ALTER TABLE geo_city       ALTER COLUMN source SET DEFAULT 'sifen';

-- ── 2. Lo que quedó de Factomate sale del selector, pero se conserva ───────
-- Hijos primero, por simetría con el orden del sync.
UPDATE geo_city       SET active = FALSE WHERE source <> 'sifen' AND active;
UPDATE geo_district   SET active = FALSE WHERE source <> 'sifen' AND active;
UPDATE geo_department SET active = FALSE WHERE source <> 'sifen' AND active;

-- ── 3. La PK del proveedor intermediario deja de existir ───────────────────
ALTER TABLE geo_department DROP COLUMN IF EXISTS providerid;
ALTER TABLE geo_district   DROP COLUMN IF EXISTS providerid;
ALTER TABLE geo_city       DROP COLUMN IF EXISTS providerid;

-- ── 4. Los comentarios del schema dejan de nombrar al proveedor viejo ──────
COMMENT ON TABLE geo_department IS
  'Catálogo geográfico fiscal, nivel 1. Dato de PLATAFORMA (sin companyid), cargado del seed de SIFEN por database/seed_geo_catalog.php al boot y por maintenance.php?job=geo-catalog-sync a pedido.';
COMMENT ON TABLE geo_district IS
  'Catálogo geográfico fiscal, nivel 2 (272 filas). Sale del mismo seed de SIFEN que los otros dos niveles.';
COMMENT ON TABLE geo_city IS
  'Catálogo geográfico fiscal, nivel 3 (6.766 filas). Alimenta el selector en cascada del domicilio de los establecimientos fiscales y la resolución de códigos por nombre del asistente.';
COMMENT ON COLUMN geo_department.code IS
  'CÓDIGO FISCAL de la SET: el número que termina en el documento electrónico y el que el comercio ve en su constancia.';
COMMENT ON COLUMN geo_department.source IS
  'De qué catálogo salió el código. Lo escribe el sync en cada upsert. Filas con source distinto de ''sifen'' son de un proveedor anterior y quedan inactivas: su código puede no existir en SIFEN.';
COMMENT ON COLUMN geo_district.source IS
  'De qué catálogo salió el código. Ver geo_department.source.';
COMMENT ON COLUMN geo_city.source IS
  'De qué catálogo salió el código. Ver geo_department.source.';

COMMIT;
