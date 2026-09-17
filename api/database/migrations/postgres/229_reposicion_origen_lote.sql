-- 229_reposicion_origen_lote.sql
-- El faltante del lote de producción como ORIGEN de una necesidad
-- (context/70 §B.5: "el faltante del lote de producción (etapa B)" era el
-- tercer origen previsto y el único que faltaba).
--
-- La necesidad sigue siendo la MISMA entidad de la mig 228 con las mismas
-- reglas: una sola abierta por (tenant, sucursal, ítem), cantidad fija,
-- cobertura por producción o transferencia. Lo único que cambia es de dónde
-- salió, así que esto es un valor más en el CHECK y no una columna ni una
-- tabla nueva.
--
-- `sourceid` queda NULL para este origen a propósito: el lote todavía no
-- existe como documento cuando se genera la necesidad (la pantalla calcula el
-- faltante ANTES de producir, que es el momento en que sirve pedir), y
-- guardar ahí un id que no apunta a nada sería peor que no guardarlo. Cuando
-- el origen es un conteo, `sourceid` sigue siendo el conteo.

-- 'production_batch' mide exactamente 16 caracteres y la columna nació
-- VARCHAR(16): entra justo. Se ensancha para que el próximo origen no sea una
-- migración de tipo además de una de CHECK (agrandar un varchar no reescribe
-- la tabla).
ALTER TABLE replenishment_need ALTER COLUMN origin TYPE VARCHAR(24);

ALTER TABLE replenishment_need DROP CONSTRAINT IF EXISTS replenishment_need_origin_check;
ALTER TABLE replenishment_need ADD CONSTRAINT replenishment_need_origin_check
  CHECK (origin IN ('min_stock','count_panel','count_register','production_batch'));
