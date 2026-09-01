import { describe, it, expect } from "vitest"
import type { TextStreamPart, ToolSet } from "ai"
import { truncationMetadata, isTruncated } from "@/lib/agent/truncation"

/**
 * El corte por `maxOutputTokens` no lanza ningún error: llega como una
 * respuesta normal con `finishReason: "length"`. Si esta detección se rompe,
 * nada falla — simplemente se vuelve a entregar media respuesta con cara de
 * respuesta entera, que es el bug que esto arregla. De ahí el test.
 */

/** Arma un part `finish` del stream con el finishReason pedido. */
const finish = (finishReason: string): TextStreamPart<ToolSet> =>
  ({
    type: "finish",
    finishReason,
    rawFinishReason: finishReason,
    totalUsage: { inputTokens: 1, outputTokens: 1, totalTokens: 2 },
  }) as unknown as TextStreamPart<ToolSet>

describe("truncationMetadata (servidor)", () => {
  it("marca truncated cuando el corte fue por longitud", () => {
    expect(truncationMetadata({ part: finish("length") })).toEqual({ truncated: true })
  })

  it("no marca nada en un cierre normal ni en los otros cortes", () => {
    // `stop` es la respuesta completa. `tool-calls` es el corte que produce
    // `stopWhen: hasToolCall(...)` en los dos routes: NO es una respuesta
    // truncada, y marcarlo pondría el aviso en cada confirmación de acción.
    for (const reason of ["stop", "tool-calls", "content-filter", "error", "other", "unknown"]) {
      expect(truncationMetadata({ part: finish(reason) })).toBeUndefined()
    }
  })

  it("no marca nada en el part `start` (el SDK llama al callback dos veces)", () => {
    const start = { type: "start" } as unknown as TextStreamPart<ToolSet>
    expect(truncationMetadata({ part: start })).toBeUndefined()
  })

  it("ignora el corte por longitud de un paso intermedio", () => {
    // Con multi-step el SDK emite `finish-step` por cada paso y un solo
    // `finish` al final. El aviso habla de la respuesta que el usuario LEE, o
    // sea la del último paso: un `finish-step` no lo dispara.
    const step = { ...finish("length"), type: "finish-step" } as unknown as TextStreamPart<ToolSet>
    expect(truncationMetadata({ part: step })).toBeUndefined()
  })
})

describe("isTruncated (cliente)", () => {
  it("reconoce la metadata que puso el servidor", () => {
    expect(isTruncated({ metadata: { truncated: true } })).toBe(true)
  })

  it("es falso para una respuesta sana", () => {
    expect(isTruncated({})).toBe(false)
    expect(isTruncated({ metadata: undefined })).toBe(false)
    expect(isTruncated({ metadata: {} })).toBe(false)
  })

  it("aguanta metadata basura del historial persistido", () => {
    // localStorage puede tener mensajes de una versión anterior del formato:
    // la forma se valida, no se castea.
    for (const metadata of [null, "length", 42, [], { truncated: "true" }, { truncated: false }]) {
      expect(isTruncated({ metadata })).toBe(false)
    }
  })
})
