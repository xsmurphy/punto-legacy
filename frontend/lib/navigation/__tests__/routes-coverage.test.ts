import { readdirSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

import { routePathname } from "@/lib/navigation/build"
import { PANEL_ROUTES, POS_ROUTES, UNINDEXED_PAGES } from "@/lib/navigation/routes"
import { SETTINGS_SECTION_IDS } from "@/lib/settings/sections"

/**
 * Cobertura del registro de navegación.
 *
 * El problema que este test existe para impedir: las superficies de
 * navegación (sidebar y command palette) se mantenían a mano, así que cada
 * página nueva nacía invisible — nadie se enteraba hasta que un usuario no
 * encontraba la sección. Llegaron a faltar 35 páginas.
 *
 * Acá se lee el filesystem de `app/(panel)` y `app/(pos)`, se listan las
 * páginas REALES y se exige que cada una esté en el registro
 * (`lib/navigation/routes.ts`) o en la allowlist de exclusiones
 * (`UNINDEXED_PAGES`, con motivo escrito). Una página nueva rompe el test
 * hasta que su autor la indexe o la excluya a propósito.
 *
 * Los tres asserts son simétricos:
 *  1. Ninguna página real queda fuera del registro sin excusa.
 *  2. Ninguna entrada del registro apunta a una página inexistente.
 *  3. La allowlist no se pudre (excluir algo que ya no existe, o excluir e
 *     indexar la misma página).
 *
 * El cuarto assert cubre el nivel de abajo: una página puede existir en el
 * registro y aun así aterrizar en el lugar equivocado. Los tabs de /settings
 * se deep-linkean con `?section=<id>`, y ese id tiene que existir en
 * `lib/settings/sections.ts` — la lista con la que la pantalla decide qué
 * mostrar. Sin el cruce, renombrar un tab deja el link apuntando al vacío y
 * el usuario cae en "Empresa" sin que nada falle.
 */

// lib/navigation/__tests__ → raíz de `frontend/`
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")
const APP_DIR = path.join(FRONTEND_ROOT, "app")
const ROUTE_GROUPS = ["(panel)", "(pos)"]

/**
 * Convierte la ruta de un `page.tsx` en su pathname público.
 * Los segmentos entre paréntesis son route groups de Next y no aparecen en
 * la URL. Devuelve `null` para rutas dinámicas (`[id]`), que quedan fuera del
 * índice por definición: no se pueden buscar sin conocer el registro.
 */
function toPathname(relativeFile: string): string | null {
  const segments = relativeFile.split(path.sep).slice(0, -1) // sin "page.tsx"
  if (segments.some((s) => s.startsWith("["))) return null
  const visible = segments.filter((s) => !s.startsWith("("))
  return "/" + visible.join("/")
}

function listPages(dir: string, base = dir): string[] {
  const out: string[] = []
  for (const dirent of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, dirent.name)
    if (dirent.isDirectory()) {
      out.push(...listPages(full, base))
    } else if (dirent.name === "page.tsx") {
      out.push(path.relative(base, full))
    }
  }
  return out
}

const realPages = new Set<string>()
for (const group of ROUTE_GROUPS) {
  for (const file of listPages(path.join(APP_DIR, group), APP_DIR)) {
    const pathname = toPathname(file)
    if (pathname) realPages.add(pathname)
  }
}

const registry = [...PANEL_ROUTES, ...POS_ROUTES]
const indexedPages = new Set(registry.map((entry) => routePathname(entry.to)))
const excludedPages = new Set(Object.keys(UNINDEXED_PAGES))

describe("registro de navegación", () => {
  it("encuentra páginas reales para comparar (sanity)", () => {
    // Si el layout de `app/` cambia y el walker deja de encontrar páginas,
    // los asserts de abajo pasarían vacíos y el test sería decorativo.
    expect(realPages.size).toBeGreaterThan(50)
  })

  it("indexa (o excluye con motivo) todas las páginas del panel y del POS", () => {
    const missing = [...realPages]
      .filter((p) => !indexedPages.has(p) && !excludedPages.has(p))
      .sort()
    expect(
      missing,
      "Páginas sin entrada en lib/navigation/routes.ts. Agregalas al registro " +
        "o, si no deben ser navegables, sumalas a UNINDEXED_PAGES con el motivo.",
    ).toEqual([])
  })

  it("no apunta a páginas inexistentes", () => {
    const dangling = registry
      .filter((entry) => !realPages.has(routePathname(entry.to)))
      .map((entry) => `${entry.title} → ${entry.to}`)
      .sort()
    expect(dangling, "Entradas del registro sin page.tsx correspondiente.").toEqual([])
  })

  it("mantiene la allowlist de exclusiones sana", () => {
    const stale = [...excludedPages].filter((p) => !realPages.has(p)).sort()
    expect(stale, "Exclusiones de páginas que ya no existen — borralas.").toEqual([])

    const contradictory = [...excludedPages].filter((p) => indexedPages.has(p)).sort()
    expect(contradictory, "Páginas excluidas E indexadas a la vez.").toEqual([])

    const withoutReason = Object.entries(UNINDEXED_PAGES)
      .filter(([, reason]) => reason.trim().length < 10)
      .map(([p]) => p)
    expect(withoutReason, "Toda exclusión necesita un motivo escrito.").toEqual([])
  })

  it("deep-linkea /settings a secciones que existen", () => {
    const broken = registry
      .filter((entry) => routePathname(entry.to) === "/settings")
      .map((entry) => ({
        entry,
        section: new URLSearchParams(entry.to.split("?")[1] ?? "").get("section"),
      }))
      .filter(({ section }) => section !== null && !SETTINGS_SECTION_IDS.includes(section))
      .map(({ entry, section }) => `${entry.title} → ?section=${section}`)
      .sort()
    expect(
      broken,
      "Entradas de /settings con un ?section= que no existe en " +
        "lib/settings/sections.ts. Ids válidos: " +
        SETTINGS_SECTION_IDS.join(", "),
    ).toEqual([])
  })
})
