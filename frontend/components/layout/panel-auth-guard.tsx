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
  LayoutGrid,
  CalendarDays,
  SquareKanban,
  Bookmark,
  Boxes,
  ClipboardEdit,
  ArrowLeftRight,
  Factory,
  LayoutTemplate,
  RotateCcw,
  Users,
  Truck,
  UserCog,
  ScrollText,
  HandCoins,
  Gift,
  RefreshCw,
  Package,
  ClipboardList,
  Wallet,
  Banknote,
  Landmark,
} from "lucide-react"
import { toast } from "sonner"

import { AppSidebar, type NavEntry, type NavItem, type NavGroup } from "@/components/layout/app-sidebar"
import { usePermissions } from "@/hooks/use-permissions"
import { useBootstrap, useSetActiveOutlet } from "@/hooks/use-bootstrap"
import { useParkedSales } from "@/hooks/use-parked-sales"
import { RealtimeWire } from "@/components/realtime-wire"
import { AgentChatFloating } from "@/components/agent/agent-chat-floating"
import { useSettings } from "@/hooks/use-settings"
import { useViewScope } from "@/hooks/use-view-scope"
import { api } from "@/lib/api-client"
import { useQueryClient } from "@tanstack/react-query"
import { useModules } from "@/hooks/use-modules"
import type { ModulesMap } from "@/lib/types/module"
import { AuthSentinel } from "@/components/auth/auth-sentinel"

// Menú lateral. Definido acá (client) porque los iconos son componentes
// función y no pueden cruzar la frontera server → client como props.
// Dashboard como item explícito (UX: el logo no era enough).
// Iconos: el set elegido por owner (2026-06-13) — más afines a la categoría
// que el set genérico anterior (Package/Users/BarChart3).
const panelNav: NavEntry[] = [
  { title: "Dashboard", to: "/", icon: LayoutDashboard },
  { title: "Asistente", to: "/chat", icon: MessageCircle, hideOnMobile: true },
  {
    title: "Ventas",
    icon: HandCoins,
    items: [
      { title: "Transacciones", to: "/reports/transactions", icon: ScrollText, requires: "reports.sales.view" },
      { title: "Cuentas por cobrar", to: "/reports/open-invoices", icon: HandCoins, requires: "reports.sales.view" },
      { title: "Gift cards", to: "/reports/giftcards", icon: Gift, requires: "reports.giftcards.view" },
      { title: "Facturas recurrentes", to: "/reports/recurring", icon: RefreshCw, requires: "reports.recurring.view" },
    ],
  },
  {
    title: "Artículos",
    icon: LayoutTemplate,
    items: [
      { title: "Catálogo", to: "/items", icon: ShoppingBasket, requires: "inventory.item.view" },
      { title: "Inventario", to: "/inventory-count", icon: Boxes, requires: "inventory.stock.adjust" },
      { title: "Ajustes de stock", to: "/stock-adjustment", icon: ClipboardEdit, requires: "inventory.stock.adjust" },
      { title: "Transferencias", to: "/stock-transfer", icon: ArrowLeftRight, requires: "inventory.transfer" },
      { title: "Producción", to: "/produccion", icon: Factory, requires: "production.manage" },
    ],
  },
  {
    title: "Compras y Gastos",
    icon: ShoppingBasket,
    items: [
      { title: "Registro de compras", to: "/purchase", icon: Package },
      { title: "Compras y gastos", to: "/reports/purchases", icon: ClipboardList, requires: "reports.purchases.view" },
      { title: "Cuentas por pagar", to: "/reports/open-invoices?state=outcome", icon: Wallet, requires: "reports.sales.view" },
      { title: "Movimientos de caja", to: "/reports/expenses", icon: Banknote, requires: "reports.expenses.view" },
    ],
  },
  {
    title: "Contactos",
    icon: Contact,
    items: [
      { title: "Clientes", to: "/contacts?type=1", icon: Users, requires: "contacts.customer.view" },
      { title: "Proveedores", to: "/contacts?type=2", icon: Truck, requires: "contacts.supplier.view" },
      { title: "Usuarios", to: "/contacts?type=0", icon: UserCog, requires: "contacts.user.view" },
    ],
  },
  { title: "Finanzas", to: "/finanzas", icon: Landmark, requires: "finance.manage" },
  { title: "Reportes", to: "/reports", icon: ChartPie, requires: "reports.sales.view" },
  { title: "Caja", to: "/pos", icon: ScanBarcode }, // Caja = POS dentro del propio panel...
]

/**
 * Filtra entries por permisos. Si `permsLoaded=false` (bootstrap aún cargando),
 * retorna TODO sin filtrar — durante loading se muestran todos los items y
 * cuando llegan los perms, se filtran. Evita:
 *  1. Hidratación mismatch (React #418): SSR/primer-client tienen
 *     perms=[] → filtro oculta todo. Tras llegada del bootstrap → perms
 *     llenos → re-render con árbol distinto → mismatch.
 *  2. Flicker de sidebar vacío durante el load inicial.
 */
