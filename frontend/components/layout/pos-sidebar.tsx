"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import {
  Blocks,
  Bookmark,
  ClipboardCheck,
  ClipboardList,
  LayoutGrid,
  Lock,
  MessageCircle,
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
  useSidebar,
} from "@/components/ui/sidebar"
import { cn } from "@/lib/utils"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { useParkedSales } from "@/hooks/use-parked-sales"
import { useActiveOrders } from "@/hooks/use-orders"
import { useLockStore } from "@/lib/pos/lock-store"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"
import { useCatalogStore } from "@/lib/catalog/store"
import { usePosModules } from "@/hooks/use-pos-modules"
import type { ModulesMap } from "@/lib/types/module"
import { usePosUIStore } from "@/lib/ui/store"
import { useOnlineStatus } from "@/hooks/use-online-status"
import { useCartStore } from "@/lib/cart/store"
import { MODE_VISUALS } from "@/lib/pos/mode-visuals"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { toast } from "sonner"

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
 * `false`. Con eso, un fallo de red o un 401 escondía Espacios y Órdenes sin
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

// Alto de fila: `h-12` (48px) en mobile porque el menú se abre como drawer de
// abajo y el cajero lo toca con el pulgar — sobre el mínimo táctil de 44px
// (§1 de context/14 habilita sobreescribir el tamaño shadcn cuando lo pide una
// restricción real, documentándolo). En desktop vuelve al rail de íconos
// (`md:h-8`), donde se opera con mouse.
const NAV_ITEM_CLASS =
  "h-12 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 data-[active=true]:!bg-[#EAEEF1] dark:data-[active=true]:!bg-[oklch(0.16_0_0)] [&:hover:not([data-active=true])]:!bg-[#E3E5E9] dark:[&:hover:not([data-active=true])]:!bg-[#1A1D1F]"

const ACTION_ITEM_CLASS =
  "h-12 text-base [&>svg]:size-5 md:h-8 md:text-sm md:[&>svg]:size-4 [&:hover]:!bg-[#E3E5E9] dark:[&:hover]:!bg-[#1A1D1F]"

// El badge se posiciona absoluto contra la fila; con `h-12` en mobile las
// reglas del primitive (calibradas para `h-8`) lo dejaban pegado arriba.
const MENU_BADGE_CLASS = "top-3.5! md:top-1.5!"

