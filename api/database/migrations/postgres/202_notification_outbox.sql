-- 202_notification_outbox.sql
-- Outbox GENÉRICO de notificaciones salientes — E0 de
-- context/57-entrega-digital-del-kude.md (D4).
--
-- POR QUÉ UNA TABLA Y NO UN MÉTODO. El primer consumidor es la entrega del
-- KuDE por email cuando SIFEN aprueba, pero no es el único previsto: la
-- cotización en PDF (context/56) necesita exactamente el mismo camino, y
-- después vienen comanda lista y recordatorio de cobro. Si esto nace como
-- `EInvoiceService::sendKudeByEmail()`, en tres meses hay tres copias del
-- reintento, tres criterios de idempotencia y ningún lugar donde ver qué se
-- mandó. Mismo molde que el outbox de FE (`einvoice_document`, mig 92), que
-- ya demostró que el patrón aguanta.
--
-- IDEMPOTENCIA: UNIQUE(companyid, entitytype, entityid, channel, recipient).
-- La reconciliación de SIFEN corre cada 10 minutos y puede volver a ver el
-- mismo documento aprobado; el `ON CONFLICT DO NOTHING` de `enqueue()` hace
-- que la segunda pasada no encole un segundo email. El `recipient` ENTRA en
-- la clave a propósito: el reenvío manual a otra dirección ("mandámelo a la
-- del contador", D8) es una fila NUEVA y legítima, no un duplicado.
--
-- ESTADOS: pending → sent | error. No hay 'sending': el claim del drainer no
-- necesita un estado intermedio porque empuja `next_attempt_at` hacia adelante
-- en el mismo UPDATE que incrementa `attempts` (ver NotificationOutbox::drain),
-- así la fila sale del conjunto elegible de forma atómica. Un estado extra
-- habría agregado el problema de las filas 'sending' huérfanas que ya
-- documentó el outbox de FE (el filtro sintético "trabado" del panel).
--
-- `error` es el estado TERMINAL tras agotar los intentos, con `last_error`
-- visible — nunca un fallo en silencio. Y no se usa para "el PDF todavía no
-- está listo": eso vuelve a `pending` con backoff, porque la factura ya se
-- emitió y el envío tiene que insistir (context/57 §Arquitectura).
--
-- `meta` JSONB: contexto que arma QUIEN ENCOLA y que el adapter no tiene por
-- qué recalcular (ej. quién pidió el reenvío manual). El contenido del mensaje
-- NO vive acá: lo construye un builder por `entitytype` en el momento del
-- envío, para que un email encolado ayer no salga con datos congelados de
-- ayer. Ver api/lib/Notifications/.
--
-- `entityid` es UUID sin FK: la tabla es genérica y `entitytype` decide contra
-- qué tabla apunta — una FK obligaría a una columna por tipo de entidad. La
-- integridad la da el builder, que resuelve la entidad al enviar y falla si no
-- existe. `companyid` SÍ tiene FK con ON DELETE CASCADE: si se borra el
-- tenant, no queda cola pendiente hacia sus clientes.
--
-- Todo lowercase sin comillas (convención del repo). IF NOT EXISTS en todo —
-- la migración tiene que poder correr dos veces sin romper.

CREATE TABLE IF NOT EXISTS notification_outbox (
  notificationid   UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid        UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  entitytype       VARCHAR(40)   NOT NULL,
  entityid         UUID          NOT NULL,
  channel          VARCHAR(20)   NOT NULL DEFAULT 'email',
  recipient        VARCHAR(255)  NOT NULL,
  status           VARCHAR(12)   NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending','sent','error')),
  attempts         SMALLINT      NOT NULL DEFAULT 0,
  next_attempt_at  TIMESTAMPTZ   NOT NULL DEFAULT now(),
  last_error       TEXT,
  meta             JSONB         NOT NULL DEFAULT '{}'::jsonb,
  created_at       TIMESTAMPTZ   NOT NULL DEFAULT now(),
  sent_at          TIMESTAMPTZ
);

COMMENT ON TABLE notification_outbox IS
  'Outbox genérico de notificaciones salientes (context/57 D4). Un adapter por channel, un builder por entitytype; el drenaje lo hace el cron (maintenance.php?job=notification-drain).';
COMMENT ON COLUMN notification_outbox.entitytype IS
  'Qué se notifica: "einvoice_document" (entrega del KuDE) es el primer tipo. Decide qué builder arma el mensaje.';
COMMENT ON COLUMN notification_outbox.status IS
  'pending = por enviar o reintentando; sent = entregado al proveedor; error = intentos agotados, con last_error visible.';
COMMENT ON COLUMN notification_outbox.meta IS
  'Contexto del encolado (ej. quién pidió un reenvío manual). NO guarda el cuerpo del mensaje: eso se arma al enviar.';

-- Idempotencia dura. Es la que hace que una reconciliación repetida no mande
-- dos veces el mismo KuDE al mismo destinatario.
CREATE UNIQUE INDEX IF NOT EXISTS uq_notification_outbox_target
  ON notification_outbox(companyid, entitytype, entityid, channel, recipient);

-- Cola del drainer: PARCIAL sobre pending para que el índice no cargue las
-- filas ya enviadas (que son la mayoría en régimen y no se vuelven a mirar
-- nunca desde acá). Mismo criterio que idx_einvoice_document_retry (mig 92).
CREATE INDEX IF NOT EXISTS idx_notification_outbox_due
  ON notification_outbox(next_attempt_at)
  WHERE status = 'pending';

-- Lectura desde el panel: "¿este documento ya se envió y cuándo?" (E4). El
-- índice UNIQUE de arriba cubre el prefijo (companyid, entitytype, entityid),
-- así que la subconsulta del listado de documentos no necesita índice propio.
