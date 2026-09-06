-- Migration 200 — el índice del reporte de anulaciones cubre los DOS granos
-- (`/v1/reports/order-cancellations`).
--
-- La mig 192 creó `idx_pos_order_event_item_cancel` con el predicado parcial
-- `scope = 'item' AND to_status = 'cancelled'`, porque el reporte de entonces
-- solo miraba anulaciones de ÍTEM. Desde 2026-09-06 el mismo reporte también
-- lista las órdenes canceladas ENTERAS (`scope = 'order'`), que era el agujero
-- grande: una comanda de ocho líneas borrada de un click no aparecía en ningún
-- lado.
--
-- Con el alcance nuevo, el índice de la 192 dejó de servir: su predicado
-- (`scope='item'`) NO es implicado por el WHERE del reporte
-- (`to_status='cancelled' AND scope IN ('item','order')`), así que el planner
-- no lo puede usar y vuelve al seq scan sobre la tabla que más crece del
-- módulo — `pos_order_event` guarda de 3 a 5 filas por CADA plato de CADA
-- comanda.
--
-- Se REEMPLAZA en vez de agregar un segundo índice parcial para `scope='order'`:
-- dos índices obligarían al planner a un BitmapOr para una consulta que es una
-- sola, duplicarían la escritura en el camino caliente de la cocina, y —lo que
-- importa más— serían dos objetos que hay que acordarse de tocar juntos la
-- próxima vez que el reporte cambie. Es el mismo criterio con el que el gate y
-- el service se unificaron en vez de duplicarse.
--
-- El predicado nuevo cae en `to_status = 'cancelled'` a secas: es lo que el
-- reporte filtra siempre y sigue siendo una fracción mínima de la tabla (las
-- anulaciones son la excepción, no el flujo). El `scope IN (...)` de la query
-- queda como filtro sobre las filas del índice, que es exactamente lo que se
-- quiere: hoy no descarta nada —el CHECK de la mig 85 solo admite esos dos
-- valores— y el día que aparezca un scope nuevo, no entra al reporte sin que
-- alguien lo decida.
--
-- `created_at DESC` porque es exactamente el ORDER BY del reporte, así el
-- índice cubre el orden además del filtro.
--
-- Sin `CONCURRENTLY`: las migraciones corren en el arranque del contenedor
-- dentro de una transacción, y `CREATE INDEX CONCURRENTLY` no puede correr en
-- una. Sobre una tabla de este tamaño el lock es breve; si algún día deja de
-- serlo, se crea a mano fuera de la ventana de despliegue.
--
-- ORDEN de las dos sentencias: primero se CREA el nuevo y después se dropea el
-- viejo. Al revés, entre un statement y el otro no habría ningún índice — y
-- aunque todo esto ocurra dentro de la misma transacción del migrador, el
-- orden correcto es el que sigue siendo seguro si mañana alguien las corre a
-- mano y sueltas.
CREATE INDEX IF NOT EXISTS idx_pos_order_event_cancel
    ON pos_order_event (companyid, created_at DESC)
    WHERE to_status = 'cancelled';

DROP INDEX IF EXISTS idx_pos_order_event_item_cancel;
