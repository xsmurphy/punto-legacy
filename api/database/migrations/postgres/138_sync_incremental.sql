-- 138_sync_incremental.sql
-- Sync incremental del POS (context/43-sync-incremental.md).
--
-- Un `SELECT ... WHERE updated_at > $fecha` nunca devuelve una fila
-- borrada — sin esto, un producto/cliente eliminado en el panel mientras la
-- tablet está offline queda FANTASMA en la caja al reconectar (visible,
-- vendible, inexistente en el server). Esta migración agrega la tabla de
-- lápidas + el trigger genérico que la puebla, para `item` y `contact` (las
-- dos entidades que este sync sincroniza por fila — ver el doc para por qué
-- el resto del catálogo del POS, "settings", no lo necesita).
--
-- Por qué TRIGGER de DB y no un helper PHP que cada call-site debe recordar
-- llamar: el codebase tiene ~15 call-sites de DELETE FROM item/contact
-- repartidos en varios Services (ver auditoría en context/43). Un helper
-- exige que TODO call-site futuro se acuerde de invocarlo — el mismo
-- principio que ya forzó a mover `manageStock()` a publicar realtime desde
-- adentro (context/15, hardening 2026-08-15) en vez de confiar en cada
-- caller. Un trigger AFTER DELETE no puede olvidarse: corre pase lo que
-- pase, incluso para código que no existe todavía o un DELETE emitido a
-- mano contra la base.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS, CREATE OR REPLACE FUNCTION,
-- DROP TRIGGER IF EXISTS + CREATE TRIGGER. No reescribe `item`/`contact`
-- (agregar un trigger no reescribe la tabla, solo toma un lock breve tipo
-- ACCESS EXCLUSIVE instantáneo) — no debería poder abortar el boot.

-- ── 1. Tabla de lápidas ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS deleted_row (
  "companyId"  UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  entity       TEXT         NOT NULL,
  "rowId"      UUID         NOT NULL,
  deleted_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
  PRIMARY KEY ("companyId", entity, "rowId")
);

-- Cubre tanto el delta ("lápidas de esta entity desde tal fecha") como la
-- purga por antigüedad (mismo orden de columnas, el índice sirve para ambos).
CREATE INDEX IF NOT EXISTS idx_deleted_row_delta
  ON deleted_row("companyId", entity, deleted_at);

-- ── 2. Trigger genérico ──────────────────────────────────────────────────────
-- TG_ARGV[0] = nombre de entity a registrar (ej. 'item'), TG_ARGV[1] = nombre
-- de la columna PK en minúscula (PG pliega identificadores sin comillas a
-- minúscula, y `item`/`contact` declaran sus columnas sin comillas — por eso
-- 'itemid'/'contactid', no 'itemId'/'contactId'). `to_jsonb(OLD) ->> col`
-- lee la columna por nombre dinámico sin necesitar una función por tabla.
CREATE OR REPLACE FUNCTION fn_record_deletion() RETURNS TRIGGER AS $$
DECLARE
  v_row_id  UUID;
  v_company UUID;
BEGIN
  v_row_id  := (to_jsonb(OLD) ->> TG_ARGV[1])::uuid;
  v_company := (to_jsonb(OLD) ->> 'companyid')::uuid;
  IF v_company IS NOT NULL AND v_row_id IS NOT NULL THEN
    INSERT INTO deleted_row ("companyId", entity, "rowId", deleted_at)
    VALUES (v_company, TG_ARGV[0], v_row_id, now())
    ON CONFLICT ("companyId", entity, "rowId") DO UPDATE SET deleted_at = EXCLUDED.deleted_at;
  END IF;
  RETURN OLD;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_item_tombstone ON item;
CREATE TRIGGER trg_item_tombstone
AFTER DELETE ON item
FOR EACH ROW EXECUTE FUNCTION fn_record_deletion('item', 'itemid');

DROP TRIGGER IF EXISTS trg_contact_tombstone ON contact;
CREATE TRIGGER trg_contact_tombstone
AFTER DELETE ON contact
FOR EACH ROW EXECUTE FUNCTION fn_record_deletion('contact', 'contactid');

-- ── 3. Purga (pg_cron, mismo patrón fail-tolerant que mig 36) ───────────────
-- Retención 90 días (decisión del owner) — coordinada A MANO con
-- `SyncService::TOMBSTONE_RETENTION_DAYS` (api/lib/Sync/SyncService.php)
-- porque pg_cron no puede leer una constante PHP. El endpoint /v1/sync
-- fuerza `full=true` cuando el `since` del cliente es más viejo que esa
-- ventana — nunca confía en que la cobertura de lápidas llegue más atrás de
-- lo que la purga garantiza.
DO $$
BEGIN
  CREATE EXTENSION IF NOT EXISTS pg_cron;
EXCEPTION WHEN OTHERS THEN
  RAISE NOTICE 'pg_cron no disponible (¿falta shared_preload_libraries=pg_cron + restart?). Purga de deleted_row NO programada.';
END $$;

DO $$
BEGIN
  BEGIN
    PERFORM cron.unschedule('purge-deleted-row');
  EXCEPTION WHEN OTHERS THEN
    NULL; -- el job no existía, seguimos
  END;

  PERFORM cron.schedule(
    'purge-deleted-row',
    '0 4 * * *',
    $job$DELETE FROM deleted_row WHERE deleted_at < now() - interval '90 days'$job$
  );
  RAISE NOTICE 'pg_cron job purge-deleted-row programado correctamente.';
EXCEPTION
  WHEN undefined_function OR undefined_table OR others THEN
    RAISE NOTICE 'pg_cron no disponible — job purge-deleted-row NO programado. Ver comentario de infra en mig 36.';
END $$;
