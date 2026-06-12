-- 24_taxonomy_cleanup.sql
-- Slice 4 del refactor taxonomy: borrar rows huérfanos.
--
-- `tusFacturas` confirmado muerto por el owner. No hay consumers activos
-- en /api ni /app ni panel-next (sólo aparece en comentario de
-- TransactionsService.php). El panel legacy lo usaba pero va a morir.
--
-- NO se tocan otros types — siguen vivos:
--   - 'role' y 'roleData' (POS los lee — slice futuro para mover a tabla
--     dedicada cuando se haga el refactor del módulo usuarios).
--   - 'location' parcialmente migrado a item_location; type='location'
--     en taxonomy sigue siendo la fuente de verdad de metadata.
--   - 'paymentMethod', 'tag' — pending slices.
--   - 'category', 'brand', 'tax', 'printTemplate' — ya migrados (rows
--     siguen vivos hasta que el POS migre y se haga el corte final).
--
-- Idempotente: si los rows ya fueron borrados, el DELETE es no-op.

-- Log de safety: contar antes/después para que el migration runner
-- imprima cuántos rows se afectaron (visible en logs de Coolify).
DO $$
DECLARE
    n_deleted INTEGER;
BEGIN
    DELETE FROM taxonomy WHERE taxonomyType = 'tusFacturas';
    GET DIAGNOSTICS n_deleted = ROW_COUNT;
    RAISE NOTICE '[24_taxonomy_cleanup] tusFacturas rows deleted: %', n_deleted;
END $$;
