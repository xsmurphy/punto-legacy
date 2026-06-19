-- 36_tenant_audit_pgcron.sql
-- Programa un job pg_cron que purga filas de tenant_audit > 2 meses (retención).
--
-- Esta migración corre AUTOMÁTICAMENTE en cada deploy (database/migrate.php desde
-- docker-entrypoint.sh). Es FAIL-TOLERANT: si pg_cron no está disponible, hace
-- no-op con NOTICE y NO rompe el boot fail-fast del container.
--
-- Único prerequisito que una migración SQL NO puede automatizar (es config de
-- cluster Postgres, no DDL): habilitar la librería preload. Hacelo UNA vez en el
-- Postgres gestionado de Coolify:
--   1. postgresql.conf:  shared_preload_libraries = 'pg_cron'
--   2. Reiniciar Postgres.
-- Con eso hecho, esta migración crea la extensión y programa el job sola en el
-- próximo deploy. Idealmente seteá el preload ANTES de desplegar este código, así
-- el primer run de esta migración ya deja la retención programada.
-- (Si esta migración ya corrió en no-op antes de habilitar el preload, el runner
--  no la re-ejecuta; en ese caso agregá una migración nueva que repita el bloque
--  de scheduling de abajo.)

-- 1. Crear la extensión (tolerante: falla si falta el preload a nivel cluster).
DO $$
BEGIN
  CREATE EXTENSION IF NOT EXISTS pg_cron;
EXCEPTION WHEN OTHERS THEN
  RAISE NOTICE 'pg_cron no disponible (¿falta shared_preload_libraries=pg_cron + restart?). Retención de tenant_audit NO programada.';
END $$;

-- 2. Programar el job de purga (idempotente: desprograma antes de reprogramar).
DO $$
BEGIN
  -- Si el job ya existe (re-run), lo desprogramamos primero. unschedule lanza si
  -- no existe → lo absorbemos.
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
