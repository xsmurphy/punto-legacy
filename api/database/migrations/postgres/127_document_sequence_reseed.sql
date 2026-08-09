-- 127_document_sequence_reseed.sql
-- Re-siembra de `document_sequence` inmediatamente antes de que F2 (context/37)
-- ponga a los emisores a consumirla.
--
-- Por qué hace falta si la mig 117 ya sembró: entre 117 y este deploy los
-- emisores siguieron siendo `MAX(invoiceNo)+1` y `registerQuoteNumber`, así que
-- cada venta y cada cotización emitida en el medio dejó a `nextnumber` atrás.
-- Arrancar a asignar desde un contador atrasado REEMITIRÍA un número ya usado
-- — una factura duplicada, que es ilegal ante la SET. Esta migración vuelve a
-- tomar el GREATEST de todas las fuentes, igual que el seed original.
--
-- Es idempotente y solo SUBE: correrla de nuevo no puede bajar un contador.
-- Corre en el mismo deploy que el código de F2, que es lo que cierra la
-- ventana — después de esto ninguna emisión pasa por MAX().
--
-- También siembra las cajas creadas después de la 117 (INSERT ... NOT EXISTS).
-- `register`/`transaction`/`pos_order` son DDL legacy: lowercase sin comillas.
-- `numbering_lease` es camelCase quoted (mig 54).

BEGIN;

-- ============================================================
-- 1. Cajas nuevas (creadas después de la mig 117)
-- ============================================================

INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT r.companyid, d.doctype, 'register', r.registerid, 1
FROM register r
CROSS JOIN (VALUES ('factura'), ('cotizacion')) AS d(doctype)
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT o.companyid, 'orden', 'outlet', o.outletid, COALESCE(MAX(o.ordernumber), 0) + 1
FROM pos_order o
WHERE o.outletid IS NOT NULL
GROUP BY o.companyid, o.outletid
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

-- ============================================================
-- 2. Re-seed — solo sube, nunca baja
-- ============================================================
-- El piso de `register.data.registerNumbering` se sigue considerando: es lo
-- último que el operador cargó a mano en el panel y puede ser MAYOR que lo
-- emitido (timbrado nuevo que arranca en 2336 sin ventas todavía). F2 deja de
-- escribirlo; queda leído por última vez acá.

UPDATE document_sequence s
   SET nextnumber = GREATEST(
         s.nextnumber,
         COALESCE((SELECT MAX(t.invoiceno) FROM transaction t
                    WHERE t.registerid = s.scopeid
                      AND t.companyid  = s.companyid
                      -- ::text a propósito: SaleService persiste el tipo como
                      -- string y la columna no está garantizada como int.
                      AND t.transactiontype::text IN ('0', '3')), 0) + 1,
         COALESCE((SELECT MAX(l."invoiceNo") FROM numbering_lease l
                    WHERE l."registerId" = s.scopeid
                      AND l."companyId"  = s.companyid), 0) + 1,
         CASE WHEN r.data -> 'registerNumbering' ->> 'factura' ~ '^[0-9]+$'
              THEN (r.data -> 'registerNumbering' ->> 'factura')::bigint
              ELSE 1 END
       ),
       updated_at = now()
  FROM register r
 WHERE r.registerid = s.scopeid
   AND r.companyid  = s.companyid
   AND s.scopetype  = 'register'
   AND s.doctype    = 'factura';

UPDATE document_sequence s
   SET nextnumber = GREATEST(
         s.nextnumber,
         COALESCE((SELECT MAX(t.invoiceno) FROM transaction t
                    WHERE t.registerid = s.scopeid
                      AND t.companyid  = s.companyid
                      AND t.transactiontype::text = '9'), 0) + 1,
         CASE WHEN r.data -> 'registerNumbering' ->> 'cotizacion' ~ '^[0-9]+$'
              THEN (r.data -> 'registerNumbering' ->> 'cotizacion')::bigint
              ELSE 1 END
       ),
       updated_at = now()
  FROM register r
 WHERE r.registerid = s.scopeid
   AND r.companyid  = s.companyid
   AND s.scopetype  = 'register'
   AND s.doctype    = 'cotizacion';

-- Contador legacy de cotización: guarda la ÚLTIMA usada, de ahí el +1.
-- Guardado por information_schema — referenciar una columna inexistente aborta
-- el deploy entero (precedente: migs 74/77).
DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'register' AND column_name = 'registerquotenumber'
  ) THEN
    EXECUTE '
      UPDATE document_sequence s
         SET nextnumber = GREATEST(s.nextnumber, COALESCE(r.registerquotenumber, 0) + 1),
             updated_at = now()
        FROM register r
       WHERE r.registerid = s.scopeid
         AND r.companyid  = s.companyid
         AND s.scopetype  = ''register''
         AND s.doctype    = ''cotizacion''';
  END IF;
END $$;

-- ============================================================
-- 3. Rango y prefijo del timbrado
-- ============================================================
-- El timbrado ya vive en `register.data` (mig 26) y la caja ES el punto de
-- expedición. `rangeto` es lo que le permite al asignador cortar la emisión al
-- agotarse el rango en vez de facturar fuera de timbrado.

UPDATE document_sequence s
   SET prefix = NULLIF(r.data ->> 'registerInvoicePrefix', ''),
       updated_at = now()
  FROM register r
 WHERE r.registerid = s.scopeid
   AND r.companyid  = s.companyid
   AND s.scopetype  = 'register'
   AND s.doctype    = 'factura'
   AND s.prefix IS NULL;

COMMIT;
