BEGIN;

-- Captura del número de comprobante y timbrado que vienen IMPRESOS en el
-- documento del PROVEEDOR (context/29-numeracion-y-exclusividad-de-caja.md
-- §5): compra/gasto, nota de crédito de compra (transactionType 14) y pago a
-- proveedor (transactionType 5, kind='purchase_payment'). Decisión del owner
-- 2026-08-17, textual: "un documento ajeno no lleva correlativo nuestro —
-- capturar el número del proveedor". NO se genera correlativo interno para
-- estos documentos.
--
-- Compra (types 1/4) YA tenía invoiceNo/invoicePrefix/invoiceDate para el
-- número+fecha del proveedor (reusados tal cual acá — son pervasivos en
-- Reports/exports, no se tocan). Lo único que faltaba para compra era el
-- TIMBRADO con su vencimiento: el campo "Timbrado / Auth" del form (authNo)
-- se guardaba concatenado dentro de invoicePrefix como "authNo;prefix" (hack
-- legacy, PurchasesService.php:451-453) SIN vencimiento y sin volver a
-- separarse en el read path (PurchasesService::get/list nunca lo splitea de
-- vuelta — dead field al editar). supplierauthno/supplierauthnoduedate
-- reemplazan ese hack: PurchasesService pasa a escribir el timbrado ahí,
-- invoicePrefix vuelve a guardar SOLO el prefijo. Los rows viejos con el
-- shape "authNo;prefix" siguen leyéndose vía el split legacy (no se tocan).
--
-- NC de compra y pago a proveedor NO tenían ningún campo: invoiceNo/
-- invoicePrefix ya tienen otro significado ahí (pago a proveedor los usa
-- para SU PROPIO recibo — CreditPaymentService.php:568-586) o no se insertan
-- en absoluto (NC compra). Usan las columnas nuevas completas
-- (supplierdocprefix/supplierdocno/supplierdocdate).
--
-- Todas NULLABLE, sin backfill: el sistema está en producción, nada
-- retroactivo (owner 2026-08-17) — filas ya registradas quedan en NULL.
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS supplierauthno VARCHAR(20);
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS supplierauthnoduedate DATE;
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS supplierdocprefix VARCHAR(10);
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS supplierdocno BIGINT;
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS supplierdocdate DATE;

COMMIT;
