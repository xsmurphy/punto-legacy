-- 232_wallet.sql
-- Wallet multi-nivel F1 — núcleo (context/74).
--
-- Dos tablas y una columna, y nada más (§3.5): sin campo `balance`, sin
-- `wallet_limit`. El saldo de un bolsillo ES la suma de sus movimientos, y el
-- tope ES el saldo (§3.3) — por eso un bolsillo nunca puede quedar negativo.
--
-- Las invariantes de §8 que se pueden expresar en la BD viven ACÁ y no solo en
-- `WalletService`. El servicio las chequea antes para dar un 422 con mensaje;
-- esto es la red debajo, para el día que alguien escriba por otro camino:
--
--   - nunca negativo            → CHECK (balanceafter >= 0)
--   - saldo = suma              → trigger: balanceafter = anterior + amount
--   - append-only               → trigger: UPDATE/DELETE lanzan
--   - cada movimiento con autor → actorcontactid NOT NULL
--   - jerarquía de un nivel y dentro del comercio → trigger en `contact`
--
-- Lo que NO puede vivir acá y vive en el servicio: la concurrencia (el
-- `pg_advisory_xact_lock` por contacto+bolsillo que serializa leer-y-escribir)
-- y la atomicidad de la transferencia (dos INSERT en una transacción).

SET LOCAL lock_timeout = '10s';

-- ═══════════════════════════════════════════════════════════════════════
-- 1. contact.parentcontactid — la jerarquía vive en el contacto (§3.1, D2)
-- ═══════════════════════════════════════════════════════════════════════
--
-- NULL = titular. Con valor = hijo de ese contacto. Es la ÚNICA fuente de la
-- jerarquía: la wallet no guarda su propio padre.
--
-- Columna NUEVA y no la `contact.parentId` que ya existe en el schema base: esa
-- es legacy, ningún servicio la escribe ni la lee, y la mig 08 la recorrió con
-- semántica de franquicia (companyId del franquiciador). Reusarla heredaría
-- valores de un significado que no es este; un hijo mal inferido debitaría el
-- saldo de otra familia.
--
-- Sin ON DELETE: los contactos se archivan (contactStatus), no se borran. Un
-- titular con hijos no se puede borrar físicamente — el único borrado físico es
-- la purga del tenant entero (`CompanyAdminService::hardDelete()`), que rompe
-- esta FK primero, igual que hace con `contact.parentId`.

ALTER TABLE contact
  ADD COLUMN IF NOT EXISTS parentcontactid UUID REFERENCES contact(contactid);

-- No auto-referencia. El resto de la regla (mismo comercio, un solo nivel) no
-- entra en un CHECK porque mira otras filas: va en el trigger de abajo.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conname = 'contact_parent_not_self_chk'
       AND conrelid = 'contact'::regclass
  ) THEN
    ALTER TABLE contact
      ADD CONSTRAINT contact_parent_not_self_chk
      CHECK (parentcontactid IS NULL OR parentcontactid <> contactid);
  END IF;
END $$;

-- "Los hijos de este titular". Parcial: la enorme mayoría de los contactos son
-- titulares (NULL) y no tienen por qué pagar el índice.
CREATE INDEX IF NOT EXISTS idx_contact_parentcontact
    ON contact (companyid, parentcontactid)
 WHERE parentcontactid IS NOT NULL;

COMMENT ON COLUMN contact.parentcontactid IS
  'Wallet (context/74 §3.1): NULL = titular; con valor = hijo de ese contacto. '
  'Un solo nivel y dentro del mismo comercio (trigger trg_contact_parent_guard).';

-- Mismo comercio y un solo nivel. Solo corre cuando se SETEA un padre (WHEN en
-- el trigger), así que el resto de los INSERT/UPDATE de contact no lo pagan.
--
-- Carrera: la fila que se actualiza ya está bloqueada por el propio UPDATE, y
-- la del padre se lee `FOR SHARE`. Así, si en paralelo alguien le pone padre al
-- padre (UPDATE → lock exclusivo de esa fila), este trigger espera a que
-- confirme y ve el valor nuevo; y si alguien cuelga un hijo de ESTE contacto,
-- su trigger espera por el lock de esta fila y ve el padre recién puesto. En
-- los dos casos uno de los dos se rechaza: no quedan dos niveles.
CREATE OR REPLACE FUNCTION fn_contact_parent_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_parent_company uuid;
  v_parent_parent  uuid;
BEGIN
  SELECT companyid, parentcontactid
    INTO v_parent_company, v_parent_parent
    FROM contact
   WHERE contactid = NEW.parentcontactid
     FOR SHARE;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'wallet_parent_not_found';
  END IF;
  IF v_parent_company IS DISTINCT FROM NEW.companyid THEN
    RAISE EXCEPTION 'wallet_parent_other_company';
  END IF;
  -- El padre ya es hijo de alguien: serían dos niveles.
  IF v_parent_parent IS NOT NULL THEN
    RAISE EXCEPTION 'wallet_parent_is_child';
  END IF;
  -- Este contacto ya tiene hijos: pasarlo a hijo también serían dos niveles.
  IF EXISTS (SELECT 1 FROM contact
              WHERE parentcontactid = NEW.contactid
                AND companyid = NEW.companyid) THEN
    RAISE EXCEPTION 'wallet_child_has_children';
  END IF;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_contact_parent_guard ON contact;
