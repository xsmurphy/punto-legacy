-- 126_giftcard_code_unique_ci.sql
--
-- Auditoría del ticket T2 del tester (ver context/10-roadmap.md, nota T2
-- 2026-08-08): el canje (api/v1/giftcards.php validate/consume) matchea el
-- código con
-- `UPPER(code) = UPPER(?)` (case-insensitive, así el cajero puede tipearlo
-- en minúscula), pero la UNIQUE("companyId", code) de la mig 44 es
-- case-SENSITIVE. Dos filas que solo difieren en case ("GC-ABC12345" y
-- "gc-abc12345") pasan la constraint existente sin problema pero son LA
-- MISMA gift card para el canje, que resuelve con LIMIT 1 y se queda con
-- una sola de las dos, arbitrariamente — plata fantasma en la otra.
--
-- `giftcard.code` tiene dos escrituras en el repo, y ambas quedan
-- normalizadas a mayúsculas + pre-check case-insensitive en este mismo
-- commit: `SaleService::issueGiftCard()` (INSERT, emisión desde la venta) y
-- `GiftcardsService::update()` (UPDATE, edición desde el panel). Hacia
-- adelante ninguna de las dos va a producir un duplicado case-variant nuevo.
-- Este índice es la garantía real ante carrera concurrente (dos devices
-- emitiendo con el mismo código a la vez, o un INSERT y un UPDATE
-- solapados) — los pre-checks applicativos son solo UX (mensaje legible en
-- vez del 23505 crudo).
--
-- Convención de deploy (precedente migs 74/77/122 — operador jsonb `?` y
-- asumir estado que no se cumple tiraron deploys enteros): PROHIBIDO
-- `?`/`?|`/`?&` de jsonb (no aplica acá, no hay jsonb) y PROHIBIDO asumir
-- que la tabla está limpia. `CREATE UNIQUE INDEX` sobre una tabla CON
-- duplicados preexistentes (companyId, UPPER(code)) aborta con
-- unique_violation — así que va envuelto en DO+EXCEPTION: si hay
-- duplicados, se reporta con RAISE NOTICE (visible en el log del deploy) y
-- el índice se SALTEA sin abortar el boot del container. No se renombra ni
-- se fusiona ninguna fila: mutar `code` en una gift card que ya podría estar
-- impresa/entregada a un cliente sería un cambio de datos más peligroso que
-- el problema que resuelve. El backstop server-side (SaleService, arriba)
-- queda protegiendo la unicidad hacia adelante aunque el índice no se haya
-- podido crear en este run — y como es CREATE INDEX (no CONCURRENTLY) con
-- IF NOT EXISTS, correr esta migración de nuevo después de limpiar los
-- duplicados a mano SÍ crea el índice, sin acción manual adicional.
--
-- Idempotente y re-corrible: IF NOT EXISTS + el bloque de detección solo
-- hace RAISE NOTICE (sin side effects), así que un re-run es un no-op si el
-- índice ya existe, o vuelve a intentar crearlo si todavía no.

BEGIN;

DO $$
DECLARE
  dup_groups int;
BEGIN
  SELECT COUNT(*) INTO dup_groups FROM (
    SELECT 1
      FROM giftcard
     GROUP BY "companyId", UPPER(code)
    HAVING COUNT(*) > 1
  ) d;

  IF dup_groups > 0 THEN
    RAISE NOTICE 'giftcard: % grupo(s) de código duplicado preexistente(s) por (companyId, UPPER(code)) — uq_giftcard_company_code_ci NO se pudo crear. Resolver a mano (revisar cuál fila es la gift card real y renombrar/anular la otra) y re-correr esta migración.', dup_groups;
  END IF;

  BEGIN
    EXECUTE 'CREATE UNIQUE INDEX IF NOT EXISTS uq_giftcard_company_code_ci ON giftcard ("companyId", UPPER(code))';
  EXCEPTION
    WHEN unique_violation THEN
      RAISE NOTICE 'giftcard: CREATE UNIQUE INDEX uq_giftcard_company_code_ci abortado por duplicados existentes — ver aviso anterior. El boot NO se interrumpe; SaleService::issueGiftCard queda como única defensa hasta resolver los duplicados.';
  END;
END $$;

COMMIT;
