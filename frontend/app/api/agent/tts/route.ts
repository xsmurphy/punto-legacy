import { assertAiCredits, debitAiUsage, AiCreditsError } from "@/lib/ai/billing-gate"
import { fetchAiModelConfig } from "@/lib/ai/model-config"
import { parsePcmContentType, pcmToWav } from "@/lib/ai/pcm-wav"
import { MAX_TTS_CHARS, charsToEquivalentTokens } from "@/lib/ai/tts-usage"

export const runtime = "nodejs"
export const maxDuration = 30

/**
 * BFF de la voz del agente — `context/80-voz-del-agente.md`.
 *
 * Convierte el texto de un mensaje del asistente en audio con un modelo TTS de
 * OpenRouter y lo devuelve reproducible (MP3, o WAV cuando el proveedor solo
 * emite PCM — ver `ttsRequestParams`). Realm PANEL: la credencial es el Bearer
 * del panel (`context/54`), el MISMO que usa `app/api/agent/chat/route.ts`, y
 * se reenvía tal cual al backend para resolver la company server-side.
 *
 * D5 del plan — este endpoint es SOLO del panel. El asistente de la caja
 * (`context/59`) tiene su propio BFF con el Bearer del DEVICE y no puede
 * llamar acá: el POS es token-only por mandato
 * (`feedback_pos_token_only_no_realms`) y compartir un endpoint entre los dos
 * realms es exactamente la clase de bug que ese mandato viene a cerrar. Si
 * alguna vez se quiere voz en la caja, es un segundo route con ESE realm.
 *
 * El cobro NO tiene mecanismo propio: entra por el wrapper compartido
 * `lib/ai/billing-gate.ts` (gate fail-closed antes de gastar, débito
 * best-effort después), igual que el chat y el OCR. Un débito propio para el
 * TTS es una arquitectura explícitamente rechazada en §5 del plan — el gate se
 * unificó una vez (P1 2026-07-31) justamente para que no haya dos.
 */

/** Capability del catálogo `ai_model_config` con la que se resuelve el modelo y su precio. */
const TTS_CAPABILITY = "tts"

/**
 * Modelo por defecto si `/v1/ai/config` no responde o no tiene la capability
 * `tts` configurada.
 *
 * D2 del plan, revisada DOS veces el 2026-09-17: Kokoro se descartó primero
 * por calidad de español, Gemini lo reemplazó… y duró un día. Su latencia lo
 * hace inusable: la generación escala con el largo de forma errática (medido:
 * 200 chars ≈ 6s, 300 llegó a 45s, 1100 ≈ 144s) y el primer byte llega al
 * FINAL, así que ni streaming ni troceo la salvan. Kokoro genera lo mismo en
 * 1-2 segundos — la voz es más plana, pero una voz que llega tarde no es una
 * voz. Vuelve como default (mig 227, decisión del owner) y Gemini queda como
 * alternativa de calidad si su latencia algún día se arregla. La fuente real
 * es el catálogo de /admin; esto es solo el paracaídas, alineado a la seed.
 */
const DEFAULT_TTS_MODEL = "hexgrad/kokoro-82m"

/**
 * Params de la request por FAMILIA de modelo. OpenRouter no los normaliza:
 * cada proveedor TTS tiene su propio contrato y equivocarlo falla la request
 * ENTERA — y como el cliente cae a la voz del navegador ante cualquier falla,
 * el síntoma es "la voz nueva nunca se escucha" sin error visible.
 *
 * Verificado contra el endpoint real 2026-09-17:
 * - TODOS los proveedores exigen `voice` explícita ("An explicit voice is
 *   required for this TTS provider") — no existe el "usa tu default".
 * - Kokoro: español con `ef_dora` (f) / `em_alex` / `em_santa` (m), mp3 ok.
 * - Gemini: `Kore` multilingüe, y SOLO emite `pcm` (mp3 devuelve 400) — el
 *   PCM se envuelve en WAV acá abajo antes de responder.
 * - Aura-2 (Deepgram): `aura-2-celeste-es` / `aura-2-estrella-es`, mp3 ok.
 *
 * Un modelo de familia desconocida configurado en /admin va a fallar acá por
 * la voz faltante: el lugar de su alta es esta función, no un if en el
 * handler.
 */
function ttsRequestParams(modelId: string): { voice?: string; format: "mp3" | "pcm" } {
  if (modelId.startsWith("google/")) return { voice: "Kore", format: "pcm" }
  if (modelId.startsWith("deepgram/")) return { voice: "aura-2-celeste-es", format: "mp3" }
  // em_alex y no ef_dora: el owner escuchó las tres voces es-* y eligió (2026-09-17).
  return { voice: "em_alex", format: "mp3" }
}

