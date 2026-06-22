"use client"

/**
 * Menú de opciones de la venta — drawer inferior (shadcn/vaul).
 *
 * Acciones cableadas: descuento global, nota, usuario, guardar, lista de precios.
 * Acciones stub (Próximamente): imprimir, etiquetas, cotización, remisión, cita, orden.
 * Eliminados: moneda, devolución.
 */

import * as React from "react"
import {
  Printer,
  Percent,
  MessageSquare,
  User,
  Tag,
  Save,
  FileText,
  Truck,
  CalendarPlus,
  ClipboardList,
  Tags,
  X,
  MoreVertical,
  type LucideIcon,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from "@/components/ui/drawer"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { cn } from "@/lib/utils"
import { usePosUIStore } from "@/lib/ui/store"
import { useCartStore } from "@/lib/cart/store"
import { NumericPad } from "@/components/pos/numeric-pad"
import { usePriceLists } from "@/hooks/use-price-lists"
import { useTeamMembers } from "@/hooks/use-team"
import { useSaveParkedSale } from "@/hooks/use-parked-sales"
import { toast } from "sonner"

// ── Tipos ─────────────────────────────────────────────────────────────────────

type ActiveDialog =
  | "discount"
  | "note"
  | "user"
  | "priceList"
  | "parkedSales"
  | null

// ── Componente principal ──────────────────────────────────────────────────────

export function SaleOptionsDrawer({
  onCancelSale,
}: {
  onCancelSale: () => void
}) {
  const open = usePosUIStore((s) => s.optionsOpen)
  const setOpen = usePosUIStore((s) => s.setOptionsOpen)
  const discountPadMode = usePosUIStore((s) => s.discountPadMode)
  const setDiscountPadMode = usePosUIStore((s) => s.setDiscountPadMode)

  const [activeDialog, setActiveDialog] = React.useState<ActiveDialog>(null)

  // Selectors for icon active state.
  const note = useCartStore((s) => s.note)
  const cartLines = useCartStore((s) => s.lines)

  const hasGlobalDiscount = React.useMemo(() => {
    if (cartLines.length === 0) return false
    return cartLines.some((l) => (l.discount ?? 0) > 0)
  }, [cartLines])

  const hasGlobalSeller = React.useMemo(() => {
    if (cartLines.length === 0) return false
    return cartLines.every((l) => Boolean(l.sellerId))
  }, [cartLines])

  const openDialog = (d: ActiveDialog) => {
    setOpen(false)
    setActiveDialog(d)
  }

  const closeDialog = () => setActiveDialog(null)

  const handleCancel = () => {
    setOpen(false)
    onCancelSale()
  }

  // ── Opciones de la venta ───────────────────────────────────────────────────

  const options: Array<{
    key: string
    label: string
    icon: LucideIcon
    action?: () => void
    stub?: boolean
    active?: boolean
  }> = [
    {
      key: "print",
      label: "Imprimir",
      icon: Printer,
      stub: true,
    },
    {
      key: "discount",
      label: "Descuento global",
      icon: Percent,
      action: () => openDialog("discount"),
      active: hasGlobalDiscount,
    },
    {
      key: "note",
      label: "Nota",
      icon: MessageSquare,
      action: () => openDialog("note"),
      active: Boolean(note),
    },
    {
      key: "user",
      label: "Usuario",
      icon: User,
      action: () => openDialog("user"),
      active: hasGlobalSeller,
    },
    {
      key: "tags",
      label: "Etiquetas",
      icon: Tag,
      stub: true,
    },
    {
      key: "save",
      label: "Guardar",
      icon: Save,
      action: () => {
        setOpen(false)
        handleSave()
      },
    },
    {
      key: "quote",
      label: "Cotización",
      icon: FileText,
      stub: true,
    },
    {
      key: "remission",
      label: "Remisión",
      icon: Truck,
      stub: true,
    },
    {
      key: "schedule",
      label: "Cita",
      icon: CalendarPlus,
      stub: true,
    },
    {
      key: "order",
      label: "Orden",
      icon: ClipboardList,
      stub: true,
    },
    {
      key: "priceList",
      label: "Lista de precios",
      icon: Tags,
      action: () => openDialog("priceList"),
    },
  ]

  // ── Guardar venta en curso ─────────────────────────────────────────────────

  const saveParked = useSaveParkedSale()

  const handleSave = () => {
    const { lines, customer, note } = useCartStore.getState()
    if (lines.length === 0) {
      toast.error("No hay ítems para guardar")
      return
    }
    saveParked.mutate(
      { data: { cart: lines, customer, notes: note } },
      {
        onSuccess: () => {
          useCartStore.getState().clear()
          toast.success("Venta guardada")
        },
        onError: () => toast.error("No se pudo guardar la venta"),
      },
    )
  }

  return (
    <>
      <Drawer open={open} onOpenChange={setOpen}>
        <DrawerTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="size-9"
            aria-label="Opciones de venta"
          >
            <MoreVertical className="size-5" />
          </Button>
        </DrawerTrigger>
        <DrawerContent className="mx-auto max-w-lg">
          <DrawerHeader className="pb-2">
            <DrawerTitle>Opciones de venta</DrawerTitle>
          </DrawerHeader>

          <div className="overflow-y-auto px-2 pb-4">
            <div className="flex flex-col">
              {options.map((opt) => (
                <OptionRow
                  key={opt.key}
                  label={opt.label}
                  icon={opt.icon}
                  stub={opt.stub}
                  active={opt.active}
                  onClick={() => {
                    if (opt.stub) return
                    opt.action?.()
                  }}
                />
              ))}

              <div className="my-1.5 h-px bg-border" />

              <OptionRow
                label="Cancelar venta"
                icon={X}
                destructive
                onClick={handleCancel}
              />
            </div>
          </div>

          <DrawerClose className="sr-only">Cerrar</DrawerClose>
        </DrawerContent>
      </Drawer>

      {/* ── Dialogs de acciones ────────────────────────────────────────────── */}

      <DiscountDialog
        open={activeDialog === "discount"}
        onClose={closeDialog}
        mode={discountPadMode}
        onModeToggle={() =>
          setDiscountPadMode(discountPadMode === "money" ? "percent" : "money")
        }
      />

      <NoteDialog open={activeDialog === "note"} onClose={closeDialog} />

      <UserDialog open={activeDialog === "user"} onClose={closeDialog} />

      <PriceListDialog
        open={activeDialog === "priceList"}
        onClose={closeDialog}
      />
    </>
  )
}

// ── OptionRow ─────────────────────────────────────────────────────────────────

function OptionRow({
  label,
  icon: Icon,
  stub,
  destructive,
  active,
  onClick,
}: {
  label: string
  icon: LucideIcon
  stub?: boolean
  destructive?: boolean
  active?: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={stub}
      title={stub ? "Próximamente" : undefined}
      className={cn(
        "flex items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[15px] transition-colors",
        destructive
          ? "text-destructive hover:bg-destructive/10"
          : stub
            ? "cursor-not-allowed text-muted-foreground/50"
            : "text-foreground hover:bg-muted",
      )}
    >
      <Icon
        className={cn(
          "size-5 shrink-0",
          destructive
            ? "text-destructive"
            : stub
              ? "text-muted-foreground/40"
              : active
                ? "text-primary"
                : "text-muted-foreground",
        )}
      />
      <span className="font-medium">{label}</span>
      {stub && (
        <span className="ml-auto text-xs text-muted-foreground/50">
          Próximamente
        </span>
      )}
    </button>
  )
}

// ── Dialog de descuento global ────────────────────────────────────────────────

function DiscountDialog({
  open,
  onClose,
  mode,
  onModeToggle,
}: {
  open: boolean
  onClose: () => void
  mode: "money" | "percent"
  onModeToggle: () => void
}) {
  const [value, setValue] = React.useState("0")

  const handleConfirm = () => {
    const num = parseFloat(value)
    if (!isNaN(num) && num > 0) {
      useCartStore.getState().applyGlobalDiscount(num, mode)
      toast.success(
        mode === "percent"
          ? `Descuento del ${num}% aplicado`
          : `Descuento de ${num} aplicado`,
      )
    }
    setValue("0")
    onClose()
  }

  React.useEffect(() => {
    if (open) setValue("0")
  }, [open])

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose() }}>
      <DialogContent className="max-w-xs">
        <DialogHeader>
          <DialogTitle>Descuento global</DialogTitle>
        </DialogHeader>
        <NumericPad
          mode={mode === "percent" ? "percent" : "money"}
          value={value}
          onChange={setValue}
          onShiftToggle={onModeToggle}
          onConfirm={handleConfirm}
          onCancel={onClose}
        />
        <DialogFooter>
          <Button variant="outline" size="sm" onClick={onClose}>
            Cancelar
          </Button>
          <Button size="sm" onClick={handleConfirm}>
            Aplicar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Dialog de nota ────────────────────────────────────────────────────────────

function NoteDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const currentNote = useCartStore((s) => s.note)
  const [value, setValue] = React.useState(currentNote ?? "")

  React.useEffect(() => {
    if (open) setValue(currentNote ?? "")
  }, [open, currentNote])

  const handleConfirm = () => {
    useCartStore.getState().setNote(value.trim() || null)
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose() }}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Nota de la venta</DialogTitle>
        </DialogHeader>
        <Textarea
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder="Ej. sin cebolla, piso 3..."
          rows={4}
          autoFocus
          onKeyDown={(e) => {
            if (e.key === "Enter" && e.ctrlKey) handleConfirm()
            if (e.key === "Escape") onClose()
          }}
        />
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={handleConfirm}>Guardar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Dialog de usuario ─────────────────────────────────────────────────────────

function UserDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const { data } = useTeamMembers({ status: "1" })
  const users = data?.users ?? []

  const handleSelect = (userId: string) => {
    // Capturar líneas al momento del click — la snapshot es consistente
    // porque no hay await entre getState() y los setLineSeller.
    const lines = useCartStore.getState().lines
    lines.forEach((l) => useCartStore.getState().setLineSeller(l.lineId, userId))
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose() }}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Asignar usuario</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-1 py-1">
          {users.length === 0 && (
            <p className="text-sm text-muted-foreground">
              No hay usuarios disponibles
            </p>
          )}
          {users.map((u) => (
            <button
              key={u.id}
              type="button"
              onClick={() => handleSelect(u.id)}
              className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition-colors hover:bg-muted"
            >
              <span
                className="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white"
                style={{ backgroundColor: u.color ?? "#6b7280" }}
              >
                {u.name.charAt(0).toUpperCase()}
              </span>
              <span className="font-medium">{u.name}</span>
            </button>
          ))}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Dialog de lista de precios ────────────────────────────────────────────────

function PriceListDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const { data: lists } = usePriceLists()
  const currentId = useCartStore((s) => s.priceListId)
  const [selected, setSelected] = React.useState<string>(currentId ?? "")

  React.useEffect(() => {
    if (open) setSelected(currentId ?? "")
  }, [open, currentId])

  const handleConfirm = () => {
    useCartStore.getState().setPriceListId(selected || null)
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose() }}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Lista de precios</DialogTitle>
        </DialogHeader>
        <Select value={selected} onValueChange={setSelected}>
          <SelectTrigger>
            <SelectValue placeholder="Precios estándar" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="">Precios estándar</SelectItem>
            {(lists ?? []).map((pl) => (
              <SelectItem key={pl.priceListId} value={pl.priceListId}>
                {pl.priceListName}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={handleConfirm}>Aplicar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
