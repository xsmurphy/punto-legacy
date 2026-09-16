import { describe, expect, it } from "vitest"

import type { Order } from "@/hooks/use-orders"
import {
  isScheduledAfter,
  orderScheduledDay,
  orderScheduledLabel,
} from "@/lib/orders/order-display"

/**
 * El corte por fecha de entrega (context/79).
 *
 * Lo que se fija acá es UN día, no un instante: una orden es "para el viernes"
 * y el KDS tiene que dejarla afuera el jueves entero y mostrarla el viernes
 * entero. El caso que más importa es el timestamp con offset: Postgres lo
 * renderiza en la zona del COMERCIO, así que los componentes de pared que
 * llegan YA son el día del comercio — re-convertirlos por la zona del
 * dispositivo correría el corte un día para cualquier tablet fuera de esa
 * zona, que es exactamente el bug que esta función existe para no tener.
 */
function order(scheduledFor: string | null): Order {
  return { scheduledFor } as Order
}

describe("orderScheduledDay", () => {
  it("una orden sin fecha es 'para ahora', no un día", () => {
    expect(orderScheduledDay(order(null))).toBeNull()
  })

  it("lee el día de pared del comercio, ignorando el offset del timestamp", () => {
    // Medianoche del 19 en -03. Convertido a UTC sería el 19 a las 03:00, y
    // en una tablet en +09 sería el 19 a las 12:00: el día no se mueve porque
    // no se convierte nada.
    expect(orderScheduledDay(order("2026-09-19 00:00:00-03"))).toBe("2026-09-19")
  })

  it("el último minuto del día sigue siendo ese día", () => {
    expect(orderScheduledDay(order("2026-09-19 23:59:00-03"))).toBe("2026-09-19")
  })
})

describe("isScheduledAfter — la cocina no ve el futuro (D3)", () => {
  it("deja afuera la orden de mañana", () => {
    expect(isScheduledAfter(order("2026-09-19 00:00:00-03"), "2026-09-18")).toBe(true)
  })

  it("muestra la orden del día, a cualquier hora", () => {
    expect(isScheduledAfter(order("2026-09-19 00:00:00-03"), "2026-09-19")).toBe(false)
    expect(isScheduledAfter(order("2026-09-19 20:30:00-03"), "2026-09-19")).toBe(false)
  })

  it("una orden VENCIDA no producida sigue en la cola — no desaparece sola", () => {
    expect(isScheduledAfter(order("2026-09-17 00:00:00-03"), "2026-09-18")).toBe(false)
  })

  it("las órdenes sin fecha ('para ahora') nunca son futuro", () => {
    expect(isScheduledAfter(order(null), "2026-09-18")).toBe(false)
  })

  it("cruza el fin de mes y de año sin reordenar mal", () => {
    expect(isScheduledAfter(order("2027-01-01 00:00:00-03"), "2026-12-31")).toBe(true)
    expect(isScheduledAfter(order("2026-12-31 00:00:00-03"), "2027-01-01")).toBe(false)
  })
})

describe("orderScheduledLabel", () => {
  it("no inventa un 'Inmediata' para la orden sin fecha", () => {
    expect(orderScheduledLabel(order(null))).toBeNull()
  })

  it("formatea con el helper del proyecto, no con el ISO crudo", () => {
    expect(orderScheduledLabel(order("2026-09-19 00:00:00-03"))).toBe("19 sep 2026")
  })
})
