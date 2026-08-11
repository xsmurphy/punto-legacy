-- 132_promote_stranded_jsonb.sql
-- Rescata los valores que el wrapper escribió dentro del JSONB cuando en
-- realidad pertenecían a una COLUMNA real.
--
-- Causa: `ncmInsert`/`ncmUpdate` decidían "esto es columna" contra
-- `_getTableSchema()`, un mapa mantenido a mano con 22 tablas de las 137 que
-- tiene la base. Toda columna ausente de ese mapa se trataba como campo
-- desconocido y terminaba dentro del JSONB. Los SELECT leen la columna, así que
-- el dato quedaba invisible y la request respondía 200 — el mismo mecanismo que
-- ya había escondido `hasVariants` y `pinhash` (migs 99 y 100).
--
-- El mapa fue eliminado: ahora el schema se lee del catálogo de PG
-- (`Punto\App\Database\Schema`), así que esto no puede volver a pasar. Esta
-- migración limpia lo que quedó del período en que sí pasaba.
--
-- Criterio, uniforme para todos los casos:
--   1. Se promueve SOLO si la columna está NULL y el JSONB trae un valor. La
--      columna es lo que leen los SELECT: si ya tiene dato, es la verdad
--      efectiva y no se pisa con una copia vieja.
--   2. La clave se borra del JSONB SIEMPRE, haya habido promoción o no. Dejar
--      las dos es dejar dos fuentes de verdad divergiendo en silencio.
--
-- `jsonb_exists()` en vez del operador `?`: el `?` se reescribe como
-- placeholder PDO y aborta el boot (precedente migs 74/77).

BEGIN;

-- ============================================================
-- transaction.meta → invoiceNo / invoicePrefix / transactionDueDate
-- ============================================================
-- 28 filas (todas transactionType=1, compras) con las claves en `meta`; 10
-- traen número de factura del proveedor, 10 el prefijo y 22 el vencimiento.
-- La columna está NULL en las 28, así que la promoción no pisa nada.

UPDATE transaction
   SET invoiceno = NULLIF(meta->>'invoiceNo', '')::bigint
 WHERE jsonb_exists(meta, 'invoiceNo')
   AND invoiceno IS NULL
   AND NULLIF(meta->>'invoiceNo', '') ~ '^[0-9]+$';

UPDATE transaction
   SET invoiceprefix = NULLIF(meta->>'invoicePrefix', '')
 WHERE jsonb_exists(meta, 'invoicePrefix')
   AND invoiceprefix IS NULL
   AND NULLIF(meta->>'invoicePrefix', '') IS NOT NULL;

-- El cast va guardado por regex: una fecha basura en el JSONB abortaría la
-- migración entera y con ella el deploy.
UPDATE transaction
   SET transactionduedate = (meta->>'transactionDueDate')::timestamptz
 WHERE jsonb_exists(meta, 'transactionDueDate')
   AND transactionduedate IS NULL
   AND meta->>'transactionDueDate' ~ '^\d{4}-\d{2}-\d{2}';

-- `transactionComplete` NO se promueve: la columna ya tiene valor en las 28
-- filas. Difiere del JSONB en todas, pero es la columna la que leen los SELECT
-- y la que gobierna el estado de la transacción — el JSONB es la copia
-- fantasma. Solo se borra la clave.

UPDATE transaction
   SET meta = meta - 'invoiceNo' - 'invoicePrefix' - 'transactionDueDate' - 'transactionComplete'
 WHERE jsonb_exists(meta, 'invoiceNo')
    OR jsonb_exists(meta, 'invoicePrefix')
    OR jsonb_exists(meta, 'transactionDueDate')
    OR jsonb_exists(meta, 'transactionComplete');

-- ============================================================
-- fin_check.data → transactionid
-- ============================================================
-- 2 cheques con el vínculo a su transacción varado en el JSONB y la columna en
-- NULL: el cheque quedaba sin poder rastrearse hasta la venta que lo originó.

UPDATE fin_check
   SET transactionid = (data->>'transactionid')::uuid
 WHERE jsonb_exists(data, 'transactionid')
   AND transactionid IS NULL
   AND data->>'transactionid' ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

UPDATE fin_check
   SET data = data - 'transactionid'
 WHERE jsonb_exists(data, 'transactionid');

-- ============================================================
-- item.data → variantParentId
-- ============================================================
-- 1 fila con la clave presente pero sin valor: no hay nada que promover, solo
-- la clave suelta que ensucia el JSONB.

UPDATE item
   SET data = data - 'variantParentId'
 WHERE jsonb_exists(data, 'variantParentId')
   AND data->>'variantParentId' IS NULL;

UPDATE item
   SET variantparentid = (data->>'variantParentId')::uuid
 WHERE jsonb_exists(data, 'variantParentId')
   AND variantparentid IS NULL
   AND data->>'variantParentId' ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

UPDATE item
   SET data = data - 'variantParentId'
 WHERE jsonb_exists(data, 'variantParentId');

COMMIT;