/**
 * Sidebar mínimo exclusivo del POS. Muestra SOLO las rutas del workspace
 * de caja — sin links al panel (Artículos, Contactos, Reportes, etc.).
 *
 * Siempre renderizado collapsed/icon en desktop (PosSidebarProvider lo fuerza).
 * En mobile baja como DRAWER de abajo (`mobileVariant="drawer"`), igual que el
 * menú "Opciones de venta": la caja se opera con el pulgar y un panel lateral
 * queda fuera del alcance del dedo (pedido del owner 2026-08-25).
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
  // Conteo de stock: mismo criterio conservador que los dos de arriba —
  // mientras no sepamos, se muestra; solo un "apagado" explícito lo esconde.
  const stockCountEnabled =
    moduleEnabled(modules, modulesLoading, modulesError, "stockCount") !== false
  const lock = useLockStore((s) => s.lock)
  // Permisos REALES del operador desbloqueado (llegan del unlock por PIN,
  // filtrados al prefijo `pos.` en el backend). Es la ÚNICA fuente válida de
  // permisos dentro de /pos — ver el comentario del item "Asistente" abajo.
  const operatorPermissions = useLockStore((s) => s.operatorPermissions)
  const canUseAgent = operatorPermissions.includes("pos.ai.use")
  const canCountStock = operatorPermissions.includes("pos.stock.count")
  const isOnline = useOnlineStatus()
  const setAgentDialogOpen = usePosUIStore((s) => s.setAgentDialogOpen)
  const parkedCount = parkedSales?.length ?? 0
  const activeOrdersCount = activeOrders?.orders.length ?? 0

  // HotKeys es la home del workspace (`/pos`), no una ruta hija: en desktop el
  // bloque izquierdo ya pinta la grilla, así que el link va pelado. En mobile
  // ese bloque no se pinta (su lugar lo ocupa el carrito) y navegar a /pos
  // dejaba la grilla inalcanzable — reporte del owner 2026-08-01. El param
  // `?view=hotkeys` la abre como módulo-modal, igual que Órdenes/Espacios, y
  // sobrevive un reload porque vive en la URL y no en un store.
  // Mismo `isMobile` que usa el primitive para decidir sheet/drawer/rail — se
  // lee del contexto en vez de suscribirse otra vez a `useIsMobile()`.
  const { isMobile, setOpenMobile } = useSidebar()
  const hotkeysHref = isMobile ? "/pos?view=hotkeys" : "/pos"

  // El drawer se cierra al tocar cualquier ítem: navegar o disparar la acción y
  // quedarse con el menú tapando el carrito no sirve de nada. Mismo patrón que
  // `app-sidebar.tsx` / `admin-sidebar.tsx` — en desktop es no-op porque el rail
  // es persistente y `openMobile` ni se usa.
  const closeMobile = React.useCallback(() => {
    if (isMobile) setOpenMobile(false)
  }, [isMobile, setOpenMobile])

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
    <Sidebar collapsible="icon" variant="inset" mobileVariant="drawer">
      {/* Los insets del notch solo aplican al rail de desktop, que sí pega
          contra el borde de la pantalla. El drawer mobile ya descuenta su
          propio inset inferior en el primitive (`components/ui/drawer.tsx`) y
          nace lejos del superior — sumar padding acá abriría un hueco muerto
          arriba del menú. */}
      <SidebarHeader
        className={cn(!isMobile && "pt-[calc(0.5rem+var(--safe-t))]")}
      >
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
                  className={NAV_ITEM_CLASS}
                >
                  {/* `isActive` compara contra `pathname`, que NO incluye la
                      query — se marca igual con o sin `?view=hotkeys`. */}
                  <Link
                    href={hotkeysHref}
                    onClick={() => {
                      setHotkeysEditing(false)
                      closeMobile()
                    }}
                  >
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
                    className={NAV_ITEM_CLASS}
                  >
                    <Link href="/pos/ordenes" onClick={closeMobile}>
                      <ClipboardList />
                      <span>Órdenes</span>
                    </Link>
                  </SidebarMenuButton>
                  {activeOrdersCount > 0 && (
                    <SidebarMenuBadge className={MENU_BADGE_CLASS}>
                      {activeOrdersCount}
                    </SidebarMenuBadge>
                  )}
                </SidebarMenuItem>
              )}

              {tablesEnabled && (
                <SidebarMenuItem>
                  <SidebarMenuButton
                    asChild
                    isActive={pathname.startsWith("/pos/espacios")}
                    tooltip="Espacios"
                    className={NAV_ITEM_CLASS}
                  >
                    <Link href="/pos/espacios" onClick={closeMobile}>
                      <LayoutGrid />
                      <span>Espacios</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              )}

              {/* Conteo de stock (context/63 F1). Doble gate, y los dos hacen
                  falta: el MÓDULO dice si el comercio lo usa, el PERMISO dice
                  si esta persona puede contar. Un cajero sin `pos.stock.count`
                  en un comercio que sí tiene el módulo no debería ver un link
                  que le va a contestar 403.

                  El permiso sale del lock-store —los permisos reales del
                  operador del PIN, filtrados al prefijo `pos.` por el backend—
                  y NUNCA de `usePermission()`, que resuelve contra el rol
                  `device` y es el mismo para cualquiera que agarre la tablet. */}
              {stockCountEnabled && canCountStock && (
                <SidebarMenuItem>
                  <SidebarMenuButton
                    asChild
                    isActive={pathname.startsWith("/pos/conteo")}
                    tooltip="Conteo de stock"
                    className={NAV_ITEM_CLASS}
                  >
                    <Link href="/pos/conteo" onClick={closeMobile}>
                      <ClipboardCheck />
                      <span>Conteo</span>
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
                    className={NAV_ITEM_CLASS}
                  >
                    <Link href="/pos/guardadas" onClick={closeMobile}>
                      <Bookmark />
                      <span>Guardadas</span>
                    </Link>
                  </SidebarMenuButton>
                  {parkedCount > 0 && (
                    <SidebarMenuBadge className={MENU_BADGE_CLASS}>
                      {parkedCount}
                    </SidebarMenuBadge>
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
      <SidebarFooter
        className={cn(!isMobile && "pb-[calc(0.5rem+var(--safe-b))]")}
      >
        <SidebarMenu>
          {/* Asistente — PRIMER item del footer, arriba de "Modo" (context/59
              D7, decisión del owner). Sin FAB: taparía el CTA de cobrar.

              Gate: `operatorPermissions` del lock-store, que son los permisos
              REALES de la persona que desbloqueó con su PIN. NUNCA
              `usePermission()`: ese sale de `useBootstrap()`, el bootstrap del
              realm PANEL, y en una caja pareada el rol es el rol `device` — el
              item aparecía o no según cómo se hubiera abierto la caja
              (revertido en `80a21be2`).

              El diálogo NO se monta acá sino en `app/(pos)/layout.tsx`: en
              mobile este footer vive dentro del drawer del sidebar, que
              `closeMobile()` desmonta en el mismo toque que abre el chat. */}
          {canUseAgent && (
            <SidebarMenuItem>
              <SidebarMenuButton
                tooltip={
                  isOnline
                    ? "Asistente"
                    : "Sin conexión — el asistente necesita internet"
                }
                // El impedimento se informa en el control que impide, nunca en
                // una banda (`feedback_pos_alerts_on_the_action_not_banners`).
                // El item no se esconde: sacarlo movería "Modo" y "Bloquear"
                // de lugar cada vez que se cae la red (regla #10 de context/14).
                //
                // `aria-disabled` y NO `disabled`, mismo criterio que el
                // `PayCta` del carrito: un botón realmente deshabilitado no
                // recibe eventos de puntero y el tooltip del sidebar —que es
                // donde vive el motivo— nunca llegaría a mostrarse. El click
                // se bloquea abajo, a mano.
                //
                // En mobile no hay tooltip (el primitive lo oculta fuera del
                // rail colapsado) ni hay hover, así que ahí el motivo sale por
                // toast al tocar: sigue siendo "el aviso en el control", con
                // el mecanismo que la superficie táctil permite.
                aria-disabled={!isOnline || undefined}
                className={cn(ACTION_ITEM_CLASS, !isOnline && "opacity-50")}
                onClick={() => {
                  if (!isOnline) {
                    toast.error("Sin conexión", {
                      description: "El asistente necesita internet para responder.",
                    })
                    return
                  }
                  closeMobile()
                  setAgentDialogOpen(true)
                }}
              >
                <MessageCircle />
                <span>Asistente</span>
              </SidebarMenuButton>
            </SidebarMenuItem>
          )}
          {!modoSoloOrdenes && (
            <SidebarMenuItem>
              <SidebarMenuButton
                tooltip="Modo del POS"
                onClick={() => {
                  closeMobile()
                  setModeDialogOpen(true)
                }}
                className={ACTION_ITEM_CLASS}
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
              onClick={() => {
                closeMobile()
                lock()
              }}
              className={ACTION_ITEM_CLASS}
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
