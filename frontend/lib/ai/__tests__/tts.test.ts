import { describe, expect, it, vi, beforeEach, afterEach } from "vitest"
import { charsToEquivalentTokens, MAX_TTS_CHARS } from "../tts-usage"

/**
 * Guard de la voz del agente (context/80) contra el route real
 * (`app/api/agent/tts/route.ts`).
 *
 * Lo que fija, que es lo que no se ve leyendo:
 *  - el gate de créditos es FAIL-CLOSED de verdad: ni sin saldo ni con el
 *    balance no verificable se llega a gastar la llamada al proveedor;
 *  - el débito sale con `capability: "tts"`, que es el ÚNICO campo que hace
 *    distinguible el gasto de voz en el desglose de /admin (agrupa por
 *    `meta->>'capability'`, no por `reason`), y con los caracteres mapeados a
 *    tokens equivalentes;
 *  - el tope de longitud corta ANTES de cobrar nada.
 *
 * El fetch se intercepta una sola vez y se despacha por URL: así el gate de
 * `lib/ai/billing-gate.ts` corre de verdad en vez de estar mockeado, que es
 * justo la mitad que interesa verificar.
 */

const OLD_API_URL = process.env.API_URL
const OLD_KEY = process.env.OPENROUTER_API_KEY

/** Llamadas que el route hizo contra OpenRouter y contra la API, por test. */
type Call = { url: string; body: unknown }
let calls: Call[] = []

/** Config que devuelve `/v1/ai/config`. Null = la capability `tts` no existe. */
let ttsConfig: { model: string; creditsperktoken: number } | null = null
/** Saldo que devuelve `/v1/ai/balance`. "throw" simula la red caída. */
let balance: number | "throw" = 100
/** Status con el que responde OpenRouter. */
let openRouterStatus = 200
/** Bytes que devuelve OpenRouter. */
let openRouterBytes = new Uint8Array([1, 2, 3, 4])

