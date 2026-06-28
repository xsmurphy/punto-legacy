/**
 * Print via diálogo nativo del browser usando un **iframe oculto**.
 *
 * Por qué iframe y no `window.open()`:
 *   - `window.open` dispara popup blocker en muchos browsers; cuando el user
 *     bloquea el popup, el flujo se rompe silencioso.
 *   - Cerrar el popup deja el contexto al main window con `window.print()`
 *     pendiente → el browser muestra un SEGUNDO diálogo con el HTML del
 *     dialog de pago, no del recibo. Bug reportado 2026-06-28.
 *   - El popup hereda algún CSS de la app y produce flicker (light/dark)
 *     hasta que el navegador lo arma como documento aparte.
 *
 * El iframe es un documento aislado: no hereda CSS del padre, no compite
 * por foco, no abre ventanas, no es popup-bloqueable. Imprimimos su
 * `contentWindow.print()` y lo removemos al terminar (afterprint listener
 * o timeout de seguridad).
 *
 * La plantilla HTML que viene como input ya trae su propio `<style>` y/o
 * estructura; el iframe la renderiza tal cual.
 */
export function triggerWindowPrint(html: string): void {
  if (typeof window === "undefined") return

  const iframe = document.createElement("iframe")
  iframe.setAttribute("aria-hidden", "true")
  iframe.style.position = "fixed"
  iframe.style.right = "0"
  iframe.style.bottom = "0"
  iframe.style.width = "0"
  iframe.style.height = "0"
  iframe.style.border = "0"
  iframe.style.visibility = "hidden"
  // srcdoc en vez de open()/write(): el browser inicializa el documento
  // aislado en una sola operación, sin ventanas-de-medio-estado.
  iframe.srcdoc = html

  let removed = false
  const cleanup = () => {
    if (removed) return
    removed = true
    iframe.parentNode?.removeChild(iframe)
  }

  iframe.onload = () => {
    const win = iframe.contentWindow
    if (!win) {
      cleanup()
      return
    }
    // afterprint dispara al cerrar el diálogo (Print o Cancel).
    win.addEventListener("afterprint", cleanup, { once: true })
    // Fallback: algunos browsers (Safari iOS) no emiten afterprint o el
    // user puede dejar el diálogo abierto. Remover el iframe a los 60s
    // garantiza no leakear nodos sin interferir con el print en curso.
    setTimeout(cleanup, 60_000)
    // focus() necesario en Firefox para que print() use el iframe.
    win.focus()
    win.print()
  }

  document.body.appendChild(iframe)
}
