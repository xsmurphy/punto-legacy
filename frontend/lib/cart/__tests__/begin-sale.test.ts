/**
 * `beginSale()` — el modo de la caja cuando se toma la acción de facturar.
 *
 * Lo que se fija acá no es aritmética: es QUIÉN cambia el modo. La acción la
 * llaman los call-sites que se comprometen a facturar (cobrar una comanda o
 * una mesa, facturar una cotización guardada), nunca el diálogo de cobro al
 * abrirse — abrirlo es reversible y el hotkey Enter lo dispara desde cualquier
 * modo, así que ponerlo ahí convertía un Enter+Esc accidental en pérdida de
 * modo y de los atributos de la orden. El test protege las dos mitades: que
 * facturar SÍ deje la caja en venta, y que al hacerlo no quede colgado nada
 * que pertenecía a la orden.
 *
 * @vitest-environment node
 */

import { beforeEach, describe, expect, it } from "vitest"

import { useCartStore } from "@/lib/cart/store"

describe("beginSale", () => {
  beforeEach(() => {
    useCartStore.getState().clear()
  })

  it("desde cotización deja la caja en modo venta — el modo con hotkeys y CTA de cobro", () => {
    useCartStore.getState().setPosMode("cotizacion")
    useCartStore.getState().beginSale()
    expect(useCartStore.getState().posMode).toBe("venta")
  })

  it("desde modo orden deja la caja en modo venta", () => {
    useCartStore.getState().setPosMode("orden")
    useCartStore.getState().beginSale()
    expect(useCartStore.getState().posMode).toBe("venta")
  })

  it("suelta los atributos de la orden: una venta de mostrador no tiene envío ni dirección", () => {
    useCartStore.getState().setPosMode("orden")
    useCartStore.getState().setFulfillment("delivery")
    useCartStore.getState().beginSale()
    expect(useCartStore.getState().fulfillment).toBe("dine_in")
    expect(useCartStore.getState().deliveryAddress).toBeNull()
  })

  it("NO toca los flags fiscales del carrito — el crédito elegido antes de cobrar sobrevive", () => {
    useCartStore.getState().toggleCredito()
    useCartStore.getState().beginSale()
    expect(useCartStore.getState().credito).toBe(true)
  })

  it("vaciar el carrito también devuelve a venta (la venta confirmada termina así)", () => {
    useCartStore.getState().setPosMode("cotizacion")
    useCartStore.getState().clear()
    expect(useCartStore.getState().posMode).toBe("venta")
  })
})
