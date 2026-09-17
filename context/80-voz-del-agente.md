# 80 — Voz del agente (TTS por OpenRouter)

> Estado: **implementado** (2026-09-17, commit `71ceb677`, mig 226). D1-D6
> CERRADAS. La D2 giró DOS veces el mismo día — leerla abajo: el default
> terminó siendo **Kokoro con voz `ef_dora`** (mig 227), porque Gemini
> resultó inusable por latencia, no por calidad. Los modelos se cambian por
> `/admin` o con una mig nueva de UPDATE (patrón mig 98/227) — NUNCA
> editando la seed 226, que ya corrió en prod.

## 1. El pedido (owner)

El bot lee las respuestas con la voz nativa del navegador
(`speechSynthesis`) y es muy mala. Reemplazarla por un modelo TTS **de
OpenRouter**, debitando los tokens del **crédito IA del tenant** — igual que
el chat.

## 2. Qué existe (verificado 2026-09-17)

- **OpenRouter tiene TTS desde 2026**: endpoint dedicado
  `/api/v1/audio/speech`, compatible con la Audio Speech API de OpenAI —
  texto entra, bytes de audio salen (MP3/PCM según modelo). Catálogo:
  Gemini Flash TTS (70+ idiomas), Kokoro 82M (barato, 54 voces), Grok Voice,
  Qwen-Audio TTS, Aura-2. NO es un chat model con truco: es otra ruta.
- **La voz actual vive en UN archivo**:
  `frontend/components/agent/message-actions.tsx` (botón de leer en voz alta,
  `window.speechSynthesis`). Un solo punto de reemplazo.
- **El billing está resuelto**: `frontend/lib/ai/billing-gate.ts` es el
  wrapper compartido de los BFF que llaman a OpenRouter —
  `assertAiCredits` FAIL-CLOSED antes de gastar, `debitAiUsage` best-effort
  después, reconciliable contra `ai_credit_ledger`. El TTS es un consumidor
  más del mismo wrapper, no un mecanismo nuevo.
- **Los modelos y su precio en créditos se administran en `/admin`**
  (`creditsperktoken` por modelo). Un modelo TTS entra al mismo catálogo.

## 3. Diseño propuesto

Un BFF nuevo `app/api/agent/tts/route.ts` (realm panel):

1. Recibe `{ text, messageId }`.
2. `assertAiCredits` (mismo gate del chat).
3. POST a OpenRouter `/api/v1/audio/speech` con el modelo TTS configurado.
4. Devuelve el audio (MP3) al cliente; `debitAiUsage` con el consumo.
5. `message-actions.tsx` reproduce el blob y lo **retiene en memoria**:
   re-escuchar el mismo mensaje no vuelve a pedir ni a debitar. Sin caché
   server-side en v1 — el mensaje de un chat rara vez se escucha dos veces
   desde dos devices.

## 4. Decisiones

- **D1 — CERRADA (owner)**: se cobra del crédito IA del tenant.
- **D2 — Modelo default: Kokoro 82M, voz `ef_dora`** (mig 227 — tercera
  vuelta de esta decisión, las tres el 2026-09-17). Historia completa
  porque explica el criterio: (1) Kokoro se propuso por precio y se
  descartó por español flojo; (2) Gemini Flash TTS entró por calidad… y
  duró UN día en producción: su latencia es errática y escala con el largo
  (medido contra el endpoint real: 200 chars ≈ 6s, 300 llegó a 45s, 1100 ≈
  144s, TTFB al final de la generación) — ni streaming ni el troceo del
  cliente la salvan; (3) el owner decidió Kokoro: genera lo mismo en 1-2s,
  y una voz que llega tarde no es una voz. **La latencia es requisito de
  admisión de cualquier modelo TTS futuro, antes que la calidad.** Gemini
  queda seleccionable desde `/admin` si algún día se arregla. Regla
  aprendida el mismo día: en OpenRouter TODOS los proveedores TTS exigen
  `voice` explícita, y cada familia tiene su contrato (Gemini solo emite
  PCM → el BFF lo envuelve en WAV; Kokoro/Aura-2 dan mp3) — el mapa vive
  en `ttsRequestParams` del route. Sin la fila de capability, `debit.php`
  corta 422 y —como el débito es best-effort— la voz saldría GRATIS en
  silencio: la seed 226 no es opcional.
- **D3 — Unidad de cobro.** El TTS cobra por CARACTERES de entrada, no por
  tokens de salida. `debitAiUsage` recibe tokens; se mapea caracteres→"tokens
  equivalentes" (chars/4, el estándar) para no bifurcar el ledger. El
  `reason` del débito distingue `tts` de `chat` — el desglose en /admin
  tiene que poder decir cuánto se va en voz.
- **D4 — Fallback.** Sin créditos o con OpenRouter caído: ¿cae a la voz
  nativa (mala pero gratis) o el botón se deshabilita con motivo? Propuesta:
  **cae a la nativa con un toast** — el usuario pidió escuchar, escuchar
  algo malo le gana a un botón muerto. El gate fail-closed sigue: lo que
  nunca pasa es gastar sin poder cobrar.
- **D5 — Alcance de superficies.** v1 solo el agente del PANEL. El asistente
  de la caja (`context/59`) tiene BFF propio con Bearer del device — si se
  quiere voz ahí, es un segundo endpoint con ESE realm, nunca compartir el
  del panel (mandato token-only del POS). Queda para después del OK.
- **D6 — Streaming: NO; troceo: SÍ** (revisada 2026-09-17, el mismo día que
  salió). La latencia molestó en la práctica el primer día: un mensaje largo
  tardaba ~48s en empezar a sonar. Medido contra el endpoint real: la
  generación de Gemini escala con el largo y MAL (200 chars ≈ 6s, 1100 ≈
  144s) y el primer byte llega al FINAL (TTFB 142s de 144s) — streamear la
  respuesta no ayuda en nada. La solución es del CLIENTE
  (`lib/ai/tts-chunk.ts`): el texto se pide por oraciones agrupadas en
  pedazos (el primero corto, ~180 chars — es la espera del usuario), en
  paralelo con tope de 3, y se reproducen en secuencia. Cada pedazo pasa por
  el mismo gate y débito; el costo total no cambia.

## 5. Arquitecturas rechazadas

- **Llamar a OpenRouter desde el browser.** La key es secreta y el gate de
  créditos es server-side; el BFF no es opcional.
- **Un proveedor TTS directo (OpenAI/ElevenLabs).** El agente es
  OpenRouter/model-agnostic por decisión vigente; un segundo proveedor es
  una segunda credencial, otro billing y otra config.
- **Débito propio para TTS fuera de `billing-gate`.** Duplicaría el gate
  que ya se unificó una vez (P1 del 2026-07-31) exactamente para que no
  haya dos.
