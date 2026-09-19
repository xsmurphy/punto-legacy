import { describe, expect, it } from "vitest"

import { buildPaletteSections, type NavContext } from "@/lib/navigation/build"
import { PANEL_ROUTES } from "@/lib/navigation/routes"
import { countActiveOutlets } from "@/hooks/use-outlets"

/**
 * El reporte de Sucursales solo se ofrece con 2+ sucursales activas en el
 * alcance del usuario (y con `reports.sales.view`, el gate del endpoint).
 */
function hasOutletsReport(ctx: NavContext): boolean {
  return buildPaletteSections(PANEL_ROUTES, ctx).some((s) =>
    s.items.some((i) => i.to === "/reports/outlets"),
  )
}

const base: NavContext = { perms: ["reports.sales.view"], permsLoaded: true }

describe("requiresMultiOutlet", () => {
  it("con 2+ sucursales y el permiso, aparece", () => {
    expect(hasOutletsReport({ ...base, multiOutlet: true })).toBe(true)
  })
  it("con una sola sucursal no aparece", () => {
    expect(hasOutletsReport({ ...base, multiOutlet: false })).toBe(false)
  })
  it("mientras no se sabe (undefined) no aparece", () => {
    expect(hasOutletsReport(base)).toBe(false)
  })
  it("sin el permiso del endpoint no aparece aunque haya sucursales", () => {
    expect(hasOutletsReport({ perms: [], permsLoaded: true, multiOutlet: true })).toBe(false)
  })
})

describe("countActiveOutlets", () => {
  it("cuenta solo las activas", () => {
    expect(countActiveOutlets([{ status: 1 }, { status: 0 }, { status: 1 }])).toBe(2)
    expect(countActiveOutlets(undefined)).toBe(0)
  })
})
