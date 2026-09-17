-- 228_necesidad_de_reposicion.sql
-- Reposición por stock mínimo y por conteo (context/70 §B.5, D6-D8).
--
-- ── Qué agrega ───────────────────────────────────────────────────────────────
--
-- 1. `item.itemreplenishqty` — "cantidad a reponer o producir". Cantidad FIJA
--    (D7: mínimo 20 + reponer 50 → al llegar a 20 se piden 50; "hasta el
--    máximo" quedó rechazado). NULL = el ítem no dispara reposición, aunque
--    tenga mínimo: el mínimo solo avisa, como hasta ahora. Mismo tipo que
--    `itemminstock` (mig 133) porque hay ítems que se venden por peso.
--
-- 2. `replenishment_need` — la NECESIDAD. "Activar" es crear esto, nunca un
--    documento (D7, opción B): una orden de producción en borrador automática
--    consume insumos al confirmarse y se acumula sin que nadie la revise.
--
-- 3. `replenishment_need_coverage` — con QUÉ documento se cubrió y cuánto se
--    planeó cubrir con él. La cantidad EFECTIVA no se guarda: se deriva del
--    estado del documento (orden COMPLETADA → lo producido; transferencia
--    vigente → lo transferido de ese ítem hacia la sucursal; cancelado → 0).
--    Guardarla sería un segundo saldo que diverge el día que el documento
--    cambie de estado por un camino que no avisa.
--
-- ── Una sola necesidad ABIERTA por (tenant, sucursal, ítem) ─────────────────
--
-- Cada venta debajo del mínimo volvería a dispararla. El SELECT previo no
-- alcanza: dos cajas vendiendo el mismo ítem a la vez leen "no hay abierta"
-- las dos. Lo garantiza el índice único PARCIAL, y el disparo inserta con
-- `ON CONFLICT ... DO NOTHING` contra él. Una necesidad cubierta o cerrada
-- sale del índice: si el saldo vuelve a caer, se abre otra.
--
-- El mínimo es UNO por ítem y se compara contra el saldo de CADA sucursal
-- (no por depósito), así que la sucursal es parte de la identidad.

ALTER TABLE item ADD COLUMN IF NOT EXISTS itemreplenishqty numeric(15,3);

COMMENT ON COLUMN item.itemreplenishqty IS
  'Cantidad a reponer o producir al llegar al stock mínimo. NULL = no dispara reposición.';

ALTER TABLE item DROP CONSTRAINT IF EXISTS item_replenish_qty_chk;
ALTER TABLE item ADD CONSTRAINT item_replenish_qty_chk
  CHECK (itemreplenishqty IS NULL OR itemreplenishqty > 0);

CREATE TABLE IF NOT EXISTS replenishment_need (
  needid       UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid    UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid     UUID          NOT NULL REFERENCES outlet(outletId) ON DELETE CASCADE,
  itemid       UUID          NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  quantity     NUMERIC(15,3) NOT NULL CHECK (quantity > 0),
  -- De dónde salió: el mínimo (cualquier movimiento), el conteo del panel o
  -- el de la caja (D8).
  origin       VARCHAR(16)   NOT NULL
                 CHECK (origin IN ('min_stock','count_panel','count_register')),
  -- Id del conteo cuando el origen es un conteo. Sin FK: el conteo es un
  -- documento con su propio ciclo de vida y la necesidad solo lo referencia.
  sourceid     UUID,
  -- Saldo de la sucursal en el momento del disparo. Contexto para quien la
  -- cubre ("quedaban 4"), no se recalcula.
  onhandat     NUMERIC(15,3),
  status       VARCHAR(10)   NOT NULL DEFAULT 'open'
                 CHECK (status IN ('open','covered','closed')),
  closereason  TEXT,
  createdby    UUID,
  closedby     UUID,
  createdat    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updatedat    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  coveredat    TIMESTAMPTZ,
  closedat     TIMESTAMPTZ,
  -- El cierre manual EXIGE motivo, en la BD y no solo en el form.
  CONSTRAINT replenishment_need_close_reason_chk
    CHECK (status <> 'closed' OR (closereason IS NOT NULL AND btrim(closereason) <> ''))
);

CREATE UNIQUE INDEX IF NOT EXISTS uidx_replenishment_need_open
    ON replenishment_need (companyid, outletid, itemid)
 WHERE status = 'open';

CREATE INDEX IF NOT EXISTS idx_replenishment_need_list
    ON replenishment_need (companyid, status, outletid, createdat DESC);

CREATE TABLE IF NOT EXISTS replenishment_need_coverage (
  coverageid   UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  needid       UUID          NOT NULL REFERENCES replenishment_need(needid) ON DELETE CASCADE,
  companyid    UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  sourcetype   VARCHAR(20)   NOT NULL
                 CHECK (sourcetype IN ('production_order','stock_transfer')),
  sourceid     UUID          NOT NULL,
  -- Cuánto se PLANEÓ cubrir con este documento (lo que se pidió producir o
  -- transferir). Lo efectivo se deriva del documento, ver arriba.
  quantity     NUMERIC(15,3) NOT NULL CHECK (quantity > 0),
  createdby    UUID,
  createdat    TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CONSTRAINT uq_replenishment_need_coverage UNIQUE (needid, sourcetype, sourceid)
);

-- Al cambiar de estado un documento (orden completada/cancelada, transferencia
-- cancelada) se buscan las necesidades que cubre por acá.
CREATE INDEX IF NOT EXISTS idx_replenishment_need_coverage_source
    ON replenishment_need_coverage (companyid, sourcetype, sourceid);
