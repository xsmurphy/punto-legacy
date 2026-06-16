"use client"

/**
 * SidebarProvider del POS — sidebar SIEMPRE colapsado (icon-rail) y NO
 * expandible. Solo aplica en /pos.
 *
 * Controlado con `open={false}` + `onOpenChange` no-op: cualquier toggle
 * (⌘B, click en el logo, SidebarTrigger) intenta `setOpen(true)` pero el
 * handler lo ignora → el sidebar queda fijo en modo icono.
 *
 * El sheet mobile sigue funcionando: usa `openMobile` (estado interno
 * independiente de `open`), así que el cajero puede abrir la nav en mobile.
 */

import { SidebarProvider } from "@/components/ui/sidebar"

export function PosSidebarProvider({ children }: { children: React.ReactNode }) {
  return (
    <SidebarProvider open={false} onOpenChange={() => {}}>
      {children}
    </SidebarProvider>
  )
}
