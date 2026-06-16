import { SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { PanelAuthGuard } from "@/components/layout/panel-auth-guard"
import { PosSidebarProvider } from "@/components/layout/pos-sidebar-provider"

/**
 * Layout del POS — vive dentro del shell del panel (mismo AppSidebar + auth)
 * pero con el área de contenido full-bleed (sin padding) porque la caja maneja
 * su propio layout 2-columnas a pantalla completa.
 *
 * Sidebar SIEMPRE colapsado y no expandible en /pos (PosSidebarProvider).
 */
export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <PosSidebarProvider>
      <PanelAuthGuard>
        {/* En desktop el SidebarInset (variante inset) agrega m-2 (8px arriba +
            8px abajo = 1rem). Restamos ese 1rem del alto para que la caja
            encaje exacto en el viewport y no se pase ~16px. En mobile no hay
            margen → h-svh completo. */}
        <SidebarInset className="h-svh overflow-hidden md:h-[calc(100svh-1rem)]">
          <SidebarTrigger className="fixed left-[calc(0.75rem+env(safe-area-inset-left))] top-[calc(0.75rem+env(safe-area-inset-top))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
          {children}
        </SidebarInset>
      </PanelAuthGuard>
    </PosSidebarProvider>
  )
}