function filterByPermissions(entries: NavEntry[], perms: string[], permsLoaded: boolean): NavEntry[] {
  if (!permsLoaded) return entries
  return entries.reduce<NavEntry[]>((acc, entry) => {
    const asGroup = entry as NavGroup
    if (asGroup.items !== undefined) {
      const filteredItems = asGroup.items.filter(
        (item: NavItem) => !item.requires || perms.includes(item.requires),
      )
      if (filteredItems.length > 0) {
        acc.push({ ...asGroup, items: filteredItems })
      }
    } else {
      const item = entry as NavItem
      if (!item.requires || perms.includes(item.requires)) {
        acc.push(entry)
      }
    }
    return acc
  }, [])
}

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
  const permissions = usePermissions()
  // Sidebar contextual: dentro de /pos se muestran los módulos de la caja.
  const isPos = pathname === "/pos" || pathname.startsWith("/pos/")
  // Solo en POS: el endpoint /v1/parked-sales requiere Bearer del device.
  // Desde el panel sin POS pareado devolvía 401 tras ~6s, retrasando todas
  // las cargas de página (incluido /settings/devices). Incidente 2026-06-28.
  const { data: parkedSales } = useParkedSales({ enabled: isPos })
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
      ? [{ title: "Espacios", to: "/pos/espacios", icon: LayoutGrid }]
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
  ]
  const { data: bootstrap, isLoading } = useBootstrap()
  // permsLoaded: solo filtrar cuando el bootstrap llegó. Si no, mostrar TODO
  // (mejor UX + evita hidratación mismatch — ver filterByPermissions).
  const nav = isPos ? posNav : filterByPermissions(panelNav, permissions, !!bootstrap)
  // El logo de la empresa lo trae /v1/settings (no /v1/bootstrap). Se
  // muestra en el avatar del menu user del footer. staleTime 60s del hook
  // evita el refetch en cada navegación. null si la empresa aún no subió.
  const { data: settings } = useSettings()
  const setActiveOutlet = useSetActiveOutlet()
  const { scope: viewScope, setScope: setViewScope } = useViewScope()
  const qc = useQueryClient()
  // 401 de bootstrap ahora lo captura AuthSentinel via evento api:unauthorized.
  // El useEffect anterior fue eliminado para evitar doble navegación.

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
  const outlets = bootstrap?.outlets ?? []

  // Sucursal SELECCIONADA (view-scope) que ve el operador en el dropdown del
  // logo. El agente IA debe respetarla igual que el resto del panel (header
  // `X-Outlet-Id`). Si no hay override (viewScope null), cae al outlet del JWT.
  const viewOutletId =
    typeof viewScope === "string" && viewScope !== "all" ? viewScope : viewScope === "all" ? "all" : ""
  const viewOutletName =
    viewScope === "all"
      ? "Todas las sucursales"
      : typeof viewScope === "string"
        ? (outlets.find((o) => o.id === viewScope)?.name ?? bootstrap?.activeOutletName ?? "")
        : (bootstrap?.activeOutletName ?? "")

  // El footer muestra la sucursal SELECCIONADA (view-scope), consistente con el
  // dropdown del logo — antes mostraba la del JWT (activeOutlet) y desalineaba.
  const user = bootstrap
    ? {
        name: bootstrap.companyName || "Punto",
        subtitle: viewOutletName,
      }
    : {
        name: isLoading ? "Cargando…" : "Punto User",
        subtitle: "",
      }

  // Config/identity keys that must NOT be invalidated on outlet change.
  // Everything else is treated as outlet-scoped and gets refetched.
  // Denylist is robust to new per-outlet keys being added — unlike the old
  // allowlist which silently missed keys (e.g. "dashboard-widget" vs "dashboard").
  const NON_SCOPED_ROOTS = React.useMemo(
    () =>
      new Set([
        "bootstrap",
        "pos-bootstrap",
        "pos-bootstrap-auth",
        "pos-config",
        "pos-hotkeys",
        "settings",
        "modules",
        "admin",
        "billing",
        "roles",
        "team",
        "team-roles",
        "document-templates",
        "printer-bindings",
        "pos-devices",
        "device-invitations",
        "registers",
        "permission-catalog",
        "me",
        "users",
        "auth-sessions",
        "plans",
        "companies",
        "company",
        "currencies",
        "ai-balance",
        "ai-ledger",
        "screens",
      ]),
    [],
  )

  // Invalidate all outlet-scoped queries by predicate (denylist approach).
  // An allowlist was used before but drifted — e.g. "dashboard-widget" was missed
  // because the key differs from "dashboard". A denylist is robust to new keys.
  const invalidateScopedReads = React.useCallback(() => {
    qc.invalidateQueries({
      predicate: (q) => {
        const root = q.queryKey?.[0]
        return typeof root === "string" && !NON_SCOPED_ROOTS.has(root)
      },
    })
  }, [qc, NON_SCOPED_ROOTS])

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
      <AuthSentinel />
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
          viewOutletId={viewOutletId}
          viewOutletName={viewOutletName}
          showFab={!isPos && pathname !== "/chat"}
        />
      )}
    </>
  )
}
