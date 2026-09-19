import { describe, expect, it } from "vitest"

import {
  packGrid,
  showCustomers,
  showSplitDonut,
  showTopCategories,
  showTopHours,
  showTopItems,
  visibleAttentionRows,
  visibleInfoRows,
  type AttentionRow,
} from "@/lib/dashboard/visibility"
import type {
  CustomersWidget,
  IncomeOutcomeStatsWidget,
  InfoWidget,
  PaymentStatusWidget,
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

  it("comercio sin ventas en el período, sin caja y sin gift cards → ninguna fila", () => {
    expect(visibleInfoRows(stats(0), info({}))).toEqual([])
  })

  it("cajas abiertas en 0 se muestra si el comercio usa caja", () => {
    expect(visibleInfoRows(stats(5), info({ usesDrawers: true, openDrawersCount: 0 }))).toEqual([
      "ticket",
      "drawers",
    ])
  })

  it("gift cards solo con alguna vigente", () => {
    expect(visibleInfoRows(stats(0), info({ giftCardsCount: 2 }))).toEqual(["giftCards"])
  })

  it("backend viejo sin usesDrawers: no se asume que usa caja", () => {
    expect(visibleInfoRows(stats(1), info({ openDrawersCount: 1 }))).toEqual(["ticket"])
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
