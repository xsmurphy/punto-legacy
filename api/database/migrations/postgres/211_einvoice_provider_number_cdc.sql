-- 211_einvoice_provider_number_cdc.sql
--
-- `provider_number` no entra el CDC, y por eso una factura APROBADA quedó
-- registrada como error.
--
-- QUÉ PASÓ, con un documento fiscal real de por medio. La factura
-- 001-002-0000615 de Balloon Party se emitió, SIFEN la aprobó (0260, CDC
-- 01035951931001002000061512026090911014423631) y el UPDATE que la registraba
-- de nuestro lado explotó:
--
--   SQLSTATE[22001]: value too long for type character varying(40)
--
-- `provider_number` recibe el `bulkId` del proveedor, y en FE-PY el bulkId ES
-- el CDC (ver el COMMENT de la mig 206): 44 caracteres exactos, siempre. La
-- columna nació con 40 cuando el bulkId era un identificador corto de otro
-- proveedor, y nadie la ensanchó al cambiar de motor.
--
-- POR QUÉ ES GRAVE Y NO UN DETALLE DE ESQUEMA. El documento quedó EMITIDO del
-- lado del proveedor y `error` del nuestro. Un reintento habría intentado
-- emitir dos veces el mismo comprobante fiscal — lo salvó que FE-PY tiene un
-- índice único parcial por (tenant, tipo, establecimiento, punto, numero) que
-- devuelve 409, pero depender de la defensa del otro lado no es una garantía
-- nuestra.
--
-- 64 y no 44: el ancho exacto vuelve a fallar el día que un proveedor devuelva
-- su propio identificador en vez del CDC, que es exactamente cómo nació este
-- bug. `cdc` sí queda en 44 porque ahí el largo es parte del dato (SIFEN lo
-- define), no una elección nuestra.

BEGIN;

ALTER TABLE einvoice_document
  ALTER COLUMN provider_number TYPE varchar(64);

COMMENT ON COLUMN einvoice_document.provider_number IS
  'Identificador del documento del lado del PROVEEDOR. En FE-PY es el CDC (44 chars). '
  'Ancho 64 con margen: 44 exactos volvería a romper con un proveedor que devuelva otro formato (mig 211).';

COMMIT;
