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
import { usePermission, usePermissions } from "@/hooks/use-permissions"
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
import { AccountDeniedScreen } from "@/components/auth/account-denied-screen"
import {
  ACCOUNT_DENIED_EVENT,
  readAccountDenialReason,
  type AccountDeniedEventDetail,
  type AccountDenialReason,
} from "@/lib/auth/account-denial"

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
  // Mismo permiso que exige api/v1/ai/execute.php:23 — gatea FAB y Sheet.
  const canUseAgent = usePermission("ai.agent.use")
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
  const { data: bootstrap, isLoading, error: bootstrapError } = useBootstrap()

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

  // ── Cuenta bloqueada / suspendida / inactiva ──────────────────────────────
  //
  // Este 403 NO es un permiso: lo devuelve el embudo de auth de la API
  // (`apiAuthTenant()` → `companyAccessDenial()`) para TODAS las requests del
  // tenant a la vez. Sin este gate el panel montaba el layout completo con cada
  // listado vacío y 403s silenciosos en consola: un panel muerto que no le
  // decía al usuario que su cuenta estaba bloqueada por falta de pago
  // (incidente 2026-09-11, job `plan-lifecycle`).
  //
  // Vive ACÁ y no en cada página por la misma razón que el gate de auth: es el
  // único lugar por el que pasan todas las pantallas del panel. Y se alimenta
  // de DOS fuentes, porque el bloqueo llega en dos momentos distintos:
  //
  //   1. al ARRANCAR — el bootstrap ya falla con 403, se lee de su error;
  //   2. a MITAD DE SESIÓN — el panel está cargado y el job corre mientras el
  //      usuario trabaja: ahí el 403 lo ve la primera request que salga, y el
  //      transporte (`lib/api-client.ts`) emite `api:account-denied`.
  //
  // Los 403 de permisos no llegan por ninguna de las dos: no traen `reason`.
  const [deniedByEvent, setDeniedByEvent] = React.useState<AccountDenialReason | null>(null)
  React.useEffect(() => {
    function handler(e: Event) {
      const detail = (e as CustomEvent<AccountDeniedEventDetail>).detail
      if (detail?.reason) setDeniedByEvent(detail.reason)
    }
    window.addEventListener(ACCOUNT_DENIED_EVENT, handler)
    return () => window.removeEventListener(ACCOUNT_DENIED_EVENT, handler)
  }, [])

  // El error del bootstrap MANDA sobre el evento: es la fuente que se vuelve a
  // consultar al reintentar, así que si el bootstrap vuelve a cargar bien, el
  // estado se apaga solo.
  const denialReason = readAccountDenialReason(bootstrapError) ?? deniedByEvent

  // "Ya regularicé el pago" — se limpia la marca del evento y se refetchea el
  // bootstrap. Si la cuenta sigue bloqueada, su 403 vuelve a encender la
  // pantalla por el camino 1; si se reactivó, el panel carga normalmente.
  const handleRetryAccount = React.useCallback(() => {
    setDeniedByEvent(null)
    qc.invalidateQueries()
  }, [qc])

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

  // La cuenta no puede operar → pantalla de estado EN LUGAR del panel. No se
  // monta el sidebar ni los children: no hay datos que mostrar y cada query
  // que arrancara volvería a chocar contra el mismo 403.
  //
  // La sesión NO se cierra sola — el usuario sale con el botón de la pantalla
  // si quiere. Desloguearlo automáticamente le sacaría el único cartel que le
  // explica qué pasó.
  //
  // `AuthSentinel` sigue montado: un 401 (sesión vencida mientras mira esta
  // pantalla) tiene que seguir mandando al login.
  if (denialReason) {
    return (
      <>
        <AuthSentinel />
        <AccountDeniedScreen
          reason={denialReason}
          onLogout={handleLogout}
          onRetry={handleRetryAccount}
        />
      </>
    )
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
      {/* Asistente IA del PANEL. FAB oculto en /chat, que ya es el chat.
          Este guard NO cubre /pos —el grupo `(pos)` tiene el suyo—, así que la
          caja monta su propia instancia: `components/pos/pos-agent-chat.tsx`.
          El gate `ai.agent.use` espeja el del backend
          (api/v1/ai/execute.php:23): sin el permiso el endpoint rechaza igual,
          así que ni el FAB ni el Sheet se montan. */}
      {bootstrap?.companyId != null && canUseAgent && (
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
