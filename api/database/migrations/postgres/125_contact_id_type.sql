-- 125_contact_id_type.sql
-- Cliente extranjero (pasaporte, cédula extranjera, diplomático, identificación
-- tributaria) además de RUC/cédula paraguaya. Feature exclusiva de Paraguay
-- (brief 2026-08-08) — el gate por país vive server-side en ContactService,
-- no acá.
--
-- Shape elegido: `contactIdType SMALLINT` nullable, con los 7 códigos de la
-- Tabla 3 de la SET ("Especificación Técnica para Importación", SET, junio
-- 2021) — el MISMO código que después consume facturación electrónica
-- (SaleToInvoiceMapper), así que no hay una segunda tabla de traducción del
-- lado de Punto entre "cómo lo guarda el form" y "cómo lo necesita el DE".
--   11 RUC, 12 CÉDULA DE IDENTIDAD, 13 PASAPORTE, 14 CÉDULA EXTRANJERO,
--   15 SIN NOMBRE (consumidor final / innominado), 16 DIPLOMÁTICO,
--   17 IDENTIFICACIÓN TRIBUTARIA.
--
-- OJO: esta NO es la codificación que usa la API de Factomate/Efatech para su
-- propio campo `identityDocumentTypeCode` (catálogo `IdentityDocumentType/Get`,
-- ej. 1=CÉDULA, 2=PASAPORTE, 3=CARNET_DIPLOMÁTICO, 5=INNOMINADO — verificado
-- contra la implementación de referencia Automate/efatech). Son DOS tablas
-- distintas; el mapeo explícito entre ambas vive en
-- SaleToInvoiceMapper::mapIdType(), no acá ni en el schema.
--
-- Número del documento: NO se agrega una columna por tipo. RUC sigue en
-- `contactTIN` (columna real, sin cambios). Los otros 6 tipos (12,13,14,15,16,17)
-- comparten `contactCI` (vive en `data` JSONB desde la mig 25) — ese campo pasa
-- de significar "cédula paraguaya" a significar "el número del documento
-- secundario, sea cual sea el tipo declarado en contactIdType". Evita una
-- explosión de columnas (contactPassport, contactForeignId, ...) para un dato
-- que ya era texto libre.
--
-- Backfill: NINGUNO en esta migración (a propósito — "sin backfill
-- destructivo" del brief). Los contactos existentes quedan con
-- contactIdType = NULL. La regla de inferencia para esos casos vive en código
-- (ContactService::inferIdType(), reusada por EInvoiceService::resolveClient()
-- para no duplicar la regla), NO en SQL:
--   tiene contactTIN         → 11 (RUC)
--   si no, tiene contactCI   → 12 (CÉDULA DE IDENTIDAD)
--   si no                    → 15 (SIN NOMBRE / innominado)
-- Un backfill de columna real habría fijado ese valor para siempre y hecho
-- imposible distinguir después "nunca se declaró" de "se declaró explícito";
-- inferir on-read preserva esa distinción y es barato (misma fila ya leída).
--
-- Convenciones de deploy (precedente migs 74/77 que tiraron todos los
-- deploys en prod, y mig 122 que rompió por localizar un CHECK por texto en
-- vez de por columna — ver 52bfde6f): PROHIBIDO el operador `?`/`?|`/`?&` de
-- jsonb (no aplica acá, no hay jsonb nuevo). Todo idempotente. El ADD
-- CONSTRAINT es sobre una columna NUEVA (no hay un CHECK previo sobre
-- `contactIdType` que localizar por conkey) — el guard IF NOT EXISTS por
-- conname alcanza porque el nombre lo elegimos nosotros y no colisiona con
-- nada preexistente.

BEGIN;

ALTER TABLE contact ADD COLUMN IF NOT EXISTS contactIdType SMALLINT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'contact_id_type_allowed'
           AND conrelid = 'contact'::regclass
    ) THEN
        ALTER TABLE contact
            ADD CONSTRAINT contact_id_type_allowed
            CHECK (contactIdType IS NULL OR contactIdType IN (11, 12, 13, 14, 15, 16, 17));
    END IF;
END $$;

COMMIT;