CREATE TRIGGER trg_contact_parent_guard
  BEFORE INSERT OR UPDATE OF parentcontactid ON contact
  FOR EACH ROW
  WHEN (NEW.parentcontactid IS NOT NULL)
  EXECUTE FUNCTION fn_contact_parent_guard();


-- ═══════════════════════════════════════════════════════════════════════
-- 2. wallet_pocket — catálogo de bolsillos del comercio (§3.2)
-- ═══════════════════════════════════════════════════════════════════════
--
-- No se borran: tienen historia (movimientos que los referencian). Se
-- desactivan con `active`. Un bolsillo inactivo deja de ofrecerse para
-- operaciones nuevas; su saldo y sus movimientos se siguen viendo.

CREATE TABLE IF NOT EXISTS wallet_pocket (
  id         UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid  UUID         NOT NULL REFERENCES company(companyid) ON DELETE CASCADE,
  name       VARCHAR(80)  NOT NULL CHECK (btrim(name) <> ''),
  active     BOOLEAN      NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
  -- Destino de la FK compuesta de wallet_movement: un movimiento no puede
  -- apuntar al bolsillo de otro comercio.
  CONSTRAINT wallet_pocket_company_uq UNIQUE (id, companyid)
);

-- "Almuerzo" y "almuerzo" son el mismo bolsillo para el cajero que lo elige.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_wallet_pocket_name
    ON wallet_pocket (companyid, lower(btrim(name)));

COMMENT ON TABLE wallet_pocket IS
  'Wallet (context/74 §3.2): bolsillos del comercio. No se borran (tienen '
  'historia), se desactivan.';


-- ═══════════════════════════════════════════════════════════════════════
-- 3. wallet_movement — append-only (§3.4, §3.5)
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS wallet_movement (
  id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  -- El orden. Los UUID del proyecto son v4 random: `ORDER BY id` no da
  -- recencia, y `createdat` empata dentro de una misma transacción (los dos
  -- lados de una transferencia tienen el MISMO now()). `seq` es monótono.
  seq             BIGSERIAL     NOT NULL UNIQUE,
  companyid       UUID          NOT NULL REFERENCES company(companyid) ON DELETE CASCADE,
  -- De quién es el saldo.
  contactid       UUID          NOT NULL REFERENCES contact(contactid),
  pocketid        UUID          NOT NULL,
  type            VARCHAR(10)   NOT NULL,
  amount          NUMERIC(15,2) NOT NULL,
  -- Saldo del bolsillo tras el movimiento. Lo que se lee como "saldo vigente".
  balanceafter    NUMERIC(15,2) NOT NULL,
  -- Congelado en cada carga (§4). NUNCA se lee de la configuración vigente:
  -- eso refacturaría saldos ya facturados (§10).
  billingmode     CHAR(1),
  -- Une los dos lados de una transferencia.
  transfergroupid UUID,
  -- Qué originó el movimiento: la venta de la carga (F2), la venta pagada con
  -- saldo, etc. Texto libre acotado y no FK: el origen es polimórfico.
  sourcetype      VARCHAR(40),
  sourceid        UUID,
  -- Quién lo hizo, siempre (§8).
  actorcontactid  UUID          NOT NULL REFERENCES contact(contactid),
  reason          TEXT,
  createdat       TIMESTAMPTZ   NOT NULL DEFAULT now(),

  CONSTRAINT wallet_movement_pocket_fk
    FOREIGN KEY (pocketid, companyid) REFERENCES wallet_pocket (id, companyid),

  CONSTRAINT wallet_movement_type_chk
    CHECK (type IN ('load', 'transfer', 'spend', 'refund', 'adjust')),

  -- La invariante del módulo: el tope ES el saldo, así que nunca es negativo.
  CONSTRAINT wallet_movement_balance_nonneg_chk
    CHECK (balanceafter >= 0),

  CONSTRAINT wallet_movement_amount_nonzero_chk
    CHECK (amount <> 0),

  -- El signo lo decide el tipo. transfer y adjust van en los dos sentidos.
  CONSTRAINT wallet_movement_sign_chk
    CHECK (
      (type = 'load'   AND amount > 0) OR
      (type = 'refund' AND amount > 0) OR
      (type = 'spend'  AND amount < 0) OR
      (type IN ('transfer', 'adjust'))
    ),

  CONSTRAINT wallet_movement_billingmode_chk
    CHECK (billingmode IS NULL OR billingmode IN ('A', 'B')),
  -- Una carga sin modo grabado es exactamente lo que §4 prohíbe.
  CONSTRAINT wallet_movement_load_billingmode_chk
    CHECK (type <> 'load' OR billingmode IS NOT NULL),

  -- Una transferencia sin grupo no se puede reconstruir como par.
  CONSTRAINT wallet_movement_transfer_group_chk
    CHECK ((type = 'transfer') = (transfergroupid IS NOT NULL)),

  -- Un ajuste es la corrección de un error: sin motivo no se puede auditar.
  CONSTRAINT wallet_movement_adjust_reason_chk
    CHECK (type <> 'adjust' OR (reason IS NOT NULL AND btrim(reason) <> ''))
);

