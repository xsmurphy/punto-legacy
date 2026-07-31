BEGIN;

-- ============================================================
-- Centro de notificaciones del panel (context/31-centro-de-notificaciones.md
-- — plan cerrado 2026-07-30).
--
-- El feed unificado (`/v1/notifications/feed.php`, realm panel) NO persiste
-- eventos propios: une en vivo filas de `notify` (eventos) + vencimientos
-- derivados del forecast financiero (cheques/cuotas/compras). Lo único que
-- se persiste es el ESTADO por usuario de cada item, identificado por una
-- `alertKey` determinística:
--   - `notify:<notifyId>`               para eventos de la tabla `notify`
--   - `due:<tipo>:<id>:<duedate>`        para vencimientos derivados
--
-- Leído ≠ descartado: leído (readat) solo atenúa el item en el feed;
-- descartado (dismissedat) lo saca del feed. Ambos son operaciones
-- idempotentes vía UPSERT (ON CONFLICT (companyid, userid, alertkey)).
--
-- Distinto del watermark legacy del POS (contact.contactLastNotificationSeen
-- + NotificationService) — ese sigue intacto, es realm pos-app y no se toca.
--
-- Mismas convenciones que el resto del módulo Finanzas (72_finance.sql,
-- 102_finance_checks_loans.sql): columnas físicas lowercase sin comillas
-- (ncmInsert/ncmUpdate/ncmExecute arman SQL con las keys del array PHP tal
-- cual), companyid explícito, PK uuid gen_random_uuid().
-- ============================================================

CREATE TABLE IF NOT EXISTS notification_state (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid    uuid NOT NULL,
  userid       uuid NOT NULL,
  alertkey     varchar(160) NOT NULL,
  readat       timestamptz,
  dismissedat  timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now()
);

-- Idempotencia de read/dismiss: un usuario tiene a lo sumo una fila de
-- estado por alertKey. El endpoint hace UPSERT sobre esta constraint.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_notification_state
  ON notification_state (companyid, userid, alertkey);

-- Lookup del feed: todas las filas de estado de un usuario en un tenant.
CREATE INDEX IF NOT EXISTS idx_notification_state_user
  ON notification_state (companyid, userid);

COMMIT;
