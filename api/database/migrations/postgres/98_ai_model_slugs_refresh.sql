-- 98_ai_model_slugs_refresh.sql
-- Actualiza los slugs de modelo del agente IA a los vigentes en OpenRouter.
--
-- Los slugs sembrados por la mig 43 (`deepseek/deepseek-chat`,
-- `google/gemini-flash-1.5`) quedaron viejos: el de visión ya NO existe en el
-- catálogo de OpenRouter, así que esa capability estaba muerta desde que el
-- proveedor lo retiró — sin ningún aviso en el producto, el modelo simplemente
-- deja de responder. La mig 43 usa ON CONFLICT DO NOTHING, así que corregirla
-- en el archivo no arregla ninguna instalación existente: hace falta este UPDATE.
--
-- Solo se tocan las filas que siguen en el slug viejo — si el operador ya eligió
-- otro modelo desde /admin, su elección manda.
BEGIN;

UPDATE ai_model_config
   SET model = 'deepseek/deepseek-v4-flash', updatedat = now()
 WHERE capability = 'chat'
   AND model = 'deepseek/deepseek-chat';

UPDATE ai_model_config
   SET model = 'google/gemini-3.5-flash', updatedat = now()
 WHERE capability = 'vision'
   AND model = 'google/gemini-flash-1.5';

COMMIT;
