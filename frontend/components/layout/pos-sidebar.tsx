"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import {
  Blocks,
  Bookmark,
  ClipboardList,
  LayoutGrid,
  Lock,
} from "lucide-react"
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar"
import { cn } from "@/lib/utils"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { useParkedSales } from "@/hooks/use-parked-sales"
import { useActiveOrders } from "@/hooks/use-orders"
import { useLockStore } from "@/lib/pos/lock-store"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"
import { useCatalogStore } from "@/lib/catalog/store"
import { useIsMobile } from "@/hooks/use-mobile"
import { useModules } from "@/hooks/use-modules"
import type { ModulesMap } from "@/lib/types/module"

// Mismo criterio conservador que `panel-auth-guard.tsx` (posNav): mientras
// isLoading o error, el item condicional NO se muestra — evita parpadeo.
// DEUDA: este sidebar y `posNav` en panel-auth-guard.tsx son DOS fuentes de
// verdad para la nav del POS (panel-auth-guard nunca se renderiza en /pos,
// pos-sidebar.tsx es el real) — deberían unificarse en un solo lugar.
function moduleEnabled(m: ModulesMap | undefined, isLoading: boolean, key: string): boolean {
  return !isLoading && m?.[key]?.enabled === true
}

/**
 * Sidebar mínimo exclusivo del POS. Muestra SOLO las rutas del workspace
 * de caja — sin links al panel (Artículos, Contactos, Reportes, etc.).
 *
 * Siempre renderizado collapsed/icon en desktop (PosSidebarProvider lo fuerza).
 * En mobile aparece como Sheet al abrirse.
 */
export function PosSidebar() {
  const pathname = usePathname()
  const { data: parkedSales } = useParkedSales()
  const { data: activeOrders } = useActiveOrders()
  const { data: modules, isLoading: modulesLoading } = useModules()
  const ordersEnabled = moduleEnabled(modules, modulesLoading, "ordersPanel")
  const tablesEnabled = moduleEnabled(modules, modulesLoading, "tables")
  const lock = useLockStore((s) => s.lock)
  const parkedCount = parkedSales?.length ?? 0
  const activeOrdersCount = activeOrders?.orders.length ?? 0

  // HotKeys es la home del workspace (`/pos`), no una ruta hija: en desktop el
  // bloque izquierdo ya pinta la grilla, así que el link va pelado. En mobile
  // ese bloque no se pinta (su lugar lo ocupa el carrito) y navegar a /pos
  // dejaba la grilla inalcanzable — reporte del owner 2026-08-01. El param
  // `?view=hotkeys` la abre como módulo-modal, igual que Órdenes/Espacios, y
  // sobrevive un reload porque vive en la URL y no en un store.
  const isMobile = useIsMobile()
  const hotkeysHref = isMobile ? "/pos?view=hotkeys" : "/pos"

  // Gate del link "Guardadas" según Ajustes → permitirGuardarVentas (default
  // true). La página sigue accesible por URL directa si algún operador la
  // tiene abierta — solo se oculta el link.
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: registerConfigData } = usePosRegisterConfig(activeRegisterId)
  const permitirGuardarVentas = registerConfigData?.config?.permitirGuardarVentas ?? true

  return (
    <Sidebar collapsible="icon" variant="inset">
      <SidebarHeader className="pt-[calc(0.5rem+env(safe-area-inset-top))]">
        <div className="flex items-center gap-2 px-2 py-1.5 group-data-[collapsible=icon]:px-0 group-data-[collapsible=icon]:justify-center">
          <Link
            href="/"
            aria-label="Ir al panel"
            className={cn(
              "hidden size-8 aspect-square shrink-0 items-center justify-center group-data-[collapsible=icon]:flex",
              "cursor-pointer transition-opacity hover:opacity-90",
            )}
          >
            <PuntoLogo variant="mark" className="size-8" />
          </Link>
          <div className="grid group-data-[collapsible=icon]:hidden min-w-0">
            <span className="truncate text-sm font-semibold leading-tight">Punto</span>
          </div>
        </div>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupContent>
            <SidebarMenu className="gap-1">
              <SidebarMenuItem>
                <SidebarMenuButton
                  asChild
                  isActive={pathname === "/pos"}
                  tooltip="HotKeys"
                  className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
                >
                  {/* `isActive` compara contra `pathname`, que NO incluye la
                      query — se marca igual con o sin `?view=hotkeys`. */}
                  <Link href={hotkeysHref}>
                    <Blocks />
                    <span>HotKeys</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>

              {ordersEnabled && (
                <SidebarMenuItem>
                  <SidebarMenuButton
                    asChild
                    isActive={pathname.startsWith("/pos/ordenes")}
                    tooltip="Órdenes"
                    className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
                  >
                    <Link href="/pos/ordenes">
                      <ClipboardList />
                      <span>Órdenes</span>
                    </Link>
                  </SidebarMenuButton>
                  {activeOrdersCount > 0 && (
                    <SidebarMenuBadge>{activeOrdersCount}</SidebarMenuBadge>
                  )}
                </SidebarMenuItem>
              )}

              {tablesEnabled && (
                <SidebarMenuItem>
                  <SidebarMenuButton
                    asChild
                    isActive={pathname.startsWith("/pos/espacios")}
                    tooltip="Espacios"
                    className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
                  >
                    <Link href="/pos/espacios">
                      <LayoutGrid />
                      <span>Espacios</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              )}

              {permitirGuardarVentas && (
                <SidebarMenuItem>
                  <SidebarMenuButton
                    asChild
                    isActive={pathname.startsWith("/pos/guardadas")}
                    tooltip="Guardadas"
                    className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"
                  >
                    <Link href="/pos/guardadas">
                      <Bookmark />
                      <span>Guardadas</span>
                    </Link>
                  </SidebarMenuButton>
                  {parkedCount > 0 && (
                    <SidebarMenuBadge>{parkedCount}</SidebarMenuBadge>
                  )}
                </SidebarMenuItem>
              )}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      <SidebarFooter className="pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton
              tooltip="Bloquear"
              onClick={lock}
              className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 [&:hover]:!bg-[#E3E5E9] dark:[&:hover]:!bg-[#1A1D1F]"
            >
              <Lock />
              <span>Bloquear</span>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>
    </Sidebar>
  )
}
