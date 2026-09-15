-- 222_planes_sin_versionado.sql
-- Consolida los planes ARCHIVADOS por el versionado de F4 (context/34 §F4,
-- SUPERSEDED 2026-09-15).
--
-- ── Qué cambió ──────────────────────────────────────────────────────────
-- Owner 2026-09-15: "un cambio en el plan aplica a todos los que están atados
-- a ese plan". `PlanAdminService::update()` ahora edita la fila en el lugar.
-- El versionado viejo clonaba el plan por cualquier campo con un plan_code
-- nuevo y archivaba el anterior SIN mover a los tenants: en producción el
-- Trial se editó para dar 500 créditos (plan 5) y los tenants siguieron en el
-- Trial archivado (plan 3) con 0. Esta migración deja un solo plan vivo por
-- cada línea que el versionado partió.
--
-- ── La regla ────────────────────────────────────────────────────────────
-- Por cada `type` que tiene al menos un plan archivado y EXACTAMENTE UN plan
-- vigente (archived = 0):
--   1. Sobrevive el plan_code MÁS BAJO del type, con los términos del VIGENTE
--      (nombre, precio, duración, límites, features, créditos).
--   2. Los tenants de los demás plan_code del type pasan al sobreviviente.
--   3. `billing_request` (requested/currentPlanCode) se re-apunta igual, para
--      que ninguna solicitud quede señalando un plan que ya no existe.
--   4. Las demás filas del type se BORRAN. Nada referencia `plans.id`.
--
-- Por qué sobrevive el código más bajo y no el vigente: el código original es
-- el que conoce el resto del sistema. `SignupService` asigna `plan = 3` fijo
-- a todo alta (seed de la mig 13, presente en todos los entornos). Si
-- sobreviviera el 5 y se borrara el 3, cada alta nueva quedaría con un plan
-- inexistente → límites en 0 → `LIMIT 0` en los listados (el crash que
-- motivó la mig 13). Para los tenants el resultado es idéntico: un solo plan
-- Trial con los términos vigentes (500 créditos).
--
-- Un `type` con archivados pero con CERO o VARIOS vigentes NO se toca: no hay
-- un equivalente único y moverlo sería adivinar. Se informa con NOTICE y
-- queda para que un admin lo resuelva desde /admin.
--
-- El plan_code 0 (Free) queda fuera: su índice único es parcial
-- (`WHERE plan_code != 0`, mig 10) y nunca fue versionado.
--
-- Créditos IA: esta migración NO acredita nada. La recarga mensual
-- (`PlanLifecycleService::rechargeMonthlyAiCredits`) lee el plan en vivo y
-- acredita una vez por período; un tenant movido desde un plan con 0 recibe
-- los créditos del período corriente en la próxima corrida del job, y uno que
-- ya fue acreditado este período no recibe nada de nuevo.
--
-- `plans.archived` queda como columna de historial: nada nuevo la escribe.
--
-- Idempotente: la segunda corrida no encuentra archivados consolidables.

BEGIN;

DO $$
DECLARE
    g         RECORD;
    v         RECORD;
    leftover  RECORD;
    survivor  SMALLINT;
    removed   SMALLINT[];
    moved     INTEGER;
BEGIN
    FOR g IN
        SELECT type
          FROM plans
         WHERE plan_code <> 0
         GROUP BY type
        HAVING count(*) FILTER (WHERE archived = 1) > 0
           AND count(*) FILTER (WHERE archived = 0) = 1
    LOOP
        SELECT * INTO v
          FROM plans
         WHERE type = g.type AND plan_code <> 0 AND archived = 0;

        SELECT min(plan_code) INTO survivor
          FROM plans
         WHERE type = g.type AND plan_code <> 0;

        SELECT array_agg(plan_code) INTO removed
          FROM plans
         WHERE type = g.type AND plan_code <> 0 AND plan_code <> survivor;

        IF survivor <> v.plan_code THEN
            UPDATE plans
               SET name               = v.name,
                   price              = v.price,
                   duration_days      = v.duration_days,
                   max_items          = v.max_items,
                   max_users          = v.max_users,
                   max_customers      = v.max_customers,
                   max_outlets        = v.max_outlets,
                   max_registers      = v.max_registers,
                   max_suppliers      = v.max_suppliers,
                   max_categories     = v.max_categories,
                   max_brands         = v.max_brands,
                   features           = v.features,
                   ai_credits_monthly = v.ai_credits_monthly,
                   archived           = 0
             WHERE plan_code = survivor;
        END IF;

        UPDATE company SET plan = survivor WHERE plan = ANY (removed);
        GET DIAGNOSTICS moved = ROW_COUNT;

        UPDATE billing_request SET requestedplancode = survivor WHERE requestedplancode = ANY (removed);
        UPDATE billing_request SET currentplancode   = survivor WHERE currentplancode   = ANY (removed);

        DELETE FROM plans WHERE plan_code = ANY (removed);

        RAISE NOTICE 'mig 222: type "%" consolidado en plan_code % con los términos del plan %; borrados %; tenants movidos %',
            g.type, survivor, v.plan_code, removed, moved;
    END LOOP;

    FOR leftover IN
        SELECT plan_code, name, type
          FROM plans
         WHERE archived = 1
         ORDER BY plan_code
    LOOP
        RAISE NOTICE 'mig 222: plan archivado % "%" (type "%") sin un vigente único equivalente — no se tocó',
            leftover.plan_code, leftover.name, leftover.type;
    END LOOP;
END $$;

COMMIT;
