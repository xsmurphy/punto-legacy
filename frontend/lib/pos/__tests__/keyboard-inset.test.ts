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

/**
 * El shell se achica UNA vez y las superficies fijas se achican solas.
 *
 * Lo anterior arreglaba modal por modal, y el owner volvió con "eso está
 * pasando con muchas cosas en el POS" (2026-08-30): cualquier pantalla de ruta
 * con un campo seguía escribiendo a ciegas. La corrección de fondo es que el
 * documento del POS mida lo que se VE, así que ninguna pantalla nueva tenga
 * que acordarse de nada.
 *
 * Estos tests fijan las dos mitades de esa corrección y, sobre todo, la
 * frontera entre ellas: el descuento del shell y el de las superficies `fixed`
 * NO se suman (son árboles distintos), pero encadenar dos restas dentro del
 * MISMO árbol sí sería el bug — el mismo de "el botón de cobrar quedó
 * demasiado arriba" que cuida `safe-area.test.ts`, un eje descontado dos veces.
 */
describe("el teclado achica la lámina del POS, no cada pantalla", () => {
  it("el body fijado sube su borde inferior con el teclado", () => {
    const css = read("app/globals.css")
    const rule = css.match(/html\[data-pos-touch\] body \{[^}]*\}/g)?.at(-1) ?? ""
    expect(rule, "el body del POS no descuenta el teclado").toMatch(
      /bottom:\s*var\(--kb-inset\)/,
    )
    // `height: auto` es la mitad silenciosa del fix: con `position: fixed`, si
    // `top`, `height` y `bottom` están los tres, el navegador ignora `bottom`.
    // Sin esto el descuento de arriba no hace absolutamente nada y el síntoma
    // vuelve entero.
    expect(rule, "con height distinto de auto, `bottom` queda ignorado").toMatch(
      /height:\s*auto/,
    )
  })

  it("el shell repite el descuento porque `dvh` no ve el teclado", () => {
    // El body achicado no cambia cuánto mide `100dvh` (viewport de LAYOUT), y
    // el shell se cuelga de esa unidad porque `h-full` colapsa contra el
    // `min-h-svh` del wrapper del sidebar. Va DENTRO de la misma expresión de
    // alto: no es una segunda resta encadenada, es la misma medida.
    const shell = read("app/(pos)/layout.tsx")
    const inset = shell.match(/<SidebarInset[\s\S]*?>/)?.[0] ?? ""
    expect(inset).toMatch(/h-\[calc\(100dvh-var\(--kb-inset\)\)\]/)
    expect(inset).toMatch(/md:h-\[calc\(100dvh-1rem-var\(--kb-inset\)\)\]/)
  })

  it("nadie DENTRO del shell vuelve a restar el teclado", () => {
    // La frontera: `--kb-inset` solo puede aparecer en superficies que se
    // posicionan `fixed` contra el viewport —los portales de Radix y de vaul,
    // y los overlays a pantalla completa—, más el propio shell. Un componente
    // de ruta que la descuente estaría restando sobre un contenedor que ya
    // está achicado, y su contenido terminaría flotando el alto del teclado
    // por encima de donde va.
    const allowed = new Set([
      // La fuente y su sonda.
      "components/pos/keyboard-inset.tsx",
      "components/pos/viewport-probe.tsx",
      "lib/pos/__tests__/keyboard-inset.test.ts",
      // El default y la lámina.
      "app/globals.css",
      // El shell.
      "app/(pos)/layout.tsx",
      // Portales `fixed`: no son descendientes de nada del shell.
      "components/ui/dialog.tsx",
      "components/ui/drawer.tsx",
      "components/register/customer-dialog.tsx",
      // Overlay `fixed inset-x-0` a pantalla completa (el PIN).
      "components/register/lock-screen.tsx",
    ])
    const offenders = allSourceFiles()
      .filter((rel) => !allowed.has(rel))
      .filter((rel) => stripComments(read(rel)).includes("--kb-inset"))
    expect(
      offenders,
      `descuentan el teclado adentro del shell, que ya lo descontó: ${offenders.join(", ")}`,
    ).toEqual([])
  })

  it("el lock screen centra el PIN sobre el hueco visible", () => {
    // Es `fixed`, así que resuelve contra el VIEWPORT y el body achicado no lo
    // toca: sin su propio descuento, el `justify-center` centra los círculos
    // en la pantalla entera y el teclado numérico —que abre su propio input
    // invisible— los tapa.
    const lock = read("components/register/lock-screen.tsx")
    expect(lock).toMatch(/fixed inset-x-0 top-0 bottom-\[var\(--kb-inset\)\]/)
  })
})

