-- 176_purchase_draft_queue.sql
-- Cola de extracción OCR de facturas de compra.
--
-- Hasta acá el upload era SÍNCRONO: el request subía la imagen, esperaba a la
-- IA (10-30s) y recién ahí creaba el borrador. Con eso no se puede subir un
-- lote — el usuario queda mirando la pantalla y si la cierra, pierde todo.
--
-- El borrador pasa a crearse APENAS se sube la imagen (status 'queued') y la
-- extracción solo lo ENRIQUECE después. Mismo patrón que el sistema de Actuo
-- (`invoices` + `extraction_jobs`), pero acá un borrador ES el job: no hay
-- batching ni múltiples proveedores de IA, así que una tabla aparte solo
-- agregaría un join.
--
-- Estados: queued → processing → pending (listo para revisar) | failed.
-- 'pending' | 'approved' | 'rejected' ya existían y no cambian de significado.
BEGIN;

-- El CHECK viejo solo admitía pending/approved/rejected.
ALTER TABLE purchase_draft DROP CONSTRAINT IF EXISTS purchase_draft_status_chk;
ALTER TABLE purchase_draft ADD CONSTRAINT purchase_draft_status_chk
  CHECK (status IN ('queued', 'processing', 'pending', 'approved', 'rejected', 'failed'));

-- Cuántas veces se intentó extraer. El requeue lo usa para no reintentar
-- infinitamente una factura que el modelo no puede leer (foto ilegible, PDF
-- corrupto): al llegar al tope queda 'failed' y el usuario decide.
ALTER TABLE purchase_draft ADD COLUMN IF NOT EXISTS attempts smallint NOT NULL DEFAULT 0;

-- Cuándo se tomó para procesar. Un 'processing' viejo = el proceso murió a
-- mitad (contenedor reciclado durante el deploy), y el job de mantenimiento lo
-- devuelve a la cola. Sin este timestamp no hay forma de distinguir "está
-- procesando ahora" de "quedó colgado".
ALTER TABLE purchase_draft ADD COLUMN IF NOT EXISTS processing_at timestamptz;

-- La cola se barre por (status, created_at): los pendientes más viejos primero.
CREATE INDEX IF NOT EXISTS idx_purchase_draft_queue
  ON purchase_draft (status, created_at)
  WHERE status IN ('queued', 'processing');

COMMIT;
