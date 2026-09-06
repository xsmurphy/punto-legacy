-- 196_payment_order.sql
-- ORDEN DE PAGO — el documento que AUTORIZA pagarle a un proveedor.
--
-- Hoy existe el PAGO (`CreditPaymentService`, recibo `transaction` type=5, un
-- recibo saldando N facturas vía `transaction_link kind='purchase_payment'`),
-- pero no existe el documento PREVIO: el que agrupa N facturas de compra a
-- crédito pendientes, alguien con autoridad aprueba, y recién después se
-- ejecuta. Esta migración crea ese documento.
--
-- ============================================================
-- LO QUE ESTA FEATURE NO HACE: PAGAR
-- ============================================================
--
-- La orden de pago NO reimplementa el recibo, ni el asiento en Finanzas, ni la
-- imputación contra las facturas, ni la anulación. Al ejecutarse, traduce sus
-- líneas al array `allocations` que `CreditPaymentService::create()` ya recibe
-- y lo llama. Por eso `payment_order_line` tiene EXACTAMENTE el shape de una
-- allocation — `(transactionid, amount)` — y no un shape propio: ejecutar es
-- traducir, no recalcular. Cualquier columna que se agregue acá y que el
-- servicio de pagos no sepa consumir es la señal de que la feature se está
-- convirtiendo en un duplicado del módulo de pagos.
--
-- `paymenttransactionid` es el puente hacia ese mundo: apunta al recibo type=5
-- que efectivamente ejecutó la orden. Sin FK declarada porque `transaction`
-- está PARTICIONADA (mig 156) y una FK a tabla particionada obliga a que la
-- clave incluya la columna de partición; el vínculo se resuelve por query, que
-- es el mismo criterio que ya usa `transaction_link`.
--
-- ============================================================
-- EL INVARIANTE QUE NO PUEDE VIVIR EN EL SERVICIO
-- ============================================================
--
-- Una factura de compra NO puede estar en dos órdenes de pago VIVAS
-- (borrador o aprobada) a la vez: si lo estuviera, se pagaría dos veces.
--
-- Chequear eso con un SELECT en el servicio es una race: dos requests
-- concurrentes leen "no está en ninguna" y las dos insertan. La única forma
-- honesta de expresarlo es un índice único, y un índice único parcial no puede
-- mirar el `status` de OTRA tabla.
--
-- Por eso `payment_order_line.orderstatus` es un ESPEJO de
-- `payment_order.status`. No es una denormalización que el servicio mantiene
-- —eso volvería a depender de que nadie se olvide—: lo mantienen dos triggers,
-- así que la columna es DERIVADA y el servicio no puede escribirla ni
-- falsificarla. El índice único parcial sobre `('draft','approved')` hace el
-- resto, y lo hace bajo el lock de Postgres, no bajo el optimismo del caller.
--
-- Corolario deliberado: cancelar o pagar una orden LIBERA sus facturas para
-- otra orden. Una orden `cancelled` no bloquea nada (la factura sigue impaga y
-- alguien tiene que poder rearmarla) y una `paid` tampoco necesita bloquear
-- (la factura ya quedó saldada o con saldo menor, y ese saldo es el que
-- `CreditPaymentService` revalida al ejecutar la orden siguiente).
--
-- ============================================================
-- LO QUE NO SE GUARDA ACÁ, A PROPÓSITO
-- ============================================================
--
-- - El SALDO de cada factura. Es un derivado vivo (`transactionTotal` menos
--   `TransactionLinkService::paidForCreditOrigins()`) y congelarlo acá crearía
--   una segunda fuente de verdad que envejece: entre que se arma la orden y se
--   paga, alguien pudo cobrar esa factura por otro lado. Se revalida al
--   APROBAR y al EJECUTAR contra el saldo real, nunca contra una foto.
-- - El método de pago. Se elige al EJECUTAR, no al autorizar: la orden dice
--   "pagale esto a este proveedor", no "pagalo con este cheque".
-- - Moneda. El tenant tiene una sola (multi-moneda es `context/42`, sin
--   planificar). Agregar la columna acá sin el motor detrás sería un campo que
--   nadie valida.
--
-- ============================================================
-- CASING
-- ============================================================
--
-- Tablas NUEVAS: lowercase sin comillas, igual que `inventory_count` (mig 150)
-- y `fin_*`. Las 18 tablas camelCase entrecomilladas son legado; `transaction`
-- es una de ellas, y por eso las referencias a ella en el código PHP van con
-- su casing original — pero NINGUNA columna de estas dos tablas lo lleva.
--
-- Idempotente (IF NOT EXISTS en todo, CREATE OR REPLACE en las funciones).

BEGIN;

