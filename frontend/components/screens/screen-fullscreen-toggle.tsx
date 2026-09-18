"use client"

/**
 * Pantalla completa para las pantallas pareadas (`app/(screen)/*`).
 *
 * Una tablet colgada en la pared se pone en pantalla completa una vez y se
 * queda así; el botón existe para ese momento y para salir cuando alguien tiene
 * que tocar el navegador.
 *
 * Vive acá y no en cada pantalla porque la lógica es la misma en todas y ya
 * estaba duplicada: la pantalla de cliente la tenía inline con un `<svg>` a mano
 * (prohibido por la §6 de context/14 — los iconos son de `lucide-react`) y el
 * reloj de marcación estaba usando el `FullscreenToggle` del POS, que es OTRA
 * cosa: ese togglea el ancho del módulo contra el carrito en un store de la caja
 * que una pantalla pareada no tiene.
 */

import * as React from "react"
import { Maximize2, Minimize2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

export function ScreenFullscreenToggle({ className }: { className?: string }) {
  const [active, setActive] = React.useState(false)

  // El estado se lee del documento y no de lo que se tocó: se puede salir de
  // pantalla completa con Escape, sin pasar por este botón.
  React.useEffect(() => {
    const sync = () => setActive(document.fullscreenElement !== null)
    sync()
    document.addEventListener("fullscreenchange", sync)
    return () => document.removeEventListener("fullscreenchange", sync)
  }, [])

  const label = active ? "Salir de pantalla completa" : "Pantalla completa"

  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      aria-label={label}
      title={label}
      aria-pressed={active}
      className={cn("text-muted-foreground hover:text-foreground", className)}
      onClick={() => {
        // Sin `await`: el navegador puede rechazarlo (permiso, iframe) y eso no
        // es un error que haya que contarle a nadie — el botón sigue ahí.
        if (document.fullscreenElement) void document.exitFullscreen().catch(() => undefined)
        else void document.documentElement.requestFullscreen().catch(() => undefined)
      }}
    >
      {active ? <Minimize2 /> : <Maximize2 />}
    </Button>
  )
}
