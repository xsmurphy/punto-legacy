"use client"

import * as React from "react"

import { useCatalogStore } from "@/lib/catalog/store"
import { selectPosWindowTitle } from "@/lib/pos/window-title"

/**
 * Pone el título de la ventana de la caja (`lib/pos/window-title.ts`).
 *
 * Se hace en un efecto de cliente y no con `metadata` de Next porque los tres
 * nombres viven en el bootstrap del POS, que es estado del DISPOSITIVO: el
 * server no sabe qué caja es esta hasta que el catálogo hidrata.
 */
export function PosDocumentTitle() {
  const title = useCatalogStore(selectPosWindowTitle)

  React.useEffect(() => {
    // Se guarda el título con el que se llegó y se restaura al desmontar: al
    // volver al panel la pestaña seguiría diciendo el nombre de la caja.
    const previous = document.title
    document.title = title
    return () => {
      document.title = previous
    }
  }, [title])

  return null
}
