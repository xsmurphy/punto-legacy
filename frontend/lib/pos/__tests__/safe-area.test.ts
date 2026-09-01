import { describe, expect, it } from "vitest"
import { readdirSync, readFileSync } from "node:fs"
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

/** Comentarios fuera: un `var(--safe-b)` citado en prosa no es una aplicación. */
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

/** Todos los fuentes de `frontend/`, recursivo. */
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

describe("fuente única de las áreas seguras", () => {
  it("las cuatro variables se definen una sola vez, en globals.css", () => {
    const css = read("app/globals.css")
    for (const name of ["--safe-t", "--safe-r", "--safe-b", "--safe-l"]) {
      const defs = css.match(new RegExp(`${name}:\\s*env\\(safe-area-inset-`, "g"))
      expect(defs?.length, `${name} definida ${defs?.length ?? 0} veces`).toBe(1)
    }
  })

  it("NADIE en frontend/ lee `env(safe-area-inset-*)` salvo globals.css", () => {
    // El barrido va sobre TODO el árbol y no sobre un inventario: un
    // inventario solo caza lo que ya sabíamos que existía, y la falla que
    // importa es la superficie NUEVA que nadie agregó a la lista. La allowlist
    // es de un solo archivo a propósito — si `env()` aparece en cualquier otro
    // lado, se perdió el único lugar donde mirar y donde forzar valores para
    // simular un iPhone en el browser (la única forma de verificar esto sin
    // dispositivo).
    const needle = "env(safe-area-" + "inset-"
    const allowed = new Set([
      "app/globals.css",
      // Este archivo nombra el patrón en prosa (docblocks y títulos de test).
      "lib/pos/__tests__/safe-area.test.ts",
    ])
    // `stripComments`: un docblock que NOMBRA el patrón (explicando por qué no
    // hay que usarlo) no es una aplicación. Sin esto el guard obliga a meter en
    // la allowlist a cualquier archivo que lo mencione en prosa, y la allowlist
    // deja de significar "acá se usa de verdad".
    const offenders = allSourceFiles()
      .filter((rel) => !allowed.has(rel))
      .filter((rel) => stripComments(read(rel)).includes(needle))
    expect(
      offenders,
      `usan env() en vez de var(--safe-*): ${offenders.join(", ")}`,
    ).toEqual([])
  })
})

/**
 * Cuántas veces se descuenta CADA eje en un archivo.
 *
 * Cuenta las variables directas y también las utilidades: `safe-area` son los
 * cuatro ejes y `safe-area-x` los dos laterales, así que valen como aplicación
 * aunque no se escriba ningún `var()`.
 */
function insetCounts(rel: string): Record<"t" | "r" | "b" | "l", number> {
  const src = stripComments(read(rel))
  const count = (re: RegExp) => src.match(re)?.length ?? 0
  const fourSided = count(/\bsafe-area(?![-\w])/g)
  const lateral = count(/\bsafe-area-x\b/g)
  return {
    // `safe-area-x` NO suma en los ejes vertical/horizontal-inferior: es
    // laterales y nada más.
    t: count(/var\(--safe-t\)/g) + fourSided,
    b: count(/var\(--safe-b\)/g) + fourSided,
    r: count(/var\(--safe-r\)/g) + fourSided + lateral,
    l: count(/var\(--safe-l\)/g) + fourSided + lateral,
  }
}

/**
 * Inventario EXACTO de aplicaciones por eje y por archivo.
 *
 * No es "≤ 1": hay archivos con varias superficies excluyentes entre sí (el
 * lock screen monta tres pantallas distintas, cada una a los cuatro bordes) y
 * ahí más de una aplicación es correcta. Lo que no puede pasar es que el
 * número cambie sin que alguien lo decida: sumar un segundo `pb-[var(--safe-b)]`
 * dentro de una superficie que ya lo tenía es exactamente el bug del CTA
 * "demasiado arriba", y en un archivo de 1800 líneas no se ve en el diff.
 *
 * Si tocás la topología de insets, actualizá el número acá y contá por qué en
 * el mensaje del commit.
 */
