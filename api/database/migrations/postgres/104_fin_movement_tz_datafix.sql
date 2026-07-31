-- 104_fin_movement_tz_datafix.sql
-- Corrige `fin_movement.date` para las filas escritas con `date('Y-m-d H:i:s')`
-- del container en UTC en vez de la hora tenant-local (America/Asuncion,
-- UTC-3) — bug 2026-07-30: MovementService/CheckService/LoanService/
-- ReconciliationService (api/lib/Finance/*) y el fallback de fecha de
-- api/v1/drawer.php nunca pasaban por data.php (que hace
-- date_default_timezone_set() para /app y /panel), así que corrían con el
-- default timezone del proceso PHP-FPM. En prod ese default es UTC → esas
-- filas quedaron con `date` = created_at + 3h, adelantadas sobre eventos
-- posteriores en el ORDER BY (m.date DESC, m.created_at DESC) de
-- MovementService::list → "últimos movimientos" de /finanzas intercalaba mal.
--
-- Heurística: solo tocamos filas donde date está en la ventana estrecha
-- [created_at + 170min, created_at + 190min] — el offset exacto de un tenant
-- UTC-3 es +180min. Esa ventana angosta es deliberada: separa el bug (offset
-- fijo de exactamente 3h) de fechas backdatadas legítimas (carga manual de un
-- movimiento con fecha pasada/futura arbitraria), que no van a caer justo en
-- ese rango de 20 minutos alrededor de +3h.
--
-- Asume TODOS los tenants en America/Asuncion (UTC-3, sin DST) — válido hoy
-- (single-tenant-timezone de facto). Si en el futuro hay tenants en otra TZ,
-- esta heurística por ventana fija ya no alcanza y hay que revisar por
-- companyId.
--
-- Idempotente por construcción: tras el shift, date ≈ created_at (diferencia
-- de milisegundos de proceso, no minutos) y la fila deja de matchear la
-- ventana — correr la migración de nuevo es un no-op.
BEGIN;

UPDATE fin_movement
   SET date = date - interval '3 hours'
 WHERE date >= created_at + interval '170 minutes'
   AND date <= created_at + interval '190 minutes';

COMMIT;
