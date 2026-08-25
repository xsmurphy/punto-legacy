"use client"

/**
 * Menú principal del POS — modal tipo settings (sidebar + content area).
 *
 * Reemplaza el overlay fullscreen translúcido que venía del legacy. Ahora usa
 * el mismo patrón de Dialog que /settings: sidebar a la izquierda con los
 * items del menú, content area a la derecha con descripción + CTA (default)
 * o con un componente custom rico (cuando la sección tiene `CustomContent`).
 *
 * Abre con el botón "≡" del toolbar de caja (Cart Panel) o con el atajo Q.
 * ESC lo cierra vía el Dialog de shadcn (sin handler manual).
 *
 * Items: Control de Caja · Transacciones · Agenda · Órdenes · Impresoras ·
 * Módulos · Ajustes · Editar Hotkeys.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import {
  Menu,
  AppWindowMac,
  Calculator,
  ReceiptText,
  CalendarDays,
  SquareKanban,
  Settings,
  LayoutGrid,
  DoorOpen,
  ArrowDown,
  ArrowUp,
  Printer,
  Palette,
  Component,
  Bell,
  X,
  type LucideIcon, 
  CloudOff,
} from "lucide-react"
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, XAxis, YAxis } from "recharts"

import { cn } from "@/lib/utils"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"
import { SyncQueueList } from "@/components/pos/sync-queue-list"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { Avatar, AvatarImage, AvatarFallback } from "@/components/ui/avatar"
import { useCatalogStore } from "@/lib/catalog/store"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { usePosUIStore } from "@/lib/ui/store"
import { useCartStore } from "@/lib/cart/store"
import { ThemePicker } from "@/components/theme-picker"
import { usePrintWithPicker } from "@/lib/hardware/printers/print-with-fallback"
import { usePrinterBindings } from "@/hooks/use-printer-bindings"
import { posApi } from "@/lib/api/pos-client"
import type { TicketData, TicketItem } from "@/lib/hardware/printers"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
import { CashMovementDialog } from "@/components/register/cash-movement-dialog"
import { formatMoney } from "@/lib/format-money"
import { formatDateTime, formatRelativeShort } from "@/lib/format-date"
import { StatTile } from "@/components/domain/reports/stat-tile"
import { useLockStore } from "@/lib/pos/lock-store"
import {
  useDrawerStatus,
  useDrawerSummary,
  useDrawerHourlyStats,
  useLocalShiftTotals,
  useOpenDrawer,
  useCloseDrawer,
  useDrawerExpense,
  useDrawerIncome,
  useShiftMethods,
  useShiftCloseBlockers,
  DrawerActionError,
  type DrawerSummary,
  type DrawerHourlyRow,
} from "@/hooks/use-drawer"
import {
  blockerOrderLabel,
  blockerSpaceLabel,
  shiftCloseBlockedSummary,
  type ShiftCloseBlockers,
} from "@/lib/pos/shift-close-gate"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { gapMessages, type CountedMethod } from "@/lib/pos/local-shift-total"
import {
  DrawerCloseReportDialog,
  DrawerCountDialog,
} from "@/components/pos/drawer-count-dialog"
import {
  clearShiftCloseReport,
  closeTotalsMatch,
  parseServerCloseTotals,
  readShiftCloseReport,
  type ServerCloseMethodRow,
  type ShiftCloseReport,
} from "@/lib/pos/shift-close-reconciliation"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { toast } from "sonner"
import { useOnlineStatus } from "@/hooks/use-online-status"
import { peekAll } from "@/lib/pos/offline-queue"
import { getOpsCount, peekAllOps, type PendingOpRow } from "@/lib/pos/pending-ops"
import { syncPendingOps } from "@/lib/pos/pending-ops-sync"
import { hasContextScopedWork } from "@/lib/pos/context-reset"
import { sendPendingOp } from "@/lib/pos/pending-ops-transport"
import { usePosOutlets, usePosRegisters } from "@/hooks/use-pos-outlets"
import { useUpdateDeviceContext } from "@/hooks/use-update-device-context"
import { PosTransactionsDialog } from "@/components/register/pos-transactions-dialog"
import { PrintersManager } from "@/components/settings/printers-manager"
import { RemoveDeviceDialog } from "@/components/pos/remove-device-dialog"
import {
  usePosRegisterConfig,
  useUpdatePosRegisterConfig,
  POS_REGISTER_CONFIG_DEFAULTS,
  type PosRegisterConfig,
} from "@/hooks/use-pos-config"

// ── Tipos ────────────────────────────────────────────────────────────────────

interface MenuSection {
  key: string
  label: string
  icon: LucideIcon
  /**
   * Si está definido, se renderiza en el content area en lugar del bloque
   * descripción + CTA default. El componente recibe el router y el setter
   * del modal a través de context (ver MenuContentCtx).
   */
  CustomContent?: React.ComponentType
  // Solo para items sin CustomContent (render default):
  description?: string
  ctaLabel?: string
  /** Si true, el CTA se muestra deshabilitado. */
  disabled?: boolean
  /**
   * Acción directa al hacer click en el sidebar: si está definido, ejecuta
   * `onSelect` en lugar de cambiar `activeKey` al content area.
   */
  onSelect?: (helpers: {
    setOpen: (v: boolean) => void
    activeRegisterId: string
    router: ReturnType<typeof import("next/navigation").useRouter>
  }) => void
}

// ── Context para pasar router/setOpen a los custom panels sin prop-drilling ──

interface MenuContentCtxValue {
  setOpen: (v: boolean) => void
  router: ReturnType<typeof useRouter>
}

const MenuContentCtx = React.createContext<MenuContentCtxValue | null>(null)

function useMenuCtx() {
  const ctx = React.useContext(MenuContentCtx)
  if (!ctx) throw new Error("useMenuCtx debe usarse dentro de PosMainMenu")
  return ctx
}

// ── Logo del tenant ──────────────────────────────────────────────────────────

/**
 * Logo de la empresa (config.companyLogo del bootstrap). Fallback: inicial
 * del nombre de la empresa, o la marca Punto si tampoco hay companyName.
 * Reusado en el header del sidebar y en la card EMPRESA del panel derecho.
 */
function TenantLogo({ className }: { className?: string }) {
  const companyLogo = useCatalogStore((s) => s.config?.companyLogo)
  const companyName = useCatalogStore((s) => s.config?.companyName)
  const initial = companyName?.trim()?.[0]?.toUpperCase()

  return (
    <Avatar className={cn("rounded-md", className)}>
      {companyLogo ? <AvatarImage src={companyLogo} alt={companyName || "Logo"} className="object-contain" /> : null}
      <AvatarFallback className="rounded-md bg-transparent">
        {initial ? (
          <span className="text-sm font-semibold text-muted-foreground">{initial}</span>
        ) : (
          <PuntoLogo variant="mark" className="size-full" />
        )}
      </AvatarFallback>
    </Avatar>
  )
}

// ── Secciones ────────────────────────────────────────────────────────────────

// Las secciones con CustomContent no necesitan description/ctaLabel.
// Las secciones sin CustomContent usan el render default (descripción + CTA).
const SECTIONS: Omit<MenuSection, "disabled">[] = [
  {
    key: "drawer",
    label: "Control de Caja",
    icon: Calculator,
    CustomContent: ControlDeCajaPanel,
  },
  {
    key: "transactions",
    label: "Transacciones",
    icon: ReceiptText,
    CustomContent: TransactionsPreview,
  },
  {
    // Todo lo que esta caja emitió o cambió y todavía no llegó al servidor.
    // Vive acá y no en una banda del carrito: no requiere atención del cajero
    // (se sincroniza solo) y las bandas apiladas le comían alto a la lista de
    // ítems. El punto del trigger avisa que hay algo; el detalle está a un
    // toque.
    //
    // "Pendientes" y no "Ventas pendientes": desde que la cola incluye las
    // operaciones de caja y de configuración, acá adentro puede haber un
    // CIERRE DE CAJA rechazado. Nadie que busque su cierre lo va a buscar bajo
    // "Ventas".
    key: "sync-queue",
    label: "Pendientes",
    icon: CloudOff,
    CustomContent: SyncQueuePanel,
  },
  // Agenda, Órdenes y Módulos ocultos por ahora — se rehabilitan cuando
  // construyamos esas secciones reales (hoy son previews). 2026-06-28.
  // {
  //   key: "agenda",
  //   label: "Agenda",
  //   icon: CalendarDays,
  //   CustomContent: AgendaPreview,
  // },
  // {
  //   key: "orders",
  //   label: "Órdenes",
  //   icon: SquareKanban,
  //   CustomContent: OrdersPreview,
  // },
  {
    key: "printers",
    label: "Impresoras",
    icon: Printer,
    CustomContent: PrintersPanel,
  },
  // Módulos oculto por ahora — ver nota más arriba (Agenda/Órdenes).
  // {
  //   key: "modules",
  //   label: "Módulos",
  //   icon: Component,
  //   CustomContent: ModulesPanel,
  // },
  {
    key: "appearance",
    label: "Apariencia",
    icon: Palette,
    CustomContent: AppearancePanel,
  },
  {
    key: "settings",
    label: "Ajustes",
    icon: Settings,
    CustomContent: AjustesPanel,
  },
  {
    key: "edit-hotkeys",
    label: "HotKeys",
    icon: LayoutGrid,
    // Sin CustomContent ni description: el click ejecuta onSelect directo.
    onSelect: ({ setOpen, activeRegisterId, router }) => {
      if (!activeRegisterId) return
      setOpen(false)
      // El editor de hotkeys vive en /pos (ProductArea). El intent de "modo
      // edición" viaja en la URL, NO solo en el store: si la navegación
      // termina en hard reload por version-skew post-deploy (chunk/RSC del
      // build viejo), el store zustand muere pero el param sobrevive —
      // ProductArea lo consume y activa la edición igual. El setEditing
      // directo queda como fast-path para la navegación client-side normal.
      router.push("/pos?hotkeys=edit")
      useHotkeysStore.getState().setEditing(true)
    },
  },
]

// ── Componente principal ─────────────────────────────────────────────────────

