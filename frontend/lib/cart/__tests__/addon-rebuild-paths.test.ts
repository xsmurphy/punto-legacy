/**
 * Los add-ons vuelven al carrito por TODOS los caminos de cobro (context/41).
 *
 * El agujero que este test fija: `rebuildSelectionsFromOrder` existía desde el
 * 2026-08-23 pero solo la usaba `loadFromOrder`. Cobrar una MESA
 * (`loadFromSession`) y cobrar una PARTE de la mesa (`buildItemsLines`)
 * reconstruían el carrito sin `selections`, así que
 * `SaleService::expandAddonSelections` no corría: el add-on no generaba su
 * `itemSold`, no descontaba stock y no salía indentado en el ticket. La plata
 * salía bien —el recargo ya venía adentro del precio del padre— y por eso el
 * agujero era invisible: el queso extra se regalaba del inventario.
 *
 * Lo que se verifica acá es la mitad de FRONT de la cadena: que la línea que
 * sale hacia `/v1/sales` lleve `selections` con la qty POR UNIDAD del padre y
 * el precio correcto. La mitad de BACK (selections → `itemSold` hija + stock +
 * ticket) es `expandAddonSelections`, que ya corre igual para los tres caminos
 * porque el payload es el mismo.
 *
 * @vitest-environment node
 */

import { beforeEach, describe, expect, it } from "vitest"

import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { buildItemsLines, buildProportionalLines, sourcesFromOrders } from "@/lib/spaces/settlement-lines"
import type { Order, OrderItem } from "@/hooks/use-orders"
import type { PosItem } from "@/lib/types/pos-bootstrap"

// ── Fixtures ────────────────────────────────────────────────────────────────
//
// Una hamburguesa de 15.000 con queso extra de 2.000. La orden persiste el
// padre a 17.000 (recargo adentro) y la hija a price 0 con priceDelta 2.000.

const PARENT_ITEM = "item-hamburguesa"
const CHEESE_ITEM = "item-queso"
const OPTION_ID = "opt-queso"

const catalogItem: PosItem = {
  id: PARENT_ITEM,
  name: "Hamburguesa",
  price: 15000,
  taxId: "tax-10",
  taxIncluded: true,
  addonGroups: [
    {
      id: "grp-extras",
      name: "Extras",
      minSelect: 0,
      maxSelect: null,
      sort: 0,
      options: [
        {
          id: OPTION_ID,
          itemId: CHEESE_ITEM,
          itemName: "Queso extra",
          priceDelta: 2000,
          isDefault: false,
          isLocked: false,
          maxQty: 3,
          sort: 0,
        },
      ],
    },
  ],
} as unknown as PosItem

function orderItem(over: Partial<OrderItem> & { id: string }): OrderItem {
  return {
    itemId: PARENT_ITEM,
    name: "Hamburguesa",
    qty: 1,
    price: 17000,
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
    ...over,
  } as OrderItem
}

/** Padre + hija tal como las persiste la orden (mig 140). */
function parentWithCheese(
  parentId: string,
  parentQty: number,
  optQty = 1,
  over: Partial<OrderItem> = {},
): OrderItem[] {
  return [
    orderItem({ id: parentId, qty: parentQty, price: 15000 + 2000 * optQty, ...over }),
    orderItem({
      id: `${parentId}-child`,
      itemId: CHEESE_ITEM,
      name: "Queso extra",
      // La orden persiste childQty = optQty × parentQty.
      qty: optQty * parentQty,
      price: 0,
      parentOrderItemId: parentId,
      addonOptionId: OPTION_ID,
      priceDelta: 2000,
    }),
  ]
}

function order(id: string, items: OrderItem[], over: Partial<Order> = {}): Order {
  return {
    id,
    status: "open",
    customerId: null,
    note: null,
    items,
    ...over,
  } as unknown as Order
}

beforeEach(() => {
  useCartStore.getState().clear()
  useCatalogStore.setState({ items: [catalogItem], customers: [] })
})

