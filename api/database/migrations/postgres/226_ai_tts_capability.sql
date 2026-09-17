-- 226_ai_tts_capability.sql
-- Capability `tts` del catálogo de modelos IA — context/80-voz-del-agente.md.
--
-- Solo DATOS: `ai_model_config.capability` es TEXT PRIMARY KEY sin CHECK (mig
-- 43) y /admin ya permite crear capabilities nuevas, así que la voz del agente
-- no necesita ningún cambio de schema.
--
-- Por qué la fila igual se seedea y no se deja para que alguien la cargue a
-- mano en /admin: `api/v1/ai/debit.php` resuelve el precio con
-- `SELECT ... FROM ai_model_config WHERE capability = ? AND enabled` y corta
-- con 422 si no encuentra nada. Como el débito es best-effort (no rompe la
-- respuesta que ya se entregó), sin esta fila el TTS FUNCIONA y NO COBRA: el
-- comercio escucha gratis y el único rastro es un console.error del BFF. Un
-- gasto que no se cobra y no se ve es exactamente lo que no se descubre hasta
-- la factura del proveedor.
--
-- El slug lleva `-preview` porque así lo publica OpenRouter hoy (verificado
-- 2026-09-17). Cuando salga de preview, refrescarlo con una mig nueva (patrón
-- de 98_ai_model_slugs_refresh.sql) o desde /admin — NO editando este seed: el
-- ON CONFLICT DO NOTHING hace que este INSERT no vuelva a correr.
--
-- `creditsperktoken` arranca en 1, igual que chat y vision. El TTS cobra por
-- caracteres mapeados a tokens equivalentes (chars/4, ver
-- frontend/lib/ai/tts-usage.ts), así que el número es comparable al del chat y
-- se ajusta desde /admin cuando haya consumo real medido.
BEGIN;

INSERT INTO ai_model_config (capability, model, creditsperktoken) VALUES
  ('tts', 'google/gemini-3.1-flash-tts-preview', 1)
ON CONFLICT (capability) DO NOTHING;

COMMIT;