export async function POST(req: Request) {
  const apiKey = process.env.OPENROUTER_API_KEY
  if (!apiKey) {
    return Response.json({ error: "OPENROUTER_API_KEY no configurada" }, { status: 500 })
  }

  const authHeader = req.headers.get("authorization") ?? ""
  const apiUrl = process.env.API_URL ?? ""

  let body: { text?: unknown }
  try {
    body = (await req.json()) as { text?: unknown }
  } catch {
    return Response.json({ error: "Body inválido" }, { status: 400 })
  }

  const text = typeof body.text === "string" ? body.text.trim() : ""
  if (!text) {
    return Response.json({ error: "No hay texto para leer" }, { status: 400 })
  }
  if (text.length > MAX_TTS_CHARS) {
    // 413 y no 400: el texto es válido, lo que no entra es su tamaño. El
    // cliente lo distingue para caer a la voz del navegador en vez de tratarlo
    // como un error de programación.
    return Response.json({ error: "El texto es demasiado largo para leerlo" }, { status: 413 })
  }

  // Modelo desde la config del tenant, mismo patrón que el chat: el catálogo de
  // /admin manda y el slug no se hardcodea. `ai_model_config.capability` es
  // TEXT libre (mig 43) y /admin permite crear capabilities nuevas, así que
  // `tts` entra al catálogo sin tocar el schema. El unwrap del envelope
  // `{ok, data}` vive en `fetchAiModelConfig` — parsear el body crudo acá es
  // exactamente el bug que ese helper vino a matar (fail-open en el MODELO;
  // el cobro tiene su gate fail-closed aparte, abajo).
  let modelId = DEFAULT_TTS_MODEL
  const config = await fetchAiModelConfig(apiUrl, authHeader, "[agent-tts]")
  const chosen = config[TTS_CAPABILITY]?.model
  if (chosen) {
    modelId = chosen
  } else {
    // Sin fila `tts` habilitada la voz IGUAL suena (este default), pero el
    // débito de abajo se rechaza con 422 ("Capability sin config activa") y
    // el comercio escucha gratis. Es best-effort, así que no rompe nada en
    // el momento — por eso queda logueado: el síntoma es invisible.
    console.error(`[agent-tts] no hay capability '${TTS_CAPABILITY}' habilitada en ai_model_config; se usa ${modelId} y el débito va a fallar`)
  }

  // Gate de créditos ANTES de gastar la llamada al proveedor. FAIL-CLOSED.
  const requestId = crypto.randomUUID()
  try {
    await assertAiCredits({ apiUrl, authHeader, logPrefix: "[agent-tts]" })
  } catch (e) {
    if (e instanceof AiCreditsError) {
      // 402 = sin créditos, 503 = no se pudo verificar. El cliente los
      // distingue por status para elegir el mensaje del toast.
      return Response.json({ error: e.message }, { status: e.status })
    }
    throw e
  }

  const params = ttsRequestParams(modelId)
  let audio: ArrayBuffer
  let mimeType: string
  try {
    const res = await fetch("https://openrouter.ai/api/v1/audio/speech", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${apiKey}`,
      },
      body: JSON.stringify({
        model: modelId,
        // El texto va CRUDO, tal como lo escribió el agente: sin prompt, sin
        // SSML, sin instrucciones de lectura. Este endpoint sintetiza, no
        // conversa — cualquier cosa que le agreguemos la termina leyendo en voz
        // alta al usuario.
        input: text,
        ...(params.voice ? { voice: params.voice } : {}),
        response_format: params.format,
      }),
    })

    if (!res.ok) {
      const detail = await res.text().catch(() => "")
      console.error(`[agent-tts] OpenRouter respondió ${res.status} model=${modelId} — ${detail.slice(0, 300)}`)
      return Response.json({ error: "No se pudo generar la voz" }, { status: 502 })
    }

    const raw = await res.arrayBuffer()
    if (raw.byteLength === 0) {
      // Antes del wrap: un PCM vacío envuelto en WAV mide 44 bytes y pasaría
      // el chequeo de abajo como si fuera audio.
      console.error(`[agent-tts] OpenRouter devolvió audio vacío model=${modelId}`)
      return Response.json({ error: "No se pudo generar la voz" }, { status: 502 })
    }
    if (params.format === "pcm") {
      // Gemini responde PCM pelado (`audio/pcm;rate=24000;channels=1`) que un
      // browser no reproduce: se envuelve en WAV con el rate/channels que
      // declara el header — es un prefijo de 44 bytes, no un transcode.
      const { rate, channels } = parsePcmContentType(res.headers.get("content-type"))
      audio = pcmToWav(raw, rate, channels)
      mimeType = "audio/wav"
    } else {
      audio = raw
      mimeType = "audio/mpeg"
    }
  } catch (e) {
    console.error("[agent-tts] fallo de red contra OpenRouter", e)
    return Response.json({ error: "No se pudo generar la voz" }, { status: 502 })
  }

  // Débito best-effort DESPUÉS de tener el audio, con `await`: la respuesta
  // todavía no salió, así que una promesa suelta acá se puede quedar sin
  // ejecutar cuando el runtime da por terminada la request. El costo es un
  // round-trip interno contra la propia API.
  //
  // `capability: "tts"` es lo que hace distinguible el gasto de voz: el
  // desglose de /admin agrupa por `meta->>'capability'`
  // (`AiAdminService::consumptionReport`), no por `reason` —que
  // `api/v1/ai/debit.php` escribe fijo en 'agent_chat' y nadie lee para
  // desglosar—. Los tokens de SALIDA van en 0 a propósito: el TTS no genera
  // tokens, genera audio, y la unidad de cobro son los caracteres de entrada
  // (D3, ver `lib/ai/tts-usage.ts`).
  await debitAiUsage({
    apiUrl,
    authHeader,
    tokensIn: charsToEquivalentTokens(text),
    tokensOut: 0,
    capability: TTS_CAPABILITY,
    model: modelId,
    requestId,
    logPrefix: "[agent-tts]",
  })

  return new Response(audio, {
    headers: {
      "Content-Type": mimeType,
      "Content-Length": String(audio.byteLength),
      // El audio depende del texto y ya se cachea del lado del cliente por
      // mensaje (D6): un caché intermedio no aporta y puede servir el audio de
      // un mensaje a otro tenant.
      "Cache-Control": "no-store",
    },
  })
}
