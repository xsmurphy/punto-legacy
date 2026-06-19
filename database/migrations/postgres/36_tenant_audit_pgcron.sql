-- 36_tenant_audit_pgcron.sql
-- Programa un job pg_cron para purgar filas de tenant_audit > 2 meses.
--
-- PASO MANUAL DE INFRA (antes de correr esta migración):
--   1. En el Postgres gestionado de Coolify, agregar a postgresql.conf:
--        shared_preload_libraries = 'pg_cron'
--   2. Reiniciar Postgres.
--   3. Conectarse a la DB y correr: CREATE EXTENSION IF NOT EXISTS pg_cron;
--   4. Re-correr esta migración.
--
-- Si pg_cron NO está instalado, el bloque no falla — emite un NOTICE y sigue.

DO $$
BEGIN
  -- Idempotente: si el job ya existe (re-run de la migración), lo desprogramamos
  -- primero. cron.unschedule lanza si no existe → lo absorbemos.
  BEGIN
    PERFORM cron.unschedule('purge-tenant-audit');
  EXCEPTION WHEN OTHERS THEN
    NULL; -- el job no existía, seguimos
  END;

  PERFORM cron.schedule(
    'purge-tenant-audit',
    '0 3 * * *',
    $job$DELETE FROM tenant_audit WHERE "createdAt" < now() - interval '2 months'$job$
  );
  RAISE NOTICE 'pg_cron job purge-tenant-audit programado correctamente.';
EXCEPTION
  WHEN undefined_function OR undefined_table OR others THEN
    RAISE NOTICE 'pg_cron no disponible — job purge-tenant-audit NO programado. Ver comentario de infra en este archivo.';
END $$;
