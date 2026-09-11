import { describe, expect, it } from "vitest"

import { shiftRangeBackwards } from "../reports/previous-range"

/** El rango tal como lo manda `rangeToBackend()`. */
const r = (from: string, to: string) => shiftRangeBackwards(from, to)

describe("shiftRangeBackwards", () => {
  it("un mes de 30 días compara contra los 30 días anteriores", () => {
    // El bug: restando milisegundos, el anterior arrancaba 2026-08-01 23:59:59
    // y el backend rellenaba 31 buckets contra 30, corriendo la comparativa
    // un día entero.
    expect(r("2026-09-01 00:00:00", "2026-09-30 23:59:59")).toEqual({
      from: "2026-08-02 00:00:00",
      to: "2026-08-31 23:59:59",
    })
  })

  it("un solo día compara contra el día anterior completo", () => {
    expect(r("2026-09-10 00:00:00", "2026-09-10 23:59:59")).toEqual({
      from: "2026-09-09 00:00:00",
      to: "2026-09-09 23:59:59",
    })
  })

  it("el anterior siempre tiene la misma cantidad de días que el actual", () => {
    const casos: Array<[string, string, number]> = [
      ["2026-09-01 00:00:00", "2026-09-07 23:59:59", 7],
      ["2026-03-01 00:00:00", "2026-03-31 23:59:59", 31],
      ["2026-01-01 00:00:00", "2026-01-31 23:59:59", 31], // cruza de año
    ]
    for (const [from, to, dias] of casos) {
      const prev = r(from, to)
      const f = new Date(prev.from.replace(" ", "T"))
      const t = new Date(prev.to.replace(" ", "T"))
      const span =
        Math.round(
          (new Date(t.getFullYear(), t.getMonth(), t.getDate()).getTime() -
            new Date(f.getFullYear(), f.getMonth(), f.getDate()).getTime()) /
            86_400_000,
        ) + 1
      expect(span, `${from}..${to}`).toBe(dias)
    }
  })

  it("el anterior termina justo antes de que empiece el actual", () => {
    const prev = r("2026-09-01 00:00:00", "2026-09-30 23:59:59")
    expect(prev.to.startsWith("2026-08-31")).toBe(true)
  })
})
