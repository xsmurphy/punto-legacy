-- Renombra el realm `mcp` → `api`.
--
-- POR QUÉ. El realm es la frontera de seguridad y describe "acceso programático
-- de SOLO LECTURA en nombre de un usuario". Eso no es MCP: MCP resultó ser su
-- primer consumidor, no su definición. La misma key funciona hoy como API key
-- común contra cualquier endpoint que optó por el realm:
--
--   curl -H "Authorization: Bearer <key>" https://api.punto.la/v1/settings
--
-- Dejarlo como `mcp` significaba que dentro de seis meses un comercio
-- integrando su propio dashboard —o el sistema de su contador— iba a estar
-- autenticando con un realm llamado "mcp" que no tiene nada que ver con MCP.
--
-- CUÁNDO. Ahora, porque hoy las únicas keys que existen son de prueba. Cada
-- semana que pasa esto se vuelve una migración sobre credenciales en uso, que
-- es exactamente la clase de rename que después nadie hace.
--
-- Se renombra también en `tenant_audit`: si no, el historial de llamadas queda
-- partido en dos realms y el reporte de auditoría muestra "mcp" para lo viejo y
-- "api" para lo nuevo, sin que nada explique la diferencia.
--
-- `realm` es varchar(16) SIN CHECK (mig 69), así que no hay constraint que
-- tocar. Idempotente: correrla dos veces no hace nada la segunda.

BEGIN;

UPDATE auth_session SET realm = 'api' WHERE realm = 'mcp';
UPDATE auth_session SET module = 'api' WHERE module = 'mcp';
UPDATE tenant_audit  SET realm = 'api' WHERE realm = 'mcp';

COMMIT;
