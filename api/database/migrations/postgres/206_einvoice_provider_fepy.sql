-- 206_einvoice_provider_fepy.sql
-- Facturación electrónica — segundo proveedor: FE-PY (motor PROPIO).
--
-- ── Por qué ──────────────────────────────────────────────────────────
--
-- Factomate resultó estar probando su API white-label con nosotros
-- (endpoints en 500, sin control de tiempos). El motor propio —FE-PY, el
-- mismo `facturacionelectronicapy-xmlgen` envuelto en una API multi-tenant—
-- YA emitió facturas reales en producción, así que el emisor pasa a ser
-- nuestro. Factomate NO se retira: queda intacto como plan B y el cutover
-- es POR TENANT, vía `einvoice_account.provider` ('factomate' | 'fepy').
-- La columna es multi-provider desde la mig 92, no cambia el diseño.
--
-- ── provider_tenant_ref ──────────────────────────────────────────────
--
-- `factomate_tenant_id` es INTEGER (mig 100: la API de Factomate usa ids
-- enteros para Tenant). El tenant de FE-PY es un **UUID v7** generado por
-- su aplicación (`api/src/db/schema.ts`, `id: uuid().primaryKey()`), y
-- todas sus rutas lo validan con `z.string().uuid()` — un uuid NO entra en
-- una columna INTEGER, así que reusar el campo viejo no era una opción.
--
-- Columna NUEVA y GENÉRICA, no `fepy_tenant_id`: el próximo proveedor
-- vuelve a tener su propia forma de identificar al emisor y no tiene
-- sentido sumar una columna por cada uno. TEXT y no UUID por lo mismo —
-- guarda la referencia OPACA que devolvió el proveedor, sea cual sea su
-- forma. `factomate_tenant_id` queda como está: es de Factomate y sigue
-- siendo la llave de sus filas ya provisionadas.
--
-- Nada de backfill: no hay una sola cuenta en FE-PY todavía. NULL
-- significa exactamente "este emisor no está dado de alta en un proveedor
-- de referencia opaca", que es la verdad para todas las filas de hoy.
--
-- ── einvoice_document.provider_number con 'fepy' ─────────────────────
--
-- No cambia de tipo ni de nombre, cambia lo que GUARDA según el proveedor,
-- y por eso se documenta acá: en Factomate es el `Id` raíz del bulk (la
-- llave de `getBulk/{id}`); en FE-PY la reconsulta se hace POR CDC
-- (`POST /v1/tenants/:id/de/:cdc/consulta` — no existe un GET por txnId),
-- así que ahí guarda el CDC. El rol de la columna —"la llave con la que
-- este proveedor reconcilia este documento"— es el mismo en los dos casos.
--
-- Todo lowercase sin comillas (convención del repo). IF NOT EXISTS en
-- todo: la migración tiene que poder correr dos veces sin romper.

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS provider_tenant_ref TEXT;

COMMENT ON COLUMN einvoice_account.provider_tenant_ref IS
  'Referencia OPACA del emisor en el proveedor cuando su id no es un entero. Para provider=''fepy'' es el UUID v7 del tenant que devolvió POST /v1/tenants, y es lo que va en el path de TODAS sus rutas (/v1/tenants/{ref}/...). NO reemplaza a factomate_tenant_id (INTEGER, mig 100), que sigue siendo la llave de las cuentas de Factomate.';

COMMENT ON COLUMN einvoice_document.provider_number IS
  'Llave con la que el PROVEEDOR reconcilia este documento. En Factomate: el Id raíz del bulk que devolvió POST /Bulk (getBulk/{id}). En FE-PY: el CDC, porque su reconsulta es por CDC (POST /v1/tenants/{ref}/de/{cdc}/consulta) y no existe lectura por el txnId interno de ellos.';
