import { cookies } from "next/headers"

import { SidebarProvider, SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { PanelAuthGuard } from "@/components/layout/panel-auth-guard"

/**
 * Layout del panel.
 *
 * El intento previo de implementar /settings como modal con parallel
 * routes (`@modal/(.)settings`) fue revertido: el slot causaba que el
 * cliente y el server discreparan en el shape del router state tree
 * post-deploy → "The router state header was sent but could not be
 * parsed" → /settings no abría. El modal real lo hace el page mismo
 * con <Dialog>; el fondo en blanco al abrirlo es un trade-off
 * conocido del approach "route con Dialog" (vs modal global con
 * state) y queda por mejorar después.
 */
export default async function PanelLayout({
  children,
}: {
  children: React.ReactNode
}) {
  // SSR-aware sidebar state: leemos la cookie `sidebar_state` para que el
  // colapsado/expandido sobreviva navegación y reload sin flicker. shadcn
  // escribe esta cookie automáticamente cada vez que el usuario togglea el
  // sidebar (ui/sidebar.tsx → setOpen).
  //
  // Default = colapsado: si la cookie no existe (primer ingreso, modo
  // incógnito) o vale "false", el sidebar arranca cerrado. Solo si el
  // usuario lo abrió manualmente (cookie="true") aparece expandido.
  const cookieStore = await cookies()
  const defaultOpen = cookieStore.get("sidebar_state")?.value === "true"

  return (
    <SidebarProvider defaultOpen={defaultOpen}>
      <PanelAuthGuard>
        <SidebarInset>
          <SidebarTrigger className="fixed left-[calc(0.75rem+env(safe-area-inset-left))] top-[calc(0.75rem+env(safe-area-inset-top))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
          <main className="flex min-w-0 flex-1 flex-col gap-4 p-4 pt-[calc(3.5rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] md:p-6 md:pt-6">
            {children}
          </main>
        </SidebarInset>
      </PanelAuthGuard>
    </SidebarProvider>
  )
}
