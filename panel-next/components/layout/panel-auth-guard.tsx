"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import {
  ShoppingBasket,
  Contact,
  ChartPie,
  ScanBarcode,
  LayoutDashboard,
} from "lucide-react"
import { toast } from "sonner"

import { AppSidebar, type NavEntry } from "@/components/layout/app-sidebar"
import { useBootstrap, useSetActiveOutlet } from "@/hooks/use-bootstrap"
import { ApiError } from "@/lib/api-client"

// Menú lateral. Definido acá (client) porque los iconos son componentes
// función y no pueden cruzar la frontera server → client como props.
// Dashboard como item explícito (UX: el logo no era enough).
// Iconos: el set elegido por owner (2026-06-13) — más afines a la categoría
// que el set genérico anterior (Package/Users/BarChart3).
const panelNav: NavEntry[] = [
  { title: "Dashboard", to: "/", icon: LayoutDashboard },
  { title: "Artículos", to: "/items", icon: ShoppingBasket },
  { title: "Contactos", to: "/contacts", icon: Contact },
  { title: "Reportes", to: "/reports", icon: ChartPie },
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
  const setActiveOutlet = useSetActiveOutlet()

  React.useEffect(() => {
    if (error instanceof ApiError && error.status === 401) {
      router.replace("/login")
    }
  }, [error, router])

  // Subtitle del sidebar = nombre de la sucursal activa SIEMPRE que exista
  // (mismo comportamiento que el panel legacy: debajo del nombre de la empresa
  // aparece la sucursal en la que se está trabajando, sin importar si hay 1 o N
  // sucursales). El selector dentro del dropdown sigue gateado a outlets.length>1
  // (no tiene sentido mostrar un picker con una sola opción).
  const outlets = bootstrap?.outlets ?? []
  const subtitle = bootstrap?.activeOutletName ?? ""

  const user = bootstrap
    ? {
        name: bootstrap.companyName || "Punto",
        subtitle,
      }
    : {
        name: isLoading ? "Cargando…" : "Punto User",
        subtitle: "",
      }

  const handleSelectOutlet = (outletId: string) => {
    if (outletId === bootstrap?.activeOutletId) return
    setActiveOutlet.mutate(outletId, {
      onSuccess: ({ outletName }) => {
        toast.success(`Sucursal: ${outletName}`)
      },
      onError: (err) => {
        toast.error(err.message || "No se pudo cambiar de sucursal")
      },
    })
  }

  return (
    <>
      <AppSidebar
        scope="Panel"
        items={panelNav}
        user={user}
        outlets={outlets}
        activeOutletId={bootstrap?.activeOutletId ?? ""}
        onSelectOutlet={handleSelectOutlet}
        isSwitchingOutlet={setActiveOutlet.isPending}
      />
      {children}
    </>
  )
}
