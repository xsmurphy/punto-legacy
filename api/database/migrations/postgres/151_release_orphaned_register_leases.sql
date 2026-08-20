-- 151_release_orphaned_register_leases.sql
-- Limpieza de tenencias de caja (`register_lease`) colgadas por el bug de
-- "dispositivo cambia de caja" (context/29-numeracion-y-exclusividad-de-caja.md
-- §4, fix en api/v1/active-register.php + api/v1/register/claim.php de esta
-- misma sesión, 2026-08-20).
--
-- El bug: cuando un device cambiaba de caja (reasignado desde el panel, o
-- movido por el operador desde "Ajustes del POS"), `device.registerid` se
-- actualizaba a la caja nueva pero la tenencia (`register_lease`, mig 141)
-- sobre la caja VIEJA nunca se liberaba — quedaba `status='active'` para
-- siempre, bloqueando esa caja para cualquier otro dispositivo (el
-- constraint `uq_register_lease_active` es por caja, así que nadie más podía
-- tomarla). Caso real verificado en prod: device "Christian Mac"
-- (1fe5feee-d561-403d-8efe-fd94613c149b) tenía tenencia activa sobre "Caja
-- Mariano" (b3bbde02-27ff-4bb0-8464-0298ffe844a6) tomada 2026-08-20 02:48,
-- pero su fila `device` ya apuntaba a "Nueva Caja"
-- (019ec283-ad28-7782-8a15-8fd57ff75243).
--
-- Esta migración es un cierre retroactivo, no una reasignación: solo toca
-- tenencias donde la caja de la tenencia YA NO coincide con la caja actual
-- del device (`device.registerid IS DISTINCT FROM register_lease.registerid`)
-- — el mismo criterio que decide si una tenencia está huérfana. No inventa
-- datos: usa exactamente la misma transición que `RegisterLeaseService::
-- close()` hace en el código (status→'released', releasedAt=NOW(),
-- releasedBy identificable, y anula cualquier `numbering_lease` histórico no
-- consumido que quedara atado a esa tenencia).
--
-- Verificado contra prod (solo lectura, 2026-08-20): exactamente 1 fila
-- afecta hoy — la de "Christian Mac" arriba. Cero tenencias activas de
-- devices revocados (esas ya las libera `devices.php` DELETE desde antes).

BEGIN;

WITH orphaned AS (
    SELECT rl.registerleaseid
      FROM register_lease rl
      JOIN device d ON d.deviceid = rl.deviceid
     WHERE rl.status = 'active'
       AND d.registerid IS DISTINCT FROM rl.registerid
)
UPDATE register_lease
   SET status = 'released',
       releasedat = now(),
       releasedby = 'migration:151_release_orphaned_register_leases'
  FROM orphaned
 WHERE register_lease.registerleaseid = orphaned.registerleaseid;

-- Mismo segundo paso que RegisterLeaseService::close() — anula los números
-- `numbering_lease` HISTÓRICOS no consumidos que hayan quedado atados a las
-- tenencias recién cerradas (no-op esperado: no hay escritores nuevos de
-- numbering_lease desde context/29 §6, y la fila real de este caso no tenía
-- ninguno pendiente).
UPDATE numbering_lease
   SET voidedat = now(),
       voidreason = 'released'
 WHERE registerleaseid IN (
     SELECT registerleaseid FROM register_lease
      WHERE releasedby = 'migration:151_release_orphaned_register_leases'
 )
   AND consumedat IS NULL
   AND voidedat IS NULL;

COMMIT;
