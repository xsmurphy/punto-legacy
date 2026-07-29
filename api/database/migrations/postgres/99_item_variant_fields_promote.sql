-- 99_item_variant_fields_promote.sql
-- Rescata los campos de variantes que se escribieron en el JSONB `data` en vez
-- de su columna real.
--
-- `_getTableSchema()` (api/includes/functions.php) no listaba hasVariants /
-- variantParentId / variantAttributes entre las columnas de `item`, así que
-- _routeToJsonb las mandaba al JSONB: el PUT devolvía 200 pero la columna nunca
-- cambiaba. El fix del wrapper corrige de acá en más; esta mig repara lo ya
-- escrito y limpia las claves sueltas del JSONB para que no queden dos fuentes
-- de verdad para el mismo dato.
--
-- NO usar el operador `?` de jsonb en queries que pasen por PDO: colisiona con
-- el placeholder y aborta el boot. Por eso jsonb_exists().
BEGIN;

UPDATE item
   SET hasvariants = (data->>'hasVariants') IN ('1', 't', 'true'),
       data        = data - 'hasVariants'
 WHERE jsonb_exists(data, 'hasVariants');

-- Solo se promueven UUID bien formados; cualquier otra cosa no podría ser un
-- parent válido y se deja intacta para poder auditarla.
UPDATE item
   SET variantparentid = (data->>'variantParentId')::uuid,
       data            = data - 'variantParentId'
 WHERE jsonb_exists(data, 'variantParentId')
   AND data->>'variantParentId' ~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';

-- applyVariantRules hace json_encode antes de persistir, así que en el JSONB el
-- valor puede haber quedado como STRING con JSON adentro. Se contemplan ambos.
UPDATE item
   SET variantattributes = CASE
         WHEN jsonb_typeof(data->'variantAttributes') = 'string'
           THEN (data->>'variantAttributes')::jsonb
         ELSE data->'variantAttributes'
       END,
       data = data - 'variantAttributes'
 WHERE jsonb_exists(data, 'variantAttributes')
   AND jsonb_typeof(data->'variantAttributes') IN ('string', 'object');

COMMIT;
