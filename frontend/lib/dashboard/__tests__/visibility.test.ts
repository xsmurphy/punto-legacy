import { describe, expect, it } from "vitest"

import {
  kpiDeltas,
  outletDelta,
  packGrid,
  showCustomers,
  showSalesByOutlet,
  showSplitDonut,
  showTopCategories,
  showTopHours,
  showTopItems,
  visibleAttentionRows,
  visibleDuePart,
  visibleInfoRows,
  visibleNowTiles,
  type AttentionRow,
  type NowTile,
} from "@/lib/dashboard/visibility"
import type {
  CustomersWidget,
  IncomeOutcomeStatsWidget,
  InfoWidget,
  PaymentStatusWidget,
  PeriodStats,
  SalesByOutletRow,
} from "@/hooks/use-dashboard-widget"

const pay = (p: Partial<PaymentStatusWidget>): PaymentStatusWidget => ({
  contado: 0, credito: 0, cobrado: 0, porcobrar: 0,
  contadoCount: 0, creditoCount: 0, cobradoCount: 0, porcobrarCount: 0,
  ...p,
})

describe("Requiere atención", () => {
  const row = (key: string, count: number): AttentionRow =>
    ({ key, count, amount: null, href: "/x" }) as AttentionRow

  it("sin datos o sin filas no hay nada que pintar (la card no existe)", () => {
    expect(visibleAttentionRows(undefined)).toEqual([])
    expect(visibleAttentionRows({ rows: [] })).toEqual([])
  })

  it("descarta ceros y claves que el front no conoce", () => {
    const out = visibleAttentionRows({
      rows: [row("stock", 3), row("margin", 0), row("futura", 9), row("attendance", 1)],
    })
    expect(out.map((r) => r.key)).toEqual(["stock", "attendance"])
  })
})

describe("donuts", () => {
  it("tipo de venta: solo con contado Y crédito", () => {
    expect(showSplitDonut(undefined, "sale-type")).toBe(false)
    expect(showSplitDonut(pay({ contado: 100 }), "sale-type")).toBe(false)
    expect(showSplitDonut(pay({ contado: 100, credito: 50 }), "sale-type")).toBe(true)
  })

  it("cobranza: solo con cobrado Y por cobrar", () => {
    expect(showSplitDonut(pay({ contado: 100 }), "receivables")).toBe(false)
    expect(showSplitDonut(pay({ credito: 50, porcobrar: 50 }), "receivables")).toBe(false)
    expect(showSplitDonut(pay({ credito: 50, cobrado: 20, porcobrar: 30 }), "receivables")).toBe(true)
  })
})

describe("rankings", () => {
  it("artículos y horas: con al menos una fila", () => {
    expect(showTopItems(undefined)).toBe(false)
    expect(showTopItems([])).toBe(false)
    expect(showTopItems([{ name: "a", count: 1, total: 1 }])).toBe(true)
    expect(showTopHours({ hour: [], total: [] })).toBe(false)
    expect(showTopHours({ hour: ["10:00 Ventas"], total: [3] })).toBe(true)
  })

  it("categorías: una sola (todo 'Sin categoría') no informa", () => {
    expect(showTopCategories([{ title: "Sin categoría", total: 10 }])).toBe(false)
    expect(showTopCategories([{ title: "A", total: 10 }, { title: "B", total: 2 }])).toBe(true)
  })
})

describe("clientes", () => {
  it("solo con clientes identificados en el período", () => {
    const c = (totalPeriod: number): CustomersWidget =>
      ({ total: 40, totalPeriod, new: 0, old: 0, returnRate: 0 })
    expect(showCustomers(undefined)).toBe(false)
    expect(showCustomers(c(0))).toBe(false)
    expect(showCustomers(c(3))).toBe(true)
  })
})

