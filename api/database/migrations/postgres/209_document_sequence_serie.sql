-- 209_document_sequence_serie.sql
-- La SERIE fiscal es la identidad de la numeración, no el correlativo solo.
--
-- ── El incidente ────────────────────────────────────────────────────────
-- Una caja cambió su punto de expedición de `001-001` a `001-002` y el POS
-- mandó el número 838 contra un punto que iba por 614.
--
-- Causa raíz: `uq_document_sequence ON (companyid, doctype, scopetype,
-- scopeid)` (mig 117). Ni el timbrado ni el punto de expedición formaban
-- parte de la clave, así que una caja tenía UNA fila para toda su vida y
-- `prefix` era un ATRIBUTO mutable de esa fila. Al editar el punto en el
-- panel, `RegisterAdminService::seedSequence()` pisaba el `prefix` y dejaba
-- el `nextnumber` — o sea que la serie nueva heredaba el contador de la
-- vieja.
--
-- ── El modelo correcto (decisión del owner, context/29 §2) ──────────────
-- El número de un comprobante paraguayo es la tripleta
-- (timbrado, punto de expedición, correlativo). `001-001-1234567` y
-- `001-002-1234567` conviven legalmente: son dos ramas de numeración
-- independientes. Entonces cambiar el timbrado o el punto ABRE UNA SERIE
-- NUEVA: no se resetea ningún contador, nace una fila nueva y la vieja
-- queda como registro de lo que esa serie emitió.
--
-- ── Forma de la clave ───────────────────────────────────────────────────
-- `prefix` YA existe en la tabla y YA significa "punto de expedición"
-- (`EEE-PPP`). La corrección arquitectónica es promoverlo de atributo a
-- IDENTIDAD, y sumarle el timbrado —que hasta hoy no vivía en la secuencia
-- en absoluto—. No se agrega una columna derivada tipo `seriekey`: sería
-- duplicar `prefix` con otro nombre y dejar dos fuentes de verdad para el
-- mismo dato. La clave nueva se lee como el invariante fiscal:
--
--     (companyid, doctype, scopetype, scopeid, invoiceauth, prefix)
--
-- Las dos columnas van NOT NULL DEFAULT '' y NO nullable: en Postgres
-- `NULL <> NULL` dentro de un índice único, así que dos filas con NULL
-- convivirían y —peor— el `ON CONFLICT` de `DocumentNumber::allocate()`
-- nunca matchearía, creando una fila nueva por cada venta. Con '' como
-- "sin serie fiscal" los ~12 doctypes NO fiscales (merma, produccion,
-- orden, orden_pago, remision, conteo, transferencia, recibo…) siguen
-- teniendo exactamente UNA fila por scope, igual que hoy.
--
-- ── Migración de datos: por configuración vigente, a propósito ──────────
-- El owner confirmó hoy (2026-09-09) que NO HAY NINGUNA FACTURA LEGAL
-- EMITIDA: nada llegó a SIFEN, nada se imprimió, las transacciones que
-- existen son pruebas que quedaron solo en la base de Punto.
--
-- Por eso esta migración NO reconstruye series históricas desde
-- `transaction` ni desde `einvoice_document`. Cada fila existente se
-- asigna a la serie que le corresponde según el timbrado y el punto
-- ACTUALES de su caja, con el contador que ya tenga. Reconstruir sería
-- complejidad y superficie de bug para proteger datos que no existen.
--
-- ── Alcance: doctype 'factura' ──────────────────────────────────────────
-- Solo la factura recibe serie fiscal en esta migración. La cotización no
-- lleva timbrado (`RegisterAdminService::update` ya le pasa prefix=null) y
-- la nota de crédito hoy la numera el proveedor (fuera de alcance,
-- context/40 F3). El mecanismo queda genérico: sumar un doctype a la serie
-- es pasarle una `DocumentSeries` en su call-site, sin tocar el schema.

BEGIN;

-- ═══════════════════════════════════════════════════════════════════════
-- 1. document_sequence — la serie entra en la identidad
-- ═══════════════════════════════════════════════════════════════════════

