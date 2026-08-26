"use client"

import * as React from "react"

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
        ["--safe-t", cs.getPropertyValue("--safe-t").trim() || "—"],
        ["--safe-b", cs.getPropertyValue("--safe-b").trim() || "—"],
        ["--kb-inset", cs.getPropertyValue("--kb-inset").trim() || "—"],
      ])
    }

    measure()
    window.addEventListener("resize", measure)
    window.visualViewport?.addEventListener("resize", measure)
    return () => {
      window.removeEventListener("resize", measure)
      window.visualViewport?.removeEventListener("resize", measure)
    }
  }, [])

  return (
    <div className="fixed left-2 top-1/2 z-[200] -translate-y-1/2 rounded-lg bg-black/85 p-3 font-mono text-[11px] leading-tight text-white shadow-xl">
      {rows.map(([k, v]) => (
        <div key={k} className="flex justify-between gap-3">
          <span className="opacity-70">{k}</span>
          <span className="tabular-nums">{v}</span>
        </div>
      ))}
    </div>
  )
}