describe("información general", () => {
  const stats = (count: number) => ({ count, customerAverage: 10 }) as IncomeOutcomeStatsWidget
  const info = (p: Partial<InfoWidget>) => ({ giftCardsCount: 0, openDrawersCount: 0, ...p }) as InfoWidget

  it("comercio sin ventas en el período y sin gift cards → ninguna fila", () => {
    expect(visibleInfoRows(stats(0), info({}))).toEqual([])
  })

  it("cajas abiertas ya no es fila de acá: se mudó a 'Ahora' (un número, una vez)", () => {
    expect(visibleInfoRows(stats(5), info({ usesDrawers: true, openDrawersCount: 3 }))).toEqual(["ticket"])
  })

  it("gift cards solo con alguna vigente", () => {
    expect(visibleInfoRows(stats(0), info({ giftCardsCount: 2 }))).toEqual(["giftCards"])
  })
})

describe("Ahora", () => {
  const due = (count: number, overdue = 0) => ({ count, overdue, amount: count * 10, next: null, href: "/x" })
  const all: NowTile[] = [
    { key: "orders", href: "/o", active: 3, late: 1, lateMinutes: 20 },
    { key: "spaces", href: "/s", total: 8, free: 8, occupied: 0, billRequested: 0 },
    { key: "drawers", href: "/d", count: 1, rows: [{ drawerId: "1", registerName: "Caja", outletName: "", operator: "", openedAt: "" }] },
    { key: "staff", href: "/p", count: 2, people: [] },
    { key: "agenda", href: "/a", count: 1, next: [] },
    { key: "dues", checks: due(1), payables: null },
  ]

  it("sin datos o sin filas no hay bloque", () => {
    expect(visibleNowTiles(undefined)).toEqual([])
    expect(visibleNowTiles({ tiles: [] })).toEqual([])
  })

  it("pasa lo que el backend manda con dato, en su orden", () => {
    expect(visibleNowTiles({ tiles: all }).map((t) => t.key)).toEqual([
      "orders", "spaces", "drawers", "staff", "agenda", "dues",
    ])
  })

  it("descarta ceros y claves que el front no conoce", () => {
    const zeros = [
      { key: "orders", href: "/o", active: 0, late: 0, lateMinutes: 20 },
      { key: "spaces", href: "/s", total: 0, free: 0, occupied: 0, billRequested: 0 },
      { key: "drawers", href: "/d", count: 0, rows: [] },
      { key: "staff", href: "/p", count: 0, people: [] },
      { key: "agenda", href: "/a", count: 0, next: [] },
      { key: "dues", checks: due(0), payables: null },
      { key: "reservas", count: 4 },
    ] as unknown as NowTile[]
    expect(visibleNowTiles({ tiles: zeros })).toEqual([])
  })

  it("espacios todos libres SÍ se muestra (hay espacios cargados)", () => {
    expect(visibleNowTiles({ tiles: [all[1]] })).toHaveLength(1)
  })

  it("vencimientos: una parte con solo vencidos cuenta; en cero no", () => {
    expect(visibleDuePart(due(0, 2))).not.toBeNull()
    expect(visibleDuePart(due(0, 0))).toBeNull()
    expect(visibleDuePart(null)).toBeNull()
  })
})

