"use client"

/**
 * Menú principal del POS — overlay fullscreen translúcido.
 *
 * Port de `ncmMenu` del legacy (app.js:849 + #bodyMainMenu en index.php):
 * un overlay a pantalla completa, translúcido sobre la caja, con botones
 * grandes ("Ver" + título, acento de borde izquierdo). Lo abre el botón
 * "≡" del toolbar de caja.
 *
 * Distinto de los MÓDULOS (Hotkeys/Mesas/… → sidebar) y de las OPCIONES
 * DE VENTA (Imprimir/Descuento/… → SaleOptionsDrawer).
 *
 * Items (de #bodyMainMenu): Control de Caja · Transacciones · Agenda ·
 * Órdenes · Ajustes · Bloquear/Salir. La card de usuario abajo-izquierda
 * (sin el widget de Spotify del legacy). Cierra con ESC o click en backdrop.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import {
  Menu,
  X,
  Calculator,
  ReceiptText,
  CalendarDays,
  ClipboardList,
  Settings,
  LogOut,
  ChefHat,
  type LucideIcon,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { useCatalogStore } from "@/lib/catalog/store"

interface MenuEntry {
  key: string
  /** Etiqueta chica arriba del título (legacy "Ver"). */
  sub: string
  label: string
  /** Pista a la derecha del título (ej. atajo "(ESC)"). */
  hint?: string
  icon: LucideIcon
  href?: string
  action?: boolean
}

const ENTRIES: MenuEntry[] = [
  { key: "drawer", sub: "Ver", label: "Control de Caja", icon: Calculator, action: true },
  { key: "transactions", sub: "Ver", label: "Transacciones", icon: ReceiptText, href: "/reports/transactions" },
  { key: "agenda", sub: "Ver", label: "Agenda", icon: CalendarDays, href: "/pos/calendario" },
  { key: "orders", sub: "Ver", label: "Órdenes", icon: ClipboardList, href: "/pos/ordenes" },
  { key: "settings", sub: "Ver", label: "Ajustes", icon: Settings, href: "/settings" },
  { key: "lock", sub: "Bloquear o", label: "Salir", hint: "(ESC)", icon: LogOut, action: true },
]

export function PosMainMenu() {
  const router = useRouter()
  const [open, setOpen] = React.useState(false)
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const registers = useCatalogStore((s) => s.registers)
  // Mostrar la caja que matchee el claim activo, no siempre la primera.
  const register = registers.find((r) => r.id === activeRegisterId) ?? registers[0] ?? null

  // ESC cierra (atajo histórico del legacy).
  React.useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false)
    }
    window.addEventListener("keydown", onKey)
    return () => window.removeEventListener("keydown", onKey)
  }, [open])

  const go = (entry: MenuEntry) => {
    setOpen(false)
    if (entry.href) {
      router.push(entry.href)
      return
    }
    // TODO (F2): Control de Caja (arqueo) y Bloquear/Salir (lock + logout).
  }

  return (
    <>
      <Button
        variant="ghost"
        size="icon"
        className="size-9"
        aria-label="Menú del POS"
        onClick={() => setOpen(true)}
      >
        <Menu className="size-5" />
      </Button>

      {open && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Menú del POS"
          className="fixed inset-0 z-50"
        >
          {/* Backdrop translúcido sobre la caja */}
          <div
            className="absolute inset-0 bg-background/85 backdrop-blur-sm"
            onClick={() => setOpen(false)}
          />

          {/* Cerrar (arriba-izquierda, como el legacy) */}
          <button
            type="button"
            onClick={() => setOpen(false)}
            aria-label="Cerrar menú"
            className="absolute left-5 top-5 z-10 flex size-10 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            <X className="size-6" />
          </button>

          {/* Grid de botones grandes, centrado */}
          <div className="relative flex h-full w-full items-center justify-center p-6">
            <div className="grid w-full max-w-4xl grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
              {ENTRIES.map((entry) => (
                <BigMenuButton key={entry.key} entry={entry} onClick={() => go(entry)} />
              ))}
            </div>
          </div>

          {/* Card de usuario / sesión (abajo-izquierda). Sin Spotify. */}
          <div className="absolute bottom-5 left-5 z-10 flex items-center gap-3 rounded-2xl border border-border bg-card/80 px-4 py-3 backdrop-blur-md">
            <Avatar className="size-11">
              <AvatarFallback className="bg-muted">
                <ChefHat className="size-5 text-muted-foreground" />
              </AvatarFallback>
            </Avatar>
            <div className="min-w-0">
              {/* TODO (F2): nombre + rol del usuario activo (lock/session). */}
              <p className="truncate text-sm font-semibold text-foreground">
                {config?.companyName || "Punto"}
              </p>
              <p className="truncate text-xs text-muted-foreground">
                {register?.name || "Caja"}
              </p>
            </div>
          </div>
        </div>
      )}
    </>
  )
}

function BigMenuButton({
  entry,
  onClick,
}: {
  entry: MenuEntry
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="group flex flex-col items-start border-l-2 border-border/70 py-2 pl-4 text-left transition-colors hover:border-foreground"
    >
      <span className="text-sm font-medium text-muted-foreground">{entry.sub}</span>
      <span className="text-2xl font-bold leading-tight text-foreground">
        {entry.label}
        {entry.hint && (
          <span className="ml-1.5 align-middle text-sm font-normal text-muted-foreground">
            {entry.hint}
          </span>
        )}
      </span>
    </button>
  )
}
