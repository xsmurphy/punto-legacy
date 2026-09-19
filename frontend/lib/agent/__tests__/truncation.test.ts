import { describe, expect, it } from "vitest"

import { truncationMetadataFor } from "@/lib/agent/truncation"

const finish = (finishReason: string) => ({ part: { type: "finish", finishReason } as never })

describe("truncationMetadataFor — la respuesta a medias se marca", () => {
  it("largo máximo → truncada", () => {
    expect(truncationMetadataFor(() => 2, 10)(finish("length"))).toEqual({ truncated: true })
  })

  it("pasos agotados con el modelo pidiendo más tools → truncada", () => {
    expect(truncationMetadataFor(() => 10, 10)(finish("tool-calls"))).toEqual({ truncated: true })
  })

  it("tool-calls con pasos de sobra es la confirmación, no un corte", () => {
    expect(truncationMetadataFor(() => 3, 10)(finish("tool-calls"))).toBeUndefined()
  })

  it("fin normal → nada", () => {
    expect(truncationMetadataFor(() => 10, 10)(finish("stop"))).toBeUndefined()
    expect(truncationMetadataFor(() => 1, 10)({ part: { type: "text-delta" } as never })).toBeUndefined()
  })
})
