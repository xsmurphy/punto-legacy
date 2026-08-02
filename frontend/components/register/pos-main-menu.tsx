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
  TrendingUp,
  TrendingDown,
  type LucideIcon,
} from "lucide-react"
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
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
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
import { CashMovementDialog } from "@/components/register/cash-movement-dialog"
import { formatMoney } from "@/lib/format-money"
import { formatDateTime, formatRelativeShort, tenantNow } from "@/lib/format-date"
import { StatTile } from "@/components/domain/reports/stat-tile"
import { useLockStore } from "@/lib/pos/lock-store"
import {
  useDrawerStatus,
  useDrawerSummary,
  useDrawerHourlyStats,
  useOpenDrawer,
  useCloseDrawer,
  useDrawerExpense,
  useDrawerIncome,
  type DrawerSummary,
  type DrawerHourlyRow,
} from "@/hooks/use-drawer"
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
import { usePosOutlets, usePosRegisters } from "@/hooks/use-pos-outlets"
import { useUpdateDeviceContext } from "@/hooks/use-update-device-context"
import { posFetch } from "@/lib/api/pos-fetch"
import { getDeviceClaims } from "@/lib/auth/device-claims"
import { PosTransactionsDialog } from "@/components/register/pos-transactions-dialog"
import { PrintersManager } from "@/components/settings/printers-manager"
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
  const [activeKey, setActiveKey] = React.useState<string | null>(null)

  // Stores de dominio para los handlers de secciones.
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const companyName = useCatalogStore((s) => s.config?.companyName)
  const setHotkeysEditing = useHotkeysStore((s) => s.setEditing)

  // Estado para el Dialog de transacciones
  const [transactionsOpen, setTransactionsOpen] = React.useState(false)

  // Resetear la sección al cerrar el modal para la próxima apertura.
  const handleOpenChange = (v: boolean) => {
    setOpen(v)
    if (!v) setActiveKey(null)
  }

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
      {/* Trigger ≡ — se mantiene idéntico al original para no romper el cart-panel */}
      <Button
        variant="ghost"
        size="icon"
        className="size-9"
        aria-label="Menú del POS"
        onClick={() => setOpen(true)}
      >
        <AppWindowMac className="size-5" />
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
          className={cn(
            "gap-0 overflow-hidden p-0",
            "max-sm:!inset-0 max-sm:!h-dvh max-sm:!max-w-none max-sm:!w-auto max-sm:!translate-x-0 max-sm:!translate-y-0 max-sm:!rounded-none",
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
          <div className="flex h-full min-h-0 w-full flex-col overflow-hidden sm:grid sm:h-[80vh] sm:grid-cols-[200px_1fr]">

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
                className="flex shrink-0 gap-0.5 overflow-x-auto border-b bg-card p-2 sm:flex-col sm:border-b-0 sm:p-3"
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
                <div className="flex min-w-0 items-center gap-2 text-muted-foreground">
                  <span className="shrink-0">Menú del POS</span>
                  {activeSection && (
                    <>
                      <span className="text-muted-foreground/50">›</span>
                      <span className="truncate text-foreground">{activeSection.label}</span>
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
              {!activeSection ? (
                <AccountOverview setActiveKey={setActiveKey} />
              ) : activeSection.CustomContent ? (
                /* Sección con contenido custom — ocupa todo el content area.
                   min-h-0 es crítico para que el flex-child no expanda más allá
                   del contenedor y el panel interno pueda scrollear. */
                <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
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

                  {/* Barra inferior con CTA */}
                  <div className="border-t bg-background px-6 py-4 sm:px-8">
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
      <PosTransactionsDialog open={transactionsOpen} onOpenChange={setTransactionsOpen} />
    </MenuContentCtx.Provider>
  )
}

// ─────────────────────────────────────────────────────────────────────────────
// Componentes custom para el content area
// ─────────────────────────────────────────────────────────────────────────────

// ── Resumen de la cuenta (default landing del menú) ──────────────────────────

/** Series del bar chart "Ventas por hora" — escala verde monocromática de
 *  tokens (context/20 §Tokens): `--chart-1` para hoy (el dato protagonista),
 *  `--chart-3` para ayer, además atenuado por `fillOpacity`. Sin hex. */
const VENTAS_POR_HORA_CONFIG = {
  hoy: { label: "Hoy", color: "var(--chart-1)" },
  ayer: { label: "Ayer", color: "var(--chart-3)" },
} satisfies ChartConfig

interface HourlyPoint {
  /** Hora del día 0-23 en TZ del tenant. */
  hour: number
  hoy: number
  ayer: number
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
 * Une las series de hoy y ayer en un solo dataset continuo, recortado al rango
 * de horas con datos (min..max de AMBAS series). Se rellenan las horas
 * intermedias sin ventas con 0 para que el eje X no salte.
 */
function buildHourlySeries(today: DrawerHourlyRow[], yesterday: DrawerHourlyRow[]): HourlyPoint[] {
  const map = new Map<number, HourlyPoint>()
  const absorb = (rows: DrawerHourlyRow[], key: "hoy" | "ayer") => {
    for (const row of rows) {
      const h = parseHour(row.hour)
      if (h < 0) continue
      const point = map.get(h) ?? { hour: h, hoy: 0, ayer: 0 }
      point[key] += row.salesTotal
      map.set(h, point)
    }
  }
  absorb(today, "hoy")
  absorb(yesterday, "ayer")
  if (map.size === 0) return []

  const hours = [...map.keys()]
  const min = Math.min(...hours)
  const max = Math.max(...hours)
  const out: HourlyPoint[] = []
  for (let h = min; h <= max; h++) {
    out.push(map.get(h) ?? { hour: h, hoy: 0, ayer: 0 })
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
 * `useDrawerHourlyStats` (ventas por hora, hoy vs ayer — resource aparte, no
 * embebido en el summary). `salesCount`/`customersCount`/`salesTotal` son
 * opcionales en DrawerSummary (default 0) para tolerar un backend sin deployar.
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

  const { data: hourly, isLoading: hourlyLoading } = useDrawerHourlyStats(isOpen)

  const salesCount = summary?.salesCount ?? 0
  const salesTotal = summary?.salesTotal ?? 0
  const customersCount = summary?.customersCount ?? 0
  const avgTicket = salesCount > 0 ? salesTotal / salesCount : 0

  const today = React.useMemo(() => hourly?.today ?? [], [hourly])
  const yesterday = React.useMemo(() => hourly?.yesterday ?? [], [hourly])
  const series = React.useMemo(() => buildHourlySeries(today, yesterday), [today, yesterday])

  // Hora local del tenant (0-23). El corte "a esta hora" tiene que ser el mismo
  // para las dos series: usar la última hora CON ventas de hoy castigaría a hoy
  // cuando ayer siguió vendiendo más tarde.
  const currentHour = React.useMemo(
    () => parseHour(tenantNow(hourly?.timezone ?? config?.timezone)),
    [hourly?.timezone, config?.timezone],
  )

  const derived = React.useMemo(() => {
    const sumUntil = (rows: DrawerHourlyRow[], limit: number) =>
      rows.reduce((acc, r) => {
        const h = parseHour(r.hour)
        return h >= 0 && h <= limit ? acc + r.salesTotal : acc
      }, 0)

    const todayUntilNow = sumUntil(today, currentHour)
    const yesterdayUntilNow = sumUntil(yesterday, currentHour)
    // delta% = (acumulado de hoy hasta la hora actual − acumulado de ayer hasta
    // esa MISMA hora) / acumulado de ayer. Sin base de ayer no hay porcentaje.
    const deltaPct =
      yesterdayUntilNow > 0 ? ((todayUntilNow - yesterdayUntilNow) / yesterdayUntilNow) * 100 : null

    // Proyección = total de hoy + (ritmo por hora del turno × horas que le
    // quedan al día comercial). El "día comercial" se define por la hora de la
    // ÚLTIMA venta de AYER: es el único dato real de hasta cuándo se vende en
    // esta caja. Sin ventas ayer no hay horizonte → no se proyecta nada.
    const yesterdayHours = yesterday.map((r) => parseHour(r.hour)).filter((h) => h >= 0)
    const lastHourYesterday = yesterdayHours.length > 0 ? Math.max(...yesterdayHours) : null
    const openHour = summary?.date ? parseHour(summary.date) : -1
    const elapsedHours = openHour >= 0 ? Math.max(1, currentHour - openHour + 1) : 1
    const ratePerHour = salesTotal / elapsedHours
    const remainingHours =
      lastHourYesterday !== null ? Math.max(0, lastHourYesterday - currentHour) : 0
    const projection =
      lastHourYesterday !== null ? salesTotal + ratePerHour * remainingHours : null

    return { deltaPct, projection }
  }, [today, yesterday, currentHour, salesTotal, summary?.date])

  const hasChartData = series.some((p) => p.hoy > 0 || p.ayer > 0)

  return (
    <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-6 sm:p-8">
      {/* Línea de contexto compacta: sucursal · caja activa */}
      <p className="text-sm text-muted-foreground">
        {outlet?.name || "—"} · {activeRegister?.name || "Sin caja seleccionada"}
      </p>

      {!loading && !isOpen ? (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 py-12 text-center">
          <p className="text-sm text-muted-foreground">Caja cerrada</p>
          <Button onClick={() => setActiveKey("drawer")}>Abrir caja</Button>
        </div>
      ) : (
        <>
          <div>
            <h1 className="text-2xl font-semibold">Turno en curso</h1>
            <p className="text-sm text-muted-foreground">
              {cashierName ? `Cajero: ${cashierName}` : "Operador sin identificar"}
              {summary?.date ? ` · abierto ${formatRelativeShort(summary.date)}` : ""}
            </p>
          </div>

          {/* Fila 1 — números clave. Total vendido es el hero (span 2 + emphasis).
              Patrón StatTile (context/20 §2026-07-31). */}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <StatTile
              label="Total vendido"
              value={formatMoney(salesTotal, config)}
              isLoading={loading}
              emphasis
              className="col-span-2"
            />
            <StatTile label="Ventas" value={salesCount} isLoading={loading} />
            <StatTile
              label="Ticket promedio"
              value={formatMoney(avgTicket, config)}
              isLoading={loading}
            />
            <StatTile label="Clientes atendidos" value={customersCount} isLoading={loading} />
          </div>

          {/* Fila 2 — ventas por hora, hoy vs ayer */}
          {hourlyLoading ? (
            <Skeleton className="h-[220px] w-full rounded-lg" />
          ) : hasChartData ? (
            <Card variant="soft" size="sm">
              <CardHeader>
                <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Ventas por hora
                </CardTitle>
              </CardHeader>
              <CardContent>
                <ChartContainer config={VENTAS_POR_HORA_CONFIG} className="h-[200px] w-full">
                  <BarChart data={series} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
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
                                {VENTAS_POR_HORA_CONFIG[name as keyof typeof VENTAS_POR_HORA_CONFIG]
                                  ?.label ?? name}
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
                    {/* Ayer primero para que quede detrás visualmente en la leyenda
                        y hoy (chart-1) sea la barra que se lee primero. */}
                    <Bar
                      dataKey="ayer"
                      fill="var(--color-ayer)"
                      fillOpacity={0.45}
                      radius={[4, 4, 0, 0]}
                    />
                    <Bar dataKey="hoy" fill="var(--color-hoy)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ChartContainer>
              </CardContent>
            </Card>
          ) : (
            <p className="text-sm text-muted-foreground">
              Todavía no hay ventas en este turno.
            </p>
          )}

          {/* Fila 3 — derivados del chart. Cada tile se oculta si no tiene base
              real de comparación (nunca inventar una proyección sin histórico). */}
          {!hourlyLoading && (derived.deltaPct !== null || derived.projection !== null) && (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {derived.deltaPct !== null && (
                <StatTile
                  label="vs ayer a esta hora"
                  value={`${derived.deltaPct >= 0 ? "+" : ""}${derived.deltaPct.toFixed(1)}%`}
                  tone={derived.deltaPct >= 0 ? "positive" : "negative"}
                  icon={
                    derived.deltaPct >= 0 ? (
                      <TrendingUp className="size-3.5" />
                    ) : (
                      <TrendingDown className="size-3.5" />
                    )
                  }
                />
              )}
              {derived.projection !== null && (
                <StatTile
                  label="Proyección del día"
                  value={formatMoney(derived.projection, config)}
                />
              )}
            </div>
          )}

          {/* Tiles secundarios — solo lo que aplica */}
          {!loading && summary && (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <StatTile label="Efectivo en caja" value={formatMoney(summary.subtotal, config)} />
              {summary.tips > 0 && (
                <StatTile label="Propinas" value={formatMoney(summary.tips, config)} />
              )}
              {summary.returns !== 0 && (
                <StatTile
                  label="Devoluciones"
                  value={formatMoney(summary.returns, config)}
                  tone="negative"
                />
              )}
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
 * handleSimpleConfirm más abajo).
 *
 * FLAG: summary.list trae filas (name, amount) pero no hay campo separado de
 * "apertura" vs "ventas por método" — se mapean como payments usando las
 * filas de list tal como vienen del backend.
 */
function buildCloseRegTicket(
  summary: DrawerSummary,
  config: { companyName?: string } | null,
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
    discount: 0,
    total: p.total,
    categoryId: null,
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
function ControlDeCajaPanel() {
  // En el POS usamos el config del catalog store (ya hidratado por useCatalogSeed).
  // useBootstrap no se necesita en el POS — el config viene del PosBootstrap.
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: status, isLoading: statusLoading } = useDrawerStatus()
  const { data: summary, isLoading: summaryLoading } = useDrawerSummary()
  const { data: bindingsData } = usePrinterBindings(activeRegisterId || undefined, { client: posApi })
  const allBindings = bindingsData?.bindings ?? []
  const { requestPrint, pickerDialog } = usePrintWithPicker()

  const openDrawer  = useOpenDrawer()
  const closeDrawer = useCloseDrawer()
  const expense     = useDrawerExpense()
  const income      = useDrawerIncome()

  // Estado local del modal de monto (abre/cierra con apertura/cierre/movimiento)
  type ModalMode = "open" | "close" | "expense" | "income" | null
  const [modalMode, setModalMode] = React.useState<ModalMode>(null)

  const isOpen = status?.isOpen ?? false
  const loading = statusLoading || summaryLoading

  function openModal(mode: ModalMode) {
    setModalMode(mode)
  }

  async function handleSimpleConfirm(amount: number) {
    const date = new Date().toISOString().replace("T", " ").slice(0, 19)
    try {
      if (modalMode === "open") await openDrawer.mutateAsync({ amount, date })
      if (modalMode === "close") {
        // Snapshot del summary ANTES de cerrar: al confirmar el cierre la
        // query de summary se invalida y los datos desaparecen — sin este
        // snapshot no habría forma de imprimir el reporte de cierre después.
        const summarySnapshot = summary
        await closeDrawer.mutateAsync({ amount, date })
        if (summarySnapshot && allBindings) {
          try {
            requestPrint("closeReg", buildCloseRegTicket(summarySnapshot, config), allBindings)
          } catch (printErr) {
            // Un fallo de impresora no debe afectar el cierre, que ya se confirmó.
            console.error("[closeReg auto-print]", printErr)
            toast.warning("La caja cerró pero no se pudo imprimir el cierre")
          }
        }
      }
      setModalMode(null)
    } catch (err) {
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
    close:   "Cerrar caja — monto contado",
    expense: "Extracción de efectivo",
    income:  "Ingreso de efectivo",
  }


  // ── Modals de monto — NumericPadDialog para open/close, CashMovementDialog para expense/income ──
  const isSimpleMode = modalMode === "open" || modalMode === "close"
  const isMovementMode = modalMode === "expense" || modalMode === "income"
  const [draftSimple, setDraftSimple] = React.useState("0")

  // Reset draft al abrir un modo simple
  React.useEffect(() => {
    if (isSimpleMode) setDraftSimple("0")
  }, [isSimpleMode])

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

        {/* Resumen del turno */}
        {summary && (
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

        {/* Caja cerrada: prompt para abrir */}
        {!loading && !isOpen && !summary && (
          <div className="mt-8 flex flex-col items-center gap-3 text-center">
            <p className="text-sm text-muted-foreground">
              La caja está cerrada. Abrila para empezar a cobrar.
            </p>
          </div>
        )}
      </div>

      {/* Barra de acciones */}
      <div className="flex gap-2 border-t bg-background px-6 py-4">
        {isOpen ? (
          <>
            <Button
              variant="destructive"
              className="flex-1"
              onClick={() => openModal("close")}
            >
              Cerrar caja
            </Button>
            <Button variant="outline" size="sm" onClick={() => openModal("expense")}>
              <ArrowDown className="size-4" />
              Extraer
            </Button>
            <Button variant="outline" size="sm" onClick={() => openModal("income")}>
              <ArrowUp className="size-4" />
              Ingresar
            </Button>
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
          </>
        ) : (
          <Button className="flex-1" onClick={() => openModal("open")} disabled={loading}>
            Abrir caja
          </Button>
        )}
      </div>

      {/* NumericPadDialog para open/close (sin nota) */}
      <NumericPadDialog
        open={isSimpleMode}
        onClose={() => setModalMode(null)}
        title={modalMode && isSimpleMode ? modalLabel[modalMode] : ""}
        mode="money"
        value={draftSimple}
        onValueChange={setDraftSimple}
        onConfirm={() => { void handleSimpleConfirm(Number(draftSimple)) }}
        confirmLabel="Confirmar"
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

/** Últimas transacciones del turno con CTA para ir al listado completo. */
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
  if (!activeRegisterId) {
    return (
      <div className="p-6 text-sm text-muted-foreground">
        Elegí una caja activa primero.
      </div>
    )
  }
  return (
    <div className="h-full overflow-y-auto px-6 py-6">
      <PrintersManager registerId={activeRegisterId} />
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
 * Selectores de sucursal+caja del device actual.
 *
 * El admin pre-elige estos valores al generar el link de invitación, pero el
 * cajero puede moverse entre cajas del tenant desde Ajustes sin pedir un link
 * nuevo. UPDATE en la fila device → invalidate del bootstrap → catalog store
 * se re-hidrata con el contexto nuevo.
 */
function DeviceContextSelectors() {
  const activeOutletId = useCatalogStore((s) => s.outlet?.id ?? "")
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)

  const { data: outletsData } = usePosOutlets()
  const { data: registersData } = usePosRegisters()
  const updateContext = useUpdateDeviceContext()

  const [pendingOutletId, setPendingOutletId] = React.useState<string>("")
  const effectiveOutletId = pendingOutletId || activeOutletId

  const outlets = outletsData?.rows ?? []
  const registersOfOutlet = (registersData?.registers ?? []).filter(
    (r) => r.outletId === effectiveOutletId && r.status,
  )

  function handleOutletChange(newOutletId: string) {
    if (newOutletId === activeOutletId) {
      setPendingOutletId("")
      return
    }
    // No commiteamos al server hasta que elija una caja del outlet nuevo
    // (el endpoint exige registerId — sucursal sola no se puede).
    setPendingOutletId(newOutletId)
  }

  function handleRegisterChange(newRegisterId: string) {
    const targetOutletId = pendingOutletId || activeOutletId
    if (!targetOutletId) return
    if (newRegisterId === activeRegisterId && !pendingOutletId) return

    updateContext.mutate(
      pendingOutletId
        ? { registerId: newRegisterId, outletId: targetOutletId }
        : { registerId: newRegisterId },
      {
        onSuccess: () => {
          toast.success("Contexto actualizado")
          setPendingOutletId("")
        },
        onError: (e) => toast.error(e.message),
      },
    )
  }

  const disabled = updateContext.isPending

  return (
    <>
      <div className="flex items-center gap-3">
        <label className="w-48 shrink-0 text-sm text-muted-foreground">
          Sucursal
        </label>
        <Select
          value={effectiveOutletId}
          onValueChange={handleOutletChange}
          disabled={disabled}
        >
          <SelectTrigger className="flex-1">
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
      </div>

      <div className="flex items-center gap-3">
        <label className="w-48 shrink-0 text-sm text-muted-foreground">
          Caja
        </label>
        <Select
          value={pendingOutletId ? "" : activeRegisterId}
          onValueChange={handleRegisterChange}
          disabled={disabled || registersOfOutlet.length === 0}
        >
          <SelectTrigger className="flex-1">
            <SelectValue placeholder={pendingOutletId ? "Elegí una caja para confirmar" : "Sin seleccionar"} />
          </SelectTrigger>
          <SelectContent>
            {registersOfOutlet.map((r) => (
              <SelectItem key={r.id} value={r.id}>
                {r.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </>
  )
}

function AjustesPanel() {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
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
    (key: keyof PosRegisterConfig, value: boolean) => {
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
              <div className="flex items-center gap-3">
                <label className="w-48 shrink-0 text-sm text-muted-foreground">
                  Bloquear sesión luego de (seg.)
                </label>
                <Input className="flex-1" type="number" defaultValue="0" />
              </div>

              {/* IP POS Bancard */}
              {/* TODO (backend): persistir en config del dispositivo para integración Bancard */}
              <div className="flex items-center gap-3">
                <label className="w-48 shrink-0 text-sm text-muted-foreground">
                  IP POS Bancard
                </label>
                <Input
                  className="flex-1"
                  placeholder="Ej: 192.168.101.68"
                />
              </div>
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
                  <div>
                    <p className="text-sm">{label}</p>
                    {description && (
                      <p className="text-xs text-muted-foreground">{description}</p>
                    )}
                  </div>
                  <Switch
                    checked={(pending[key] as boolean | undefined) ?? config[key]}
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
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button
                  variant="ghost"
                  className="text-destructive hover:text-destructive"
                >
                  Eliminar dispositivo del comercio
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Eliminar dispositivo del comercio</AlertDialogTitle>
                  <AlertDialogDescription>
                    Esta acción desvinculará este dispositivo de la caja. Tendrás que volver a parearlo para usar el POS.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancelar</AlertDialogCancel>
                  <AlertDialogAction
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    onClick={async () => {
                      try {
                        const deviceId = getDeviceClaims("pos")?.deviceId ?? null
                        const res = await posFetch("/api/pos/revoke-this-device", {
                          method: "POST",
                          headers: { "Content-Type": "application/json" },
                          body: JSON.stringify({ deviceId }),
                        })
                        if (!res.ok) {
                          const data = await res.json().catch(() => ({}))
                          toast.error((data as { error?: { message?: string } }).error?.message ?? "Error al eliminar el dispositivo")
                          return
                        }
                        // El device fue revocado server-side; recargar /pos hace que
                        // PosAuthGuard re-evalúe y muestre DeviceNotConnected.
                        window.location.href = "/pos"
                      } catch {
                        toast.error("Error al eliminar el dispositivo")
                      }
                    }}
                  >
                    Eliminar
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </div>

        </div>
      </div>
    </div>
  )
}
