-- 189_plan_expired_at.sql
-- CUÁNDO venció el plan del tenant. Habilita el job `plan-lifecycle`
-- (P2 de context/34-admin-saas-plan.md §F7).
--
-- ============================================================
-- POR QUÉ NO ALCANZA `company.planExpired`
-- ============================================================
--
-- `planExpired` es un BOOLEAN: dice "está vencido", no "desde cuándo". La D5
-- del owner define 5 días de gracia entre el vencimiento y el bloqueo, y esos
-- 5 días no se pueden computar sobre un booleano. Sin esta columna el job
-- solo podría contar la gracia desde `expiresAt` — y eso es exactamente lo
-- que NO se quiere (ver abajo).
--
-- ============================================================
-- LA GRACIA SE CUENTA DESDE ACÁ, NO DESDE `expiresAt`. ES A PROPÓSITO
-- ============================================================
--
-- Medido en prod el 2026-09-05: 6 tenants con `expiresAt` pasado, todos con
-- `status='active'`, `blocked=0` y `planExpired=false`. El más viejo hace 74
-- días. Nadie los venció nunca porque nadie escribía `planExpired` (no había
-- job). Ninguno fue avisado.
--
-- Si la gracia se contara desde `expiresAt`, la PRIMERA corrida del job los
-- bloquearía a los 6 de una — 74 días de atraso, 5 de gracia, bloqueo — sin
-- una sola advertencia previa. Contándola desde `planExpiredAt`, que el job
-- escribe en el momento en que los MARCA, caen solos en el lugar correcto:
-- la primera corrida los marca vencidos (entran en gracia hoy) y recién 5
-- días después son candidatos a bloqueo. En el medio reciben el aviso de
-- entrada en gracia.
--
-- No es un efecto lateral afortunado: es la razón por la que la columna
-- existe. Cualquier cambio que haga arrancar la gracia desde `expiresAt`
-- rompe esa propiedad y bloquea cuentas de golpe.
--
-- ============================================================
-- NULLABLE, SIN BACKFILL
-- ============================================================
--
-- Un backfill `planExpiredAt = expiresAt` para los ya vencidos sería
-- justamente el bloqueo de golpe descrito arriba. Los tenants que hoy tienen
-- `planExpired = true` sin timestamp (si los hubiera) NUNCA se bloquean: el
-- job exige `planExpiredAt IS NOT NULL` en la rama de bloqueo. Fail-closed
-- hacia el lado seguro — no bloquear a nadie por falta de dato.
--
-- ============================================================
-- SIN ÍNDICE
-- ============================================================
--
-- El índice `idx_company_blocked (blocked, planExpired)` ya existe y es el que
-- filtra la rama de bloqueo; `planExpiredAt` solo refina un conjunto que en el
-- peor caso es "todos los tenants vencidos", del orden de decenas. `company`
-- es una tabla chica que se escribe en cada guardado de ficha: un índice más
-- sería costo de escritura sin lectura que lo justifique.

BEGIN;

ALTER TABLE company
    ADD COLUMN IF NOT EXISTS planExpiredAt TIMESTAMPTZ;

COMMENT ON COLUMN company.planExpiredAt IS
    'Momento en que el job `plan-lifecycle` marcó `planExpired = true`. Es el '
    'origen de los 5 días de gracia de la D5 (context/34 §F7) — NO se cuenta '
    'desde `expiresAt`, para que un tenant vencido hace meses entre en gracia '
    'al ser marcado y no se bloquee de golpe. NULL = nunca fue marcado por el '
    'job; en ese estado la rama de bloqueo lo ignora. Ver mig 189.';

COMMIT;
