import { cookies } from "next/headers"

import { SidebarProvider, SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { PanelAuthGuard } from "@/components/layout/panel-auth-guard"

/**
 * Layout del panel.
 *
 * Slot `modal` (parallel route `@modal`) — patrón Next App Router para
 * "modal sobre la página actual". Cuando una intercepting route como
 * `@modal/(.)settings` matchea, su contenido se renderea ENCIMA del
 * `children` actual sin reemplazarlo — la página de fondo permanece
 * intacta. Sin esto, navegar a `/settings` desde el dashboard hacía
 * que el dashboard se desmontara y el modal quedara sobre vacío
 * (el clásico "modal con bg blanco").
 *
 * Deep link directo (`https://.../settings`) NO usa el intercept y
 * cae a `app/(panel)/settings/page.tsx` normal — el slot @modal
 * renderea su `default.tsx` (null).
 */
export default async function PanelLayout({
  children,
  modal,
}: {
  children: React.ReactNode
  modal: React.ReactNode
}) {
  // SSR-aware sidebar state: leemos la cookie `sidebar_state` para que el
  // colapsado/expandido sobreviva navegación y reload sin flicker. shadcn
  // escribe esta cookie automáticamente cada vez que el usuario togglea el
  // sidebar (ui/sidebar.tsx → setOpen).
  const cookieStore = await cookies()
  const defaultOpen = cookieStore.get("sidebar_state")?.value !== "false"

  return (
    <SidebarProvider defaultOpen={defaultOpen}>
      <PanelAuthGuard>
        <SidebarInset>
          <SidebarTrigger className="fixed left-[calc(0.75rem+env(safe-area-inset-left))] top-[calc(0.75rem+env(safe-area-inset-top))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
          <main className="flex min-w-0 flex-1 flex-col gap-4 p-4 pt-[calc(3.5rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] md:p-6 md:pt-6">
            {children}
          </main>
          {modal}
        </SidebarInset>
      </PanelAuthGuard>
    </SidebarProvider>
  )
}
