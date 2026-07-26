-- 86_order_events_backfill.sql
-- Backfill de eventos sintéticos para pos_order_event (F-EVT-0,
-- context/27-delivery-sla-plan.md §C.5) sobre órdenes creadas ANTES de que
-- exista el historial real (mig 85). Sin esto los reportes de tiempos y
-- cuellos de botella arrancan vacíos hasta que se acumule tráfico nuevo —
-- sembramos eventos aproximados a partir de los timestamps que YA tiene
-- pos_order (created_at/sent_at/closed_at).
--
-- Es una APROXIMACIÓN, no la historia real: no sabemos si hubo re-trabajo
-- (ready → in_progress → ready) ni quién marcó cada transición — por eso todo
-- lo sembrado acá usa actor_kind='system', actor_id=NULL, actor_module=NULL,
-- que lo distingue de un evento real (recordEvent() nunca escribe
-- actor_kind='system' con contexto de request; solo cron/backfill). El
-- from_status de la transición final (closed_at) se infiere: 'sent' si la
-- orden llegó a enviarse, 'open' si se cerró/canceló sin pasar por sent — no
-- hay forma de saber retroactivamente si pasó por in_progress/ready.
--
-- Sin filtro de companyid A PROPÓSITO: data-fix one-off cross-tenant, mismo
-- criterio que 84_decor_hard_delete_cleanup.sql — todos los tenants con
-- órdenes viejas necesitan el mismo backfill por igual.
--
-- Idempotente: cada INSERT valida con NOT EXISTS que ese evento puntual
-- (orderid + scope + from_status + to_status) no haya sido sembrado ya, así
-- un re-run no duplica.

-- 1) Creación → status inicial 'open' (toda orden nace 'open', mig 79 default).
INSERT INTO pos_order_event
    (companyid, outletid, orderid, scope, from_status, to_status, actor_kind, created_at)
SELECT o.companyid, o.outletid, o.orderid, 'order', NULL, 'open', 'system', o.created_at
  FROM pos_order o
 WHERE NOT EXISTS (
   SELECT 1 FROM pos_order_event e
    WHERE e.orderid = o.orderid AND e.scope = 'order'
      AND e.to_status = 'open' AND e.from_status IS NULL
 );

-- 2) open → sent, cuando la orden efectivamente se envió.
INSERT INTO pos_order_event
    (companyid, outletid, orderid, scope, from_status, to_status, actor_kind, created_at)
SELECT o.companyid, o.outletid, o.orderid, 'order', 'open', 'sent', 'system', o.sent_at
  FROM pos_order o
 WHERE o.sent_at IS NOT NULL
   AND NOT EXISTS (
     SELECT 1 FROM pos_order_event e
      WHERE e.orderid = o.orderid AND e.scope = 'order'
        AND e.to_status = 'sent' AND e.from_status = 'open'
   );

-- 3) Transición final (closed_at) → status con el que quedó la orden
--    (closed o cancelled). from_status aproximado: 'sent' si llegó a
--    enviarse, 'open' si se cerró/canceló directo desde open.
INSERT INTO pos_order_event
    (companyid, outletid, orderid, scope, from_status, to_status, actor_kind, created_at)
SELECT o.companyid, o.outletid, o.orderid, 'order',
       CASE WHEN o.sent_at IS NOT NULL THEN 'sent' ELSE 'open' END,
       o.status, 'system', o.closed_at
  FROM pos_order o
 WHERE o.closed_at IS NOT NULL
   AND o.status IN ('closed', 'cancelled')
   AND NOT EXISTS (
     SELECT 1 FROM pos_order_event e
      WHERE e.orderid = o.orderid AND e.scope = 'order'
        AND e.to_status = o.status
        AND e.from_status = CASE WHEN o.sent_at IS NOT NULL THEN 'sent' ELSE 'open' END
   );
