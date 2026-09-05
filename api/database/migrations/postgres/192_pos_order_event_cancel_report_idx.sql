-- Migration 192 — índice para el reporte de anulaciones de ítems
-- (`/v1/reports/order-item-cancellations`).
--
-- Los tres índices de la mig 85 arrancan en `companyid` seguido de una columna
-- que este reporte NO filtra (`orderid`, `outletid`, `stationid`). En modo
-- CONSOLIDADO —el usuario global, sin `VIEW_OUTLET_ID`, que es el caso del
-- dueño mirando todas sus sucursales— el WHERE queda en `companyid` +
-- `created_at` + dos predicados de status, y ninguno de los tres sirve: hay que
-- escanear todos los eventos del tenant en el rango.
--
-- `pos_order_event` crece con CADA transición de CADA ítem de CADA comanda (un
-- plato genera de 3 a 5 filas), mientras que las anulaciones son excepciones.
-- Por eso el índice es PARCIAL: indexa solo las filas que este reporte mira, lo
-- que en un tenant sano es una fracción mínima de la tabla. Ocupa poco y no le
-- agrega escritura al camino caliente de la cocina (una fila que no matchea el
-- predicado no entra al índice).
--
-- `created_at DESC` porque es exactamente el ORDER BY del reporte, así el
-- índice cubre el orden además del filtro.
--
-- Sin `CONCURRENTLY`: las migraciones corren en el arranque del contenedor
-- dentro de una transacción, y `CREATE INDEX CONCURRENTLY` no puede correr en
-- una. Sobre una tabla de este tamaño el lock es breve; si algún día deja de
-- serlo, se crea a mano fuera de la ventana de despliegue.
CREATE INDEX IF NOT EXISTS idx_pos_order_event_item_cancel
    ON pos_order_event (companyid, created_at DESC)
    WHERE scope = 'item' AND to_status = 'cancelled';
