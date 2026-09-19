"use client"

import * as React from "react"
import { ArrowDownToLine, ArrowUpToLine } from "lucide-react"

import { Button } from "@/components/ui/button"

/**
 * Atajos "ir al inicio / ir al final" de un listado largo (hasta 1000 filas
 * por página). Viven DENTRO del contenedor de la tabla como un elemento
 * sticky de alto cero: acompañan el scroll mientras la tabla está en pantalla
 * y se van con ella, sin listeners globales ni botones huérfanos en otras
 * vistas. Cada flecha aparece solo si hay algo que recorrer en esa dirección.
 *
 * Quedan a la derecha, por encima de la burbuja del asistente
 * (`agent-chat-floating`: bottom-6 + size-14), para no taparla.
 */
export function ScrollEnds({
  containerRef,
  startRef,
  endRef,
}: {
  /** Bloque cuya altura decide si el listado es "largo". */
  containerRef: React.RefObject<HTMLElement | null>
  /** Destino de "ir al inicio" (la barra de búsqueda/filtros). */
  startRef: React.RefObject<HTMLElement | null>
  /** Destino de "ir al final" (la paginación). */
  endRef: React.RefObject<HTMLElement | null>
}) {
  const [canUp, setCanUp] = React.useState(false)
  const [canDown, setCanDown] = React.useState(false)

  React.useEffect(() => {
    const el = containerRef.current
    if (!el) return
    let frame = 0
    const measure = () => {
      frame = 0
      const rect = el.getBoundingClientRect()
      const vh = window.innerHeight
      // Solo si el listado supera la pantalla con holgura: en uno que entra
      // casi entero, los botones serían ruido.
      const long = rect.height > vh * 1.5
      setCanUp(long && rect.top < -vh * 0.5)
      setCanDown(long && rect.bottom > vh * 1.5)
    }
    const schedule = () => {
      if (!frame) frame = requestAnimationFrame(measure)
    }
    measure()
    // capture: el scroll puede ser de la ventana o de un ancestro con overflow.
    window.addEventListener("scroll", schedule, { passive: true, capture: true })
    window.addEventListener("resize", schedule)
    const ro = new ResizeObserver(schedule)
    ro.observe(el)
    return () => {
      if (frame) cancelAnimationFrame(frame)
      window.removeEventListener("scroll", schedule, { capture: true })
      window.removeEventListener("resize", schedule)
      ro.disconnect()
    }
  }, [containerRef])

  if (!canUp && !canDown) return null

  return (
    <div className="pointer-events-none sticky bottom-24 z-30 h-0">
      <div className="flex -translate-y-full flex-col items-end gap-2">
        {canUp && (
          <Button
            type="button"
            variant="outline"
            size="icon"
            aria-label="Ir al inicio"
            title="Ir al inicio"
            className="pointer-events-auto rounded-full shadow-md"
            onClick={() => startRef.current?.scrollIntoView({ behavior: "smooth", block: "start" })}
          >
            <ArrowUpToLine />
          </Button>
        )}
        {canDown && (
          <Button
            type="button"
            variant="outline"
            size="icon"
            aria-label="Ir al final"
            title="Ir al final"
            className="pointer-events-auto rounded-full shadow-md"
            onClick={() => endRef.current?.scrollIntoView({ behavior: "smooth", block: "end" })}
          >
            <ArrowDownToLine />
          </Button>
        )}
      </div>
    </div>
  )
}
