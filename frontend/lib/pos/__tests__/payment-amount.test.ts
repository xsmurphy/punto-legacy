/**
 * La regla del visor del cobro (`resolvePaymentAmount`).
 *
 * Existe por una regresión concreta: la misma regla estaba escrita dos veces
 * en el diálogo de cobro —una por modo— y la copia del modo CRÉDITO no
 * contemplaba el visor vacío. Resultado: con el cliente ya elegido, ningún
 * botón de medio de pago respondía (reporte del owner, 2026-08-25). Lo que se
 * fija acá no es aritmética, es que el modo de la venta NO participa del
 * cálculo: hay una sola función y estos son todos sus casos.
 */

import { describe, expect, it } from "vitest"

import { resolvePaymentAmount } from "@/lib/pos/payment-amount"

describe("resolvePaymentAmount", () => {
  it("visor vacío cobra el restante — el caso que estaba roto en crédito", () => {
    expect(resolvePaymentAmount(0, 150_000)).toEqual({ amount: 150_000, change: 0 })
  })

  it("monto menor al restante es pago parcial, sin vuelto", () => {
    expect(resolvePaymentAmount(50_000, 150_000)).toEqual({ amount: 50_000, change: 0 })
  })

  it("monto exacto cubre la venta sin generar vuelto", () => {
    expect(resolvePaymentAmount(150_000, 150_000)).toEqual({ amount: 150_000, change: 0 })
  })

  it("monto mayor cobra el restante y devuelve la diferencia como vuelto", () => {
    expect(resolvePaymentAmount(200_000, 150_000)).toEqual({ amount: 150_000, change: 50_000 })
  })

  it("el pago nunca supera el restante — el excedente no entra a la venta", () => {
    const { amount } = resolvePaymentAmount(1_000_000, 150_000)
    expect(amount).toBeLessThanOrEqual(150_000)
  })

  it("un monto negativo se trata como visor vacío, no como descuento", () => {
    expect(resolvePaymentAmount(-1, 150_000)).toEqual({ amount: 150_000, change: 0 })
  })
})
