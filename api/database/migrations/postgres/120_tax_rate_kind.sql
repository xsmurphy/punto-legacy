-- 120_tax_rate_kind.sql
-- F0 del plan de impuestos multi-país (context/38-impuestos-multi-pais.md,
-- sección "Arquitectura propuesta → A. Fuente única de impuestos").
--
-- Objetivo: `tax` pasa a ser la fuente única de impuestos, con tasa numérica
-- (`rate`) y tipo explícito (`kind`) en vez de que cada consumer parsee
-- `name` a mano (o peor, hardcodee '10'/'5'/'0' como hace hoy EInvoice).
--
-- Este mig NO toca los triggers bidireccionales tax<->taxonomy de la mig
-- 23 (sync_taxonomy_to_tax / sync_tax_to_taxonomy) — siguen vivos hasta que
-- el último lector legacy de `taxonomy` migre (F2/F3). Los triggers solo
-- sincronizan `name`/`outletId`/`extra`; `rate`/`kind` son exclusivos de
-- `tax` y no tienen contraparte en `taxonomy`.
--
-- Convenciones de deploy (precedente migs 74/77 que tiraron todos los
-- deploys en prod): nada de operador `?`/`?|`/`?&` de jsonb (el wrapper PDO
-- lo reescribe a placeholder y rompe el boot) — no aplica acá, no hay jsonb.
-- `tax` es DDL legacy lowercase sin comillas. Todo idempotente: re-correr
-- este archivo no debe fallar ni duplicar backfill.

BEGIN;

-- ── 1. Columnas nuevas ──────────────────────────────────────────────────
ALTER TABLE tax ADD COLUMN IF NOT EXISTS rate DECIMAL(5,2);
ALTER TABLE tax ADD COLUMN IF NOT EXISTS kind VARCHAR(10) NOT NULL DEFAULT 'rate';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'tax_kind_allowed'
           AND conrelid = 'tax'::regclass
    ) THEN
        ALTER TABLE tax
            ADD CONSTRAINT tax_kind_allowed
            CHECK (kind IN ('rate', 'exempt'));
    END IF;
END $$;

-- ── 2. Backfill de filas faltantes desde taxonomy ─────────────────────────
-- El trigger de la mig 23 solo sincroniza altas/bajas/updates NUEVOS desde
-- el momento en que se creó — filas de taxonomy que ya existían antes del
-- trigger, o que por lo que sea nunca dispararon el sync, pueden no tener
-- contraparte en `tax`. Este INSERT cierra ese gap. ON CONFLICT DO NOTHING
-- lo hace re-corrible.
--
-- companyId IS NOT NULL: `tax.companyId` es NOT NULL (mig 23), pero existen
-- filas de taxonomy con companyId NULL (defaults globales del catálogo, no
-- atadas a un tenant). Esas NO se migran — quedan fuera de `tax` a propósito,
-- no son impuestos de ningún comercio real.
INSERT INTO tax (taxId, companyId, outletId, name, extra)
SELECT taxonomyId, companyId, outletId, taxonomyName, taxonomyExtra
FROM taxonomy
WHERE taxonomyType = 'tax'
  AND companyId IS NOT NULL
ON CONFLICT (taxId) DO NOTHING;

-- ── 3. Poblar rate/kind en toda fila sin normalizar ───────────────────────
-- Regla: extraer el primer número de `name` (acepta coma o punto decimal,
-- típico de LATAM: "10", "10.5", "10,5"). Si hay número → kind='rate' con
-- ese valor. Si no hay número (ej. "Exentas", "N/A") → kind='exempt',
-- rate=0 (0% es el valor aritmético correcto para un exento, `kind` es lo
-- que lo distingue semánticamente de un rate real de 0%).
-- El guard <= 100 es de deploy, no cosmético: `name` es texto libre del
-- comercio, y un nombre tipo "IVA Ley 2024" parsearía 2024, que desborda
-- DECIMAL(5,2) y ABORTA la migración entera (deploy caído, precedente
-- migs 74/77). Una tasa > 100% no existe fiscalmente: esas filas caen al
-- UPDATE siguiente como exempt/0, que es el default seguro.
UPDATE tax
   SET rate = replace(substring(name from '\d+(?:[.,]\d+)?'), ',', '.')::decimal(5,2),
       kind = 'rate'
 WHERE rate IS NULL
   AND substring(name from '\d+(?:[.,]\d+)?') IS NOT NULL
   AND replace(substring(name from '\d+(?:[.,]\d+)?'), ',', '.')::numeric <= 100;

UPDATE tax
   SET rate = 0,
       kind = 'exempt'
 WHERE rate IS NULL;

COMMIT;
