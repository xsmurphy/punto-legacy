-- 117_document_sequence.sql
-- Secuencia única de numeración correlativa de documentos (context/37).
--
-- Requerimiento del owner: TODO documento que el sistema emita o reciba lleva
-- correlativo. Hoy conviven tres mecanismos que cubren 3 documentos de ~13:
--   1. `MAX(invoiceNo)+1` vía numbering_lease  → factura
--   2. `registerQuoteNumber` + MAX             → cotización
--   3. `MAX(ordernumber)+1` + advisory lock    → orden (por sucursal)
-- más 9 columnas contador en `register` (mig 26) que casi nadie consume.
--
-- Ninguno sirve como correlativo real: `MAX+1` reemite si se borra la última
-- fila, y `RegisterService::nextDocNumber()` es lectura pura (no reserva), así
-- que dos cajeros concurrentes obtienen el mismo número.
--
-- Esta tabla los SUBSUME. El asignador (Punto\Api\Documents\DocumentNumber)
-- hace `UPDATE ... RETURNING`, que toma el row lock de PG: atómico, sin
-- advisory lock y sin escanear la tabla del documento.
--
-- F1 solo crea la tabla y siembra. NO cambia el comportamiento de ningún
-- emisor — eso es F2. Sembrar de más es inofensivo; sembrar de menos
-- reemitiría un número ya usado, así que el seed toma el GREATEST de TODAS
-- las fuentes que hoy conviven.
--
-- Todo lowercase sin comillas para `register`/`transaction`/`pos_order` (DDL
-- legacy). `numbering_lease` es camelCase quoted (mig 54) — ahí sí van comillas.

BEGIN;

-- ============================================================
-- 1. CREATE TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS document_sequence (
  sequenceid  uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid   uuid        NOT NULL,
  -- 'factura' | 'cotizacion' | 'orden' | 'nota_credito' | 'produccion' | ...
  -- NO es SaleType: varios documentos no son transacciones (producción,
  -- transferencia, conteo). Son dimensiones distintas.
  doctype     varchar(40) NOT NULL,
  scopetype   varchar(20) NOT NULL,
  scopeid     uuid        NOT NULL,
  -- PRÓXIMO número a emitir (no el último usado — los contadores legacy de
  -- `register` guardan el último, y esa asimetría ya causó confusión).
  nextnumber  bigint      NOT NULL DEFAULT 1,
  -- Rango autorizado del timbrado. NULL = sin límite declarado.
  rangefrom   bigint,
  rangeto     bigint,
  prefix      varchar(16),
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT document_sequence_scope_chk
    CHECK (scopetype IN ('register', 'outlet', 'company')),
  CONSTRAINT document_sequence_next_chk CHECK (nextnumber >= 1),
  CONSTRAINT document_sequence_range_chk
    CHECK (rangefrom IS NULL OR rangeto IS NULL OR rangeto >= rangefrom)
);

-- Una secuencia por (empresa, documento, scope). Es también el índice del
-- asignador: el UPDATE busca exactamente por estas cuatro columnas.
CREATE UNIQUE INDEX IF NOT EXISTS uq_document_sequence
  ON document_sequence (companyid, doctype, scopetype, scopeid);

-- ============================================================
-- 2. BACKFILL — factura y cotización (scope register)
-- ============================================================
-- Fuentes seguras (columnas que existen con certeza): lo realmente emitido en
-- `transaction`, los arriendos de numbering_lease y el piso que guardó el
-- commit b334095b en register.data. Los contadores legacy se suman en el paso
-- 4, guardados por information_schema.

INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT r.companyid,
       'factura',
       'register',
       r.registerid,
       GREATEST(
         COALESCE((SELECT MAX(t.invoiceno) FROM transaction t
                    WHERE t.registerid = r.registerid
                      AND t.companyid  = r.companyid
                      -- ::text a propósito: SaleService persiste el tipo como
                      -- string y no está garantizado que la columna sea int.
                      -- Comparar varchar con int aborta la migración entera.
                      AND t.transactiontype::text IN ('0', '3')), 0) + 1,
         COALESCE((SELECT MAX(l."invoiceNo") FROM numbering_lease l
                    WHERE l."registerId" = r.registerid
                      AND l."companyId"  = r.companyid), 0) + 1,
         -- El piso guarda el PRÓXIMO, no el último: va sin +1. El regex evita
         -- que un JSONB editado a mano reviente el cast y con él el deploy.
         CASE WHEN r.data -> 'registerNumbering' ->> 'factura' ~ '^[0-9]+$'
              THEN (r.data -> 'registerNumbering' ->> 'factura')::bigint
              ELSE 1 END,
         1
       )
FROM register r
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT r.companyid,
       'cotizacion',
       'register',
       r.registerid,
       GREATEST(
         COALESCE((SELECT MAX(t.invoiceno) FROM transaction t
                    WHERE t.registerid = r.registerid
                      AND t.companyid  = r.companyid
                      AND t.transactiontype::text = '9'), 0) + 1,
         CASE WHEN r.data -> 'registerNumbering' ->> 'cotizacion' ~ '^[0-9]+$'
              THEN (r.data -> 'registerNumbering' ->> 'cotizacion')::bigint
              ELSE 1 END,
         1
       )
FROM register r
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

-- ============================================================
-- 3. BACKFILL — orden (scope outlet, decisión del owner)
-- ============================================================
-- `pos_order.ordernumber` ya es por sucursal; se siembra desde ahí y no desde
-- los contadores de caja, que son de otra dimensión.

INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT o.companyid,
       'orden',
       'outlet',
       o.outletid,
       -- COALESCE: si todas las órdenes de la sucursal tienen ordernumber NULL,
       -- MAX devuelve NULL y `NULL + 1` violaría el NOT NULL de nextnumber.
       COALESCE(MAX(o.ordernumber), 0) + 1
FROM pos_order o
WHERE o.outletid IS NOT NULL
GROUP BY o.companyid, o.outletid
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

-- ============================================================
-- 4. BACKFILL — contadores legacy de `register` (guardado)
-- ============================================================
-- Mig 26 declara que estas columnas SE MANTIENEN, pero no todas las bases
-- necesariamente las tienen y una migración que referencia una columna
-- inexistente aborta el deploy entero (precedente: migs 74/77). Se leen solo
-- si information_schema las reporta.
--
-- Guardan el ÚLTIMO usado → +1 para obtener el próximo. Solo SUBEN el valor
-- ya sembrado: si un contador quedó por debajo de lo realmente emitido, gana
-- lo emitido.

CREATE OR REPLACE FUNCTION pg_temp.seed_from_legacy_counter(p_col text, p_doctype text)
RETURNS void AS $fn$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'register' AND column_name = p_col
  ) THEN
    EXECUTE format(
      'UPDATE document_sequence s
          SET nextnumber = GREATEST(s.nextnumber, COALESCE(r.%I, 0) + 1),
              updated_at = now()
         FROM register r
        WHERE r.registerid = s.scopeid
          AND r.companyid  = s.companyid
          AND s.scopetype  = ''register''
          AND s.doctype    = %L',
      p_col, p_doctype
    );
  END IF;
END;
$fn$ LANGUAGE plpgsql;

SELECT pg_temp.seed_from_legacy_counter('registerinvoicenumber', 'factura');
SELECT pg_temp.seed_from_legacy_counter('registerquotenumber',   'cotizacion');

COMMIT;
