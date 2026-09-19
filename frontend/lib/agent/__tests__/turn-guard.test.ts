import { describe, expect, it } from "vitest"

import {
  interruptionFor,
  isStalled,
  markLastInterrupted,
  isInterrupted,
  retryPayloadFor,
} from "@/lib/agent/interruption"
import {
  guardTools,
  guardUIStream,
  TOOL_TIMEOUT_READ_RESULT,
  TOOL_TIMEOUT_WRITE_RESULT,
  TURN_TIMEOUT_COPY,
  type StreamChunk,
  type StreamEnd,
} from "@/lib/agent/turn-guard"

/**
 * Guardia del turno del asistente: las dos fallas reportadas en producción
 * (2026-09-19) — el turno que se cuelga para siempre esperando una consulta
 * lenta, y la respuesta que se corta a mitad sin avisar — convertidas en
 * regresión sobre la lógica pura.
 */

const never = () => new Promise<never>(() => {})
const tick = (ms: number) => new Promise((r) => setTimeout(r, ms))

describe("guardTools — ninguna consulta cuelga el turno", () => {
  it("una lectura que no responde devuelve error legible al vencer", async () => {
    const settled: string[] = []
    const tools = guardTools(
      { get_report: { execute: never } },
      { timeoutMs: 20, onSettled: (e) => settled.push(`${e.tool}:${e.outcome}`) },
    )
    const out = await (tools.get_report as { execute: (i: unknown, o: unknown) => Promise<unknown> }).execute({}, {})
    expect(out).toEqual({ error: TOOL_TIMEOUT_READ_RESULT })
    expect(settled).toEqual(["get_report:timeout"])
  })

  it("una acción que muta usa el aviso de escritura (no promete que no pasó nada)", async () => {
    const tools = guardTools({ execute_action: { execute: never } }, { timeoutMs: 20 })
    const out = await (tools.execute_action as { execute: (i: unknown, o: unknown) => Promise<unknown> }).execute({}, {})
    expect(out).toEqual({ error: TOOL_TIMEOUT_WRITE_RESULT })
  })

  it("aborta la señal que recibió la tool al vencer", async () => {
    let seen: AbortSignal | undefined
    const tools = guardTools(
      {
        slow: {
          execute: (_: unknown, o: { abortSignal?: AbortSignal }) => {
            seen = o.abortSignal
            return never()
          },
        },
      },
      { timeoutMs: 20 },
    )
    await (tools.slow as { execute: (i: unknown, o: unknown) => Promise<unknown> }).execute({}, {})
    expect(seen?.aborted).toBe(true)
  })

  it("una tool rápida pasa intacta y las definiciones sin execute no se tocan", async () => {
    const def = { description: "x" }
    const tools = guardTools({ fast: { execute: async () => ({ ok: 1 }) }, plain: def }, { timeoutMs: 1000 })
    const out = await (tools.fast as { execute: (i: unknown, o: unknown) => Promise<unknown> }).execute({}, {})
    expect(out).toEqual({ ok: 1 })
    expect(tools.plain).toBe(def)
  })
})

describe("guardUIStream — latido y tope del turno", () => {
  async function drain(stream: ReadableStream<StreamChunk>): Promise<StreamChunk[]> {
    const out: StreamChunk[] = []
    const reader = stream.getReader()
    for (;;) {
      const { done, value } = await reader.read()
      if (done) return out
      out.push(value)
    }
  }

  it("con la fuente muda, late y al tope cierra con el error del turno", async () => {
    const source = new ReadableStream<StreamChunk>({ start() {} })
    const ends: StreamEnd[] = []
    let aborted = false
    const chunks = await drain(
      guardUIStream(source, {
        // El intervalo del latido tiene piso de 250 ms (no se revisa más seguido
        // que eso): el tope tiene que dejar pasar al menos un par de revisiones.
        heartbeatMs: 20,
        turnTimeoutMs: 700,
        onTurnTimeout: () => {
          aborted = true
        },
        onEnd: (e) => ends.push(e),
      }),
    )
    expect(chunks.some((c) => c.type === "data-heartbeat")).toBe(true)
    expect(chunks.at(-1)).toEqual({ type: "error", errorText: TURN_TIMEOUT_COPY })
    expect(ends).toEqual([{ kind: "turn-timeout" }])
    expect(aborted).toBe(true)
  })

  it("una fuente que termina bien pasa entera y cierra como completada", async () => {
    const source = new ReadableStream<StreamChunk>({
      start(c) {
        c.enqueue({ type: "text-delta", delta: "hola" })
        c.close()
      },
    })
    const ends: StreamEnd[] = []
    const chunks = await drain(guardUIStream(source, { turnTimeoutMs: 1000, onEnd: (e) => ends.push(e) }))
    expect(chunks).toEqual([{ type: "text-delta", delta: "hola" }])
    expect(ends).toEqual([{ kind: "completed" }])
    await tick(5)
  })
})

describe("interrupción — nunca una respuesta a medias sin aviso", () => {
  const base = { isAbort: false, isDisconnect: false, isError: false, stalled: false }

  it("un corte de conexión o un stream sin cierre marcan la respuesta como interrumpida", () => {
    expect(interruptionFor({ ...base, isDisconnect: true })).toEqual({})
    expect(interruptionFor({ ...base, finishReason: undefined })).toEqual({})
    expect(interruptionFor({ ...base, stalled: true })).toEqual({})
  })

  it("el tope del turno llega con su texto al usuario", () => {
    expect(interruptionFor({ ...base, isError: true, error: new Error(TURN_TIMEOUT_COPY) })).toEqual({
      detail: TURN_TIMEOUT_COPY,
    })
  })

  it("un fin normal, un stop del usuario o sin créditos NO son interrupciones", () => {
    expect(interruptionFor({ ...base, finishReason: "stop" })).toBeNull()
    expect(interruptionFor({ ...base, isAbort: true })).toBeNull()
    expect(interruptionFor({ ...base, isError: true, error: new Error("Sin créditos de IA") })).toBeNull()
  })

  it("marca la última respuesta y Reintentar reenvía la pregunta que la originó", () => {
    const msgs: { id: string; role: string; metadata?: unknown; parts: { type: string; text: string }[] }[] = [
      { id: "u1", role: "user", parts: [{ type: "text", text: "¿más vendidos los sábados?" }] },
      { id: "a1", role: "assistant", parts: [{ type: "text", text: "Los más vend" }] },
    ]
    const marked = markLastInterrupted(msgs, {})
    expect(isInterrupted(marked[1])).toBe(true)
    expect(isInterrupted(marked[0])).toBe(false)
    expect(retryPayloadFor(marked, "a1")).toEqual({ text: "¿más vendidos los sábados?" })
  })

  it("estancado = sin actividad por el umbral", () => {
    expect(isStalled(0, 44_999, 45_000)).toBe(false)
    expect(isStalled(0, 45_000, 45_000)).toBe(true)
  })
})
