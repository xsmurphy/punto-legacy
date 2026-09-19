import { describe, expect, it } from "vitest"

import { splitShares } from "@/lib/charts/split"

describe("splitShares", () => {
  it("fracciones exactas para dibujar y porcentajes que suman 100", () => {
    const s = splitShares(1, 2)
    expect(s.widths[0]).toBeCloseTo(1 / 3)
    expect(s.widths[1]).toBeCloseTo(2 / 3)
    expect(s.percents).toEqual([33, 67])
  })
  it("mitad y mitad", () => {
    expect(splitShares(50, 50).percents).toEqual([50, 50])
  })
  it("con las dos partes en > 0 ninguna se lee 0 ni 100", () => {
    expect(splitShares(1, 10_000).percents).toEqual([1, 99])
    expect(splitShares(10_000, 1).percents).toEqual([99, 1])
  })
  it("una parte en cero → 100/0; las dos en cero → 0/0", () => {
    expect(splitShares(5, 0).percents).toEqual([100, 0])
    expect(splitShares(0, 0)).toEqual({ widths: [0, 0], percents: [0, 0] })
  })
  it("negativos y no finitos cuentan como cero", () => {
    expect(splitShares(-5, 10).percents).toEqual([0, 100])
    expect(splitShares(Number.NaN, 10).percents).toEqual([0, 100])
  })
})
