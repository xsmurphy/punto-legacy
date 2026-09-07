-- Migration 201 — "corregir y emitir de nuevo": un documento RECHAZADO por
-- SIFEN se reemplaza por uno NUEVO, y el rechazado queda como registro
-- (context/28-facturacion-electronica-plan.md §F7, N2).
--
-- POR QUÉ NO ALCANZA CON LO QUE HAY. `retry()` reencola la MISMA fila y solo
-- desde `status='error'` (nunca llegó a Factomate). Un documento rechazado por
-- SIFEN tiene `status='issued'` —el envío salió bien, lo que falló es el
-- veredicto fiscal— así que no hay nada que reintentar: Factomate no reemite,
-- cada `/Bulk` es un documento nuevo y el número lo asigna la SET
-- (`number => -1`). Reemitir es INSERTAR otra fila para la misma venta.
--
-- Y ahí choca con la idempotencia dura del outbox: `uq_einvoice_document_tx`
-- (mig 92) es UNIQUE(companyid, transactionid, doctype) y existe para que un
-- reintento de la cola offline con el mismo transactionId no duplique el
-- documento (`ON CONFLICT DO NOTHING` en `enqueueForSale`). Esa garantía NO se
-- afloja: lo que cambia es su alcance. El índice pasa a ser PARCIAL sobre los
-- documentos ACTIVOS (`superseded_by IS NULL`), de modo que sigue habiendo UNO
-- solo por (venta, tipo de documento) y las reemisiones anteriores conviven
-- como historia. La venta encolada dos veces sigue chocando contra el índice
-- exactamente igual que antes.
--
-- `superseded_by` apunta al documento que lo REEMPLAZA (no al revés): así el
-- predicado del índice se lee sobre la fila vieja, que es la que tiene que
-- salir del conjunto activo. El rechazado NO se borra ni se marca `cancelled`
-- —SIFEN también lo tiene y anularlo es otra cosa (`cancel()` va contra un
-- documento que SIFEN aceptó)—: queda `issued` + rechazado, con un puntero al
-- que lo sucede.
--
-- DEFERRABLE INITIALLY DEFERRED, y no es cosmético: la reemisión tiene que
-- sacar la fila vieja del índice ANTES de insertar la nueva (si no, las dos
-- serían activas por un instante y la UNIQUE aborta), y para eso escribe el
-- puntero hacia un id que todavía no existe. Con la FK diferida, la
-- verificación ocurre al COMMIT de la transacción, cuando la fila nueva ya
-- está. La alternativa —no poner FK— dejaba un puntero sin integridad
-- referencial; la otra —insertar primero— es justamente la que el índice
-- prohíbe.
--
-- OJO al leer código: `ON CONFLICT` sobre un índice PARCIAL exige repetir el
-- predicado en el conflict_target (`... ON CONFLICT (companyid, transactionid,
-- doctype) WHERE superseded_by IS NULL DO NOTHING`). Sin eso Postgres no
-- infiere el índice y tira "no unique or exclusion constraint matching the ON
-- CONFLICT specification" — `EInvoiceService::enqueueForSale` ya va con el
-- predicado.
--
-- ORDEN de las sentencias (mismo criterio que la mig 200): primero se CREA el
-- índice nuevo y después se dropea el viejo, para que no exista un intervalo
-- sin ninguna unicidad si algún día alguien las corre sueltas. Por eso el
-- índice nuevo lleva nombre propio (`..._active`) en vez de reusar el de la
-- mig 92: dos índices no pueden compartir nombre, y el nombre además dice qué
-- garantiza ahora.
ALTER TABLE einvoice_document
    ADD COLUMN IF NOT EXISTS superseded_by UUID
        REFERENCES einvoice_document(einvoicedocid)
        DEFERRABLE INITIALLY DEFERRED;

COMMENT ON COLUMN einvoice_document.superseded_by IS
    'Documento que REEMPLAZA a este tras un rechazo de SIFEN (context/28 §F7 N2). NULL = documento activo; no-NULL = queda solo como registro histórico.';

CREATE UNIQUE INDEX IF NOT EXISTS uq_einvoice_document_tx_active
    ON einvoice_document(companyid, transactionid, doctype)
    WHERE superseded_by IS NULL;

DROP INDEX IF EXISTS uq_einvoice_document_tx;
