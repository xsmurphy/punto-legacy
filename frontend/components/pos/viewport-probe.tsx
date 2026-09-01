"use client"

import * as React from "react"

import { keyboardWindow } from "@/lib/pos/keyboard-window"

/**
 * Sonda de viewport — se monta SOLO con `?debug=viewport` en la URL del POS.
 *
 * Existe porque el "gap abajo" del iPhone se persiguió tres veces a ciegas: no
 * hay devtools en una PWA instalada en iOS, así que cada hipótesis (áreas
 * seguras, doble descuento, caché del meta viewport al instalar) se probaba
 * deployando y mirando una foto. Esto pone los números en la pantalla para
 * que una captura los responda de una vez.
 *
 * Qué mirar:
 *   - `innerHeight` vs `screen.height`: si difieren por ~34pt, el viewport de
 *     la app NO llega al borde físico y ningún CSS puede taparlo (el meta
 *     `viewport-fit=cover` no está aplicando).
 *   - `dvh/svh/lvh` medidos: si `dvh` < `body`, las unidades de viewport están
 *     reportando el viewport chico y el shell no debe colgarse de ellas.
 *   - `--safe-b`: 0 en la PWA significa que iOS no expone el inset, que es la
 *     firma de que `cover` no se aplicó.
 *   - `standalone`: distingue PWA instalada de Safari/Chrome.
 *
 * No se borra al cerrar el bug: es la herramienta para el próximo. Cuesta cero
 * mientras nadie ponga el query param.
 */
export function ViewportProbe() {
  const [rows, setRows] = React.useState<Array<[string, string]>>([])

  React.useEffect(() => {
    function measure() {
      const el = document.createElement("div")
      el.style.cssText = "position:fixed;top:0;left:0;width:0;pointer-events:none;visibility:hidden"
      document.body.appendChild(el)
      const unit = (u: string) => {
        el.style.height = `100${u}`
        return Math.round(el.getBoundingClientRect().height)
      }
      const dvh = unit("dvh")
      const svh = unit("svh")
      const lvh = unit("lvh")
      el.remove()

      const cs = getComputedStyle(document.documentElement)
      const vv = window.visualViewport
      const standalone =
        window.matchMedia("(display-mode: standalone)").matches ||
        // iOS expone su propio flag no estándar.
        (navigator as unknown as { standalone?: boolean }).standalone === true

      setRows([
        ["standalone", String(standalone)],
        ["dpr", String(window.devicePixelRatio)],
        ["screen.height", String(window.screen.height)],
        ["innerHeight", String(window.innerHeight)],
        ["body.clientHeight", String(document.body.clientHeight)],
        ["html.clientHeight", String(document.documentElement.clientHeight)],
        ["100dvh", String(dvh)],
        ["100svh", String(svh)],
        ["100lvh", String(lvh)],
        ["visualViewport.h", vv ? String(Math.round(vv.height)) : "—"],
        // Las filas `(calc)` son EL diagnóstico del teclado virtual: la sonda
        // recalcula por su cuenta lo que `keyboard-inset.tsx` publica, así que
        // verlos uno al lado del otro dice si una variable está en 0 porque no
        // hay teclado o porque la medición no lo ve. Ese contraste ya cazó dos
        // bugs y por eso se mantiene para las TRES, no solo para el total.
        //
        // El primero: en el iPhone del owner con la PWA instalada y el teclado
        // abierto, `innerHeight` marcó 441 y `visualViewport.h` marcó 441
        // TAMBIÉN — `innerHeight` sigue al viewport VISUAL, no al de layout.
        // Restarlos daba 0 y el inset nunca se publicaba. El alto correcto es
        // `html.clientHeight` (797 ahí), contra el que resuelven `100dvh` y los
        // elementos `fixed`: 797 - 441 = 356.
        //
        // El segundo: `visualViewport.top` valía 356 con el body ya fijado, o
        // sea que iOS desplaza el viewport visual igual. Un solo número no
        // alcanzaba —dice cuánto tapa, no dónde— y todo lo `fixed` terminó
        // dibujado fuera de pantalla por arriba. De ahí el par: lo visible es
        // el tramo [top, layout - bottom] del viewport de layout.
        //
        // QUÉ MIRAR: `top(calc) + bottom(calc)` tiene que dar `covered(calc)`,
        // y cada `(calc)` tiene que coincidir con su variable. Si `--kb-top`
        // marca 0 con `visualViewport.top` en 356, volvió el bug del 2026-08-31.
        ["visualViewport.top", vv ? String(Math.round(vv.offsetTop)) : "—"],
        ...(vv
          ? (() => {
              const w = keyboardWindow(
                document.documentElement.clientHeight,
                vv.height,
                vv.offsetTop
              )
              return [
                ["top(calc)", String(w.top)],
                ["bottom(calc)", String(w.bottom)],
                ["covered(calc)", String(w.inset)],
              ] as Array<[string, string]>
            })()
          : ([
              ["top(calc)", "—"],
              ["bottom(calc)", "—"],
              ["covered(calc)", "—"],
            ] as Array<[string, string]>)),
        ["--safe-t", cs.getPropertyValue("--safe-t").trim() || "—"],
        ["--safe-b", cs.getPropertyValue("--safe-b").trim() || "—"],
        ["--kb-top", cs.getPropertyValue("--kb-top").trim() || "—"],
        ["--kb-bottom", cs.getPropertyValue("--kb-bottom").trim() || "—"],
        ["--kb-inset", cs.getPropertyValue("--kb-inset").trim() || "—"],
      ])
    }

    measure()
    window.addEventListener("resize", measure)
    window.visualViewport?.addEventListener("resize", measure)
    // `scroll` del viewport VISUAL además de `resize`: iOS desplaza ese
    // viewport para revelar el campo enfocado sin cambiar su alto, así que sin
    // este listener el probe mostraría un `offsetTop` viejo — justo el término
    // que puede estar faltando en la cuenta. `keyboard-inset.tsx` escucha los
    // dos por el mismo motivo; el probe tiene que medir igual que él o no
    // sirve para diagnosticarlo.
    window.visualViewport?.addEventListener("scroll", measure)
    return () => {
      window.removeEventListener("resize", measure)
      window.visualViewport?.removeEventListener("resize", measure)
      window.visualViewport?.removeEventListener("scroll", measure)
    }
  }, [])

  return (
    // La sonda se centra sobre la ventana VISIBLE, no sobre el viewport de
    // layout: era `top-1/2` a secas, y con el teclado abierto —el único
    // momento en que estos números importan— quedaba dibujada fuera de
    // pantalla. Misma expresión que el diálogo centrado de
    // `components/ui/dialog.tsx`.
    <div className="fixed left-2 top-[calc(var(--kb-top)+50%-var(--kb-inset)/2)] z-[200] -translate-y-1/2 rounded-lg bg-black/85 p-3 font-mono text-[11px] leading-tight text-white shadow-xl">
      {rows.map(([k, v]) => (
        <div key={k} className="flex justify-between gap-3">
          <span className="opacity-70">{k}</span>
          <span className="tabular-nums">{v}</span>
        </div>
      ))}
    </div>
  )
}
