-- 129_stock_docs_numbering.sql
-- F3 de context/37 — correlativo para los documentos internos de stock.
--
-- Producción, merma, transferencia y conteo son documentos: se imprimen, se
-- auditan y se referencian ("la transferencia 45"), pero hasta ahora solo se
-- identificaban por UUID. Todos van con scope OUTLET (D2, cerrada): no son
-- documentos fiscales, así que no dependen del punto de expedición.
--
-- La secuencia vive en `document_sequence` (mig 117) y la asigna
-- `DocumentNumber::allocate()` dentro de la transacción del documento — sin
-- huecos, sin MAX() y sin advisory lock.
--
-- Cuidado con el DDL mixto: `production_order` y `waste_event` son lowercase
-- sin comillas (mig 76); `inventory_count` y `stock_transfer` son camelCase
-- quoted (migs 46/47).

BEGIN;

-- ============================================================
-- 1. Columnas
-- ============================================================
-- Nullable a propósito: las filas históricas no tienen número y forzar uno
-- inventado sería peor que no tenerlo. El backfill de abajo se lo asigna por
-- orden cronológico, que es el orden en que realmente ocurrieron.

ALTER TABLE production_order ADD COLUMN IF NOT EXISTS docnumber bigint;
ALTER TABLE waste_event      ADD COLUMN IF NOT EXISTS docnumber bigint;
ALTER TABLE inventory_count  ADD COLUMN IF NOT EXISTS "docNumber" bigint;
ALTER TABLE stock_transfer   ADD COLUMN IF NOT EXISTS "docNumber" bigint;

-- ============================================================
-- 2. Backfill — numeración cronológica por sucursal
-- ============================================================
-- `row_number()` sobre la fecha de creación: el documento más viejo de cada
-- sucursal es el 1. Empates por PK para que el resultado sea determinístico
-- (dos filas con el mismo timestamp no pueden quedar ambas en el mismo número).

UPDATE production_order po
   SET docnumber = n.rn
  FROM (
    SELECT orderid,
           row_number() OVER (PARTITION BY companyid, outletid ORDER BY created_at, orderid) AS rn
      FROM production_order
     WHERE outletid IS NOT NULL
  ) n
 WHERE n.orderid = po.orderid
   AND po.docnumber IS NULL;

-- waste_event.outletid es nullable: una merma sin sucursal no puede numerarse
-- por sucursal. Se deja sin número (los emisores actuales siempre mandan
-- outlet, así que esto solo alcanza filas históricas anómalas).
UPDATE waste_event we
   SET docnumber = n.rn
  FROM (
    SELECT wasteid,
           row_number() OVER (PARTITION BY companyid, outletid ORDER BY created_at, wasteid) AS rn
      FROM waste_event
     WHERE outletid IS NOT NULL
  ) n
 WHERE n.wasteid = we.wasteid
   AND we.docnumber IS NULL;

UPDATE inventory_count ic
   SET "docNumber" = n.rn
  FROM (
    SELECT "inventoryCountId",
           row_number() OVER (PARTITION BY "companyId", "outletId" ORDER BY "startedAt", "inventoryCountId") AS rn
      FROM inventory_count
  ) n
 WHERE n."inventoryCountId" = ic."inventoryCountId"
   AND ic."docNumber" IS NULL;

-- La transferencia se numera por la sucursal que la EMITE (origen): es la que
-- genera el documento. La de destino lo recibe, no lo emite.
UPDATE stock_transfer st
   SET "docNumber" = n.rn
  FROM (
    SELECT "stockTransferId",
           row_number() OVER (PARTITION BY "companyId", "fromOutletId" ORDER BY "createdAt", "stockTransferId") AS rn
      FROM stock_transfer
  ) n
 WHERE n."stockTransferId" = st."stockTransferId"
   AND st."docNumber" IS NULL;

-- ============================================================
-- 3. Semilla de las secuencias
-- ============================================================
-- `nextnumber` = lo más alto ya asignado + 1, por sucursal. Se siembran TODAS
-- las sucursales, incluso las que nunca emitieron el documento (arrancan en 1):
-- así el asignador nunca depende de que la fila exista.

INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT o.companyid, d.doctype, 'outlet', o.outletid, 1
FROM outlet o
CROSS JOIN (VALUES ('produccion'), ('merma'), ('transferencia'), ('conteo')) AS d(doctype)
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