CREATE TABLE IF NOT EXISTS payment_order (
  paymentorderid       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid            uuid          NOT NULL,
  outletid             uuid          NOT NULL,
  supplierid           uuid          NOT NULL,

  -- Correlativo interno (context/37, `DocumentNumber::allocate`), scope
  -- `outlet` — mismo scope que ya tienen la remisión y los documentos de
  -- stock, y por el mismo motivo: una orden de pago se emite desde
  -- panel/backoffice, sin caja de por medio (`PurchasesService` nunca setea
  -- `registerId` en una compra). NO es documento fiscal: no toca timbrado ni
  -- SIFEN. NULL solo sería posible si la secuencia fallara, y en ese caso la
  -- transacción del alta se revierte entera.
  docnumber            bigint        NULL,

  status               varchar(16)   NOT NULL DEFAULT 'draft',
  total                numeric(14,2) NOT NULL DEFAULT 0,

  -- Fecha en la que se propone pagar. Es del DOCUMENTO (cuándo hay que
  -- desembolsar), no de las facturas (cada una tiene su propio vencimiento).
  paymentdate          date          NULL,
  notes                text          NULL,

  createdby            uuid          NOT NULL,
  created_at           timestamptz   NOT NULL DEFAULT now(),
  updated_at           timestamptz   NOT NULL DEFAULT now(),

  approvedby           uuid          NULL,
  approved_at          timestamptz   NULL,

  paidby               uuid          NULL,
  paid_at              timestamptz   NULL,
  -- Recibo type=5 que ejecutó esta orden. Ver docblock: sin FK por partición.
  paymenttransactionid uuid          NULL,

  cancelledby          uuid          NULL,
  cancelled_at         timestamptz   NULL,
  cancelreason         text          NULL,

  CONSTRAINT chk_payment_order_status
    CHECK (status IN ('draft', 'approved', 'paid', 'cancelled')),

  -- Cancelar EXIGE motivo — mismo criterio que la anulación de ítems de
  -- comanda (`OrderCoreService::updateStatus`, mig 190): un estado terminal
  -- que borra trabajo se explica o no se aplica. En el CHECK y no solo en el
  -- servicio porque es una regla del documento, no de un endpoint.
  CONSTRAINT chk_payment_order_cancel_reason
    CHECK (status <> 'cancelled'
           OR (cancelreason IS NOT NULL AND btrim(cancelreason) <> '')),

  -- Un estado terminal sin su atribución es una auditoría que no sirve.
  CONSTRAINT chk_payment_order_approved_attribution
    CHECK (status <> 'approved' OR (approvedby IS NOT NULL AND approved_at IS NOT NULL)),
  CONSTRAINT chk_payment_order_cancelled_attribution
    CHECK (status <> 'cancelled' OR (cancelledby IS NOT NULL AND cancelled_at IS NOT NULL)),
  -- Una orden pagada SIN recibo sería una orden que dice haber movido plata
  -- sin poder mostrar cuál. `CreditPaymentService` es el único que produce ese
  -- id, así que este CHECK es también la garantía de que nadie marcó `paid` a
  -- mano sin pasar por él.
  CONSTRAINT chk_payment_order_paid_attribution
    CHECK (status <> 'paid'
           OR (paidby IS NOT NULL AND paid_at IS NOT NULL AND paymenttransactionid IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS payment_order_line (
  paymentorderlineid uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid          uuid          NOT NULL,
  paymentorderid     uuid          NOT NULL
    REFERENCES payment_order (paymentorderid) ON DELETE CASCADE,
  -- La factura de compra a crédito (`transaction`, transactionType=4). Sin FK:
  -- `transaction` está particionada (mig 156).
  transactionid      uuid          NOT NULL,
  amount             numeric(14,2) NOT NULL,
  -- DERIVADA: espejo de payment_order.status, mantenida por los dos triggers
  -- de abajo. El servicio NUNCA la escribe. Existe únicamente para que el
  -- índice único parcial pueda expresar "viva" sin mirar la otra tabla.
  orderstatus        varchar(16)   NOT NULL DEFAULT 'draft',
  created_at         timestamptz   NOT NULL DEFAULT now(),

  CONSTRAINT chk_payment_order_line_amount CHECK (amount > 0)
);

-- ── El invariante: una factura en UNA sola orden viva ──────────────────────
CREATE UNIQUE INDEX IF NOT EXISTS uidx_payment_order_line_live_invoice
  ON payment_order_line (companyid, transactionid)
  WHERE orderstatus IN ('draft', 'approved');

-- Una factura no puede repetirse DENTRO de la misma orden (dos líneas para la
-- misma factura serían dos allocations que `CreditPaymentService` mergearía
-- sumando — el total de la orden y lo realmente imputado divergirían).
CREATE UNIQUE INDEX IF NOT EXISTS uidx_payment_order_line_unique_in_order
  ON payment_order_line (paymentorderid, transactionid);

CREATE INDEX IF NOT EXISTS idx_payment_order_company_status
  ON payment_order (companyid, status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_payment_order_company_supplier
  ON payment_order (companyid, supplierid, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_payment_order_company_outlet
  ON payment_order (companyid, outletid, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_payment_order_line_order
  ON payment_order_line (paymentorderid);

-- ── Triggers que hacen de `orderstatus` un derivado real ───────────────────
--
-- BEFORE INSERT/UPDATE en la línea: el estado sale SIEMPRE de la cabecera, se
-- ignora lo que haya mandado el caller. Así una línea insertada en una orden
-- ya aprobada nace 'approved' y entra al índice único con el valor correcto.
CREATE OR REPLACE FUNCTION fn_payment_order_line_status() RETURNS trigger AS $$
BEGIN
  SELECT po.status INTO NEW.orderstatus
    FROM payment_order po
   WHERE po.paymentorderid = NEW.paymentorderid;
  IF NEW.orderstatus IS NULL THEN
    RAISE EXCEPTION 'payment_order_line: la orden % no existe', NEW.paymentorderid;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_payment_order_line_status ON payment_order_line;
CREATE TRIGGER trg_payment_order_line_status
  BEFORE INSERT OR UPDATE ON payment_order_line
  FOR EACH ROW EXECUTE FUNCTION fn_payment_order_line_status();

-- AFTER UPDATE en la cabecera: propaga el cambio de estado a las líneas. Es lo
-- que hace que aprobar/cancelar/pagar entre o salga del índice único sin que
-- el servicio tenga que acordarse.
CREATE OR REPLACE FUNCTION fn_payment_order_propagate_status() RETURNS trigger AS $$
BEGIN
  IF NEW.status IS DISTINCT FROM OLD.status THEN
    UPDATE payment_order_line
       SET orderstatus = NEW.status
     WHERE paymentorderid = NEW.paymentorderid;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_payment_order_propagate_status ON payment_order;
CREATE TRIGGER trg_payment_order_propagate_status
  AFTER UPDATE ON payment_order
  FOR EACH ROW EXECUTE FUNCTION fn_payment_order_propagate_status();

-- ── Una orden PAGADA es inmutable ──────────────────────────────────────────
--
-- En trigger y no solo en el servicio: `paid` es el estado en el que la orden
-- ya movió plata real y tiene un recibo que la respalda. Editarla después
-- desincronizaría el documento de autorización del hecho económico que
-- autorizó. El servicio igual lo chequea antes (para dar un 422 con mensaje en
-- vez de un 500 del driver); esto es la red debajo.
CREATE OR REPLACE FUNCTION fn_payment_order_paid_immutable() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'UPDATE' AND OLD.status = 'paid' THEN
    RAISE EXCEPTION 'Una orden de pago ya ejecutada no se puede modificar';
  END IF;
  IF TG_OP = 'DELETE' AND OLD.status = 'paid' THEN
    RAISE EXCEPTION 'Una orden de pago ya ejecutada no se puede eliminar';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_payment_order_paid_immutable ON payment_order;
CREATE TRIGGER trg_payment_order_paid_immutable
  BEFORE UPDATE OR DELETE ON payment_order
  FOR EACH ROW EXECUTE FUNCTION fn_payment_order_paid_immutable();

COMMENT ON TABLE payment_order IS
  'Orden de pago a proveedor: el documento que AUTORIZA el pago. '
  'borrador -> aprobada -> pagada (+ cancelada). Al ejecutarse llama a '
  'CreditPaymentService::create() con sus lineas como allocations — no '
  'reimplementa el recibo ni el asiento en Finanzas. Ver context/modules/08-compras.md.';

COMMENT ON COLUMN payment_order.docnumber IS
  'Correlativo interno via DocumentNumber::allocate(doctype orden_pago, scope outlet). '
  'NO es documento fiscal: no toca timbrado ni SIFEN.';

COMMENT ON COLUMN payment_order.paymenttransactionid IS
  'Recibo (transaction type=5, kind purchase_payment) que ejecuto esta orden. '
  'Sin FK porque transaction esta particionada (mig 156).';

COMMENT ON COLUMN payment_order_line.orderstatus IS
  'DERIVADA — espejo de payment_order.status mantenido por trigger. El servicio '
  'nunca la escribe. Existe para que uidx_payment_order_line_live_invoice pueda '
  'impedir que una factura este en dos ordenes vivas sin mirar la otra tabla.';

COMMIT;
