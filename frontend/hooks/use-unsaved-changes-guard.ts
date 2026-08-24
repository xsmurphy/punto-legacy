"use client"

import * as React from "react"

/**
 * Aviso antes de perder ediciones sin guardar (pedido owner 2026-08-24:
 * "sectores donde hay ediciones que puedan ser destructivas si no se guardan
 * ... antes de cerrar o navegar fuera de la página te avise con un Confirm
 * nativo"). El caso que lo motivó es el editor de plantillas de impresión, que
 * es trabajo de precisión (posicionar bloques al milímetro) y no tiene
 * autoguardado — pero el patrón es de la app entera, por eso vive acá y no
 * dentro de esa pantalla.
 *
 * ── Qué salidas cubre ────────────────────────────────────────────────────
 *
 * 1. **Cerrar / recargar la pestaña** (`beforeunload`). El texto NO se puede
 *    customizar: los browsers ignoran cualquier mensaje propio desde ~2016 y
 *    muestran el suyo ("¿Salir del sitio?"). Es una limitación del browser,
 *    no algo a resolver acá — `message` solo aplica al camino 2.
 *
 * 2. **Navegar dentro de la app** con el App Router de Next. `beforeunload` NO
 *    dispara acá (no hay unload: el router intercambia el árbol de React sin
 *    salir del documento). Se cubre con un listener de `click` en fase de
 *    CAPTURA sobre `document`: corre antes que el handler de `<Link>` (React
 *    19 engancha sus listeners en el contenedor raíz, que está DENTRO de
 *    `document`), así que `stopImmediatePropagation()` alcanza para que la
 *    navegación nunca arranque. Cubre `<Link>` y `<a>` por igual, sin envolver
 *    ni parchear nada de Next.
 *
 * ── Qué NO cubre (a propósito) ───────────────────────────────────────────
 *
 * - **Back/forward del browser.** `popstate` avisa DESPUÉS de que la
 *   navegación ocurrió; bloquearla requiere empujar una entrada centinela al
 *   `history` y devolver al usuario con `history.go(-1)`, pisando el state
 *   interno del App Router (`__NA` / árbol privado de Next). Es frágil y
 *   cuando falla deja al usuario varado en una entrada fantasma — peor que no
 *   tener guard. Queda como decisión abierta del owner, no se improvisa.
 *
 * - **Navegación programática de terceros** (`router.push()` desde otro
 *   componente). No hay API de bloqueo en el App Router de Next 16. El call
 *   site propio se cubre llamando a `confirmDiscard()` antes del `push` — ver
 *   el botón "volver" de `template-editor.tsx`.
 *
 * ── Falsos positivos ─────────────────────────────────────────────────────
 *
 * El guard se arma SOLO con `dirty === true`. Un guard que pregunta siempre
 * entrena al usuario a apretar "salir" sin leer, así que la pantalla que lo
 * usa es responsable de que `dirty` refleje una edición REAL del usuario — no
 * normalizaciones que la propia pantalla aplica al abrir. El editor de
 * plantillas lo resuelve comparando formas canónicas
 * (`canonicalizeTemplateForCompare`, lib/types/print-template.ts).
 */

/** Texto del `confirm()` de navegación interna. El de `beforeunload` lo pone
 *  el browser y no se puede cambiar (ver docblock). */
export const UNSAVED_CHANGES_MESSAGE =
  "Hay cambios sin guardar. Si salís ahora se pierden. ¿Querés salir igual?"

export interface UnsavedChangesGuard {
  /**
   * Pregunta al usuario si puede descartar los cambios. Devuelve `true`
   * cuando se puede seguir adelante (no hay nada sucio, o el usuario aceptó
   * perderlo). Para los call sites que navegan por código (`router.push`),
   * que el listener de clicks no puede interceptar:
   *
   * ```ts
   * onClick={() => { if (guard.confirmDiscard()) router.push("/settings") }}
   * ```
   *
   * Además marca la salida como autorizada, así el `beforeunload` no vuelve a
   * preguntar si esa navegación termina siendo una carga completa.
   */
  confirmDiscard: () => boolean
}

export function useUnsavedChangesGuard(
  dirty: boolean,
  message: string = UNSAVED_CHANGES_MESSAGE,
): UnsavedChangesGuard {
  // Salida ya autorizada por el usuario en este ciclo de vida — evita la
  // doble pregunta (confirm propio + diálogo nativo) cuando el click aceptado
  // resulta ser una navegación de documento completo y no una del router.
  const bypassRef = React.useRef(false)

  const confirmDiscard = React.useCallback(() => {
    if (!dirty || bypassRef.current) return true
    if (!window.confirm(message)) return false
    bypassRef.current = true
    return true
  }, [dirty, message])

  // `dirty` vuelve a false al guardar → el listener se desarma solo.
  React.useEffect(() => {
    if (!dirty) {
      bypassRef.current = false
      return
    }

    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (bypassRef.current) return
      // Las dos formas conviven: `preventDefault()` es la spec actual,
      // `returnValue` sigue siendo lo que miran los browsers viejos.
      e.preventDefault()
      e.returnValue = ""
    }

    const onClickCapture = (e: MouseEvent) => {
      if (bypassRef.current) return
      if (e.defaultPrevented) return
      // Solo click primario y sin modificadores: ctrl/cmd/shift abren en otra
      // pestaña o ventana — la página con los cambios no se pierde.
      if (e.button !== 0) return
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return

      const target = e.target
      const anchor =
        target instanceof Element ? (target.closest("a[href]") as HTMLAnchorElement | null) : null
      if (!anchor) return
      // `target="_blank"` abre pestaña nueva; `download` no navega.
      if (anchor.target && anchor.target !== "_self") return
      if (anchor.hasAttribute("download")) return

      const raw = anchor.getAttribute("href")
      // Ancla interna (`#seccion`) o handler no-navegable (`mailto:`, `tel:`):
      // no se sale de la página.
      if (!raw || raw.startsWith("#")) return

      let url: URL
      try {
        url = new URL(anchor.href, window.location.href)
      } catch {
        return
      }
      if (url.protocol !== "http:" && url.protocol !== "https:") return
      // Otro origen: el browser abandona el documento, así que lo cubre
      // `beforeunload` con su propio diálogo — preguntar acá sería doble.
      if (url.origin !== window.location.origin) return
      // Misma ruta: no se pierde nada.
      if (url.pathname === window.location.pathname && url.search === window.location.search) return

      if (window.confirm(message)) {
        bypassRef.current = true
        return
      }
      e.preventDefault()
      // `stopImmediatePropagation` y no `stopPropagation`: hay handlers en
      // capture sobre el mismo `document` (Radix cierra popovers/menús con
      // uno) que no deberían correr para una navegación que se canceló.
      e.stopImmediatePropagation()
    }

    window.addEventListener("beforeunload", onBeforeUnload)
    document.addEventListener("click", onClickCapture, true)
    return () => {
      window.removeEventListener("beforeunload", onBeforeUnload)
      document.removeEventListener("click", onClickCapture, true)
    }
  }, [dirty, message])

  return { confirmDiscard }
}