-- Saldo vigente de (contacto, bolsillo) = balanceafter de la fila de mayor seq.
-- También sirve para el listado de movimientos de un contacto.
CREATE INDEX IF NOT EXISTS idx_wallet_movement_balance
    ON wallet_movement (companyid, contactid, pocketid, seq DESC);

-- Movimientos de un contacto en todos sus bolsillos, del más nuevo al más viejo.
CREATE INDEX IF NOT EXISTS idx_wallet_movement_contact
    ON wallet_movement (companyid, contactid, seq DESC);

-- "¿Este origen ya generó un movimiento?" (F2: una venta, una carga).
CREATE INDEX IF NOT EXISTS idx_wallet_movement_source
    ON wallet_movement (companyid, sourcetype, sourceid)
 WHERE sourceid IS NOT NULL;

COMMENT ON TABLE wallet_movement IS
  'Wallet (context/74 §3.4): movimientos append-only. El saldo es la suma; '
  'balanceafter lo materializa por fila y un trigger verifica que encadene.';
COMMENT ON COLUMN wallet_movement.billingmode IS
  'Modo de facturación CONGELADO en la carga (A = factura al cargar). Nunca se '
  'resuelve desde la configuración vigente (context/74 §4).';


-- ── saldo = suma: balanceafter tiene que encadenar con el anterior ─────────
--
-- Sin esto, `balanceafter` sería un campo de saldo más que alguien puede
-- escribir mal (§10, "un campo balance"). Con esto, el último balanceafter es
-- SIEMPRE la suma de los amounts del bolsillo, y leerlo es O(1).
--
-- Concurrencia: este SELECT no bloquea. El que serializa a dos escritores del
-- mismo bolsillo es el `pg_advisory_xact_lock` que toma `WalletService` antes
-- de leer el saldo. Un escritor que se saltee el servicio y corra en paralelo
-- podría encadenar contra el mismo anterior; el CHECK de no-negativo lo sigue
-- conteniendo.
CREATE OR REPLACE FUNCTION fn_wallet_movement_chain() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_prev numeric(15,2);
BEGIN
  SELECT balanceafter INTO v_prev
    FROM wallet_movement
   WHERE companyid = NEW.companyid
     AND contactid = NEW.contactid
     AND pocketid  = NEW.pocketid
   ORDER BY seq DESC
   LIMIT 1;

  IF NEW.balanceafter IS DISTINCT FROM COALESCE(v_prev, 0) + NEW.amount THEN
    RAISE EXCEPTION 'wallet_balance_chain_broken'
      USING DETAIL = format('anterior=%s amount=%s balanceafter=%s',
                            COALESCE(v_prev, 0), NEW.amount, NEW.balanceafter);
  END IF;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_wallet_movement_chain ON wallet_movement;
CREATE TRIGGER trg_wallet_movement_chain
  BEFORE INSERT ON wallet_movement
  FOR EACH ROW EXECUTE FUNCTION fn_wallet_movement_chain();


-- ── append-only ────────────────────────────────────────────────────────────
--
-- Un movimiento no se edita ni se borra: un error se corrige con otro
-- movimiento, con motivo y autor (§8).
--
-- La ÚNICA excepción es la purga del tenant entero desde /admin
-- (`CompanyAdminService::hardDelete()`), que borra TODO el comercio y no puede
-- dejar filas huérfanas. Se habilita con una marca de sesión local a esa
-- transacción (`set_config('punto.tenant_purge', <companyId>, true)`) y solo
-- para las filas de ESE comercio: no hay forma de borrar un movimiento suelto.
CREATE OR REPLACE FUNCTION fn_wallet_movement_append_only() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE'
     AND COALESCE(current_setting('punto.tenant_purge', true), '') = OLD.companyid::text
  THEN
    RETURN OLD;
  END IF;
  RAISE EXCEPTION 'wallet_movement_append_only'
    USING DETAIL = 'Los movimientos de saldo no se editan ni se borran; se corrigen con un ajuste.';
END;
$$;

DROP TRIGGER IF EXISTS trg_wallet_movement_append_only ON wallet_movement;
CREATE TRIGGER trg_wallet_movement_append_only
  BEFORE UPDATE OR DELETE ON wallet_movement
  FOR EACH ROW EXECUTE FUNCTION fn_wallet_movement_append_only();

-- TRUNCATE no dispara triggers de fila: se bloquea aparte.
CREATE OR REPLACE FUNCTION fn_wallet_movement_no_truncate() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'wallet_movement_append_only';
END;
$$;

DROP TRIGGER IF EXISTS trg_wallet_movement_no_truncate ON wallet_movement;
CREATE TRIGGER trg_wallet_movement_no_truncate
  BEFORE TRUNCATE ON wallet_movement
  FOR EACH STATEMENT EXECUTE FUNCTION fn_wallet_movement_no_truncate();
