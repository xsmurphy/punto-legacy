import { describe, expect, it } from "vitest"

import { MODULES_CATALOG, catalogByKind, modulesForCountry } from "@/lib/modules-catalog"

/**
 * GUARD — lo específico de un país no se le ofrece a todos los tenants.
 *
 * Regla del owner (2026-08-28): «todas las funcionalidades que apuntan a
 * Paraguay tienen que habilitarse solo cuando el tenant selecciona que su país
 * es Paraguay, y no que esté siempre habilitado y hardcodeado a Paraguay».
 *
 * Es el complemento del guard de literales (`no-hardcoded-paraguay.test.ts`):
 * aquel impide que se escriba "Gs" o "es-PY" suelto; este impide algo más
 * grande y más difícil de ver — un MÓDULO entero (facturación de la SET,
 * Bancard, uPay) ofrecido a un comercio que no puede usarlo. El síntoma
 * tampoco falla ruidosamente: el módulo aparece, el usuario lo configura, y
 * recién descubre que no le sirve cuando intenta emitir.
 *
 * Lo que se verifica es el catálogo, no cada pantalla: si un módulo nuevo
 * atado a un país no declara `countries`, este test lo caza.
 */

/** Módulos que hoy son PY-only, por normativa o por proveedor local. */
const EXPECTED_PY_ONLY = ["einvoicePy", "bancard", "upay"]

describe("módulos específicos de un país", () => {
  it("los módulos PY-only declaran su país", () => {
    for (const key of EXPECTED_PY_ONLY) {
      const entry = MODULES_CATALOG.find((m) => m.key === key)
      expect(entry, `falta el módulo ${key} en el catálogo`).toBeDefined()
      expect(entry?.countries, `${key} no declara countries`).toEqual(["PY"])
    }
  })

  it("un tenant NO paraguayo no ve ninguno de ellos", () => {
    const keys = modulesForCountry("BR").map((m) => m.key)
    for (const key of EXPECTED_PY_ONLY) {
      expect(keys, `${key} se le ofrece a un tenant de BR`).not.toContain(key)
    }
  })

  it("un tenant paraguayo los ve todos", () => {
    const keys = modulesForCountry("PY").map((m) => m.key)
    for (const key of EXPECTED_PY_ONLY) {
      expect(keys).toContain(key)
    }
  })

  it("sin país todavía (bootstrap sin cargar) no se ofrece nada país-específico", () => {
    // Preferimos mostrar de menos por un instante que ofrecer una integración
    // de otro país y tener que sacarla cuando llega el dato.
    for (const country of [undefined, null, ""]) {
      const keys = modulesForCountry(country).map((m) => m.key)
      for (const key of EXPECTED_PY_ONLY) {
        expect(keys, `${key} visible sin país resuelto`).not.toContain(key)
      }
    }
  })

  it("los módulos sin restricción se ven en cualquier país", () => {
    const unrestricted = MODULES_CATALOG.filter((m) => !m.countries).map((m) => m.key)
    expect(unrestricted.length).toBeGreaterThan(0)
    const brKeys = modulesForCountry("BR").map((m) => m.key)
    for (const key of unrestricted) {
      expect(brKeys).toContain(key)
    }
  })

  it("`catalogByKind` respeta el país (es la puerta que usa el panel)", () => {
    const brIntegrations = catalogByKind("integration", "BR").map((m) => m.key)
    expect(brIntegrations).not.toContain("einvoicePy")
    const pyIntegrations = catalogByKind("integration", "PY").map((m) => m.key)
    expect(pyIntegrations).toContain("einvoicePy")
  })

  it("`countries` usa ISO alpha-2 en mayúsculas", () => {
    // Sin esto, un "py" en minúscula pasaría el type-check y no matchearía
    // nunca contra el `country` del bootstrap, dejando el módulo invisible
    // para todos — un fallo silencioso en la dirección opuesta.
    for (const m of MODULES_CATALOG) {
      for (const c of m.countries ?? []) {
        expect(c, `${m.key} declara "${c}"`).toMatch(/^[A-Z]{2}$/)
      }
    }
  })
})
