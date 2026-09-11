import { describe, expect, it } from "vitest"

import { buildRankingSlices } from "@/components/domain/reports/ranking-bar-chart"

describe("buildRankingSlices", () => {
  const data = [
    { label: "a", value: 10, overlayValue: 4 },
    { label: "b", value: 30, overlayValue: 9 },
    { label: "c", value: 0 },
    { label: "d", value: 20 },
  ]

  it("sin overlay la barra vale el total", () => {
    // El bug de 2026-09-10: `part` salía 0 y el gráfico no dibujaba NADA, sin
    // tirar un error. Por eso este caso es el primero.
    const out = buildRankingSlices(data, 10, false)
    expect(out.map((d) => d.part)).toEqual([30, 20, 10])
    expect(out.every((d) => d.rest === 0)).toBe(true)
  })

  it("con overlay, part + rest siempre suma el total", () => {
    const out = buildRankingSlices(data, 10, true)
    for (const d of out) {
      expect(d.part + d.rest, d.label).toBe(d.value)
    }
    // `d` no trae overlayValue: es todo resto, no un agujero.
    expect(out.find((d) => d.label === "d")).toMatchObject({ part: 0, rest: 20 })
  })

  it("ordena desc, saca los no positivos y respeta el límite", () => {
    expect(buildRankingSlices(data, 2, false).map((d) => d.label)).toEqual(["b", "d"])
    expect(buildRankingSlices(data, 10, false).some((d) => d.label === "c")).toBe(false)
  })

  it("una porción mayor que el total no desborda la barra", () => {
    const out = buildRankingSlices([{ label: "x", value: 5, overlayValue: 99 }], 10, true)
    expect(out[0]).toMatchObject({ part: 5, rest: 0 })
  })
})
