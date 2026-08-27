"use client"

import * as React from "react"
import { useRouter, usePathname } from "next/navigation"
import { toast } from "sonner"

import { AppSidebar } from "@/components/layout/app-sidebar"
import { PANEL_ROUTES, POS_ROUTES } from "@/lib/navigation/routes"
import {
  buildPaletteSections,
  buildSidebarNav,
  type NavContext,
} from "@/lib/navigation/build"
import { usePermissions } from "@/hooks/use-permissions"
import { useBootstrap, useSetActiveOutlet } from "@/hooks/use-bootstrap"
import { useParkedSales } from "@/hooks/use-parked-sales"
import { RealtimeWire } from "@/components/realtime-wire"
import { AgentChatFloating } from "@/components/agent/agent-chat-floating"
import { useSettings } from "@/hooks/use-settings"
import { useViewScope } from "@/hooks/use-view-scope"
import { api } from "@/lib/api-client"
import { clearPanelToken } from "@/lib/auth/panel-token"
import { useQueryClient } from "@tanstack/react-query"
import { useModules } from "@/hooks/use-modules"
import type { ModulesMap } from "@/lib/types/module"
import { AuthSentinel } from "@/components/auth/auth-sentinel"

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
  const { data: bootstrap, isLoading } = useBootstrap()

  // Contexto de navegación: lo que el registro de rutas necesita para decidir
  // qué se muestra. `permsLoaded` solo es true cuando llegó el bootstrap — si
  // no, se muestra TODO sin filtrar (mejor UX + evita hidratación mismatch;
  // ver `isVisible` en lib/navigation/build.ts).
  const navContext: NavContext = {
    perms: permissions,
    permsLoaded: !!bootstrap,
    moduleEnabled: (key) => moduleEnabled(modules, key),
    badges: {
      parkedSales: parkedSales?.length ? String(parkedSales.length) : undefined,
    },
  }

  // Las dos superficies salen del MISMO registro (`lib/navigation/routes.ts`)
  // y del mismo filtro de permisos. Ninguna mantiene su propia lista.
  // El sidebar es contextual: dentro de /pos muestra los módulos de la caja.
  const nav = buildSidebarNav(isPos ? POS_ROUTES : PANEL_ROUTES, navContext)
  // El palette es del panel. En /pos está desactivado (la caja tiene su
  // propia búsqueda), así que no se arma el índice.
  const paletteSections = isPos ? undefined : buildPaletteSections(PANEL_ROUTES, navContext)
  // El logo de la empresa lo trae /v1/settings (no /v1/bootstrap). Se
  // muestra en el avatar del menu user del footer. staleTime 60s del hook
  // evita el refetch en cada navegación. null si la empresa aún no subió.
  const { data: settings } = useSettings()
  const setActiveOutlet = useSetActiveOutlet()
  const { scope: viewScope, setScope: setViewScope } = useViewScope()
  const qc = useQueryClient()
  // 401 de bootstrap ahora lo captura AuthSentinel via evento api:unauthorized.
  // El useEffect anterior fue eliminado para evitar doble navegación.

  // Logout del panel — revoca la sesión del panel en el server y borra SU token
  // del browser. El token del POS (`lib/auth/device-token.ts`) queda intacto:
  // modela device pairing, no sesión humana (ver
  // [[project_pos_dual_session_model]]). Si el endpoint falla, igual limpiamos
  // el token local, el cache de TanStack y redirigimos — el peor caso es que la
  // sesión siga viva en la BD hasta su TTL natural, pero el browser ya no la
  // tiene y el usuario sí ve que "se cerró" porque cae al login.
  const handleLogout = React.useCallback(async () => {
    try {
      await api.post("/v1/logout", {})
    } catch {
      // ignore: el redirect al login pasa igual
    }
    clearPanelToken()
    qc.clear()
    router.replace("/login")
  }, [qc, router])

  // ── Impersonación (admin "entró como" este tenant) ────────────────────────
  // La marca `_imp_panel` la setea el BFF de admin junto a `_jwt_panel` al
  // impersonar (app/api/admin/[...path]/route.ts). Es cosmética: solo decide
  // si se muestra el botón de salida. Estado y no lectura directa en render
  // porque `document` no existe en SSR.
  const [isImpersonating, setIsImpersonating] = React.useState(false)
  React.useEffect(() => {
    setIsImpersonating(document.cookie.split("; ").includes("_imp_panel=1"))
  }, [])

  // Salir = cerrar la sesión IMPERSONADA (el logout normal del panel: revoca
  // `_jwt_panel` en el server) + borrar la marca + volver a /admin, cuya
  // cookie `_jwt_admin` nunca se tocó. Full navigation y no router.push: el
  // realm admin es otro layout tree y conviene rehidratar de cero.
  const handleExitImpersonation = React.useCallback(async () => {
    try {
      await api.post("/v1/logout", {})
    } catch {
      // igual salimos: sin logout la sesión impersonada expira sola en 24h
    }
    clearPanelToken()
    document.cookie = "_imp_panel=; path=/; max-age=0"
    qc.clear()
    window.location.href = "/admin"
  }, [qc])

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
        paletteSections={paletteSections}
        user={user}
        companyLogo={settings?.hasLogo ? settings.logo : null}
        outlets={outlets}
        activeOutletId={bootstrap?.activeOutletId ?? ""}
        onSelectOutlet={handleSelectOutlet}
        isSwitchingOutlet={setActiveOutlet.isPending}
        viewScope={viewScope}
        onSelectAllOutlets={handleSelectAllOutlets}
        onLogout={handleLogout}
        isImpersonating={isImpersonating}
        onExitImpersonation={handleExitImpersonation}
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
