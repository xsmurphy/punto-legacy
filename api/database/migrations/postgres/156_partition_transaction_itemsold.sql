-- 156_partition_transaction_itemsold.sql
-- E1 de context/48-escalamiento-de-datos.md (D3/D4): particionado mensual
-- de `transaction`/`itemsold` por rango de fecha + columnas denormalizadas
-- (companyid/outletid/registerid) en `itemsold`.
--
-- Verificado contra prod antes de escribir esta mig (2026-08-22): 723 filas
-- en transaction, 1.029 en itemsold, min(transactiondate)=2026-01-01,
-- 0 huérfanos, 221 transacciones con registerid NULL, 0 con voidedat,
-- 20 FK entrantes reales contra transaction (confirmadas contra
-- pg_constraint, coincide 1:1 con lo relevado en context/48).
--
-- ── Por qué transaction_registry ─────────────────────────────────────────
--
-- Postgres exige que la clave de partición (transactiondate) forme parte
-- de cualquier PK/UNIQUE de la tabla particionada: la PK de `transaction`
-- pasa de (transactionid) a (transactionid, transactiondate). Eso rompe
-- DOS unicidades que hoy son GLOBALES y no pueden depender del mes de la
-- venta: `transactionuid` (dedup de reintentos offline del POS) y el
-- número fiscal por punto de expedición (`uq_transaction_expedition_
-- invoiceno`, integridad legal — dos facturas con el mismo número es
-- ilegal, sin importar en qué mes cayó cada una). D3 de context/48 proponía
-- 2 caminos para las FK entrantes (compuesta vs. sin FK); ninguno de los
-- dos resuelve el problema de la unicidad global, que es ortogonal. La
-- solución: `transaction_registry`, tabla NO particionada, chica (1 fila
-- por transacción, solo las columnas necesarias para (a) las 2 unicidades
-- globales y (b) ser el DESTINO de las 20 FK entrantes de los satélites
-- (evita denormalizar transactiondate en 10+ tablas, la alternativa que
-- D3 ya había descartado por motivo simétrico).
--
-- Se mantiene en sync con `transaction` vía 2 triggers (INSERT/UPDATE) +
-- una FK de vuelta (transaction_registry → transaction) ON DELETE CASCADE
-- ON UPDATE CASCADE que hace de "borrado" declarativo: cuando se hard-
-- deletea una transacción (pasa hoy para tipos no-venta: orden/mesa/
-- agenda/compra, purga de empresa/sucursal), Postgres se encarga de borrar
-- la fila del registry sin necesidad de un trigger AFTER DELETE a mano, Y
-- las 20 FK de los satélites (que ahora apuntan al registry) siguen
-- bloqueando el borrado si hay hijos, exactamente igual que hoy.
--
-- ── Partición DEFAULT + 12 meses ─────────────────────────────────────────
--
-- Offline-first (project_offline_scope): el back nunca rechaza una venta
-- ya  emitida. Una partición DEFAULT (además de las 12 mensuales hacia
-- adelante) es la red de seguridad: si el job `partition-ensure` no corre
-- por semanas y una fecha cae fuera de rango, la fila entra igual a la
-- DEFAULT en vez de que el INSERT falle duro.

BEGIN;

-- ═══════════════════════════════════════════════════════════════════════
-- 1. transaction_registry — unicidad global + destino de las FK entrantes
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE transaction_registry (
    transactionid   uuid PRIMARY KEY,
    transactiondate timestamptz NOT NULL,
    companyid       uuid NOT NULL,
    outletid        uuid NOT NULL,
    registerid      uuid,
    transactionuid  varchar(50),
    transactiontype smallint,
    invoiceauth     text,
    invoiceno       bigint
);

COMMENT ON TABLE transaction_registry IS
  'Clave global de la fact particionada `transaction` (E1, context/48 D3, '
  'mig 156). transaction tiene PK compuesta (transactionid, transactiondate) '
  'porque Postgres exige que la clave de particion forme parte de la PK -- '
  'eso rompe 2 unicidades que deben valer SIN IMPORTAR el mes de la venta: '
  'transactionuid (dedup de reintentos offline del POS) y el numero fiscal '
  'por punto de expedicion (uq_transaction_expedition_invoiceno). Esta '
  'tabla, chica y NO particionada, sostiene esas 2 unicidades y es el '
  'DESTINO de las 20 FK entrantes que hoy apuntan a transaction(transactionid) '
  '-- evita denormalizar transactiondate en 10+ tablas satelite solo para '
  'satisfacer al motor. Se sincroniza via triggers AFTER INSERT/UPDATE en '
  'transaction y una FK de vuelta (transaction_registry -> transaction) '
  'ON DELETE CASCADE / ON UPDATE CASCADE que actua como "borrado '
  'declarativo" (transaction nunca hard-deletea ventas -- si algun dia se '
  'hard-deletea una fila no-venta, el registry se limpia solo).';

INSERT INTO transaction_registry
    (transactionid, transactiondate, companyid, outletid, registerid,
     transactionuid, transactiontype, invoiceauth, invoiceno)
  SELECT transactionid, transactiondate, companyid, outletid, registerid,
         transactionuid, transactiontype, invoiceauth, invoiceno
    FROM transaction;

-- Liberar los 2 nombres de unicidad global de `transaction` (se recrean
-- sobre el registry con el MISMO nombre -- SaleService.php:256-270 detecta
-- el choque fiscal/duplicado por el nombre exacto del constraint/index).
ALTER TABLE transaction DROP CONSTRAINT transaction_transactionuid_key;
DROP INDEX uq_transaction_expedition_invoiceno;

ALTER TABLE transaction_registry
  ADD CONSTRAINT transaction_transactionuid_key UNIQUE (transactionuid);

CREATE UNIQUE INDEX uq_transaction_expedition_invoiceno
  ON transaction_registry (companyid, registerid, COALESCE(invoiceauth, ''), invoiceno)
  WHERE invoiceno IS NOT NULL AND transactiontype IN (0, 3);

-- ═══════════════════════════════════════════════════════════════════════
-- 2. Redirigir las 20 FK entrantes hacia transaction_registry
--    (confirmadas 1:1 contra pg_constraint en prod, 2026-08-22)
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE comission
  DROP CONSTRAINT comission_transactionid_fkey,
  ADD  CONSTRAINT comission_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE document_remision
  DROP CONSTRAINT document_remision_transactionid_fkey,
  ADD  CONSTRAINT document_remision_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE giftcardsold
  DROP CONSTRAINT giftcardsold_transactionid_fkey,
  ADD  CONSTRAINT giftcardsold_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE itemsold
  DROP CONSTRAINT itemsold_transactionid_fkey,
  ADD  CONSTRAINT itemsold_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE order_transaction_link
  DROP CONSTRAINT order_transaction_link_transactionid_fkey,
  ADD  CONSTRAINT order_transaction_link_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE printserver
  DROP CONSTRAINT printserver_transactionid_fkey,
  ADD  CONSTRAINT printserver_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE saas_invoice_sale
  DROP CONSTRAINT saas_invoice_sale_transactionid_fkey,
  ADD  CONSTRAINT saas_invoice_sale_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE satisfaction
  DROP CONSTRAINT satisfaction_transactionid_fkey,
  ADD  CONSTRAINT satisfaction_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE sold_pack
  DROP CONSTRAINT sold_pack_transactionid_fkey,
  ADD  CONSTRAINT sold_pack_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE stock
  DROP CONSTRAINT stock_transactionid_fkey,
  ADD  CONSTRAINT stock_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE toaddress
  DROP CONSTRAINT toaddress_transactionid_fkey,
  ADD  CONSTRAINT toaddress_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE toscheduleuid
  DROP CONSTRAINT toscheduleuid_transactionuid_fkey,
  ADD  CONSTRAINT toscheduleuid_transactionuid_fkey FOREIGN KEY (transactionuid) REFERENCES transaction_registry(transactionuid);

ALTER TABLE totaxobj
  DROP CONSTRAINT totaxobj_transactionid_fkey,
  ADD  CONSTRAINT totaxobj_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE totransaction
  DROP CONSTRAINT totransaction_parentid_fkey,
  ADD  CONSTRAINT totransaction_parentid_fkey FOREIGN KEY (parentid) REFERENCES transaction_registry(transactionid);

ALTER TABLE totransaction
  DROP CONSTRAINT totransaction_transactionid_fkey,
  ADD  CONSTRAINT totransaction_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE transaction_link
  DROP CONSTRAINT transaction_link_derivedid_fkey,
  ADD  CONSTRAINT transaction_link_derivedid_fkey FOREIGN KEY (derivedid) REFERENCES transaction_registry(transactionid);

ALTER TABLE transaction_link
  DROP CONSTRAINT transaction_link_originid_fkey,
  ADD  CONSTRAINT transaction_link_originid_fkey FOREIGN KEY (originid) REFERENCES transaction_registry(transactionid);

ALTER TABLE voucher
  DROP CONSTRAINT voucher_issuedbytransactionid_fkey,
  ADD  CONSTRAINT voucher_issuedbytransactionid_fkey FOREIGN KEY (issuedbytransactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE voucher
  DROP CONSTRAINT voucher_usedbytransactionid_fkey,
  ADD  CONSTRAINT voucher_usedbytransactionid_fkey FOREIGN KEY (usedbytransactionid) REFERENCES transaction_registry(transactionid);

ALTER TABLE vpayments
  DROP CONSTRAINT vpayments_transactionid_fkey,
  ADD  CONSTRAINT vpayments_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

-- ═══════════════════════════════════════════════════════════════════════
-- 3. Funciones genéricas de particionado (usadas por transaction e itemsold,
--    y por el job `partition-ensure` de aca en adelante)
-- ═══════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION ensure_month_partitions(p_table regclass, p_column name, p_months_ahead int)
RETURNS text[] LANGUAGE plpgsql AS $$
DECLARE
  v_schema        text;
  v_bare_name     text;
  v_default_name  text;
  v_default_exists boolean;
  v_earliest_part date;
  v_min_data      timestamptz;
  v_start_month   date;
  v_end_month     date;
  v_month         date;
  v_part_name     text;
  v_created       text[] := ARRAY[]::text[];
  v_rows_in_range bigint;
BEGIN
  SELECT n.nspname, c.relname INTO v_schema, v_bare_name
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
   WHERE c.oid = p_table;

  v_default_name := v_bare_name || '_default';

  -- Punto de partida: el mes de la particion mensual mas vieja YA CREADA
  -- (mirando pg_inherits, no los datos). Si todavia no existe ninguna
  -- (bootstrap / primera corrida), se cae al minimo real de la columna
  -- (o al mes actual si la tabla esta vacia). Una vez que la cobertura
  -- mensual arranca en un mes dado, un dato viejo suelto que aparezca
  -- despues (ej. una fecha de 2019 cargada por error) NO empuja el
  -- arranque mas atras -- se queda en la particion default a proposito
  -- (ver verificacion de la mig 156 / D3 de context/48: la funcion no
  -- debe poder ser forzada a crear anios de particiones vacias por una
  -- fecha basura).
  SELECT min(to_date(regexp_replace(c.relname, '^' || v_bare_name || '_y(\d{4})m(\d{2})$', '\1\2'), 'YYYYMM'))
    INTO v_earliest_part
    FROM pg_inherits i
    JOIN pg_class c ON c.oid = i.inhrelid
   WHERE i.inhparent = p_table
     AND c.relname ~ ('^' || v_bare_name || '_y[0-9]{4}m[0-9]{2}$');

  IF v_earliest_part IS NOT NULL THEN
    v_start_month := v_earliest_part;
  ELSE
    EXECUTE format('SELECT min(%I) FROM %s', p_column, p_table) INTO v_min_data;
    v_start_month := COALESCE(date_trunc('month', v_min_data)::date, date_trunc('month', now())::date);
  END IF;

  v_end_month := date_trunc('month', now() + (p_months_ahead || ' months')::interval)::date;

  SELECT EXISTS (
    SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE c.relname = v_default_name AND n.nspname = v_schema
  ) INTO v_default_exists;
  IF NOT v_default_exists THEN
    EXECUTE format('CREATE TABLE %I PARTITION OF %s DEFAULT', v_default_name, p_table);
  END IF;

  v_month := v_start_month;
  WHILE v_month <= v_end_month LOOP
    v_part_name := v_bare_name || '_y' || to_char(v_month, 'YYYY') || 'm' || to_char(v_month, 'MM');

    IF NOT EXISTS (
      SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
       WHERE c.relname = v_part_name AND n.nspname = v_schema
    ) THEN
      EXECUTE format(
        'SELECT count(*) FROM %I WHERE %I >= %L AND %I < %L',
        v_default_name, p_column, v_month, p_column, (v_month + interval '1 month')
      ) INTO v_rows_in_range;

      -- Si la default ya tiene filas de este rango, hay que desprenderla
      -- antes de poder declarar la particion mensual explicita (Postgres
      -- no permite crear una partición cuyo rango se superpone con filas
      -- que ya viven en la DEFAULT sin antes desatarla).
      IF v_rows_in_range > 0 THEN
        EXECUTE format('ALTER TABLE %s DETACH PARTITION %I', p_table, v_default_name);
      END IF;

      EXECUTE format(
        'CREATE TABLE %I PARTITION OF %s FOR VALUES FROM (%L) TO (%L)',
        v_part_name, p_table, v_month, (v_month + interval '1 month')
      );
      v_created := array_append(v_created, v_part_name);

      IF v_rows_in_range > 0 THEN
        -- Mueve las filas del rango: entran por el padre (ya resuelven a
        -- la particion mensual recien creada), se borran de la copia
        -- desprendida y se vuelve a pegar la default como default.
        --
        -- DISABLE/ENABLE TRIGGER ALL alrededor del DELETE: la copia
        -- desprendida retiene, como tabla independiente, el trigger
        -- interno que implementa cualquier FK "referenced-side" que
        -- apunte al padre (ej. transaction_registry -> transaction ON
        -- DELETE CASCADE) -- sin este guard, el DELETE de acá abajo
        -- cascadearia y borraria la fila de transaction_registry que la
        -- linea de arriba ACABA de reinsertar/sincronizar.
        EXECUTE format(
          'INSERT INTO %s SELECT * FROM %I WHERE %I >= %L AND %I < %L',
          p_table, v_default_name, p_column, v_month, p_column, (v_month + interval '1 month')
        );
        EXECUTE format('ALTER TABLE %I DISABLE TRIGGER ALL', v_default_name);
        EXECUTE format(
          'DELETE FROM %I WHERE %I >= %L AND %I < %L',
          v_default_name, p_column, v_month, p_column, (v_month + interval '1 month')
        );
        EXECUTE format('ALTER TABLE %I ENABLE TRIGGER ALL', v_default_name);
        EXECUTE format('ALTER TABLE %s ATTACH PARTITION %I DEFAULT', p_table, v_default_name);
      END IF;
    END IF;

    v_month := (v_month + interval '1 month')::date;
  END LOOP;

  RETURN v_created;
END;
$$;

COMMENT ON FUNCTION ensure_month_partitions(regclass, name, int) IS
  'Crea (si faltan) las particiones mensuales de p_table entre el mes de '
  'la particion mas vieja ya creada (o el minimo real de p_column si '
  'todavia no hay ninguna) y now() + p_months_ahead meses. Crea tambien '
  'la particion DEFAULT si no existe. Si la DEFAULT ya tenia filas del mes '
  'que se esta por crear, las migra automaticamente. Usada por la mig 156 '
  '(bootstrap) y por el job partition-ensure de api/v1/maintenance.php.';

CREATE OR REPLACE FUNCTION partition_health(p_table regclass, p_column name, p_months_required int)
RETURNS int LANGUAGE plpgsql AS $$
DECLARE
  v_schema    text;
  v_bare_name text;
  v_month     date := date_trunc('month', now())::date;
  v_count     int := 0;
  v_part_name text;
BEGIN
  SELECT n.nspname, c.relname INTO v_schema, v_bare_name
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
   WHERE c.oid = p_table;

  LOOP
    EXIT WHEN v_count >= p_months_required;
    v_part_name := v_bare_name || '_y' || to_char(v_month, 'YYYY') || 'm' || to_char(v_month, 'MM');
    EXIT WHEN NOT EXISTS (
      SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
       WHERE c.relname = v_part_name AND n.nspname = v_schema
    );
    v_count := v_count + 1;
    v_month := (v_month + interval '1 month')::date;
  END LOOP;

  RETURN v_count;
END;
$$;

COMMENT ON FUNCTION partition_health(regclass, name, int) IS
  'Cuenta cuantos meses futuros CONSECUTIVOS (arrancando en el mes actual) '
  'ya tienen particion explicita para p_table/p_column. Usada por el job '
  'partition-ensure para alertar (GlitchTip) si la cobertura cae por '
  'debajo de p_months_required.';

-- ═══════════════════════════════════════════════════════════════════════
-- 4. Swap de `transaction` por una tabla particionada por transactiondate
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE transaction RENAME TO transaction_legacy;

-- Libera los nombres de indice/constraint que se reusan sobre la tabla
-- nueva (renombrar la tabla NO renombra sus indices -- los nombres de
-- indice son unicos a nivel schema, no a nivel tabla). transaction_legacy
-- se dropea entera mas abajo, asi que esto es seguro.
ALTER TABLE transaction_legacy DROP CONSTRAINT transaction_pkey;
DROP INDEX idx_transaction_drawerid;
DROP INDEX idx_transaction_voidedat;
DROP INDEX idx_tx_company_invoice;
DROP INDEX idx_tx_company_reg;
DROP INDEX idx_tx_company_type;
DROP INDEX idx_tx_customer;
DROP INDEX idx_tx_date;
DROP INDEX idx_tx_outlet;
DROP INDEX idx_tx_status;
DROP INDEX idx_tx_updated;

-- Mismas columnas, mismo orden (transactionid PRIMERO -- DB::Insert_ID()
-- devuelve la primera columna del RETURNING *, api/includes/lib/DB.php:358).
CREATE TABLE transaction (
    transactionid           uuid DEFAULT gen_random_uuid() NOT NULL,
    transactiondate         timestamptz DEFAULT now() NOT NULL,
    transactiondiscount     numeric(15,2),
    transactiontax          numeric(15,2),
    transactiontotal        numeric(15,2) NOT NULL,
    transactionunitssold    numeric(15,3),
    transactionpaymenttype  text,
    transactiontype         smallint,
    transactionname         varchar(100),
    transactionnote         varchar(255),
    transactioncomplete     boolean DEFAULT true,
    transactionduedate      timestamptz,
    transactionstatus       smallint DEFAULT 1,
    transactionuid          varchar(50),
    transactioncurrency     varchar(3),
    fromdate                timestamptz,
    todate                  timestamptz,
    invoiceno               bigint,
    invoiceprefix           varchar(150),
    tableno                 integer,
    "timestamp"             bigint,
    packageid               uuid,
    categorytransid         uuid,
    customerid              uuid,
    registerid              uuid,
    userid                  uuid NOT NULL,
    responsibleid           uuid,
    supplierid              uuid,
    outletid                uuid NOT NULL,
    companyid               uuid NOT NULL,
    updated_at              timestamptz,
    meta                    jsonb DEFAULT '{}'::jsonb NOT NULL,
    drawerid                uuid,
    ivaremoved              boolean DEFAULT false NOT NULL,
    interno                 boolean DEFAULT false NOT NULL,
    invoiceauth             text,
    invoiceauthstart        text,
    invoiceauthexpiration   text,
    supplierauthno          varchar(20),
    supplierauthnoduedate   date,
    supplierdocprefix       varchar(10),
    supplierdocno           bigint,
    supplierdocdate         date,
    voidedat                timestamptz,
    voidreason              text,
    voidedby                uuid,
    CONSTRAINT transaction_pkey PRIMARY KEY (transactionid, transactiondate)
) PARTITION BY RANGE (transactiondate);

COMMENT ON COLUMN transaction.transactionunitssold IS 'Suma de las unidades vendidas de la transacción. DECIMAL(15,3) como itemSold.itemSoldUnits — hay ítems fraccionables (kg, litros).';
COMMENT ON COLUMN transaction.ivaremoved IS 'true = venta emitida sin IVA (toggle del POS). Los importes ya vienen netos.';
COMMENT ON COLUMN transaction.interno IS 'true = venta de consumo interno (botón Interno del POS), no una venta a cliente.';

-- FK salientes (mismos nombres/definiciones que antes del particionado —
-- una tabla particionada puede tener FK normales hacia tablas regulares).
ALTER TABLE transaction ADD CONSTRAINT transaction_categorytransid_fkey FOREIGN KEY (categorytransid) REFERENCES taxonomy(taxonomyid);
ALTER TABLE transaction ADD CONSTRAINT transaction_companyid_fkey FOREIGN KEY (companyid) REFERENCES company(companyid);
ALTER TABLE transaction ADD CONSTRAINT transaction_customerid_fkey FOREIGN KEY (customerid) REFERENCES contact(contactid);
ALTER TABLE transaction ADD CONSTRAINT transaction_outletid_fkey FOREIGN KEY (outletid) REFERENCES outlet(outletid);
ALTER TABLE transaction ADD CONSTRAINT transaction_registerid_fkey FOREIGN KEY (registerid) REFERENCES register(registerid);
ALTER TABLE transaction ADD CONSTRAINT transaction_responsibleid_fkey FOREIGN KEY (responsibleid) REFERENCES contact(contactid);
ALTER TABLE transaction ADD CONSTRAINT transaction_supplierid_fkey FOREIGN KEY (supplierid) REFERENCES contact(contactid);
ALTER TABLE transaction ADD CONSTRAINT transaction_userid_fkey FOREIGN KEY (userid) REFERENCES contact(contactid);

-- Particion DEFAULT: necesaria para poder insertar filas ANTES de que
-- existan particiones mensuales explicitas.
CREATE TABLE transaction_default PARTITION OF transaction DEFAULT;

-- Copia masiva (723 filas en prod) — todo cae en DEFAULT por ahora,
-- ensure_month_partitions() de mas abajo la reparte en particiones
-- mensuales.
INSERT INTO transaction (
    transactionid, transactiondate, transactiondiscount, transactiontax,
    transactiontotal, transactionunitssold, transactionpaymenttype,
    transactiontype, transactionname, transactionnote, transactioncomplete,
    transactionduedate, transactionstatus, transactionuid, transactioncurrency,
    fromdate, todate, invoiceno, invoiceprefix, tableno, "timestamp",
    packageid, categorytransid, customerid, registerid, userid,
    responsibleid, supplierid, outletid, companyid, updated_at, meta,
    drawerid, ivaremoved, interno, invoiceauth, invoiceauthstart,
    invoiceauthexpiration, supplierauthno, supplierauthnoduedate,
    supplierdocprefix, supplierdocno, supplierdocdate, voidedat,
    voidreason, voidedby
  )
  SELECT
    transactionid, transactiondate, transactiondiscount, transactiontax,
    transactiontotal, transactionunitssold, transactionpaymenttype,
    transactiontype, transactionname, transactionnote, transactioncomplete,
    transactionduedate, transactionstatus, transactionuid, transactioncurrency,
    fromdate, todate, invoiceno, invoiceprefix, tableno, "timestamp",
    packageid, categorytransid, customerid, registerid, userid,
    responsibleid, supplierid, outletid, companyid, updated_at, meta,
    drawerid, ivaremoved, interno, invoiceauth, invoiceauthstart,
    invoiceauthexpiration, supplierauthno, supplierauthnoduedate,
    supplierdocprefix, supplierdocno, supplierdocdate, voidedat,
    voidreason, voidedby
    FROM transaction_legacy;

DROP TABLE transaction_legacy;

-- Indices no-unicos, mismos nombres — definidos sobre el padre, Postgres
-- los propaga a cada particion existente (incl. default) y a las que se
-- creen despues via PARTITION OF.
CREATE INDEX idx_transaction_drawerid ON transaction (drawerid) WHERE drawerid IS NOT NULL;
CREATE INDEX idx_transaction_voidedat ON transaction (companyid, voidedat) WHERE voidedat IS NOT NULL;
CREATE INDEX idx_tx_company_invoice ON transaction (companyid, registerid, transactiontype, invoiceno, transactiondate);
CREATE INDEX idx_tx_company_reg ON transaction (companyid, registerid, transactiontype, transactiondate);
CREATE INDEX idx_tx_company_type ON transaction (companyid, transactiontype, transactiondate);
CREATE INDEX idx_tx_customer ON transaction (customerid, userid);
CREATE INDEX idx_tx_date ON transaction (transactiondate);
CREATE INDEX idx_tx_outlet ON transaction (outletid);
CREATE INDEX idx_tx_status ON transaction (transactionstatus, companyid);
CREATE INDEX idx_tx_updated ON transaction (updated_at);

-- Particiones mensuales reales (Jan-Aug 2026 hoy) + 12 meses de margen
-- hacia adelante. Reparte lo que quedo en DEFAULT tras la copia masiva.
SELECT ensure_month_partitions('transaction', 'transactiondate', 12);

-- Triggers de sync hacia transaction_registry — se crean DESPUES de la
-- copia masiva a proposito (el registry ya esta poblado desde el paso 1,
-- no hace falta que la copia masiva los dispare 723 veces).
CREATE OR REPLACE FUNCTION transaction_registry_sync_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO transaction_registry
      (transactionid, transactiondate, companyid, outletid, registerid,
       transactionuid, transactiontype, invoiceauth, invoiceno)
    VALUES
      (NEW.transactionid, NEW.transactiondate, NEW.companyid, NEW.outletid,
       NEW.registerid, NEW.transactionuid, NEW.transactiontype,
       NEW.invoiceauth, NEW.invoiceno)
    ON CONFLICT (transactionid) DO UPDATE SET
      transactiondate = EXCLUDED.transactiondate,
      companyid       = EXCLUDED.companyid,
      outletid        = EXCLUDED.outletid,
      registerid      = EXCLUDED.registerid,
      transactionuid  = EXCLUDED.transactionuid,
      transactiontype = EXCLUDED.transactiontype,
      invoiceauth     = EXCLUDED.invoiceauth,
      invoiceno       = EXCLUDED.invoiceno;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_transaction_registry_sync_insert
  AFTER INSERT ON transaction
  FOR EACH ROW EXECUTE FUNCTION transaction_registry_sync_insert();

CREATE OR REPLACE FUNCTION transaction_registry_sync_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  UPDATE transaction_registry SET
      transactiondate = NEW.transactiondate,
      companyid       = NEW.companyid,
      outletid        = NEW.outletid,
      registerid      = NEW.registerid,
      transactionuid  = NEW.transactionuid,
      transactiontype = NEW.transactiontype,
      invoiceauth     = NEW.invoiceauth,
      invoiceno       = NEW.invoiceno
    WHERE transactionid = NEW.transactionid;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_transaction_registry_sync_update
  AFTER UPDATE OF transactiondate, companyid, outletid, registerid,
                   transactionuid, transactiontype, invoiceauth, invoiceno
  ON transaction
  FOR EACH ROW EXECUTE FUNCTION transaction_registry_sync_update();

-- Borrado: sin trigger AFTER DELETE. Se apoya en la FK de vuelta: cuando
-- se hard-deletea una fila de `transaction`, Postgres cascadea el borrado
-- de su fila en transaction_registry, y las 20 FK de los satelites (que
-- apuntan al registry) siguen bloqueando si hay hijos, igual que hoy.
--
-- Row-movement (UPDATE que cruza de particion, ej. corregir transactiondate):
-- Postgres trata la RI de una FK que referencia una tabla particionada
-- consistentemente con la semantica de UPDATE, no como delete+insert --
-- verificado contra Postgres 18 en el arnes de esta mig (ver reporte).
ALTER TABLE transaction_registry
  ADD CONSTRAINT transaction_registry_transaction_fkey
  FOREIGN KEY (transactionid, transactiondate)
  REFERENCES transaction (transactionid, transactiondate)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- ═══════════════════════════════════════════════════════════════════════
-- 5. itemsold: mismo swap + companyid/outletid/registerid (D4)
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE itemsold ADD COLUMN companyid  uuid;
ALTER TABLE itemsold ADD COLUMN outletid   uuid;
ALTER TABLE itemsold ADD COLUMN registerid uuid;

UPDATE itemsold i SET
    companyid  = r.companyid,
    outletid   = r.outletid,
    registerid = r.registerid
  FROM transaction_registry r
  WHERE r.transactionid = i.transactionid;

ALTER TABLE itemsold RENAME TO itemsold_legacy;

ALTER TABLE itemsold_legacy DROP CONSTRAINT itemsold_pkey;
DROP INDEX idx_itemsold_date;
DROP INDEX idx_itemsold_item;
DROP INDEX idx_itemsold_tx;
DROP INDEX idx_itemsold_user;

CREATE TABLE itemsold (
    itemsoldid          uuid DEFAULT gen_random_uuid() NOT NULL,
    itemsoldtotal       numeric(15,2) NOT NULL,
    itemsoldtax         numeric(15,2),
    itemsolddate        timestamptz DEFAULT now() NOT NULL,
    itemsoldunits       numeric(15,3),
    itemsolddiscount    numeric(15,2),
    itemsoldcogs        numeric(15,2),
    itemsoldcomission   numeric(15,2),
    itemsolddescription varchar(255),
    itemsoldparent      uuid,
    itemsoldcategory    uuid,
    itemid              uuid NOT NULL,
    userid              uuid,
    transactionid       uuid NOT NULL,
    meta                jsonb DEFAULT '{}'::jsonb NOT NULL,
    companyid           uuid,
    outletid            uuid,
    registerid          uuid,
    CONSTRAINT itemsold_pkey PRIMARY KEY (itemsoldid, itemsolddate)
) PARTITION BY RANGE (itemsolddate);

ALTER TABLE itemsold ADD CONSTRAINT itemsold_itemid_fkey FOREIGN KEY (itemid) REFERENCES item(itemid);
ALTER TABLE itemsold ADD CONSTRAINT itemsold_itemsoldcategory_fkey FOREIGN KEY (itemsoldcategory) REFERENCES taxonomy(taxonomyid);
ALTER TABLE itemsold ADD CONSTRAINT itemsold_itemsoldparent_fkey FOREIGN KEY (itemsoldparent) REFERENCES item(itemid);
ALTER TABLE itemsold ADD CONSTRAINT itemsold_userid_fkey FOREIGN KEY (userid) REFERENCES contact(contactid);
ALTER TABLE itemsold ADD CONSTRAINT itemsold_transactionid_fkey FOREIGN KEY (transactionid) REFERENCES transaction_registry(transactionid);

CREATE TABLE itemsold_default PARTITION OF itemsold DEFAULT;

INSERT INTO itemsold (
    itemsoldid, itemsoldtotal, itemsoldtax, itemsolddate, itemsoldunits,
    itemsolddiscount, itemsoldcogs, itemsoldcomission, itemsolddescription,
    itemsoldparent, itemsoldcategory, itemid, userid, transactionid, meta,
    companyid, outletid, registerid
  )
  SELECT
    itemsoldid, itemsoldtotal, itemsoldtax, itemsolddate, itemsoldunits,
    itemsolddiscount, itemsoldcogs, itemsoldcomission, itemsolddescription,
    itemsoldparent, itemsoldcategory, itemid, userid, transactionid, meta,
    companyid, outletid, registerid
    FROM itemsold_legacy;

DROP TABLE itemsold_legacy;

-- NOT NULL despues del backfill (D4): registerid queda nullable a
-- proposito (ventas desde panel sin caja).
ALTER TABLE itemsold ALTER COLUMN companyid SET NOT NULL;
ALTER TABLE itemsold ALTER COLUMN outletid  SET NOT NULL;

CREATE INDEX idx_itemsold_date ON itemsold (itemsolddate);
CREATE INDEX idx_itemsold_item ON itemsold (itemid);
CREATE INDEX idx_itemsold_tx ON itemsold (transactionid);
CREATE INDEX idx_itemsold_user ON itemsold (userid);
CREATE INDEX idx_itemsold_company_item_date ON itemsold (companyid, itemid, itemsolddate);

SELECT ensure_month_partitions('itemsold', 'itemsolddate', 12);

-- Red de seguridad DB-side (D4): si algun insert-path se olvida de mandar
-- companyid/outletid/registerid (raw SQL fuera de SaleService/ReturnService/
-- PurchaseCreditNoteService — ver verify_chain y tests), se completan solos
-- desde transaction_registry en vez de romper por NOT NULL. Si el
-- transactionid no existe en el registry (insert huerfano de verdad), se
-- rechaza con el mismo efecto que tendria la FK.
CREATE OR REPLACE FUNCTION itemsold_backfill_dims() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
  r RECORD;
BEGIN
  IF NEW.companyid IS NULL OR NEW.outletid IS NULL THEN
    SELECT companyid, outletid, registerid INTO r
      FROM transaction_registry WHERE transactionid = NEW.transactionid;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'itemsold.transactionid % no existe en transaction_registry', NEW.transactionid;
    END IF;
    NEW.companyid  := COALESCE(NEW.companyid, r.companyid);
    NEW.outletid   := COALESCE(NEW.outletid, r.outletid);
    NEW.registerid := COALESCE(NEW.registerid, r.registerid);
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_itemsold_backfill_dims
  BEFORE INSERT ON itemsold
  FOR EACH ROW EXECUTE FUNCTION itemsold_backfill_dims();

COMMIT;
