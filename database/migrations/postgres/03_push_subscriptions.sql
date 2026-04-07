-- =============================================================
-- Tabla: push_subscription
-- Almacena suscripciones Web Push (VAPID) por contacto/browser.
-- Reemplaza OneSignal.
-- =============================================================

CREATE TABLE IF NOT EXISTS push_subscription (
    id         BIGSERIAL PRIMARY KEY,
    contactId  UUID          NOT NULL REFERENCES contact(contactId) ON DELETE CASCADE,
    companyId  UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
    endpoint   TEXT          NOT NULL UNIQUE,
    p256dh     TEXT          NOT NULL,
    auth       TEXT          NOT NULL,
    active     BOOLEAN       NOT NULL DEFAULT TRUE,
    createdAt  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updatedAt  TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_push_sub_contactId ON push_subscription(contactId);
CREATE INDEX IF NOT EXISTS idx_push_sub_companyId ON push_subscription(companyId);
CREATE INDEX IF NOT EXISTS idx_push_sub_active    ON push_subscription(active);