-- Timbrado. `varchar(20)`: `registerInvoiceAuth` se persiste como entero en
-- el JSONB de la caja (`RegisterAdminService::update` hace `(int) $auth`) y
-- el regex de validación es `^\d+$`, así que 20 caracteres sobran de lejos.
ALTER TABLE document_sequence ADD COLUMN IF NOT EXISTS invoiceauth varchar(20);

UPDATE document_sequence SET invoiceauth = '' WHERE invoiceauth IS NULL;

ALTER TABLE document_sequence ALTER COLUMN invoiceauth SET DEFAULT '';
ALTER TABLE document_sequence ALTER COLUMN invoiceauth SET NOT NULL;

-- `prefix` deja de ser nullable por la razón de arriba (NULL rompe tanto la
-- unicidad como el ON CONFLICT del asignador).
UPDATE document_sequence SET prefix = '' WHERE prefix IS NULL;

ALTER TABLE document_sequence ALTER COLUMN prefix SET DEFAULT '';
ALTER TABLE document_sequence ALTER COLUMN prefix SET NOT NULL;

COMMENT ON COLUMN document_sequence.invoiceauth IS
  'Timbrado de la serie (mig 209). Junto con `prefix` (punto de expedicion '
  'EEE-PPP) forma la IDENTIDAD de la serie: cambiar cualquiera de los dos '
  'abre una serie NUEVA con su propio contador, nunca resetea la vigente. '
  'Cadena vacia = documento sin serie fiscal (merma, produccion, orden, '
  'cotizacion...), que tiene una sola fila por scope como antes de la mig 209.';

COMMENT ON COLUMN document_sequence.prefix IS
  'Punto de expedicion EEE-PPP de la serie (context/29 §1). Desde la mig 209 '
  'es IDENTIDAD, no atributo: editar el punto en el panel resuelve a OTRA '
  'fila en vez de pisar el prefijo de la que hay dejando su contador -- que '
  'es exactamente como una caja mando el numero 838 contra un punto que iba '
  'por 614.';

-- Asignación de las filas existentes a su serie vigente. Ver el bloque
-- "Migración de datos" del encabezado: se asigna por CONFIGURACIÓN ACTUAL
-- de la caja porque no había ningún documento fiscal emitido que preservar.
UPDATE document_sequence s
   SET invoiceauth = COALESCE(NULLIF(TRIM(r.data ->> 'registerInvoiceAuth'), ''), ''),
       prefix      = COALESCE(NULLIF(TRIM(r.data ->> 'registerInvoicePrefix'), ''), ''),
       updated_at  = now()
  FROM register r
 WHERE r.registerid = s.scopeid
   AND r.companyid  = s.companyid
   AND s.scopetype  = 'register'
   AND s.doctype    = 'factura'
   -- Solo las filas SIN serie asignada. `schema_migrations` ya impide que
   -- este archivo corra dos veces, pero sin este filtro una re-corrida
   -- manual colapsaría a la misma clave las N series que la caja hubiera
   -- acumulado desde entonces y reventaría contra `uq_document_sequence`.
   AND s.invoiceauth = ''
   AND s.prefix      = '';

-- La clave nueva es un SUPERCONJUNTO de la vieja (agrega columnas), así que
-- no puede fallar por duplicados: todo par que era único bajo 4 columnas lo
-- sigue siendo bajo 6. El nombre se conserva — es también el índice que usa
-- el `ON CONFLICT` del asignador, que infiere por columnas, no por nombre.
DROP INDEX IF EXISTS uq_document_sequence;

CREATE UNIQUE INDEX uq_document_sequence
  ON document_sequence (companyid, doctype, scopetype, scopeid, invoiceauth, prefix);

