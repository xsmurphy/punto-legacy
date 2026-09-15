import { beforeEach, describe, expect, it, vi } from "vitest"

/**
 * Contrato de las preferencias persistidas de `<DataTable>`.
 *
 * Se testea la capa pura (`lib/table-state/store.ts`): la suite corre en
 * `environment: "node"` sin jsdom, y lo que se rompe en este feature no es el
 * render sino QUÉ se lee y escribe — la clave, la separación por persona y el
 * "vacío no es ausente" de la visibilidad.
 */

const store = new Map<string, string>()
let throwOnAccess = false
vi.stubGlobal("window", {
  localStorage: {
    getItem: (k: string) => {
      if (throwOnAccess) throw new Error("SecurityError")
      return store.get(k) ?? null
    },
    setItem: (k: string, v: string) => {
      if (throwOnAccess) throw new Error("QuotaExceededError")
      store.set(k, v)
    },
    removeItem: (k: string) => {
      if (throwOnAccess) throw new Error("SecurityError")
      store.delete(k)
    },
  },
})

const {
  adminTableNamespace,
  clearTableState,
  collectColumnIds,
  legacyVisibilityKey,
  patchTableState,
  readTableState,
  subscribeTableStateReset,
  tableStateKey,
  tenantTableNamespace,
} = await import("@/lib/table-state/store")

const NS = tenantTableNamespace("company-a", 7)

