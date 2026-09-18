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
 *
 * ── Cuándo NO se muestra (2026-09-18) ─────────────────────────────────────
 *
 * Dos casos, y en los dos el botón era un control que no hacía nada:
 *
 *  1. **iPhone.** Safari en iOS no implementa la Fullscreen API en el teléfono
 *     (sí en el iPad). `requestFullscreen` directamente no existe, así que
 *     tocarlo no producía ningún efecto ni ningún error visible.
 *  2. **App instalada.** Ya ocupa la pantalla entera; no hay nada que
 *     maximizar.
 *
 * Se detecta la CAPACIDAD, no el aparato: nada de mirar el user agent. El día
 * que iOS la implemente, el botón aparece solo.
 */

import * as React from "react"
import { Maximize2, Minimize2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { useStandalone } from "@/hooks/use-standalone"
import { cn } from "@/lib/utils"

export function ScreenFullscreenToggle({ className }: { className?: string }) {
  const [active, setActive] = React.useState(false)
  const standalone = useStandalone()

  // Arranca en `false` y se resuelve en un efecto: `document` no existe en el
  // render del servidor, y decidirlo durante el render sería un mismatch de
  // hidratación.
  const [supported, setSupported] = React.useState(false)
  React.useEffect(() => {
    setSupported(typeof document.documentElement.requestFullscreen === "function")
  }, [])

  // El estado se lee del documento y no de lo que se tocó: se puede salir de
  // pantalla completa con Escape, sin pasar por este botón.
  React.useEffect(() => {
    const sync = () => setActive(document.fullscreenElement !== null)
    sync()
    document.addEventListener("fullscreenchange", sync)
    return () => document.removeEventListener("fullscreenchange", sync)
  }, [])

  // Un control que no puede hacer nada no se ofrece gris con tooltip: acá no
  // hay un impedimento que la persona pueda resolver (§10 de context/14 pide
  // decirlo en el control cuando HAY algo que decir), simplemente esa acción no
  // existe en este aparato.
  if (!supported || standalone) return null

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
