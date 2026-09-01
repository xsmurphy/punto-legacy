import { describe, expect, it } from "vitest"
import { readdirSync, readFileSync } from "node:fs"
import path from "node:path"

import { keyboardWindow } from "../keyboard-window"

/**
 * Guard del TECLADO VIRTUAL, hermano de `safe-area.test.ts`.
 *
 * El teclado no achica el viewport de layout en iOS, así que `dvh` no lo ve; y
 * además WebKit DESPLAZA el viewport visual dentro del de layout, así que
 * saber cuánto tapa no alcanza para saber dónde dibujar. La medición vive en UN
 * solo lugar (`components/pos/keyboard-inset.tsx` + la aritmética pura de
 * `lib/pos/keyboard-window.ts`) y se publica como tres variables:
 *
 *     --kb-top      lo tapado por ARRIBA      → POSICIONA
 *     --kb-bottom   lo tapado por ABAJO       → POSICIONA
 *     --kb-inset    el total tapado           → DIMENSIONA
 *
 * Estos tests cuidan las tres mitades: que la medición no se disperse en
 * call-sites, que la ARITMÉTICA sea la correcta, y que ningún consumidor de
 * POSICIÓN vuelva a apoyarse en el total tapado — que es el bug que se arregló
 * dos veces al revés antes de entenderlo (ver el docblock de
 * `keyboard-window.ts`).
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
    //
    // `lib/pos/keyboard-window.ts` NO está en la lista y no hace falta: recibe
    // tres números y no toca el DOM. Esa es justamente la razón de haberla
    // separado — la aritmética se testea contra su resultado y no contra una
    // expresión regular sobre el código fuente del componente.
    const allowed = new Set([
      "components/pos/keyboard-inset.tsx",
      "lib/pos/__tests__/keyboard-inset.test.ts",
      // Sonda de diagnóstico (`?debug=viewport`): LEE y muestra números, no
      // escribe las variables ni las usa nadie para maquetar. La regla que
      // cuida este test es que haya una sola FUENTE de la medición; un
      // observador de solo lectura, montado bajo un query param, no la duplica
      // — y es la única forma de ver estos valores en una PWA de iOS, donde no
      // hay devtools.
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

  it("las tres variables tienen default en globals.css", () => {
    // Sin el default, cualquier `calc()` que las use queda inválido en el panel
    // y en el desktop, donde nadie las escribe. Y un `calc()` inválido en el
    // `top` de un diálogo no degrada: lo manda a la esquina.
    const css = read("app/globals.css")
    expect(css).toMatch(/--kb-top:\s*0px/)
    expect(css).toMatch(/--kb-bottom:\s*0px/)
    expect(css).toMatch(/--kb-inset:\s*0px/)
  })

  it("el medidor publica las tres", () => {
    const source = read("components/pos/keyboard-inset.tsx")
    for (const name of ["--kb-top", "--kb-bottom", "--kb-inset"]) {
      expect(source).toContain(`setProperty("${name}"`)
      // Y las limpia al desmontar: fuera del POS nadie las consume, y una
      // variable colgada dejaría al panel maquetando contra un teclado que ya
      // no está.
      expect(source).toContain(`removeProperty("${name}")`)
    }
  })

  it("el POS monta el medidor", () => {
    expect(read("app/(pos)/layout.tsx")).toContain("<PosKeyboardInset />")
  })
})

/**
 * LA ARITMÉTICA — el corazón del bug, y lo único que se puede testear de
 * verdad. Los números salen de la sonda `?debug=viewport` en el iPhone del
 * owner con la PWA instalada y el teclado abierto (2026-08-31):
 *
 *     html.clientHeight 797   visualViewport.h 441   visualViewport.top 356
 *
 * O sea: lo que se ve es el tramo [356, 797] de un viewport de layout de 797.
 */
