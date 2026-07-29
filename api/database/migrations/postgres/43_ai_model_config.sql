-- 43_ai_model_config.sql
-- Config de modelos del agente IA por capability (chat/vision/etc).
-- Editable desde /admin (UI = slice AI-4). El route handler la lee via /v1/ai/config.
-- creditsPerKToken incluido para que AI-2 (billing) solo tenga que leer.
BEGIN;

CREATE TABLE IF NOT EXISTS ai_model_config (
  capability        TEXT PRIMARY KEY,
  model             TEXT NOT NULL,
  enabled           BOOLEAN NOT NULL DEFAULT true,
  creditsperktoken  NUMERIC(10,4) NOT NULL DEFAULT 1,
  updatedat         TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Slugs vigentes al 2026-07-29. OpenRouter retira slugs sin aviso: cuando eso
-- pase, la capability deja de responder en silencio. Refrescarlos con una mig
-- nueva (ver 98_ai_model_slugs_refresh.sql), NO editando este seed — el
-- ON CONFLICT DO NOTHING hace que este INSERT no vuelva a correr nunca.
INSERT INTO ai_model_config (capability, model, creditsperktoken) VALUES
  ('chat',   'deepseek/deepseek-v4-flash', 1),
  ('vision', 'google/gemini-3.5-flash',    1)
ON CONFLICT (capability) DO NOTHING;

COMMIT;
