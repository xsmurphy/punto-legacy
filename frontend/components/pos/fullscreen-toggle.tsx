"use client"

/**
 * Botón icon-only para togglear pantalla completa de módulo (oculta el
 * CartPanel) en las barras flotantes de /pos/espacios y /pos/ordenes.
 *
 * Mismo estilo visual que el `ViewButton` icon-only de
 * app/(pos)/pos/ordenes/page.tsx — pill oscura del design system del POS.
 *
 * Oculto en mobile (`hidden md:flex`): ahí el módulo ya se abre como Dialog
 * fullscreen sobre el carrito (ver docblock de app/(pos)/pos/layout.tsx), así
 * que el toggle no tiene sentido — el carrito ya no convive con el módulo.
 */

import { Maximize2, Minimize2 } from "lucide-react"

import { cn } from "@/lib/utils"
import { useWorkspaceStore } from "@/lib/pos/workspace-store"

export function FullscreenToggle() {
  const modulesFullscreen = useWorkspaceStore((s) => s.modulesFullscreen)
  const toggle = useWorkspaceStore((s) => s.toggleModulesFullscreen)

  const label = modulesFullscreen ? "Salir de pantalla completa" : "Pantalla completa"

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={label}
      title={label}
      aria-pressed={modulesFullscreen}
      className={cn(
        "hidden size-9 shrink-0 items-center justify-center rounded-full transition-colors md:flex",
        modulesFullscreen ? "bg-white text-neutral-900" : "text-white/80 hover:text-white",
      )}
    >
      {modulesFullscreen ? <Minimize2 className="size-4" /> : <Maximize2 className="size-4" />}
    </button>
  )
}
