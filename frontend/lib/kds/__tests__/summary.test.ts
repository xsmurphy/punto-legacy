import { describe, expect, it } from "vitest"

import type { Order, OrderItem, OrderItemStatus, OrderStatus } from "@/hooks/use-orders"
import { summarizeOrders } from "@/lib/kds/summary"

/**
 * El consolidado del día (context/70).
 *
 * Lo que se fija acá son CUENTAS, que es lo único de esta vista que puede
 * estar mal sin que se note: un plato de menos en la fila se ve igual de bien
 * que uno de más, y quien arma bandejas le cree al número. Los casos que
 * importan son los que rompen la suma ingenua: la misma orden pidiendo el
 * mismo plato dos veces, las líneas que ya no se arman (cancelada, entregada,
 * orden terminal), y las opciones —que NO son platos y por lo tanto no pueden
 * sumar al total, pero sí tienen que contarse aparte—.
 */

let seq = 0

function item(partial: Partial<OrderItem> & { name: string; qty: number }): OrderItem {
  seq += 1
  return {
    id: partial.id ?? `it-${seq}`,
    itemId: partial.itemId ?? null,
    name: partial.name,
    qty: partial.qty,
    price: null,
    note: null,
    tags: null,
    stationId: partial.stationId ?? null,
    stationName: null,
    status: partial.status ?? "pending",
    course: 1,
    createdAt: null,
    readyAt: null,
    deliveredAt: null,
    parentOrderItemId: partial.parentOrderItemId ?? null,
    addonOptionId: null,
    priceDelta: null,
  }
}

function order(partial: Partial<Order> & { items: OrderItem[] }): Order {
  seq += 1
  return {
    id: partial.id ?? `ord-${seq}`,
    status: partial.status ?? ("sent" as OrderStatus),
    orderNumber: partial.orderNumber ?? null,
    customerName: partial.customerName ?? null,
    // El orden del detalle sale de `sentAt` (lo mismo que ordena el board).
    sentAt: partial.sentAt ?? "2026-09-17 12:00:00-03",
    createdAt: null,
    items: partial.items,
  } as Order
}

describe("summarizeOrders — agrupación por plato", () => {
  it("suma el mismo ítem del catálogo a través de varias órdenes", () => {
    const rows = summarizeOrders(
      [
        order({ items: [item({ itemId: "milanesa", name: "Milanesa", qty: 3 })] }),
        order({ items: [item({ itemId: "milanesa", name: "Milanesa", qty: 2 })] }),
      ],
      []
    )

    expect(rows).toHaveLength(1)
    expect(rows[0]).toMatchObject({ name: "Milanesa", pending: 5, ready: 0, total: 5 })
  })

  it("suma dos líneas del mismo plato DENTRO de una orden en una sola entrada del detalle", () => {
    const rows = summarizeOrders(
      [
        order({
          orderNumber: 42,
          items: [
            item({ itemId: "milanesa", name: "Milanesa", qty: 1 }),
            item({ itemId: "milanesa", name: "Milanesa", qty: 2 }),
          ],
        }),
      ],
      []
    )

    expect(rows[0].pending).toBe(3)
    expect(rows[0].orders).toHaveLength(1)
    expect(rows[0].orders[0]).toMatchObject({ orderNumber: 42, qty: 3, ready: false })
  })

  it("una línea libre agrupa por nombre y NO se mezcla con el ítem del catálogo", () => {
    const rows = summarizeOrders(
      [
        order({ items: [item({ name: "Tarta", qty: 1 })] }),
        order({ items: [item({ name: "Tarta", qty: 2 })] }),
        order({ items: [item({ itemId: "tarta-cat", name: "Tarta", qty: 4 })] }),
      ],
      []
    )

    expect(rows).toHaveLength(2)
    expect(rows.map((r) => r.pending).sort((a, b) => a - b)).toEqual([3, 4])
  })
})

describe("summarizeOrders — pendiente vs. listo", () => {
  it("separa lo que falta armar de lo ya armado", () => {
    const rows = summarizeOrders(
      [
        order({
          items: [
            item({ itemId: "mila", name: "Milanesa", qty: 2, status: "pending" }),
            item({ itemId: "mila", name: "Milanesa", qty: 1, status: "preparing" }),
            item({ itemId: "mila", name: "Milanesa", qty: 4, status: "ready" }),
          ],
        }),
      ],
      []
    )

    // `preparing` es trabajo sin terminar: cuenta como pendiente, no como listo.
    expect(rows[0]).toMatchObject({ pending: 3, ready: 4, total: 7 })
  })

  it("la orden con todo armado sigue en el resumen — es comida esperando salir", () => {
    const rows = summarizeOrders(
      [order({ status: "ready", items: [item({ itemId: "mila", name: "Milanesa", qty: 2, status: "ready" })] })],
      []
    )

    expect(rows[0]).toMatchObject({ pending: 0, ready: 2 })
    expect(rows[0].orders[0].ready).toBe(true)
  })

  it("una orden con parte armada no figura como lista en el detalle", () => {
    const rows = summarizeOrders(
      [
        order({
          items: [
            item({ itemId: "mila", name: "Milanesa", qty: 1, status: "ready" }),
            item({ itemId: "mila", name: "Milanesa", qty: 1, status: "pending" }),
          ],
        }),
      ],
      []
    )

    expect(rows[0].orders[0]).toMatchObject({ qty: 2, ready: false })
  })
})

