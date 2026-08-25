/**
 * Cambiar de sucursal o de caja tiene que dejar el cliente sin nada del
 * contexto anterior (owner 2026-08-24).
 *
 * Lo que se verifica acá no se ve mirando la pantalla: que el descarte alcance
 * TODO lo que cuelga de la terna `outlet + register`, no solo las líneas del
 * carrito. Una regresión —alguien agrega un campo al carrito y el reset no lo
 * cubre— se manifestaría como una venta emitida con el cliente, el descuento o
 * la mesa de otra sucursal, que es justo el tipo de bug que no se nota hasta
 * que sale impreso.
 *
 * Se ejercita el reset real contra los stores reales; lo único falseado es el
 * `QueryClient` (solo interesa QUÉ caches se invalidan, no la mecánica de
 * react-query) y `localStorage`, que el store de hotkeys necesita para
 * construirse bajo `environment: "node"`.
 */

import { beforeEach, describe, expect, it, vi } from "vitest"
import "fake-indexeddb/auto"

/** localStorage mínimo en memoria — `persist` lo resuelve al importar el store. */
function installLocalStorage() {
  const map = new Map<string, string>()
  const fake = {
    getItem: (k: string) => map.get(k) ?? null,
    setItem: (k: string, v: string) => void map.set(k, v),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => Array.from(map.keys())[i] ?? null,
    get length() {
      return map.size
    },
  }
  vi.stubGlobal("localStorage", fake)
}

installLocalStorage()

const { resetContextScopedState, hasContextScopedWork } = await import(
  "@/lib/pos/context-reset"
)
const { useCartStore } = await import("@/lib/cart/store")
const { useHotkeysStore } = await import("@/lib/hotkeys/store")
const { useAddonPickerStore } = await import("@/lib/cart/addon-picker-store")
const { useGiftcardIssueStore } = await import("@/lib/cart/giftcard-issue-store")
const { useSpaceSettlementStore } = await import("@/lib/spaces/settlement-store")
const { enqueue, peekAll } = await import("@/lib/pos/offline-queue")
const { enqueueOp, peekAllOps } = await import("@/lib/pos/pending-ops")

/** QueryClient de mentira: solo registra las keys invalidadas. */
function fakeQueryClient() {
  const invalidated: unknown[] = []
  return {
    client: { invalidateQueries: (a: { queryKey: unknown }) => void invalidated.push(a.queryKey) },
    invalidated,
  }
}

/** Deja los stores como si la caja estuviera a mitad de una venta. */
function seedDirtyContext() {
  const cart = useCartStore.getState()
  cart.clear()
  cart.addLines([{ itemId: "item-de-sucursal-A", name: "Producto", qty: 1, unitPrice: 10_000 }])
  cart.setCustomer({
    id: "cli-1",
    name: "Cliente de la sucursal A",
    phone: null,
    tin: null,
    storeCredit: 0,
    isCreditable: false,
  })
  cart.setPosMode("cotizacion")
  cart.setSaleDiscount(10, "percent")
  cart.setNote("nota de la caja vieja")

  useHotkeysStore.getState().hydrate([
    { itemId: "item-de-sucursal-A", position: 3, color: "", isCategory: false },
  ])
  useHotkeysStore.getState().setEditing(true)

  useSpaceSettlementStore
    .getState()
    .setSettlingSpace({ sessionId: "ses-1", spaceName: "Mesa 4" })
  useSpaceSettlementStore
    .getState()
    .setSplitTarget({ sessionId: "ses-1", spaceName: "Mesa 4" })
}

beforeEach(() => {
  useCartStore.getState().clear()
  useHotkeysStore.getState().reset()
  useAddonPickerStore.getState().close()
  useGiftcardIssueStore.getState().close()
  useSpaceSettlementStore.getState().setSplitTarget(null)
  useSpaceSettlementStore.getState().setSettlingSpace(null)
})