describe("la ventana visible del teclado", () => {
  const IOS = { layout: 797, visual: 441, offset: 356 }

  it("iOS con desplazamiento: todo lo tapado está ARRIBA", () => {
    // El caso reportado. `top` es lo que faltaba: sin él, un `fixed` con
    // `top: 0; bottom: 356px` ocupa [0, 441] —la mitad que iOS scrolleó fuera
    // de vista— y el PIN aparece pegado al borde superior.
    expect(keyboardWindow(IOS.layout, IOS.visual, IOS.offset)).toEqual({
      top: 356,
      bottom: 0,
      inset: 356,
    })
  })

  it("iOS sin desplazamiento: todo lo tapado está ABAJO", () => {
    // Mismo teclado, campo arriba de todo: WebKit no necesita desplazar nada.
    // El total no cambia; cambia el REPARTO.
    expect(keyboardWindow(IOS.layout, IOS.visual, 0)).toEqual({
      top: 0,
      bottom: 356,
      inset: 356,
    })
  })

  it("desplazamiento parcial: se reparte, no se duplica", () => {
    expect(keyboardWindow(IOS.layout, IOS.visual, 100)).toEqual({
      top: 100,
      bottom: 256,
      inset: 356,
    })
  })

  it("`inset` NO se achica con el desplazamiento", () => {
    // La regresión de la primera versión: restar `offsetTop` del alto tapado
    // hacía que la cuenta se cancelara sola justo cuando iOS desplazaba, el
    // total caía bajo el umbral y todo el sistema descontaba cero con el
    // teclado arriba. `inset` depende de las dos ALTURAS y de nada más.
    const sinDesplazar = keyboardWindow(IOS.layout, IOS.visual, 0).inset
    for (const offset of [0, 50, 200, 356]) {
      expect(keyboardWindow(IOS.layout, IOS.visual, offset).inset).toBe(
        sinDesplazar,
      )
    }
  })

  it("teclado cerrado: las tres en cero", () => {
    expect(keyboardWindow(797, 797, 0)).toEqual({ top: 0, bottom: 0, inset: 0 })
  })

  it("un `offsetTop` residual sin teclado NO se publica", () => {
    // Sin teclado, un desplazamiento sobrante es basura a RESTAURAR (el
    // `scrollTo(0,0)` de `keyboard-inset.tsx`), no un hueco contra el que
    // maquetar. Publicarlo correría la app entera hacia abajo.
    expect(keyboardWindow(797, 797, 60)).toEqual({
      top: 0,
      bottom: 0,
      inset: 0,
    })
  })

  it("la barra del navegador no es un teclado", () => {
    // 60-90px de diferencia son el chrome del navegador entrando y saliendo.
    // Ningún teclado virtual mide menos de ~250px.
    expect(keyboardWindow(844, 754, 0).inset).toBe(0) // 90
    expect(keyboardWindow(844, 724, 0).inset).toBe(0) // 120: el umbral es `>`
    expect(keyboardWindow(844, 723, 0).inset).toBe(121)
  })

  it("Android/Chrome que ACHICA el viewport de layout no descuenta nada", () => {
    // `interactive-widget=resizes-content`: `clientHeight` baja junto con
    // `vv.height`, la diferencia da ~0 y el contenido ya se reacomodó solo.
    // Es el caso que el código anterior manejaba bien y que un cambio de
    // fórmula puede romper sin que se note en el iPhone.
    expect(keyboardWindow(441, 441, 0)).toEqual({
      top: 0,
      bottom: 0,
      inset: 0,
    })
    // Ni siquiera si el redondeo lo deja negativo: nunca un valor que empuje
    // el layout al revés.
    expect(keyboardWindow(440, 441, 0)).toEqual({
      top: 0,
      bottom: 0,
      inset: 0,
    })
  })

  it("Android/Chrome que NO lo achica se comporta como antes del cambio", () => {
    // Viewport de layout intacto y sin desplazamiento: todo lo tapado cae en
    // `--kb-bottom`, que es literalmente lo que valía `--kb-inset` antes. Este
    // test es el que impide "arreglar" iOS rompiendo Android.
    const w = keyboardWindow(797, 441, 0)
    expect(w.bottom).toBe(w.inset)
    expect(w.top).toBe(0)
  })

  it("clampea un `offsetTop` fuera de rango", () => {
    // Defensivo: `bottom` se deriva restando, así que un `top` mayor que el
    // total daría un `bottom` negativo.
    expect(keyboardWindow(797, 441, 400)).toEqual({
      top: 356,
      bottom: 0,
      inset: 356,
    })
    expect(keyboardWindow(797, 441, -5)).toEqual({
      top: 0,
      bottom: 356,
      inset: 356,
    })
  })

  it("con el viewport sin medir cae del lado cerrado", () => {
    expect(keyboardWindow(NaN, 441, 0)).toEqual({
      top: 0,
      bottom: 0,
      inset: 0,
    })
  })

  it("INVARIANTE: `top + bottom === inset`, siempre", () => {
    // La suma no cambia; cambia el reparto. Si esto se rompe, alguna
    // superficie va a quedar con un hueco o con solape del tamaño del error —
    // y con fracciones del dispositivo real, no con enteros redondos.
    for (let layout = 400; layout <= 900; layout += 37) {
      for (let visual = 100; visual <= layout; visual += 53) {
        for (const offset of [0, 17, 128.6, layout - visual, layout]) {
          const w = keyboardWindow(layout + 0.4, visual - 0.2, offset)
          expect(w.top + w.bottom).toBe(w.inset)
          expect(w.top).toBeGreaterThanOrEqual(0)
          expect(w.bottom).toBeGreaterThanOrEqual(0)
          expect(Number.isInteger(w.top)).toBe(true)
          expect(Number.isInteger(w.bottom)).toBe(true)
          expect(Number.isInteger(w.inset)).toBe(true)
        }
      }
    }
  })
})

