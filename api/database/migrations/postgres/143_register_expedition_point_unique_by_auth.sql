-- 143_register_expedition_point_unique_by_auth.sql
-- Corrige la clave de unicidad del punto de expedición: es (timbrado +
-- EEE-PPP), NO solo el EEE-PPP.
--
-- La mig 128 asumió "un punto de expedición, UNA caja" y creó (o intentó
-- crear) un índice único sobre (companyid, registerInvoicePrefix). Esa
-- regla es INCORRECTA: el EEE-PPP identifica el punto de expedición ante la
-- SET, pero la numeración correlativa es POR TALONARIO, y un talonario es
-- un timbrado. Dos cajas SÍ pueden compartir el mismo EEE-PPP si tienen
-- timbrados distintos — son dos talonarios independientes, cada uno con su
-- propia secuencia, y eso es legal y ya está en uso en producción (caja
-- "Mariano" y "Nueva Caja" comparten timbrado 1734563552 con prefixes
-- 001-001 y 001-002 respectivamente; ver también un caso simétrico con
-- prefix compartido y timbrados distintos, resuelto por el owner el
-- 2026-08-17).
--
-- Lo que NO puede repetirse es el PAR completo: dos cajas activas con el
-- MISMO timbrado y el MISMO EEE-PPP llevan la misma secuencia y, en cuanto
-- las dos emiten offline sin verse, salen dos facturas distintas con el
-- mismo número. Documento duplicado, ilegal ante la SET. El riesgo que se
-- previene es el mismo de la mig 128 — lo único que cambia es la clave.
--
-- El guard de aplicación está en RegisterAdminService::assertExpeditionPointFree
-- (alta, edición del timbrado o del prefix, y reactivación). Este índice es
-- la red de contención: el servicio es hoy el único escritor del timbrado,
-- pero un invariante fiscal no puede depender de que siga siéndolo.
--
-- Scope COMPANY, no sucursal: el RUC es de la empresa y el establecimiento
-- ya viaja en los primeros tres dígitos del EEE-PPP. Solo cajas ACTIVAS —
-- una caja dada de baja conserva su historial pero no emite.
--
-- El índice de la 128 (clave vieja, incorrecta) se dropea primero. En
-- producción nunca llegó a crearse — la mig quedó en schema_migrations pero
-- emitió el WARNING de duplicados y retornó sin crear el índice, así que el
-- DROP es un no-op ahí. En otras bases (dev, otros tenants) donde sí se
-- haya creado con la clave vieja, dejarlo bloquearía altas legítimas de una
-- segunda caja con el mismo EEE-PPP y timbrado distinto.
--
-- OJO — igual que la 128, esta migración NO falla si hay duplicados por la
-- clave nueva. Un CREATE UNIQUE INDEX que aborta tira el deploy entero,
-- imagen vieja incluida (precedente: migs 74/77, y la 122 tumbó todos los
-- deploys ~4h). Con duplicados presentes se emite un WARNING con el detalle
-- y el índice queda sin crear; el guard del servicio ya bloquea los nuevos
-- pares y obliga a resolver el existente la primera vez que alguien toca
-- esa caja. Resuelto el duplicado, esta migración se puede volver a correr:
-- es idempotente (IF NOT EXISTS + re-chequeo).

BEGIN;

DROP INDEX IF EXISTS uq_register_expedition_point;

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
      SELECT companyid::text || ' → timbrado ' || (data ->> 'registerInvoiceAuth')
             || ' / ' || (data ->> 'registerInvoicePrefix')
             || ' (' || count(*)::text || ' cajas)' AS detail
        FROM register
       WHERE registerstatus = TRUE
         AND data ->> 'registerInvoicePrefix' IS NOT NULL
         AND data ->> 'registerInvoiceAuth' IS NOT NULL
       GROUP BY companyid, data ->> 'registerInvoiceAuth', data ->> 'registerInvoicePrefix'
      HAVING count(*) > 1
    ) d;

  IF dupes IS NOT NULL THEN
    RAISE WARNING
      'mig 143: pares (timbrado, punto de expedición) duplicados, índice NO creado. Resolver y re-correr. Detalle: %',
      dupes;
    RETURN;
  END IF;

  -- `->>` sobre jsonb es IMMUTABLE, así que sirve como expresión de índice.
  CREATE UNIQUE INDEX IF NOT EXISTS uq_register_expedition_point_by_auth
      ON register (companyid, (data ->> 'registerInvoiceAuth'), (data ->> 'registerInvoicePrefix'))
   WHERE registerstatus = TRUE
     AND data ->> 'registerInvoicePrefix' IS NOT NULL
     AND data ->> 'registerInvoiceAuth' IS NOT NULL;
END $$;

COMMIT;
