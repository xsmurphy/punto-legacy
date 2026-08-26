-- Migration 174: binding QR de Bancard → tenant (aislamiento cross-tenant)
--
-- /v1/bancard.php refresh/cancel tomaban el `id` del QR del body y lo mandaban
-- a Bancard con el token GLOBAL de plataforma, sin ninguna verificación de
-- pertenencia: un tenant podía refrescar/cancelar el cobro de OTRO comercio con
-- solo conocer (o adivinar) el id (auditoría 2026-08-26, path de dinero).
--
-- Bancard no expone un binding id→comercio consultable, así que se persiste
-- localmente al crear el QR: `createQR` guarda todos los ids candidatos que
-- devuelve la respuesta (el front prueba varias claves — ver
-- frontend/lib/payments/psp-qr.ts ID_KEYS) apuntando al companyId emisor.
-- refresh/cancel validan contra esta tabla y rechazan si el id pertenece a otro
-- tenant. Fail-open a propósito para un id DESCONOCIDO (creado antes de esta
-- mig, o clave de id que no capturamos): preserva el comportamiento legacy y
-- garantiza que un error de captura NO rompa un flujo legítimo — solo agrega
-- protección, nunca la quita.
--
-- Idempotente.

CREATE TABLE IF NOT EXISTS bancard_qr (
    qrId       TEXT         PRIMARY KEY,
    companyId  UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
    outletId   UUID,
    createdAt  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_bancard_qr_company ON bancard_qr(companyId);
