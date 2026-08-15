-- 137_document_remision.sql
-- Nota de remisión (context/42-remision.md) — documento de traslado de
-- mercadería, sin conexión a SIFEN todavía (esa es una fase aparte).
--
-- QUÉ CUBRE esta tabla y qué NO:
--
-- El traslado entre sucursales/depósitos PROPIOS ya tiene su propio documento
-- completo (`stock_transfer`, migs 46/129): numerado, con movimiento de stock
-- de doble entrada (egreso+ingreso) y cancelación. Esa es la remisión para ese
-- motivo — no se duplica acá. `document_remision` cubre los motivos que
-- `stock_transfer` no modela porque el destino NO es un outlet propio:
-- venta, devolución a proveedor, consignación, exposición/demostración,
-- compra (recepción).
--
-- STOCK: ninguno de estos 5 motivos mueve stock desde esta tabla. Cada uno ya
-- tiene (o puede tener) su propio dueño del movimiento real:
--   - venta            → la factura, cuando se emite (SaleService).
--   - devolucion_proveedor → YA EXISTE `PurchaseCreditNoteService`
--     (transactionType 14, `affectsStock`) — mover stock acá también sería
--     duplicar el egreso si el comercio también carga la NC de compra.
--   - compra           → la compra (`PurchasesService`, types 1/4) ya suma
--     el stock al recibir la mercadería.
--   - consignacion / exposicion → no hay hoy una operación que consuma ese
--     stock; moverlo de la sucursal sería inventar un movimiento sin dueño.
--     Abierto para el owner (ver context/42 "Decisiones abiertas").
-- Esta tabla es documental: motivo, origen, destino, ítems+cantidad, fecha.
-- `transactionid` (nullable) linkea al documento que sí mueve plata/stock
-- cuando existe — la remisión puede emitirse ANTES que ese documento.
--
-- NUMERACIÓN: scope OUTLET, no REGISTER. `context/37` D2 (cerrada antes de
-- este plan) proponía scope register para remisión "por ser fiscal" — pero
-- la mayoría de estos motivos se emiten desde el panel/backoffice, sin caja
-- de por medio (traslado por compra, devolución a proveedor, consignación,
-- exposición). Forzar scope register dejaría esos flujos sin secuencia
-- utilizable. Se sigue el mismo criterio que F3 (mig 129) usó para
-- producción/merma/transferencia/conteo: outlet. Cuando llegue la fase SIFEN
-- y el timbrado exija punto de expedición, esa migración de scope se hace
-- entonces (mismo patrón que F2 hizo para factura/cotización) — ver
-- context/42 §"Qué queda para SIFEN".
--
-- Todo lowercase sin comillas — mismo estilo que voucher (mig 100),
-- transaction_link (mig 115), document_sequence (mig 117).

BEGIN;

CREATE TABLE IF NOT EXISTS document_remision (
  remisionid             UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid              UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  -- Campo de primera clase (no texto libre): condiciona qué otros campos
  -- aplican y es lo que SIFEN va a exigir en la fase de facturación
  -- electrónica. 'traslado_interno' NO es un valor válido acá a propósito
  -- — ese motivo vive en `stock_transfer` (ver docblock arriba).
  motivo                 VARCHAR(30)    NOT NULL,
  docnumber              BIGINT,
  outletid               UUID           NOT NULL REFERENCES outlet(outletid),
  locationid             UUID,
  -- Entidad que recibe la mercadería — cliente (venta) o proveedor
  -- (devolución). NULL cuando el destino no es un contacto del sistema
  -- (feria, exposición, depósito de un tercero sin ficha).
  destinationcontactid   UUID           REFERENCES contact(contactid),
  -- Texto libre del destino: dirección de entrega, lugar de exposición, o
  -- aclaración cuando no hay destinationcontactid. Nunca es la ÚNICA fuente
  -- del motivo — motivo ya es la columna tipada.
  destinationnote        TEXT,
  -- Documento que efectivamente mueve plata/stock, cuando existe: la
  -- factura (venta), la nota de crédito de compra (devolución a proveedor,
  -- transactionType 14) o la compra (compra). NULL si la remisión se emitió
  -- antes de que ese documento exista (caso típico, ver docblock del plan) o
  -- si el motivo no tiene una transacción asociada (consignación/exposición).
  transactionid          UUID           REFERENCES transaction(transactionId),
  transferdate           TIMESTAMPTZ    NOT NULL DEFAULT now(),
  status                 SMALLINT       NOT NULL DEFAULT 1, -- 1 activa, 0 cancelada
  note                   TEXT,
  createdby              UUID           NOT NULL,
  createdat              TIMESTAMPTZ    NOT NULL DEFAULT now(),
  CONSTRAINT document_remision_motivo_chk
    CHECK (motivo IN ('venta', 'devolucion_proveedor', 'consignacion', 'exposicion', 'compra')),
  CONSTRAINT document_remision_status_chk CHECK (status IN (0, 1))
);

CREATE INDEX IF NOT EXISTS idx_document_remision_company_date
  ON document_remision(companyid, transferdate DESC);
CREATE INDEX IF NOT EXISTS idx_document_remision_outlet
  ON document_remision(companyid, outletid, transferdate DESC);
CREATE INDEX IF NOT EXISTS idx_document_remision_transaction
  ON document_remision(transactionid);
CREATE INDEX IF NOT EXISTS idx_document_remision_contact
  ON document_remision(destinationcontactid);

-- Correlativo por sucursal (D2 arriba). Índice parcial: nunca debería haber
-- una fila sin docnumber (se asigna dentro de la misma TX del create), pero
-- es la misma red de contención que ya usan production_order/stock_transfer/etc.
CREATE UNIQUE INDEX IF NOT EXISTS uq_document_remision_docnumber
  ON document_remision(companyid, outletid, docnumber) WHERE docnumber IS NOT NULL;

CREATE TABLE IF NOT EXISTS document_remision_item (
  remisionitemid    UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  remisionid        UUID           NOT NULL REFERENCES document_remision(remisionid) ON DELETE CASCADE,
  itemid            UUID           NOT NULL REFERENCES item(itemid) ON DELETE RESTRICT,
  qty               NUMERIC(14,4)  NOT NULL,
  note              TEXT,
  CONSTRAINT document_remision_item_qty_positive CHECK (qty > 0)
);

CREATE INDEX IF NOT EXISTS idx_document_remision_item_remision ON document_remision_item(remisionid);
CREATE INDEX IF NOT EXISTS idx_document_remision_item_item ON document_remision_item(itemid);

-- Siembra de `document_sequence` (mig 117) para el doctype nuevo, scope
-- outlet — mismo patrón que la 3b de la 129 (re-seed de 'orden'). Tabla
-- recién creada, sin datos históricos que backfillear: arranca en 1 en todas
-- las sucursales existentes.
INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT o.companyid, 'remision', 'outlet', o.outletid, 1
FROM outlet o
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

COMMIT;