const EXPECTED_INSETS: Record<string, Record<"t" | "r" | "b" | "l", number>> = {
  // Shell del POS: superior y laterales. El inferior es de `CartBottom` —
  // volver a ponerlo acá es el bug del CTA "demasiado arriba".
  "app/(pos)/layout.tsx": { t: 1, r: 1, b: 0, l: 1 },
  // b:2 = la columna izquierda (solo `md`) y el módulo fullscreen (solo
  // móvil). Nunca conviven en la misma pantalla.
  "app/(pos)/pos/layout.tsx": { t: 1, r: 0, b: 2, l: 0 },
  // La ÚNICA aplicación del eje inferior del carrito.
  "components/register/cart-panel.tsx": { t: 0, r: 0, b: 1, l: 0 },
  // Tres pantallas excluyentes (spinner, error, lock), cada una a los cuatro
  // bordes.
  "components/register/lock-screen.tsx": { t: 3, r: 3, b: 3, l: 3 },
  "components/register/pos-loading-screen.tsx": { t: 1, r: 1, b: 1, l: 1 },
  // b:3 = las tres ramas excluyentes del content area (resumen de cuenta,
  // panel custom, barra de CTA).
  "components/register/pos-main-menu.tsx": { t: 1, r: 0, b: 3, l: 0 },
  "components/register/pos-transactions-dialog.tsx": { t: 1, r: 0, b: 1, l: 0 },
  // El `max-h` acotado al área segura toca los dos ejes verticales.
  "components/register/pay-dialog.tsx": { t: 1, r: 0, b: 1, l: 0 },
  "components/domain/contacts/contact-detail-view.tsx": { t: 1, r: 1, b: 1, l: 1 },
  "components/pos/install-prompt.tsx": { t: 0, r: 0, b: 1, l: 0 },
  // t:3 = el `max-h` centrado, el padding fullscreen y la X reposicionada.
  "components/ui/dialog.tsx": { t: 3, r: 2, b: 2, l: 1 },
  "components/ui/drawer.tsx": { t: 0, r: 1, b: 1, l: 1 },
  "components/layout/pos-sidebar.tsx": { t: 1, r: 0, b: 1, l: 0 },
}

/**
 * Superficies que apoyan en algún borde del dispositivo.
 *
 * Es el mismo conjunto que `EXPECTED_INSETS` (más abajo): las que declaran un
 * inset son exactamente las que tienen que declararlo. La lista NO es lo que
 * protege contra superficies nuevas que se olviden —para eso está el barrido
 * de `env()` sobre todo el árbol—, sino contra que a una conocida se le caiga
 * la declaración en un refactor.
 */
const SURFACES = Object.keys(EXPECTED_INSETS)

describe("cada superficie que toca un borde lo descuenta", () => {
  it.each(SURFACES)("%s descuenta las áreas seguras", (rel) => {
    expect(read(rel)).toMatch(DECLARES_SAFE_AREA)
  })

  it("cada overlay a pantalla completa del POS trae su propia declaración", () => {
    // Se posicionan `fixed` contra el viewport: no hay padding que heredar del
    // shell aunque estén montados adentro.
    //
    // El match es `fixed inset-` y no `fixed inset-0`: el lock screen pasó a
    // `fixed inset-x-0` con los bordes verticales apoyados en la ventana
    // visible del teclado (`--kb-top` / `--kb-bottom`, ver
    // `keyboard-inset.test.ts`) para que no le tape el PIN, y con el patrón
    // viejo la superficie que más importa —la única con un campo— se salía del
    // guard justo al tocarla.
    for (const rel of [
      "components/register/lock-screen.tsx",
      "components/register/pos-loading-screen.tsx",
    ]) {
      const lines = read(rel)
        .split("\n")
        .filter((line) => line.includes("fixed inset-"))
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
  it.each(Object.keys(EXPECTED_INSETS))(
    "%s aplica cada eje las veces declaradas",
    (rel) => {
      const actual = insetCounts(rel)
      for (const [axis, expected] of Object.entries(EXPECTED_INSETS[rel])) {
        expect(
          actual[axis as "t" | "r" | "b" | "l"],
          `${rel}: el eje "${axis}" se descuenta ${actual[axis as "t"]} veces y se esperaban ${expected}`,
        ).toBe(expected)
      }
    },
  )

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
