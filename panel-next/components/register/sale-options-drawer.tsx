"use client"

/**
 * Menú de opciones de la venta — drawer inferior (shadcn/vaul).
 *
 * Reemplaza el dropdown "..." por un drawer centrado que abre desde abajo,
 * espejo del `ncmActionSheet` + `#menuOpsList` del POS legacy (app/index.php).
 *
 * Opciones (set de MODO VENTA, portado de #menuOpsListTpl):
 *   Imprimir · Descuento global · Nota · Usuario · Etiquetas · Guardar ·
 *   Moneda · Devolución · Cotización · Remisión · Cita · Orden ·
 *   Lista de precios · (Cancelar venta)
 *
 * El menú legacy VARÍA por modo (venta / órdenes / agenda) y por permisos
 * (hide-basic, visible-high, etc.). Acá implementamos el set de venta; los
 * otros modos y el gating RBAC llegan en fases siguientes (ver `mode` +
 * TODOs). Todas las acciones son stubs salvo "Cancelar venta".
 */

import * as React from "react"
import {
  Printer,
  Percent,
  MessageSquare,
  User,
  Tag,
  Save,
  Coins,
  Undo2,
  FileText,
  Truck,
  CalendarPlus,
  ClipboardList,
  Tags,
  X,
  MoreHorizontal,
  type LucideIcon,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from "@/components/ui/drawer"
import { cn } from "@/lib/utils"

interface SaleOption {
  key: string
  label: string
  icon: LucideIcon
  onSelect?: () => void
  /** Acción destructiva (Cancelar venta) — separada visualmente al final. */
  destructive?: boolean
}

export function SaleOptionsDrawer({
  onCancelSale,
}: {
  onCancelSale: () => void
}) {
  const [open, setOpen] = React.useState(false)

  // Set de opciones de MODO VENTA. TODO (modos): cuando existan Órdenes /
  // Agenda, derivar este array del modo activo. TODO (RBAC): gatear
  // Usuario/Guardar/Lista de precios por permiso/plan (hide-basic, etc.).
  const options: SaleOption[] = [
    { key: "print", label: "Imprimir", icon: Printer },
    { key: "discount", label: "Descuento global", icon: Percent },
    { key: "note", label: "Nota", icon: MessageSquare },
    { key: "user", label: "Usuario", icon: User },
    { key: "tags", label: "Etiquetas", icon: Tag },
    { key: "save", label: "Guardar", icon: Save },
    { key: "currency", label: "Moneda", icon: Coins },
    { key: "return", label: "Devolución", icon: Undo2 },
    { key: "quote", label: "Cotización", icon: FileText },
    { key: "remission", label: "Remisión", icon: Truck },
    { key: "schedule", label: "Cita", icon: CalendarPlus },
    { key: "order", label: "Orden", icon: ClipboardList },
    { key: "priceList", label: "Lista de precios", icon: Tags },
  ]

  const handle = (opt: SaleOption) => {
    // TODO (fases siguientes): cablear cada acción (numpad descuento, editor
    // de nota, toggle devolución/cotización, selector de moneda/lista, etc.).
    setOpen(false)
    opt.onSelect?.()
  }

  const handleCancel = () => {
    setOpen(false)
    onCancelSale()
  }

  return (
    <Drawer open={open} onOpenChange={setOpen}>
      <DrawerTrigger asChild>
        <Button variant="ghost" size="icon" className="size-9" aria-label="Opciones de venta">
          <MoreHorizontal className="size-5" />
        </Button>
      </DrawerTrigger>
      <DrawerContent className="mx-auto max-w-lg">
        <DrawerHeader className="pb-2">
          <DrawerTitle>Opciones de venta</DrawerTitle>
        </DrawerHeader>

        <div className="overflow-y-auto px-2 pb-4">
          <div className="flex flex-col">
            {options.map((opt) => (
              <OptionRow key={opt.key} option={opt} onClick={() => handle(opt)} />
            ))}

            <div className="my-1.5 h-px bg-border" />

            <OptionRow
              option={{
                key: "cancel",
                label: "Cancelar venta",
                icon: X,
                destructive: true,
              }}
              onClick={handleCancel}
            />
          </div>
        </div>

        <DrawerClose className="sr-only">Cerrar</DrawerClose>
      </DrawerContent>
    </Drawer>
  )
}

function OptionRow({
  option,
  onClick,
}: {
  option: SaleOption
  onClick: () => void
}) {
  const Icon = option.icon
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "flex items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[15px] transition-colors",
        option.destructive
          ? "text-destructive hover:bg-destructive/10"
          : "text-foreground hover:bg-muted",
      )}
    >
      <Icon
        className={cn(
          "size-5 shrink-0",
          option.destructive ? "text-destructive" : "text-muted-foreground",
        )}
      />
      <span className="font-medium">{option.label}</span>
    </button>
  )
}
