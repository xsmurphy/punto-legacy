import { describe, expect, it, vi, beforeEach } from "vitest"

/**
 * GUARD — el detalle de transacción del POS no puede volver a mostrarse vacío.
 *
 * Reporte del tester (2026-08-28): al abrir una transacción el panel mostraba
 * "Sin cliente", "Tipo NaN", "Items (0)" y "Gs 0" pese a que la fila de la
 * lista traía los datos bien; recargar la página lo arreglaba.
 *
 * Eran DOS defectos que se tapaban entre sí:
 *
 *  1. `use-transactions.ts` iba por `posFetch` crudo y casteaba el ENVELOPE
 *     `{ ok, data }` de la API (`apiOk`) a `TransactionDetail`. El resultado es
 *     un objeto sin `type`/`items`/`customerName` pero TRUTHY, así que la
 *     guarda `!isLoading && detail` lo daba por bueno y lo pintaba en vez de
 *     mostrar un error. La lista tenía el mismo defecto (`data.transactionsList`
 *     sobre el envelope = `undefined`), pero fallaba en silencio contra `?? []`.
 *  2. `usePosTransactionDetail` cacheaba bajo la MISMA queryKey
 *     `["pos-transaction", id]` con su propio fetcher, ése sí correcto. Dos
 *     escritores con formas distintas sobre una sola clave: ganaba el último en
 *     resolver, de ahí la intermitencia y el "se arregla recargando".
 *
 * El test ejercita la cadena REAL —fetcher → `posApi` → `posFetch`— mockeando
 * solo la capa de red. Si alguien vuelve a leer `.json()` a mano o a saltear el
 * wrapper del realm device, esto falla.
 */

const posFetchMock = vi.fn()
vi.mock("@/lib/api/pos-fetch", () => ({ posFetch: (...a: unknown[]) => posFetchMock(...a) }))

import { fetchTransactionDetail, fetchTransactionsList } from "@/hooks/use-transactions"

/** Lo que realmente manda la API: `apiOk` envuelve TODO en `{ ok, data }`. */
function envelope(data: unknown): Response {
  return new Response(JSON.stringify({ ok: true, data }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  })
}

beforeEach(() => posFetchMock.mockReset())

describe("detalle de transacción del POS", () => {
  it("devuelve el payload, no el envelope", async () => {
    const detalle = {
      transactionId: "tx-1",
      type: "9",
      customerName: "Jose Maria Benitez Martinez",
      total: "10800000",
      transactionDatas: [{ name: "Módulo Ropero", status: 1 }],
    }
    posFetchMock.mockResolvedValue(envelope(detalle))

    const out = await fetchTransactionDetail("enc-1")

    // Lo que rompía: `out.ok === true` y nada más — el panel pintaba el vacío.
    expect(out).not.toHaveProperty("ok")
    expect(out).toMatchObject({ type: "9", customerName: "Jose Maria Benitez Martinez" })
  })

  it("pide la ruta del realm device, con el id escapado", async () => {
    posFetchMock.mockResolvedValue(envelope({ transactionId: "tx-1" }))
    await fetchTransactionDetail("a/b c")
    expect(posFetchMock.mock.calls[0][0]).toBe("/api/pos/transactions/a%2Fb%20c")
  })

  it("una respuesta no-ok tira, no devuelve un objeto vacío", async () => {
    posFetchMock.mockResolvedValue(
      new Response(JSON.stringify({ ok: false, error: { message: "Transacción no encontrada", code: 404 } }), {
        status: 404,
        headers: { "Content-Type": "application/json" },
      }),
    )
    await expect(fetchTransactionDetail("enc-1")).rejects.toThrow()
  })
})

describe("lista de transacciones del POS", () => {
  it("lee transactionsList de DENTRO del envelope", async () => {
    posFetchMock.mockResolvedValue(
      envelope({ date: "2026-08-28", transactionsList: [{ transactionId: "tx-1", name: "Jose" }] }),
    )

    const out = await fetchTransactionsList({})

    // Con el bug esto daba [] siempre, en silencio, contra el `?? []`.
    expect(out).toHaveLength(1)
    expect(out[0]).toMatchObject({ transactionId: "tx-1" })
  })

  it("sin transactionsList devuelve lista vacía, no revienta", async () => {
    posFetchMock.mockResolvedValue(envelope({ date: "2026-08-28" }))
    await expect(fetchTransactionsList({})).resolves.toEqual([])
  })
})
