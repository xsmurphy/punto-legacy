-- Migration 204 — el guard de numeración: registrar, de forma legible POR
-- CÓDIGO, que el CDC que devolvió el proveedor NO describe el documento que
-- la venta imprimió (context/28 §Numeración del emisor; `api/lib/EInvoice/Cdc.php`).
--
-- QUÉ PROBLEMA RESUELVE. Desde el merge de la numeración del emisor, la
-- factura electrónica se manda con el número congelado de la caja
-- (`transaction.invoiceNo`), el MISMO que salió impreso en el ticket que el
-- cliente se llevó. Si el proveedor ignorara ese número y numerara por su
-- cuenta, el CDC devuelto identificaría OTRO documento — y todo lo que el
-- comercio imprima después (CDC, QR de ekuatia, link de consulta) apuntaría a
-- un comprobante distinto del que tiene el cliente en la mano. `Cdc::
-- assertMatchesSale()` descompone el CDC y detecta exactamente eso; esta
-- columna es donde queda anotado.
--
-- POR QUÉ UNA COLUMNA Y NO `status='error'` — ES LA PARTE IMPORTANTE.
-- Cuando el guard salta, el documento YA ESTÁ EMITIDO: Factomate lo mandó,
-- SIFEN lo tiene, hay CDC. Marcarlo `error` lo dejaría elegible para
-- `retry()`, que solo reencola desde `status='error'`, y reintentar un
-- documento ya emitido lo EMITIRÍA DOS VECES — Factomate no reemite, cada
-- `/Bulk` es un documento nuevo (context/28 §F7, la misma trampa que
-- documentó la mig 201 para el rechazo de SIFEN). O sea: el estado correcto
-- sigue siendo `issued`, y lo que falta es un lugar aparte donde decir "este
-- documento existe pero no es el que imprimimos".
--
-- POR QUÉ NO REUSAR `error_message`. Esa columna acompaña a `status='error'`
-- y la UI la lee como "esto falló, se puede reintentar". Meter acá una prosa
-- distinta obligaría a los consumidores a adivinar cuál de los dos
-- significados tiene mirando el `status` — y, peor, el camino de IMPRESIÓN
-- necesita un predicado SQL, no una heurística sobre un texto libre.
--
-- TEXT y no BOOLEAN: NULL = coincide (el caso normal, y el default de todas
-- las filas que ya existen), no-NULL = la descripción de la discrepancia. Un
-- booleano obligaría a guardar el detalle en otro lado o a perderlo, y "el
-- número del CDC es 907 y la venta declara 833" es justo lo que el comercio
-- necesita leer para entender qué pasó.
--
-- LO QUE ESTA COLUMNA GOBIERNA. El camino de impresión y el portal del
-- comprador filtran por `numbering_mismatch IS NULL`: ante la duda NO se
-- imprime un CDC ni un QR, se cae al hueco honesto ("CDC disponible en el
-- portal"). Un comprobante fiscal sin CDC es un problema; un comprobante
-- fiscal con el CDC de OTRO documento es un problema peor y además
-- indetectable para quien lo recibe.

BEGIN;

ALTER TABLE einvoice_document
  ADD COLUMN IF NOT EXISTS numbering_mismatch TEXT;

COMMENT ON COLUMN einvoice_document.numbering_mismatch IS
  'NULL = el CDC devuelto describe la misma venta que se imprimió. No-NULL = descripción de la discrepancia detectada por Cdc::assertMatchesSale(); el documento sigue issued (ya existe en SIFEN, reintentarlo lo duplicaría) pero su CDC/QR NO se imprimen ni se publican en el portal.';

-- Índice parcial: las filas con discrepancia son la excepción, y el panel las
-- lista para revisión. Parcial y no total porque en régimen normal la columna
-- es NULL en el 100% de las filas — un índice completo sería peso muerto en
-- cada INSERT del outbox.
CREATE INDEX IF NOT EXISTS ix_einvoice_document_numbering_mismatch
    ON einvoice_document (companyid, created_at DESC)
 WHERE numbering_mismatch IS NOT NULL;

COMMIT;
