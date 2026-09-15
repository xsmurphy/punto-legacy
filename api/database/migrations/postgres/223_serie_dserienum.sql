-- 223_serie_dserienum.sql
-- La SERIE de SIFEN (`dSerieNum`, dos letras) entra en la identidad de la serie
-- fiscal, y el timbrado del emisor deja la forma del motor anterior.
--
-- ── Qué es la serie y por qué hace falta ────────────────────────────────
-- SIFEN permite informar `dSerieNum` (C010, dentro de `gTimb`): dos letras
-- mayúsculas. Si un punto de expedición (timbrado + establecimiento + punto)
-- ya emitió CON serie, todo documento posterior en ese punto tiene que
-- informar LA MISMA serie o SIFEN rechaza con `1110 — Serie informada
-- incorrecta`. Punto nunca mandaba serie.
--
-- Caso real (Balloon Party, timbrado 18260177): su punto 001-001 lo usaba otro
-- sistema que emitía con serie `AA`, así que las facturas 838/839/840 de Punto
-- volvieron rechazadas con 1110. Esta migración NO carga la serie de nadie ni
-- toca esos documentos: el 001-001 lo comparten dos emisores y eso lo resuelve
-- el owner con el cliente (context/28 §F8).
--
-- ── Decisiones del owner (2026-09-15) ───────────────────────────────────
--   1. Configurable por punto de expedición, OPCIONAL. Por defecto no se manda.
--   2. La serie es de PUNTO y vive junto al timbrado y el punto (acá), no como
--      copia en el motor.
--   3. Viaja en el body de cada `POST /de` junto con el número.
--   4. Ningún valor precargado: ni en código, ni en esta migración, ni en UI.
--
-- ── Identidad, no atributo ──────────────────────────────────────────────
-- En SIFEN, al cambiar la serie (AA → AB cuando se agota el correlativo) la
-- numeración REINICIA: `AA-001-001-0000001` y `AB-001-001-0000001` son dos
-- documentos legales distintos. Es exactamente el mismo razonamiento que la
-- mig 209 aplicó al timbrado y al punto: la serie es parte de la IDENTIDAD de
-- la serie fiscal, y cambiarla abre una secuencia NUEVA (la vieja queda como
-- registro de lo que emitió). Como atributo editable de la fila, cambiar AA por
-- AB reetiquetaría el contador de AA — el incidente del 838 contra un punto que
-- iba por 614, otra vez, por otra puerta.
--
-- La identidad queda:  (timbrado, punto de expedición, serie, correlativo)
--
-- ── Por doctype ─────────────────────────────────────────────────────────
-- La numeración de SIFEN es por tipo de documento: la factura y la nota de
-- crédito son dos talonarios bajo el mismo timbrado y punto, y un sistema
-- anterior pudo emitir facturas con serie y notas de crédito sin ella. Por eso
-- la serie es de cada fila de `document_sequence` (que ya está partida por
-- doctype) y se configura en la caja por separado para factura y NC
-- (`register.data.registerInvoiceSerie` / `registerCreditNoteSerie`).
--
-- ── Terreno: nada que reconstruir ───────────────────────────────────────
-- Nadie mandó nunca una serie, así que toda fila existente ES serie vacía y el
-- default '' la describe con exactitud. No hay backfill.
--
-- ── DEPLOY: ventana conocida, hacerlo en horario de BAJO TRÁFICO ────────
-- Esta migración reemplaza `uq_document_sequence` por la clave de 7 columnas.
-- Entre que corre (al arrancar el contenedor NUEVO) y que el contenedor VIEJO
-- deja de atender, el código viejo sigue haciendo
-- `ON CONFLICT (companyid, doctype, scopetype, scopeid, invoiceauth, prefix)`,
-- que ya no coincide con ningún índice único → Postgres responde 42P10 y la
-- numeración SERVER-SIDE falla en esa ventana (cotización, orden, recibo, NC,
-- documentos de stock y el `advanceTo()` post-venta, que es best-effort y no
-- tumba la venta). Las facturas del POS no se ven afectadas: el número lo
-- decide el device. La ventana dura lo que tarda en levantar el backend (~1
-- min); por eso el deploy va en horario de bajo tráfico. Ver context/29 §7.
--
-- `lock_timeout`: el DROP/CREATE INDEX y los ALTER toman locks exclusivos; si
-- un reporte largo tiene `transaction`/`transaction_registry` tomada, la
-- migración NO se queda encolada detrás bloqueando todas las ventas que llegan
-- después — falla en 10 s, el contenedor nuevo no arranca y el viejo sigue
-- sirviendo. Se reintenta el deploy.

BEGIN;

SET LOCAL lock_timeout = '10s';

-- ═══════════════════════════════════════════════════════════════════════
-- 1. document_sequence — la serie entra en la clave
-- ═══════════════════════════════════════════════════════════════════════
-- NOT NULL DEFAULT '' por la misma razón que `invoiceauth`/`prefix` en la
-- mig 209: en un índice único de Postgres `NULL <> NULL`, así que con NULL el
-- `ON CONFLICT` del asignador no matchearía nunca y crearía una fila por venta.
ALTER TABLE document_sequence ADD COLUMN IF NOT EXISTS serie varchar(2);