beforeEach(() => {
  process.env.API_URL = "https://api.example.test"
  process.env.OPENROUTER_API_KEY = "or-key-de-prueba"
  calls = []
  ttsConfig = { model: "google/gemini-3.1-flash-tts-preview", creditsperktoken: 1 }
  balance = 100
  openRouterStatus = 200
  openRouterBytes = new Uint8Array([1, 2, 3, 4])

  vi.stubGlobal("fetch", async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const body = init?.body ? JSON.parse(String(init.body)) : undefined
    calls.push({ url, body })

    if (url.endsWith("/v1/ai/config")) {
      // Envelope canónico `{ok, data}` (apiOk) — el mock viejo respondía el
      // map pelado y con eso el route "funcionaba" en el test mientras en
      // producción nunca encontraba la capability (bug 2026-09-17).
      return new Response(JSON.stringify({ ok: true, data: ttsConfig ? { tts: ttsConfig } : {} }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    }
    if (url.endsWith("/v1/ai/balance")) {
      if (balance === "throw") throw new Error("red caída")
      return new Response(JSON.stringify({ data: { balance } }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    }
    if (url.endsWith("/v1/ai/debit")) {
      return new Response(JSON.stringify({ credits: 1, balance: 99 }), { status: 200 })
    }
    if (url.includes("openrouter.ai")) {
      if (openRouterStatus !== 200) {
        return new Response("boom", { status: openRouterStatus })
      }
      // El content-type imita al proveedor real: PCM declara rate/channels
      // (Gemini) y el resto responde mp3.
      const asPcm = (body as { response_format?: string } | undefined)?.response_format === "pcm"
      return new Response(openRouterBytes, {
        status: 200,
        headers: { "Content-Type": asPcm ? "audio/pcm;rate=24000;channels=1" : "audio/mpeg" },
      })
    }
    throw new Error(`fetch inesperado: ${url}`)
  })

  // El route loguea los caminos degradados a propósito; no ensuciar la salida.
  vi.spyOn(console, "error").mockImplementation(() => {})
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  process.env.API_URL = OLD_API_URL
  process.env.OPENROUTER_API_KEY = OLD_KEY
})

async function postTts(body: unknown): Promise<Response> {
  const { POST } = await import("../../../app/api/agent/tts/route")
  return POST(
    new Request("https://app.example.test/api/agent/tts", {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: "Bearer panel-token" },
      body: JSON.stringify(body),
    }),
  )
}

const speechCalls = () => calls.filter((c) => c.url.includes("openrouter.ai"))
const debitCalls = () => calls.filter((c) => c.url.endsWith("/v1/ai/debit"))

describe("charsToEquivalentTokens", () => {
  it("mapea caracteres a tokens equivalentes redondeando hacia arriba", () => {
    expect(charsToEquivalentTokens("")).toBe(0)
    expect(charsToEquivalentTokens("a")).toBe(1)
    expect(charsToEquivalentTokens("abcd")).toBe(1)
    expect(charsToEquivalentTokens("abcde")).toBe(2)
    expect(charsToEquivalentTokens("x".repeat(MAX_TTS_CHARS))).toBe(1000)
  })

  it("nunca devuelve 0 para un texto no vacío — un débito de 0 no se registra", () => {
    expect(charsToEquivalentTokens("hola")).toBeGreaterThan(0)
  })
})

describe("POST /api/agent/tts", () => {
  it("rechaza un texto vacío sin gastar nada", async () => {
    const res = await postTts({ text: "   " })
    expect(res.status).toBe(400)
    expect(speechCalls()).toHaveLength(0)
    expect(debitCalls()).toHaveLength(0)
  })

  it("corta con 413 un texto que pasa el tope, antes de cobrar", async () => {
    const res = await postTts({ text: "x".repeat(MAX_TTS_CHARS + 1) })
    expect(res.status).toBe(413)
    expect(speechCalls()).toHaveLength(0)
    expect(debitCalls()).toHaveLength(0)
  })

  it("sin créditos devuelve 402 y NO llama al proveedor", async () => {
    balance = 0
    const res = await postTts({ text: "hola" })
    expect(res.status).toBe(402)
    expect(speechCalls()).toHaveLength(0)
  })

  it("con el balance no verificable devuelve 503 y NO llama al proveedor (fail-closed)", async () => {
    balance = "throw"
    const res = await postTts({ text: "hola" })
    expect(res.status).toBe(503)
    expect(speechCalls()).toHaveLength(0)
  })

  it("con Gemini pide pcm + voz explícita, envuelve en WAV y debita por caracteres", async () => {
    const text = "x".repeat(400)
    const res = await postTts({ text })

    expect(res.status).toBe(200)
    // Gemini solo emite PCM (verificado contra el endpoint real 2026-09-17):
    // el route lo envuelve en WAV (44 bytes de header + los datos).
    expect(res.headers.get("Content-Type")).toBe("audio/wav")
    const wav = await res.arrayBuffer()
    expect(wav.byteLength).toBe(44 + 4)
    expect(String.fromCharCode(...new Uint8Array(wav, 0, 4))).toBe("RIFF")

    expect(speechCalls()).toHaveLength(1)
    expect(speechCalls()[0].body).toMatchObject({
      model: "google/gemini-3.1-flash-tts-preview",
      input: text,
      response_format: "pcm",
      // Gemini EXIGE voz explícita — sin ella el proveedor devuelve 400.
      voice: "Kore",
    })

    expect(debitCalls()).toHaveLength(1)
    expect(debitCalls()[0].body).toMatchObject({
      capability: "tts",
      tokensIn: 100,
      tokensOut: 0,
      model: "google/gemini-3.1-flash-tts-preview",
    })
  })

  it("el texto viaja crudo: sin prompt ni instrucciones de lectura", async () => {
    await postTts({ text: "Las ventas de hoy fueron 500." })
    const sent = speechCalls()[0].body as { input: string }
    expect(sent.input).toBe("Las ventas de hoy fueron 500.")
  })

  it("usa el modelo del catálogo de /admin cuando está configurado — Kokoro va en mp3 con su voz en español", async () => {
    ttsConfig = { model: "hexgrad/kokoro-82m", creditsperktoken: 1 }
    const res = await postTts({ text: "hola" })
    expect(res.headers.get("Content-Type")).toBe("audio/mpeg")
    // TODOS los proveedores exigen voz explícita en OpenRouter (verificado
    // 2026-09-17) — un request sin `voice` es un 400 garantizado.
    expect(speechCalls()[0].body).toMatchObject({
      model: "hexgrad/kokoro-82m",
      response_format: "mp3",
      voice: "em_alex",
    })
    expect(debitCalls()[0].body).toMatchObject({ model: "hexgrad/kokoro-82m" })
  })

  it("sin capability configurada cae al default (Kokoro, mig 227) y sigue funcionando", async () => {
    ttsConfig = null
    const res = await postTts({ text: "hola" })
    expect(res.status).toBe(200)
    expect(speechCalls()[0].body).toMatchObject({ model: "hexgrad/kokoro-82m", voice: "em_alex" })
  })

  it("un fallo del proveedor devuelve 502 y no debita", async () => {
    openRouterStatus = 500
    const res = await postTts({ text: "hola" })
    expect(res.status).toBe(502)
    expect(debitCalls()).toHaveLength(0)
  })

  it("un audio vacío del proveedor no se entrega como éxito ni se cobra", async () => {
    openRouterBytes = new Uint8Array([])
    const res = await postTts({ text: "hola" })
    expect(res.status).toBe(502)
    expect(debitCalls()).toHaveLength(0)
  })
})
