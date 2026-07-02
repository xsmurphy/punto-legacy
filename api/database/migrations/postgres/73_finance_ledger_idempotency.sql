BEGIN;

-- ============================================================
-- Finanzas Fase 3 — idempotencia del ledger derivado con pago dividido.
-- Ver context/22-finanzas-module-plan.md §4 (plan cerrado).
--
-- Problema: una venta puede pagarse con varios métodos (efectivo + tarjeta),
-- cada uno resolviendo a una cuenta distinta. El UNIQUE original
-- (companyid, source, sourceid) solo permitía 1 movimiento por venta —
-- con split-payment el 2do INSERT (misma venta, otra cuenta) colisionaba.
--
-- Fix: agregar accountid al UNIQUE. Regla de negocio (FinanceLedger):
-- 1 movimiento por (origen, cuenta resuelta), sumando las líneas de pago
-- que caen en la misma cuenta. Con esto, 2 métodos → 2 cuentas → 2 filas
-- sin colisión; re-correr el backfill histórico es idempotente.
--
-- Columnas físicas en minúscula sin comillas (mismo criterio que mig 72).
-- ============================================================

DROP INDEX IF EXISTS uidx_fin_movement_source;

CREATE UNIQUE INDEX IF NOT EXISTS uidx_fin_movement_source
  ON fin_movement (companyid, source, sourceid, accountid)
  WHERE sourceid IS NOT NULL;

COMMIT;