describe("comparativa con el período anterior", () => {
  const p = (x: Partial<PeriodStats>): PeriodStats => ({
    total: 0, expenses: 0, revenue: 0, margin: 100, count: 0, customerAverage: 0, ...x,
  })
  const withPrev = (curr: Partial<PeriodStats>, prev: Partial<PeriodStats> | null) =>
    ({ ...p(curr), previous: prev === null ? null : p(prev) }) as IncomeOutcomeStatsWidget

  it("sin período anterior con datos → ningún delta", () => {
    expect(kpiDeltas(withPrev({ total: 100, count: 1 }, null))).toEqual({})
    expect(kpiDeltas({ ...p({ total: 100 }) } as IncomeOutcomeStatsWidget)).toEqual({})
    expect(kpiDeltas(undefined)).toEqual({})
  })

  it("variación relativa de ingresos y ventas", () => {
    const d = kpiDeltas(withPrev({ total: 150, count: 3 }, { total: 100, count: 2 }))
    expect(d.total).toEqual({ pct: 50, higherIsBetter: true })
    expect(d.count).toEqual({ pct: 50, higherIsBetter: true })
  })

  it("egresos: subir es malo", () => {
    const d = kpiDeltas(withPrev({ total: 10, expenses: 120 }, { total: 10, expenses: 100 }))
    expect(d.expenses).toEqual({ pct: 20, higherIsBetter: false })
  })

  it("anterior en cero para esa cifra → sin delta (no 'infinito')", () => {
    const d = kpiDeltas(withPrev({ total: 100, expenses: 50 }, { total: 100, expenses: 0 }))
    expect(d.expenses).toBeUndefined()
  })

  it("ticket promedio solo con ventas en los dos períodos", () => {
    expect(kpiDeltas(withPrev({ count: 0, customerAverage: 0 }, { count: 2, customerAverage: 50 })).customerAverage)
      .toBeUndefined()
    expect(kpiDeltas(withPrev({ count: 2, customerAverage: 60 }, { count: 2, customerAverage: 50 })).customerAverage?.pct)
      .toBeCloseTo(20)
  })

  it("margen en PUNTOS y solo medido (ingresos y egresos en los dos)", () => {
    const d = kpiDeltas(
      withPrev({ total: 100, expenses: 56, margin: 44 }, { total: 100, expenses: 60, margin: 40 }),
    )
    expect(d.margin).toEqual({ pct: 4, kind: "points" })
    // Sin egresos el backend informa 100%: no es un margen medido.
    expect(kpiDeltas(withPrev({ total: 100, expenses: 0, margin: 100 }, { total: 100, expenses: 60, margin: 40 })).margin)
      .toBeUndefined()
  })
})

describe("ventas por sucursal", () => {
  const row = (total: number, previous: number | null = null): SalesByOutletRow =>
    ({ outletId: String(total), name: "S", total, share: 50, previous })

  it("hacen falta dos sucursales vendiendo", () => {
    expect(showSalesByOutlet(undefined)).toBe(false)
    expect(showSalesByOutlet([row(100)])).toBe(false)
    expect(showSalesByOutlet([row(100), row(0)])).toBe(false)
    expect(showSalesByOutlet([row(100), row(50)])).toBe(true)
  })

  it("delta por sucursal solo con base", () => {
    expect(outletDelta(row(100, null))).toBeUndefined()
    expect(outletDelta(row(100, 0))).toBeUndefined()
    expect(outletDelta(row(150, 100))).toEqual({ pct: 50 })
  })
})

describe("packGrid (sin huecos)", () => {
  const h = (key: string) => ({ key, full: false })
  const f = (key: string) => ({ key, full: true })
  const shape = (bs: { key: string; full: boolean }[]) => bs.map((b) => `${b.key}${b.full ? "*" : ""}`)

  it("pares de media fila quedan como están", () => {
    expect(shape(packGrid([h("a"), h("b"), f("t"), h("c"), h("d")]))).toEqual(["a", "b", "t*", "c", "d"])
  })

  it("una media fila sola antes de una entera sube a la próxima media fila", () => {
    expect(shape(packGrid([h("a"), f("t"), h("c"), h("d")]))).toEqual(["a", "c", "t*", "d*"])
  })

  it("una media fila sin pareja posible ocupa la fila entera", () => {
    expect(shape(packGrid([h("a"), f("t")]))).toEqual(["a*", "t*"])
    expect(shape(packGrid([f("t"), h("c")]))).toEqual(["t*", "c*"])
  })

  it("nada visible → nada", () => {
    expect(packGrid([])).toEqual([])
  })
})