describe("hasContextScopedWork", () => {
  it("con el carrito vacío no hay nada que confirmar", () => {
    expect(hasContextScopedWork()).toBe(false)
  })

  it("una línea cargada ya es trabajo que el cajero perdería", () => {
    seedDirtyContext()
    expect(hasContextScopedWork()).toBe(true)
  })
})

describe("resetContextScopedState", () => {
  it("vacía la venta entera, no solo las líneas", () => {
    seedDirtyContext()
    const { client } = fakeQueryClient()

    resetContextScopedState(client as never)

    const cart = useCartStore.getState()
    expect(cart.lines).toEqual([])
    // Todo esto describía a la sucursal anterior y no puede sobrevivir.
    expect(cart.customer).toBeNull()
    expect(cart.posMode).toBe("venta")
    expect(cart.saleDiscount).toBeNull()
    expect(cart.note).toBeNull()
  })

  it("suelta la grilla de hotkeys y sale del modo edición", () => {
    // Si la grilla de la caja vieja sobreviviera y el cajero tocara "Listo",
    // guardaría los hotkeys de una caja encima de la otra.
    seedDirtyContext()
    const { client } = fakeQueryClient()

    resetContextScopedState(client as never)

    expect(useHotkeysStore.getState().hotkeys).toEqual([])
    expect(useHotkeysStore.getState().editing).toBe(false)
  })

  it("suelta la mesa que se estaba cobrando", () => {
    seedDirtyContext()
    const { client } = fakeQueryClient()

    resetContextScopedState(client as never)

    expect(useSpaceSettlementStore.getState().settlingSpace).toBeNull()
    expect(useSpaceSettlementStore.getState().splitTarget).toBeNull()
  })

  // EL invariante crítico de todo este cambio. Las dos colas guardan cosas ya
  // EMITIDAS o ya decididas por el cajero, selladas con el `registerId` de su
  // caja: el cerco de `pending-ops-sync` impide que se apliquen sobre otra, así
  // que mudarse con la cola llena no corrompe nada. Vaciarlas acá, en cambio,
  // sería PERDER ventas que el cliente ya se llevó. Si alguien alguna vez
  // agrega un `clear()` de colas al reset "para dejar la caja limpia", este
  // test es lo único que lo frena.
  it("NO toca la cola de ventas offline ni la de operaciones", async () => {
    await enqueue({
      clientTempId: "venta-emitida-en-sucursal-A",
      invoiceNo: 41,
      sale: { total: 10_000 } as never,
    })
    // Un CIERRE DE CAJA sin enviar: lo más caro que puede haber en la cola.
    await enqueueOp({
      kind: "drawerClose",
      stream: "drawer",
      registerId: "reg-de-sucursal-A",
      payload: { counted: 250_000 },
      label: "Cierre de caja",
    })
    const { client } = fakeQueryClient()

    resetContextScopedState(client as never)

    const ventas = await peekAll()
    expect(ventas.map((v) => v.clientTempId)).toEqual(["venta-emitida-en-sucursal-A"])
    const ops = await peekAllOps()
    expect(ops).toHaveLength(1)
    expect(ops[0]?.registerId).toBe("reg-de-sucursal-A")
  })

  it("invalida las caches por-sucursal que no llevan el outlet en la key", () => {
    const { client, invalidated } = fakeQueryClient()

    resetContextScopedState(client as never)

    // Las demás queries del POS ya van keyeadas por registerId/outletId y se
    // re-piden solas; estas resuelven el outlet server-side y quedarían
    // mostrando la sucursal vieja.
    expect(invalidated).toContainEqual(["parked-sales"])
    expect(invalidated).toContainEqual(["pos-space-sectors"])
    expect(invalidated).toContainEqual(["pos-spaces"])
    // Las órdenes activas son el caso grave: con la cache vieja, tocar una
    // orden la cobra en la caja nueva.
    expect(invalidated).toContainEqual(["orders", "active"])
    expect(invalidated).toContainEqual(["pos-transactions"])
  })
})
