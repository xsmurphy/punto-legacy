-- 128_register_expedition_point_unique.sql
-- Un punto de expedición, UNA caja.
--
-- El EEE-PPP (`register.data->>'registerInvoicePrefix'`) identifica el punto de
-- expedición ante la SET, y la numeración correlativa es POR punto de
-- expedición. Dos cajas con el mismo EEE-PPP llevan dos secuencias
-- independientes sobre el mismo talonario: en cuanto las dos emiten — y con el
-- POS offline emiten sin verse — salen dos facturas distintas con el mismo
-- número. Documento duplicado, ilegal ante la SET.
--
-- El guard está en RegisterAdminService (alta, edición del timbrado y
-- reactivación). Este índice es la red de contención: el servicio es hoy el
-- único escritor del timbrado, pero un invariante fiscal no puede depender de
-- que siga siéndolo.
--
-- Scope COMPANY, no sucursal: el RUC es de la empresa y el establecimiento ya
-- viaja en los primeros tres dígitos. Solo cajas ACTIVAS — una caja dada de
-- baja conserva su historial pero no emite.
--
-- OJO — la migración NO falla si ya hay duplicados. Hay bases con dos cajas
-- compartiendo EEE-PPP (se pudo hacer hasta este commit) y un CREATE UNIQUE
-- INDEX que aborta tira el deploy entero, imagen vieja incluida (precedente:
-- migs 74/77). Con duplicados presentes se emite un WARNING con el detalle y el
-- índice queda sin crear; el guard del servicio ya bloquea los nuevos y obliga
-- a resolver el par existente la primera vez que alguien toca esa caja.
-- Resuelto el duplicado, esta migración se puede volver a correr: es
-- idempotente (IF NOT EXISTS + re-chequeo).

BEGIN;

DO $$
DECLARE
  dupes text;
BEGIN
  SELECT string_agg(detail, '; ')
    INTO dupes
    FROM (
      -- Casts explícitos: `uuid || text` y `text || bigint` resuelven por
      -- anynonarray, pero acá el mensaje de error no vale una sorpresa de
      -- resolución de operadores en medio de un deploy.
      SELECT companyid::text || ' → ' || (data ->> 'registerInvoicePrefix')
             || ' (' || count(*)::text || ' cajas)' AS detail
        FROM register
       WHERE registerstatus = TRUE
         AND data ->> 'registerInvoicePrefix' IS NOT NULL
       GROUP BY companyid, data ->> 'registerInvoicePrefix'
      HAVING count(*) > 1
    ) d;

  IF dupes IS NOT NULL THEN
    RAISE WARNING
      'mig 128: puntos de expedición duplicados, índice NO creado. Resolver y re-correr. Detalle: %',
      dupes;
    RETURN;
  END IF;

  -- `->>` sobre jsonb es IMMUTABLE, así que sirve como expresión de índice.
  CREATE UNIQUE INDEX IF NOT EXISTS uq_register_expedition_point
      ON register (companyid, (data ->> 'registerInvoicePrefix'))
   WHERE registerstatus = TRUE
     AND data ->> 'registerInvoicePrefix' IS NOT NULL;
END $$;

COMMIT;