describe("summarizeOrders — lo que NO se arma", () => {
  it.each<OrderItemStatus>(["cancelled", "delivered"])("ignora la línea %s", (status) => {
    const rows = summarizeOrders(
      [
        order({
          items: [
            item({ itemId: "mila", name: "Milanesa", qty: 5, status }),
            item({ itemId: "mila", name: "Milanesa", qty: 1, status: "pending" }),
          ],
        }),
      ],
      []
    )

    expect(rows[0].total).toBe(1)
  })

  it.each<OrderStatus>(["cancelled", "delivered", "closed"])("ignora la orden %s entera", (status) => {
    const rows = summarizeOrders(
      [order({ status, items: [item({ itemId: "mila", name: "Milanesa", qty: 9 })] })],
      []
    )

    expect(rows).toEqual([])
  })

  it("un plato sin ninguna línea viva no deja una fila en cero", () => {
    const rows = summarizeOrders(
      [order({ items: [item({ itemId: "mila", name: "Milanesa", qty: 3, status: "cancelled" })] })],
      []
    )

    expect(rows).toEqual([])
  })
})

describe("summarizeOrders — opciones (add-ons)", () => {
  it("cuenta las guarniciones por separado sin sumarlas al total del plato", () => {
    const mila1 = item({ itemId: "mila", name: "Milanesa", qty: 2, id: "p1" })
    const mila2 = item({ itemId: "mila", name: "Milanesa", qty: 1, id: "p2" })

    const rows = summarizeOrders(
      [
        order({
          items: [
            mila1,
            item({ name: "Arroz", qty: 2, parentOrderItemId: "p1" }),
            mila2,
            item({ name: "Puré", qty: 1, parentOrderItemId: "p2" }),
          ],
        }),
        order({
          items: [
            item({ itemId: "mila", name: "Milanesa", qty: 3, id: "p3" }),
            item({ name: "Arroz", qty: 3, parentOrderItemId: "p3" }),
          ],
        }),
      ],
      []
    )

    // El total del plato NO incluye las opciones: son el mismo plato, no platos más.
    expect(rows).toHaveLength(1)
    expect(rows[0]).toMatchObject({ name: "Milanesa", pending: 6 })
    // Y las opciones se leen de mayor a menor, que es el orden en que se arman.
    expect(rows[0].addons).toEqual([
      { name: "Arroz", qty: 5 },
      { name: "Puré", qty: 1 },
    ])
  })

  it("una opción de una línea cancelada no se cuenta", () => {
    const rows = summarizeOrders(
      [
        order({
          items: [
            item({ itemId: "mila", name: "Milanesa", qty: 2, id: "p1", status: "cancelled" }),
            item({ name: "Arroz", qty: 2, parentOrderItemId: "p1" }),
            item({ itemId: "mila", name: "Milanesa", qty: 1, id: "p2" }),
            item({ name: "Puré", qty: 1, parentOrderItemId: "p2" }),
          ],
        }),
      ],
      []
    )

    expect(rows[0].addons).toEqual([{ name: "Puré", qty: 1 }])
  })
})

describe("summarizeOrders — filtro de estaciones y orden de las filas", () => {
  it("con filtro solo cuenta los platos de esta pantalla", () => {
    const rows = summarizeOrders(
      [
        order({
          items: [
            item({ itemId: "mila", name: "Milanesa", qty: 2, stationId: "cocina" }),
            item({ itemId: "gaseosa", name: "Gaseosa", qty: 4, stationId: "barra" }),
          ],
        }),
      ],
      ["cocina"]
    )

    expect(rows).toHaveLength(1)
    expect(rows[0].name).toBe("Milanesa")
  })

  it("ordena por pendiente descendente y desempata por nombre", () => {
    const rows = summarizeOrders(
      [
        order({
          items: [
            item({ itemId: "a", name: "Pollo", qty: 2 }),
            item({ itemId: "b", name: "Milanesa", qty: 9 }),
            item({ itemId: "c", name: "Empanada", qty: 2 }),
          ],
        }),
      ],
      []
    )

    expect(rows.map((r) => r.name)).toEqual(["Milanesa", "Empanada", "Pollo"])
  })

  it("un plato ya armado cae al fondo aunque tenga más unidades", () => {
    const rows = summarizeOrders(
      [
        order({
          items: [
            item({ itemId: "a", name: "Milanesa", qty: 10, status: "ready" }),
            item({ itemId: "b", name: "Pollo", qty: 1, status: "pending" }),
          ],
        }),
      ],
      []
    )

    expect(rows.map((r) => r.name)).toEqual(["Pollo", "Milanesa"])
  })

  it("el detalle va en orden de llegada, no de carga", () => {
    const rows = summarizeOrders(
      [
        order({ orderNumber: 2, sentAt: "2026-09-17 12:30:00-03", items: [item({ itemId: "m", name: "Milanesa", qty: 1 })] }),
        order({ orderNumber: 1, sentAt: "2026-09-17 12:05:00-03", items: [item({ itemId: "m", name: "Milanesa", qty: 1 })] }),
      ],
      []
    )

    expect(rows[0].orders.map((o) => o.orderNumber)).toEqual([1, 2])
  })
})
