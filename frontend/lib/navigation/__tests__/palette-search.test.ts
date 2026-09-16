import { describe, expect, it } from "vitest"

import { buildPaletteSections } from "@/lib/navigation/build"
import { PANEL_ROUTES } from "@/lib/navigation/routes"
import { normalizeSearchText, paletteScore } from "@/lib/navigation/search"

/**
 * Ranking del command palette contra el registro REAL de rutas.
 *
 * El caso que originó el módulo: buscando "sucursales" el palette listaba
 * primero "Artículos · Transferencias" y "Ventas · Facturas recurrentes", y
 * "Sucursales" quedaba tercero. Los asserts de acá son ese reporte convertido
 * en regresión: lo que se escribe entero tiene que salir primero, y lo que no
 * tiene nada que ver no tiene que salir.
 */

const sections = buildPaletteSections(PANEL_ROUTES, { perms: [], permsLoaded: false })
const items = sections.flatMap((s) => s.items)

/** Lo que vería el usuario, en el orden en que cmdk lo pinta. */
function search(query: string): string[] {
  return items
    .map((it) => ({ title: it.title, score: paletteScore(it, query) }))
    .filter((r) => r.score > 0)
    .sort((a, b) => b.score - a.score)
    .map((r) => r.title)
}

describe("normalizeSearchText", () => {
  it("saca acentos, mayúsculas y separadores del título", () => {
    expect(normalizeSearchText("Artículos · Categorías")).toBe("articulos categorias")
    expect(normalizeSearchText("  Impresión/Plantillas  ")).toBe("impresion plantillas")
  })
})

describe("paletteScore — reglas", () => {
  const entry = { title: "Sucursales", keywords: ["locales", "tiendas"] }

  it("el título completo gana a todo lo demás", () => {
    expect(paletteScore(entry, "sucursales")).toBeGreaterThan(
      paletteScore({ title: "Reportes · Sucursales por período" }, "sucursales"),
    )
  })

  it("encuentra por prefijo y por sinónimo", () => {
    expect(paletteScore(entry, "sucu")).toBeGreaterThan(0)
    expect(paletteScore(entry, "tiendas")).toBeGreaterThan(0)
  })

  it("el match en el título pesa más que el sinónimo", () => {
    const porTitulo = paletteScore({ title: "Tiendas" }, "tiendas")
    const porSinonimo = paletteScore({ title: "Sucursales", keywords: ["tiendas"] }, "tiendas")
    expect(porTitulo).toBeGreaterThan(porSinonimo)
  })

  it("el plural encuentra el singular", () => {
    expect(paletteScore({ title: "Caja" }, "cajas")).toBeGreaterThan(0)
    expect(paletteScore({ title: "Sucursal" }, "sucursales")).toBeGreaterThan(0)
  })

  it("no matchea por subsecuencia desparramada", () => {
    expect(paletteScore({ title: "Ventas · Facturas recurrentes" }, "sucursales")).toBe(0)
    expect(paletteScore({ title: "Artículos · Transferencias" }, "sucursales")).toBe(0)
  })

  it("con varias palabras, todas tienen que estar", () => {
    const control = { title: "Reportes · Control de cajas" }
    expect(paletteScore(control, "control cajas")).toBeGreaterThan(0)
    expect(paletteScore(control, "control clientes")).toBe(0)
  })

  it("sin búsqueda no descarta nada", () => {
    expect(paletteScore(entry, "")).toBeGreaterThan(0)
    expect(paletteScore(entry, "   ")).toBeGreaterThan(0)
  })
})

describe("palette — el índice real del panel", () => {
  it("'sucursales' devuelve Sucursales primero, sin coincidencias de casualidad", () => {
    const results = search("sucursales")
    expect(results[0]).toBe("Sucursales")
    expect(results).not.toContain("Ventas · Facturas recurrentes")
    // "Transferencias" sí aparece —tiene "sucursales" entre sus sinónimos, se
    // transfiere entre sucursales— pero detrás de la sección que se llama así.
    expect(results.indexOf("Artículos · Transferencias")).toBeGreaterThan(0)
  })

  it("'cajas' lista lo de caja y no ajustes de stock ni gift cards", () => {
    const results = search("cajas")
    expect(results.length).toBeGreaterThan(0)
    expect(results.every((title) => /caj/i.test(normalizeSearchText(title)) === false)).toBe(false)
    expect(results).not.toContain("Artículos · Ajustes de stock")
    expect(results).not.toContain("Ventas · Gift cards")
  })

  it("una búsqueda sin sentido no devuelve nada", () => {
    expect(search("zzzqqq")).toEqual([])
  })

  it("cada entrada del registro se encuentra escribiendo su propio título", () => {
    const sinEncontrarse = items.filter((it) => {
      const results = search(it.title)
      return results[0] !== it.title
    })
    expect(sinEncontrarse.map((i) => i.title)).toEqual([])
  })
})
