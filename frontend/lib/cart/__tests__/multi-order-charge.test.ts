/**
 * UNA venta / UNA factura por VARIAS órdenes sueltas (owner 2026-09-17).
 *
 * El síntoma reportado: "cuando le doy en cobrar una orden me elimina lo que
 * estaba en el carrito y carga lo nuevo". `loadFromOrder` pisaba el carrito con
 * `...initialState` y guardaba UN `orderParentId`, así que cobrar una segunda
 * orden borraba la primera y solo se facturaba la última.
 *
 * El backend ya lo soportaba —`order_transaction_link` (mig 115) es N→N y el
 * cobro de un ESPACIO ya marca varias órdenes con la misma transacción—, así
 * que lo que se verifica acá es el lado del carrito: cuándo se agrega y cuándo
 * se reemplaza. CÓMO se reconstruyen las líneas (add-ons incluidos) no se toca
 * y lo cubre `addon-rebuild-paths.test.ts`.
 *
 * @vitest-environment node
 */

import { beforeEach, describe, expect, it } from "vitest"

import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { chargeTargetFor } from "@/lib/pos/pending-charges"
import type { Order, OrderItem } from "@/hooks/use-orders"
import type { PosCustomer, PosItem } from "@/lib/types/pos-bootstrap"

// ── Fixtures ────────────────────────────────────────────────────────────────

const ITEM_A = "item-empanada"
const ITEM_B = "item-gaseosa"

const catalogItems = [
  { id: ITEM_A, name: "Empanada", price: 5000, taxId: "tax-10", taxIncluded: true, addonGroups: [] },
  { id: ITEM_B, name: "Gaseosa", price: 8000, taxId: "tax-10", taxIncluded: true, addonGroups: [] },
] as unknown as PosItem[]

const ANA = { id: "cli-ana", name: "Ana" } as unknown as PosCustomer
const BETO = { id: "cli-beto", name: "Beto" } as unknown as PosCustomer

function orderItem(id: string, itemId: string, qty = 1, price = 5000): OrderItem {
  return {
    id,
    itemId,
    name: itemId === ITEM_A ? "Empanada" : "Gaseosa",
    qty,
    price,
    note: null,
    tags: null,
    stationId: null,
    stationName: null,
    status: "pending",
    course: 0,
    createdAt: null,
    readyAt: null,
    deliveredAt: null,
    parentOrderItemId: null,
    addonOptionId: null,
    priceDelta: null,
  } as unknown as OrderItem
}

function order(id: string, items: OrderItem[], over: Partial<Order> = {}): Order {
  return {
    id,
    status: "open",
    customerId: null,
    note: null,
    orderNumber: 1,
    items,
    ...over,
  } as unknown as Order
}

const ORD_1 = order("ord-1", [orderItem("i1", ITEM_A, 2)])
const ORD_2 = order("ord-2", [orderItem("i2", ITEM_B, 1, 8000)])

beforeEach(() => {
  useCartStore.getState().clear()
  useCatalogStore.setState({ items: catalogItems, customers: [ANA, BETO] })
})

describe("carrito vacío — el comportamiento de siempre", () => {
  it("carga limpio y deja UNA orden de origen", () => {
    const result = useCartStore.getState().loadFromOrder(ORD_1)

    expect(result).toEqual({ kind: "loaded" })
    const s = useCartStore.getState()
    expect(s.lines).toHaveLength(1)
    expect(s.lines[0].qty).toBe(2)
    expect(s.orderParentIds).toEqual(["ord-1"])
    expect(s.posMode).toBe("venta")
  })
})

