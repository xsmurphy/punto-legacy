-- 144_register_lease_no_expiry.sql
-- La tenencia de caja (`register_lease`, mig 141) deja de vencer por fecha.
--
-- Contexto (context/29-numeracion-y-exclusividad-de-caja.md, revisado y
-- RECHAZADO el arriendo de bloques de numeración por el owner 2026-08-17):
-- `expiresAt` existía en `register_lease` para que una tenencia no
-- sobreviviera un cambio de fecha del outlet — motivo ligado al arriendo de
-- bloques de números (`numbering_lease`, TTL 24h), que este mismo cambio
-- elimina. Sin bloques reservados, no hay nada que un vencimiento por fecha
-- proteja: la tenencia ahora se libera SOLO al cerrar la caja o por
-- revocación explícita de admin (panel, "Liberar caja").
--
-- `expiresAt` deja de ser NOT NULL (no se dropea la columna: las filas
-- históricas conservan su valor tal cual, es historia fiscal). Los nuevos
-- INSERT de `api/v1/register/claim.php` la dejan NULL. `numbering_lease`
-- NO se toca acá — sigue existiendo, sin escritores nuevos, como registro
-- auditable de los bloques arrendados antes de este cambio.

BEGIN;

ALTER TABLE "register_lease" ALTER COLUMN "expiresAt" DROP NOT NULL;

COMMIT;
