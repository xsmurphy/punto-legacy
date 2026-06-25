import * as React from "react"
import { SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { PosSidebarProvider } from "@/components/layout/pos-sidebar-provider"
import { PosAuthGuard } from "@/components/layout/pos-auth-guard"
import { PosSidebar } from "@/components/layout/pos-sidebar"

/**
 * Layout del POS — auth via _jwt (device cookie, 10 años), NO _jwt_panel.
 * PosAuthGuard redirige a /pos-pair si no hay cookie _jwt válida.
 * PanelAuthGuard y AuthSentinel NO se montan acá — el POS es un realm
 * separado del panel.
 *
 * PosSidebar es un sidebar mínimo (Caja / Guardadas / Bloquear / Ajustes)
 * sin los módulos del panel (Artículos, Contactos, Reportes, etc.).
 */
export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <PosSidebarProvider>
      <PosAuthGuard>
        <PosSidebar />
        <SidebarInset className="h-svh overflow-hidden md:h-[calc(100svh-1rem)]">
          <SidebarTrigger className="fixed right-[calc(0.75rem+env(safe-area-inset-right))] bottom-[calc(7.5rem+env(safe-area-inset-bottom))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
          {children}
        </SidebarInset>
      </PosAuthGuard>
    </PosSidebarProvider>
  )
}
