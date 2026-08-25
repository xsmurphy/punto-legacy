import { describe, expect, it } from "vitest"
import { readFileSync } from "node:fs"
import path from "node:path"

/**
 * Guard de áreas seguras del POS.
 *
 * `viewport-fit=cover` + `statusBarStyle: black-translucent` (app/layout.tsx)
 * hacen que la app instalada pinte de borde a borde. Cualquier superficie que
 * llegue a un borde y no descuente `env(safe-area-inset-*)` queda tapada por el
 * chrome del sistema: en el iPhone, la toolbar del carrito terminó debajo del
 * reloj y la batería, con dos de sus cuatro botones inalcanzables (reporte del
 * owner 2026-08-25).
 *
 * El bug no es de un componente sino de una CLASE de componente — el shell y
 * cada overlay fullscreen, que se portalean fuera del shell y por eso no
 * heredan su padding. Este test enumera esas superficies y exige que declaren
 * las áreas seguras, para que la próxima que se agregue no repita el olvido.
 *
 * Es un chequeo de código, no de render: no hay DOM ni browser en esta suite.
 * Lo que garantiza es que la declaración esté; que el valor sea el correcto lo
 * verifica el dispositivo.
 */

const ROOT = path.resolve(import.meta.dirname, "../../..")

/** Superficies del POS que tocan los bordes del dispositivo. */
const EDGE_SURFACES = [
  "app/(pos)/layout.tsx",
  "app/(pos)/pos/layout.tsx",
  "components/register/lock-screen.tsx",
  "components/register/pos-loading-screen.tsx",
  "components/register/pos-main-menu.tsx",
  "components/ui/drawer.tsx",
]

/** Declara área segura: la utilidad compartida o un `env()` explícito. */
const DECLARES_SAFE_AREA = /safe-area(?:-x)?\b|env\(safe-area-inset-/

function read(rel: string): string {
  return readFileSync(path.join(ROOT, rel), "utf8")
}

describe("áreas seguras del POS", () => {
  it.each(EDGE_SURFACES)("%s descuenta las áreas seguras", (rel) => {
    expect(read(rel)).toMatch(DECLARES_SAFE_AREA)
  })

  it("el shell del workspace es el que las aplica (no cada componente)", () => {
    // Si esto falla porque el shell cambió de forma, moverlo está bien — lo que
    // NO puede pasar es que el workspace deje de descontarlas en algún lado.
    const shell = read("app/(pos)/layout.tsx")
    expect(shell).toMatch(/SidebarInset[^>]*className={?["'][^"']*safe-area/s)
  })

  it("cada overlay `fixed inset-0` del POS declara las áreas seguras", () => {
    const overlays = [
      "components/register/lock-screen.tsx",
      "components/register/pos-loading-screen.tsx",
    ]
    for (const rel of overlays) {
      const src = read(rel)
      // Cada className que fija un overlay a los cuatro bordes tiene que traer
      // su propia declaración: son varios por archivo (spinner, error, lock).
      const fullscreenClassNames = src
        .split("\n")
        .filter((line) => line.includes("fixed inset-0"))
      expect(fullscreenClassNames.length).toBeGreaterThan(0)
      for (const line of fullscreenClassNames) {
        expect(line, `${rel}: overlay sin área segura → ${line.trim()}`).toMatch(
          DECLARES_SAFE_AREA,
        )
      }
    }
  })

  it("el drawer bottom no apoya su contenido sobre la barra de gestos", () => {
    // Es el primitive compartido: ~15 actionsheets del POS y del panel cuelgan
    // de acá, así que el padding inferior seguro vive una sola vez.
    expect(read("components/ui/drawer.tsx")).toMatch(
      /vaul-drawer-direction=bottom\]:pb-\[max\([^\]]*safe-area-inset-bottom/,
    )
  })

  it("la utilidad safe-area está definida una sola vez, en globals.css", () => {
    const css = read("app/globals.css")
    expect(css).toMatch(/@utility safe-area\s*\{/)
    expect(css.match(/@utility safe-area\s*\{/g)?.length).toBe(1)
  })
})

/**
 * Mínimo táctil de la toolbar del carrito.
 *
 * La caja se opera con el dedo (context: el POS es touch/tablet-first). Los
 * cuatro triggers de la toolbar estaban en `size-9` (36px), por debajo del
 * mínimo de las guías de iOS y Android. El contenedor es `h-14` (56px), así
 * que 44px entra sin mover ningún slot.
 */
describe("área táctil de la toolbar de la caja", () => {
  const TRIGGERS: Array<[string, RegExp]> = [
    ["components/register/cart-panel.tsx", /className="size-11"\s*\n\s*onClick={onSearch}/],
    ["components/register/cart-panel.tsx", /className="size-11"\s*\n\s*onClick={onCustomer}/],
    ["components/register/pos-main-menu.tsx", /className="relative size-11"/],
    ["components/register/sale-options-drawer.tsx", /className="size-11"/],
  ]

  it.each(TRIGGERS)("%s mantiene el trigger en 44px", (rel, pattern) => {
    expect(read(rel)).toMatch(pattern)
  })
})
