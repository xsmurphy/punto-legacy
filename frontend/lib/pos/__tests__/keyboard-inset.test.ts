import { describe, expect, it } from "vitest"
import { readdirSync, readFileSync } from "node:fs"
import path from "node:path"

/**
 * Guard del inset del TECLADO VIRTUAL, hermano de `safe-area.test.ts`.
 *
 * El teclado no achica el viewport de layout en iOS, así que `dvh` no lo ve y
 * lo que queda debajo de él es inalcanzable: el buscador de usuarios del POS
 * quedaba entero atrás del teclado (reporte del owner, 2026-08-25). La medición
 * vive en UN solo lugar (`components/pos/keyboard-inset.tsx`, con
 * `visualViewport`) y se publica como `--kb-inset`; el resto solo la descuenta.
 *
 * Estos tests cuidan las dos mitades: que la medición no se disperse en
 * call-sites, y que el primitive que la consume no la pierda en un refactor.
 */

const ROOT = path.resolve(import.meta.dirname, "../../..")

function read(rel: string): string {
  return readFileSync(path.join(ROOT, rel), "utf8")
}

/** Comentarios fuera: nombrar la API en un docblock no es medir. */
function stripComments(src: string): string {
  return src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:])\/\/.*$/gm, "$1")
}

const SKIP_DIRS = new Set([
  "node_modules",
  ".next",
  ".git",
  "dist",
  "out",
  "coverage",
  "public",
])

function allSourceFiles(dir = ROOT, acc: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name.startsWith(".") && entry.name !== ".") continue
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      if (SKIP_DIRS.has(entry.name)) continue
      allSourceFiles(full, acc)
    } else if (/\.(tsx?|css)$/.test(entry.name)) {
      acc.push(path.relative(ROOT, full))
    }
  }
  return acc
}

describe("una sola medición del teclado", () => {
  it("nadie más lee `visualViewport`", () => {
    // Misma allowlist de un solo archivo que el barrido de áreas seguras de
    // `safe-area.test.ts`: si la medición reaparece en un call-site, se pierde
    // el único lugar donde mirar y donde forzar un valor para simular el
    // teclado en el browser.
    const allowed = new Set([
      "components/pos/keyboard-inset.tsx",
      "lib/pos/__tests__/keyboard-inset.test.ts",
      // Sonda de diagnóstico (`?debug=viewport`): LEE y muestra números, no
      // escribe `--kb-inset` ni la usa nadie para maquetar. La regla que cuida
      // este test es que haya una sola FUENTE de la medición; un observador de
      // solo lectura, montado bajo un query param, no la duplica — y es la
      // única forma de ver estos valores en una PWA de iOS, donde no hay
      // devtools.
      "components/pos/viewport-probe.tsx",
    ])
    const offenders = allSourceFiles()
      .filter((rel) => !allowed.has(rel))
      .filter((rel) => stripComments(read(rel)).includes("visualViewport"))
    expect(
      offenders,
      `miden el teclado por su cuenta: ${offenders.join(", ")}`,
    ).toEqual([])
  })

  it("`--kb-inset` tiene un default en globals.css", () => {
    // Sin el default, cualquier `calc()` que la use queda inválido en el panel
    // y en el desktop, donde nadie la escribe.
    expect(read("app/globals.css")).toMatch(/--kb-inset:\s*0px/)
  })

  it("el POS monta el medidor", () => {
    expect(read("app/(pos)/layout.tsx")).toContain("<PosKeyboardInset />")
  })
})

describe("los modales conviven con el teclado", () => {
  const dialog = read("components/ui/dialog.tsx")

  it("el diálogo centrado se acota Y se recentra sobre el hueco visible", () => {
    // Solo el `max-h` no alcanza: un modal más bajo pero centrado contra la
    // pantalla completa sigue quedando medio tapado.
    expect(dialog).toMatch(/max-h-\[min\(85dvh,[^\]]*var\(--kb-inset\)/)
    expect(dialog).toMatch(/top-\[calc\(50%-var\(--kb-inset\)\/2\)\]/)
  })

  it("el fullscreen apoya su borde inferior sobre el teclado", () => {
    expect(dialog).toMatch(/max-sm:bottom-\[var\(--kb-inset\)\]/)
  })

  it("el buscador de clientes recorta su alto con el teclado abierto", () => {
    expect(read("components/register/customer-dialog.tsx")).toMatch(
      /max-h-\[calc\(86dvh-var\(--kb-inset\)\)\]/,
    )
  })
})