describe("cobrar una MESA (loadFromSession)", () => {
  it("la línea sale con selections — sin ellas el add-on no descuenta stock", () => {
    useCartStore
      .getState()
      .loadFromSession("sess-1", "Mesa 4", [order("ord-1", parentWithCheese("p1", 1))])

    const lines = useCartStore.getState().lines
    expect(lines).toHaveLength(1)
    expect(lines[0].selections).toEqual([
      { optionId: OPTION_ID, qty: 1, itemId: CHEESE_ITEM, name: "Queso extra", priceDelta: 2000 },
    ])
    expect(lines[0].basePrice).toBe(15000)
    expect(lines[0].unitPrice).toBe(17000)
  })

  it("la qty del add-on vuelve a ser POR UNIDAD del padre — 2 hamburguesas no son 4 quesos", () => {
    useCartStore
      .getState()
      .loadFromSession("sess-1", "Mesa 4", [order("ord-1", parentWithCheese("p1", 2))])

    const line = useCartStore.getState().lines[0]
    expect(line.qty).toBe(2)
    // La orden persistió qty 2 en la hija; la selección lleva 1 y el server
    // vuelve a multiplicar por las unidades del padre.
    expect(line.selections?.[0].qty).toBe(1)
  })

  it("suma las órdenes de la mesa sin fusionar el mismo producto con add-ons distintos", () => {
    useCartStore.getState().loadFromSession("sess-1", "Mesa 4", [
      order("ord-1", parentWithCheese("p1", 1)),
      // Segunda ronda: la misma hamburguesa pero SIN queso.
      order("ord-2", [orderItem({ id: "p2", price: 15000 })]),
    ])

    const lines = useCartStore.getState().lines
    expect(lines).toHaveLength(2)
    expect(lines[0].selections).toHaveLength(1)
    expect(lines[1].selections).toBeUndefined()
  })

  it("dos rondas con EL MISMO add-on sí colapsan en una línea de qty 2", () => {
    useCartStore.getState().loadFromSession("sess-1", "Mesa 4", [
      order("ord-1", parentWithCheese("p1", 1)),
      order("ord-2", parentWithCheese("p2", 1)),
    ])

    const lines = useCartStore.getState().lines
    expect(lines).toHaveLength(1)
    expect(lines[0].qty).toBe(2)
    expect(lines[0].selections?.[0].qty).toBe(1)
  })

  it("una hija cancelada no descuenta stock: la línea vuelve sin ese add-on", () => {
    const items = parentWithCheese("p1", 1)
    items[1] = { ...items[1], status: "cancelled" } as OrderItem
    useCartStore.getState().loadFromSession("sess-1", "Mesa 4", [order("ord-1", items)])

    const line = useCartStore.getState().lines[0]
    expect(line.selections).toBeUndefined()
    // Fail-safe: sin selecciones el precio persistido queda tal cual — se
    // cobra como se venía cobrando, no se inventa un descuento.
    expect(line.unitPrice).toBe(17000)
  })

  it("opción que ya no existe en el catálogo → línea sin add-ons, nunca un 422 que deja la mesa incobrable", () => {
    useCatalogStore.setState({ items: [{ ...catalogItem, addonGroups: [] } as PosItem] })
    useCartStore
      .getState()
      .loadFromSession("sess-1", "Mesa 4", [order("ord-1", parentWithCheese("p1", 1))])

    const line = useCartStore.getState().lines[0]
    expect(line.selections).toBeUndefined()
    expect(line.unitPrice).toBe(17000)
  })
})

