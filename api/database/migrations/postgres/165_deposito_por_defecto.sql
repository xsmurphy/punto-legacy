-- 165_deposito_por_defecto.sql
-- Toda sucursal tiene un depósito, y uno solo de ellos es el POR DEFECTO.
--
-- REGLA DE NEGOCIO (owner, 2026-08-24): "Cada sucursal sí o sí, por defecto,
-- tiene que tener un depósito. Cuando se crea una sucursal, se crea un
-- depósito. Ese depósito creado es el depósito por defecto, porque el stock
-- tiene que estar en un lugar físico, no puede estar en el aire. [...] el
-- depósito no puede ser opcional, sí o sí tiene que haber uno y sí o sí se
-- tiene que seleccionar uno. Por ende, ya tiene que estar preseleccionado el
-- principal, el depósito por defecto."
--
-- ESTADO PREVIO: los depósitos son filas de `taxonomy` con
-- taxonomytype='location' atadas a la sucursal por `taxonomy.outletid`. No
-- existía el concepto de "por defecto" (ni flag ni convención), y 7 de las 9
-- sucursales de producción no tenían ninguna fila `location`. El "depósito
-- principal" era de hecho `stock.locationid IS NULL` — un lugar que la UI
-- nombraba con un placeholder pero que no existe como fila.
--
-- ALCANCE (decisión explícita del owner): esta migración NO toca el histórico
-- del ledger. Las filas de `stock` con `locationid IS NULL` se quedan como
-- están y `stock.locationid` sigue siendo NULLABLE. La consolidación de esas
-- filas con el depósito por defecto de su sucursal se paga en la LECTURA
-- (`Inventory::ledgerLocationJoin()`, ver context/52), no migrando datos.
--
-- ============================================================
-- 1. Marcador del depósito por defecto
-- ============================================================
--
-- `taxonomy.taxonomyextra` es la columna de metadatos que el proyecto ya usa
-- para este tipo de flag (`RoleService` marca sus roles seed con
-- {"isSeed": true, "slug": "..."}). Se sigue ese patrón: el depósito por
-- defecto lleva {"isDefault": true}.
--
-- OJO — `taxonomyextra` es TEXT, no JSONB (db-schema-postgres.sql:185), así
-- que todo acceso necesita cast explícito a jsonb. Se usa `->>` y NUNCA el
-- operador `?` de jsonb: `?` colisiona con el placeholder de PDO, se reescribe
-- a `$1` y aborta el boot del contenedor (ya tiró dos deploys, migs 74/77).
--
-- La función existe para que el índice único de abajo pueda ser PARCIAL sobre
-- una expresión JSONB sin arriesgar el build: un `taxonomyextra` con texto que
-- no parsea como JSON haría fallar el cast dentro del predicado (Postgres no
-- garantiza el orden de evaluación de los AND de un predicado de índice, así
-- que anteponer `taxonomytype = 'location'` NO protege).
--
-- POR QUÉ `LANGUAGE sql` Y NO plpgsql CON BLOQUE `EXCEPTION`: un bloque
-- EXCEPTION de plpgsql abre una SUBTRANSACCIÓN al entrar, y
-- `BeginInternalSubTransaction` aborta con "cannot start subtransactions
-- during a parallel operation" si la query corre en un plan paralelo. Los
-- lectores del ledger hacen LEFT JOIN de esta función contra `stock`, que está
-- PARTICIONADA (mig 156) — o sea que el plan paralelo es justamente el que va
-- a elegir el planner en el reporte de stock. Marcarla PARALLEL UNSAFE lo
-- evitaría, pero mataría el paralelismo de esos reportes.
--
-- La guarda `IS NOT JSON OBJECT` (PG16+; prod corre 18.4) reemplaza al
-- EXCEPTION sin subtransacción ni cast riesgoso. Va dentro de un `CASE`, que
-- SÍ garantiza evaluación ordenada de sus ramas — un `AND` no la garantiza.
--
-- IMMUTABLE es correcto y necesario: el resultado depende solo de los
-- argumentos, y Postgres exige inmutabilidad para usarla en un predicado de
-- índice.

CREATE OR REPLACE FUNCTION fn_taxonomy_is_default_location(p_type text, p_extra text)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
PARALLEL SAFE
AS $$
    SELECT CASE
        WHEN p_type IS DISTINCT FROM 'location' THEN false
        WHEN p_extra IS NULL                    THEN false
        WHEN btrim(p_extra) = ''                THEN false
        WHEN p_extra IS NOT JSON OBJECT         THEN false
        ELSE COALESCE((p_extra::jsonb ->> 'isDefault') = 'true', false)
    END;
$$;

COMMENT ON FUNCTION fn_taxonomy_is_default_location(text, text) IS
    'true si la fila de taxonomy es el depósito POR DEFECTO de su sucursal '
    '(taxonomytype=''location'' + taxonomyextra {"isDefault": true}). '
    'IMMUTABLE para poder usarse en el predicado de uq_taxonomy_location_default. '
    'Devuelve false ante taxonomyextra no parseable en vez de lanzar. '
    'Ver context/52-stock-ledger-unica-fuente.md.';