UPDATE document_sequence s
   SET nextnumber = GREATEST(s.nextnumber, x.maxno + 1), updated_at = now()
  FROM (
    SELECT companyid, outletid, COALESCE(MAX(docnumber), 0) AS maxno
      FROM production_order WHERE outletid IS NOT NULL GROUP BY companyid, outletid
  ) x
 WHERE s.companyid = x.companyid AND s.scopeid = x.outletid
   AND s.scopetype = 'outlet' AND s.doctype = 'produccion';

UPDATE document_sequence s
   SET nextnumber = GREATEST(s.nextnumber, x.maxno + 1), updated_at = now()
  FROM (
    SELECT companyid, outletid, COALESCE(MAX(docnumber), 0) AS maxno
      FROM waste_event WHERE outletid IS NOT NULL GROUP BY companyid, outletid
  ) x
 WHERE s.companyid = x.companyid AND s.scopeid = x.outletid
   AND s.scopetype = 'outlet' AND s.doctype = 'merma';

UPDATE document_sequence s
   SET nextnumber = GREATEST(s.nextnumber, x.maxno + 1), updated_at = now()
  FROM (
    SELECT "companyId" AS companyid, "outletId" AS outletid, COALESCE(MAX("docNumber"), 0) AS maxno
      FROM inventory_count GROUP BY "companyId", "outletId"
  ) x
 WHERE s.companyid = x.companyid AND s.scopeid = x.outletid
   AND s.scopetype = 'outlet' AND s.doctype = 'conteo';

UPDATE document_sequence s
   SET nextnumber = GREATEST(s.nextnumber, x.maxno + 1), updated_at = now()
  FROM (
    SELECT "companyId" AS companyid, "fromOutletId" AS outletid, COALESCE(MAX("docNumber"), 0) AS maxno
      FROM stock_transfer GROUP BY "companyId", "fromOutletId"
  ) x
 WHERE s.companyid = x.companyid AND s.scopeid = x.outletid
   AND s.scopetype = 'outlet' AND s.doctype = 'transferencia';

-- ============================================================
-- 3b. Re-seed de `orden` — el emisor recién ahora la consume
-- ============================================================
-- La mig 117 sembró la secuencia de 'orden', pero hasta este deploy
-- `OrderCoreService::create` seguía usando `MAX(ordernumber)+1`: cada pedido
-- creado en el medio dejó a `nextnumber` atrás. Arrancar a asignar desde un
-- contador atrasado repetiría números de pedido ya usados.
--
-- Es el mismo re-seed que la mig 127 hizo para factura y cotización; 'orden' no
-- entraba ahí porque su emisor todavía no estaba migrado. Solo SUBE.

INSERT INTO document_sequence (companyid, doctype, scopetype, scopeid, nextnumber)
SELECT o.companyid, 'orden', 'outlet', o.outletid, 1
FROM outlet o
ON CONFLICT (companyid, doctype, scopetype, scopeid) DO NOTHING;

UPDATE document_sequence s
   SET nextnumber = GREATEST(s.nextnumber, x.maxno + 1), updated_at = now()
  FROM (
    SELECT companyid, outletid, COALESCE(MAX(ordernumber), 0) AS maxno
      FROM pos_order WHERE outletid IS NOT NULL GROUP BY companyid, outletid
  ) x
 WHERE s.companyid = x.companyid AND s.scopeid = x.outletid
   AND s.scopetype = 'outlet' AND s.doctype = 'orden';

-- ============================================================
-- 4. Unicidad
-- ============================================================
-- Índices parciales (WHERE ... IS NOT NULL) para no chocar con las filas
-- históricas sin número. Son la red de contención del asignador: si algún día
-- otro camino escribe estas tablas sin pasar por DocumentNumber, el duplicado
-- se corta acá y no seis meses después en una auditoría.

CREATE UNIQUE INDEX IF NOT EXISTS uq_production_order_docnumber
  ON production_order (companyid, outletid, docnumber) WHERE docnumber IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_waste_event_docnumber
  ON waste_event (companyid, outletid, docnumber) WHERE docnumber IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_inventory_count_docnumber
  ON inventory_count ("companyId", "outletId", "docNumber") WHERE "docNumber" IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_transfer_docnumber
  ON stock_transfer ("companyId", "fromOutletId", "docNumber") WHERE "docNumber" IS NOT NULL;

COMMIT;
