"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { Package, Users, BarChart3, ScanBarcode, LayoutDashboard } from "lucide-react"

import { AppSidebar, type NavEntry } from "@/components/layout/app-sidebar"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { ApiError } from "@/lib/api-client"

// Menú lateral. Definido acá (client) porque los iconos son componentes
// función y no pueden cruzar la frontera server → client como props.
// Dashboard como item explícito (UX: el logo no era enough).
const panelNav: NavEntry[] = [
  { title: "Dashboard", to: "/", icon: LayoutDashboard },
  { title: "Artículos", to: "/items", icon: Package },
  { title: "Contactos", to: "/contacts", icon: Users },
  { title: "Reportes", to: "/reports", icon: BarChart3 },
  // Caja = POS legacy (subdomain distinto). SSO via /bff/pos-redirect.php
  // en el legacy; cuando se migre, este `to` apunta al endpoint Next equivalente.
  { title: "Caja", to: "/pos", icon: ScanBarcode },
]

/**
 * Wrapper client-side del panel. Gate de auth (bootstrap → 401 → /login) y
 * monta el AppSidebar con el `user` resuelto + items de navegación.
 *
 * Vive separado del layout server-side para que el layout pueda leer la cookie
 * del sidebar (cookies() solo está en server components) y pasarla a
 * SidebarProvider sin flicker.
 */
export function PanelAuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const { data: bootstrap, isLoading, error } = useBootstrap()

  React.useEffect(() => {
    if (error instanceof ApiError && error.status === 401) {
      router.replace("/login")
    }
  }, [error, router])

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
    <>
      <AppSidebar scope="Panel" items={panelNav} user={user} />
      {children}
    </>
  )
}
