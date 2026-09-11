import { describe, expect, it } from "vitest"

import type { OrderEvent } from "@/hooks/use-orders"
import { formatMinutes, orderStageDurations } from "@/lib/orders/order-stage-marks"

/**
 * Mismos casos que `api/tests/operations_report_test.php`: el detalle de una
 * orden tiene que decir lo mismo que el promedio del reporte le atribuye.
 */

function ev(
  scope: OrderEvent["scope"],
  from: string | null,
  to: string,
  hhmm: string,
): OrderEvent {
  return {
    scope,
    orderItemId: null,
    stationId: null,
    stationName: null,
    fromStatus: from as OrderEvent["fromStatus"],
    toStatus: to as OrderEvent["toStatus"],
    actorKind: "user",
    actorModule: null,
    reason: null,
    createdAt: `2026-03-02 ${hhmm}:00-03`,
  }
}

describe("orderStageDurations", () => {
  it("ciclo completo: cada etapa y el total", () => {
    const d = orderStageDurations(
      [
        ev("order", null, "sent", "10:00"),
        ev("order", "sent", "in_progress", "10:05"),
        ev("order", "in_progress", "ready", "10:20"),
        ev("order", "ready", "delivered", "10:25"),
      ],
      "2026-03-02 10:00:00-03",
    )
    expect(d).toEqual({ toProgress: 5, progressToReady: 15, readyToDelivered: 5, total: 25, skipped: false })
  })

  it("ignora los eventos de ítem aunque usen los mismos nombres de estado", () => {
    const d = orderStageDurations(
      [
        ev("order", null, "sent", "10:00"),
        ev("order", "sent", "in_progress", "10:05"),
        ev("item", "preparing", "ready", "10:18"),
        ev("order", "in_progress", "ready", "10:20"),
        ev("item", "ready", "delivered", "10:24"),
        ev("order", "ready", "delivered", "10:25"),
      ],
      null,
    )
    expect(d.readyToDelivered).toBe(5)
    expect(d.total).toBe(25)
  })

  it("salto enviada → entregada: sin etapas, con total, marcado como salteado", () => {
    const d = orderStageDurations(
      [ev("order", null, "sent", "10:30"), ev("order", "sent", "delivered", "11:30")],
      null,
    )
    expect(d).toEqual({ toProgress: null, progressToReady: null, readyToDelivered: null, total: 60, skipped: true })
  })

  it("re-trabajo: 'lista' es la ÚLTIMA vez — lo rehecho cuenta como proceso", () => {
    const d = orderStageDurations(
      [
        ev("order", null, "sent", "12:00"),
        ev("order", "sent", "in_progress", "12:10"),
        ev("order", "in_progress", "ready", "12:30"),
        ev("order", "ready", "in_progress", "12:35"),
        ev("order", "in_progress", "ready", "12:50"),
        ev("order", "ready", "delivered", "13:00"),
      ],
      null,
    )
    expect(d.progressToReady).toBe(40)
    expect(d.readyToDelivered).toBe(10)
  })

  it("salteó 'en proceso' y lo retomó: nunca una demora negativa", () => {
    const d = orderStageDurations(
      [
        ev("order", null, "sent", "11:00"),
        ev("order", "sent", "ready", "11:00"),
        ev("order", "ready", "in_progress", "11:05"),
        ev("order", "in_progress", "ready", "11:10"),
        ev("order", "ready", "delivered", "11:15"),
      ],
      null,
    )
    expect(d.progressToReady).toBe(5)
    expect(d.toProgress).toBe(5)
  })

  it("la cola se mide desde el ENVÍO, no desde la creación", () => {
    const d = orderStageDurations(
      [
        ev("order", null, "open", "18:20"),
        ev("order", "open", "sent", "18:30"),
        ev("order", "sent", "in_progress", "18:32"),
      ],
      "2026-03-02 18:20:00-03",
    )
    expect(d.toProgress).toBe(2)
    expect(d.total).toBeNull()
  })

  it("sin eventos: todo null, nunca cero", () => {
    expect(orderStageDurations([], "2026-03-02 10:00:00-03")).toEqual({
      toProgress: null,
      progressToReady: null,
      readyToDelivered: null,
      total: null,
      skipped: false,
    })
  })
})

describe("formatMinutes", () => {
  const comma = { thousand: "dot", decimal: "comma" } as never
  it("un decimal por debajo de la hora", () => {
    expect(formatMinutes(6.66, comma)).toMatch(/^6[,.]7 min$/)
  })
  it("horas y minutos por encima", () => {
    expect(formatMinutes(80, comma)).toBe("1 h 20 min")
    expect(formatMinutes(120, comma)).toBe("2 h")
    expect(formatMinutes(119.8, comma)).toBe("2 h")
  })
})
