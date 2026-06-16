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
  Component,
  Bell,
  SquaresIntersect,
  Plus,
  type LucideIcon,
} from "lucide-react"

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
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog"
import { useCatalogStore } from "@/lib/catalog/store"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { usePosUIStore } from "@/lib/ui/store"
import { useCartStore } from "@/lib/cart/store"

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
  onSelect?: (helpers: { setOpen: (v: boolean) => void; activeRegisterId: string }) => void
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
    key: "agenda",
    label: "Agenda",
    icon: CalendarDays,
    CustomContent: AgendaPreview,
  },
  {
    key: "orders",
    label: "Órdenes",
    icon: SquareKanban,
    CustomContent: OrdersPreview,
  },
  {
    key: "printers",
    label: "Impresoras",
    icon: Printer,
    CustomContent: PrintersPanel,
  },
  {
    key: "modules",
    label: "Módulos",
    icon: Component,
    CustomContent: ModulesPanel,
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
    onSelect: ({ setOpen, activeRegisterId }) => {
      if (!activeRegisterId) return
      setOpen(false)
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
  const setHotkeysEditing = useHotkeysStore((s) => s.setEditing)

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

  // Secciones enriquecidas con el flag `disabled` calculado en runtime.
  const sectionsWithState: MenuSection[] = SECTIONS.map((s) => ({
    ...s,
    disabled: s.key === "edit-hotkeys" ? !activeRegisterId : false,
    ctaLabel:
      s.key === "edit-hotkeys" && !activeRegisterId ? "Sin caja activa" : s.ctaLabel,
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
        <Menu className="size-5" />
      </Button>

      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent
          // Modal sidebar+content. Overrides:
          // - mobile fullscreen (el teclado virtual haría scroll en modal chico);
          // - desktop 48rem clamped (más chico que /settings, es un menú);
          // - reset de gap/padding (el grid interno maneja su layout).
          className={cn(
            "gap-0 overflow-hidden p-0",
            "max-sm:!inset-0 max-sm:!h-dvh max-sm:!max-w-none max-sm:!w-auto max-sm:!translate-x-0 max-sm:!translate-y-0 max-sm:!rounded-none",
            "sm:!max-w-[min(48rem,calc(100vw-2rem))] sm:!w-full",
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

            {/* Sidebar: vertical en desktop, horizontal scrolleable en mobile.
                pr-12 en mobile deja lugar al botón X absolute del DialogContent. */}
            <nav
              aria-label="Secciones del menú del POS"
              className="flex shrink-0 gap-0.5 overflow-x-auto border-b bg-card p-2 pr-12 sm:flex-col sm:border-b-0 sm:border-r sm:p-3 sm:pr-3"
            >
              {sectionsWithState.map(({ key, label, icon: Icon, onSelect, disabled }) => {
                const active = activeKey === key
                // Items con onSelect (ej. HotKeys) no muestran content area:
                // ejecutan la acción directo al click. Si están disabled
                // (sin caja activa), el click no hace nada y se muestran atenuados.
                const handleClick = () => {
                  if (onSelect) {
                    onSelect({ setOpen, activeRegisterId: activeRegisterId ?? "" })
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

            {/* Content area */}
            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">

              {/* Breadcrumb: solo visible en desktop, solo cuando hay sección activa */}
              <header className="hidden items-center gap-2 border-b py-3 pl-6 pr-14 text-sm sm:flex">
                <div className="flex items-center gap-2 text-muted-foreground">
                  <span>Menú del POS</span>
                  {activeSection && (
                    <>
                      <span className="text-muted-foreground/50">›</span>
                      <span className="text-foreground">{activeSection.label}</span>
                    </>
                  )}
                </div>
              </header>

              {/* Sin sección seleccionada → empty state de bienvenida */}
              {!activeSection ? (
                <div className="flex flex-1 items-center justify-center p-6">
                  <div className="flex flex-col items-center gap-3 text-center">
                    <Menu className="size-12 text-muted-foreground" />
                    <p className="text-lg font-semibold">Menú del POS</p>
                    <p className="max-w-sm text-sm text-muted-foreground">
                      Elegí una opción del menú para ver más detalles y acceder a las acciones de la caja.
                    </p>
                  </div>
                </div>
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
    </MenuContentCtx.Provider>
  )
}

// ─────────────────────────────────────────────────────────────────────────────
// Componentes custom para el content area
// ─────────────────────────────────────────────────────────────────────────────

// ── Control de Caja ──────────────────────────────────────────────────────────

/**
 * Replica el diseño del legacy POS: resumen del turno activo con totales
 * por método de pago y acciones de cierre/extracción/ingreso/impresión.
 */
function ControlDeCajaPanel() {
  // Fecha de apertura mock (demo).
  // TODO (backend): obtener del endpoint GET /api/v1/pos/drawer/active
  const apertura = new Intl.DateTimeFormat("es", {
    weekday: "long",
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(2026, 5, 3, 15, 35)) // miércoles, 3 jun. 2026 a las 15:35

  // Filas del resumen de caja (mock).
  // TODO (backend): calcular desde movimientos de caja del turno activo.
  const filas: { label: string; monto: string; bold?: boolean }[] = [
    { label: "Caja Inicial", monto: "250.000" },
    { label: "TRANSFERENCIA", monto: "966.400" },
    { label: "Efectivo", monto: "4.087.500" },
    { label: "T. Débito", monto: "2.368.125" },
    { label: "T. Crédito", monto: "403.000" },
    { label: "Extracciones (Efectivo)", monto: "20.000" },
    { label: "Ingresos (Efectivo)", monto: "30.000" },
    { label: "TOTAL DE EFECTIVO", monto: "4.347.500", bold: true },
    { label: "PROPINAS", monto: "30.000", bold: true },
    { label: "NOTA DE CRÉDITO", monto: "0", bold: true },
  ]

  return (
    <div className="flex h-full flex-col">
      {/* Cuerpo scrolleable */}
      <div className="flex-1 overflow-y-auto px-6 py-6">
        {/* Header: ícono + estado */}
        <div className="mb-1 flex flex-col items-center gap-1">
          <DoorOpen className="size-16 text-muted-foreground" />
          <p className="text-xs uppercase tracking-wider text-muted-foreground">
            Caja abierta
          </p>
        </div>

        {/* Subheader: fecha y hora de apertura */}
        <p className="mb-6 text-center text-base font-semibold capitalize">
          {apertura}
        </p>

        {/* Listado de filas */}
        <div className="divide-y divide-border">
          {filas.map(({ label, monto, bold }) => (
            <div
              key={label}
              className={cn(
                "flex items-center justify-between px-1 py-3 text-sm",
                bold && "font-bold",
              )}
            >
              <span>{label}</span>
              <span className="tabular-nums">{monto}</span>
            </div>
          ))}
        </div>

        {/* TOTAL grande */}
        {/* TODO (backend): sumar todos los métodos de pago del turno */}
        <div className="mt-4 flex items-center justify-between rounded-lg bg-muted/30 px-3 py-3">
          <span className="text-base font-bold uppercase">TOTAL</span>
          <span className="text-2xl font-black tabular-nums">8.085.025</span>
        </div>
      </div>

      {/* Barra inferior con acciones */}
      <div className="flex gap-2 border-t bg-background px-6 py-4">
        <Button
          variant="destructive"
          className="flex-1"
          onClick={() => console.log("TODO (F2): cerrar caja")}
        >
          Cerrar caja
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={() => console.log("TODO (F2): extracción de efectivo")}
        >
          <ArrowDown className="size-4" />
          Extraer
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={() => console.log("TODO (F2): ingreso de efectivo")}
        >
          <ArrowUp className="size-4" />
          Ingresar
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={() => console.log("TODO (F2): imprimir resumen de caja")}
        >
          <Printer className="size-4" />
          Imprimir
        </Button>
      </div>
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
            router.push("/reports/transactions")
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

/** Lista de impresoras configuradas con estado de conexión. */
function PrintersPanel() {
  // Mock de impresoras configuradas.
  // TODO (backend): GET /api/v1/pos/printers
  const impresoras: { nombre: string; config: string; conectada: boolean }[] = [
    {
      nombre: "Comanda",
      config: "Ticket (Base) (Comanda) — Auto",
      conectada: true,
    },
    {
      nombre: "Ticket",
      config: "Ticket 80mm — USB",
      conectada: false,
    },
    {
      nombre: "Resumen ticket",
      config: "Ticket (Resumen) — Bluetooth",
      conectada: false,
    },
  ]

  return (
    <div className="flex h-full flex-col">
      <div className="flex-1 overflow-y-auto px-6 py-6">
        <p className="mb-4 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
          Impresoras configuradas
        </p>

        <div className="divide-y divide-border">
          {impresoras.map((imp) => (
            <div
              key={imp.nombre}
              className="flex items-center gap-3 px-1 py-3"
            >
              {/* Ícono */}
              <Printer className="size-5 shrink-0 text-muted-foreground" />
              {/* Info */}
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{imp.nombre}</p>
                <p className="text-xs text-muted-foreground">{imp.config}</p>
              </div>
              {/* Estado */}
              <span
                className={cn(
                  "shrink-0 rounded-full px-2 py-0.5 text-xs font-medium",
                  imp.conectada
                    ? "bg-green-100 text-green-700"
                    : "bg-muted text-muted-foreground",
                )}
              >
                {imp.conectada ? "Conectada" : "No detectada"}
              </span>
              {/* Menú contextual (decorativo) */}
              <Button variant="ghost" size="sm" className="h-7 w-7 p-0">
                ···
              </Button>
            </div>
          ))}
        </div>

        {/* Agregar impresora */}
        {/* TODO (backend): abrir modal de configuración de impresora */}
        <Button
          variant="outline"
          className="mt-4 w-full gap-2"
          onClick={() => console.log("TODO (backend): agregar impresora")}
        >
          <Plus className="size-4" />
          Agregar impresora
        </Button>
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
    mesas: false,
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
      key: "mesas",
      icono: SquaresIntersect,
      nombre: "Mesas y Espacios",
      descripcion: "Gestión de salón con mesas o boxes",
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

/** Panel de configuración del dispositivo POS. Largo y scrolleable. */
function AjustesPanel() {
  // Flag de agrupado de repetidos — wireado al store real del carrito.
  const mergeRepeated = useCartStore((s) => s.mergeRepeated)
  const setMergeRepeated = useCartStore((s) => s.setMergeRepeated)

  // Estado local de los toggles — TODO (backend): cargar y guardar desde config del tenant.
  const [opciones, setOpciones] = React.useState({
    controlCaja: true,
    tecladoVirtual: false,
    ordenEnVenta: false,
    ordenAImpresion: false,
    servidorImpresion: false,
    sonidosAlertas: false,
    inhabilitarAnimaciones: false,
    permitirGuardarVentas: false,
    ocultarDetalleCombos: false,
    modoSoloOrdenes: false,
  })

  const toggleOpciones: { key: keyof typeof opciones; label: string }[] = [
    { key: "controlCaja", label: "Control de Caja" },
    { key: "tecladoVirtual", label: "Teclado virtual" },
    { key: "ordenEnVenta", label: "Orden en venta" },
    { key: "ordenAImpresion", label: "Orden a impresión" },
    { key: "servidorImpresion", label: "Servidor de impresión" },
    { key: "sonidosAlertas", label: "Sonidos en alertas" },
    { key: "inhabilitarAnimaciones", label: "Inhabilitar animaciones" },
    { key: "permitirGuardarVentas", label: "Permitir guardar ventas" },
    { key: "ocultarDetalleCombos", label: "Ocultar detalle de combos en impresión" },
    { key: "modoSoloOrdenes", label: "Modo: solo órdenes" },
  ]

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

              {/* Sucursal */}
              {/* TODO (backend): cargar outlets del tenant */}
              <div className="flex items-center gap-3">
                <label className="w-48 shrink-0 text-sm text-muted-foreground">
                  Sucursal
                </label>
                <Select defaultValue="central">
                  <SelectTrigger className="flex-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="central">Central</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Caja */}
              {/* TODO (backend): cargar cajas del outlet */}
              <div className="flex items-center gap-3">
                <label className="w-48 shrink-0 text-sm text-muted-foreground">
                  Caja
                </label>
                <Select defaultValue="principal">
                  <SelectTrigger className="flex-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="principal">Caja Principal</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Próxima numeración */}
              {/* TODO (backend): GET /api/v1/pos/register/next-seq */}
              <div className="flex items-center gap-3">
                <label className="w-48 shrink-0 text-sm text-muted-foreground">
                  Próxima numeración
                </label>
                <Input className="flex-1" defaultValue="3578" />
              </div>

              {/* Fecha */}
              {/* TODO (backend): no editable — viene del servidor */}
              <div className="flex items-center gap-3">
                <label className="w-48 shrink-0 text-sm text-muted-foreground">
                  Fecha
                </label>
                <Input className="flex-1" type="date" defaultValue="2026-06-16" />
              </div>

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

              {/* Toggle: Agrupar productos repetidos — wireado al useCartStore. */}
              <div className="flex items-center justify-between gap-3 border-b border-border px-1 py-3">
                <div>
                  <p className="text-sm">Agrupar productos repetidos</p>
                  <p className="text-xs text-muted-foreground">
                    Sumar cantidad al tocar el mismo producto seguido. Si tocás otro entre medio, se crea una línea nueva — útil para promos con descuento por línea.
                  </p>
                </div>
                <Switch
                  checked={mergeRepeated}
                  onCheckedChange={setMergeRepeated}
                />
              </div>

              {toggleOpciones.map(({ key, label }, idx) => (
                <div
                  key={key}
                  className={cn(
                    "flex items-center justify-between gap-3 px-1 py-3",
                    idx < toggleOpciones.length - 1 && "border-b border-border",
                  )}
                >
                  <span className="text-sm">{label}</span>
                  <Switch
                    checked={opciones[key]}
                    onCheckedChange={(val) =>
                      setOpciones((prev) => ({ ...prev, [key]: val }))
                    }
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
            {/* TODO (backend): desasociar dispositivo del comercio — requiere confirmación */}
            <Button
              variant="ghost"
              className="text-destructive hover:text-destructive"
              onClick={() => console.log("TODO (backend): eliminar dispositivo del comercio")}
            >
              Eliminar dispositivo del comercio
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