describe("acumular una segunda orden", () => {
  it("agrega las líneas en vez de pisarlas y suma el id de origen", () => {
    useCartStore.getState().loadFromOrder(ORD_1)
    const result = useCartStore.getState().loadFromOrder(ORD_2)

    expect(result).toEqual({ kind: "appended", orderCount: 2, customerConflict: false })
    const s = useCartStore.getState()
    expect(s.lines.map((l) => l.itemId)).toEqual([ITEM_A, ITEM_B])
    expect(s.orderParentIds).toEqual(["ord-1", "ord-2"])
  })

  it("la MISMA orden dos veces no duplica nada", () => {
    useCartStore.getState().loadFromOrder(ORD_1)
    useCartStore.getState().loadFromOrder(ORD_2)
    const before = useCartStore.getState()
    const lines = before.lines

    const result = useCartStore.getState().loadFromOrder(ORD_1)

    expect(result).toEqual({ kind: "already-added" })
    // Misma referencia: no se recrearon las líneas ni se tocó el estado.
    expect(useCartStore.getState().lines).toBe(lines)
    expect(useCartStore.getState().orderParentIds).toEqual(["ord-1", "ord-2"])
  })

  it("no mergea contra una línea del carrito con precio editado a mano", () => {
    useCartStore.getState().loadFromOrder(ORD_1)
    const lineId = useCartStore.getState().lines[0].lineId
    useCartStore.getState().setLinePrice(lineId, 1000)

    // Segunda orden con EL MISMO ítem: si mergeara, las 2 unidades nuevas se
    // cobrarían al precio manual de 1.000.
    useCartStore.getState().loadFromOrder(order("ord-3", [orderItem("i3", ITEM_A, 2)]))

    const lines = useCartStore.getState().lines
    expect(lines).toHaveLength(2)
    expect(lines[0].unitPrice).toBe(1000)
    expect(lines[1].unitPrice).toBe(5000)
  })

  it("no resetea lo que el cajero ya eligió para el cobro", () => {
    useCartStore.getState().loadFromOrder(ORD_1)
    useCartStore.getState().toggleCredito()
    useCartStore.getState().toggleInterno()
    useCartStore.getState().toggleIva()

    useCartStore.getState().loadFromOrder(ORD_2)

    const s = useCartStore.getState()
    expect(s.credito).toBe(true)
    expect(s.interno).toBe(true)
    expect(s.ivaRemoved).toBe(true)
  })
})

describe("cliente — una factura tiene UN receptor", () => {
  it("el que ya está en el carrito gana y el conflicto se avisa", () => {
    useCartStore.getState().loadFromOrder(order("ord-a", [orderItem("i1", ITEM_A)], { customerId: ANA.id }))
    const result = useCartStore
      .getState()
      .loadFromOrder(order("ord-b", [orderItem("i2", ITEM_B, 1, 8000)], { customerId: BETO.id }))

    expect(result).toMatchObject({ kind: "appended", customerConflict: true })
    expect(useCartStore.getState().customer?.id).toBe(ANA.id)
  })

  it("si el carrito no tenía cliente, toma el de la orden que se agrega", () => {
    useCartStore.getState().loadFromOrder(ORD_1)
    const result = useCartStore
      .getState()
      .loadFromOrder(order("ord-b", [orderItem("i2", ITEM_B, 1, 8000)], { customerId: BETO.id }))

    expect(result).toMatchObject({ kind: "appended", customerConflict: false })
    expect(useCartStore.getState().customer?.id).toBe(BETO.id)
  })

  it("el mismo cliente en las dos órdenes no es conflicto", () => {
    useCartStore.getState().loadFromOrder(order("ord-a", [orderItem("i1", ITEM_A)], { customerId: ANA.id }))
    const result = useCartStore
      .getState()
      .loadFromOrder(order("ord-b", [orderItem("i2", ITEM_B, 1, 8000)], { customerId: ANA.id }))

    expect(result).toMatchObject({ customerConflict: false })
  })
})

describe("un espacio no se mezcla con una orden suelta", () => {
  it("reemplaza el carrito y limpia el rastro de la sesión", () => {
    useCartStore.getState().loadFromSession("sess-1", "Mesa 4", [ORD_1])
    expect(useCartStore.getState().sessionParentId).toBe("sess-1")

    const result = useCartStore.getState().loadFromOrder(ORD_2)

    expect(result).toEqual({ kind: "replaced" })
    const s = useCartStore.getState()
    // Son objetos de cobro mutuamente excluyentes: acumular dejaría las
    // órdenes del espacio sin marcar y la sesión sin cerrar.
    expect(s.sessionParentId).toBeNull()
    expect(s.sessionOrderIds).toEqual([])
    expect(s.orderParentIds).toEqual(["ord-2"])
    expect(s.lines).toHaveLength(1)
  })
})

describe("objeto del cobro pendiente", () => {
  it("viaja con TODAS las órdenes del carrito", () => {
    useCartStore.getState().loadFromOrder(ORD_1)
    useCartStore.getState().loadFromOrder(ORD_2)

    const { settlementIntent, sessionParentId, orderParentIds } = useCartStore.getState()
    expect(chargeTargetFor({ settlementIntent, sessionParentId, orderParentIds })).toEqual({
      kind: "order",
      orderIds: ["ord-1", "ord-2"],
    })
  })
})
