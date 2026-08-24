-- 166_caja_por_defecto.sql
-- Toda sucursal tiene su caja. Backfill del eslabón que faltaba de la cadena.
--
-- REGLA DE NEGOCIO (owner, 2026-08-24): "Cuando un tenant crea una nueva
-- cuenta en el sistema (signup), se crea automáticamente por defecto la
-- empresa > una sucursal (Central) > un depósito > una caja. Estos van
-- encadenados obligatoriamente. Cuando creo una sucursal nueva también se crea
-- la Sucursal > depósito > caja. El depósito y la caja están al mismo nivel,
-- ambos son hijos directos de la sucursal."
--
--     Company
--     └── Outlet (sucursal)
--         ├── Location (depósito)   ← hermanos, hijos directos del outlet
--         └── Register (caja)
--
-- La mig 165 cerró la rama del DEPÓSITO. Ésta cierra la de la CAJA.
--
-- ESTADO PREVIO: los dos caminos de alta de sucursal en producción
-- (`Auth\SignupService` y `Outlets\OutletsService::create()`) YA crean su caja
-- dentro de la misma transacción que la sucursal — o sea que el código estaba
-- bien. El agujero está en los SEEDS: `01_master_admin.sql` crea la empresa
-- master y "Master Outlet" con SQL crudo y nunca insertó un `register`. En
-- producción esa es la única sucursal sin caja (1 de 9).
--
-- ============================================================
-- Qué caso se backfillea, y cuál NO
-- ============================================================
--
-- SE CREA CAJA: sucursal sin NINGUNA fila en `register`. Nunca tuvo caja, así
-- que no hay nada que respetar.
--
-- NO SE TOCA: sucursal con cajas pero todas con `registerstatus = FALSE`. Una
-- caja dada de baja se desactiva a propósito (`RegisterAdminService::delete()`
-- hace soft delete cuando la caja tiene transacciones, para preservar el
-- histórico fiscal). Crear una caja nueva ahí sería inventar un talonario que
-- el operador no pidió, y REACTIVAR una vieja le devolvería su timbrado y su
-- punto de expedición a la circulación — justo lo que el índice
-- `uq_register_expedition_point_by_auth` (mig 143) existe para vigilar. Se
-- reporta con RAISE NOTICE y se deja para decisión humana. En producción hoy
-- no hay ninguna en ese estado.
--
-- ============================================================
-- Por qué esto NO choca con la numeración fiscal (context/29)
-- ============================================================
--
-- Crear una caja NO es asignar un punto de expedición. El punto de expedición
-- (`EEE-PPP`) y el timbrado viven en `register.data ->> 'registerInvoicePrefix'`
-- y `data ->> 'registerInvoiceAuth'`, y los escribe UN solo lugar:
-- `RegisterAdminService` (alta/edición desde Sucursal › Cajas), que valida con
-- `assertExpeditionPointFree()`. Esta migración inserta la caja con
-- `data = '{}'`: sin timbrado y sin prefix.
--
-- El índice único de la mig 143 es PARCIAL —
--   WHERE registerstatus = TRUE
--     AND data ->> 'registerInvoicePrefix' IS NOT NULL
--     AND data ->> 'registerInvoiceAuth'   IS NOT NULL
-- — así que una caja sin talonario queda FUERA del índice y no puede colisionar
-- con nada. El invariante "por timbrado, punto de expedición + correlativo es
-- único" (context/29 §2) se mantiene intacto: la caja creada acá no emite
-- ningún documento fiscal hasta que un humano le asigne timbrado y prefix, que
-- es exactamente lo que ya hace la "Nueva Caja" que `OutletsService::create()`
-- viene creando desde siempre.
--
-- Ninguna de las arquitecturas rechazadas de context/29 §6 se revive: no hay
-- arriendo de bloques, no hay asignación de número en el servidor, no hay
-- tenencia que se pise.
--
-- ============================================================
-- Nombre
-- ============================================================
--
-- 'Caja Principal' — el mismo literal que usa `SignupService`. A diferencia de
-- `taxonomy` (que tiene `uq_taxonomy_company_type_name`, mig 38, y obligó a la
-- 165 a resolver nombres libres), `register` NO tiene ningún índice único
-- sobre el nombre: los únicos índices son la PK, dos btree de FK y el parcial
-- del punto de expedición. El literal fijo es seguro y en producción ya se
-- repite entre tenants.
--
-- Idempotente: al re-correr, ninguna sucursal cae en `faltantes`.

BEGIN;

DO $$
DECLARE
    creadas    int;
    inactivas  text;
BEGIN
    WITH faltantes AS (
        SELECT o.outletid, o.companyid
          FROM outlet o
         WHERE o.companyid IS NOT NULL
           -- SIN filtro por `outletstatus`, mismo criterio que la 165: una
           -- sucursal inactiva que se reactiva tiene que encontrar su cadena
           -- completa, no un agujero que recién se nota ese día.
           AND NOT EXISTS (
               SELECT 1 FROM register r WHERE r.outletid = o.outletid
           )
    )
    INSERT INTO register (registerid, registername, registerstatus, outletid, companyid, data)
    SELECT gen_random_uuid(), 'Caja Principal', TRUE, f.outletid, f.companyid, '{}'::jsonb
      FROM faltantes f;

    GET DIAGNOSTICS creadas = ROW_COUNT;
    RAISE NOTICE 'mig 166: % caja(s) creada(s) para sucursales que no tenían ninguna.', creadas;

    -- Reporte (no acción) del caso que se decidió NO tocar.
    SELECT string_agg(o.outletid::text || ' (' || o.outletname || ')', '; ')
      INTO inactivas
      FROM outlet o
     WHERE EXISTS (SELECT 1 FROM register r WHERE r.outletid = o.outletid)
       AND NOT EXISTS (
           SELECT 1 FROM register r WHERE r.outletid = o.outletid AND r.registerstatus = TRUE
       );

    IF inactivas IS NOT NULL THEN
        RAISE NOTICE
            'mig 166: sucursales con cajas pero TODAS inactivas — no se tocan, requieren decisión humana (reactivar una o crear otra desde Sucursal > Cajas): %',
            inactivas;
    END IF;
END $$;

COMMIT;
