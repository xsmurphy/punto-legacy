import { describe, expect, it } from "vitest"

import { isDashboardFirstLoad, isSettled } from "@/lib/dashboard/first-load"

const pending = { data: undefined, error: null }
const loaded = { data: { total: 0 }, error: null }
const failed = { data: undefined, error: new Error("403") }

describe("isSettled", () => {
  it("sin datos ni error no respondió", () => {
    expect(isSettled(pending)).toBe(false)
  })
  it("con datos (aunque sean vacíos) respondió", () => {
    expect(isSettled(loaded)).toBe(true)
    expect(isSettled({ data: [], error: null })).toBe(true)
  })
  it("con error respondió", () => {
    expect(isSettled(failed)).toBe(true)
  })
})

describe("isDashboardFirstLoad", () => {
  it("sin permisos resueltos es primera carga aunque todo haya respondido", () => {
    expect(
      isDashboardFirstLoad({ permissionsResolved: false, queries: [{ query: loaded, enabled: true }] }),
    ).toBe(true)
  })

  it("una query encendida sin respuesta la mantiene", () => {
    expect(
      isDashboardFirstLoad({
        permissionsResolved: true,
        queries: [
          { query: loaded, enabled: true },
          { query: pending, enabled: true },
        ],
      }),
    ).toBe(true)
  })

  it("una query apagada no se espera (nunca va a responder)", () => {
    expect(
      isDashboardFirstLoad({
        permissionsResolved: true,
        queries: [
          { query: loaded, enabled: true },
          { query: pending, enabled: false },
        ],
      }),
    ).toBe(false)
  })

  it("un error cuenta como respuesta: no deja la página en skeleton para siempre", () => {
    expect(
      isDashboardFirstLoad({
        permissionsResolved: true,
        queries: [
          { query: loaded, enabled: true },
          { query: failed, enabled: true },
        ],
      }),
    ).toBe(false)
  })
})
