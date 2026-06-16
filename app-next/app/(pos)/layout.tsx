/**
 * Layout del POS — full-screen con icon-rail sidebar a la izquierda.
 *
 * Estructura (2026-06-16, pivote a design system panel-next):
 *   ┌──┬─────────────────────────────────────────────┐
 *   │  │                                             │
 *   │ S│  SidebarInset (children: register, etc.)    │
 *   │ I│                                             │
 *   │ D│                                             │
 *   │ E│                                             │
 *   │  │                                             │
 *   └──┴─────────────────────────────────────────────┘
 *
 * El sidebar arranca colapsado (`defaultOpen={false}`) → estado
 * permanente `collapsed`, lo que activa los tooltips nativos y
 * deja el rail en `--sidebar-width-icon` (4rem custom).
 *
 * Ver context/16-app-next-rewrite.md y feedback del owner 2026-06-16.
 */

import { PosAuthGuard } from "@/components/layout/pos-auth-guard"
import { PosSidebar } from "@/components/layout/pos-sidebar"
import { SidebarInset, SidebarProvider } from "@/components/ui/sidebar"

export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <PosAuthGuard>
      <SidebarProvider
        defaultOpen={false}
        // Width custom del rail: ~64px (vs 48px default del primitive).
        // El POS necesita target táctil un poco más grande que un dashboard.
        style={{ "--sidebar-width-icon": "4rem" } as React.CSSProperties}
      >
        <PosSidebar />
        <SidebarInset className="flex h-svh flex-col overflow-hidden bg-background">
          {children}
        </SidebarInset>
      </SidebarProvider>
    </PosAuthGuard>
  )
}
