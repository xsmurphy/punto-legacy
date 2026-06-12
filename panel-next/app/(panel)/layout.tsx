"use client"

import { useRouter } from "next/navigation"
import { SidebarProvider, SidebarInset, SidebarTrigger } from "@/components/ui/sidebar"
import { AppSidebar, type NavEntry } from "@/components/layout/app-sidebar"
import { Package, Users, BarChart3, ScanBarcode, LayoutDashboard } from "lucide-react"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { ApiError } from "@/lib/api-client"
import * as React from "react"

// Menú lateral. Dashboard como item explícito (el usuario reportó que
// no había forma de volver desde otras secciones — antes asumíamos que el
// logo era enough, pero la UX no lo deja claro).
const panelNav: NavEntry[] = [
  { title: "Dashboard", to: "/", icon: LayoutDashboard },
  { title: "Artículos", to: "/items", icon: Package },
  { title: "Contactos", to: "/contacts", icon: Users },
  { title: "Reportes", to: "/reports", icon: BarChart3 },
  // Caja = POS legacy (subdomain distinto). SSO via /bff/pos-redirect.php
  // en el legacy; cuando se migre, este `to` apunta al endpoint Next equivalente.
  { title: "Caja", to: "/pos", icon: ScanBarcode },
]

export default function PanelLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const { data: bootstrap, isLoading, error } = useBootstrap()

  // Gate de auth client-side: si bootstrap devuelve 401 (sin cookie o
  // cookie expirada), mandamos al login. Esto es complemento del
  // middleware Next que se va a agregar como hardening en slice futuro
  // (server-side redirect antes de renderizar el layout).
  React.useEffect(() => {
    if (error instanceof ApiError && error.status === 401) {
      router.replace("/login")
    }
  }, [error, router])

  // Sidebar user: usa el companyName del bootstrap como nombre principal
  // y el ID del usuario como subtitle (placeholder hasta tener /v1/me
  // con first_name/last_name/email/phone — port pendiente del slice 1).
  const user = bootstrap
    ? {
        name: bootstrap.companyName || "Punto",
        subtitle: `Usuario #${bootstrap.user.id}`,
      }
    : {
        name: isLoading ? "Cargando…" : "Punto User",
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