export function PosMainMenu() {
  const router = useRouter()

  // Estado de apertura del modal — lo maneja el store global para que
  // el atajo Q (use-pos-hotkeys.ts) pueda abrirlo sin importar PosMainMenu.
  const open = usePosUIStore((s) => s.menuOpen)
  const setOpen = usePosUIStore((s) => s.setMenuOpen)

  // Sección activa en el sidebar. null = pantalla de bienvenida vacía.
  // Vive en el store (y no en estado local) porque es un destino de
  // navegación: el indicador de estado del carrito abre el menú directamente
  // en "Ventas pendientes" (`openMenuSection`), sin diálogo intermedio.
  const activeKey = usePosUIStore((s) => s.menuSection)
  const setActiveKey = usePosUIStore((s) => s.setMenuSection)

  // Stores de dominio para los handlers de secciones.
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const companyName = useCatalogStore((s) => s.config?.companyName)
  const setHotkeysEditing = useHotkeysStore((s) => s.setEditing)

  // Estado para el Dialog de transacciones
  const [transactionsOpen, setTransactionsOpen] = React.useState(false)

  // `setMenuOpen(false)` ya resetea la sección en el store — la próxima
  // apertura arranca en la bienvenida.
  const handleOpenChange = (v: boolean) => setOpen(v)

  // Handler para el CTA del item edit-hotkeys (único item sin CustomContent
  // que necesita lógica propia en lugar de navegación).
  const handleDefaultCta = (key: string) => {
    if (key === "edit-hotkeys") {
      if (!activeRegisterId) return
      setOpen(false)
      setHotkeysEditing(true)
    }
  }

  // Leer config para gatear la sección de caja según controlCaja.
  // Cola de sincronización: alimenta el punto del trigger (ver más abajo).
  const pendingCount = useOfflineSyncStore((st) => st.pendingCount)
  const failedCount  = useOfflineSyncStore((st) => st.failedCount)
  const { data: registerConfigData } = usePosRegisterConfig(activeRegisterId)
  const controlCaja = registerConfigData?.config?.controlCaja ?? true
  const modoSoloOrdenes = registerConfigData?.config?.modoSoloOrdenes ?? false

  const sectionsWithState: MenuSection[] = SECTIONS
    .filter((s) => s.key !== "drawer" || controlCaja)
    // Modo solo-órdenes (spec owner): el POS queda solo para órdenes y
    // mesas, se ocultan transacciones y caja del menú.
    .filter((s) => !modoSoloOrdenes || (s.key !== "drawer" && s.key !== "transactions"))
    .map((s) => ({
      ...s,
      disabled: false,
    }))

  const activeSection = sectionsWithState.find((s) => s.key === activeKey) ?? null

  return (
    <MenuContentCtx.Provider value={{ setOpen, router }}>
      {/* Trigger ≡ — se mantiene idéntico al original para no romper el cart-panel.
          El punto indica ventas en cola: antes eso era una banda propia arriba
          de la toolbar del carrito y, junto al aviso de sin conexión, apilaba
          dos franjas que empujaban los iconos y le comían alto a la lista de
          ítems. Una venta encolada no necesita la atención del cajero (se
          sincroniza sola), así que se degrada a señal pasiva acá; el detalle
          sigue estando a un toque, en Menú → Ventas pendientes. Rojo solo
          cuando hay ventas FALLIDAS, que sí son terminales y piden acción
          (context/08 §53). */}
      <Button
        variant="ghost"
        size="icon"
        className="relative size-11"
        aria-label={
          pendingCount > 0
            ? `Menú del POS — ${pendingCount} venta${pendingCount !== 1 ? "s" : ""} sin sincronizar`
            : "Menú del POS"
        }
        onClick={() => setOpen(true)}
      >
        <AppWindowMac className="size-5" />
        {pendingCount > 0 && (
          <span
            aria-hidden
            className={cn(
              "absolute top-1 right-1 size-2 rounded-full ring-2 ring-background",
              failedCount > 0 ? "bg-destructive" : "bg-amber-500",
            )}
          />
        )}
      </Button>

      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent
          // La X propia vive en el header del content area (ver más abajo) —
          // la del primitive es `absolute top-4 right-4` y con `p-0` quedaba
          // despegada de todo.
          showCloseButton={false}
          // Modal sidebar+content. Overrides:
          // - mobile fullscreen (el teclado virtual haría scroll en modal chico);
          // - desktop 64rem clamped (paritario con /settings y el detalle de
          //   cliente — el menú escala mejor con módulos y info del tenant).
          // - reset de gap/padding (el grid interno maneja su layout).
          mobileFullscreen
          className={cn(
            "gap-0 overflow-hidden p-0",
            // `max-sm:p-0` desactiva a propósito el padding que
            // `mobileFullscreen` pone por default (gutter + áreas seguras):
            // este modal monta un grid con chrome propio —nav arriba, header
            // con breadcrumb, barra de acción abajo— y ese padding dejaba una
            // franja del fondo del diálogo contra los bordes. Eso es lo que el
            // owner vio como "el modal del menú no baja hasta el final de la
            // pantalla" (2026-08-25): la barra inferior terminaba 34px antes
            // del borde y debajo asomaba el fondo del popover.
            //
            // A cambio, los insets se descuentan donde corresponde: `--safe-t`
            // en el nav (el elemento que apoya arriba, más abajo en este
            // archivo) y `--safe-b` en la barra de acción del final. El fondo
            // de cada uno llega al borde físico; lo que se corre es el
            // contenido. Ver `app/globals.css` § "Áreas seguras del
            // dispositivo".
            "max-sm:p-0",
            "sm:!max-w-[min(64rem,calc(100vw-2rem))] sm:!w-full",
          )}
        >
          {/* Header sr-only para a11y — el contenido visual es el grid */}
          <DialogHeader className="sr-only">
            <DialogTitle>Menú del POS</DialogTitle>
            <DialogDescription>
              Acciones y navegación de la caja: transacciones, agenda, ajustes y más.
            </DialogDescription>
          </DialogHeader>

          {/* Grid: sidebar nav (izquierda) + content area (derecha) */}
          {/* `max-sm:flex-1`: bajo `sm` el DialogContent es una columna flex a
              pantalla completa (ver `mobileFullscreen` en ui/dialog.tsx) y este
              grid es su único hijo — toma todo el alto que sobra. El `h-full`
              alcanza mientras el padre tenga alto definido; el `flex-1` lo deja
              explícito y sobrevive a que alguien meta otro hijo al lado. */}
          <div className="flex h-full min-h-0 w-full flex-col overflow-hidden max-sm:flex-1 sm:grid sm:h-[80vh] sm:grid-cols-[200px_1fr]">

            {/* Sidebar: vertical en desktop, horizontal scrolleable en mobile. */}
            <div className="flex shrink-0 flex-col sm:border-r">
              {/* Identidad del comercio — solo en desktop, arriba del listado de
                  secciones. Logo grande centrado + nombre debajo, SIN borde
                  inferior: el sidebar se lee como una sola columna continua
                  (pedido del owner 2026-08-02). */}
              <div className="hidden flex-col items-center gap-2 px-3 pt-6 pb-4 sm:flex">
                <TenantLogo className="size-16 shrink-0" />
                <span className="w-full truncate text-center text-sm font-semibold leading-tight">
                  {companyName || "Punto"}
                </span>
              </div>

              <nav
                aria-label="Secciones del menú del POS"
                // `pt` con `--safe-t`: en móvil este nav es el primer
                // elemento del modal fullscreen, o sea el que apoya en el
                // borde superior del teléfono. Su fondo sigue llegando hasta
                // el borde (queda debajo del status bar translúcido, que es lo
                // que hace que se vea como app); lo que baja es el contenido.
                // Desde `sm` arriba tiene el bloque de identidad del comercio
                // y vuelve al padding normal.
                className="flex shrink-0 gap-0.5 overflow-x-auto border-b bg-card p-2 pt-[calc(0.5rem+var(--safe-t))] sm:flex-col sm:border-b-0 sm:p-3"
              >
                {sectionsWithState.map(({ key, label, icon: Icon, onSelect, disabled }) => {
                const active = activeKey === key
                // Items con onSelect (ej. HotKeys) no muestran content area:
                // ejecutan la acción directo al click. Si están disabled
                // (sin caja activa), el click no hace nada y se muestran atenuados.
                const handleClick = () => {
                  if (key === "transactions") {
                    setOpen(false)
                    setTransactionsOpen(true)
                    return
                  }
                  if (onSelect) {
                    onSelect({ setOpen, activeRegisterId: activeRegisterId ?? "", router })
                  } else {
                    setActiveKey(key)
                  }
                }
                return (
                  <button
                    key={key}
                    type="button"
                    onClick={handleClick}
                    className={cn(
                      "flex shrink-0 items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm transition-colors sm:w-full",
                      active
                        ? "bg-accent font-medium text-accent-foreground"
                        : "text-muted-foreground hover:bg-accent/50 hover:text-foreground",
                      disabled && "cursor-not-allowed opacity-50",
                    )}
                    aria-current={active ? "page" : undefined}
                    aria-disabled={disabled ?? undefined}
                  >
                    <Icon className="size-4 shrink-0" />
                    <span>{label}</span>
                  </button>
                )
                })}
              </nav>
            </div>

            {/* Content area */}
            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">

              {/* Breadcrumb + cierre.
                  La X del primitive (`absolute top-4 right-4` sobre el
                  DialogContent) quedaba flotando en el aire: este modal resetea
                  `gap-0 p-0`, así que no hay padding propio con el que
                  alinearse. Por eso el DialogContent va con
                  `showCloseButton={false}` y la X vive ACÁ, en el mismo eje
                  vertical que "Menú del POS" y con el mismo padding que el resto
                  del header. El header ya no es desktop-only justamente para que
                  en mobile siga habiendo un único botón de cierre (antes el nav
                  reservaba `pr-12` para esquivar la X absolute). */}
              <header className="flex items-center justify-between gap-2 border-b py-3 pl-6 pr-3 text-sm">
                {/* "Menú del POS" con peso de título (pedido del owner
                    2026-08-08); la sección activa sigue como breadcrumb. */}
                <div className="flex min-w-0 items-center gap-2">
                  <span className="shrink-0 text-base font-semibold text-foreground">
                    Menú del POS
                  </span>
                  {activeSection && (
                    <>
                      <span className="text-muted-foreground/50">›</span>
                      <span className="truncate text-muted-foreground">
                        {activeSection.label}
                      </span>
                    </>
                  )}
                </div>
                <DialogClose asChild>
                  <Button variant="ghost" size="icon-sm" aria-label="Cerrar menú">
                    <X />
                  </Button>
                </DialogClose>
              </header>

              {/* Sin sección seleccionada → resumen de la cuenta logueada */}
              {/* Las tres ramas terminan contra el borde inferior del
                  teléfono, así que cada una descuenta `--safe-b` en SU último
                  elemento: las dos scrolleables en su contenedor, la de abajo
                  en la barra del CTA. No se puede poner una sola vez en el
                  content area: dejaría el fondo del diálogo asomando debajo de
                  la barra. */}
              {!activeSection ? (
                <div className="flex min-h-0 flex-1 flex-col overflow-hidden pb-[var(--safe-b)]">
                  <AccountOverview setActiveKey={setActiveKey} />
                </div>
              ) : activeSection.CustomContent ? (
                /* Sección con contenido custom — ocupa todo el content area.
                   min-h-0 es crítico para que el flex-child no expanda más allá
                   del contenedor y el panel interno pueda scrollear. */
                <div className="flex min-h-0 flex-1 flex-col overflow-hidden pb-[var(--safe-b)]">
                  <activeSection.CustomContent />
                </div>
              ) : (
                /* Sección default: descripción + CTA */
                <div className="flex h-full flex-col">
                  <div className="flex-1 overflow-y-auto px-6 py-6 sm:px-8">
                    <div className="max-w-xl space-y-4">
                      {/* Ícono + título */}
                      <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-lg bg-muted">
                          <activeSection.icon className="size-5 text-foreground" />
                        </span>
                        <h2 className="text-xl font-bold">{activeSection.label}</h2>
                      </div>
                      {/* Descripción */}
                      <p className="text-sm leading-relaxed text-muted-foreground">
                        {activeSection.description}
                      </p>
                    </div>
                  </div>

                  {/* Barra inferior con CTA — apoya en el borde inferior del
                      teléfono, así que descuenta `--safe-b` sumado a su propio
                      `py-4`. Su fondo llega hasta el borde: si el inset se
                      descontara un nivel más arriba quedaría una franja del
                      popover por debajo de la barra, que es exactamente lo que
                      se veía como "el modal no baja hasta el final". */}
                  <div className="border-t bg-background px-6 pt-4 pb-[calc(1rem+var(--safe-b))] sm:px-8">
                    <Button
                      onClick={() => handleDefaultCta(activeSection.key)}
                      disabled={activeSection.disabled}
                    >
                      {activeSection.ctaLabel}
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </div>
        </DialogContent>
      </Dialog>
      {/* Dialog de transacciones — fuera del Dialog del menú para no anidar modales */}
      <PosTransactionsDialog
        open={transactionsOpen}
        onOpenChange={setTransactionsOpen}
        onDismiss={() => setOpen(true)}
      />
    </MenuContentCtx.Provider>
  )
}

// ─────────────────────────────────────────────────────────────────────────────
// Componentes custom para el content area
// ─────────────────────────────────────────────────────────────────────────────

// ── Resumen de la cuenta (default landing del menú) ──────────────────────────

/** Series del bar chart "Ventas por hora" — escala verde monocromática de
 *  tokens (context/20 §Tokens): `--chart-1` para el dato protagonista (hoy, o
 *  el turno cuando se pinta la serie única), `--chart-3` para ayer, además
 *  atenuado por `fillOpacity`. Sin hex. */
const VENTAS_POR_HORA_CONFIG = {
  hoy: { label: "Hoy", color: "var(--chart-1)" },
  ayer: { label: "Ayer", color: "var(--chart-3)" },
  turno: { label: "Turno", color: "var(--chart-1)" },
} satisfies ChartConfig

/** Paleta ciclada del donut de métodos de pago — mismos tokens, sin hex. */
const CHART_SLICE_COLORS = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
  "var(--chart-5)",
] as const

type HourlyKey = "hoy" | "ayer" | "turno"

interface HourlyPoint {
  /** Hora del día 0-23 en TZ del tenant. */
  hour: number
  hoy: number
  ayer: number
  turno: number
}

/** Etiqueta corta para el eje Y — formatMoney entero es demasiado ancho acá. */
function compactAmount(v: number): string {
  const abs = Math.abs(v)
  if (abs >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (abs >= 1_000) return `${Math.round(v / 1_000)}k`
  return String(Math.round(v))
}

/** "2026-08-02 14:00" → 14. -1 si el string no matchea el formato del backend. */
function parseHour(raw: string): number {
  const h = Number(raw.slice(11, 13))
  return Number.isFinite(h) ? h : -1
}

/**
 * Une N series horarias en un solo dataset continuo, recortado al rango de
 * horas con datos (min..max de TODAS las series). Se rellenan las horas
 * intermedias sin ventas con 0 para que el eje X no salte.
 *
 * Un turno multi-día se colapsa a hora-del-día a propósito: la pregunta que
 * responde el chart es "a qué hora vendo", no "qué día".
 */
function buildHourlySeries(inputs: Array<{ rows: DrawerHourlyRow[]; key: HourlyKey }>): HourlyPoint[] {
  const map = new Map<number, HourlyPoint>()
  for (const { rows, key } of inputs) {
    for (const row of rows) {
      const h = parseHour(row.hour)
      if (h < 0) continue
      const point = map.get(h) ?? { hour: h, hoy: 0, ayer: 0, turno: 0 }
      point[key] += row.salesTotal
      map.set(h, point)
    }
  }
  if (map.size === 0) return []

  const hours = [...map.keys()]
  const min = Math.min(...hours)
  const max = Math.max(...hours)
  const out: HourlyPoint[] = []
  for (let h = min; h <= max; h++) {
    out.push(map.get(h) ?? { hour: h, hoy: 0, ayer: 0, turno: 0 })
  }
  return out
}

/**
 * Panel inicial del menú del POS — mini-dashboard del turno en curso. Se
 * renderiza cuando ninguna sección del sidebar está seleccionada.
 *
 * Reemplaza las cards EMPRESA/SUCURSAL/CAJA ACTIVA/PUNTO DE EXPEDICIÓN/PAÍS
 * (decisión owner 2026-08-02): esa info estática no le sirve al cajero en la
 * pantalla más vista del menú — el turno (ventas, total, clientes, ritmo) sí.
 * Sucursal + caja quedan como línea de contexto compacta arriba.
 *
 * TODO lo que se muestra es de SU caja y SU turno — nada company-wide: el
 * backend scopea por `registerId` del JWT del device y por la sesión de caja
 * abierta (ver DrawerService).
 *
 * Datos: `useDrawerStatus`/`useDrawerSummary` (resumen del turno) +
 * `useDrawerHourlyStats` (ventas por hora: turno / hoy / ayer — resource aparte,
 * no embebido en el summary). `salesCount`/`customersCount`/`salesTotal`/
 * `paymentBreakdown` son opcionales en DrawerSummary para tolerar un backend sin
 * deployar (el bloque que los usa se oculta solo).
 */
function AccountOverview({
  setActiveKey,
}: {
  setActiveKey: (key: string | null) => void
}) {
  const config = useCatalogStore((s) => s.config)
  const outlet = useCatalogStore((s) => s.outlet)
  const registers = useCatalogStore((s) => s.registers)
  const users = useCatalogStore((s) => s.users)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const activeRegister = registers.find((r) => r.id === activeRegisterId) ?? null

  // Operador activo: la MISMA fuente que usa el lock screen para saber quién
  // desbloqueó (lock-screen.tsx → setActiveUser tras validar el PIN). Es la
  // única identidad "quién está operando" que existe en el cliente: el user del
  // PosBootstrap es la identidad de PAREO del device (solo `{id, role}`, sin
  // nombre, y ni siquiera se guarda en el catalog store), así que rotularlo
  // "Cajero" sería mentir. Fallback: si nunca se bloqueó la caja en esta sesión
  // pero el tenant tiene un único operador, ese es inequívocamente el cajero.
  const activeUser = useLockStore((s) => s.activeUser)
  const cashierName = activeUser?.name ?? (users.length === 1 ? users[0].name : null)

  const { data: status, isLoading: statusLoading } = useDrawerStatus()
  const { data: summary, isLoading: summaryLoading } = useDrawerSummary()
  const isOpen = status?.isOpen ?? false
  const loading = statusLoading || summaryLoading

  // Control de caja a ciegas (flag panel-only de la caja): el cajero no ve
  // montos acumulados — ni tiles de plata ni charts. Se corta también el
  // fetch de las series horarias: no se pinta nada con ellas.
  const { data: registerConfigData } = usePosRegisterConfig(activeRegisterId)
  const blind = registerConfigData?.config?.blindControl ?? false

  const { data: hourly, isLoading: hourlyLoading } = useDrawerHourlyStats(isOpen && !blind)

  const salesCount = summary?.salesCount ?? 0
  const salesTotal = summary?.salesTotal ?? 0
  const customersCount = summary?.customersCount ?? 0
  const avgTicket = salesCount > 0 ? salesTotal / salesCount : 0

  const shift = React.useMemo(() => hourly?.shift ?? [], [hourly])
  const today = React.useMemo(() => hourly?.today ?? [], [hourly])
  const yesterday = React.useMemo(() => hourly?.yesterday ?? [], [hourly])

  /**
   * Qué pinta el chart:
   *   "day"   → hoy vs ayer (el caso normal: turno diario).
   *   "shift" → serie única del TURNO, cuando hoy y ayer calendario están
   *             vacíos pero el turno sí tiene ventas. Pasa con turnos que
   *             llevan días abiertos: las ventas del turno cayeron en días
   *             calendario que no son ni hoy ni ayer, y antes eso dejaba el
   *             card renderizado y sin una sola barra.
   *   "none"  → no hay nada que mostrar → hint.
   */
  const chartMode: "day" | "shift" | "none" =
    today.length > 0 || yesterday.length > 0 ? "day" : shift.length > 0 ? "shift" : "none"

  const series = React.useMemo(() => {
    if (chartMode === "day") {
      return buildHourlySeries([
        { rows: yesterday, key: "ayer" },
        { rows: today, key: "hoy" },
      ])
    }
    if (chartMode === "shift") {
      return buildHourlySeries([{ rows: shift, key: "turno" }])
    }
    return []
  }, [chartMode, today, yesterday, shift])

  /** Donut de métodos de pago. El backend ya excluye Caja Inicial /
   *  Extracciones / Ingresos — acá solo se descartan los montos en cero. */
  const paymentSlices = React.useMemo(
    () =>
      (summary?.paymentBreakdown ?? [])
        .filter((row) => row.amount > 0)
        .map((row, i) => ({
          name: row.name,
          amount: row.amount,
          fill: CHART_SLICE_COLORS[i % CHART_SLICE_COLORS.length],
        })),
    [summary?.paymentBreakdown],
  )

  /** Config sin `color`: los colores viajan en el `fill` de cada slice, así
   *  ChartStyle no emite custom properties con nombres de método de pago
   *  (tienen espacios/acentos y romperían el CSS). */
  const paymentChartConfig = React.useMemo<ChartConfig>(() => {
    const cfg: ChartConfig = { amount: { label: "Monto" } }
    for (const slice of paymentSlices) cfg[slice.name] = { label: slice.name }
    return cfg
  }, [paymentSlices])

  /** Top 5 productos del turno POR CANTIDAD — solo para el modo ciego (en el
   *  dashboard normal se quitó por espacio, poda 2026-08-08). Cantidades, no
   *  montos: es lo único que el modo ciego puede mostrar. */
  const topProductsByQty = React.useMemo(() => {
    const rows = [...(summary?.soldProducts ?? [])]
      .filter((p) => p.qty > 0)
      .sort((a, b) => b.qty - a.qty)
      .slice(0, 5)
    const max = rows.reduce((m, p) => Math.max(m, p.qty), 0)
    return rows.map((p) => ({ ...p, pct: max > 0 ? (p.qty / max) * 100 : 0 }))
  }, [summary?.soldProducts])


  return (
    /* Dashboard SIN scroll (pedido del owner 2026-08-02): todo tiene que
       entrar de un vistazo. `overflow-hidden` + `min-h-0` en la columna y en
       la fila de charts — los charts absorben el alto sobrante en vez de
       empujar contenido fuera de la pantalla. Cualquier bloque nuevo va
       DENTRO de una de las dos filas de tiles o de la fila de charts; no
       agregar filas de alto fijo. */
    <div className="flex h-full min-h-0 flex-col gap-3 overflow-hidden p-5 sm:p-6">
      {!loading && !isOpen ? (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 text-center">
          <p className="text-sm text-muted-foreground">Caja cerrada</p>
          <Button onClick={() => setActiveKey("drawer")}>Abrir caja</Button>
        </div>
      ) : (
        <>
          {/* Encabezado: h1 + una sola línea con cajero · sucursal · caja ·
              antigüedad del turno (antes eran dos líneas separadas). */}
          <div className="shrink-0">
            <h1 className="text-2xl font-semibold leading-tight">Turno en curso</h1>
            <p className="truncate text-sm text-muted-foreground">
              {cashierName ? `Cajero: ${cashierName}` : "Operador sin identificar"}
              {` · ${outlet?.name || "—"} · ${activeRegister?.name || "Sin caja"}`}
              {summary?.date ? ` · abierto ${formatRelativeShort(summary.date)}` : ""}
            </p>
          </div>

          {/* Fila ÚNICA de tiles — poda 2026-08-08 (pedido del owner: menos
              datos, sin desborde). Quedan los 4 números que el cajero mira
              durante el turno; vs-ayer / proyección / propinas / devoluciones
              se fueron a reportes del panel. Patrón StatTile (context/20
              §2026-07-31). En modo ciego: SOLO conteos, cero montos. */}
          {blind ? (
            <div className="grid shrink-0 grid-cols-2 gap-2 sm:max-w-md">
              <StatTile label="Ventas" value={salesCount} isLoading={loading} emphasis />
              <StatTile label="Clientes" value={customersCount} isLoading={loading} />
            </div>
          ) : (
            /* Template asimétrico: Ventas es un conteo corto (1fr); Promedio y
               Efectivo llevan montos en Gs. que superan el millón — 1.5fr para
               que ni el label ni "Gs. 1.234.567" trunquen. */
            <div className="grid shrink-0 grid-cols-2 gap-2 lg:grid-cols-[2fr_1fr_1.5fr_1.5fr]">
              <StatTile
                label="Total vendido"
                value={formatMoney(salesTotal, config)}
                isLoading={loading}
                emphasis
                className="col-span-2 lg:col-span-1"
              />
              <StatTile label="Ventas" value={salesCount} isLoading={loading} />
              <StatTile
                label="Promedio"
                value={formatMoney(avgTicket, config)}
                isLoading={loading}
              />
              {summary && (
                <StatTile label="Efectivo" value={formatMoney(summary.subtotal, config)} />
              )}
            </div>
          )}

          {/* Fila 2 — charts. `shrink-0` NO es decorativo: `<Card>` lleva
              `overflow-hidden`, y un flex item que es scroll container tiene
              min-height automático 0 (CSS flexbox §4.5). Dentro de esta columna
              `overflow-y-auto` la card del chart se aplastaba a cero y quedaba
              solo el título — el card "vacío" que reportó el owner. */}
          {blind ? (
            /* Modo ciego: nada de charts (todos grafican montos). Lo único
               agregado que se muestra es el top de productos POR CANTIDAD. */
            topProductsByQty.length > 0 ? (
              <Card variant="soft" size="sm" className="min-h-0 flex-1 lg:max-w-md">
                <CardHeader>
                  <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Más vendidos del turno
                  </CardTitle>
                </CardHeader>
                <CardContent className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto">
                  {topProductsByQty.map((product) => (
                    <div key={product.name} className="flex flex-col gap-1">
                      <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate">{product.name}</span>
                        <span className="shrink-0 font-medium tabular-nums">{product.qty} u.</span>
                      </div>
                      <div className="h-1.5 overflow-hidden rounded-full bg-foreground/5">
                        <div
                          className="h-full rounded-full bg-chart-1"
                          style={{ width: `${product.pct}%` }}
                        />
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : (
              <p className="shrink-0 text-sm text-muted-foreground">
                Todavía no hay ventas en este turno.
              </p>
            )
          ) : hourlyLoading ? (
            <Skeleton className="min-h-0 w-full flex-1 rounded-lg" />
          ) : chartMode === "none" && paymentSlices.length === 0 ? (
            <p className="shrink-0 text-sm text-muted-foreground">
              Todavía no hay ventas en este turno.
            </p>
          ) : (
            /* Fila de charts: absorbe TODO el alto sobrante (`flex-1 min-h-0`)
               en vez de tener alturas fijas — es lo que permite que el
               dashboard entre sin scroll en pantallas distintas. */
            <div className="grid min-h-0 flex-1 grid-cols-1 gap-3 lg:grid-cols-3">
              {chartMode !== "none" && (
                <Card variant="soft" size="sm" className="min-h-0 lg:col-span-2">
                  <CardHeader>
                    <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      {chartMode === "shift" ? "Ventas por hora del turno" : "Ventas por hora"}
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="min-h-0 flex-1">
                    <ChartContainer config={VENTAS_POR_HORA_CONFIG} className="h-full w-full">
                      <BarChart data={series} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
                        <CartesianGrid
                          strokeDasharray="3 3"
                          stroke="var(--border)"
                          vertical={false}
                        />
                        <XAxis
                          dataKey="hour"
                          tickFormatter={(h: number) => `${String(h).padStart(2, "0")}h`}
                          fontSize={10}
                          stroke="var(--muted-foreground)"
                          tickLine={false}
                          axisLine={false}
                        />
                        <YAxis
                          fontSize={10}
                          width={44}
                          stroke="var(--muted-foreground)"
                          tickLine={false}
                          axisLine={false}
                          tickFormatter={compactAmount}
                        />
                        <ChartTooltip
                          cursor={{ fill: "var(--accent)", opacity: 0.4 }}
                          content={
                            <ChartTooltipContent
                              labelFormatter={(label) => `${String(label).padStart(2, "0")}:00`}
                              formatter={(value, name) => (
                                <div className="flex w-full items-center justify-between gap-3">
                                  <span className="text-muted-foreground">
                                    {VENTAS_POR_HORA_CONFIG[
                                      name as keyof typeof VENTAS_POR_HORA_CONFIG
                                    ]?.label ?? name}
                                  </span>
                                  <span className="font-medium tabular-nums">
                                    {formatMoney(Number(value) || 0, config)}
                                  </span>
                                </div>
                              )}
                            />
                          }
                        />
                        <ChartLegend content={<ChartLegendContent />} />
                        {chartMode === "day" ? (
                          <>
                            {/* Ayer primero para que quede detrás visualmente en la
                                leyenda y hoy (chart-1) sea la barra que se lee primero. */}
                            <Bar
                              dataKey="ayer"
                              fill="var(--color-ayer)"
                              fillOpacity={0.45}
                              radius={[4, 4, 0, 0]}
                            />
                            <Bar dataKey="hoy" fill="var(--color-hoy)" radius={[4, 4, 0, 0]} />
                          </>
                        ) : (
                          <Bar dataKey="turno" fill="var(--color-turno)" radius={[4, 4, 0, 0]} />
                        )}
                      </BarChart>
                    </ChartContainer>
                  </CardContent>
                </Card>
              )}

              {/* Columna derecha: solo el donut de métodos de pago ("Más
                  vendidos" salió del dashboard en la poda 2026-08-08 — vive
                  en los reportes del panel). */}
              <div
                className={cn(
                  "flex min-h-0 flex-col gap-3",
                  chartMode === "none" && "lg:col-span-3",
                )}
              >
              {/* Donut de métodos de pago. Se oculta entero si el backend todavía
                  no expone `paymentBreakdown` (deploy escalonado). */}
              {paymentSlices.length > 0 && (
                <Card variant="soft" size="sm" className="min-h-0 flex-1">
                  <CardHeader>
                    <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Por método de pago
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="flex min-h-0 flex-1 flex-col gap-2">
                    {/* Solo la dona (pedido del owner 2026-08-18): se sacó la
                        lista de métodos con montos que vivía debajo. Lo que esa
                        lista aportaba —mapeo color→método y monto exacto— no se
                        pierde: la leyenda va DENTRO del propio chart (mismo
                        patrón `ChartLegend`/`ChartLegendContent` que "Ventas por
                        hora" arriba) y el monto exacto sigue en el tooltip al
                        tocar/pasar sobre una porción. Nada de esto agrega una
                        fila nueva fuera del chart: el legend entra en el alto
                        que ya ocupaba la lista, sin desplazar controles. */}
                    <ChartContainer
                      config={paymentChartConfig}
                      className="mx-auto aspect-square min-h-0 flex-1"
                    >
                      <PieChart>
                        <ChartTooltip
                          content={
                            <ChartTooltipContent
                              nameKey="name"
                              hideLabel
                              formatter={(value, name) => (
                                <div className="flex w-full items-center justify-between gap-3">
                                  <span className="text-muted-foreground">{name}</span>
                                  <span className="font-medium tabular-nums">
                                    {formatMoney(Number(value) || 0, config)}
                                  </span>
                                </div>
                              )}
                            />
                          }
                        />
                        {/* Radios en %, no en px: el dashboard no scrollea y
                            este card absorbe el alto sobrante, así que la caja
                            del chart se achica según el tenant. Con px fijos el
                            círculo terminaba más grande que su SVG y se
                            recortaba por los 4 lados — se veía octogonal.
                            Mismo patrón que contact-detail-view.tsx. */}
                        <Pie
                          data={paymentSlices}
                          dataKey="amount"
                          nameKey="name"
                          innerRadius="58%"
                          outerRadius="100%"
                          paddingAngle={2}
                          strokeWidth={0}
                        >
                          {paymentSlices.map((slice) => (
                            <Cell key={slice.name} fill={slice.fill} />
                          ))}
                        </Pie>
                        <ChartLegend content={<ChartLegendContent nameKey="name" />} />
                      </PieChart>
                    </ChartContainer>
                  </CardContent>
                </Card>
              )}

              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

// ── Control de Caja ──────────────────────────────────────────────────────────

/**
 * Construye el TicketData de cierre de caja a partir del summary del turno.
 *
 * Función pura — reutilizada por el botón "Imprimir" manual (mientras la caja
 * sigue abierta) y por el auto-print al confirmar el cierre (donde el summary
 * se pasa como snapshot tomado ANTES de invalidar la query, ver
 * handleCloseConfirm más abajo).
 *
 * FLAG: summary.list trae filas (name, amount) pero no hay campo separado de
 * "apertura" vs "ventas por método" — se mapean como payments usando las
 * filas de list tal como vienen del backend.
 */
function buildCloseRegTicket(
  summary: DrawerSummary,
  config: Pick<PosConfig, "companyName" | "currency" | "thousand" | "decimal"> | null,
): TicketData {
  const closingPayments = summary.list.map((row) => ({
    method: row.name,
    amount: row.amount,
  }))
  // items: reusa la tabla de productos del renderer genérico
  // (renderFallbackTicketHtml en print-in-browser.ts) — closeReg
  // no tiene concepto de plantilla propia, así que la tabla
  // Ítem/Cant./P.Unit/Total ya existente es el lugar correcto
  // para el resumen de productos vendidos del turno (devoluciones
  // ya vienen restadas desde el backend, ver DrawerService::getSoldProducts).
  const soldItems: TicketItem[] = summary.soldProducts.map((p) => ({
    name: p.name,
    qty: p.qty,
    unitPrice: p.qty !== 0 ? p.total / p.qty : p.total,
    discountAmount: 0,
    discountPercent: 0,
    total: p.total,
    categoryId: null,
    id: null,
    uid: null,
    note: null,
    tags: null,
    // Cierre de caja: resumen agregado del turno (DrawerService::getSoldProducts),
    // sin desglose de impuesto por producto — no hay de dónde sacarlo acá.
    taxId: null,
    taxRate: null,
    taxKind: null,
    taxIncluded: null,
    taxAmount: null,
    taxNet: null,
  }))
  return {
    companyName: config?.companyName ?? "",
    docType: "closeReg",
    transactionId: "",
    date: summary.date ?? new Date().toISOString(),
    items: soldItems,
    subtotal: summary.subtotal,
    discount: 0,
    taxTotal: 0,
    total: summary.total,
    payments: closingPayments,
    note: summary.tips > 0 ? `Propinas: ${summary.tips}` : undefined,
    currency: config?.currency ?? null,
    thousand: config?.thousand ?? null,
    decimal: config?.decimal ?? null,
  }
}

/**
 * Panel de control de caja en el menú del POS.
 *
 * Muestra el estado real del cajón (abierto/cerrado), el resumen del turno
 * (filas por método de pago, extracciones, ingresos, propinas, total) y
 * expone las acciones: abrir, cerrar, extraer, ingresar efectivo.
 *
 * Datos: hook use-drawer → BFF /api/pos/drawer → api/v1/drawer.php
 */
/**
 * Lo que Control de Caja muestra cuando la caja está operando sin servidor.
 *
 * Ocupa el lugar del resumen del turno. Sin conexión ese resumen no existe,
 * pero el total SÍ: es la suma de lo que este dispositivo registró, con los
 * huecos que no puede cubrir escritos al lado. Ver `local-shift-total.ts` para
 * el cálculo y para por qué la tenencia exclusiva de caja es lo que lo vuelve
 * defendible.
 *
 * Lo que este bloque no puede hacer nunca es parecerse al cierre. De ahí que
 * cada fila diga "según este dispositivo" en vez de "Total", que las
 * advertencias vayan pegadas al número y no escondidas abajo, y que el texto
 * final repita quién calcula el arqueo de verdad.
 */
function OfflineDrawerNotice({
  isOpen,
  openDate,
  blind,
}: {
  isOpen: boolean
  openDate: string | null
  blind: boolean
}) {
  const config = useCatalogStore((s) => s.config)
  const { data: totals } = useLocalShiftTotals(openDate)
  const [queued, setQueued] = React.useState<{ count: number; total: number } | null>(null)

  React.useEffect(() => {
    let alive = true
    async function load() {
      const rows = await peekAll()
      if (!alive) return
      setQueued({
        count: rows.length,
        total: rows.reduce(
          (sum, r) => sum + r.sale.payment.reduce((s, p) => s + (p.total ?? 0), 0),
          0,
        ),
      })
    }
    void load()
    return () => {
      alive = false
    }
  }, [])

  // Con la caja cerrada no hay turno del que hablar: el bloque queda en una
  // sola línea y las posiciones no se mueven cuando el turno se abra.
  if (!isOpen) {
    return (
      <div className="mx-auto max-w-sm space-y-3 text-center">
        <p className="text-sm text-muted-foreground">
          Sin conexión: podés abrir la caja igual. La apertura se envía sola al
          recuperar la conexión.
        </p>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-sm space-y-3">
      {openDate && (
        <p className="text-center text-sm font-medium capitalize text-muted-foreground">
          Abierta desde {formatDateTime(openDate)}
        </p>
      )}

      {/* Control de caja a ciegas: la regla no se cae con la red. El cálculo
          devuelve `null` con `blindControl` prendido (la decisión vive en
          `computeLocalShiftTotals`, no acá) y esta rama es lo que se ve. */}
      {blind || !totals ? (
        <p className="text-center text-sm text-muted-foreground">
          {blind
            ? "Control de caja a ciegas: contá el efectivo y cada medio de pago del turno, y declaralos al cerrar. El arqueo se ve desde el panel."
            : "Sin conexión: contá el efectivo y cada medio de pago del turno y declaralos al cerrar. El arqueo lo calcula el servidor cuando vuelva la conexión."}
        </p>
      ) : (
        <>
          <p className="text-center text-sm text-muted-foreground">
            Sin conexión. Esto es lo que registró este dispositivo en el turno.
          </p>

          <div className="divide-y divide-border">
            {!totals.gaps.includes("no-open-entry") && (
              <div className="flex items-center justify-between px-1 py-2.5 text-sm">
                <span className="text-muted-foreground">Caja Inicial</span>
                <span className="tabular-nums font-medium">
                  {formatMoney(totals.openAmount, config)}
                </span>
              </div>
            )}
            {totals.byMethod.map(({ name, amount }) => (
              <div
                key={name}
                className="flex items-center justify-between px-1 py-2.5 text-sm"
              >
                <span className="text-muted-foreground">{name}</span>
                <span className="tabular-nums font-medium">
                  {formatMoney(amount, config)}
                </span>
              </div>
            ))}
            {totals.cashOut > 0 && (
              <div className="flex items-center justify-between px-1 py-2.5 text-sm">
                <span className="text-muted-foreground">Extracciones (Efectivo)</span>
                <span className="tabular-nums font-medium">
                  {formatMoney(totals.cashOut, config)}
                </span>
              </div>
            )}
            {totals.cashIn > 0 && (
              <div className="flex items-center justify-between px-1 py-2.5 text-sm">
                <span className="text-muted-foreground">Ingresos (Efectivo)</span>
                <span className="tabular-nums font-medium">
                  {formatMoney(totals.cashIn, config)}
                </span>
              </div>
            )}
          </div>

          <div className="flex items-center justify-between rounded-lg bg-muted/30 px-3 py-2.5">
            <span className="text-sm font-bold uppercase">Efectivo en esta caja</span>
            <span className="tabular-nums font-semibold">
              {formatMoney(totals.cashTotal, config)}
            </span>
          </div>

          <div className="flex items-center justify-between rounded-lg bg-accent px-3 py-3">
            <span className="text-base font-bold uppercase">Registrado acá</span>
            <span className="text-2xl font-black tabular-nums">
              {formatMoney(totals.total, config)}
            </span>
          </div>

          <p className="text-xs font-medium text-muted-foreground">
            No es el cierre del turno: es lo que registró este dispositivo. El
            arqueo definitivo lo calcula el servidor con el monto contado, cuando
            el cierre se sincronice.
          </p>

          <ul className="space-y-1">
            {gapMessages(totals.gaps).map((msg) => (
              <li key={msg} className="text-xs text-muted-foreground">
                {msg}
              </li>
            ))}
          </ul>

          <p className="text-xs text-muted-foreground">
            {totals.salesCount} venta{totals.salesCount !== 1 ? "s" : ""} registrada
            {totals.salesCount !== 1 ? "s" : ""}
            {queued && queued.count > 0
              ? `, ${queued.count} sin enviar por ${formatMoney(queued.total, config)}.`
              : "."}
          </p>
        </>
      )}
    </div>
  )
}

/**
 * Cómo terminó el cierre que se hizo sin red.
 *
 * El cajero cerró mirando el total que este dispositivo había registrado, con
 * sus advertencias. Horas después la cola drenó y el servidor calculó el
 * arqueo real, con todo lo que el device no podía ver. Este bloque es la otra
 * mitad de esa conversación: los dos números, uno al lado del otro, y la
 * diferencia dicha con todas las letras.
 *
 * Se muestra coincidan o no, y se queda hasta que alguien lo descarta. Lo que
 * NO se muestra nunca es el arqueo de una caja con `blindControl`: ahí no hay
 * informe, porque el dueño decidió que este cajero no ve acumulados. Cuando NO coinciden se pinta en
 * destructivo: puede ser un faltante, o puede ser una extracción hecha desde
 * el panel que este aparato nunca vio, y las dos posibilidades necesitan que
 * alguien las mire.
 *
 * Vive al final del cuerpo scrolleable, arriba de la barra de acciones, que
 * está fija: no desplaza ningún botón.
 */
function ShiftCloseReportNotice({ blind }: { blind: boolean }) {
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const pendingOpsCount = useOfflineSyncStore((s) => s.pendingOpsCount)
  const [report, setReport] = React.useState<ShiftCloseReport | null>(null)

  React.useEffect(() => {
    let alive = true
    async function load() {
      const row = await readShiftCloseReport(activeRegisterId)
      if (alive) setReport(row)
    }
    void load()
    return () => {
      alive = false
    }
    // Se relee cuando la cola de operaciones se mueve: el informe nace justo
    // cuando el cierre sale de esa cola.
  }, [activeRegisterId, pendingOpsCount])

  // Defensa en profundidad: el informe ni siquiera se crea para un cierre a
  // ciegas (`reconcileAppliedOp` corta sin total local), pero si el dueño
  // prendió `blindControl` DESPUÉS de que quedara uno guardado, esta pantalla
  // no es quien se lo va a mostrar.
  if (!report || blind) return null

  const matches = closeTotalsMatch(report.local, report.server)
  const diff = report.diff

  return (
    <div
      className={cn(
        "mt-6 rounded-lg border p-3",
        matches ? "border-border" : "border-destructive/30 bg-destructive/10",
      )}
    >
      <p className={cn("text-sm font-medium", !matches && "text-destructive")}>
        {matches
          ? "El cierre sin conexión se registró en el servidor"
          : "El arqueo del servidor no coincide con lo que había registrado esta caja"}
      </p>
      <div className="mt-2 space-y-0.5">
        {report.local && (
          <div className="flex items-center justify-between text-xs">
            <span className="text-muted-foreground">Registrado en este dispositivo</span>
            <span className="tabular-nums">{formatMoney(report.local.total, config)}</span>
          </div>
        )}
        {report.server && (
          <div className="flex items-center justify-between text-xs">
            <span className="text-muted-foreground">Arqueo del servidor</span>
            <span className="tabular-nums">{formatMoney(report.server.total, config)}</span>
          </div>
        )}
        {diff !== null && diff !== 0 && (
          <div className="flex items-center justify-between text-xs font-medium">
            <span>Diferencia</span>
            <span className="tabular-nums">{formatMoney(diff, config)}</span>
          </div>
        )}
        <div className="flex items-center justify-between text-xs">
          <span className="text-muted-foreground">Efectivo contado al cerrar</span>
          <span className="tabular-nums">{formatMoney(report.counted, config)}</span>
        </div>
      </div>

      {/* Arqueo medio por medio, tal como quedó en el servidor (mig 169). Un
          informe guardado antes de ese deploy no lo trae y esta sección
          simplemente no aparece — no hay filas que inventar. */}
      {(report.server?.byMethod?.length ?? 0) > 0 && (
        <div className="mt-3">
          <p className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Arqueo por medio de pago
          </p>
          <div className="space-y-0.5">
            {report.server?.byMethod.map((r) => (
              <div key={r.key} className="flex items-center justify-between text-xs">
                <span className="text-muted-foreground">{r.name}</span>
                <span
                  className={cn(
                    "tabular-nums",
                    r.difference !== null && r.difference !== 0 && "font-medium text-destructive",
                  )}
                >
                  {r.counted === null ? "—" : formatMoney(r.counted, config)}
                  {r.difference !== null && r.difference !== 0
                    ? ` (${formatMoney(r.difference, config)})`
                    : ""}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
      {!matches && (
        <p className="mt-2 text-xs text-muted-foreground">
          La diferencia puede ser plata faltante o una operación que este
          dispositivo no vio (una extracción hecha desde el panel, ventas de
          antes de que tomara la caja). El detalle del turno está en el panel.
        </p>
      )}
      <Button
        size="sm"
        variant="outline"
        className="mt-2"
        onClick={() => {
          void clearShiftCloseReport(activeRegisterId).then(() => setReport(null))
        }}
      >
        Entendido
      </Button>
    </div>
  )
}

/**
 * Aviso de operaciones de caja que el servidor RECHAZÓ.
 *
 * Un cierre encolado que falla no puede desaparecer en silencio: es plata
 * contada. El indicador de la caja ya lo pinta en destructivo, pero este es el
 * lugar donde está parada la persona responsable del arqueo, así que también
 * se dice acá, con el detalle y la salida a mano.
 *
 * No desplaza nada: vive al final del cuerpo scrolleable del panel, arriba de
 * la barra de acciones, que está fija.
 */
/**
 * Qué le falta cerrar al cajero, con un camino para ir a resolverlo.
 *
 * Decirle "no podés cerrar" sin decirle QUÉ es lo que más frustra, así que la
 * lista es el contenido principal y no un detalle secundario. Los botones
 * navegan a las pantallas donde esas cosas se cierran (`/pos/ordenes`,
 * `/pos/espacios`) — la del POS, no la del panel.
 */
function ShiftCloseBlockersNotice({
  blockers,
  blocked,
  unverified,
}: {
  blockers: ShiftCloseBlockers
  blocked: boolean
  unverified: boolean
}) {
  const router = useRouter()
  const setMenuOpen = usePosUIStore((s) => s.setMenuOpen)

  function goTo(path: string) {
    // Cerrar el menú antes de navegar: la pantalla de órdenes/espacios está
    // DEBAJO de este overlay, no en una sección hermana del menú.
    setMenuOpen(false)
    router.push(path)
  }

  // Sin red: no se pudo verificar, pero el cierre procede igual. Es una
  // advertencia, no un impedimento — por eso no pinta destructivo y el botón
  // de arriba sigue habilitado.
  if (unverified) {
    return (
      <div className="mt-6 rounded-lg border bg-muted/50 p-3">
        <p className="text-sm font-medium">Órdenes y espacios sin verificar</p>
        <p className="mt-1 text-sm text-muted-foreground">
          Este comercio exige cerrar las órdenes y los espacios antes del turno, y sin
          conexión la caja no puede consultarlos. El cierre se va a encolar igual; si al
          sincronizar quedan abiertos, la operación va a quedar pendiente en esta misma
          pantalla para reintentarla.
        </p>
      </div>
    )
  }

  if (!blocked) return null

  return (
    <div className="mt-6 rounded-lg border border-destructive/30 bg-destructive/10 p-3">
      <p className="text-sm font-medium text-destructive">
        {shiftCloseBlockedSummary(blockers)}
      </p>
      <p className="mt-1 text-sm text-muted-foreground">
        Cobralas o cancelalas y el botón se habilita solo.
      </p>

      {blockers.spaces.length > 0 && (
        <ul className="mt-2 space-y-0.5">
          {blockers.spaces.map((s) => (
            <li key={s.id} className="text-sm text-muted-foreground">
              {blockerSpaceLabel(s)}
            </li>
          ))}
        </ul>
      )}
      {blockers.orders.length > 0 && (
        <ul className="mt-2 space-y-0.5">
          {blockers.orders.map((o) => (
            <li key={o.id} className="text-sm text-muted-foreground">
              {blockerOrderLabel(o)}
            </li>
          ))}
        </ul>
      )}
      {blockers.truncated && (
        <p className="mt-1 text-sm text-muted-foreground">
          Se listan las primeras; el total está arriba.
        </p>
      )}

      <div className="mt-2 flex gap-2">
        {blockers.orderCount > 0 && (
          <Button size="sm" variant="outline" onClick={() => goTo("/pos/ordenes")}>
            Ver órdenes
          </Button>
        )}
        {blockers.spaceCount > 0 && (
          <Button size="sm" variant="outline" onClick={() => goTo("/pos/espacios")}>
            Ver espacios
          </Button>
        )}
      </div>
    </div>
  )
}

function FailedDrawerOpsNotice() {
  const failedOpsCount = useOfflineSyncStore((s) => s.failedOpsCount)
  // "Revisar" NAVEGA a la sección de pendientes dentro de este mismo menú. No
  // abre un diálogo encima (el `SyncQueueDialog` fue eliminado 2026-08-23) ni
  // cierra el menú: la lista vive en una sección hermana, a un salto.
  const setMenuSection = usePosUIStore((s) => s.setMenuSection)
  const [drawerFailed, setDrawerFailed] = React.useState<PendingOpRow[]>([])

  React.useEffect(() => {
    let alive = true
    async function load() {
      const all = await peekAllOps()
      if (!alive) return
      setDrawerFailed(all.filter((o) => o.stream === "drawer" && o.status === "failed"))
    }
    void load()
    return () => {
      alive = false
    }
    // Se relee cuando cambia el contador global de fallidas — que es lo que
    // mueve el loop de sync.
  }, [failedOpsCount])

  if (drawerFailed.length === 0) return null

  return (
    <div className="mt-6 rounded-lg border border-destructive/30 bg-destructive/10 p-3">
      <p className="text-sm font-medium text-destructive">
        {drawerFailed.length === 1
          ? "Una operación de caja no se pudo registrar"
          : `${drawerFailed.length} operaciones de caja no se pudieron registrar`}
      </p>
      <ul className="mt-1 space-y-0.5">
        {drawerFailed.map((op) => (
          <li key={op.opId} className="text-xs text-muted-foreground">
            {op.label}
            {op.error ? ` — ${op.error.message}` : ""}
          </li>
        ))}
      </ul>
      <Button
        size="sm"
        variant="outline"
        className="mt-2"
        onClick={() => setMenuSection("sync-queue")}
      >
        Revisar
      </Button>
    </div>
  )
}

function ControlDeCajaPanel() {
  // En el POS usamos el config del catalog store (ya hidratado por useCatalogSeed).
  // useBootstrap no se necesita en el POS — el config viene del PosBootstrap.
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: status, isLoading: statusLoading } = useDrawerStatus()
  const { data: summary, isLoading: summaryLoading } = useDrawerSummary()
  // `fromCache` = el estado de la caja salió del device, no del servidor. Es
  // la señal DURA de que no hay servidor del otro lado — más confiable que
  // `navigator.onLine`, que dice `true` con un router sin salida.
  const offline = status?.fromCache ?? false
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined, { client: posApi })
  const allBindings = bindingsData?.bindings ?? []
  const { requestPrint, pickerDialog } = usePrintWithPicker()

  const openDrawer  = useOpenDrawer()
  const closeDrawer = useCloseDrawer()
  const expense     = useDrawerExpense()
  const income      = useDrawerIncome()

  // Control de caja a ciegas: el cajero arquea SIN ver lo esperado — se
  // ocultan el resumen del turno y los totales, y no se imprime el cierre
  // (el ticket lista todos los montos). El dueño ve los números reales en
  // los reportes del panel.
  const { data: registerConfigData } = usePosRegisterConfig(activeRegisterId)
  const blind = registerConfigData?.config?.blindControl ?? false

  // Gate de cierre: el comercio puede exigir que no queden órdenes ni espacios
  // abiertos en la SUCURSAL. El flag viaja en la config de la caja (cacheada
  // offline); la lista de lo que falta se consulta al servidor.
  //
  // Sin red no se consulta y NO se bloquea: órdenes y espacios no están en el
  // snapshot offline, así que no hay ni un dato viejo que mirar — y bloquear un
  // cierre con datos vencidos dejaría al cajero con la plata contada y sin poder
  // terminar el turno. Se avisa (`closeUnverified`) y el servidor valida cuando
  // la operación sincroniza. Ver `useShiftCloseBlockers`.
  const requireClosedOrders = registerConfigData?.config?.requireClosedOrders ?? false
  const isOpen = status?.isOpen ?? false
  const closeGate = useShiftCloseBlockers(requireClosedOrders && isOpen && !offline)
  const closeBlocked = closeGate.blocking
  const closeUnverified = requireClosedOrders && isOpen && (offline || closeGate.isError)

  // Estado local del modal de monto (abre/cierra con apertura/cierre/movimiento)
  type ModalMode = "open" | "close" | "expense" | "income" | null
  const [modalMode, setModalMode] = React.useState<ModalMode>(null)

  // Medios de pago a contar en el cierre. Del servidor cuando hay resumen, del
  // journal de este dispositivo cuando no — y el efectivo siempre.
  const { methods: countMethods, expected: expectedByMethod } = useShiftMethods(
    status?.openDate ?? null,
  )

  // Arqueo que devolvió el servidor al cerrar. Se muestra UNA vez, apenas se
  // cierra: la caja ya no tiene resumen que volver a pedir. Nunca a ciegas.
  const [closeReport, setCloseReport] = React.useState<ServerCloseMethodRow[] | null>(null)

  // `isOpen` se declara arriba, junto al gate de cierre que lo necesita.
  const loading = statusLoading || summaryLoading

  function openModal(mode: ModalMode) {
    setModalMode(mode)
  }

  async function handleOpenConfirm(amount: number) {
    const date = new Date().toISOString().replace("T", " ").slice(0, 19)
    try {
      await openDrawer.mutateAsync({ amount, date })
      setModalMode(null)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error desconocido")
    }
  }

  /**
   * Cierre con el conteo declarado medio por medio.
   *
   * `amount` sigue siendo EL EFECTIVO y no la suma de todo: es lo que se
   * compara contra el efectivo esperado del cajón (mig 164) y lo que alimenta
   * el semáforo de cuadre del panel. Mandar ahí el total contado de todos los
   * medios convertiría cada turno con tarjeta en un sobrante gigante — que es
   * el bug inverso al que la mig 164 vino a arreglar.
   */
  async function handleCloseConfirm(counted: CountedMethod[]) {
    const date = new Date().toISOString().replace("T", " ").slice(0, 19)
    // La fila del efectivo SIEMPRE existe (`useShiftMethods` la siembra
    // primero, con ventas o sin ellas). Si alguna vez no llegara, cerrar con
    // `amount: 0` declararía el cajón vacío y el arqueo saldría con un
    // faltante por el fondo inicial entero: mejor no cerrar y decirlo.
    const cashRow = counted.find((c) => c.isCash)
    if (!cashRow) {
      toast.error("No se pudo determinar el efectivo contado. Volvé a intentar el cierre.")
      return
    }
    const cash = cashRow.counted
    try {
      // Snapshot del summary ANTES de cerrar: al confirmar el cierre la
      // query de summary se invalida y los datos desaparecen — sin este
      // snapshot no habría forma de imprimir el reporte de cierre después.
      const summarySnapshot = summary
      const result = await closeDrawer.mutateAsync({ amount: cash, date, counted })
      setModalMode(null)

      // Arqueo del servidor. `null` cuando el cierre se encoló (sin red): ese
      // informe llega cuando la cola drene (`ShiftCloseReportNotice`).
      if (!blind) {
        const server = parseServerCloseTotals(result)
        if (server && server.byMethod.length > 0) setCloseReport(server.byMethod)
      }

      if (summarySnapshot && allBindings && !blind) {
        try {
          requestPrint("closeReg", buildCloseRegTicket(summarySnapshot, config), allBindings)
        } catch (printErr) {
          // Un fallo de impresora no debe afectar el cierre, que ya se confirmó.
          console.error("[closeReg auto-print]", printErr)
          toast.warning("La caja cerró pero no se pudo imprimir el cierre")
        }
      }
    } catch (err) {
      // El gate de órdenes/espacios rechazó el cierre (422). Pasa cuando algo
      // se abrió entre el último refetch y el intento, o cuando el POS tenía
      // la config vieja. Se cierra el modal de conteo y se refresca la lista:
      // el aviso de abajo aparece con el detalle y el botón queda
      // deshabilitado, que es donde el impedimento tiene que vivir. Dejar el
      // modal abierto con un toast encima invitaría a reintentar a ciegas.
      const blockers =
        err instanceof DrawerActionError ? err.shiftCloseBlockers() : null
      if (blockers) {
        setModalMode(null)
        void closeGate.refetch()
      }
      toast.error(err instanceof Error ? err.message : "Error desconocido")
    }
  }

  async function handleMovementConfirm(amount: number, note: string) {
    const date = new Date().toISOString().replace("T", " ").slice(0, 19)
    try {
      if (modalMode === "expense") await expense.mutateAsync({ amount, note, date })
      if (modalMode === "income")  await income.mutateAsync({ amount, note, date })
      setModalMode(null)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Error desconocido")
    }
  }

  const isPending =
    openDrawer.isPending || closeDrawer.isPending || expense.isPending || income.isPending

  // config es PosConfig | null — compatible con Pick<PosConfig, 'currency'|'thousand'|'decimal'>
  const fmtConfig = config

  const modalLabel: Record<NonNullable<ModalMode>, string> = {
    open:    "Abrir caja — monto inicial",
    close:   "Cerrar caja — conteo por medio de pago",
    expense: "Extracción de efectivo",
    income:  "Ingreso de efectivo",
  }


  // ── Modals de monto ──
  // La APERTURA sigue siendo un solo monto (el fondo del cajón) → NumericPadDialog.
  // El CIERRE pasó a declararse medio por medio → DrawerCountDialog.
  // Extracción/ingreso llevan nota → CashMovementDialog.
  const isOpenMode = modalMode === "open"
  const isMovementMode = modalMode === "expense" || modalMode === "income"
  const [draftSimple, setDraftSimple] = React.useState("0")

  // Reset draft al abrir la apertura
  React.useEffect(() => {
    if (isOpenMode) setDraftSimple("0")
  }, [isOpenMode])

  // ── Vista principal ────────────────────────────────────────────────────────
  return (
    <div className="flex h-full flex-col">
      <div className="flex-1 overflow-y-auto px-6 py-6">

        {/* Header: ícono + estado */}
        <div className="mb-1 flex flex-col items-center gap-1">
          <DoorOpen className={cn("size-16", isOpen ? "text-emerald-500" : "text-muted-foreground")} />
          <p className="text-xs uppercase tracking-wider text-muted-foreground">
            {loading ? "Cargando…" : isOpen ? "Caja abierta" : "Caja cerrada"}
          </p>
        </div>

        {/* Fecha de apertura */}
        {summary?.date && (
          <p className="mb-6 text-center text-sm font-medium capitalize text-muted-foreground">
            {formatDateTime(summary.date)}
          </p>
        )}

        {/* Sin conexión el turno se muestra con lo que ESTE dispositivo
            registró (`shift-journal.ts` + `local-shift-total.ts`), no con un
            cache del resumen del servidor.

            La primera versión de esto no mostraba ningún total, con el
            argumento de que el device no ve las ventas que llegaron al
            servidor por otro camino. Ese argumento suponía que otro aparato
            podía estar vendiendo en la misma caja — y la tenencia exclusiva
            (`register_lease`, mig 141/143, + el grant local con TTL) ya lo
            impide: mientras la caja es de este device, sus ventas son el
            turno. Los huecos que quedan son acotados, se detectan donde se
            puede y se escriben en la pantalla; el resto de la advertencia es
            permanente. Un total con la salvedad escrita le sirve más al cajero
            que un total mudo.

            Lo que no cambia: el arqueo definitivo lo calcula el servidor con
            el monto contado cuando el cierre sincroniza, y si difiere del que
            este aparato mostró, eso se avisa y queda escrito
            (`ShiftCloseReportNotice`). */}
        {offline && (
          <OfflineDrawerNotice
            isOpen={isOpen}
            openDate={status?.openDate ?? null}
            blind={blind}
          />
        )}

        {/* Modo ciego: el arqueo se hace sin ver lo esperado. Nada del
            resumen (montos por concepto, productos, totales) se renderiza. */}
        {summary && blind && (
          <p className="mx-auto max-w-sm text-center text-sm text-muted-foreground">
            Control de caja a ciegas: contá el efectivo y cada medio de pago que
            se usó en el turno, y declaralos al cerrar la caja. El arqueo se ve
            desde el panel.
          </p>
        )}

        {/* Resumen del turno */}
        {summary && !blind && (
          <>
            <div className="divide-y divide-border">
              {summary.list.map(({ name, amount }) => (
                <div
                  key={name}
                  className="flex items-center justify-between px-1 py-2.5 text-sm"
                >
                  <span className="text-muted-foreground">{name}</span>
                  <span className="tabular-nums font-medium">
                    {formatMoney(amount, fmtConfig)}
                  </span>
                </div>
              ))}
            </div>

            {summary.tips > 0 && (
              <div className="mt-2 flex items-center justify-between px-1 py-2 text-sm font-bold">
                <span className="uppercase">Propinas</span>
                <span className="tabular-nums">{formatMoney(summary.tips, fmtConfig)}</span>
              </div>
            )}

            {/* Productos vendidos en el turno (devoluciones ya restan) */}
            {summary.soldProducts.length > 0 && (
              <div className="mt-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Productos vendidos
                </p>
                <div className="divide-y divide-border">
                  {summary.soldProducts.map(({ name, qty, total }) => (
                    <div
                      key={name}
                      className="flex items-center justify-between px-1 py-2 text-sm"
                    >
                      <span className="text-muted-foreground">
                        {qty} × {name}
                      </span>
                      <span className="tabular-nums font-medium">
                        {formatMoney(total, fmtConfig)}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Total de efectivo */}
            <div className="mt-2 flex items-center justify-between rounded-lg bg-muted/30 px-3 py-2.5">
              <span className="text-sm font-bold uppercase">Total efectivo</span>
              <span className="tabular-nums font-semibold">
                {formatMoney(summary.subtotal, fmtConfig)}
              </span>
            </div>

            {/* Total general */}
            <div className="mt-2 flex items-center justify-between rounded-lg bg-accent px-3 py-3">
              <span className="text-base font-bold uppercase">Total</span>
              <span className="text-2xl font-black tabular-nums">
                {formatMoney(summary.total, fmtConfig)}
              </span>
            </div>
          </>
        )}

        {/* Caja cerrada: prompt para abrir. Sin conexión no se pinta porque
            `OfflineDrawerNotice` ya explica el estado y por qué. */}
        {!loading && !isOpen && !summary && !offline && (
          <div className="mt-8 flex flex-col items-center gap-3 text-center">
            <p className="text-sm text-muted-foreground">
              La caja está cerrada. Abrila para empezar a cobrar.
            </p>
          </div>
        )}

        <ShiftCloseReportNotice blind={blind} />
        <FailedDrawerOpsNotice />
        {/* Qué falta cerrar. Vive en el cuerpo scrolleable, arriba de la barra
            de acciones fija — no desplaza ningún control (context/14 §10),
            mismo lugar que los otros dos avisos de esta pantalla.
            El tooltip del botón cumple la convención del impedimento en el
            control; ESTE bloque es lo que lo hace usable en una tablet, donde
            un botón deshabilitado no tiene hover que revele nada. */}
        <ShiftCloseBlockersNotice
          blockers={closeGate.data}
          blocked={closeBlocked}
          unverified={closeUnverified}
        />
      </div>

      {/* Barra de acciones */}
      <div className="flex gap-2 border-t bg-background px-6 py-4">
        {isOpen ? (
          <>
            {/* El impedimento vive en el control de la acción, no en un toast
                después de intentar. El <span> es necesario: un botón
                `disabled` no emite eventos de puntero, así que sin un
                contenedor que sí los reciba el tooltip no se abriría nunca.
                `flex-1` se mudó del botón al span para que la geometría de la
                barra no cambie entre estados. */}
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="flex-1">
                  <Button
                    variant="destructive"
                    className="w-full"
                    disabled={closeBlocked}
                    onClick={() => openModal("close")}
                  >
                    Cerrar caja
                  </Button>
                </span>
              </TooltipTrigger>
              {closeBlocked && (
                <TooltipContent side="top" className="max-w-xs">
                  {shiftCloseBlockedSummary(closeGate.data)}
                </TooltipContent>
              )}
            </Tooltip>
            <Button variant="outline" size="sm" onClick={() => openModal("expense")}>
              <ArrowDown className="size-4" />
              Extraer
            </Button>
            <Button variant="outline" size="sm" onClick={() => openModal("income")}>
              <ArrowUp className="size-4" />
              Ingresar
            </Button>
            {/* El ticket de cierre lista todos los montos del turno — en modo
                ciego no hay botón de imprimir (ni auto-print al cerrar). Sin
                conexión tampoco: no hay resumen que imprimir, y un ticket de
                cierre con los montos en blanco es peor que no imprimirlo.
                Cuando el cierre sincronice, el reporte sale del panel. */}
            {!blind && !offline && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  if (!summary) {
                    toast.info("No hay datos de caja para imprimir")
                    return
                  }
                  requestPrint("closeReg", buildCloseRegTicket(summary, config), allBindings)
                }}
              >
                <Printer className="size-4" />
                Imprimir
              </Button>
            )}
          </>
        ) : (
          <Button className="flex-1" onClick={() => openModal("open")} disabled={loading}>
            Abrir caja
          </Button>
        )}
      </div>

      {/* NumericPadDialog para la apertura (un solo monto: el fondo del cajón) */}
      <NumericPadDialog
        open={isOpenMode}
        onClose={() => setModalMode(null)}
        title={isOpenMode ? modalLabel.open : ""}
        mode="money"
        value={draftSimple}
        onValueChange={setDraftSimple}
        onConfirm={() => { void handleOpenConfirm(Number(draftSimple)) }}
        confirmLabel="Confirmar"
      />

      {/* Cierre: se declara lo contado de CADA medio de pago del turno. A
          ciegas se cuenta igual, sin ver contra qué. */}
      <DrawerCountDialog
        open={modalMode === "close"}
        onClose={() => setModalMode(null)}
        methods={countMethods}
        // Defensa en profundidad: a ciegas el esperado NO se le pasa al
        // diálogo, además de que el diálogo no lo pintaría. Un dato que no
        // llega no se puede filtrar por un `blind` mal evaluado más adelante.
        expected={blind ? undefined : expectedByMethod}
        blind={blind}
        isPending={closeDrawer.isPending}
        config={fmtConfig}
        onConfirm={(counted) => { void handleCloseConfirm(counted) }}
      />

      {/* Arqueo del servidor, una sola vez, apenas cerró. */}
      <DrawerCloseReportDialog
        open={closeReport !== null}
        onClose={() => setCloseReport(null)}
        rows={closeReport ?? []}
        config={fmtConfig}
      />

      {/* CashMovementDialog para expense/income (con nota) */}
      {isMovementMode && (
        <CashMovementDialog
          open={isMovementMode}
          onClose={() => setModalMode(null)}
          mode={modalMode as "expense" | "income"}
          title={modalLabel[modalMode]}
          isPending={isPending}
          onConfirm={handleMovementConfirm}
        />
      )}

      {/* Picker de impresora (fallback cuando no hay binding para closeReg) */}
      {pickerDialog}
    </div>
  )
}

// ── Transacciones ────────────────────────────────────────────────────────────

/**
 * Ventas emitidas y operaciones de caja/configuración que todavía no llegaron
 * al servidor. La sección LISTA las dos colas — antes mostraba un párrafo con
 * el conteo y un botón que abría la lista en otro diálogo (`SyncQueueDialog`,
 * eliminado 2026-08-23): la sección ES el lugar donde se ven, no la antesala.
 */
function SyncQueuePanel() {
  return (
    <div className="p-6">
      <SyncQueueList />
    </div>
  )
}

function TransactionsPreview() {
  const { setOpen, router } = useMenuCtx()

  // Mock de transacciones recientes.
  // TODO (backend): GET /api/v1/pos/transactions?limit=5&registerId=active
  const transacciones = [
    {
      id: "3577",
      cliente: "Juan Pérez",
      metodo: "Efectivo",
      monto: "185.000",
      hora: "15:42",
    },
    {
      id: "3576",
      cliente: "María Gómez",
      metodo: "T. Débito",
      monto: "97.500",
      hora: "15:28",
    },
    {
      id: "3575",
      cliente: "Carlos Ruiz",
      metodo: "Transferencia",
      monto: "312.000",
      hora: "14:55",
    },
    {
      id: "3574",
      cliente: "Ana Torres",
      metodo: "T. Crédito",
      monto: "54.000",
      hora: "14:30",
    },
    {
      id: "3573",
      cliente: "Pedro Díaz",
      metodo: "Efectivo",
      monto: "228.500",
      hora: "13:58",
    },
  ]

  return (
    <div className="flex h-full flex-col">
      {/* Lista */}
      <div className="flex-1 overflow-y-auto px-6 py-6">
        <p className="mb-4 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
          Últimas transacciones
        </p>
        <div>
          {transacciones.map((t) => (
            <div
              key={t.id}
              className="flex items-center gap-3 border-b border-border px-1 py-2.5 text-sm"
            >
              {/* Avatar con inicial */}
              <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground">
                {t.cliente.charAt(0)}
              </div>
              {/* Info */}
              <div className="min-w-0 flex-1">
                <p className="font-medium">Venta #{t.id}</p>
                <p className="truncate text-xs text-muted-foreground">
                  Cliente: {t.cliente} · {t.metodo}
                </p>
              </div>
              {/* Monto + hora */}
              <div className="text-right">
                <p className="tabular-nums font-medium">{t.monto}</p>
                <p className="text-xs text-muted-foreground">{t.hora}</p>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* CTA */}
      <div className="border-t bg-background px-6 py-4">
        <Button
          onClick={() => {
            setOpen(false)
            router.push("/pos/transactions")
          }}
        >
          Ver todas las transacciones
        </Button>
      </div>
    </div>
  )
}

// ── Agenda ───────────────────────────────────────────────────────────────────

/** Próximas citas del día con CTA para abrir el calendario. */
function AgendaPreview() {
  const { setOpen, router } = useMenuCtx()

  // Mock de citas próximas.
  // TODO (backend): GET /api/v1/pos/agenda/upcoming?limit=4
  const citas = [
    { hora: "15:30", cliente: "Valentina López", servicio: "Corte y color" },
    { hora: "16:00", cliente: "Roberto Mena", servicio: "Barba" },
    { hora: "16:45", cliente: "Luciana Vera", servicio: "Mechitas y peinado" },
    { hora: "17:30", cliente: "Santiago Ríos", servicio: "Corte masculino" },
  ]

  return (
    <div className="flex h-full flex-col">
      {/* Lista */}
      <div className="flex-1 overflow-y-auto px-6 py-6">
        <p className="mb-4 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
          Próximas citas
        </p>
        <div>
          {citas.map((c) => (
            <div
              key={`${c.hora}-${c.cliente}`}
              className="flex items-center gap-3 border-b border-border px-1 py-3 text-sm"
            >
              {/* Chip hora */}
              <div className="flex h-8 w-12 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-medium tabular-nums">
                {c.hora}
              </div>
              {/* Info */}
              <div className="min-w-0 flex-1">
                <p className="font-medium">{c.cliente}</p>
                <p className="text-xs text-muted-foreground">{c.servicio}</p>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* CTA */}
      <div className="border-t bg-background px-6 py-4">
        <Button
          onClick={() => {
            setOpen(false)
            router.push("/pos/calendario")
          }}
        >
          Abrir agenda
        </Button>
      </div>
    </div>
  )
}

// ── Órdenes ──────────────────────────────────────────────────────────────────

/** Órdenes activas con CTA para el kanban completo. */
function OrdersPreview() {
  const { setOpen, router } = useMenuCtx()

  // Mock de órdenes activas.
  // TODO (backend): GET /api/v1/pos/orders?status=active&limit=4
  const ordenes: {
    numero: string
    tipo: string
    items: string
    estado: string
    estadoColor: string
    tiempoDesde: string
  }[] = [
    {
      numero: "101",
      tipo: "Mesa 4",
      items: "2× Pizza Margarita, 1× Coca",
      estado: "En cocina",
      estadoColor: "bg-amber-100 text-amber-700",
      tiempoDesde: "hace 8 min",
    },
    {
      numero: "102",
      tipo: "Delivery",
      items: "1× Hamburguesa clásica, 1× Papas",
      estado: "Lista",
      estadoColor: "bg-green-100 text-green-700",
      tiempoDesde: "hace 22 min",
    },
    {
      numero: "103",
      tipo: "Pickup",
      items: "3× Empanadas, 1× Limonada",
      estado: "En cocina",
      estadoColor: "bg-amber-100 text-amber-700",
      tiempoDesde: "hace 5 min",
    },
    {
      numero: "100",
      tipo: "Mesa 2",
      items: "2× Ensalada mixta, 2× Agua mineral",
      estado: "Entregada",
      estadoColor: "bg-muted text-muted-foreground",
      tiempoDesde: "hace 35 min",
    },
  ]

  return (
    <div className="flex h-full flex-col">
      {/* Lista */}
      <div className="flex-1 overflow-y-auto px-6 py-6">
        <p className="mb-4 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
          Órdenes activas
        </p>
        <div className="space-y-2">
          {ordenes.map((o) => (
            <div
              key={o.numero}
              className="flex items-start gap-3 rounded-lg border border-border px-3 py-3 text-sm"
            >
              {/* Número */}
              <div className="shrink-0 font-mono text-xs font-bold text-muted-foreground">
                #{o.numero}
              </div>
              {/* Info */}
              <div className="min-w-0 flex-1">
                <p className="font-medium">{o.tipo}</p>
                <p className="truncate text-xs text-muted-foreground">{o.items}</p>
              </div>
              {/* Estado + tiempo */}
              <div className="flex shrink-0 flex-col items-end gap-1">
                <span
                  className={cn(
                    "rounded-full px-2 py-0.5 text-xs font-medium",
                    o.estadoColor,
                  )}
                >
                  {o.estado}
                </span>
                <span className="text-xs text-muted-foreground">{o.tiempoDesde}</span>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* CTA */}
      <div className="border-t bg-background px-6 py-4">
        <Button
          onClick={() => {
            setOpen(false)
            router.push("/pos/ordenes")
          }}
        >
          Ver todas las órdenes
        </Button>
      </div>
    </div>
  )
}

// ── Impresoras ────────────────────────────────────────────────────────────────

function PrintersPanel() {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const activeOutletId = useCatalogStore((s) => s.outlet?.id)
  if (!activeRegisterId) {
    return (
      <div className="p-6 text-sm text-muted-foreground">
        Elegí una caja activa primero.
      </div>
    )
  }
  return (
    <div className="h-full overflow-y-auto px-6 py-6">
      {/* `client={posApi}`: acá el que opera es el DEVICE, no el panel. Con el
          cliente del panel las escrituras salían con la cookie `_jwt_panel` y
          el scope equivocado, y sin red no salían en absoluto. Con el cliente
          del device, además, los cambios de impresora se encolan offline.
          `outletId` del snapshot, para no depender del listado de cajas del
          panel — que en un device no responde. */}
      <PrintersManager
        registerId={activeRegisterId}
        outletId={activeOutletId}
        client={posApi}
      />
    </div>
  )
}

// ── Apariencia ────────────────────────────────────────────────────────────────

/** Selector de tema (light/dark/system) — reusa ThemePicker del panel para
    consistencia de UX entre POS y /settings. */
function AppearancePanel() {
  return (
    <div className="h-full overflow-y-auto px-6 py-6">
      <div>
        <p className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Apariencia
        </p>
        <ThemePicker />
      </div>
    </div>
  )
}

// ── Módulos ──────────────────────────────────────────────────────────────────

/** Toggles para habilitar módulos del POS. */
function ModulesPanel() {
  // TODO (backend): cargar/guardar desde la config del tenant (GET/PATCH /api/v1/pos/modules)
  const [estados, setEstados] = React.useState({
    agenda: true,
    espacios: false,
    ordenes: false,
    alertaSolapamiento: true,
  })

  const modulos: {
    key: keyof typeof estados
    icono: LucideIcon
    nombre: string
    descripcion: string
  }[] = [
    {
      key: "agenda",
      icono: CalendarDays,
      nombre: "Calendario / Agenda",
      descripcion: "Reservas, citas, turnos para servicios",
    },
    {
      key: "espacios",
      icono: LayoutGrid,
      nombre: "Espacios",
      descripcion: "Gestión de mesas, sillas de atención u otros espacios",
    },
    {
      key: "ordenes",
      icono: SquareKanban,
      nombre: "Órdenes",
      descripcion: "Cocina, deliveries y pickup como kanban",
    },
    {
      key: "alertaSolapamiento",
      icono: Bell,
      nombre: "Alertar superposición de citas",
      descripcion: "Avisa si hay dos turnos solapados",
    },
  ]

  return (
    <div className="flex h-full flex-col">
      <div className="flex-1 overflow-y-auto px-6 py-6">
        <p className="mb-4 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
          Módulos del POS
        </p>
        <div>
          {modulos.map(({ key, icono: Icono, nombre, descripcion }, idx) => (
            <div
              key={key}
              className={cn(
                "flex items-center justify-between gap-3 px-1 py-3",
                idx < modulos.length - 1 && "border-b border-border",
              )}
            >
              <div className="flex items-center gap-3">
                <Icono className="size-5 shrink-0 text-muted-foreground" />
                <div>
                  <p className="text-sm font-medium">{nombre}</p>
                  <p className="text-xs text-muted-foreground">{descripcion}</p>
                </div>
              </div>
              <Switch
                checked={estados[key]}
                onCheckedChange={(val) =>
                  setEstados((prev) => ({ ...prev, [key]: val }))
                }
              />
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

// ── Ajustes ──────────────────────────────────────────────────────────────────

const AJUSTES_TOGGLES: { key: keyof PosRegisterConfig; label: string; description?: string }[] = [
  { key: "mergeRepeated", label: "Agrupar productos repetidos", description: "Sumar cantidad al tocar el mismo producto seguido. Si tocás otro entre medio, se crea una línea nueva — útil para promos con descuento por línea." },
  { key: "showSoftKeyboard", label: "Mostrar teclado virtual en numpads", description: "Útil para pantallas táctiles sin teclado físico." },
  { key: "controlCaja", label: "Control de Caja", description: "Apertura y cierre de turnos, extracciones e ingresos de efectivo. Al desactivarlo, la sección Control de Caja desaparece del menú." },
  { key: "ordenEnVenta", label: "Orden en venta", description: "Al confirmar una venta, muestra el botón \"Ordenar\" para generar una orden de ese pedido ya facturado (cobrar primero, ordenar después)." },
  { key: "ordenAImpresion", label: "Orden a impresión", description: "Al enviar una orden, imprime las comandas en las impresoras vinculadas — locales o del servidor de impresión de la sucursal." },
  { key: "permitirGuardarVentas", label: "Permitir guardar ventas", description: "Habilita la opción \"Guardar\" para dejar ventas en curso y retomarlas después. Desactivalo si no querés que los cajeros guarden ventas." },
  { key: "modoSoloOrdenes", label: "Modo: solo órdenes", description: "El POS queda solo para tomar órdenes y mesas: se ocultan facturación, transacciones y caja." },
]
// Sacados de la lista por decisión del owner (2026-07-29): "Inhabilitar
// animaciones", "Teclado virtual", "Servidor de impresión", "Sonidos en
// alertas" y "Ocultar detalle de combos en impresión". Ninguno tenía consumidor
// —solo existían como campo en PosRegisterConfig—, así que eran interruptores
// que no hacían nada. Las keys quedan en el tipo con default false para no
// romper los devices que ya las tengan guardadas; si alguno se implementa de
// verdad, vuelve a esta lista.

/**
 * Fila "etiqueta + control" de Ajustes — la ÚNICA forma de armar una.
 *
 * La etiqueta a la izquierda en una columna fija de 192px funciona en el
 * desktop del menú (que va a 64rem), pero en un teléfono de 390pt deja ~130px
 * para el control: los selectores de sucursal y caja quedaban con el nombre
 * cortado y el input de IP no entraba (reporte del owner 2026-08-25, "el
 * panel de ajustes se aplasta"). Bajo `sm` la fila apila —etiqueta arriba,
 * control a ancho completo— y de `sm` para arriba vuelve exactamente a la
 * geometría de siempre.
 *
 * Va en un componente y no repetida en cada fila porque eran cinco filas con
 * la misma cadena de clases en dos componentes distintos: la próxima fila que
 * alguien agregue nace responsive sin acordarse de esto.
 */
function SettingRow({
  label,
  htmlFor,
  align = "center",
  children,
}: {
  /** Sin `label` la fila solo reserva la columna en desktop (texto de ayuda). */
  label?: string
  htmlFor?: string
  /** `start` para controles de varias líneas (un párrafo de ayuda). */
  align?: "center" | "start"
  children: React.ReactNode
}) {
  return (
    <div
      className={cn(
        "flex flex-col gap-1.5 sm:flex-row sm:gap-3",
        align === "center" ? "sm:items-center" : "sm:items-start",
      )}
    >
      {label ? (
        <Label
          htmlFor={htmlFor}
          className="font-normal text-muted-foreground sm:w-48 sm:shrink-0"
        >
          {label}
        </Label>
      ) : (
        // En móvil no hay columna que reservar: la fila apila.
        <div className="hidden sm:block sm:w-48 sm:shrink-0" aria-hidden />
      )}
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  )
}

/**
 * Selectores de sucursal+caja del device actual.
 *
 * El admin pre-elige estos valores al generar el link de invitación, pero el
 * cajero puede moverse entre cajas del tenant desde Ajustes sin pedir un link
 * nuevo. UPDATE en la fila device → invalidate del bootstrap → catalog store
 * se re-hidrata con el contexto nuevo.
 *
 * Sin conexión (bug reportado 2026-08-23)
 * ───────────────────────────────────────
 * Las dos listas venían de la red (`usePosOutlets` / `usePosRegisters`) y sin
 * red quedaban vacías. Un `<Select>` cuyo `value` no matchea ningún `<SelectItem>`
 * pinta el placeholder, así que el cajero veía "Sin seleccionar" en la
 * sucursal y la caja donde está parado y facturando. La caja no estaba
 * desconfigurada: la pantalla no tenía con qué dibujarla.
 *
 * El arreglo tiene dos mitades y las dos importan:
 *
 * 1. **Mostrar el valor real.** La sucursal y la caja activas están en el
 *    snapshot del bootstrap (`catalog store`), que es justamente lo que
 *    sobrevive sin red — con eso alcanza para dibujar la opción actual aunque
 *    el listado completo del tenant no se pueda pedir.
 * 2. **Deshabilitar con el motivo a la vista.** Cambiar de sucursal o de caja
 *    sin conexión no se puede y no es un capricho: son las dimensiones que
 *    definen la numeración fiscal y la exclusividad de la caja
 *    (`context/29`), y el bootstrap offline solo conoce la sucursal actual,
 *    así que ni siquiera hay a dónde mudarse. Un control deshabilitado con el
 *    motivo escrito es preferible a uno que parece disponible y falla raro.
 *
 * La sucursal arrastra la caja (2026-08-24)
 * ─────────────────────────────────────────
 * Elegir sucursal preselecciona su PRIMERA caja y confirma el cambio de una.
 * Antes la sucursal quedaba "pendiente" y el cajero tenía que abrir el segundo
 * selector para que pasara algo; peor, entre un paso y el otro la pantalla
 * mostraba una sucursal que el device todavía no tenía. La terna
 * `companyId + outletId + registerId` no admite estados intermedios: no puede
 * quedar seleccionada una caja de la sucursal A con la sucursal B activa.
 *
 * Esto NO contradice la regla de no inventar dimensiones faltantes: el POS
 * sigue siendo fail-closed cuando el contexto llega incompleto desde el
 * backend. Acá el cajero pidió explícitamente moverse, y la preselección es
 * visible en el selector —puede cambiarla acto seguido—, no una dimensión
 * resuelta en silencio con "la primera que haya". Si la sucursal elegida no
 * tiene ninguna caja no se inventa nada: el cambio no se comitea y el
 * impedimento queda escrito al lado del control.
 */
function DeviceContextSelectors() {
  const activeOutlet = useCatalogStore((s) => s.outlet)
  const activeOutletId = activeOutlet?.id ?? ""
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const snapshotRegisters = useCatalogStore((s) => s.registers)
  const isOnline = useOnlineStatus()

  const { data: outletsData } = usePosOutlets()
  const { data: registersData } = usePosRegisters()
  const updateContext = useUpdateDeviceContext()

  const [pendingOutletId, setPendingOutletId] = React.useState<string>("")

  // Caja a la que se está mudando: la preselección de la sucursal nueva, o la
  // que el cajero eligió a mano.
  const [pendingRegisterId, setPendingRegisterId] = React.useState<string>("")

  // El destino se considera ALCANZADO cuando el catalog store ya lo refleja, y
  // ahí los selectores vuelven a leer del store.
  //
  // Esto se DERIVA en vez de limpiarse en el `onSuccess` de la mutación: el
  // servidor confirma el cambio bastante antes de que resuelva el refetch del
  // bootstrap, que es pesado. Limpiando en el onSuccess, los selectores
  // volvían a mostrar la sucursal VIEJA —con el carrito ya vacío— hasta que
  // llegaba el bootstrap nuevo. Derivado no hay ventana: el destino se muestra
  // hasta que el store lo confirma, y recién ahí los dos coinciden. Tampoco
  // hace falta un `setState` dentro de un efecto.
  const stagedOutletId = pendingOutletId === activeOutletId ? "" : pendingOutletId
  const stagedRegisterId =
    pendingRegisterId === activeRegisterId ? "" : pendingRegisterId

  const effectiveOutletId = stagedOutletId || activeOutletId

  // Listado del tenant si se pudo pedir; si no, al menos la sucursal actual
  // sacada del snapshot, para que el selector tenga la opción que va a mostrar.
  const outletsFromServer = outletsData?.rows ?? []
  const outlets: { id: string; name: string }[] =
    outletsFromServer.length > 0
      ? outletsFromServer
      : activeOutlet
        ? [{ id: activeOutlet.id, name: activeOutlet.name }]
        : []

  // `?? []` sin memo daría un array nuevo por render y `registersOf` se
  // recrearía siempre.
  const registersFromServer = React.useMemo(
    () => registersData?.registers ?? [],
    [registersData],
  )

  // Cajas de UNA sucursal, en el mismo orden en que se pintan en el selector —
  // así "la primera caja" que se preselecciona es literalmente la primera
  // opción de la lista, y no un criterio distinto del que ve el cajero.
  const registersOf = React.useCallback(
    (outletId: string): { id: string; name: string }[] =>
      registersFromServer.length > 0
        ? registersFromServer.filter((r) => r.outletId === outletId && r.status)
        : snapshotRegisters.filter((r) => r.outletId === outletId),
    [registersFromServer, snapshotRegisters],
  )

  const registersOfOutlet = registersOf(effectiveOutletId)

  // Una sucursal SIN cajas y una con todas las cajas DESACTIVADAS son dos
  // problemas distintos y se arreglan distinto (crear una caja vs. reactivar
  // la que hay). Decir "no tiene cajas" cuando tiene una apagada manda al
  // encargado a crear una caja de más.
  const anyRegisterOfOutlet = registersFromServer.some(
    (r) => r.outletId === effectiveOutletId,
  )

  // Con las listas del snapshot no se puede ofrecer un cambio: el bootstrap
  // trae la sucursal actual y sus cajas, no el mapa del tenant. Y aunque lo
  // trajera, mover la caja es una escritura contra el servidor.
  const contextLocked = !isOnline || outletsFromServer.length === 0

  // Cambio decidido que espera el OK del cajero porque hay una venta cargada.
  // null = no hay nada esperando confirmación.
  const [pendingSwitch, setPendingSwitch] =
    React.useState<{ registerId: string; outletId?: string } | null>(null)

  // Distingue "el diálogo se cerró porque el cajero CONFIRMÓ" de "se cerró
  // porque canceló o apretó Escape". Confirmar también dispara
  // `onOpenChange(false)`, y sin esta marca el cancel correría encima del
  // commit y devolvería los selectores a la caja vieja justo mientras el
  // cambio viaja al servidor.
  const committingRef = React.useRef(false)

  const cartLineCount = useCartStore((s) => s.lines.length)

  /**
   * Puerta única de todo cambio de contexto.
   *
   * Si hay una venta cargada, pregunta antes de tirarla; si no, comitea
   * derecho. Preguntar SIEMPRE sería un click de peaje en el caso normal
   * (carrito vacío, que es la mayoría), y el POS se opera con el dedo.
   *
   * Se usa `AlertDialog` y no `useUnsavedChangesGuard`: ese hook es para
   * NAVEGACIÓN (intercepta clicks en `<a>`/`<Link>` y `beforeunload`) y
   * pregunta con el `window.confirm()` nativo. Acá no se navega a ningún lado
   * —se descarta estado de un store estando en la misma pantalla—, el POS es
   * touch y el confirm nativo no se puede tocar con el dedo cómodamente, y
   * además ese hook latchea su bypass tras la primera confirmación: un segundo
   * cambio de caja en la misma sesión sucia no volvería a preguntar.
   */
  function requestSwitch(payload: { registerId: string; outletId?: string }) {
    setPendingRegisterId(payload.registerId)
    if (hasContextScopedWork()) {
      setPendingSwitch(payload)
      return
    }
    void commitSwitch(payload)
  }

  /** Cancelar la confirmación devuelve los dos selectores a lo que está activo. */
  function cancelSwitch() {
    setPendingSwitch(null)
    setPendingOutletId("")
    setPendingRegisterId("")
  }

  async function commitSwitch(payload: { registerId: string; outletId?: string }) {
    setPendingSwitch(null)

    // Antes de mudar el device, vaciar lo que quedó pendiente de la caja
    // ACTUAL. Las operaciones en cola están selladas con su `registerId` y el
    // motor no las aplica sobre otra caja (el cerco de `pending-ops-sync`), así
    // que mudarse con la cola llena no corrompe nada — pero deja al cajero con
    // operaciones que solo se pueden enviar volviendo a la caja anterior, y eso
    // hay que decirlo en el momento, no descubrirlo después.
    //
    // No se BLOQUEA el cambio: §58 pide que las reglas que traban sean
    // opcionales, y acá no hace falta trabar nada — el cerco ya garantiza la
    // integridad y el aviso cubre la sorpresa.
    try {
      if (typeof navigator === "undefined" || navigator.onLine) {
        await syncPendingOps({ send: sendPendingOp, activeRegisterId })
      }
      const left = await getOpsCount()
      if (left > 0) {
        toast.warning(
          `Quedan ${left} cambio${left !== 1 ? "s" : ""} sin enviar de esta caja. Se van a poder enviar solo desde ella.`,
        )
      }
    } catch {
      // El vaciado es una cortesía; que falle no puede impedir el cambio de
      // caja, que es una operación del servidor y no depende de la cola.
    }

    updateContext.mutate(payload, {
      // El descarte del carrito y del resto del estado de contexto lo hace
      // `useUpdateDeviceContext` en su onSuccess (ver `lib/pos/context-reset`),
      // no este componente: así vale para cualquier call-site que mueva la caja.
      //
      // El éxito NO limpia `pendingOutletId`/`pendingRegisterId`: los
      // selectores tienen que seguir mostrando el destino hasta que el
      // bootstrap re-hidrate, y de eso se encarga la derivación de
      // `stagedOutletId`/`stagedRegisterId`. Solo el error los revierte.
      onSuccess: () => toast.success("Contexto actualizado"),
      onError: (e) => {
        toast.error(e.message)
        setPendingOutletId("")
        setPendingRegisterId("")
      },
    })
  }

  function handleOutletChange(newOutletId: string) {
    if (newOutletId === activeOutletId) {
      setPendingOutletId("")
      return
    }

    // La sucursal ARRASTRA la caja: se muestra la sucursal elegida y se
    // preselecciona su primera caja en el mismo gesto. La caja anterior se
    // suelta acá mismo para que en ningún frame se vea una caja de la sucursal
    // vieja debajo de la sucursal nueva.
    setPendingOutletId(newOutletId)
    setPendingRegisterId("")

    const target = registersOf(newOutletId)[0]
    if (!target) {
      // Caso borde: sucursal sin cajas. No se inventa nada y no se comitea —
      // el device se quedaría sin `registerId`, que es exactamente el estado en
      // el que el POS no opera.
      //
      // La sucursal elegida queda a la vista a propósito, sin volver sola a la
      // anterior: así el selector de Caja se puede mostrar deshabilitado CON el
      // motivo al lado, que es como el POS comunica un impedimento (no con una
      // banda). Elegir otra sucursal —o volver a la activa— sale del estado.
      toast.error(
        registersFromServer.some((r) => r.outletId === newOutletId)
          ? "Esa sucursal no tiene ninguna caja activa. No se puede mover el device ahí."
          : "Esa sucursal no tiene cajas. No se puede mover el device ahí.",
      )
      return
    }

    requestSwitch({ registerId: target.id, outletId: newOutletId })
  }

  function handleRegisterChange(newRegisterId: string) {
    const targetOutletId = stagedOutletId || activeOutletId
    if (!targetOutletId) return
    if (newRegisterId === activeRegisterId && !stagedOutletId) return

    requestSwitch(
      stagedOutletId
        ? { registerId: newRegisterId, outletId: targetOutletId }
        : { registerId: newRegisterId },
    )
  }

  const disabled = updateContext.isPending || contextLocked
  // Sucursal elegida sin ninguna caja USABLE: el cambio quedó sin comitear.
  const noUsableRegisters = !contextLocked && registersOfOutlet.length === 0
  // …y si las hay pero están todas desactivadas, el motivo es otro.
  const onlyDisabledRegisters = noUsableRegisters && anyRegisterOfOutlet

  return (
    <>
      <SettingRow label="Sucursal" htmlFor="ajustes-outlet">
        <Select
          value={effectiveOutletId}
          onValueChange={handleOutletChange}
          disabled={disabled}
        >
          <SelectTrigger id="ajustes-outlet" className="w-full">
            <SelectValue placeholder="Sin seleccionar" />
          </SelectTrigger>
          <SelectContent>
            {outlets.map((o) => (
              <SelectItem key={o.id} value={o.id}>
                {o.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </SettingRow>

      <SettingRow label="Caja" htmlFor="ajustes-register">
        <Select
          value={stagedRegisterId || activeRegisterId}
          onValueChange={handleRegisterChange}
          disabled={disabled || registersOfOutlet.length === 0}
        >
          <SelectTrigger id="ajustes-register" className="w-full">
            <SelectValue
              placeholder={
                onlyDisabledRegisters
                  ? "Sin cajas activas"
                  : noUsableRegisters
                    ? "Esta sucursal no tiene cajas"
                    : "Sin seleccionar"
              }
            />
          </SelectTrigger>
          <SelectContent>
            {registersOfOutlet.map((r) => (
              <SelectItem key={r.id} value={r.id}>
                {r.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </SettingRow>

      {/* Altura constante: el texto CAMBIA, no aparece. Un bloque que se
          inserta al perder la conexión empujaría todo lo de abajo, y la regla
          del POS es que nada se mueva de lugar según el estado (context/14
          §10). */}
      <SettingRow align="start">
        <p className="text-xs text-muted-foreground">
          {contextLocked
            ? "Sin conexión: la sucursal y la caja no se pueden cambiar. Se muestran las actuales."
            : onlyDisabledRegisters
              ? "Esa sucursal tiene cajas, pero están todas desactivadas. Reactivá una desde el panel o elegí otra sucursal."
              : noUsableRegisters
                ? "Esa sucursal no tiene ninguna caja, así que el device no se puede mover ahí. Creá una caja desde el panel o elegí otra sucursal."
                : "Al elegir una sucursal se toma su primera caja; podés cambiarla abajo. Cambiar de sucursal o de caja vacía la venta en curso."}
        </p>
      </SettingRow>

      {/* Confirmación de descarte. Solo aparece si hay líneas cargadas — con el
          carrito vacío el cambio va derecho, sin peaje.

          Se pregunta ANTES de mover el device: una vez que el servidor aceptó
          el cambio, la venta ya no tiene dónde emitirse (otros precios, otro
          stock, otra numeración) y ofrecer "cancelar" sería mentir. */}
      <AlertDialog
        open={pendingSwitch !== null}
        onOpenChange={(o) => {
          // Al ABRIR se limpia la marca: si algún cierre no llegara a disparar
          // este handler, el ref quedaría en `true` y se comería el cancel del
          // diálogo siguiente.
          if (o) {
            committingRef.current = false
            return
          }
          if (committingRef.current) {
            committingRef.current = false
            return
          }
          cancelSwitch()
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Se va a vaciar la venta en curso</AlertDialogTitle>
            <AlertDialogDescription>
              {`Hay ${cartLineCount} ${cartLineCount === 1 ? "artículo cargado" : "artículos cargados"} en esta venta. `}
              Los precios, el stock y la numeración son de la caja actual, así
              que la venta no se puede mudar: al cambiar de contexto se descarta
              junto con el cliente, el modo y los descuentos.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={cancelSwitch}>
              Seguir en esta caja
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (!pendingSwitch) return
                committingRef.current = true
                void commitSwitch(pendingSwitch)
              }}
            >
              Descartar y cambiar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

function AjustesPanel() {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  // Módulo "POS físico Bancard" (panel → Módulos) — gatea la config de IP.
  const bancardPosEnabled = useCatalogStore((s) => s.config?.bancardPosEnabled ?? false)
  const { data, isLoading } = usePosRegisterConfig(activeRegisterId)
  const updateConfig = useUpdatePosRegisterConfig()
  const config = data?.config ?? POS_REGISTER_CONFIG_DEFAULTS

  const pendingRef = React.useRef<Partial<PosRegisterConfig>>({})
  const timerRef = React.useRef<ReturnType<typeof setTimeout> | null>(null)
  // Espejo en estado del pendingRef, SOLO para pintar. El switch era
  // controlado por el cache del server (config[key]) y el toggle recién
  // tocaba ese cache al flushear el debounce (400 ms) — hasta entonces la
  // UI no daba NINGUNA señal. En touch eso se lee como "el switch no anda"
  // (y un segundo tap dentro de la ventana lo dejaba donde empezó). El
  // valor pintado ahora es pending ?? server: feedback en el mismo frame.
  const [pending, setPending] = React.useState<Partial<PosRegisterConfig>>({})

  const flushPending = React.useCallback(() => {
    const patch = pendingRef.current
    pendingRef.current = {}
    if (Object.keys(patch).length === 0) return
    updateConfig.mutate(patch, {
      onSettled: () => {
        // Al asentarse la mutación (éxito u error) el cache del server ya es
        // la verdad (optimistic en éxito, rollback en error) — soltamos el
        // overlay de esas keys para volver a pintar desde el server.
        setPending((prev) => {
          const next = { ...prev }
          for (const k of Object.keys(patch)) delete next[k as keyof PosRegisterConfig]
          return next
        })
      },
      // El update optimista del hook ya hace rollback silencioso en error;
      // sin toast el switch "volvía solo" sin explicación — eso ES un bug de
      // UX aunque el rollback sea correcto.
      onError: (e) => toast.error(`No se pudo guardar el ajuste: ${e.message}`),
    })
  }, [updateConfig])

  const handleToggle = React.useCallback(
    (key: keyof PosRegisterConfig, value: boolean | string) => {
      pendingRef.current = { ...pendingRef.current, [key]: value }
      setPending((prev) => ({ ...prev, [key]: value }))
      if (timerRef.current) clearTimeout(timerRef.current)
      timerRef.current = setTimeout(flushPending, 400)
    },
    [flushPending],
  )

  React.useEffect(() => {
    return () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current)
        flushPending()
      }
    }
  }, [flushPending])

  return (
    <div className="flex h-full flex-col">
      <div className="flex-1 overflow-y-auto px-6 py-6">
        <div className="space-y-8">

          {/* Sección: Dispositivo */}
          <div>
            <p className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Dispositivo
            </p>
            <div className="space-y-3">

              <DeviceContextSelectors />


              {/* Sin campo "Próxima numeración": era un input mock con un valor
                  hardcodeado que no leía ni escribía nada. La numeración la
                  entrega el lease del servidor; dejar que se toque a mano
                  quemaría o duplicaría números fiscales. Decisión del owner
                  (2026-07-29). */}

              {/* Sin campo "Fecha": la fecha de la transacción la pone el
                  servidor (tenant-local, ver `tenantNow`). Un input editable acá
                  sugiere que el cajero puede fechar una venta a mano — con
                  numeración fiscal y cierres de caja de por medio, eso es un
                  problema, no una función. Decisión del owner (2026-07-29). */}

              {/* Bloqueo por inactividad */}
              {/* TODO (backend): persistir en config del dispositivo */}
              <SettingRow
                label="Bloquear sesión luego de (seg.)"
                htmlFor="ajustes-lock-timeout"
              >
                <Input
                  id="ajustes-lock-timeout"
                  className="w-full"
                  type="number"
                  defaultValue="0"
                />
              </SettingRow>

              {/* IP POS Bancard — SOLO con el módulo `bancardPos` activo
                  (panel → Módulos → POS físico Bancard). Persiste por caja en
                  register posConfig (`bancardPosIp`), mismo debounce que los
                  toggles. */}
              {bancardPosEnabled && (
                <SettingRow label="IP POS Bancard" htmlFor="ajustes-bancard-ip">
                  <Input
                    id="ajustes-bancard-ip"
                    className="w-full"
                    placeholder="Ej: 192.168.101.68"
                    value={(pending.bancardPosIp as string | undefined) ?? config.bancardPosIp}
                    disabled={isLoading}
                    onChange={(e) => handleToggle("bancardPosIp", e.target.value)}
                  />
                </SettingRow>
              )}
            </div>
          </div>

          {/* Sección: Opciones del POS */}
          <div>
            <p className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Opciones del POS
            </p>
            <div>
              {AJUSTES_TOGGLES.map(({ key, label, description }, idx) => (
                <div
                  key={key}
                  className={cn(
                    "flex items-center justify-between gap-3 px-1 py-3",
                    idx < AJUSTES_TOGGLES.length - 1 && "border-b border-border",
                  )}
                >
                  {/* `min-w-0`: sin esto una descripción larga no puede
                      encogerse y empuja al Switch fuera de la fila en un
                      teléfono. */}
                  <div className="min-w-0">
                    <p className="text-sm">{label}</p>
                    {description && (
                      <p className="text-xs text-muted-foreground">{description}</p>
                    )}
                  </div>
                  <Switch
                    checked={(pending[key] as boolean | undefined) ?? (config[key] as boolean)}
                    disabled={isLoading}
                    onCheckedChange={(val) => handleToggle(key, val)}
                  />
                </div>
              ))}
            </div>
          </div>

          {/* Sección: Acción peligrosa */}
          <div>
            <p className="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Zona de peligro
            </p>
            <RemoveDeviceDialog />
          </div>

        </div>
      </div>
    </div>
  )
}