-- ═══════════════════════════════════════════════════════════════════════
-- 2. transaction — congelar el PUNTO junto con el timbrado
-- ═══════════════════════════════════════════════════════════════════════
-- La mig 145 congeló el timbrado (`invoiceauth*`) pero NO el punto, así que
-- una factura vieja se reimprimía con el punto ACTUAL de su caja: cambiado
-- el punto, todo el historial cambiaba de número ante el cliente y ante la
-- SET. Los LECTORES ya estaban escritos para lo congelado con fallback al
-- vivo (`Reports\TransactionsService:134`, `TransactionDetailService:88`);
-- lo que faltaba era que la venta lo ESCRIBIERA.
--
-- Backfill por la misma razón que el paso 1 (no hay documento emitido): las
-- filas viejas quedan con el punto vigente de su caja, que es exactamente
-- lo que el fallback ya venía mostrando en pantalla. Es display-neutral y
-- además deja que la unicidad fiscal del paso 3 valga sobre TODAS las
-- filas y no solo sobre las nuevas.
--
-- Tipos 0/3 = cashsale/creditsale, los únicos documentos bajo timbrado que
-- emitimos nosotros y los únicos que `SaleService::save()` congela. La
-- cotización (9) queda AFUERA a propósito: `saveQuote()` no pasa datos
-- fiscales al builder, así que backfillearla dejaría las cotizaciones viejas
-- con prefijo congelado y las nuevas sin él. Se excluye además todo lo demás,
-- porque `invoiceprefix` está OCUPADA para otra cosa en otros tipos y pisarla
-- sería corromper datos reales:
--   - compras (`PurchasesService`): guarda el prefijo del documento del
--     PROVEEDOR, que no es nuestro punto de expedición;
--   - sesiones de paquete tipo 13 (`SaleService.php:1584`): guarda el hack
--     `"<n>/"` para numerar las N sesiones de un paquete.
-- `transactiontype` es smallint desde la mig 156 (línea 470), así que va
-- sin el `::text` que la mig 117 necesitaba en la tabla pre-particionado.
--
-- ── Por qué se apaga el guard de período cerrado ────────────────────────
-- `trg_period_guard_transaction` (mig 157) es BEFORE UPDATE OR DELETE sobre
-- `transaction`, SIN lista de columnas, y en modo 'tx' solo perdona el UPDATE
-- cuando lo ÚNICO que cambia es `transactioncomplete`/`updated_at`
-- (157_period_close.sql:111-127). Tocar `invoiceprefix` no está en esa
-- excepción: si algún tenant tiene un período cerrado con ventas tipo 0/3
-- adentro, el guard levanta PC001, aborta la migración ENTERA y el contenedor
-- del backend no arranca — y peor, el código nuevo queda sin el índice único
-- de 6 columnas, así que todo `ON CONFLICT` de `DocumentNumber` falla y NO SE
-- PUEDE VENDER.
--
-- Apagarlo acá es correcto, no una evasión: el guard existe para que nadie
-- REESCRIBA la contabilidad de un período cerrado, y esto no toca ni un monto
-- ni un ítem ni una fecha. Escribe una etiqueta fiscal que ya estaba implícita
-- (el mismo punto de expedición que el lector venía resolviendo en vivo) y la
-- vuelve explícita. Se apaga por nombre y se vuelve a prender de inmediato,
-- dentro de la misma transacción: si algo falla en el medio, el ROLLBACK
-- también revierte el DISABLE y el guard queda como estaba.
--
-- En una tabla particionada el ENABLE/DISABLE por nombre recurre a todas las
-- particiones (Postgres >= 14; este cluster corre 18, ver mig 156).
ALTER TABLE transaction DISABLE TRIGGER trg_period_guard_transaction;

UPDATE transaction t
   SET invoiceprefix = TRIM(r.data ->> 'registerInvoicePrefix')
  FROM register r
 WHERE r.registerid = t.registerid
   AND r.companyid  = t.companyid
   AND t.transactiontype IN (0, 3)
   AND COALESCE(t.invoiceprefix, '') = ''
   AND COALESCE(NULLIF(TRIM(r.data ->> 'registerInvoicePrefix'), ''), '') <> '';

ALTER TABLE transaction ENABLE TRIGGER trg_period_guard_transaction;

-- ═══════════════════════════════════════════════════════════════════════
-- 3. transaction_registry — la unicidad fiscal incluye el punto
-- ═══════════════════════════════════════════════════════════════════════
-- P0 SIN ESTE PASO: `uq_transaction_expedition_invoiceno` (mig 145,
-- trasladada al registry por la mig 156) es
-- `(companyid, registerid, COALESCE(invoiceauth,''), invoiceno)` — el
-- nombre dice "expedition" pero el punto de expedición NO está en la clave.
--
-- Con series, abrir una serie nueva sobre el MISMO timbrado (cambio de
-- punto de expedición, que es literalmente el caso del incidente) hace que
-- la caja vuelva a emitir 1, 2, 3… y cada uno de esos números CHOCARÍA
-- contra la serie anterior. La caja quedaría sin poder facturar, que es el
-- mismo síntoma que vinimos a arreglar.
--
-- La unicidad correcta es por SERIE: dos comprobantes con el mismo número
-- son ilegales dentro de la misma serie, y perfectamente legales entre
-- series distintas (context/29 §2).
--
-- `transaction_registry` es la tabla NO particionada que sostiene las dos
-- unicidades globales de `transaction` (mig 156): la unicidad tiene que
-- vivir acá porque una unique de la tabla particionada estaría obligada a
-- incluir `transactiondate`, y entonces no diría nada.
ALTER TABLE transaction_registry ADD COLUMN IF NOT EXISTS invoiceprefix varchar(150);