describe("cobrar UNA ORDEN suelta (loadFromOrder) — no se rompió al extraer el helper", () => {
  it("sigue reconstruyendo las selections", () => {
    useCartStore.getState().loadFromOrder(order("ord-1", parentWithCheese("p1", 2)))

    const line = useCartStore.getState().lines[0]
    expect(line.selections?.[0].optionId).toBe(OPTION_ID)
    expect(line.basePrice).toBe(15000)
    expect(line.unitPrice).toBe(17000)
  })

  it("re-cotiza el recargo con el catálogo VIGENTE, no con el congelado en la orden", () => {
    const raised = {
      ...catalogItem,
      addonGroups: [
        {
          ...catalogItem.addonGroups[0],
          options: [{ ...catalogItem.addonGroups[0].options[0], priceDelta: 3000 }],
        },
      ],
    } as PosItem
    useCatalogStore.setState({ items: [raised] })

    useCartStore.getState().loadFromOrder(order("ord-1", parentWithCheese("p1", 1)))

    const line = useCartStore.getState().lines[0]
    // base 15.000 (despejada con el delta CONGELADO) + 3.000 (delta vigente).
    expect(line.basePrice).toBe(15000)
    expect(line.unitPrice).toBe(18000)
    expect(line.selections?.[0].priceDelta).toBe(3000)
  })
})

describe("SPLIT de cuenta por ítems (buildItemsLines)", () => {
  it("la parte cobrada lleva sus selections — el add-on descuenta stock igual que en la mesa entera", () => {
    const sources = sourcesFromOrders([order("ord-1", parentWithCheese("p1", 1))])
    const lines = buildItemsLines(sources, ["p1"], [catalogItem])

    expect(lines).toHaveLength(1)
    expect(lines[0].selections?.[0]).toMatchObject({ optionId: OPTION_ID, qty: 1 })
  })

  it("las hijas NO son unidades cobrables: no entran al mapa de fuentes", () => {
    const sources = sourcesFromOrders([order("ord-1", parentWithCheese("p1", 1))])
    expect([...sources.keys()]).toEqual(["p1"])
    expect(sources.get("p1")?.children).toHaveLength(1)
  })

  it("ancla el precio al PERSISTIDO aunque el catálogo haya cambiado — la venta y el ledger no pueden diferir", () => {
    const raised = {
      ...catalogItem,
      addonGroups: [
        {
          ...catalogItem.addonGroups[0],
          options: [{ ...catalogItem.addonGroups[0].options[0], priceDelta: 3000 }],
        },
      ],
    } as PosItem
    const sources = sourcesFromOrders([order("ord-1", parentWithCheese("p1", 1))])
    const lines = buildItemsLines(sources, ["p1"], [raised])

    // El backend calcula el pago del ledger desde el precio persistido
    // (17.000): la caja tiene que cobrar exactamente eso.
    expect(lines[0].unitPrice).toBe(17000)
    // La base se despeja con el recargo VIGENTE, que es el que el server le
    // va a restar al padre — padre + hija = 17.000.
    expect(lines[0].basePrice).toBe(14000)
  })

  it("si el recargo vigente no entra en el precio persistido, la línea va sin add-ons", () => {
    const absurd = {
      ...catalogItem,
      addonGroups: [
        {
          ...catalogItem.addonGroups[0],
          options: [{ ...catalogItem.addonGroups[0].options[0], priceDelta: 99000 }],
        },
      ],
    } as PosItem
    const sources = sourcesFromOrders([order("ord-1", parentWithCheese("p1", 1))])
    const lines = buildItemsLines(sources, ["p1"], [absurd])

    expect(lines[0].selections).toBeUndefined()
    expect(lines[0].unitPrice).toBe(17000)
  })
})

describe("SPLIT por monto / partes iguales (buildProportionalLines)", () => {
  it("NO reconstruye selections — una fracción de ítem no descuenta una fracción de add-on", () => {
    const sources = sourcesFromOrders([order("ord-1", parentWithCheese("p1", 1))])
    const lines = buildProportionalLines([sources.get("p1")!], 8500, 0)

    expect(lines).toHaveLength(1)
    expect(lines[0].selections).toBeUndefined()
    expect(lines[0].qty).toBeLessThan(1)
  })
})