/**
 * La sonda tiene que ser alcanzable en la PWA instalada.
 *
 * `?debug=viewport` es inútil justo donde importa: la caja corre sin barra de
 * direcciones, así que el único modo donde aparecen estos bugs es el único
 * donde no se puede escribir el query param. El interruptor de Ajustes se SUMA
 * al param, no lo reemplaza.
 */
describe("la sonda se puede prender sin URL", () => {
  it("el montaje acepta el query param Y el interruptor", () => {
    const layout = read("app/(pos)/pos/layout.tsx")
    expect(layout).toMatch(
      /searchParams\.get\("debug"\) === "viewport" \|\| viewportProbe/,
    )
  })

  it("el interruptor es del DISPOSITIVO y sobrevive al reload", () => {
    // Persistido, no en memoria: el POS se recarga y se bloquea sola, y una
    // sonda que se apaga en cada reload no sirve para observar el arranque.
    // Local y no en `posConfig` del register: es una herramienta de ESTE
    // teléfono, no un ajuste del comercio que valga para todas sus cajas.
    const store = read("lib/pos/debug-store.ts")
    expect(store).toMatch(/persist\(/)
    expect(store).toMatch(/localStorage/)
  })

  it("Ajustes del POS expone el interruptor", () => {
    expect(read("components/register/pos-main-menu.tsx")).toContain(
      "Diagnóstico de viewport",
    )
  })
})

/**
 * La FÓRMULA de la medición — el bug que costó tres reportes del owner.
 *
 * `--kb-inset` se calculaba como `innerHeight - vv.height - vv.offsetTop`, y
 * ese tercer término era el error: `offsetTop` es POSICIÓN (cuánto desplazó el
 * navegador el viewport visual dentro del de layout), no altura. Cuando iOS
 * desplaza para revelar el campo enfocado crece hasta casi lo que mide el
 * teclado, así que la resta se cancelaba sola, `covered` caía bajo el umbral y
 * la variable quedaba en 0 — con todo el sistema descontando cero JUSTO cuando
 * el teclado estaba abierto. Las capturas del owner (2026-08-31) mostraban el
 * PIN y la nota de venta tapados con la pantalla sin moverse un pixel.
 *
 * El síntoma es indistinguible de "no hay teclado", que es lo que lo hizo
 * sobrevivir a dos rondas de arreglos en las superficies. Por eso se fija acá:
 * quien toque la fórmula tiene que romper este test a propósito.
 */
describe("la medición del teclado no mezcla posición con altura", () => {
  const source = stripComments(read("components/pos/keyboard-inset.tsx"))

  it("no resta offsetTop del alto tapado", () => {
    expect(
      source,
      "`offsetTop` es posición, no altura: restarlo anula la medición cuando iOS desplaza el viewport",
    ).not.toMatch(/-\s*(vv|visualViewport)\.offsetTop/)
  })

  it("mide contra el alto que usa el CSS, no contra innerHeight", () => {
    // En iOS con la PWA instalada `innerHeight` SIGUE al viewport visual: vale
    // lo mismo que `vv.height` y la resta da 0 siempre (medición del owner,
    // 2026-08-31: innerHeight 441 / vv.height 441 / clientHeight 797). El marco
    // del que hay que descontar es contra el que resuelven `100dvh` y los
    // elementos `fixed`, o sea `documentElement.clientHeight`.
    expect(
      source,
      "`innerHeight` no es el viewport de layout en iOS standalone",
    ).not.toMatch(/window\.innerHeight\s*-/)
    expect(source).toMatch(
      /document\.documentElement\.clientHeight[\s\S]{0,80}-\s*vv\.height/,
    )
  })

  it("nunca publica un inset negativo", () => {
    // Un navegador que achique el viewport de LAYOUT (Chrome con
    // `interactive-widget=resizes-content`) deja `innerHeight` ya reducido y la
    // diferencia puede dar negativa. Ahí el contenido se reacomodó solo y el
    // descuento correcto es 0, no un valor que empuje el layout al revés.
    expect(source).toMatch(/Math\.max\(\s*0\s*,/)
  })
})