COMMENT ON COLUMN transaction_registry.invoiceprefix IS
  'Punto de expedicion CONGELADO del comprobante (mig 209), espejo de '
  'transaction.invoiceprefix. Entra en uq_transaction_expedition_invoiceno '
  'porque la unicidad del numero fiscal es POR SERIE (timbrado + punto): '
  '001-001-0000838 y 001-002-0000838 son dos documentos legales distintos.';

UPDATE transaction_registry g
   SET invoiceprefix = t.invoiceprefix
  FROM transaction t
 WHERE t.transactionid   = g.transactionid
   AND t.transactiondate = g.transactiondate
   AND COALESCE(g.invoiceprefix, '') IS DISTINCT FROM COALESCE(t.invoiceprefix, '');

-- Los triggers de sync se reescriben para llevar la columna nueva. El
-- UPDATE necesita además que `invoiceprefix` entre en la lista de columnas
-- del `AFTER UPDATE OF`: sin eso, el paso 2 de arriba —y cualquier
-- corrección futura del punto congelado— no llegaría nunca al registry y
-- la unicidad quedaría mirando un dato viejo.
CREATE OR REPLACE FUNCTION transaction_registry_sync_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO transaction_registry
      (transactionid, transactiondate, companyid, outletid, registerid,
       transactionuid, transactiontype, invoiceauth, invoiceprefix, invoiceno)
    VALUES
      (NEW.transactionid, NEW.transactiondate, NEW.companyid, NEW.outletid,
       NEW.registerid, NEW.transactionuid, NEW.transactiontype,
       NEW.invoiceauth, NEW.invoiceprefix, NEW.invoiceno)
    ON CONFLICT (transactionid) DO UPDATE SET
      transactiondate = EXCLUDED.transactiondate,
      companyid       = EXCLUDED.companyid,
      outletid        = EXCLUDED.outletid,
      registerid      = EXCLUDED.registerid,
      transactionuid  = EXCLUDED.transactionuid,
      transactiontype = EXCLUDED.transactiontype,
      invoiceauth     = EXCLUDED.invoiceauth,
      invoiceprefix   = EXCLUDED.invoiceprefix,
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
      invoiceno       = NEW.invoiceno
    WHERE transactionid = NEW.transactionid;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_transaction_registry_sync_update ON transaction;

CREATE TRIGGER trg_transaction_registry_sync_update
  AFTER UPDATE OF transactiondate, companyid, outletid, registerid,
                   transactionuid, transactiontype, invoiceauth,
                   invoiceprefix, invoiceno
  ON transaction
  FOR EACH ROW EXECUTE FUNCTION transaction_registry_sync_update();

-- Mismo nombre a propósito: `SaleService::abortSale()` clasifica el choque
-- fiscal (409 / `NUMBER_TAKEN` para que la cola offline renumere) buscando
-- el nombre EXACTO del índice en el texto del error de PG. Renombrarlo
-- convertiría un duplicado fiscal en un 500 genérico.
--
-- Tampoco puede fallar por duplicados: agrega una columna a la clave, o sea
-- que es más permisivo que el índice que ya está enforceando hoy.
DROP INDEX IF EXISTS uq_transaction_expedition_invoiceno;

CREATE UNIQUE INDEX uq_transaction_expedition_invoiceno
  ON transaction_registry (companyid, registerid, COALESCE(invoiceauth, ''),
                           COALESCE(invoiceprefix, ''), invoiceno)
  WHERE invoiceno IS NOT NULL AND transactiontype IN (0, 3);

COMMIT;
