"use client"

import { SidebarProvider, SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { AppSidebar, type NavEntry } from "@/components/layout/app-sidebar"
import { Package, Users, BarChart3, ScanBarcode } from "lucide-react"

// Espejo del menú lateral del panel legacy (`leftMenu()` en
// panel/includes/functions.php:5623). Sidebar 80px icon-only con 4 items
// top-level — todo lo demás (Estado de Cuenta, Compras y Gastos, Módulos,
// Configuración, Cerrar Sesión) vive en el dropdown del avatar al pie.
// Dashboard no es item del menú: es la home (logo arriba lleva ahí).
const panelNav: NavEntry[] = [
  { title: "Artículos", to: "/items", icon: Package },
  { title: "Contactos", to: "/contacts", icon: Users },
  { title: "Reportes", to: "/reports", icon: BarChart3 },
  // Caja = POS legacy (subdomain distinto). SSO via /bff/pos-redirect.php
  // en el legacy; cuando se migre, este `to` apunta al endpoint Next equivalente.
  { title: "Caja", to: "/pos", icon: ScanBarcode },
]

export default function PanelLayout({ children }: { children: React.ReactNode }) {
  // Hardcode del user hasta el slice de auth. En slice 1 se reemplaza por
  // una server action que lee `_jwt_panel` y resuelve `/api/v1/me`.
  const user = {
    name: "Punto User",
    subtitle: "Sesión activa",
  }

  return (
    <SidebarProvider>
      <AppSidebar scope="Panel" items={panelNav} user={user} />
      <SidebarInset>
        <SidebarTrigger className="fixed left-[calc(0.75rem+env(safe-area-inset-left))] top-[calc(0.75rem+env(safe-area-inset-top))] z-50 size-9 rounded-full border bg-card shadow-sm md:hidden" />
        <main className="flex min-w-0 flex-1 flex-col gap-4 p-4 pt-[calc(3.5rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] md:p-6 md:pt-6">
          {children}
        </main>
      </SidebarInset>
    </SidebarProvider>
  )
}
