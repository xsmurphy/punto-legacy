import * as React from "react"
import { SidebarInset } from "@/components/ui/sidebar"
import { PosSidebarProvider } from "@/components/layout/pos-sidebar-provider"
import { PosAuthGuard } from "@/components/layout/pos-auth-guard"
import { PosSidebar } from "@/components/layout/pos-sidebar"
import { PosModeDialog } from "@/components/register/pos-mode-dialog"
import { InstallPrompt } from "@/components/pos/install-prompt"
import { ChunkErrorListener } from "@/components/pos/chunk-error-listener"
import { PosConfigSync } from "@/lib/pos/config-sync"

/**
 * Layout del POS — auth via _jwt (device cookie, 10 años), NO _jwt_panel.
 * PosAuthGuard muestra <DeviceNotConnected /> si no hay cookie _jwt válida
 * (el viejo /pos-pair fue eliminado; el pairing nuevo es invitation-based
 * vía /connect/[id] generado por el admin desde /settings/devices).
 *
 * PosSidebar es un sidebar mínimo (Caja / Guardadas / Bloquear) sin los
 * módulos del panel.
 */
export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <PosSidebarProvider>
      <PosAuthGuard>
        <ChunkErrorListener />
        <PosConfigSync />
        <PosSidebar />
        {/* `pos-scope`: globals.css aplica typography táctil (font-size,
            weight, tracking) a inputs/textareas descendientes. Cajero ve
            grande sin tocar cada caller individualmente. */}
        <SidebarInset className="pos-scope h-svh overflow-hidden md:h-[calc(100svh-1rem)]">
          {/* El trigger mobile del nav de módulos vivía acá como FAB flotante
              abajo a la derecha. Se movió al extremo izquierdo del toolbar del
              carrito (CartToolbar), junto al botón del menú principal, por
              pedido del owner (2026-08-01). Se movió, no se duplicó: dos
              triggers para la misma nav es ruido en una pantalla de teléfono.
              Todas las rutas del grupo (pos) cuelgan de /pos y montan el
              CartPanel, así que el trigger sigue presente en todas. */}
          {children}
          {/* Selector de modo — montado en el layout (no en el sidebar) para
              sobrevivir al cierre del Sheet mobile que contiene su trigger. */}
          <PosModeDialog />
          <InstallPrompt />
        </SidebarInset>
      </PosAuthGuard>
    </PosSidebarProvider>
  )
}
