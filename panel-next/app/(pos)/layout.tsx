import { cookies } from "next/headers"
import { SidebarProvider, SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { PanelAuthGuard } from "@/components/layout/panel-auth-guard"

/**
 * Layout del POS — vive dentro del shell del panel (mismo AppSidebar + auth)
 * pero con el área de contenido full-bleed (sin padding) porque la caja maneja
 * su propio layout 2-columnas a pantalla completa.
 */
export default async function PosLayout({ children }: { children: React.ReactNode }) {
  const cookieStore = await cookies()
  const defaultOpen = cookieStore.get("sidebar_state")?.value === "true"
  return (
    <SidebarProvider defaultOpen={defaultOpen}>
      <PanelAuthGuard>
        <SidebarInset className="h-svh overflow-hidden">
          <SidebarTrigger className="fixed left-[calc(0.75rem+env(safe-area-inset-left))] top-[calc(0.75rem+env(safe-area-inset-top))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
          {children}
        </SidebarInset>
      </PanelAuthGuard>
    </SidebarProvider>
  )
}
