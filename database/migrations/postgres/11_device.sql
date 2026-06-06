-- Migration: tabla device — emparejamiento de dispositivos para /app (device pairing model).
--
-- Contexto (2026-06-06): el JWT de /app representa un EMPAREJAMIENTO de dispositivo, no una sesión
-- (TTL 10 años; los cajeros entran con PIN, otro flow). Ver context/08-convenciones.md §28.
--
-- Necesidad de revocación: si un admin habilita la /app en el teléfono particular de un empleado
-- y el empleado renuncia / es despedido, se lleva la app logueada. El admin del tenant debe poder
-- revocar ESE device específico sin afectar otros dispositivos de la empresa (cajas, otros
-- empleados, móviles autorizados, etc.).
--
-- Cómo funciona:
--   - Cada login admin crea una fila en `device` con metadata (userAgent, IP, ...).
--   - El JWT incluye `did = deviceId` en el payload.
--   - El middleware `jwtAuthenticate()` valida `device.status = 1` (con cache 60s).
--   - El panel del tenant ofrece "Dispositivos" para listar y revocar (UI: pendiente, próximo
--     ciclo cuando se rehaga el panel con React).
--
-- Decisiones:
--   - status SMALLINT (1=active, 0=revoked) — soft delete para auditoría/forense.
--   - deviceName: editable por el admin desde el panel; default '' (sin asignar).
--   - userId apunta al admin que ACTIVÓ el dispositivo — no al cajero que lo usa cada turno.
--   - outletId/registerId nullable: el admin puede activar la caja desde un device antes de
--     enlazarla a una sucursal/caja específica (sucede en setup inicial).
--   - lastSeenAt se updatea throttled (cada N minutos) para evitar UPDATE por cada request.
--   - revokedAt/revokedBy: trazabilidad de quién y cuándo revocó.
--   - Índices: companyId (para listado por tenant) y status (para el cache de revocación).

CREATE TABLE IF NOT EXISTS device (
    deviceId    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    companyId   UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
    userId      UUID         NOT NULL,                                          -- admin que activó (no FK porque contact puede borrarse)
    outletId    UUID,                                                            -- sucursal seleccionada al activar (puede ser NULL)
    registerId  UUID,                                                            -- caja seleccionada al activar (puede ser NULL)
    deviceName  TEXT         NOT NULL DEFAULT '',                                -- editable por el admin
    userAgent   TEXT,                                                            -- raw header del browser
    ipFirst     INET,                                                            -- IP del login
    ipLast      INET,                                                            -- IP más reciente
    lastSeenAt  TIMESTAMPTZ,                                                     -- updateado throttled (no en cada request)
    status      SMALLINT     NOT NULL DEFAULT 1,                                 -- 1=active, 0=revoked
    revokedAt   TIMESTAMPTZ,
    revokedBy   UUID,                                                            -- admin que revocó
    createdAt   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (status IN (0, 1))
);

CREATE INDEX IF NOT EXISTS idx_device_company        ON device(companyId);
CREATE INDEX IF NOT EXISTS idx_device_company_status ON device(companyId, status);
CREATE INDEX IF NOT EXISTS idx_device_user           ON device(userId);
