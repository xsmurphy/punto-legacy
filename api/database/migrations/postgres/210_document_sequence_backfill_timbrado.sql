-- 210_document_sequence_backfill_timbrado.sql
--
-- Arregla el backfill de la mig 209, que no asignó NINGÚN timbrado.
--
-- QUÉ PASÓ. La 209 promovió `invoiceauth` + `prefix` a identidad de la serie y
-- traía un UPDATE para asignarle a cada fila la serie vigente de su caja, pero
-- filtraba con `AND s.invoiceauth = '' AND s.prefix = ''` — pedía que las DOS
-- estuvieran vacías. Y toda fila fiscal ya tenía `prefix` cargado, porque
-- `RegisterAdminService::seedSequence()` lo escribe desde que existe. O sea que
-- la condición nunca se cumplía y el UPDATE no tocó una sola fila: quedaron
-- todas con el timbrado vacío.
--
-- POR QUÉ ES URGENTE Y NO COSMÉTICO. El código nuevo resuelve la serie leyendo
-- la caja (`DocumentSeries::forRegister`), así que busca ('18260177','001-002')
-- mientras en la base está ('','001-002'). No matchea:
--
--   - `peek()` no encuentra la fila y devuelve 1, así que el bootstrap del POS
--     le informa a la caja que el próximo comprobante es el número 1.
--   - `allocate()`/`advanceTo()` no matchean el ON CONFLICT y CREAN una fila
--     nueva, dejando la que tenía el contador bueno como huérfana.
--
-- Verificado en producción antes de escribir esto: 19 filas de `factura`, 0 con
-- timbrado, 8 cuya caja sí tiene uno cargado.
--
-- POR QUÉ NO HAY COLISIÓN. La clave única es
-- (companyid, doctype, scopetype, scopeid, invoiceauth, prefix) y acá solo se
-- mueve `invoiceauth` de una fila que ya es única por su `scopeid` + `prefix`.
-- No existe otra fila del mismo scope con otro timbrado — la 209 acaba de
-- crear el espacio para que existan, y todavía no hay ninguna.
--
-- Las cajas SIN timbrado cargado conservan `invoiceauth = ''` a propósito: no
-- tienen serie fiscal todavía, y ese vacío es el mismo que usan los ~12
-- doctypes no fiscales.

BEGIN;

UPDATE document_sequence s
   SET invoiceauth = TRIM(r.data ->> 'registerInvoiceAuth'),
       updated_at  = now()
  FROM register r
 WHERE r.registerid = s.scopeid
   AND r.companyid  = s.companyid
   AND s.scopetype  = 'register'
   AND s.doctype    = 'factura'
   AND s.invoiceauth = ''
   AND COALESCE(NULLIF(TRIM(r.data ->> 'registerInvoiceAuth'), ''), '') <> '';

COMMIT;
