"use client"

/**
 * Atajos de teclado de la pantalla de cocina.
 *
 * Muchas cocinas no dependen del mouse: es lento y hay que soltar lo que se
 * tiene en la mano. Con teclado se selecciona comanda, se marcan ítems y se
 * pagina mucho más rápido — y un teclado numérico/inalámbrico colgado al lado
 * de la TV es más barato y más resistente que una pantalla táctil.
 *
 *   ← / →           comanda anterior / siguiente
 *   ↑ / ↓           ítem anterior / siguiente dentro de la comanda
 *   Enter           marcar el ítem seleccionado; sin ítem, la comanda entera
 *   Backspace       retroceder un paso el ítem seleccionado
 *   Z               deshacer la última acción de marcado
 *   P               fijar / soltar la comanda seleccionada
 *   R               abrir / cerrar el panel de recall
 *   [ ] · PgUp/PgDn página anterior / siguiente
 *   Esc             soltar la selección
 *   ?               ayuda de atajos
 *
 * GUARD: con un diálogo abierto los atajos NO disparan. Mismo criterio y misma
 * detección que `use-pos-hotkeys.ts` (`[role="dialog"][data-state="open"]` de
 * Radix) — si no, tipear el nombre de la pantalla en Ajustes marcaría comandas.
 * Los diálogos PROPIOS (ayuda, recall) se chequean por estado y no por DOM,
 * porque su propia tecla tiene que poder cerrarlos: `R` abre y cierra el recall.
 *
 * El handler se registra UNA vez y lee los callbacks de un ref: esta pantalla
 * queda abierta durante días y no tiene por qué re-suscribir un listener en
 * cada render. El cleanup en unmount es por lo mismo.
 */

import * as React from "react"

export interface KdsHotkeyHandlers {
  /** Mueve la selección de comanda (±1). */
  moveOrder: (delta: number) => void
  /** Mueve la selección de ítem dentro de la comanda (±1). */
  moveItem: (delta: number) => void
  /** Enter: marca el ítem seleccionado, o la comanda entera si no hay ítem. */
  confirm: () => void
  /** Backspace: retrocede un paso el ítem seleccionado. */
  stepBack: () => void
  undo: () => void
  togglePin: () => void
  toggleRecall: () => void
  toggleHelp: () => void
  movePage: (delta: number) => void
  clearSelection: () => void
}

function isTypingTarget(el: EventTarget | null): boolean {
  if (!(el instanceof HTMLElement)) return false
  const tag = el.tagName
  return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el.isContentEditable
}

/**
 * Un control con el foco puesto (por Tab o por un click previo). Enter y Espacio
 * son SUYOS: si el atajo global se los quedara, quien navega con Tab no podría
 * abrir Ajustes ni el recall con el teclado —tendría que ir al mouse—, y una
 * tarjeta enfocada dispararía a la vez su propio Enter y el de la selección.
 */
function isInteractiveTarget(el: EventTarget | null): boolean {
  if (!(el instanceof HTMLElement)) return false
  const tag = el.tagName
  return tag === "BUTTON" || tag === "A" || el.getAttribute("role") === "button"
}

export function useKdsHotkeys(
  handlers: KdsHotkeyHandlers,
  state: { helpOpen: boolean; recallOpen: boolean }
): void {
  const handlersRef = React.useRef(handlers)
  const stateRef = React.useRef(state)
  handlersRef.current = handlers
  stateRef.current = state

  React.useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      // Combinaciones con modificadores no son nuestros atajos.
      if (e.metaKey || e.ctrlKey || e.altKey) return
      if (isTypingTarget(e.target)) return

      const h = handlersRef.current
      const { helpOpen, recallOpen } = stateRef.current

      // Diálogos propios: solo su tecla los cierra (Escape lo maneja el Dialog).
      if (helpOpen) {
        if (e.key === "?") { e.preventDefault(); h.toggleHelp() }
        return
      }
      if (recallOpen) {
        if (e.key.toLowerCase() === "r") { e.preventDefault(); h.toggleRecall() }
        return
      }

      // Cualquier otro overlay (Ajustes, o lo que venga): el teclado es suyo.
      if (
        typeof document !== "undefined" &&
        document.querySelector('[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]')
      ) {
        return
      }

      // Enter/Espacio sobre un control enfocado son del control, no nuestros.
      if ((e.key === "Enter" || e.key === " ") && isInteractiveTarget(e.target)) return

      switch (e.key) {
        case "ArrowLeft":  e.preventDefault(); h.moveOrder(-1); return
        case "ArrowRight": e.preventDefault(); h.moveOrder(1); return
        case "ArrowUp":    e.preventDefault(); h.moveItem(-1); return
        case "ArrowDown":  e.preventDefault(); h.moveItem(1); return
        case "Enter":      e.preventDefault(); h.confirm(); return
        case "Backspace":  e.preventDefault(); h.stepBack(); return
        case "Escape":     e.preventDefault(); h.clearSelection(); return
        case "PageUp":
        case "[":          e.preventDefault(); h.movePage(-1); return
        case "PageDown":
        case "]":          e.preventDefault(); h.movePage(1); return
        case "?":          e.preventDefault(); h.toggleHelp(); return
      }

      switch (e.key.toLowerCase()) {
        case "z": e.preventDefault(); h.undo(); break
        case "p": e.preventDefault(); h.togglePin(); break
        case "r": e.preventDefault(); h.toggleRecall(); break
      }
    }

    window.addEventListener("keydown", onKey)
    return () => window.removeEventListener("keydown", onKey)
  }, [])
}
