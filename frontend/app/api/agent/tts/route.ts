import { assertAiCredits, debitAiUsage, AiCreditsError } from "@/lib/ai/billing-gate"
import { MAX_TTS_CHARS, charsToEquivalentTokens } from "@/lib/ai/tts-usage"

export const runtime = "nodejs"
export const maxDuration = 30

/**
 * BFF de la voz del agente — `context/80-voz-del-agente.md`.
 *
 * Convierte el texto de un mensaje del asistente en audio con un modelo TTS de
 * OpenRouter y lo devuelve como MP3. Realm PANEL: la credencial es el Bearer
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
 * D2 del plan, REVISADA por el owner el 2026-09-17: la propuesta original era
 * Kokoro 82M por precio, y se cayó por calidad — su español es flojo (pocas
 * voces, G2P débil fuera del inglés) y el motivo entero de la feature es que
 * la voz actual suena mal. Cambiar una voz mala por otra mala no arregla nada.
 * El default pasa a Gemini Flash TTS, que cubre 70+ idiomas. Kokoro sigue
 * siendo la alternativa BARATA si el costo llega a molestar: se cambia desde
 * /admin (es un select, no un deploy), no tocando este archivo.
 *
 * El slug lleva `-preview` porque así lo publica OpenRouter hoy; cuando salga
 * de preview el id cambia y este default deja de resolver. Es otra razón para
 * que la fuente real sea el catálogo de /admin y esto solo el paracaídas.
 */
const DEFAULT_TTS_MODEL = "google/gemini-3.1-flash-tts-preview"

/**
 * Voz del modelo. Se omite a propósito: cada modelo TTS expone su propio juego
 * de nombres de voz y OpenRouter no los normaliza, así que mandar un nombre
 * que el modelo no conoce lo hace fallar la request ENTERA — y como el cliente
 * cae a la voz del navegador ante cualquier falla, el síntoma sería "la voz
 * nueva nunca se escucha" sin ningún error visible. Sin este campo, el modelo
 * usa su voz por defecto, que funciona. Cuando haya una voz elegida y
 * verificada contra el modelo configurado, va acá.
 */
const TTS_VOICE: string | undefined = undefined

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
  // `tts` entra al catálogo sin tocar el schema.
  let modelId = DEFAULT_TTS_MODEL
  try {
    const configRes = await fetch(`${apiUrl}/v1/ai/config`, { headers: { Authorization: authHeader } })
    if (configRes.ok) {
      const config = (await configRes.json()) as Record<string, { model: string; creditsperktoken: number }>
      const chosen = config?.[TTS_CAPABILITY]?.model
      if (chosen) {
        modelId = chosen
      } else {
        // Sin fila `tts` habilitada la voz IGUAL suena (este default), pero el
        // débito de abajo se rechaza con 422 ("Capability sin config activa") y
        // el comercio escucha gratis. Es best-effort, así que no rompe nada en
        // el momento — por eso queda logueado: el síntoma es invisible.
        console.error(`[agent-tts] no hay capability '${TTS_CAPABILITY}' habilitada en ai_model_config; se usa ${modelId} y el débito va a fallar`)
      }
    } else {
      console.error(`[agent-tts] ai/config respondió ${configRes.status}, usando default ${modelId}`)
    }
  } catch (e) {
    // fail-open en el MODELO (no en el cobro: el gate de abajo es aparte)
    console.error("[agent-tts] fallo al leer ai/config, usando default", e)
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

  let audio: ArrayBuffer
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
        ...(TTS_VOICE ? { voice: TTS_VOICE } : {}),
        // El default del endpoint es `pcm` (crudo, sin contenedor): un browser
        // no lo reproduce con `new Audio()`. `mp3` se pide explícito.
        response_format: "mp3",
      }),
    })

    if (!res.ok) {
      const detail = await res.text().catch(() => "")
      console.error(`[agent-tts] OpenRouter respondió ${res.status} model=${modelId} — ${detail.slice(0, 300)}`)
      return Response.json({ error: "No se pudo generar la voz" }, { status: 502 })
    }

    audio = await res.arrayBuffer()
  } catch (e) {
    console.error("[agent-tts] fallo de red contra OpenRouter", e)
    return Response.json({ error: "No se pudo generar la voz" }, { status: 502 })
  }

  if (audio.byteLength === 0) {
    console.error(`[agent-tts] OpenRouter devolvió audio vacío model=${modelId}`)
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
      "Content-Type": "audio/mpeg",
      "Content-Length": String(audio.byteLength),
      // El audio depende del texto y ya se cachea del lado del cliente por
      // mensaje (D6): un caché intermedio no aporta y puede servir el audio de
      // un mensaje a otro tenant.
      "Cache-Control": "no-store",
    },
  })
}
