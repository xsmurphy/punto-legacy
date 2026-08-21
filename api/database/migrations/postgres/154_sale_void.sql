-- 154_sale_void.sql
-- Anulación de ventas (F1+F2 de context/40-anulacion-y-nota-credito.md).
--
-- Decisión de diseño YA CERRADA en context/40 (no relitigar): la anulación
-- NO pisa `transactiontype` a 7 (eso convertiría la factura anulada en "otra
-- cosa" y le haría perder el número/timbrado que el owner pidió conservar
-- para auditoría — "el número de factura no se libera, queda usado"). En
-- cambio se agrega estado sobre la venta original, mismo patrón que
-- `numbering_lease.voidedat`/`voidreason` (mig 141) ya usa en este mismo
-- schema para "anulado sin borrar".
--
-- `transaction` es tabla legacy (§44 de context/08-convenciones-criticas.md):
-- columnas lowercase sin quotes en el catálogo de PG. Nombres nuevos acá
-- siguen esa regla, no camelCase.
--
--   voidedat   — cuándo se anuló. NULL = vigente. Es el flag de exclusión
--                que los reportes/rollups deben sumar a su filtro existente
--                (`transactiontype IN (0,3)`), ver SaleFilters en el mismo
--                commit que agrega este archivo.
--   voidreason — motivo textual, obligatorio a nivel aplicación (no DB) —
--                ver SaleVoidService::void().
--   voidedby   — quién anuló (userId del operador). Sin FK: `contact` es
--                tabla legacy y el resto de columnas "quién" de `transaction`
--                (userid, responsibleid) tampoco declaran FK real — se
--                mantiene el mismo criterio, no se introduce uno nuevo acá.
--
-- Ventana de 48h (D4, context/40): NO se modela con una columna de
-- expiración — se calcula en runtime contra `transactiondate` en
-- SaleVoidService::canVoid()/void(). Una columna `voidexpiresat` derivada
-- quedaría desincronizada de la constante VOID_WINDOW_HOURS del código si
-- algún día cambia por tenant; mejor una sola fuente de verdad.
--
-- settingReturnAllowIngredientReversal (bool, default false, D2 de
-- context/40): NO requiere DDL — `company.config` es JSONB schemaless y
-- cualquier clave nueva se enruta sola vía ncmUpdate/_routeToJsonb (mismo
-- patrón que settingSellSoldOut, ver SettingsService.php). Se lee con
-- `config->>'settingReturnAllowIngredientReversal' = 'yes'` (default false
-- cuando la clave no existe, mismo idioma 'yes'/'no' que el resto de los
-- settingX booleanos legacy).
--
-- wasteReason "Devolución de cliente": tampoco requiere seed en migración —
-- vive en `taxonomy` (taxonomyType='wasteReason'), scoped por tenant, y se
-- resuelve lazy get-or-create en SaleVoidService (mismo criterio que
-- WasteReasonService::ensureSeed(), que ya siembra sus 5 defaults de forma
-- lazy en el primer list() del tenant, no en una migración).

BEGIN;

ALTER TABLE transaction ADD COLUMN IF NOT EXISTS voidedat   timestamptz NULL;
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS voidreason text        NULL;
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS voidedby   uuid        NULL;

-- Parcial: solo indexa las filas anuladas (minoría absoluta esperada). Sirve
-- para listados de auditoría ("ventas anuladas de esta sucursal") sin cargar
-- el índice con las filas vigentes, que ya cubre transactiondate/type.
CREATE INDEX IF NOT EXISTS idx_transaction_voidedat
    ON transaction (companyid, voidedat)
    WHERE voidedat IS NOT NULL;

COMMIT;
