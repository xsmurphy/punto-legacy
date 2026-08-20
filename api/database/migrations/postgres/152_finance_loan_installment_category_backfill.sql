-- 152_finance_loan_installment_category_backfill.sql
-- Finanzas: la categoría de `fin_movement` NO es obligatoria (decisión del
-- owner 2026-08-20, ver context/22-finanzas-module-plan.md) — sigue siendo
-- nullable en el schema. Un movimiento `source='transfer'` legítimamente no
-- lleva categoría (mover plata entre cuentas propias no es ni gasto ni
-- ingreso real, ver `MovementService::transfer()`); esta migración NO toca
-- esos.
--
-- Verificado contra prod (solo lectura, 2026-08-18): 9 movimientos sin
-- categoría hoy — 8 `source='transfer'` (correctos, quedan así) + 1
-- `source='loan_installment'` (companyid=019ead57-13f2-7370-94fc-d158e396662a,
-- movementid=fb5afbd8-2b19-4f4f-ac45-c2da52142864, "Cuota 1 — Prestamo
-- Operaciones", 4166666.67 Gs). Esa fila quedó sin categoría porque
-- `LoanService::payInstallment()` no la pasaba a
-- `MovementService::recordDerivedMovement()` (fix en la misma sesión) — es la
-- ÚNICA fila que esta migración corrige, no un backfill masivo.
--
-- Idempotente y genérica por diseño: cualquier OTRO tenant que ya tenga (o
-- llegue a tener, si esta migración corre antes que el fix de código en
-- algún ambiente) un movimiento loan_installment sin categoría recibe el
-- mismo tratamiento — asegura la categoría default "Préstamos" (expense,
-- issystem) y se la asigna. No inventa categorías para ningún otro `source`.

BEGIN;

-- 1) Asegura la categoría "Préstamos" (expense, issystem) en cada tenant que
--    tiene al menos un movimiento loan_installment sin categorizar.
INSERT INTO fin_category (categoryid, companyid, name, kind, sortorder, issystem, status, created_at)
SELECT gen_random_uuid(), t.companyid, 'Préstamos', 'expense', 7, true, 1, now()
  FROM (
        SELECT DISTINCT companyid
          FROM fin_movement
         WHERE source = 'loan_installment' AND categoryid IS NULL
       ) t
 WHERE NOT EXISTS (
        SELECT 1 FROM fin_category c
         WHERE c.companyid = t.companyid AND c.kind = 'expense' AND c.name = 'Préstamos'
       );

-- 2) Backfill: asigna esa categoría a los movimientos loan_installment
--    huérfanos (y únicamente a ellos — el WHERE replica exactamente el
--    criterio verificado contra prod arriba).
UPDATE fin_movement m
   SET categoryid = c.categoryid
  FROM fin_category c
 WHERE m.source = 'loan_installment'
   AND m.categoryid IS NULL
   AND c.companyid = m.companyid
   AND c.kind = 'expense'
   AND c.name = 'Préstamos';

COMMIT;
