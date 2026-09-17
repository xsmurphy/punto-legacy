# 80 — Voz del agente (TTS por OpenRouter)

> Estado: **en implementación** (2026-09-17). D1-D6 CERRADAS — el owner
> aprobó las propuestas tal cual (Kokoro default, cobro por caracteres con
> reason propio, fallback a voz nativa, v1 solo panel, blob sin streaming).

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
- **D2 — Modelo default.** El precio manda: la voz es un lujo, no puede
  costar más que la respuesta que lee. Propuesta: **Kokoro 82M** como
  default (barato, es-LA aceptable) y el modelo configurable desde `/admin`
  como los de chat — si un tenant quiere Gemini TTS, es un select, no un
  deploy.
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
- **D6 — Streaming.** v1 blob completo (la respuesta típica del agente son
  segundos de audio). Streaming de audio solo si la latencia molesta en la
  práctica.

## 5. Arquitecturas rechazadas

- **Llamar a OpenRouter desde el browser.** La key es secreta y el gate de
  créditos es server-side; el BFF no es opcional.
- **Un proveedor TTS directo (OpenAI/ElevenLabs).** El agente es
  OpenRouter/model-agnostic por decisión vigente; un segundo proveedor es
  una segunda credencial, otro billing y otra config.
- **Débito propio para TTS fuera de `billing-gate`.** Duplicaría el gate
  que ya se unificó una vez (P1 del 2026-07-31) exactamente para que no
  haya dos.
