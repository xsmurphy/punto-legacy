-- 227_ai_tts_model_kokoro.sql
-- El modelo TTS default pasa de Gemini a Kokoro — context/80 D2, segunda
-- revisión (owner 2026-09-17, el mismo día del estreno).
--
-- Gemini Flash TTS preview se eligió por calidad de español, pero su latencia
-- lo hace inusable: la generación escala con el largo del texto de forma
-- errática (medido contra el endpoint real: 200 chars ≈ 6s, 300 chars llegó a
-- 45s, 1100 ≈ 144s) y no manda el primer byte hasta terminar, así que ni el
-- streaming ni el troceo del cliente lo salvan. Kokoro genera el mismo texto
-- en 1-2 segundos.
--
-- Patrón de 98_ai_model_slugs_refresh.sql: UPDATE condicionado al slug que
-- sembró la mig 226 — si /admin ya configuró otro modelo para `tts`, esa
-- decisión gana y esta mig no toca nada.
BEGIN;

UPDATE ai_model_config
   SET model = 'hexgrad/kokoro-82m'
 WHERE capability = 'tts'
   AND model = 'google/gemini-3.1-flash-tts-preview';

COMMIT;
