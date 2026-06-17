"use client"

/**
 * Atajos de teclado globales del POS.
 *
 * El POS está pensado para tablets/touchscreens y cajas de alto volumen: la
 * operación por teclado reduce la dependencia del mouse y acelera la venta.
 *
 *   Q     → menú del POS
 *   W     → buscador de artículos
 *   E     → buscador de clientes
 *   R     → menú de opciones de venta
 *   Enter → cobrar (abre el modal de cobro si hay ítems)
 *
 * ESC lo manejan los propios overlays (shadcn Dialog / Drawer / menú del POS).
 *
 * Los atajos se IGNORAN cuando:
 *   - el foco está en un input/textarea/select/contenteditable (para no
 *     dispararlos al tipear);
 *   - hay un overlay del POS abierto (solo operan ESC/Enter internos);
 *   - el POS está en modo edición de hotkeys;
 *   - no hay caja activa (el modal de setup bloquea la operación).
 */

import * as React from "react"
import { usePosUIStore } from "@/lib/ui/store"
import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { useHotkeysStore } from "@/lib/hotkeys/store"

function isTypingTarget(el: EventTarget | null): boolean {
  if (!(el instanceof HTMLElement)) return false
  const tag = el.tagName
  return (
    tag === "INPUT" ||
    tag === "TEXTAREA" ||
    tag === "SELECT" ||
    el.isContentEditable
  )
}

function isInteractiveTarget(el: EventTarget | null): boolean {
  if (!(el instanceof HTMLElement)) return false
  const tag = el.tagName
  return tag === "BUTTON" || tag === "A" || el.getAttribute("role") === "button"
}

export function usePosHotkeys(): void {
  React.useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      // Combinaciones con modificadores no son nuestros atajos.
      if (e.metaKey || e.ctrlKey || e.altKey) return
      if (isTypingTarget(e.target)) return

      const ui = usePosUIStore.getState()
      const anyOverlayOpen =
        ui.searchOpen ||
        ui.customerOpen ||
        ui.payOpen ||
        ui.menuOpen ||
        ui.optionsOpen
      const editing = useHotkeysStore.getState().editing
      const activeRegisterId = useCatalogStore.getState().activeRegisterId

      // Sin caja activa o en modo edición de hotkeys → sin atajos de venta.
      if (!activeRegisterId || editing) return
      // Con un overlay abierto, el teclado lo maneja el propio overlay.
      if (anyOverlayOpen) return

      switch (e.key.toLowerCase()) {
        case "q":
          e.preventDefault()
          ui.setMenuOpen(true)
          break
        case "w":
          e.preventDefault()
          ui.setSearchOpen(true)
          break
        case "e":
          e.preventDefault()
          ui.setCustomerOpen(true)
          break
        case "r":
          e.preventDefault()
          ui.setOptionsOpen(true)
          break
        case "enter": {
          // Enter en venta = "Cobrar". Si hay líneas en el cart y no hay overlay
          // abierto, abre el modal de pago aunque el focus esté en un botón
          // (típico: el cajero acaba de clickear un producto del grid y su
          // focus queda en ese botón). Los overlays ya están skipeados arriba
          // por anyOverlayOpen.
          const lines = useCartStore.getState().lines
          if (lines.length > 0) {
            e.preventDefault()
            ui.setPayOpen(true)
          }
          break
        }
      }
    }

    window.addEventListener("keydown", onKey)
    return () => window.removeEventListener("keydown", onKey)
  }, [])
}
