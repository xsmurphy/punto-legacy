-- 219_outlet_request.sql
-- Alta de sucursal AUTOSERVICIO con paywall: modelo solicitud + aprobación.
--
-- ── Qué problema resuelve ───────────────────────────────────────────────
-- Cada sucursal se factura al PRECIO DEL PLAN del tenant por mes (decisión
-- del owner 2026-09-11: plan de 295.000 con 2 sucursales = 590.000/mes). O
-- sea que crear una sucursal es un HECHO COMERCIAL, no una operación de
-- configuración: alguien de Punto tiene que aprobarla antes de que exista.
--
-- El comercio pide desde el switcher de sucursales del panel; /admin aprueba
-- o rechaza. Al aprobar, la sucursal se crea por el servicio real
-- (`Outlets\OutletsService::create()`), nunca por INSERT directo — así la
-- CADENA de alta (outlet → depósito default + caja) se respeta igual que en
-- cualquier otro camino (ver `api/tests/outlet_chain_invariant_test.php`).
--
-- ── Por qué una tabla propia y no `billing_request` ─────────────────────
-- `billing_request` es la cola de CAMBIO DE PLAN y su payload es un
-- `plan_code`. Una solicitud de sucursal lleva nombre y dirección pedidos, y
-- al aprobarse produce una fila en `outlet`. Meterla ahí obligaría a que las
-- dos formas convivan en columnas nullables y a que el resolver ramifique por
-- tipo. Tabla propia, misma FORMA (status/resolvedAt/resolvedBy) para que la
-- cola de /admin se lea igual.
--
-- ── El invariante ───────────────────────────────────────────────────────
-- UNA sola solicitud `pending` por empresa, garantizado por índice único
-- PARCIAL (no por un check en PHP: dos pestañas del panel apretando al mismo
-- tiempo pasan cualquier validación de aplicación). Las resueltas no cuentan,
-- así que un comercio puede pedir la 2a, 3a… sucursal sin límite mientras no
-- tenga una pendiente.
--
-- ── Casing de identificadores ───────────────────────────────────────────
-- Sin comillas, igual que `billing_request` (mig 28): PG los baja a lowercase
-- y el wire queda predecible. NO entra en la lista de tablas camelCase
-- entrecomilladas del schema legacy.
--
-- `requestedByName` es un SNAPSHOT del nombre de quien pidió, no una FK
-- resuelta al leer: /admin tiene que poder mostrar "quién pidió" aunque ese
-- usuario después se dé de baja. `requestedBy` queda igual para trazabilidad,
-- sin FK a `contact` para que la baja del usuario no arrastre la solicitud.

BEGIN;

CREATE TABLE IF NOT EXISTS outlet_request (
  id              UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId       UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  requestedBy     UUID,
  requestedByName VARCHAR(255),
  name            VARCHAR(255) NOT NULL,
  address         TEXT,
  status          VARCHAR(12)  NOT NULL DEFAULT 'pending',
  reason          TEXT,
  createdAt       TIMESTAMPTZ  NOT NULL DEFAULT now(),
  resolvedAt      TIMESTAMPTZ,
  resolvedBy      VARCHAR(120),
  outletId        UUID
);

-- Una sola pendiente por empresa. Parcial: las resueltas no bloquean.
CREATE UNIQUE INDEX IF NOT EXISTS uq_outlet_request_pending_company
  ON outlet_request(companyId)
  WHERE status = 'pending';

-- La cola de /admin ordena por fecha dentro de un estado.
CREATE INDEX IF NOT EXISTS idx_outlet_request_status_date
  ON outlet_request(status, createdAt);

CREATE INDEX IF NOT EXISTS idx_outlet_request_company
  ON outlet_request(companyId);

COMMIT;
