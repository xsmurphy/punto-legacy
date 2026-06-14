import { cookies } from "next/headers"
import { SidebarProvider, SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { AdminAuthGuard } from "@/components/admin/admin-auth-guard"
import { AdminSidebar } from "@/components/admin/admin-sidebar"

/**
 * Layout del realm admin.
 *
 * Diferencias con el layout del panel (tenant):
 *   - NO usa PanelAuthGuard / useBootstrap (realm distinto).
 *   - Usa AdminAuthGuard → AdminSidebar → AdminContext.
 *   - Borde superior destructive en el sidebar (diferenciador visual).
 *
 * Las páginas de login (/admin/login) usan su propio layout (auth).
 * Este layout envuelve SOLO las rutas protegidas bajo (admin)/.
 */
export default async function AdminLayout({
  children,
}: {
  children: React.ReactNode
}) {
  // El SidebarProvider escribe "sidebar_state" (hardcodeado en ui/sidebar.tsx).
  // Leemos esa misma cookie para el SSR del admin — ambos realms comparten el estado
  // de apertura del sidebar (no hay conflicto: son rutas distintas y la preferencia
  // del usuario es razonable compartirla).
  const cookieStore = await cookies()
  const defaultOpen = cookieStore.get("sidebar_state")?.value === "true"

  return (
    <SidebarProvider defaultOpen={defaultOpen}>
      <AdminAuthGuard>
        <AdminSidebar />
        <SidebarInset>
          <SidebarTrigger className="fixed left-[calc(0.75rem+env(safe-area-inset-left))] top-[calc(0.75rem+env(safe-area-inset-top))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
          {/* Borde superior de acento en el área de contenido principal */}
          <div className="absolute inset-x-0 top-0 h-[3px] bg-destructive/30 z-0" />
          <main className="relative flex min-w-0 flex-1 flex-col gap-4 p-4 pt-[calc(3.5rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] md:p-6 md:pt-6">
            {children}
          </main>
        </SidebarInset>
      </AdminAuthGuard>
    </SidebarProvider>
  )
}
