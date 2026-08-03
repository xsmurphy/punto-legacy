"use client"

/**
 * Detector de "click afuera" que NO confunde una capa flotante con el exterior.
 *
 * PROBLEMA QUE RESUELVE (recurrente, reportado por el owner varias veces)
 *   Un `document.addEventListener("pointerdown", ...)` que solo pregunta
 *   `ref.current.contains(e.target)` considera "afuera" TODO lo que vive en un
 *   portal: Dialog, Drawer, Popover, DropdownMenu, Select, Command. Todos esos
 *   se montan en `document.body`, fuera del subárbol del ref. Resultado: el
 *   primer click DENTRO del modal dispara el handler, el contenedor se cierra o
 *   se deselecciona, y como el modal es hijo de ese contenedor se desmonta con
 *   él — al usuario le parece que "el modal se cierra clickee donde clickee".
 *
 * Por eso el guard vive acá y no en cada call-site: cualquier lugar del POS o
 * del panel que necesite "click afuera" usa este hook y no puede regresar al
 * bug. NUNCA volver a escribir el `contains()` pelado a mano.
 *
 * Las capas se detectan por su rol/atributo ARIA, que es lo que emiten tanto
 * Radix como vaul — no por clases ni por `data-slot`, que cambian con el tema.
 * El overlay (el fondo oscurecido) queda deliberadamente FUERA de la lista: un
 * click ahí sí es un click afuera.
 */

import * as React from "react"

/** Capas flotantes portaladas por Radix / vaul. */
const FLOATING_LAYER_SELECTOR = [
  '[role="dialog"]',
  '[role="alertdialog"]',
  '[role="menu"]',
  '[role="listbox"]',
  '[role="tooltip"]',
  "[data-radix-popper-content-wrapper]",
].join(", ")

export function isInsideFloatingLayer(target: EventTarget | null): boolean {
  const el = target instanceof Element ? target : null
  if (!el) return false
  return Boolean(el.closest(FLOATING_LAYER_SELECTOR))
}

/**
 * Llama a `onOutside` cuando hay un `pointerdown` fuera de `ref`, ignorando los
 * que ocurren dentro de una capa flotante.
 *
 * @param enabled  desactiva el listener sin romper el orden de hooks.
 */
export function useOutsidePointerDown(
  ref: React.RefObject<HTMLElement | null>,
  onOutside: () => void,
  enabled = true,
) {
  const handlerRef = React.useRef(onOutside)
  React.useEffect(() => {
    handlerRef.current = onOutside
  }, [onOutside])

  React.useEffect(() => {
    if (!enabled) return
    function onPointerDown(e: PointerEvent) {
      const node = ref.current
      if (!node) return
      if (node.contains(e.target as Node)) return
      if (isInsideFloatingLayer(e.target)) return
      handlerRef.current()
    }
    document.addEventListener("pointerdown", onPointerDown)
    return () => document.removeEventListener("pointerdown", onPointerDown)
  }, [ref, enabled])
}