/**
 * LA FRONTERA POSICIÓN / DIMENSIÓN — la regla que este commit instala.
 *
 * `--kb-inset` sigue siendo correcto para DIMENSIONAR (`max-height`): el alto
 * visible es `layout - total tapado`, no importa cómo se reparta. Es incorrecto
 * para POSICIONAR: `top: 0; bottom: var(--kb-inset)` describe un tramo del
 * tamaño justo pero en el lugar equivocado, y en el iPhone del owner ese lugar
 * estaba entero fuera de pantalla.
 */
describe("los consumidores de POSICIÓN usan el par, no el total", () => {
  /** Utilidades de Tailwind que escriben `top` / `bottom` / `inset-y`. */
  const POSITION_UTILITY = /(?<![\w-])(top|bottom|inset-y)-\[[^\]]*\]/g

  function positionOffenders(src: string): string[] {
    return [...src.matchAll(POSITION_UTILITY)]
      .filter(([whole, prop]) => {
        if (!whole.includes("--kb-inset")) return false
        // El único uso legítimo del total en una coordenada: el centrado
        // (`top: kb-top + 50% - inset/2`), donde `--kb-inset` aporta la MITAD
        // del alto visible y `--kb-top` el origen. Sin `--kb-top` al lado, es
        // el bug.
        return prop !== "top" || !whole.includes("--kb-top")
      })
      .map(([whole]) => whole)
  }

  it("ninguna coordenada se apoya en `--kb-inset` a secas", () => {
    // Sin comentarios: los docblocks de este arreglo CITAN la forma vieja
    // (`top-0 bottom-[var(--kb-inset)]`) para explicar por qué falló, y contar
    // esa cita como infracción obligaría a borrar justo la documentación que
    // impide repetirla.
    const offenders = allSourceFiles().flatMap((rel) =>
      positionOffenders(stripComments(read(rel))).map((m) => `${rel}: ${m}`),
    )
    expect(
      offenders,
      `posicionan con el total tapado en vez de con la ventana visible ` +
        `(--kb-top / --kb-bottom): ${offenders.join(", ")}`,
    ).toEqual([])
  })

  it("el diálogo centrado se acota con el total y se centra con el par", () => {
    const dialog = read("components/ui/dialog.tsx")
    // `max-h` DIMENSIONA → total tapado.
    expect(dialog).toMatch(/max-h-\[min\(85dvh,[^\]]*var\(--kb-inset\)/)
    // `top` POSICIONA → origen de la ventana visible + medio alto visible.
    // Con la medición del owner: 356 + 797/2 - 356/2 = 576.5, el centro real
    // de [356, 797]. Antes daba 220, fuera de pantalla por arriba.
    expect(dialog).toContain("top-[calc(var(--kb-top)+50%-var(--kb-inset)/2)]")
  })

  it("el fullscreen móvil se apoya en los dos bordes visibles", () => {
    const dialog = read("components/ui/dialog.tsx")
    expect(dialog).toContain("max-sm:top-[var(--kb-top)]")
    expect(dialog).toContain("max-sm:bottom-[var(--kb-bottom)]")
  })

  it("el drawer bottom apoya en `--kb-bottom` y se acota con `--kb-inset`", () => {
    const drawer = read("components/ui/drawer.tsx")
    expect(drawer).toContain(
      "data-[vaul-drawer-direction=bottom]:bottom-[var(--kb-bottom)]",
    )
    expect(drawer).toMatch(
      /data-\[vaul-drawer-direction=bottom\]:max-h-\[min\(80vh,calc\(100dvh-var\(--kb-inset\)-2rem\)\)\]/,
    )
    // El `pb` no posiciona ni dimensiona: pregunta "¿hay teclado?", y el total
    // es la única de las tres que lo responde en las dos plataformas
    // (`--kb-bottom` vale 0 en el iPhone CON el teclado arriba).
    expect(drawer).toContain(
      "pb-[max(1rem,calc(var(--safe-b)-var(--kb-inset)))]",
    )
  })

  it("el Sheet no queda abarcando el viewport de layout", () => {
    // Es un portal `fixed` que cuelga del `<body>`: NO hereda el
    // reposicionamiento del body del POS, así que `inset-y-0` lo dejaba con el
    // header fuera de vista. Lo consume el asistente de la caja.
    const sheet = read("components/ui/sheet.tsx")
    for (const side of ["left", "right"]) {
      expect(sheet).toContain(`data-[side=${side}]:top-[var(--kb-top)]`)
      expect(sheet).toContain(`data-[side=${side}]:bottom-[var(--kb-bottom)]`)
      // `h-full` + los dos bordes es una caja sobre-restringida: el navegador
      // descartaría `bottom` y el descuento no haría nada.
      expect(sheet).toContain(`data-[side=${side}]:h-auto`)
    }
    expect(sheet).not.toMatch(/data-\[side=(left|right)\]:h-full/)
  })

  it("los command palettes anclan su offset a la ventana visible", () => {
    // Pisan el centrado del primitive con `top-[Ndvh] translate-y-0`, así que
    // el primitive no los cubre: ese offset es desde el borde del LAYOUT y con
    // el teclado abierto arranca fuera de pantalla.
    expect(read("components/register/customer-dialog.tsx")).toContain(
      "top-[calc(var(--kb-top)+7dvh)]",
    )
    expect(read("components/register/product-search-dialog.tsx")).toContain(
      "top-[calc(var(--kb-top)+10vh)]",
    )
  })

  it("el buscador de clientes recorta su alto con el teclado abierto", () => {
    expect(read("components/register/customer-dialog.tsx")).toMatch(
      /max-h-\[calc\(86dvh-var\(--kb-inset\)\)\]/,
    )
  })

  it("el buscador de productos también recorta su alto", () => {
    // Hasta el 2026-09-01 este no consumía NINGUNA de las variables: `10vh` +
    // `80vh` medían contra el viewport grande y el diálogo pedía 675px de alto
    // para un hueco de 441.
    expect(read("components/register/product-search-dialog.tsx")).toMatch(
      /max-h-\[calc\(80dvh-var\(--kb-inset\)\)\]/,
    )
  })

  it("la sonda se centra sobre lo visible, o no se ve cuando importa", () => {
    // `?debug=viewport` es la única herramienta de diagnóstico en el
    // dispositivo real. Con `top-1/2` a secas quedaba fuera de pantalla
    // justamente con el teclado abierto.
    expect(read("components/pos/viewport-probe.tsx")).toContain(
      "top-[calc(var(--kb-top)+50%-var(--kb-inset)/2)]",
    )
  })
})

