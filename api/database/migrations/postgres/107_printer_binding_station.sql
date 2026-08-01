-- Integra la Estación de Impresión como un transport más del binding
-- existente (decisión del owner 2026-08-01): el ruteo (docTypes + categorías
-- + template + copies, getBindingsForSale/printSale) queda único; transport
-- "station" solo cambia el último paso — en vez de mandar bytes al hardware
-- local, el front encola el payload YA RENDERIZADO a print_job vía
-- PrintPoolService::enqueue (ver context/26-print-station-plan.md).
--
-- "transport" ya es VARCHAR sin CHECK (61_printer_binding_multi_transport.sql
-- agregó bluetooth/network del mismo modo) — el valor nuevo 'station' no
-- requiere alter de constraint, solo la referencia a qué station_printer usar.
BEGIN;

ALTER TABLE "printer_binding" ADD COLUMN IF NOT EXISTS "stationPrinterId" UUID NULL;
COMMENT ON COLUMN "printer_binding"."stationPrinterId" IS
  'station_printer.id cuando transport=''station''. Validado en PrinterBindingService contra companyId+outletId de la caja del binding (mismo aislamiento que PrintPoolService::enqueue).';

COMMIT;
