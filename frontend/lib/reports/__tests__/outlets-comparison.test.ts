import { describe, expect, it } from "vitest"

import {
  evolutionChart,
  hourLabel,
  MAX_SERIES,
  outletDelta,
  paymentMix,
  peakHours,
  shareOf,
} from "@/lib/reports/outlets-comparison"
import type { OutletOperationsRow, OutletSummaryRow } from "@/lib/types/outlets-report"

function row(id: string, total: number, revenue: number, previous: OutletSummaryRow["previous"] = null): OutletSummaryRow {
  return {
    outletId: id,
    name: `Sucursal ${id}`,
    active: true,
    total,
    expenses: total - revenue,
    revenue,
    margin: 0,
    count: 1,
    customerAverage: total,
    activeCustomers: 1,
    previous,
  }
}

describe("outletDelta", () => {
  it("sin período anterior no hay delta (undefined, no 0)", () => {
    expect(outletDelta(row("a", 100, 50), "total")).toBeUndefined()
  })
  it("variación relativa contra el anterior", () => {
    const r = row("a", 150, 50, { total: 100, revenue: 100, count: 1 })
    expect(outletDelta(r, "total")).toBeCloseTo(50)
    expect(outletDelta(r, "revenue")).toBeCloseTo(-50)
  })
  it("anterior en cero con valor actual ⇒ null (infinito, sin base)", () => {
    expect(outletDelta(row("a", 100, 50, { total: 0, revenue: 0, count: 0 }), "total")).toBeNull()
  })
  it("ganancia anterior negativa: la mejora se lee positiva", () => {
    expect(outletDelta(row("a", 100, 50, { total: 100, revenue: -100, count: 1 }), "revenue")).toBeCloseTo(150)
  })
})

describe("shareOf", () => {
  it("porcentaje sobre la suma, ordenado de mayor a menor", () => {
    const s = shareOf([row("a", 100, 0), row("b", 300, 0)], "total")
    expect(s.map((x) => x.outletId)).toEqual(["b", "a"])
    expect(s[0].pct).toBeCloseTo(75)
    expect(s[1].pct).toBeCloseTo(25)
  })
  it("las ganancias negativas no son una porción", () => {
    const s = shareOf([row("a", 100, -20), row("b", 300, 60), row("c", 200, 40)], "revenue")
    expect(s.map((x) => x.outletId)).toEqual(["b", "c"])
    expect(s[0].pct).toBeCloseTo(60)
  })
  it("con menos de dos porciones no hay gráfico", () => {
    expect(shareOf([row("a", 100, 10), row("b", 0, 0)], "total")).toEqual([])
    expect(shareOf([row("a", 100, 10)], "total")).toEqual([])
  })
})

describe("evolutionChart", () => {
  const buckets = [
    { bucket: "2026-06-01", end: "2026-06-01", partial: false },
    { bucket: "2026-06-02", end: "2026-06-02", partial: false },
  ]
  it("una línea por sucursal con ventas, en el orden de la tabla, sin las que no vendieron", () => {
    const res = {
      granularity: "day" as const,
      buckets,
      series: [
        { outletId: "a", name: "A", points: [{ bucket: "2026-06-01", total: 10 }, { bucket: "2026-06-02", total: 0 }] },
        { outletId: "b", name: "B", points: [{ bucket: "2026-06-01", total: 0 }, { bucket: "2026-06-02", total: 30 }] },
        { outletId: "z", name: "Z", points: [{ bucket: "2026-06-01", total: 0 }, { bucket: "2026-06-02", total: 0 }] },
      ],
    }
    const chart = evolutionChart(res, ["b", "a", "z"])
    expect(chart.series.map((s) => s.outletId)).toEqual(["b", "a"])
    expect(chart.data).toEqual([
      { bucket: "2026-06-01", end: "2026-06-01", partial: false, s0: 0, s1: 10 },
      { bucket: "2026-06-02", end: "2026-06-02", partial: false, s0: 30, s1: 0 },
    ])
    expect(chart.truncated).toBe(false)
  })
  it(`como mucho ${MAX_SERIES} líneas`, () => {
    const series = Array.from({ length: MAX_SERIES + 2 }, (_, i) => ({
      outletId: `o${i}`,
      name: `O${i}`,
      points: [{ bucket: "2026-06-01", total: i + 1 }],
    }))
    const chart = evolutionChart({ granularity: "day", buckets, series }, series.map((s) => s.outletId))
    expect(chart.series).toHaveLength(MAX_SERIES)
    expect(chart.truncated).toBe(true)
  })
})

describe("operación", () => {
  const op: OutletOperationsRow = {
    outletId: "a",
    name: "A",
    hours: [
      { hour: 12, count: 9 },
      { hour: 20, count: 5 },
      { hour: 9, count: 2 },
      { hour: 15, count: 1 },
    ],
    payments: [
      { name: "Efectivo", amount: 300 },
      { name: "Tarjeta", amount: 100 },
    ],
    topItems: [],
  }
  it("las tres horas pico", () => {
    expect(peakHours(op).map((h) => h.hour)).toEqual([12, 20, 9])
  })
  it("mix de medios de pago en %", () => {
    expect(paymentMix(op).map((p) => Math.round(p.pct))).toEqual([75, 25])
  })
  it("etiqueta de hora", () => {
    expect(hourLabel(9)).toBe("09:00")
  })
})