/**
 * El shell se achica UNA vez y las superficies fijas se posicionan solas.
 *
 * Lo anterior arreglaba modal por modal, y el owner volvió con "eso está
 * pasando con muchas cosas en el POS" (2026-08-30): cualquier pantalla de ruta
 * con un campo seguía escribiendo a ciegas. La corrección de fondo es que el
 * documento del POS ocupe lo que se VE, así que ninguna pantalla nueva tenga
 * que acordarse de nada.
 *
 * Estos tests fijan las dos mitades de esa corrección y, sobre todo, la
 * frontera entre ellas: el descuento del shell y el de las superficies `fixed`
 * NO se suman (son árboles distintos), pero encadenar dos restas dentro del
 * MISMO árbol sí sería el bug — el mismo de "el botón de cobrar quedó
 * demasiado arriba" que cuida `safe-area.test.ts`, un eje descontado dos veces.
 */
describe("el teclado achica la lámina del POS, no cada pantalla", () => {
  it("el body fijado se mueve a la ventana visible", () => {
    const css = read("app/globals.css")
    const rule = css.match(/html\[data-pos-touch\] body \{[^}]*\}/g)?.at(-1) ?? ""
    // Los DOS bordes. Con `top: 0` el alto era correcto y la posición no: la
    // lámina se dibujaba en [0, 441] mientras lo visible era [356, 797], o sea
    // la app entera por encima de la pantalla (capturas del owner 2026-08-31).
    expect(rule, "el body del POS no se apoya en la ventana visible").toMatch(
      /top:\s*var\(--kb-top\)/,
    )
    expect(rule).toMatch(/bottom:\s*var\(--kb-bottom\)/)
    // `height: auto` es la mitad silenciosa del fix: con `position: fixed`, si
    // `top`, `height` y `bottom` están los tres, el navegador ignora `bottom`.
    // Sin esto el descuento no hace absolutamente nada y el síntoma vuelve
    // entero.
    expect(rule, "con height distinto de auto, `bottom` queda ignorado").toMatch(
      /height:\s*auto/,
    )
  })

  it("el shell repite el descuento porque `dvh` no ve el teclado", () => {
    // El body reposicionado no cambia cuánto mide `100dvh` (viewport de
    // LAYOUT), y el shell se cuelga de esa unidad porque `h-full` colapsa
    // contra el `min-h-svh` del wrapper del sidebar. DIMENSIONA, así que usa el
    // total tapado. Va DENTRO de la misma expresión de alto: no es una segunda
    // resta encadenada, es la misma medida.
    const shell = read("app/(pos)/layout.tsx")
    const inset = shell.match(/<SidebarInset[\s\S]*?>/)?.[0] ?? ""
    expect(inset).toMatch(/h-\[calc\(100dvh-var\(--kb-inset\)\)\]/)
    expect(inset).toMatch(/md:h-\[calc\(100dvh-1rem-var\(--kb-inset\)\)\]/)
  })

  it("nadie DENTRO del shell vuelve a restar el teclado", () => {
    // La frontera: las variables solo pueden aparecer en superficies que se
    // posicionan `fixed` contra el viewport —los portales de Radix y de vaul,
    // y los overlays a pantalla completa—, más el propio shell. Un componente
    // de ruta que las descuente estaría restando sobre un contenedor que ya
    // está achicado, y su contenido terminaría flotando el alto del teclado
    // por encima de donde va.
    const allowed = new Set([
      // La fuente, su aritmética y su sonda.
      "components/pos/keyboard-inset.tsx",
      "lib/pos/keyboard-window.ts",
      "components/pos/viewport-probe.tsx",
      "lib/pos/__tests__/keyboard-inset.test.ts",
      // Los defaults y la lámina.
      "app/globals.css",
      // El shell.
      "app/(pos)/layout.tsx",
      // Portales `fixed`: no son descendientes de nada del shell.
      "components/ui/dialog.tsx",
      "components/ui/drawer.tsx",
      "components/ui/sheet.tsx",
      // Command palettes que PISAN el centrado del primitive y por eso tienen
      // que resolver su propia coordenada.
      "components/register/customer-dialog.tsx",
      "components/register/product-search-dialog.tsx",
      // Overlay `fixed inset-x-0` a pantalla completa (el PIN).
      "components/register/lock-screen.tsx",
    ])
    const offenders = allSourceFiles()
      .filter((rel) => !allowed.has(rel))
      .filter((rel) => /--kb-(top|bottom|inset)/.test(stripComments(read(rel))))
    expect(
      offenders,
      `descuentan el teclado adentro del shell, que ya lo descontó: ${offenders.join(", ")}`,
    ).toEqual([])
  })

  it("el lock screen centra el PIN sobre el hueco visible", () => {
    // Es `fixed`, así que resuelve contra el VIEWPORT y el body reposicionado
    // no lo toca: sin sus propios bordes, el `justify-center` centra los
    // círculos en la pantalla entera —o, con el total tapado a secas, 136px
    // por encima del borde de arriba, que es la captura del owner.
    const lock = read("components/register/lock-screen.tsx")
    expect(lock).toMatch(
      /fixed inset-x-0 top-\[var\(--kb-top\)\] bottom-\[var\(--kb-bottom\)\]/,
    )
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

  it("muestra las tres variables y las tres cuentas al lado", () => {
    // El contraste variable-publicada vs cuenta-recalculada es lo que hace
    // diagnosticable a la sonda: dice si un 0 es "no hay teclado" o "la
    // medición no lo ve". Ya cazó dos bugs; tiene que cubrir las TRES, no solo
    // el total.
    const probe = read("components/pos/viewport-probe.tsx")
    for (const name of ["--kb-top", "--kb-bottom", "--kb-inset"]) {
      expect(probe).toContain(`"${name}"`)
    }
    for (const row of ["top(calc)", "bottom(calc)", "covered(calc)"]) {
      expect(probe).toContain(row)
    }
    // Y las recalcula con la MISMA función que publica las variables: una
    // sonda que se desincroniza de la fuente diagnostica su propia copia.
    expect(probe).toContain("keyboardWindow(")
    expect(probe).toContain("visualViewport.top")
  })
})

/**
 * LA FÓRMULA de la medición — el bug que costó tres reportes del owner.
 *
 * Los dos errores de origen ya no se pueden cometer sin romper los tests de
 * aritmética de arriba, pero el componente sigue siendo quien elige QUÉ le
 * pasa a la función, y ahí es donde se metieron las dos veces:
 * `window.innerHeight` en vez del alto de layout, y `offsetTop` restado del
 * alto tapado en vez de usado como origen.
 */
describe("la medición del teclado no mezcla posición con altura", () => {
  const source = stripComments(read("components/pos/keyboard-inset.tsx"))

  it("no resta offsetTop de ninguna altura", () => {
    expect(
      source,
      "`offsetTop` es el ORIGEN de la ventana visible, no un término a restar del alto tapado",
    ).not.toMatch(/-\s*(vv|visualViewport)\.offsetTop/)
  })

  it("mide contra el alto que usa el CSS, no contra innerHeight", () => {
    // En iOS con la PWA instalada `innerHeight` SIGUE al viewport visual: vale
    // lo mismo que `vv.height` y la resta da 0 siempre (medición del owner,
    // 2026-08-31: innerHeight 441 / vv.height 441 / clientHeight 797). El marco
    // a repartir es contra el que resuelven `100dvh` y los elementos `fixed`, o
    // sea `documentElement.clientHeight`.
    expect(
      source,
      "`innerHeight` no es el viewport de layout en iOS standalone",
    ).not.toMatch(/window\.innerHeight\s*-/)
    expect(source).toMatch(
      /keyboardWindow\(\s*document\.documentElement\.clientHeight,\s*vv\.height,\s*vv\.offsetTop\s*\)/,
    )
  })
})