-- ============================================================
-- 2. Invariante: como máximo UN depósito por defecto por sucursal
-- ============================================================
--
-- Garantizado por el motor, no por disciplina de código (mismo criterio que
-- D4 de context/52). Un UNIQUE parcial común no alcanza porque el marcador
-- vive dentro de un JSONB serializado en una columna TEXT — de ahí el índice
-- funcional sobre la expresión, con la función de arriba como predicado.
--
-- Efecto lateral buscado: la lectura puede hacer LEFT JOIN contra el depósito
-- por defecto de la sucursal sin riesgo de fan-out (el JOIN no puede duplicar
-- filas del ledger porque el índice prohíbe el segundo default).

CREATE UNIQUE INDEX IF NOT EXISTS uq_taxonomy_location_default
    ON taxonomy (outletid)
    WHERE fn_taxonomy_is_default_location(taxonomytype, taxonomyextra);

COMMENT ON INDEX uq_taxonomy_location_default IS
    'Una sucursal no puede tener dos depósitos por defecto.';

BEGIN;

-- ============================================================
-- 3. Backfill A — sucursales que YA tienen depósitos pero ninguno por defecto
-- ============================================================
--
-- Se MARCA uno existente en vez de crear uno nuevo: el owner ya opera esos
-- depósitos ("Mostrador", "Almacenamiento de Materia Prima") y un depósito
-- extra sería ruido en la UI.
--
-- CRITERIO DE DESEMPATE — `taxonomy` NO tiene columna de tiempo (ni
-- created_at ni equivalente) y sus UUID son v4 random, así que `ORDER BY
-- taxonomyid` NO da orden temporal (memoria del proyecto: causó drift de
-- stock). Se usa `ctid`, que aproxima el orden físico de inserción. Es una
-- heurística, no una garantía — y alcanza: en producción cada sucursal
-- afectada tiene UN solo depósito, así que no hay desempate real que hacer.
-- Lo único que importa es que la elección sea determinística y que quede
-- exactamente una.

WITH candidatos AS (
    SELECT DISTINCT ON (t.outletid) t.taxonomyid
      FROM taxonomy t
      JOIN outlet o ON o.outletid = t.outletid
     WHERE t.taxonomytype = 'location'
       AND NOT EXISTS (
           SELECT 1
             FROM taxonomy d
            WHERE d.outletid = t.outletid
              AND fn_taxonomy_is_default_location(d.taxonomytype, d.taxonomyextra)
       )
     ORDER BY t.outletid, t.ctid
)
UPDATE taxonomy t
   SET taxonomyextra = (
           COALESCE(NULLIF(btrim(COALESCE(t.taxonomyextra, '')), '')::jsonb, '{}'::jsonb)
           || '{"isDefault": true}'::jsonb
       )::text
  FROM candidatos c
 WHERE t.taxonomyid = c.taxonomyid;

-- ============================================================
-- 4. Backfill B — sucursales activas SIN ningún depósito
-- ============================================================
--
-- NOMBRE: `uq_taxonomy_company_type_name` (mig 38) es UNIQUE sobre
-- (companyid, taxonomytype, lower(taxonomyname)) → dentro de una company NO
-- puede haber dos depósitos con el mismo nombre. Por eso el nombre no puede
-- ser un literal fijo "Depósito principal" para todas las sucursales: la
-- segunda sucursal de la misma company reventaría por violación de unicidad.
-- Se nombra por la sucursal ("Depósito Central"), que además es lo que el
-- operador espera leer en el selector.
--
-- El sufijo con los 8 primeros caracteres del outletid solo aparece si el
-- nombre ya está tomado en esa company, o si dos sucursales de la misma
-- company se llaman igual (`homonimos > 1` detecta las colisiones DENTRO de
-- este mismo INSERT, que el EXISTS no puede ver porque las filas todavía no
-- están escritas).
--
-- Idempotente: al re-correr, ninguna sucursal cae en `faltantes` porque todas
-- ya tienen su fila `location`.

WITH faltantes AS (
    SELECT o.outletid,
           o.companyid,
           o.outletname,
           count(*) OVER (PARTITION BY o.companyid, lower(o.outletname)) AS homonimos
      FROM outlet o
     WHERE o.companyid IS NOT NULL
       -- SIN filtro por `outletstatus`: una sucursal inactiva sigue teniendo
       -- filas en `stock`, y si se reactiva tiene que encontrar su depósito ya
       -- creado. Saltearlas dejaría un agujero que solo se nota el día que
       -- alguien las vuelve a usar.
       AND NOT EXISTS (
           SELECT 1
             FROM taxonomy t
            WHERE t.taxonomytype = 'location'
              AND t.outletid = o.outletid
       )
), con_nombre AS (
    SELECT f.outletid,
           f.companyid,
           CASE
               WHEN f.homonimos > 1
                 OR EXISTS (
                        SELECT 1
                          FROM taxonomy x
                         WHERE x.companyid    = f.companyid
                           AND x.taxonomytype = 'location'
                           AND lower(x.taxonomyname) = lower('Depósito ' || f.outletname)
                    )
               THEN 'Depósito ' || f.outletname || ' (' || left(f.outletid::text, 8) || ')'
               ELSE 'Depósito ' || f.outletname
           END AS nombre
      FROM faltantes f
)
INSERT INTO taxonomy (taxonomyid, companyid, taxonomytype, outletid, taxonomyname, taxonomyextra)
SELECT gen_random_uuid(), c.companyid, 'location', c.outletid, c.nombre, '{"isDefault": true}'
  FROM con_nombre c;

COMMIT;
