-- 188_cost_center_checks_loans.sql
-- Centro de costo en los CHEQUES manuales y en los PRÉSTAMOS.
--
-- PEDIDO DEL OWNER (2026-09-04): las compras y los movimientos manuales ya
-- permiten elegir centro de costo desde la mig 167; los cheques cargados a
-- mano y los créditos, no. La misma taxonomía (`fin_cost_center`) se extiende
-- a esas dos entidades.
--
-- ============================================================
-- POR QUÉ EN `fin_check` / `fin_loan` Y NO SOLO EN `fin_movement`
-- ============================================================
--
-- El centro de costo ya vive en `fin_movement` (mig 167) — que es donde se
-- REPORTA el gasto. Pero un cheque y un préstamo se cargan ANTES de que
-- exista el movimiento: el cheque nace 'pending' y solo genera movimiento al
-- efectivizarse (`CheckService::ensureMovement()`), y el préstamo genera un
-- movimiento por CUOTA al pagarse (`LoanService::payInstallment()`). Sin la
-- columna acá, el operador tendría que volver a clasificar cada cuota a mano
-- después de pagarla — o el movimiento quedaría sin centro para siempre.
--
-- Es el mismo patrón que ya sigue `fin_check.categoryid`: el dato se elige en
-- la entidad de origen y VIAJA al movimiento derivado cuando este nace.
--
-- ============================================================
-- EL PRÉSTAMO LO LLEVA EN LA CABECERA, NO POR CUOTA
-- ============================================================
--
-- `fin_loan_installment` NO recibe columna. Un crédito se toma para UN
-- destino (la obra, el área, el equipamiento) y las 36 cuotas van todas ahí:
-- elegir centro cuota por cuota sería 36 decisiones idénticas con 36
-- oportunidades de equivocarse, y ningún caso real de negocio que lo pida.
-- Las cuotas HEREDAN el centro de la cabecera al pagarse. Si el día de mañana
-- aparece el caso de una cuota imputada distinto, se agrega el override en la
-- cuota — no al revés.
--
-- ============================================================
-- SIN ÍNDICE
-- ============================================================
--
-- El filtro y el GROUP BY por centro de costo se hacen SIEMPRE sobre
-- `fin_movement` (es el libro consolidado y el único con `date` indexado para
-- eso — ver `MovementService::byCostCenter()`). Nadie lista "los cheques del
-- centro X": la pantalla de cheques filtra por dirección, estado y
-- vencimiento. Un índice acá sería costo de escritura sin lectura que lo use.

BEGIN;

-- ============================================================
-- 1. `fin_check.costcenterid`
-- ============================================================
--
-- NULLABLE, igual que `fin_movement.costcenterid` (mig 167): el centro es
-- OPCIONAL en todas las superficies de carga y no hay backfill — los cheques
-- históricos quedan sin imputar.
--
-- Los cheques nacidos de una COMPRA lo heredan de la compra
-- (`FinanceLedger::recordPurchase()` → `createCheckFromLines()`), igual que ya
-- heredan la categoría. Los de una VENTA no traen centro: un ingreso no se
-- imputa a un centro de gasto.
--
-- FK real por el mismo motivo que en la mig 167: tabla chica, local al
-- comercio, y el borrado de un centro es SOFT (status=0) — la FK garantiza
-- que nadie lo borre físicamente dejando cheques apuntando al vacío.
ALTER TABLE fin_check
    ADD COLUMN IF NOT EXISTS costcenterid uuid REFERENCES fin_cost_center(costcenterid);

COMMENT ON COLUMN fin_check.costcenterid IS
    'Centro de costo del cheque. OPCIONAL. Viaja al `fin_movement` que se '
    'genera al efectivizarse (status=cleared). Los cheques emitidos por una '
    'compra lo heredan de la compra. Ver mig 188.';

-- ============================================================
-- 2. `fin_loan.costcenterid`
-- ============================================================
--
-- En la CABECERA del préstamo (ver arriba). Cada cuota pagada copia este
-- valor al `fin_movement` que genera `LoanService::payInstallment()`.
ALTER TABLE fin_loan
    ADD COLUMN IF NOT EXISTS costcenterid uuid REFERENCES fin_cost_center(costcenterid);

COMMENT ON COLUMN fin_loan.costcenterid IS
    'Centro de costo del crédito. OPCIONAL. Se elige UNA vez en la cabecera y '
    'todas las cuotas lo heredan al pagarse — `fin_loan_installment` no lleva '
    'columna propia a propósito. Ver mig 188.';

COMMIT;
