"use client"

import * as React from "react"
import { useRouter, usePathname } from "next/navigation"
import {
  ShoppingBasket,
  Contact,
  ChartPie,
  ScanBarcode,
  LayoutDashboard,
  MessageCircle,
  Flame,
  SquaresIntersect,
  CalendarDays,
  SquareKanban,
  Bookmark,
  Boxes,
  ClipboardEdit,
  ArrowLeftRight,
  RotateCcw,
} from "lucide-react"
import { toast } from "sonner"

import { AppSidebar, type NavEntry } from "@/components/layout/app-sidebar"
import { useBootstrap, useSetActiveOutlet } from "@/hooks/use-bootstrap"
import { useParkedSales } from "@/hooks/use-parked-sales"
import { RealtimeWire } from "@/components/realtime-wire"
import { AgentChatFloating } from "@/components/agent/agent-chat-floating"
import { useSettings } from "@/hooks/use-settings"
import { useViewScope } from "@/hooks/use-view-scope"
import { api, ApiError } from "@/lib/api-client"
import { useQueryClient } from "@tanstack/react-query"
import { useModules } from "@/hooks/use-modules"
import type { ModulesMap } from "@/lib/types/module"

// Menú lateral. Definido acá (client) porque los iconos son componentes
// función y no pueden cruzar la frontera server → client como props.
// Dashboard como item explícito (UX: el logo no era enough).
// Iconos: el set elegido por owner (2026-06-13) — más afines a la categoría
// que el set genérico anterior (Package/Users/BarChart3).
const panelNav: NavEntry[] = [
  { title: "Dashboard", to: "/", icon: LayoutDashboard },
  { title: "Asistente", to: "/chat", icon: MessageCircle },
  { title: "Artículos", to: "/items", icon: ShoppingBasket },
  { title: "Inventario", to: "/inventory-count", icon: Boxes },
  { title: "Ajustes de stock", to: "/stock-adjustment", icon: ClipboardEdit },
  { title: "Contactos", to: "/contacts", icon: Contact },
  { title: "Reportes", to: "/reports", icon: ChartPie },
  { title: "Transferencias", to: "/stock-transfer", icon: ArrowLeftRight },
  // Caja = POS dentro del propio panel (route group (pos), ruta /pos).
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
  const pathname = usePathname()
  // Sidebar contextual: dentro de /pos se muestran los módulos de la caja.
  const isPos = pathname === "/pos" || pathname.startsWith("/pos/")
  // Siempre llamado — el hook maneja su propio ciclo de vida.
  const { data: parkedSales } = useParkedSales()
  // Módulos: solo se muestran items condicionales cuando enabled===true confirmado.
  // Mientras isLoading o error, los items condicionales no aparecen (default conservador).
  const { data: modules, isLoading: modulesLoading } = useModules()
  function moduleEnabled(m: ModulesMap | undefined, key: string): boolean {
    return !modulesLoading && m?.[key]?.enabled === true
  }
  // Nav del POS — construido dentro del componente porque el badge y los módulos son dinámicos.
  // La vuelta al panel es por el logo (linkea al dashboard).
  const posNav: NavEntry[] = [
    { title: "Hotkeys", to: "/pos", icon: Flame },
    ...(moduleEnabled(modules, "tables")
      ? [{ title: "Espacios", to: "/pos/mesas", icon: SquaresIntersect }]
      : []),
    ...(moduleEnabled(modules, "calendar")
      ? [{ title: "Calendario", to: "/pos/calendario", icon: CalendarDays }]
      : []),
    ...(moduleEnabled(modules, "ordersPanel")
      ? [{ title: "Órdenes", to: "/pos/ordenes", icon: SquareKanban }]
      : []),
    {
      title: "Guardadas",
      to: "/pos/guardadas",
      icon: Bookmark,
      badge: parkedSales?.length ? String(parkedSales.length) : undefined,
    },
    { title: "Devoluciones", to: "/pos/devoluciones", icon: RotateCcw },
  ]
  const nav = isPos ? posNav : panelNav
  const { data: bootstrap, isLoading, error } = useBootstrap()
  // El logo de la empresa lo trae /v1/settings (no /v1/bootstrap). Se
  // muestra en el avatar del menu user del footer. staleTime 60s del hook
  // evita el refetch en cada navegación. null si la empresa aún no subió.
  const { data: settings } = useSettings()
  const setActiveOutlet = useSetActiveOutlet()
  const { scope: viewScope, setScope: setViewScope } = useViewScope()
  const qc = useQueryClient()
  React.useEffect(() => {
    if (error instanceof ApiError && error.status === 401) {
      router.replace("/login")
    }
  }, [error, router])

  // Logout del panel — borra SOLO `_jwt_panel` (la cookie del POS `_jwt`
  // queda intacta porque modela device pairing, no sesión humana — ver
  // [[project_pos_dual_session_model]]). Si el endpoint falla, igualmente
  // limpiamos el cache de TanStack y redirigimos: el peor caso es que la
  // cookie quede vigente hasta su TTL natural (24h) pero el usuario sí ve
  // que "se cerró" porque cae al login.
  const handleLogout = React.useCallback(async () => {
    try {
      await api.post("/v1/logout", {})
    } catch {
      // ignore: el redirect al login pasa igual
    }
    qc.clear()
    router.replace("/login")
  }, [qc, router])

  // Subtitle del sidebar = nombre de la sucursal activa SIEMPRE que exista
  // (mismo comportamiento que el panel legacy: debajo del nombre de la empresa
  // aparece la sucursal en la que se está trabajando, sin importar si hay 1 o N
  // sucursales). El selector dentro del dropdown sigue gateado a outlets.length>1
  // (no tiene sentido mostrar un picker con una sola opción).
  // Si viewScope='all', el subtitle refleja el modo consolidado en lugar de
  // la sucursal del JWT (la sucursal del JWT solo afecta escrituras / POS).
  const outlets = bootstrap?.outlets ?? []
  const subtitle =
    viewScope === "all"
      ? "Todas las sucursales"
      : (bootstrap?.activeOutletName ?? "")

  const user = bootstrap
    ? {
        name: bootstrap.companyName || "Punto",
        subtitle,
      }
    : {
        name: isLoading ? "Cargando…" : "Punto User",
        subtitle: "",
      }

  // Invalidamos solo queries de lectura scope-dependientes en lugar de
  // `qc.invalidateQueries()` sin args (que abortaría mutations en vuelo,
  // refetch del bootstrap, etc.). Las query keys cubiertas son las que se
  // verán afectadas por el cambio del header X-Outlet-Id.
  const invalidateScopedReads = React.useCallback(() => {
    const keys = ["dashboard", "reports", "items", "contacts", "outlets", "drawers", "stock"]
    for (const k of keys) {
      qc.invalidateQueries({ queryKey: [k] })
    }
  }, [qc])

  const handleSelectOutlet = (outletId: string) => {
    if (outletId === bootstrap?.activeOutletId) {
      // El JWT ya apunta a esta sucursal — solo apuntamos viewScope y
      // refrescamos queries (que ahora mandarán X-Outlet-Id explícito).
      setViewScope(outletId)
      invalidateScopedReads()
      return
    }
    // setViewScope SOLO tras el éxito de la mutation — si falla, viewScope
    // y JWT permanecen en sincronía con la sucursal anterior.
    setActiveOutlet.mutate(outletId, {
      onSuccess: ({ outletName }) => {
        setViewScope(outletId)
        invalidateScopedReads()
        toast.success(`Sucursal: ${outletName}`)
      },
      onError: (err) => {
        toast.error(err.message || "No se pudo cambiar de sucursal")
      },
    })
  }

  const handleSelectAllOutlets = () => {
    // Modo "Todas" — NO se toca el JWT (las escrituras siguen scopeadas a la
    // sucursal del JWT). El header X-Outlet-Id='all' override solo los reads.
    setViewScope("all")
    invalidateScopedReads()
    toast.success("Mostrando todas las sucursales")
  }

  return (
    <>
      <AppSidebar
        scope="Panel"
        items={nav}
        user={user}
        companyLogo={settings?.hasLogo ? settings.logo : null}
        outlets={outlets}
        activeOutletId={bootstrap?.activeOutletId ?? ""}
        onSelectOutlet={handleSelectOutlet}
        isSwitchingOutlet={setActiveOutlet.isPending}
        viewScope={viewScope}
        onSelectAllOutlets={handleSelectAllOutlets}
        onLogout={handleLogout}
      />
      <RealtimeWire scope={isPos ? "pos" : "panel"}>{children}</RealtimeWire>
      {/* Asistente IA. FAB visible solo fuera de /pos y fuera de /chat.
          El Sheet se monta siempre para que el menú POS pueda abrirlo via store. */}
      {bootstrap?.companyId != null && (
        <AgentChatFloating
          companyName={bootstrap.companyName}
          outletName={bootstrap.activeOutletName}
          showFab={!isPos && pathname !== "/chat"}
        />
      )}
    </>
  )
}
