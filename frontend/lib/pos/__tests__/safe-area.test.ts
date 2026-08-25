import { describe, expect, it } from "vitest"
import { readFileSync } from "node:fs"
import path from "node:path"

/**
 * Guard de áreas seguras del POS.
 *
 * `viewport-fit=cover` + `statusBarStyle: black-translucent` (app/layout.tsx)
 * hacen que la app instalada pinte de borde a borde. Eso trae DOS fallas de
 * signo opuesto y este archivo cuida las dos:
 *
 *  1. FALTA de inset — la superficie queda debajo del chrome del sistema. Fue
 *     el primer reporte (2026-08-25): la toolbar del carrito debajo del reloj
 *     y la batería, y la X de los modales fullscreen dentro del status bar,
 *     donde no recibe el toque (el cajero quedaba encerrado en el módulo de
 *     órdenes).
 *
 *  2. EXCESO de inset — el mismo eje descontado dos veces. Fue el segundo
 *     reporte, el mismo día: el shell del POS puso `padding-bottom` y la barra
 *     del CTA conservó su `p-2`, así que el botón de cobrar quedó flotando
 *     ~42px sobre el borde en vez de apoyar en el límite del área segura.
 *
 * De ahí la regla que estos tests fijan: **el inset se descuenta UNA sola vez
 * por eje, en el elemento más externo que pinta fondo contra ese borde**. Está
 * escrita en `app/globals.css` § "Áreas seguras del dispositivo".
 *
 * Es un chequeo de código, no de render: no hay DOM ni browser en esta suite.
 * Garantiza que la declaración esté y que no esté DOS veces; que el valor se
 * vea bien lo verifica el dispositivo.
 */

const ROOT = path.resolve(import.meta.dirname, "../../..")

function read(rel: string): string {
  return readFileSync(path.join(ROOT, rel), "utf8")
}

/** Consume un área segura: la utilidad compartida o una de las variables. */
const DECLARES_SAFE_AREA = /safe-area(?:-x)?\b|var\(--safe-[trbl]\)/

describe("fuente única de las áreas seguras", () => {
  it("las cuatro variables se definen una sola vez, en globals.css", () => {
    const css = read("app/globals.css")
    for (const name of ["--safe-t", "--safe-r", "--safe-b", "--safe-l"]) {
      const defs = css.match(new RegExp(`${name}:\\s*env\\(safe-area-inset-`, "g"))
      expect(defs?.length, `${name} definida ${defs?.length ?? 0} veces`).toBe(1)
    }
  })

  it("ningún componente lee `env(safe-area-inset-*)` por su cuenta", () => {
    // Si cada call-site vuelve a llamar a `env()` se pierde el único lugar
    // donde mirar —y donde forzar valores para simular un iPhone en el
    // browser, que es la única forma de verificar esto sin dispositivo.
    const offenders = SURFACES.filter((rel) =>
      /env\(safe-area-inset-/.test(read(rel)),
    )
    expect(offenders, `usan env() en vez de var(--safe-*): ${offenders.join(", ")}`)
      .toEqual([])
  })
})

/** Superficies que apoyan en algún borde del dispositivo. */
const SURFACES = [
  "app/(pos)/layout.tsx",
  "app/(pos)/pos/layout.tsx",
  "components/register/cart-panel.tsx",
  "components/register/lock-screen.tsx",
  "components/register/pos-loading-screen.tsx",
  "components/register/pos-main-menu.tsx",
  "components/register/pos-transactions-dialog.tsx",
  "components/pos/install-prompt.tsx",
  "components/ui/dialog.tsx",
  "components/ui/drawer.tsx",
]

describe("cada superficie que toca un borde lo descuenta", () => {
  it.each(SURFACES)("%s descuenta las áreas seguras", (rel) => {
    expect(read(rel)).toMatch(DECLARES_SAFE_AREA)
  })

  it("cada overlay `fixed inset-0` del POS trae su propia declaración", () => {
    // Se portalean fuera del shell: no hay padding que heredar.
    for (const rel of [
      "components/register/lock-screen.tsx",
      "components/register/pos-loading-screen.tsx",
    ]) {
      const lines = read(rel)
        .split("\n")
        .filter((line) => line.includes("fixed inset-0"))
      expect(lines.length).toBeGreaterThan(0)
      for (const line of lines) {
        expect(line, `${rel}: overlay sin área segura → ${line.trim()}`).toMatch(
          DECLARES_SAFE_AREA,
        )
      }
    }
  })

  it("el modal fullscreen reposiciona su X fuera del status bar", () => {
    // En varios módulos del POS esa X es la ÚNICA salida. Si vuelve a
    // `top-4` pelado, el cajero queda encerrado en el teléfono.
    expect(read("components/ui/dialog.tsx")).toMatch(
      /max-sm:top-\[calc\(1rem\+var\(--safe-t\)\)\]/,
    )
  })

  it("el drawer bottom no apoya su contenido sobre la barra de gestos", () => {
    // Primitive compartido: ~15 actionsheets del POS y del panel cuelgan de
    // acá, así que el padding inferior seguro vive una sola vez.
    expect(read("components/ui/drawer.tsx")).toMatch(
      /vaul-drawer-direction=bottom\]:pb-\[max\([^\]]*var\(--safe-b\)/,
    )
  })
})

describe("el inset se descuenta una sola vez por eje", () => {
  it("el shell del POS NO descuenta el eje inferior", () => {
    // ESTE es el guard del bug "el botón de pagar quedó demasiado arriba". El
    // eje inferior pertenece a `CartBottom`, que es el elemento que realmente
    // apoya en ese borde. Si alguien vuelve a poner `safe-area` (cuatro lados)
    // o un `--safe-b` acá, los dos paddings se suman.
    const shell = read("app/(pos)/layout.tsx")
    const inset = shell.match(/<SidebarInset[\s\S]*?\/>|<SidebarInset[\s\S]*?>/)?.[0] ?? ""
    expect(inset).toMatch(/var\(--safe-t\)/)
    expect(inset, "el shell volvió a descontar el eje inferior").not.toMatch(
      /var\(--safe-b\)|safe-area\b/,
    )
  })

  it("la barra del CTA del carrito es la que descuenta el eje inferior", () => {
    expect(read("components/register/cart-panel.tsx")).toMatch(
      /pb-\[max\(0\.5rem,var\(--safe-b\)\)\]/,
    )
  })
})

/**
 * Mínimo táctil de la toolbar del carrito.
 *
 * La caja se opera con el dedo (el POS es touch/tablet-first). Los cuatro
 * triggers de la toolbar estaban en `size-9` (36px), por debajo del mínimo de
 * las guías de iOS y Android. El contenedor es `h-14` (56px), así que 44px
 * entra sin mover ningún slot.
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