UPDATE document_sequence SET serie = '' WHERE serie IS NULL;

ALTER TABLE document_sequence ALTER COLUMN serie SET DEFAULT '';
ALTER TABLE document_sequence ALTER COLUMN serie SET NOT NULL;

-- El formato se enforcea también en la base: el motor responde 422 ante una
-- serie que no sea `^[A-Z]{2}$`, y una fila con 'aa' o 'A' sería una serie
-- que nunca puede emitir. Se busca por NOMBRE (conname), nunca por el texto de
-- la definición, que Postgres normaliza.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conname = 'document_sequence_serie_format'
       AND conrelid = 'document_sequence'::regclass
  ) THEN
    ALTER TABLE document_sequence
      ADD CONSTRAINT document_sequence_serie_format
      CHECK (serie = '' OR serie ~ '^[A-Z]{2}$');
  END IF;
END
$$;

COMMENT ON COLUMN document_sequence.serie IS
  'Serie SIFEN (dSerieNum, C010) de la numeracion (mig 223). IDENTIDAD, no '
  'atributo: junto con invoiceauth y prefix forma la serie fiscal, y cambiarla '
  '(AA -> AB) abre una secuencia NUEVA con su propio contador. Cadena vacia = '
  'sin serie (el default: solo se completa cuando el sistema anterior emitia con '
  'serie en ese punto). Nunca se precarga.';

-- Superconjunto de la clave vigente (agrega una columna): no puede fallar por
-- duplicados. El nombre se conserva — es el índice que infiere el
-- `ON CONFLICT` de `DocumentNumber` y de `RegisterAdminService::seedSequence`.
DROP INDEX IF EXISTS uq_document_sequence;

CREATE UNIQUE INDEX uq_document_sequence
  ON document_sequence (companyid, doctype, scopetype, scopeid, invoiceauth, prefix, serie);

-- ═══════════════════════════════════════════════════════════════════════
-- 2. transaction — congelar la serie junto con el timbrado y el punto
-- ═══════════════════════════════════════════════════════════════════════
-- Mismo invariante que las migs 145 (timbrado) y 209 (punto): lo que se
-- numeró se congela en el documento. Un reintento o una reemisión arman el
-- body desde la transacción, así que tienen que mandar la serie con la que se
-- NUMERÓ, no la que la caja tenga configurada hoy.
--
-- Nullable y sin default: en la tabla particionada (mig 156) eso es solo
-- catálogo, sin reescritura. NULL y '' significan lo mismo ("sin serie") y
-- todos los lectores hacen COALESCE.
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS invoiceserie varchar(2);

COMMENT ON COLUMN transaction.invoiceserie IS
  'Serie SIFEN (dSerieNum) CONGELADA al numerar el comprobante (mig 223). La '
  'lee la emision electronica: un reintento o reemision manda esta, no la '
  'vigente de la caja. NULL = sin serie.';

-- ═══════════════════════════════════════════════════════════════════════
-- 3. transaction_registry — la unicidad fiscal es POR SERIE
-- ═══════════════════════════════════════════════════════════════════════
-- Abrir la serie AB en un punto que emitió con AA hace que la caja vuelva a
-- numerar desde 1, y cada número CHOCARÍA contra los de AA bajo el índice de la
-- mig 209: la caja quedaría sin poder facturar justo al rotar la serie. Dentro
-- de una serie el duplicado sigue siendo imposible; entre series es legal.
ALTER TABLE transaction_registry ADD COLUMN IF NOT EXISTS invoiceserie varchar(2);

COMMENT ON COLUMN transaction_registry.invoiceserie IS
  'Espejo de transaction.invoiceserie (mig 223). Entra en las unicidades '
  'fiscales de factura y nota de credito: el numero es unico POR SERIE.';

CREATE OR REPLACE FUNCTION transaction_registry_sync_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO transaction_registry
      (transactionid, transactiondate, companyid, outletid, registerid,
       transactionuid, transactiontype, invoiceauth, invoiceprefix, invoiceserie, invoiceno)
    VALUES
      (NEW.transactionid, NEW.transactiondate, NEW.companyid, NEW.outletid,
       NEW.registerid, NEW.transactionuid, NEW.transactiontype,
       NEW.invoiceauth, NEW.invoiceprefix, NEW.invoiceserie, NEW.invoiceno)
    ON CONFLICT (transactionid) DO UPDATE SET
      transactiondate = EXCLUDED.transactiondate,
      companyid       = EXCLUDED.companyid,
      outletid        = EXCLUDED.outletid,
      registerid      = EXCLUDED.registerid,
      transactionuid  = EXCLUDED.transactionuid,
      transactiontype = EXCLUDED.transactiontype,
      invoiceauth     = EXCLUDED.invoiceauth,
      invoiceprefix   = EXCLUDED.invoiceprefix,
      invoiceserie    = EXCLUDED.invoiceserie,
      invoiceno       = EXCLUDED.invoiceno;
  RETURN NEW;
