import { describe, expect, it } from "vitest"

import {
  DEFAULT_ORDER_STATUS_LABELS,
  normalizeOrderStatusLabels,
  orderStatusLabel,
  orderStatusLabelFor,
} from "@/lib/orders/order-status-labels"
import { eventTransitionLabel } from "@/lib/orders/order-event-label"
import type { OrderEvent } from "@/hooks/use-orders"

describe("orderStatusLabel", () => {
  it("sin nombres del comercio usa los de fábrica", () => {
    expect(orderStatusLabel("sent")).toBe("En espera")
    expect(orderStatusLabel("ready", {})).toBe("Listo")
    expect(orderStatusLabel("out_for_delivery", null)).toBe("En camino")
  })

  it("el nombre del comercio gana sobre el de fábrica", () => {
    expect(orderStatusLabel("in_progress", { in_progress: "En cocina" })).toBe("En cocina")
    expect(orderStatusLabel("sent", { in_progress: "En cocina" })).toBe("En espera")
  })

  it("un nombre vacío vuelve al de fábrica", () => {
    expect(orderStatusLabel("open", { open: "" })).toBe("Pendiente")
  })

  it("un código desconocido se devuelve tal cual", () => {
    expect(orderStatusLabel("pending")).toBe("pending")
  })
})

describe("orderStatusLabelFor", () => {
  it("una orden de envío lista usa el slot de envío", () => {
    const order = { status: "ready", fulfillment: "delivery" } as const
    expect(orderStatusLabelFor(order)).toBe(DEFAULT_ORDER_STATUS_LABELS.ready_delivery)
    expect(orderStatusLabelFor(order, { ready_delivery: "Esperando cadete", ready: "Para retirar" })).toBe(
      "Esperando cadete",
    )
  })

  it("el slot de envío vacío vuelve a su nombre de fábrica, no al de `ready`", () => {
    const order = { status: "ready", fulfillment: "delivery" } as const
    expect(orderStatusLabelFor(order, { ready: "Para retirar" })).toBe("Enviado")
  })

  it("una orden lista que no es de envío usa `ready`", () => {
    expect(orderStatusLabelFor({ status: "ready", fulfillment: "takeaway" }, { ready: "Para retirar" })).toBe(
      "Para retirar",
    )
    expect(orderStatusLabelFor({ status: "sent", fulfillment: "delivery" }, { sent: "En cola" })).toBe("En cola")
  })
})

describe("normalizeOrderStatusLabels", () => {
  it("descarta claves desconocidas, no-strings y vacíos", () => {
    expect(
      normalizeOrderStatusLabels({ sent: " En cola ", paid: "Pagada", ready: 3, open: "  ", ready_delivery: "Sale" }),
    ).toEqual({ sent: "En cola", ready_delivery: "Sale" })
  })

  it("tolera lo que no es un mapa (snapshot viejo, `[]` de PHP)", () => {
    expect(normalizeOrderStatusLabels(undefined)).toEqual({})
    expect(normalizeOrderStatusLabels([])).toEqual({})
    expect(normalizeOrderStatusLabels("x")).toEqual({})
  })
})

describe("eventTransitionLabel", () => {
  const ev = (over: Partial<OrderEvent>): OrderEvent =>
    ({ scope: "order", fromStatus: "sent", toStatus: "in_progress", ...over }) as OrderEvent

  it("los eventos de orden usan los nombres del comercio", () => {
    expect(eventTransitionLabel(ev({}), { in_progress: "En cocina" })).toBe("En espera → En cocina")
  })

  it("los eventos de ítem no se renombran", () => {
    expect(
      eventTransitionLabel(ev({ scope: "item", fromStatus: "pending", toStatus: "ready" }), { ready: "Para retirar" }),
    ).toBe("Pendiente → Listo")
  })
})
