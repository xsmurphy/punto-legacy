-- 236_wallet_load_reversal.sql — Wallet: anular o devolver una venta de CARGA
-- revierte el saldo acreditado (context/74 §13, gap de F2).
--
-- El bug que cierra: la carga es una venta con línea `walletLoad` y su `load`
-- se escribe dentro de la venta (§12.2). Anular esa venta (SaleVoidService) o
-- devolverla con nota de crédito (ReturnService) no tocaba el bolsillo: el
-- cliente quedaba con saldo que nadie pagó.
--
-- ── Por qué un TIPO propio y no un `adjust` con origen de anulación ─────────
--
--   - `adjust` es la corrección MANUAL de un error (motivo libre, autor, sin
--     documento detrás). La reversa de una carga es un efecto AUTOMÁTICO de un
--     documento fiscal (la anulación o la nota de crédito): mezclarlos haría que
--     los reportes tengan que adivinar por `sourcetype` qué ajuste fue una
--     corrección y cuál una devolución — y "Cargado" del reporte de bolsillos
--     necesita restar exactamente las devoluciones, no los ajustes.
--   - Las dos invariantes de la reversa solo se pueden expresar en la BD si el
--     tipo existe: apunta a UNA carga concreta (`reversesmovementid`), del mismo
--     cliente y bolsillo, y lo revertido de esa carga nunca supera lo cargado.
--     Con `adjust` no hay a qué atarlas.
--
-- ── Qué agrega ──────────────────────────────────────────────────────────────
--
--   - `type` pasa de VARCHAR(10) a VARCHAR(20) ('load_reversal' tiene 13).
--     Ampliar un varchar es cambio de catálogo: no reescribe filas y no dispara
--     el trigger append-only (es DDL, no UPDATE).
--   - `reversesmovementid`: la carga que se revierte. Obligatorio si y solo si
--     el tipo es `load_reversal`.
--   - CHECK de tipo y de signo ampliados: `load_reversal` siempre negativo, con
--     origen (`sourcetype`/`sourceid` = la anulación o la nota de crédito).
--   - Trigger: la carga referida es un `load` del MISMO comercio, cliente y
--     bolsillo, y la suma de sus reversas no supera su monto. Es la red debajo
--     de `WalletService::reverseLoads()`, que ya lo chequea bajo el lock del
--     bolsillo; el "nunca negativo" lo sigue garantizando el CHECK de
--     `balanceafter` que ya existe.

BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE wallet_movement
  ALTER COLUMN type TYPE VARCHAR(20);

ALTER TABLE wallet_movement
  ADD COLUMN IF NOT EXISTS reversesmovementid UUID REFERENCES wallet_movement(id);

COMMENT ON COLUMN wallet_movement.reversesmovementid IS
  'Wallet (context/74 §13): en un load_reversal, la carga (type=load) que '
  'revierte. NULL en cualquier otro tipo.';

ALTER TABLE wallet_movement DROP CONSTRAINT IF EXISTS wallet_movement_type_chk;
ALTER TABLE wallet_movement
  ADD CONSTRAINT wallet_movement_type_chk
  CHECK (type IN ('load', 'load_reversal', 'transfer', 'spend', 'refund', 'adjust'));

ALTER TABLE wallet_movement DROP CONSTRAINT IF EXISTS wallet_movement_sign_chk;
ALTER TABLE wallet_movement
  ADD CONSTRAINT wallet_movement_sign_chk
  CHECK (
    (type = 'load'          AND amount > 0) OR
    (type = 'refund'        AND amount > 0) OR
    (type = 'spend'         AND amount < 0) OR
    (type = 'load_reversal' AND amount < 0) OR
    (type IN ('transfer', 'adjust'))
  );

-- La reversa sin la carga que revierte, o sin el documento que la originó, no
-- se puede auditar.
ALTER TABLE wallet_movement DROP CONSTRAINT IF EXISTS wallet_movement_load_reversal_chk;
ALTER TABLE wallet_movement
  ADD CONSTRAINT wallet_movement_load_reversal_chk
  CHECK (
    (type = 'load_reversal') = (reversesmovementid IS NOT NULL)
    AND (type <> 'load_reversal' OR (sourcetype IS NOT NULL AND sourceid IS NOT NULL))
  );

-- "¿Cuánto se revirtió ya de esta carga?" — lo pregunta cada anulación y cada
-- devolución, bajo el lock del bolsillo.
CREATE INDEX IF NOT EXISTS idx_wallet_movement_reverses
    ON wallet_movement (reversesmovementid)
 WHERE reversesmovementid IS NOT NULL;

-- La reversa apunta a una carga del mismo cliente y bolsillo, y nunca revierte
-- más de lo que se cargó. Concurrencia: dos reversas de la misma carga tocan el
-- mismo bolsillo, así que el `pg_advisory_xact_lock` de WalletService las
-- serializa antes de llegar acá (mismo contrato que el trigger de encadenado).
CREATE OR REPLACE FUNCTION fn_wallet_movement_load_reversal_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_load     wallet_movement%ROWTYPE;
  v_reversed numeric(15,2);
BEGIN
  SELECT * INTO v_load FROM wallet_movement WHERE id = NEW.reversesmovementid;
  IF NOT FOUND OR v_load.type <> 'load' THEN
    RAISE EXCEPTION 'wallet_load_reversal_not_a_load';
  END IF;
  IF v_load.companyid <> NEW.companyid
     OR v_load.contactid <> NEW.contactid
     OR v_load.pocketid  <> NEW.pocketid THEN
    RAISE EXCEPTION 'wallet_load_reversal_other_pocket';
  END IF;

  SELECT COALESCE(SUM(-amount), 0) INTO v_reversed
    FROM wallet_movement
   WHERE reversesmovementid = NEW.reversesmovementid;

  IF v_reversed + (-NEW.amount) > v_load.amount THEN
    RAISE EXCEPTION 'wallet_load_reversal_exceeds_load'
      USING DETAIL = format('cargado=%s revertido=%s nuevo=%s', v_load.amount, v_reversed, -NEW.amount);
  END IF;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_wallet_movement_load_reversal_guard ON wallet_movement;
CREATE TRIGGER trg_wallet_movement_load_reversal_guard
  BEFORE INSERT ON wallet_movement
  FOR EACH ROW
  WHEN (NEW.type = 'load_reversal')
  EXECUTE FUNCTION fn_wallet_movement_load_reversal_guard();

COMMIT;
