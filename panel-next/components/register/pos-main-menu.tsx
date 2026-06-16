"use client"

/**
 * Menú principal del POS — modal tipo settings (sidebar + content area).
 *
 * Reemplaza el overlay fullscreen translúcido que venía del legacy. Ahora usa
 * el mismo patrón de Dialog que /settings: sidebar a la izquierda con los
 * items del menú, content area a la derecha con descripción + CTA.
 *
 * Abre con el botón "≡" del toolbar de caja (Cart Panel) o con el atajo Q.
 * ESC lo cierra vía el Dialog de shadcn (sin handler manual).
 *
 * Items: Control de Caja · Transacciones · Agenda · Órdenes · Ajustes ·
 * Cambiar Caja/Sucursal · Editar Hotkeys · Bloquear.
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
  Store,
  LayoutGrid,
  Lock,
  type LucideIcon,
} from "lucide-react"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
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
import { useLockStore } from "@/lib/pos/lock-store"
import { clearDeviceDefault } from "@/lib/pos/device"

// ── Tipos ────────────────────────────────────────────────────────────────────

interface MenuSection {
  key: string
  label: string
  icon: LucideIcon
  description: string
  ctaLabel: string
  /** Si true, el CTA se muestra deshabilitado. */
  disabled?: boolean
}

// ── Constante de secciones (no acá los handlers — los armamos en el hook) ───

const SECTIONS: Omit<MenuSection, "disabled">[] = [
  {
    key: "drawer",
    label: "Control de Caja",
    icon: Calculator,
    description:
      "Llevá el control de aperturas, cierres y arqueos de tu caja. Acá vas a ver el resumen del turno actual y poder cerrar la caja al final del día.",
    ctaLabel: "Próximamente",
  },
  {
    key: "transactions",
    label: "Transacciones",
    icon: ReceiptText,
    description:
      "Mirá el historial de ventas, devoluciones y otros movimientos. Las transacciones de esta caja y de las demás del comercio se listan en el panel.",
    ctaLabel: "Ver transacciones",
  },
  {
    key: "agenda",
    label: "Agenda",
    icon: CalendarDays,
    description:
      "Calendario de turnos y reservas. Útil para módulos con citas como peluquerías, consultorios y servicios.",
    ctaLabel: "Abrir agenda",
  },
  {
    key: "orders",
    label: "Órdenes",
    icon: SquareKanban,
    description:
      "Listado de órdenes activas y completadas — pedidos en cocina, deliveries y pickups.",
    ctaLabel: "Ver órdenes",
  },
  {
    key: "settings",
    label: "Ajustes",
    icon: Settings,
    description:
      "Configurá tu empresa, sucursales, monedas, impuestos y todo lo demás. Te lleva al panel de configuración.",
    ctaLabel: "Ir a Ajustes",
  },
  {
    key: "change-register",
    label: "Cambiar Caja / Sucursal",
    icon: Store,
    description:
      "Cambiá el dispositivo a otra caja o sucursal. Vas a tener que seleccionar la nueva caja del listado.",
    ctaLabel: "Cambiar ahora",
  },
  {
    key: "edit-hotkeys",
    label: "Editar Hotkeys",
    icon: LayoutGrid,
    description:
      "Configurá los accesos rápidos de la grilla de la caja. Arrastrá ítems para reordenar, asigná categorías y colores a cada slot.",
    ctaLabel: "Editar Hotkeys",
  },
  {
    key: "lock",
    label: "Bloquear",
    icon: Lock,
    description:
      "Bloqueá la caja para evitar usos no autorizados. Vas a necesitar tu PIN para reanudar la sesión.",
    ctaLabel: "Bloquear ahora",
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

  // Stores de dominio para los handlers de cada sección.
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const resetActiveRegister = useCatalogStore((s) => s.resetActiveRegister)
  const setHotkeysEditing = useHotkeysStore((s) => s.setEditing)

  // Resetear la sección al cerrar el modal para la próxima apertura.
  const handleOpenChange = (v: boolean) => {
    setOpen(v)
    if (!v) setActiveKey(null)
  }

  // ── Handlers por key ──────────────────────────────────────────────────────

  const handleCta = (key: string) => {
    switch (key) {
      case "drawer":
        // TODO (F2): Control de Caja — deshabilitado por ahora.
        break
      case "transactions":
        setOpen(false)
        router.push("/reports/transactions")
        break
      case "agenda":
        setOpen(false)
        router.push("/pos/calendario")
        break
      case "orders":
        setOpen(false)
        router.push("/pos/ordenes")
        break
      case "settings":
        setOpen(false)
        router.push("/settings")
        break
      case "change-register":
        // Limpiar el default del dispositivo y resetear la caja activa.
        // El guard del layout detecta activeRegisterId === '' y abre el modal de setup.
        setOpen(false)
        clearDeviceDefault()
        resetActiveRegister()
        break
      case "edit-hotkeys":
        // Solo habilitado con caja activa (sin caja no hay grilla que editar).
        if (!activeRegisterId) break
        setOpen(false)
        setHotkeysEditing(true)
        break
      case "lock":
        setOpen(false)
        useLockStore.getState().lock()
        break
    }
  }

  // Sección activa enriquecida con el flag `disabled` calculado en runtime.
  const sectionsWithState: MenuSection[] = SECTIONS.map((s) => ({
    ...s,
    disabled:
      s.key === "drawer"
        ? true
        : s.key === "edit-hotkeys"
          ? !activeRegisterId
          : false,
    ctaLabel:
      s.key === "edit-hotkeys" && !activeRegisterId ? "Sin caja activa" : s.ctaLabel,
  }))

  const activeSection = sectionsWithState.find((s) => s.key === activeKey) ?? null

  return (
    <>
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
              {sectionsWithState.map(({ key, label, icon: Icon }) => {
                const active = activeKey === key
                return (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setActiveKey(key)}
                    className={cn(
                      "flex shrink-0 items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm transition-colors sm:w-full",
                      active
                        ? "bg-accent font-medium text-accent-foreground"
                        : "text-muted-foreground hover:bg-accent/50 hover:text-foreground",
                    )}
                    aria-current={active ? "page" : undefined}
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
              ) : (
                /* Sección activa: descripción + CTA */
                <div className="flex h-full flex-col">
                  <div className="flex-1 overflow-y-auto px-6 py-6 sm:px-8">
                    <div className="max-w-xl space-y-4">
                      {/* Icono + título */}
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
                      onClick={() => handleCta(activeSection.key)}
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
    </>
  )
}
