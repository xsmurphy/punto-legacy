import { describe, expect, it } from "vitest"
import { buildPaletteSections } from "@/lib/navigation/build"
import { PANEL_ROUTES } from "@/lib/navigation/routes"
import { buildSitemap } from "@/lib/agent/sitemap"

const routes = PANEL_ROUTES.filter((r) => r.to === "/reports/summary-year")
describe("acceso al comparativo anual", () => {
  it.each([[], ["reports.sales.view"], ["reports.purchases.view"]])(
    "oculta el enlace con permisos incompletos %j",
    (...perms) => {
      expect(
        buildPaletteSections(routes, {
          perms: perms.flat() as string[],
          permsLoaded: true,
        })
      ).toEqual([])
    }
  )
  it("permite ventas y compras juntas", () => {
    expect(
      buildPaletteSections(routes, {
        perms: ["reports.sales.view", "reports.purchases.view"],
        permsLoaded: true,
      })[0].items[0].to
    ).toBe("/reports/summary-year")
  })
  it("el bot recibe los mismos requisitos", () => {
    const entry = buildSitemap().find((r) => r.path === "/reports/summary-year")
    expect(entry?.requires).toBe("reports.sales.view")
    expect(entry?.requiresAll).toEqual(["reports.purchases.view"])
  })
})