END;
$$;

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
      invoiceprefix   = NEW.invoiceprefix,
      invoiceserie    = NEW.invoiceserie,
      invoiceno       = NEW.invoiceno
    WHERE transactionid = NEW.transactionid;
  RETURN NEW;
END;
$$;

-- `invoiceserie` tiene que estar en la lista del `AFTER UPDATE OF`: sin eso
-- una corrección de la serie congelada no llegaría al registry y la unicidad
-- quedaría mirando un dato viejo (mismo motivo que `invoiceprefix` en la 209).
DROP TRIGGER IF EXISTS trg_transaction_registry_sync_update ON transaction;

CREATE TRIGGER trg_transaction_registry_sync_update
  AFTER UPDATE OF transactiondate, companyid, outletid, registerid,
                   transactionuid, transactiontype, invoiceauth,
                   invoiceprefix, invoiceserie, invoiceno
  ON transaction
  FOR EACH ROW EXECUTE FUNCTION transaction_registry_sync_update();

-- Mismo NOMBRE a propósito: `SaleService::abortSale()` clasifica el choque
-- fiscal (409 / `NUMBER_TAKEN`, para que la cola offline renumere) buscando el
-- nombre exacto del índice en el error de PG. Agrega una columna a la clave,
-- así que es más permisivo que el vigente y no puede fallar por duplicados.
DROP INDEX IF EXISTS uq_transaction_expedition_invoiceno;

CREATE UNIQUE INDEX uq_transaction_expedition_invoiceno
  ON transaction_registry (companyid, registerid, COALESCE(invoiceauth, ''),
                           COALESCE(invoiceprefix, ''), COALESCE(invoiceserie, ''), invoiceno)
  WHERE invoiceno IS NOT NULL AND transactiontype IN (0, 3);

-- El gemelo de la nota de crédito (mig 215), con la misma extensión.
DROP INDEX IF EXISTS uq_transaction_creditnote_invoiceno;

CREATE UNIQUE INDEX uq_transaction_creditnote_invoiceno
  ON transaction_registry (companyid, COALESCE(invoiceauth, ''), COALESCE(invoiceprefix, ''),
                           COALESCE(invoiceserie, ''), invoiceno)
  WHERE invoiceno IS NOT NULL
    AND transactiontype = 6
    AND COALESCE(invoiceprefix, '') <> '';

COMMENT ON INDEX uq_transaction_creditnote_invoiceno IS
  'Unicidad fiscal del numero de NOTA DE CREDITO, POR SERIE (timbrado + punto + '
  'serie SIFEN; migs 215 y 223). Gemelo disjunto de '
  'uq_transaction_expedition_invoiceno (factura, tipos 0/3). Parcial sobre '
  'invoiceprefix no vacio: un comercio sin facturacion electronica numera sus '
  'devoluciones sin serie fiscal y no puede quedar bloqueado por una regla fiscal.';

-- ═══════════════════════════════════════════════════════════════════════
-- 4. einvoice_account.stamp — el timbrado del emisor con forma PROPIA
-- ═══════════════════════════════════════════════════════════════════════
-- La columna guardaba un DTO que imitaba la API del motor anterior
-- (`StampNumber`, `Stablishment`, `ExpeditionPoint`, `CurrentNumber`,
-- `Serie`, `Deleted`), fabricado por el adaptador de FE-PY para que un parser
-- viejo lo siguiera entendiendo. De esos campos, los de punto, número y serie
-- iban SIEMPRE vacíos: FE-PY guarda un solo timbrado por emisor y el punto, el
-- número y la serie son de la CAJA (context/29). Lo único real era el timbrado.
--
-- Forma nueva, la del modelo de Punto:
--     { "numero": "18260177", "fechaInicio": "2024-01-01", "vencimiento": "" }
--
-- La COLUMNA conserva el nombre a propósito: el backend viejo sigue sirviendo
-- durante el deploy mientras esta migración corre en el contenedor nuevo, y
-- renombrarla rompería su `SELECT ... stamp` en el camino de emisión. El nombre
-- no es del motor anterior; la forma sí, y es lo que se reemplaza.
--
-- `jsonb_exists()` y no el operador `?`: colisiona con el placeholder de PDO
-- (context/08). Las filas que no tienen la forma vieja no se tocan.
UPDATE einvoice_account
   SET stamp = jsonb_build_object(
         'numero',      COALESCE(stamp ->> 'StampNumber', ''),
         'fechaInicio', COALESCE(stamp ->> 'StampDate', ''),
         'vencimiento', COALESCE(stamp ->> 'ExpirationDate', '')
       ),
       updated_at = now()
 WHERE stamp IS NOT NULL
   AND jsonb_typeof(stamp) = 'object'
   AND jsonb_exists(stamp, 'StampNumber');

COMMENT ON COLUMN einvoice_account.stamp IS
  'Timbrado del EMISOR tal como lo tiene el motor (mig 223): '
  '{numero, fechaInicio, vencimiento}. Es una proyeccion de lectura para el '
  'panel; la fuente de verdad del timbrado, el punto, la serie y el numero de '
  'cada documento es la CAJA (context/29). Objeto vacio = el motor no informo '
  'timbrado.';

COMMIT;
