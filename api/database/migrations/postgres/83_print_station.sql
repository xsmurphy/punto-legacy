-- 83_print_station.sql
-- Estación de Impresión — P0 backend core (context/26-print-station-plan.md).
--
-- La estación es un ROUTER TONTO: una pantalla device-paired (module='print',
-- extiende DISPLAY_MODULES de api/v1/screens.php) que corre en la PC con las
-- impresoras físicas. No hay tabla `print_station` — la identidad de la
-- estación ES el device pareado; las impresoras físicas cuelgan de él.
--
--   - station_printer: impresora física registrada DESDE la estación (ella
--     descubre el hardware). El nombre se puede editar desde el panel.
--   - print_job: cola durable — fuente de verdad del pool (un comando de
--     cocina no puede perderse porque la estación estaba offline). El WS
--     solo notifica; la estación resincroniza pendientes al reconectar.
--     Transiciones con CAS (UPDATE ... WHERE status='...'), mismo criterio
--     que ORDER_TRANSITIONS/KDS (mig 79) para no imprimir dos veces con dos
--     estaciones.
--   - printer_binding + "via"/"stationPrinterId": opt-in por impresora entre
--     browser-side (local, default — cero breaking change) y pool.
--
-- Columnas camelCase entre comillas dobles — mismo estilo que
-- printer_binding (mig 59), tabla que este archivo extiende.

CREATE TABLE IF NOT EXISTS "station_printer" (
  "id"              UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId"       UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  "outletId"        UUID         NOT NULL,
  "deviceId"        UUID         NOT NULL, -- device pareado module='print' (la estación); sin FK explícita, mismo criterio que device.userId (mig 11)
  "name"            VARCHAR(120) NOT NULL,
  "kind"            VARCHAR(20)  NOT NULL DEFAULT 'thermal'
                       CHECK ("kind" IN ('thermal','inkjet','matrix','generic')),
  "transport"       VARCHAR(20)  NOT NULL
                       CHECK ("transport" IN ('usb','bluetooth','network','native')),
  "transportConfig" JSONB        NOT NULL DEFAULT '{}'::jsonb, -- vendorId/productId/host/port/…
  "status"          SMALLINT     NOT NULL DEFAULT 1, -- 1=active, 0=revoked/eliminada
  "createdAt"       TIMESTAMPTZ  NOT NULL DEFAULT now(),
  "updatedAt"       TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_station_printer_tenant ON "station_printer"("companyId", "outletId");
CREATE INDEX IF NOT EXISTS idx_station_printer_device  ON "station_printer"("deviceId");

CREATE TABLE IF NOT EXISTS "print_job" (
  "id"                UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId"         UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  "outletId"          UUID         NOT NULL,
  "stationPrinterId"  UUID         NOT NULL REFERENCES "station_printer"("id") ON DELETE CASCADE,
  "docType"           VARCHAR(40),                     -- receipt/factura/quote/order/… (auditoría, no valida acá)
  "format"            VARCHAR(10)  NOT NULL DEFAULT 'escpos'
                         CHECK ("format" IN ('escpos','html','raw')),
  "payload"           TEXT         NOT NULL,            -- base64 (escpos/raw) o HTML plano — NUNCA bytea
  "copies"            SMALLINT     NOT NULL DEFAULT 1,
  "openDrawer"        BOOLEAN      NOT NULL DEFAULT FALSE,
  "status"            VARCHAR(16)  NOT NULL DEFAULT 'queued'
                         CHECK ("status" IN ('queued','printing','done','failed','cancelled')),
  "attempts"          SMALLINT     NOT NULL DEFAULT 0,
  "lastError"         TEXT,
  "sourceKind"        VARCHAR(20),                      -- 'transaction'|'order'|… (opcional, reimprimir/auditar)
  "sourceId"          UUID,
  "createdByDeviceId" UUID,                              -- device que originó el job (POS/panel)
  "createdAt"         TIMESTAMPTZ  NOT NULL DEFAULT now(),
  "updatedAt"         TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_print_job_tenant_status ON "print_job"("companyId", "outletId", "status");
CREATE INDEX IF NOT EXISTS idx_print_job_station        ON "print_job"("stationPrinterId");

-- printer_binding: opt-in por impresora. 'local' (default) = imprime como
-- hoy (browser-side); 'pool' = encola hacia una station_printer.
ALTER TABLE "printer_binding" ADD COLUMN IF NOT EXISTS "via" VARCHAR(10) NOT NULL DEFAULT 'local'
  CHECK ("via" IN ('local','pool'));
ALTER TABLE "printer_binding" ADD COLUMN IF NOT EXISTS "stationPrinterId" UUID NULL
  REFERENCES "station_printer"("id");
