-- 145_transaction_invoiceno_uniqueness.sql
-- P0 fiscal (context/29 §2): "por timbrado, la combinación punto de
-- expedición + correlativo es única". Sacado el arriendo de bloques (mig
-- 141-144), la única red que queda contra un duplicado real es
-- `register_lease` (protege contra DOS DISPOSITIVOS, no contra el MISMO
-- dispositivo re-emitiendo un correlativo viejo bajo un uid nuevo) y la
-- idempotencia por transactionUID (solo atrapa el reintento EXACTO). Esta
-- migración agrega la garantía que falta: un índice único en la BASE.
--
-- ── 1. Congelar el timbrado en la transacción (columnas nuevas) ──────────
-- `register.data->>'registerInvoiceAuth'` es CONFIGURACIÓN MUTABLE de la
-- caja (RegisterAdminService la puede editar en cualquier momento). Sin
-- congelarlo, (a) un cambio de timbrado en la caja reescribiría en silencio
-- el timbrado que reportan documentos YA EMITIDOS (mismo problema que
-- resolvió el congelado de IVA por línea, SaleService::enrichWithTaxes,
-- context/38), y (b) el índice único de abajo no tendría con qué columna
-- comparar sin volver a leer `register` en cada INSERT.
--
-- Se congelan las 3: número, inicio y vencimiento — son las que los bloques
-- de plantilla `auth_number`/`auth_start_date`/`auth_expiration`
-- (frontend/lib/print-template-palette.ts) ya esperan y hoy salen vacíos
-- porque el timbrado nunca viajó con el documento. Esta migración deja el
-- dato disponible; conectarlo a la plantilla queda fuera de esta tarea.
--
-- TEXT, no INT/DATE: espeja el shape que ya usa `register.data` (auth es un
-- string numérico, start/expiration son 'YYYY-MM-DD' — ver
-- RegisterAdminService::patch, validación `preg_match('/^\d{4}-\d{2}-\d{2}$/')`).
-- Evita conversión con pérdida y mantiene el mismo formato de punta a punta.
--
-- Lowercase SIN comillas — igual que el resto de `transaction`
-- (invoiceNo/registerId/companyId) — porque `DB::AutoExecute()` arma el
-- INSERT con las keys del array PHP tal cual, sin comillas (mig 71 documentó
-- el bug de una columna quoted rompiendo ese wrapper).
BEGIN;

ALTER TABLE transaction ADD COLUMN IF NOT EXISTS invoiceauth           TEXT;
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS invoiceauthstart      TEXT;
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS invoiceauthexpiration TEXT;

-- ── 2. Índice único parcial — el invariante hecho cumplir en la BASE ─────
--
-- Clave: (companyid, registerid, timbrado, invoiceno). El timbrado entra
-- por dos motivos: (a) `NULL != NULL` en un UNIQUE de Postgres — hoy NINGÚN
-- register de producción tiene timbrado cargado, así que sin COALESCE el
-- índice compilaría pero no protegería nada; (b) una caja PUEDE renovar de
-- timbrado (talonario nuevo) y el correlativo del talonario nuevo puede
-- legítimamente pisar rangos del talonario viejo — el timbrado congelado es
-- lo que separa esas dos ramas sin bloquear la renovación.
--
-- Scope adicional — transactiontype IN (0, 3): la caja es UN punto de
-- expedición, pero por esa misma caja pasan documentos con correlativo
-- PROPIO que NO comparten talonario con la factura (SaleType::Quote=9 vía
-- `SaleService::saveQuote()`, con su propia secuencia `document_sequence`
-- doctype='cotizacion'; devoluciones type=6 vía `ReturnService`, scope
-- OUTLET, doctype='nota_credito'; pagos de crédito type=5 sin numeración
-- propia, invoiceno=0 de relleno). Verificado en producción (2026-08-17,
-- solo lectura): sin este filtro el índice hoy NO se podría crear — hay 3
-- pares (type=0 factura, type=9 cotización) que coinciden en el mismo
-- invoiceno crudo por pura coincidencia de secuencias independientes, y 11
-- filas type=5 comparten invoiceno=0 como sentinel de "sin numeración"
-- (mismo criterio que ya usa `Document::getNextDocNumber()`, que excluye
-- `invoiceNo > 0` al buscar el último real usado). Ninguno de esos casos es
-- un duplicado fiscal — son ramas de numeración distintas por diseño
-- (context/29 §3, "recibo/devolución con correlativo propio").
-- SaleType::Cashsale=0 y SaleType::Creditsale=3 son los ÚNICOS dos tipos
-- que `SaleInput::fromPayload()` acepta (`isSimplePathEligible()`) — es el
-- mismo universo "factura" que ya usa `enqueueElectronicInvoice()` (FC/FCR)
-- y el `DocumentNumber::advanceTo('factura', ...)` que sales.php/
-- offline-sync.php llaman tras guardar. No es un scope nuevo inventado acá,
-- es el que el resto del código ya trata como "la" factura.
--
-- Con esos dos filtros, la comprobación contra producción (2026-08-17,
-- solo lectura) da CERO duplicados reales — el índice se crea limpio hoy.
-- Igual se deja el guard defensivo (WARNING + skip, no abort) por si otro
-- entorno (dev/staging/tenant futuro) sí tiene datos sucios: un CREATE
-- UNIQUE INDEX que aborta tira el deploy entero, imagen vieja incluida
-- (precedente migs 74/77/122, ~4h de downtime; mismo patrón que mig 143).
-- Nada retroactivo — si hay duplicados, esta migración NO los toca, solo
-- avisa y no crea el índice hasta que se resuelvan a mano.
DO $$
DECLARE
  dupes text;
BEGIN
  SELECT string_agg(detail, '; ')
    INTO dupes
    FROM (
      SELECT companyid::text || ' / caja ' || COALESCE(registerid::text, '(sin caja)')
             || ' / timbrado ' || COALESCE(invoiceauth, '(vacío)')
             || ' / comprobante ' || invoiceno::text
             || ' (' || count(*)::text || ' filas)' AS detail
        FROM transaction
       WHERE invoiceno IS NOT NULL
         AND transactiontype IN (0, 3)
       GROUP BY companyid, registerid, invoiceauth, invoiceno
      HAVING count(*) > 1
    ) d;

  IF dupes IS NOT NULL THEN
    RAISE WARNING
      'mig 145: comprobantes duplicados (companyId/registerId/timbrado/invoiceNo), índice NO creado. Resolver a mano y re-correr. Detalle: %',
      dupes;
    RETURN;
  END IF;

  CREATE UNIQUE INDEX IF NOT EXISTS uq_transaction_expedition_invoiceno
      ON transaction (companyid, registerid, COALESCE(invoiceauth, ''), invoiceno)
   WHERE invoiceno IS NOT NULL
     AND transactiontype IN (0, 3);
END $$;

COMMIT;
