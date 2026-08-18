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
  Repeat,
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
import { usePosModules } from "@/hooks/use-pos-modules"
import type { ModulesMap } from "@/lib/types/module"
import { usePosUIStore } from "@/lib/ui/store"
import { useCartStore } from "@/lib/cart/store"
import { MODE_VISUALS } from "@/lib/pos/mode-visuals"
import { useHotkeysStore } from "@/lib/hotkeys/store"

// Mismo criterio conservador que `panel-auth-guard.tsx` (posNav): mientras
// isLoading o error, el item condicional NO se muestra — evita parpadeo.
// DEUDA: este sidebar y `posNav` en panel-auth-guard.tsx son DOS fuentes de
// verdad para la nav del POS (panel-auth-guard nunca se renderiza en /pos,
// pos-sidebar.tsx es el real) — deberían unificarse en un solo lugar.
/**
 * ¿El módulo está activo? Solo responde `false` cuando el backend DIJO que está
 * apagado; mientras no haya respuesta buena, devuelve `undefined`.
 *
 * La versión anterior era `!isLoading && m?.[key]?.enabled === true`, que
 * colapsaba tres estados distintos —cargando, error y apagado— en un mismo
 * `false`. Con eso, un fallo de red o un 401 escondía Mesas y Órdenes sin
 * decir nada: el cajero veía el sidebar vacío y el panel seguía mostrando los
 * módulos habilitados. Un módulo no puede desaparecer por un error de lectura.
 */
function moduleEnabled(
  m: ModulesMap | undefined,
  isLoading: boolean,
  isError: boolean,
  key: string,
): boolean | undefined {
  if (isLoading || isError || m === undefined) return undefined
  return m?.[key]?.enabled === true
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
  // `usePosModules` (Bearer del device) y NO `useModules` (cookie del panel):
  // ver el docblock del hook — el cruce de realms hacía desaparecer los
  // módulos del sidebar cuando vencía la sesión de panel del operador.
  const { data: modules, isLoading: modulesLoading, isError: modulesError } = usePosModules()

  // `undefined` = todavía no sabemos. Se muestra el módulo: es preferible una
  // entrada que puede no corresponder —y que al tocarla informe— a un sidebar
  // que se vacía solo. La respuesta real llega en el mismo segundo.
  const ordersEnabled = moduleEnabled(modules, modulesLoading, modulesError, "ordersPanel") !== false
  const tablesEnabled = moduleEnabled(modules, modulesLoading, modulesError, "tables") !== false
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

  // El botón de HotKeys del sidebar promete la VISTA POR DEFECTO (grilla de
  // venta), nunca el editor — ese modo se entra solo desde Menú POS → HotKeys
  // (pos-main-menu.tsx, key "edit-hotkeys"). `editing` vive en un store global
  // (lib/hotkeys/store.ts) que no depende de la ruta, y `HotkeysEditScope`
  // (app/(pos)/pos/layout.tsx) solo lo apaga cuando CAMBIA el pathname. Como
  // este link apunta a la misma URL en la que ya se está parado al editar
  // (`/pos`, con o sin `?view=hotkeys`), Next no dispara esa transición y
  // `editing` quedaba pegado en `true` — el owner reportó que el ícono
  // "llevaba al editor". El fix es apagar el flag acá, explícito, al click.
  const setHotkeysEditing = useHotkeysStore((s) => s.setEditing)

  // Gate del link "Guardadas" según Ajustes → permitirGuardarVentas (default
  // true). La página sigue accesible por URL directa si algún operador la
  // tiene abierta — solo se oculta el link.
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: registerConfigData } = usePosRegisterConfig(activeRegisterId)
  const permitirGuardarVentas = registerConfigData?.config?.permitirGuardarVentas ?? true

  // Selector de modo (owner 2026-08-09): los modos salieron del drawer de
  // Opciones — cambian el POS entero, no la transacción en curso. Con
  // modoSoloOrdenes el POS queda lockeado en orden y el selector se oculta:
  // ofrecer cambiar de modo ahí sería un botón que no puede cumplir.
  const setModeDialogOpen = usePosUIStore((s) => s.setModeDialogOpen)
  const posMode = useCartStore((s) => s.posMode)
  const modoSoloOrdenes = registerConfigData?.config?.modoSoloOrdenes ?? false
  // Color del modo activo sobre el ícono del trigger: la misma señal que la
  // banda del carrito, para que el sidebar delate el modo aunque el carrito
  // esté vacío. Venta = sin tinte (null).
  const modeColor =
    posMode === "orden"
      ? MODE_VISUALS["orden-mostrador"].color
      : posMode === "cotizacion"
        ? MODE_VISUALS.cotizacion.color
        : null

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
                  <Link href={hotkeysHref} onClick={() => setHotkeysEditing(false)}>
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

      {/* Footer = controles del ESTADO de la caja (modo activo, bloqueo),
          separados de la navegación de arriba — misma distinción que sacó los
          modos del drawer de Opciones: navegar te lleva a un lugar, esto
          cambia cómo está operando la caja. */}
      <SidebarFooter className="pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
        <SidebarMenu>
          {!modoSoloOrdenes && (
            <SidebarMenuItem>
              <SidebarMenuButton
                tooltip="Modo del POS"
                onClick={() => setModeDialogOpen(true)}
                className="h-10 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 [&:hover]:!bg-[#E3E5E9] dark:[&:hover]:!bg-[#1A1D1F]"
              >
                {/* El tinte del ícono replica la señal de la banda del
                    carrito: modo activo visible sin abrir nada. */}
                <Repeat style={modeColor ? { color: modeColor } : undefined} />
                <span>Modo</span>
              </SidebarMenuButton>
            </SidebarMenuItem>
          )}
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
