"use client"

/**
 * Tooltip de los controles de la caja.
 *
 * Media caja son botones que son SOLO un ícono —la toolbar del carrito, las
 * herramientas de cada línea, la barra de categorías—: quien no los opera
 * todos los días no tiene de dónde deducir qué hacen. El tooltip les pone
 * nombre sin agregarles texto, que es justo lo que no entra: la toolbar mide
 * h-14 y cada botón ocupa un cuarto del ancho del carrito.
 *
 * Los que además tienen atajo lo ANUNCIAN acá (`hooks/use-pos-hotkeys.ts`).
 * Un atajo que no se ve en ninguna pantalla lo conocen el que lo programó y
 * el cajero al que se lo contaron; el tooltip es el único lugar donde puede
 * aparecer sin ocupar lugar. Las teclas van SOLAS, sin Ctrl/Cmd: es la
 * convención vigente del POS y los combos con modificador chocan con los
 * del navegador.
 *
 * No mueve nada: el contenido se portalea fuera del flujo, así que el botón
 * envuelto conserva tamaño y coordenadas (Regla #10 de
 * `context/14-ui-conventions.md`, memoria muscular del cajero).
 *
 * En touch no se dispara nunca —Radix abre con hover/foco— y está bien: el
 * atajo es para quien tiene teclado, y el `aria-label` del botón sigue siendo
 * lo que lee el lector de pantalla. Por eso el `label` que se pasa acá tiene
 * que ser EL MISMO que ese `aria-label`: son la misma respuesta a la misma
 * pregunta, dada por dos vías distintas.
 */

import * as React from "react"

import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { cn } from "@/lib/utils"

/**
 * La tecla del atajo.
 *
 * `data-slot="kbd"` no es decorativo: `TooltipContent` ya trae el tratamiento
 * de la tecla dentro del tooltip (achica el padding derecho del globo y
 * redondea el chip). Se usa el del design system en vez de dibujar uno propio.
 */
function HotkeyBadge({ hotkey }: { hotkey: string }) {
  return (
    <kbd
      data-slot="kbd"
      className={cn(
        "inline-flex h-5 min-w-5 items-center justify-center px-1.5",
        // Colores propios y no los del tooltip: el globo se invierte entre
        // tema claro y oscuro (`bg-foreground`/`text-background`) y el par
        // muted/muted-foreground se lee igual en los dos.
        "bg-muted text-muted-foreground",
        "font-mono text-xs font-medium tabular-nums",
      )}
    >
      {hotkey}
    </kbd>
  )
}

/**
 * Contenido del tooltip: el nombre de la acción y, si tiene, su tecla.
 *
 * Exportado suelto para el rail del sidebar, que no acepta un wrapper —
 * `SidebarMenuButton` monta su propio `Tooltip` y solo recibe las props del
 * contenido (`components/ui/sidebar.tsx`).
 */
export function HotkeyTooltipLabel({
  label,
  hotkey,
}: {
  label: string
  hotkey?: string
}) {
  return (
    <>
      <span>{label}</span>
      {hotkey && <HotkeyBadge hotkey={hotkey} />}
    </>
  )
}

export function HotkeyTooltip({
  label,
  hotkey,
  side = "top",
  children,
}: {
  /** Mismo texto que el `aria-label` del botón envuelto. */
  label: string
  /** Tecla del atajo, si la acción tiene uno en `use-pos-hotkeys.ts`. */
  hotkey?: string
  side?: React.ComponentProps<typeof TooltipContent>["side"]
  children: React.ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent side={side}>
        <HotkeyTooltipLabel label={label} hotkey={hotkey} />
      </TooltipContent>
    </Tooltip>
  )
}
