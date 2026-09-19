import { describe, expect, it } from "vitest"

import { hourBars, hourRange, parseHour, peakHour } from "@/lib/dashboard/top-hours"

describe("parseHour", () => {
  it("lee la hora del label del backend", () => {
    expect(parseHour("08:00 Ventas")).toBe(8)
    expect(parseHour("23:00 Ventas")).toBe(23)
  })
  it("ilegible o fuera de rango → null", () => {
    expect(parseHour("")).toBeNull()
    expect(parseHour(undefined)).toBeNull()
    expect(parseHour("Ventas")).toBeNull()
    expect(parseHour("25:00")).toBeNull()
  })
})

describe("hourBars", () => {
  it("ordena por hora del día (el backend manda ranking) y mantiene el paralelo", () => {
    const bars = hourBars({
      hour: ["18:00 Ventas", "08:00 Ventas", "12:00 Ventas"],
      total: [5, 2, 9],
      units: [10, 3, 20],
    })
    expect(bars).toEqual([
      { hour: 8, sales: 2, units: 3 },
      { hour: 12, sales: 9, units: 20 },
      { hour: 18, sales: 5, units: 10 },
    ])
  })
  it("sin `units` (backend previo) → units null", () => {
    expect(hourBars({ hour: ["09:00 Ventas"], total: [4] })).toEqual([{ hour: 9, sales: 4, units: null }])
  })
  it("descarta horas ilegibles y repetidas", () => {
    expect(hourBars({ hour: ["x", "10:00", "10:00"], total: [1, 2, 3] })).toEqual([
      { hour: 10, sales: 2, units: null },
    ])
  })
  it("sin datos → []", () => {
    expect(hourBars(undefined)).toEqual([])
  })
})

describe("peakHour", () => {
  it("la de más ventas", () => {
    expect(peakHour(hourBars({ hour: ["08:00", "12:00", "18:00"], total: [2, 9, 5] }))?.hour).toBe(12)
  })
  it("empate de ventas → más unidades; si sigue, la más temprana", () => {
    expect(
      peakHour(hourBars({ hour: ["08:00", "12:00"], total: [5, 5], units: [3, 7] }))?.hour,
    ).toBe(12)
    expect(peakHour(hourBars({ hour: ["18:00", "08:00"], total: [5, 5] }))?.hour).toBe(8)
  })
  it("sin ventas no hay pico", () => {
    expect(peakHour(hourBars({ hour: ["08:00"], total: [0] }))).toBeNull()
    expect(peakHour([])).toBeNull()
  })
})

describe("hourRange", () => {
  it("rango de una hora", () => {
    expect(hourRange(12)).toBe("12–13 h")
    expect(hourRange(23)).toBe("23–24 h")
  })
})
