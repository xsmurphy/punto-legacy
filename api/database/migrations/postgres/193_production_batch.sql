-- 193_production_batch.sql
-- Lote de producción multi-plato (context/70-viandas.md, etapa B / fase F2).
--
-- QUÉ RESUELVE
-- ------------
-- `production_order` (mig 76) es UN plato por orden. La pregunta del cliente
-- —"de 10 pedidos, cuánta pechuga necesito EN TOTAL"— necesita tomar
-- {plato, cantidad} × N, explotar todas las recetas y AGREGAR POR INSUMO. El
-- motor de explosión ya agrega dentro de un ítem
-- (`Inventory::explodeRecipeDetailed`, recursivo, con merma por nivel y guard
-- de ciclos); lo que faltaba es el agregador ENTRE ítems y un padre donde
-- vivan las N órdenes que salen de un mismo turno de cocina.
--
-- POR QUÉ UN PADRE PERSISTIDO Y NO UN CÁLCULO AL VUELO
-- ----------------------------------------------------
-- `context/70` §Arquitecturas rechazadas descarta "el lote como N
-- production_order sueltas": pierde la agregación por insumo, que es el punto
-- entero de la etapa B. Con el padre, la necesidad consolidada, el depósito de
-- insumos y el destino del terminado se deciden UNA vez para todo el turno, y
-- la orden que va a la cocina es un solo papel.
--
-- POR QUÉ LAS LÍNEAS SON `production_order` Y NO UNA TABLA NUEVA
-- --------------------------------------------------------------
-- Una línea del lote ES una orden de producción: mismo ítem, misma cantidad
-- planificada, mismo correlativo `produccion`, mismo `recipesnapshot` y
-- `unitcogs` congelados al completar. Duplicarla en `production_batch_line`
-- obligaría a reimplementar el consumo de stock y el costeo — exactamente lo
-- que D1 de `context/70` prohíbe ("el lote mueve stock vía el mismo
-- `ProductionService::complete()` de hoy"). Así que el lote agrega una columna
-- `batchid` nullable a `production_order` y nada más:
--
--   * `batchid IS NULL`  → orden suelta. Se comporta EXACTAMENTE igual que
--     antes de esta migración: cero regresión en el camino de un solo plato.
--   * `batchid` presente → línea de un lote.
--
-- `production_order` NO está particionada (mig 76: tabla plana con PK simple),
-- así que la FK al lote es una FK común. Verificado antes de escribirla —
-- `stock` y `transaction` sí lo están (migs 156/157) y ahí una FK entrante no
-- sería posible sin incluir la clave de partición.
--
-- NUMERACIÓN
-- ----------
-- El lote lleva su propio correlativo con doctype `'lote'`, scope sucursal
-- (mismo criterio que `produccion`/`merma`/`conteo` de la mig 129: no es un
-- documento fiscal, no depende del punto de expedición). No se siembra la fila
-- de `document_sequence` acá porque `DocumentNumber::allocate()` la crea sola
-- con `INSERT ... ON CONFLICT DO UPDATE` la primera vez que una sucursal emite
-- un lote — la mig 129 sembró solo porque además tenía que arrancar los
-- contadores desde el máximo histórico, y acá no hay histórico.
--
-- Cada línea conserva ADEMÁS su propio número `produccion`: son órdenes reales
-- que aparecen en el listado y en los reportes de producción, y perderían su
-- identidad si el lote se las comiera.
--
-- Todo lowercase sin comillas (convención de las migs 71/72 y 76 — ver la
-- lista de las 18 tablas con camelCase entrecomillado en `context/08`).

CREATE TABLE IF NOT EXISTS production_batch (
  batchid           UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid          UUID           NOT NULL,
  -- Depósito de INSUMOS: de dónde sale lo que se consume. Es también el
  -- depósito contra el que se mide el `onHand` de la necesidad consolidada —
  -- comparar contra el saldo de toda la sucursal cuando el consumo sale de un
  -- depósito puntual daría un "no falta nada" falso.
  locationid        UUID,
  -- Depósito DESTINO del producto terminado.
  outputlocationid  UUID,
  status            VARCHAR(16)    NOT NULL DEFAULT 'draft'
                       CHECK (status IN ('draft','confirmed','cancelled')),
  note              TEXT,
  userid            UUID,
  docnumber         INTEGER,
  created_at        TIMESTAMPTZ    NOT NULL DEFAULT now(),
  confirmed_at      TIMESTAMPTZ,
  cancelled_at      TIMESTAMPTZ,
  data              JSONB          NOT NULL DEFAULT '{}'
);

-- El índice del listado. `created_at` y no `batchid`: `gen_random_uuid()` es
-- v4 random (no v7), así que ordenar por el id NO ordena por recencia — es el
-- drift que ya causó un bug de stock (ver la nota en `context/04`).
CREATE INDEX IF NOT EXISTS idx_production_batch_company_outlet_created
  ON production_batch(companyid, outletid, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_production_batch_company_status
  ON production_batch(companyid, status);

ALTER TABLE production_order
  ADD COLUMN IF NOT EXISTS batchid UUID REFERENCES production_batch(batchid) ON DELETE SET NULL;

-- ON DELETE SET NULL y no CASCADE: borrar un lote (que hoy no se hace — se
-- cancela) nunca puede borrar las órdenes que YA movieron stock. La orden
-- huérfana sigue siendo una orden válida, que es exactamente el caso
-- `batchid IS NULL`.

-- Índice PARCIAL: la enorme mayoría de las órdenes son sueltas
-- (`batchid IS NULL`) y no hace falta indexarlas para responder "las líneas de
-- este lote".
CREATE INDEX IF NOT EXISTS idx_production_order_batch
  ON production_order(batchid) WHERE batchid IS NOT NULL;
