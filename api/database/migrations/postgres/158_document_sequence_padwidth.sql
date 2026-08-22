-- 158_document_sequence_padwidth.sql
-- Ancho de padding por secuencia de documento (context/37, decisión "padwidth").
--
-- PROBLEMA: el panel guardaba "00002129" como próxima factura y el número
-- volvía como "2129". Correcto: `nextnumber` es un ENTERO y los ceros a la
-- izquierda son FORMATO, no dato. Guardarlos dentro del número obligaría a
-- que la columna fuera texto y rompería el asignador (`nextnumber + 1`), el
-- rango del timbrado (`rangeto`) y toda comparación de correlativos.
--
-- SOLUCIÓN: el ancho se declara al lado de la secuencia y el número se
-- formatea al pintarlo. Una sola fuente de verdad por (empresa, documento,
-- scope) — la misma fila que ya lleva `prefix` y `rangeto`.
--
-- Por qué acá y no en `register.data`: el legacy tenía
-- `registerDocsLeadingZeros` en el JSONB de la caja, un ancho ÚNICO para
-- todos los documentos de esa caja. En PY la nota de crédito, la nota de
-- débito y la remisión llevan timbrado y talonario PROPIOS, así que el ancho
-- es propiedad del TALONARIO, no de la caja. `document_sequence` ya está
-- particionada por doctype: es el lugar donde el dato no se pisa entre
-- documentos.
--
-- DEFAULT 7: formato fiscal PY `EEE-PPP-NNNNNNN`
-- (context/29-numeracion-y-exclusividad-de-caja.md §1). Una caja sin ancho
-- declarado emite con el ancho legal, no sin padding.

BEGIN;

-- ============================================================
-- 1. COLUMNA
-- ============================================================
-- smallint: el techo real es 12 (bigint tiene 19 dígitos, pero un correlativo
-- de más de 12 posiciones no existe en ningún talonario). El CHECK corta el 0
-- a propósito: "sin padding" se expresa con padwidth = 1 (todo número tiene al
-- menos un dígito), no con un 0 que después hay que interpretar.
ALTER TABLE document_sequence
  ADD COLUMN IF NOT EXISTS padwidth smallint NOT NULL DEFAULT 7;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
     WHERE conrelid = 'document_sequence'::regclass
       AND conname  = 'document_sequence_padwidth_chk'
  ) THEN
    ALTER TABLE document_sequence
      ADD CONSTRAINT document_sequence_padwidth_chk
      CHECK (padwidth BETWEEN 1 AND 12);
  END IF;
END $$;

COMMENT ON COLUMN document_sequence.padwidth IS
  'Ancho TOTAL del correlativo al imprimirlo (ceros a la izquierda incluidos). '
  '7 = formato fiscal PY (001-001-0002129). Es formato: nunca entra en el '
  'valor de nextnumber ni en las comparaciones de rango.';

-- ============================================================
-- 2. BACKFILL desde el legacy `registerDocsLeadingZeros`
-- ============================================================
-- SEMÁNTICA: el comentario de la mig 26 lo llamaba "cantidad de ceros a la
-- izquierda", pero los TRES call-sites que lo consumían lo pasaban como
-- segundo argumento de `str_pad($invoiceNo, $lead, '0', STR_PAD_LEFT)` —
-- que es el ancho TOTAL del resultado, no la cantidad de ceros
-- (TransactionDetailService.php:97, Reports/FiscalService.php:239,
-- Reports/TransactionsService.php:130). Manda el código, que es lo que el
-- tenant vio impreso durante años: se copia tal cual, sin reinterpretar.
--
-- Solo pisa el default donde el tenant HABÍA declarado un ancho. La mig 26
-- guardó `NULLIF(registerDocsLeadingZeros, 0)`, así que las cajas sin padding
-- no tienen la clave en el JSONB y se quedan con el default legal de 7.
--
-- `register` tiene columnas LOWERCASE (mig 117:85 ya las lee sin comillas) —
-- no confundir con las 18 tablas camelCase entrecomilladas del schema.
-- El regex evita el cast de un JSONB con basura; el LEAST/GREATEST mantiene
-- el valor dentro del CHECK aunque el legacy tuviera un 99.
UPDATE document_sequence s
   SET padwidth   = LEAST(GREATEST((r.data->>'registerDocsLeadingZeros')::int, 1), 12),
       updated_at = now()
  FROM register r
 WHERE s.scopetype = 'register'
   AND s.scopeid   = r.registerid
   AND s.companyid = r.companyid
   AND r.data->>'registerDocsLeadingZeros' ~ '^[0-9]+$'
   AND (r.data->>'registerDocsLeadingZeros')::int >= 1;

COMMIT;
