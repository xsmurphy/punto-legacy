import * as React from "react"
import { SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { PosSidebarProvider } from "@/components/layout/pos-sidebar-provider"
import { PosAuthGuard } from "@/components/layout/pos-auth-guard"
import { PosSidebar } from "@/components/layout/pos-sidebar"
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
          <SidebarTrigger className="fixed right-[calc(0.75rem+env(safe-area-inset-right))] bottom-[calc(7.5rem+env(safe-area-inset-bottom))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
          {children}
          <InstallPrompt />
        </SidebarInset>
      </PosAuthGuard>
    </PosSidebarProvider>
  )
}
