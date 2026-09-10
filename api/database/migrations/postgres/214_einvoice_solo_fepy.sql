-- 214_einvoice_solo_fepy.sql
-- Facturación electrónica — queda UN solo motor: FE-PY.
--
-- ── Por qué ──────────────────────────────────────────────────────────
--
-- El proveedor externo con el que arrancó el módulo (mig 95, ampliado en la
-- mig 100 para el white-label) quedó fuera por decisión del owner: FE-PY
-- —el mismo `facturacionelectronicapy-xmlgen` envuelto en una API
-- multi-tenant— es de Punto, ya emitió documentos reales en producción, y
-- sostener dos motores significaba sostener dos caminos de emisión, dos de
-- provisioning y dos de numeración para siempre.
--
-- Esta migración borra el DATO. Las migraciones 95 y 100 NO se tocan: son el
-- registro de lo que efectivamente corrió en producción, y reescribirlas no
-- cambiaría ninguna base — solo falsearía la historia.
--
-- ── provider: se normaliza, no se dropea ─────────────────────────────
--
-- La columna es multi-proveedor desde la mig 92 y sigue teniendo sentido: es
-- lo único que dice con qué motor se emitió una cuenta, y `EInvoiceProviderFactory`
-- LANZA ante un valor desconocido en vez de caer a un default (una company
-- marcada con un motor que este deploy no conoce no puede terminar emitiendo
-- por otro, con otro timbrado y otra numeración, sin que nadie se entere).
--
-- Justamente por ese fail-closed hay que normalizar: si una fila quedara con
-- el proveedor viejo, su próxima emisión abortaría con "motor desconocido".
--
-- OJO, esto NO da de alta a nadie en FE-PY. Una cuenta que estaba provisionada
-- solo en el motor anterior tiene `provider_tenant_ref` NULL, así que después
-- de esto figura como NO provisionada y el panel le muestra el formulario de
-- alta. Es la verdad: su emisor no existe en el motor vigente y hay que darlo
-- de alta. El fallo pasa de "motor desconocido" (que no le dice nada a nadie)
-- a "falta dar de alta el emisor" (que es accionable), y en los dos casos es
-- fail-closed: ninguna cuenta a medias emite.
--
-- ── Las dos columnas del motor viejo ─────────────────────────────────
--
-- `factomate_tenant_id` (INTEGER, mig 100) y `factomate_user_id` (VARCHAR,
-- mig 100) eran las llaves del emisor en su API. Ya no las lee nadie: el
-- último lector, el flag `provisioned` de `EInvoiceService::getAccount()`,
-- pasó a mirar solo `provider_tenant_ref`. Se dropean con CASCADE porque
-- podrían tener índices colgando.
--
-- Todo lowercase sin comillas (convención del repo). IF EXISTS en todo: la
-- migración tiene que poder correr dos veces sin romper.
--
-- BEGIN/COMMIT explícito (convención de `migrate.php`): el UPDATE de datos y
-- los DROP van juntos o no van. Una corrida a medias dejaría cuentas ya
-- normalizadas con las columnas viejas todavía puestas — recuperable, pero es
-- estado fiscal y no hay razón para tolerarlo.

BEGIN;

UPDATE einvoice_account SET provider = 'fepy' WHERE provider IS DISTINCT FROM 'fepy';

ALTER TABLE einvoice_account
  ALTER COLUMN provider SET DEFAULT 'fepy';

ALTER TABLE einvoice_account
  DROP COLUMN IF EXISTS factomate_tenant_id CASCADE;

ALTER TABLE einvoice_account
  DROP COLUMN IF EXISTS factomate_user_id CASCADE;

COMMENT ON COLUMN einvoice_account.provider IS
  'Motor de facturación electrónica de esta cuenta. Hoy el único valor válido es ''fepy'' (FE-PY, el motor de Punto); la columna se conserva multi-motor porque es lo único que dice con qué se emitió, y EInvoiceProviderFactory lanza ante un valor que no conozca en vez de caer a un default.';

COMMENT ON COLUMN einvoice_account.provider_tenant_ref IS
  'Referencia OPACA del emisor en el motor. Para FE-PY es el UUID v7 del tenant que devolvió POST /v1/tenants, y es lo que va en el path de TODAS sus rutas (/v1/tenants/{ref}/...). NULL significa que el emisor todavía no está dado de alta: es el flag que el panel usa para mostrar el formulario de alta.';

COMMENT ON COLUMN einvoice_document.provider_number IS
  'Llave con la que el MOTOR reconcilia este documento. En FE-PY es el CDC, porque su reconsulta es por CDC (POST /v1/tenants/{ref}/de/{cdc}/consulta) y no existe lectura por su txnId interno.';

COMMIT;
