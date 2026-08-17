-- 141_register_lease.sql
-- F0 de context/29 (exclusividad de caja atada al dispositivo) — P0 fiscal:
-- hoy 4 dispositivos pueden compartir la misma caja y consumir el MISMO
-- bloque de `numbering_lease`, lo que garantiza facturas duplicadas en
-- cuanto ambos sincronizan offline. Esta migración SOLO crea el schema
-- (§5.1/§5.2 del plan) — no cambia comportamiento de `lease.php` ni de
-- `offline-sync.php` (eso es F2/F3, fuera de esta migración). Es seguro
-- desplegar esto con el `lease.php` ACTUAL corriendo encima: agrega tabla
-- y columnas nuevas nullable, no toca ninguna que el endpoint use hoy.
--
-- `register_lease` es la unidad de TENENCIA DE CAJA — separada de
-- `numbering_lease`, que sigue siendo "un número, una fila". El índice
-- `UNIQUE ("registerId") WHERE status = 'active'` ES el mecanismo real del
-- invariante "una caja, un tenedor a la vez": un segundo INSERT concurrente
-- para la misma caja falla en el constraint de BD, no depende de que
-- `lease.php` lea-antes-de-escribir correctamente (mismo patrón que el
-- advisory lock ya usado en la creación de bloques de números).
--
-- Camel-case QUOTED (igual que `numbering_lease`, mig 54): esta tabla vive
-- en el mismo dominio de numeración fiscal y se referencia constantemente
-- junto a esa — no el estilo lowercase sin comillas de módulos como
-- `pos_order*` (mig 79+). Las FK a `company`/`outlet`/`register`/`device`
-- van SIN comillas a propósito: esas tablas base tienen columnas unquoted
-- (pliegan a lowercase), y una FK unquoted resuelve contra esa forma física
-- sin necesidad de escribir el nombre en minúsculas a mano.
--
-- status: 'active' | 'released' | 'expired' | 'forced' — máquina de estados
-- del §4 del plan. F0 no la implementa (eso es F2/F3), solo deja el CHECK
-- para que un valor inválido no pueda entrar aunque el código que la escribe
-- todavía no exista.
--
-- releasedBy es TEXT libre ('device' | 'expiry' | 'admin:{contactId}') a
-- propósito — no es un enum fijo, el plan lo especifica así (§5.2) porque
-- 'admin:{contactId}' lleva un id variable adentro.
--
-- `numbering_lease` gana 3 columnas, todas nullable — NUNCA NOT NULL en esta
-- migración (backfill en la 142, y aun después de esa el plan pide dejar
-- NULL las filas ya consumidas — son historia, no importan al invariante):
--   - "registerLeaseId": bajo qué tenencia se emitió el bloque. FK a la
--     tabla nueva.
--   - "voidedAt"/"voidReason": §6.1 del plan — anulación de números no
--     consumidos al liberar/vencer/forzar una caja. Se agregan como columnas
--     acá (no una tabla `numbering_void` separada) porque la fila de
--     `numbering_lease` ya existe y ya tiene todo lo demás (invoiceNo,
--     registerId) — la opción más simple que el propio plan señala en §6.1.
--
-- Idempotente: IF NOT EXISTS en tabla/columnas/índices, DO block con chequeo
-- de pg_constraint por conname para el CHECK de voidReason (mismo patrón que
-- mig 94, `ALTER TABLE` no soporta `ADD CONSTRAINT IF NOT EXISTS`).

BEGIN;

CREATE TABLE IF NOT EXISTS "register_lease" (
  "registerLeaseId" UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId"        UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  "outletId"         UUID         NOT NULL REFERENCES outlet(outletId),
  "registerId"       UUID         NOT NULL REFERENCES register(registerId),
  "deviceId"         UUID         NOT NULL REFERENCES device(deviceId),
  "status"           TEXT         NOT NULL,
  "takenAt"          TIMESTAMPTZ  NOT NULL DEFAULT now(),
  "expiresAt"        TIMESTAMPTZ  NOT NULL,
  "releasedAt"       TIMESTAMPTZ,
  "releasedBy"       TEXT,
  CHECK ("status" IN ('active', 'released', 'expired', 'forced'))
);

-- LA garantía del invariante — un solo tenedor activo por caja a nivel de BD.
CREATE UNIQUE INDEX IF NOT EXISTS uq_register_lease_active
    ON "register_lease"("registerId")
 WHERE "status" = 'active';

-- Lookups auxiliares: historial de una caja, y "qué caja tiene tomada este
-- dispositivo" (F2/F4/F5 los van a necesitar).
CREATE INDEX IF NOT EXISTS idx_register_lease_register ON "register_lease"("registerId");
CREATE INDEX IF NOT EXISTS idx_register_lease_device    ON "register_lease"("deviceId");

-- `numbering_lease` — ata cada bloque de números a la tenencia bajo la que
-- se emitió, y deja lugar para anular los no consumidos de una tenencia
-- cortada (§6.1). Nullable a propósito, ver cabecera.
ALTER TABLE "numbering_lease"
    ADD COLUMN IF NOT EXISTS "registerLeaseId" UUID REFERENCES "register_lease"("registerLeaseId");

ALTER TABLE "numbering_lease"
    ADD COLUMN IF NOT EXISTS "voidedAt" TIMESTAMPTZ;

ALTER TABLE "numbering_lease"
    ADD COLUMN IF NOT EXISTS "voidReason" TEXT;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'numbering_lease_voidreason_check'
  ) THEN
    ALTER TABLE "numbering_lease"
      ADD CONSTRAINT numbering_lease_voidreason_check
      CHECK ("voidReason" IN ('released', 'expired', 'forced'));
  END IF;
END $$;

-- Índice para "traer los bloques anulados de esta tenencia" (auditoría del
-- hueco, §6.1). Parcial: la gran mayoría de las filas no están anuladas.
CREATE INDEX IF NOT EXISTS idx_numbering_lease_register_lease
    ON "numbering_lease"("registerLeaseId")
 WHERE "registerLeaseId" IS NOT NULL;

COMMIT;