describe("preferencias persistidas de un listado", () => {
  beforeEach(() => {
    store.clear()
    throwOnAccess = false
  })

  it("orden, buscador, filtros y visibilidad se releen igual — el reload", () => {
    patchTableState(NS, "items", { sorting: [{ id: "price", desc: true }] })
    patchTableState(NS, "items", { globalFilter: "coca" })
    patchTableState(NS, "items", { columnFilters: [{ id: "kind", value: "product" }] })
    patchTableState(NS, "items", { columnVisibility: { cost: false } })

    expect(readTableState(NS, "items")).toEqual({
      sorting: [{ id: "price", desc: true }],
      globalFilter: "coca",
      columnFilters: [{ id: "kind", value: "product" }],
      columnVisibility: { cost: false },
    })
  })

  it("una clave versionada por tabla, no una por campo", () => {
    patchTableState(NS, "items", { sorting: [] })
    patchTableState(NS, "items", { globalFilter: "x" })
    expect([...store.keys()]).toEqual([tableStateKey(NS, "items")])
    expect(tableStateKey(NS, "items")).toMatch(/^punto\.dt\.v1\./)
  })

  it("visibilidad {} es preferencia ('mostré todas'), no ausencia", () => {
    // El bug: con `Object.keys(v).length === 0` como "sin preferencia", una
    // tabla con columnas ocultas por default las volvía a ocultar al recargar.
    patchTableState(NS, "items", { columnVisibility: {} })
    const state = readTableState(NS, "items")
    expect(state.columnVisibility).toEqual({})
    expect("columnVisibility" in state).toBe(true)

    expect(readTableState(NS, "otra").columnVisibility).toBeUndefined()
  })

  it("empresas y usuarios distintos no comparten preferencias", () => {
    const otraEmpresa = tenantTableNamespace("company-b", 7)
    const otroUsuario = tenantTableNamespace("company-a", 8)
    patchTableState(NS, "items", { caller: { outlet: "outlet-de-a" } })

    expect(readTableState(otraEmpresa, "items")).toEqual({})
    expect(readTableState(otroUsuario, "items")).toEqual({})
    expect(readTableState(adminTableNamespace(7), "items")).toEqual({})
    expect(readTableState(NS, "items").caller).toEqual({ outlet: "outlet-de-a" })
  })

  it("un id raro no puede fabricar la clave de otro namespace", () => {
    expect(tenantTableNamespace("a.u-9", 1)).toBe("c-au-9.u-1")
  })

  it("el estado del caller se mezcla por clave sin pisar el de la tabla", () => {
    patchTableState(NS, "items", { sorting: [{ id: "name", desc: false }] })
    patchTableState(NS, "items", { caller: { kind: "product" } })
    patchTableState(NS, "items", { caller: { outlet: "o1" } })

    expect(readTableState(NS, "items")).toEqual({
      sorting: [{ id: "name", desc: false }],
      caller: { kind: "product", outlet: "o1" },
    })
  })

  it("migra la visibilidad de la clave vieja la primera vez", () => {
    store.set(legacyVisibilityKey("items"), JSON.stringify({ cost: false, stock: false }))

    expect(readTableState(NS, "items").columnVisibility).toEqual({ cost: false, stock: false })
    // Quedó escrita en la clave nueva…
    expect(JSON.parse(store.get(tableStateKey(NS, "items"))!).columnVisibility).toEqual({
      cost: false,
      stock: false,
    })
    // …y la vieja sigue para la próxima persona del mismo equipo.
    expect(readTableState(tenantTableNamespace("company-a", 99), "items").columnVisibility).toEqual({
      cost: false,
      stock: false,
    })
  })

  it("la clave vieja no pisa una preferencia nueva", () => {
    store.set(legacyVisibilityKey("items"), JSON.stringify({ cost: false }))
    patchTableState(NS, "items", { columnVisibility: {} })
    expect(readTableState(NS, "items").columnVisibility).toEqual({})
  })

  it("restablecer borra la clave (y la vieja) y avisa a los suscriptos", () => {
    store.set(legacyVisibilityKey("items"), JSON.stringify({ cost: false }))
    patchTableState(NS, "items", { sorting: [{ id: "price", desc: true }], caller: { kind: "x" } })
    const onReset = vi.fn()
    const unsubscribe = subscribeTableStateReset(NS, "items", onReset)
    const otraTabla = vi.fn()
    subscribeTableStateReset(NS, "contacts", otraTabla)

    clearTableState(NS, "items")

    expect(store.size).toBe(0)
    expect(readTableState(NS, "items")).toEqual({})
    expect(onReset).toHaveBeenCalledTimes(1)
    expect(otraTabla).not.toHaveBeenCalled()

    unsubscribe()
    clearTableState(NS, "items")
    expect(onReset).toHaveBeenCalledTimes(1)
  })

  it("lo corrupto se descarta campo por campo, sin romper", () => {
    store.set(
      tableStateKey(NS, "items"),
      JSON.stringify({
        sorting: [{ id: "price", desc: "si" }, { id: "name", desc: false }],
        globalFilter: 42,
        columnVisibility: { cost: false, stock: "no" },
        columnFilters: "nope",
      }),
    )
    expect(readTableState(NS, "items")).toEqual({
      sorting: [{ id: "name", desc: false }],
      columnVisibility: { cost: false },
    })

    store.set(tableStateKey(NS, "items"), "{ no es json")
    expect(readTableState(NS, "items")).toEqual({})
  })

  it("localStorage que tira no rompe: se pierde la persistencia, nada más", () => {
    throwOnAccess = true
    expect(() => patchTableState(NS, "items", { globalFilter: "x" })).not.toThrow()
    expect(readTableState(NS, "items")).toEqual({})
    expect(() => clearTableState(NS, "items")).not.toThrow()
  })
})

describe("columnas existentes (descarte de columnas fantasma)", () => {
  it("replica la regla de ids de TanStack: id, accessorKey con _, header string", () => {
    const ids = collectColumnIds([
      { id: "actions" },
      { accessorKey: "itemName" },
      { accessorKey: "outlet.name" },
      { header: "Total" },
      { header: () => null },
      { id: "grupo", columns: [{ accessorKey: "a" }, { id: "b" }] },
    ])
    expect([...ids].sort()).toEqual(["Total", "a", "actions", "b", "grupo", "itemName", "outlet_name"])
  })
})
