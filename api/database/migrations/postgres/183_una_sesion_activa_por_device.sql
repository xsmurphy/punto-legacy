-- Limpia las sesiones de dispositivo que quedaron APILADAS en producción.
--
-- QUÉ PASABA. `DeviceAuth::buildToken()` emitía una `auth_session` nueva en
-- cada pareo y en cada reconexión, sin revocar la anterior. La sesión del
-- device es eterna (`expiresat` NULL, mig 69), así que nada las cerraba: un
-- dispositivo acumulaba una credencial viva por cada vez que alguien lo
-- pareó. En el panel eso se veía como "4 sesiones" en /settings/devices.
--
-- POR QUÉ IMPORTA. Cada sesión vieja es una pestaña fantasma que sigue
-- autenticando y latiendo `/v1/register/claim` cada 5 min (HEARTBEAT_MS en
-- frontend/lib/pos/register-tenancy.ts). Apenas el tenedor legítimo suelta la
-- caja, el fantasma se la lleva. Síntoma reportado: parear una caja en una
-- tablet, revocarla, habilitarla en otra — y no poder facturar en ninguna.
--
-- El fix de raíz va en el código (`buildToken()` revoca lo anterior antes de
-- emitir, misma tanda). Esta migración es solo para lo YA encolado en prod:
-- sin ella, las sesiones viejas siguen vivas hasta que alguien vuelva a
-- parear cada dispositivo uno por uno.
--
-- POR QUÉ DEJA LA MÁS RECIENTE VIVA Y NO REVOCA TODAS. Revocar todo obliga a
-- re-parear cada aparato a mano, y el parque incluye dispositivos remotos
-- —un KDS a 400 km— cuyo motivo de existir es justamente administrarlos sin
-- viajar hasta ellos. La sesión más reciente por `deviceid` es, salvo
-- casualidad, la del pareo que el comercio hizo último y por lo tanto la que
-- está en el aparato que quiere usar; las anteriores son las fantasma.
--
-- ALCANCE. Solo filas con `deviceid IS NOT NULL`. Las sesiones de panel y
-- admin no tienen device y no entran acá: un operador puede tener el panel
-- abierto en la compu y en el teléfono a la vez, eso es legítimo.
--
-- CASING: `auth_session` es una de las 18 tablas que la mig 150 normalizó a
-- minúsculas. Sus columnas son `sessionid`/`deviceid`/`createdat`/`status`/
-- `revokedat` SIN comillas, aunque el CREATE TABLE de la mig 69 las haya
-- creado en camelCase entrecomillado. No copiar el casing de la 69.
--
-- `revokedby` queda NULL: no hay un usuario que revoque, es el sistema
-- cerrando credenciales que nunca debieron seguir abiertas. Mismo criterio
-- que la revocación al re-emitir en `DeviceAuth::buildToken()`.
--
-- IDEMPOTENTE: tras la primera corrida queda exactamente una activa por
-- device, así que la segunda no encuentra ninguna fila con rn > 1.

BEGIN;

WITH ranked AS (
    SELECT sessionid,
           row_number() OVER (
               PARTITION BY deviceid
               -- Desempate por sessionid solo para que el resultado sea
               -- DETERMINISTA si dos filas comparten `createdat` al
               -- microsegundo. NO es un orden temporal: los UUID de este
               -- schema son v4 aleatorios, no v7 — `max(sessionid)` no
               -- significa "el más nuevo" en ninguna tabla.
               ORDER BY createdat DESC, sessionid DESC
           ) AS rn
      FROM auth_session
     WHERE deviceid IS NOT NULL
       AND status = 1
)
UPDATE auth_session s
   SET status    = 0,
       revokedat = now()
  FROM ranked
 WHERE s.sessionid = ranked.sessionid
   AND ranked.rn > 1;

COMMIT;
