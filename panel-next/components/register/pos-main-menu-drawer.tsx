"use client"

/**
 * Menú principal del POS — drawer inferior (shadcn/vaul).
 *
 * Port de `ncmMenu` del POS legacy (app.js:849 + #bodyMainMenu en index.php).
 * Es el menú que abre el botón "≡" del toolbar de la caja — distinto de:
 *   - los MÓDULOS (Hotkeys/Mesas/Calendario/Órdenes) → sidebar contextual,
 *   - las OPCIONES DE VENTA (Imprimir/Descuento/…) → `SaleOptionsDrawer`.
 *
 * Items (de #bodyMainMenu): Control de Caja · Transacciones · Agenda ·
 * Órdenes · Ajustes · Panel de Control · Bloquear/Salir.
 *
 * Navegación: los items con ruta real hacen `router.push`; Control de Caja
 * (arqueo) y Bloquear/Salir (lock/logout) son stubs hasta F2.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import {
  Menu,
  Calculator,
  ReceiptText,
  CalendarDays,
  ClipboardList,
  Settings,
  LayoutDashboard,
  Lock,
  type LucideIcon,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Drawer,
  DrawerContent,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from "@/components/ui/drawer"
import { cn } from "@/lib/utils"

interface MenuEntry {
  key: string
  label: string
  icon: LucideIcon
  /** Ruta destino (router.push). Si falta, usa onSelect (stub). */
  href?: string
  /** Acción no navegacional (arqueo, lock). */
  action?: boolean
  separatorBefore?: boolean
}

export function PosMainMenuDrawer() {
  const router = useRouter()
  const [open, setOpen] = React.useState(false)

  // Port de #bodyMainMenu (ncmMenu). Los módulos operativos viven en el
  // sidebar; este menú es navegación de gestión + sesión.
  const entries: MenuEntry[] = [
    { key: "drawer", label: "Control de Caja", icon: Calculator, action: true },
    { key: "transactions", label: "Transacciones", icon: ReceiptText, href: "/reports/transactions" },
    { key: "agenda", label: "Agenda", icon: CalendarDays, href: "/pos/calendario" },
    { key: "orders", label: "Órdenes", icon: ClipboardList, href: "/pos/ordenes" },
    { key: "settings", label: "Ajustes", icon: Settings, href: "/settings" },
    { key: "panel", label: "Panel de Control", icon: LayoutDashboard, href: "/" },
    { key: "lock", label: "Bloquear / Salir", icon: Lock, action: true, separatorBefore: true },
  ]

  const handle = (entry: MenuEntry) => {
    setOpen(false)
    if (entry.href) {
      router.push(entry.href)
      return
    }
    // TODO (F2): Control de Caja (arqueo) y Bloquear/Salir (lock + logout).
  }

  return (
    <Drawer open={open} onOpenChange={setOpen}>
      <DrawerTrigger asChild>
        <Button variant="ghost" size="icon" className="size-9" aria-label="Menú del POS">
          <Menu className="size-5" />
        </Button>
      </DrawerTrigger>
      <DrawerContent className="mx-auto max-w-lg">
        <DrawerHeader className="pb-2">
          <DrawerTitle>Menú</DrawerTitle>
        </DrawerHeader>

        <div className="overflow-y-auto px-2 pb-4">
          <div className="flex flex-col">
            {entries.map((entry) => (
              <React.Fragment key={entry.key}>
                {entry.separatorBefore && <div className="my-1.5 h-px bg-border" />}
                <MenuRow entry={entry} onClick={() => handle(entry)} />
              </React.Fragment>
            ))}
          </div>
        </div>
      </DrawerContent>
    </Drawer>
  )
}

function MenuRow({ entry, onClick }: { entry: MenuEntry; onClick: () => void }) {
  const Icon = entry.icon
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "flex items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[15px] text-foreground transition-colors hover:bg-muted",
      )}
    >
      <Icon className="size-5 shrink-0 text-muted-foreground" />
      <span className="font-medium">{entry.label}</span>
    </button>
  )
}
