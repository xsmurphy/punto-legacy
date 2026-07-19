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
  XCircle,
  MoreVertical,
  Undo2,
  type LucideIcon,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
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
import { useCatalogStore } from "@/lib/catalog/store"
import { NumericPadDialog } from "@/components/pos/numeric-pad-dialog"
import { usePriceLists } from "@/hooks/use-price-lists"
import { posApi } from "@/lib/api/pos-client"
import { useTags } from "@/hooks/use-tags"
import { useSaveParkedSale } from "@/hooks/use-parked-sales"
import { toast } from "sonner"
import { createQuote } from "@/lib/commands/create-quote"
import { QuotePrintViewDialog } from "@/components/domain/transactions/quote-print-view"
import type { TransactionDetail } from "@/hooks/use-transactions"
import { SellerPickerDialog } from "@/components/pos/seller-picker-dialog"
import { usePosRegisterConfig } from "@/hooks/use-pos-config"

// ── Tipos ─────────────────────────────────────────────────────────────────────

type ActiveDialog =
  | "discount"
  | "note"
  | "user"
  | "priceList"
  | "parkedSales"
  | "tags"
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
  // El preview de cotización se construye desde el snapshot del carrito (no se
  // re-fetchea por id): acabamos de guardar la cotización con estos datos, así
  // que no dependemos del round-trip ni de la encriptación del id devuelto.
  const [quotePrintTx, setQuotePrintTx] = React.useState<TransactionDetail | null>(null)
  const [isSavingQuote, setIsSavingQuote] = React.useState(false)
  const [showSaveTitleDialog, setShowSaveTitleDialog] = React.useState(false)
  const [saveTitle, setSaveTitle] = React.useState("")

  const config = useCatalogStore((s) => s.config)

  // Modo orden (O1) — el toggle vive acá; modoSoloOrdenes bloquea la vuelta a venta.
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: registerConfigData } = usePosRegisterConfig(activeRegisterId)
  const modoSoloOrdenes = registerConfigData?.config?.modoSoloOrdenes ?? false
  const posMode = useCartStore((s) => s.posMode)
  const setPosMode = useCartStore((s) => s.setPosMode)

  // Selectors for icon active state.
  const note = useCartStore((s) => s.note)
  const cartLines = useCartStore((s) => s.lines)
  const cartTags = useCartStore((s) => s.tags)
  const saleDiscount = useCartStore((s) => s.saleDiscount)

  const hasGlobalDiscount = saleDiscount !== null

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

  const handleSaveAsQuote = async () => {
    const { lines, customer, note: cartNote, tags } = useCartStore.getState()
    if (lines.length === 0) {
      toast.error("No hay ítems para guardar")
      return
    }
    setOpen(false)
    setIsSavingQuote(true)
    try {
      const result = await createQuote({ lines, customer, userId: null, note: cartNote, tags })

      // Construir el preview desde el snapshot del carrito ANTES de limpiarlo.
      // Mismo cálculo que createQuote (total línea = qty * unitPrice, sin aplicar
      // descuento de línea — paridad con lo que el backend persiste) para que el
      // preview refleje exactamente la cotización guardada.
      const previewTotal = lines.reduce((s, l) => s + l.qty * l.unitPrice, 0)
      const previewTx: TransactionDetail = {
        transactionId: result.transactionId,
        customerId: customer?.id ?? "",
        customerName: customer?.name ?? "",
        name: "Quote",
        type: "9",
        status: "1",
        date: new Date().toISOString().slice(0, 19).replace("T", " "),
        documentNo: String(result.transactionNo),
        invoicePrefix: "PRES",
        total: String(previewTotal),
        discount: "0",
        note: cartNote ?? "",
        tags: "",
        transactionDatas: lines.map((l) => ({
          itemId: l.itemId,
          name: l.name,
          count: l.qty,
          price: l.unitPrice,
          total: l.qty * l.unitPrice,
          discount: 0,
          totalDiscount: 0,
          note: l.note ?? "",
          sku: "",
          status: 1,
        })),
        pMethods: [],
      }

      useCartStore.getState().clear()
      toast.success(`Cotización #${result.transactionNo} guardada`)
      setQuotePrintTx(previewTx)
    } catch (e) {
      toast.error("No se pudo guardar la cotización", {
        description: e instanceof Error ? e.message : String(e),
      })
    } finally {
      setIsSavingQuote(false)
    }
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
    ...(hasGlobalDiscount ? [{
      key: "clear-discount",
      label: "Quitar descuento",
      icon: XCircle as LucideIcon,
      action: () => {
        useCartStore.getState().clearSaleDiscount()
        toast.success("Descuento de venta eliminado")
        setOpen(false)
      },
    }] : []),
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
      action: () => openDialog("tags"),
      active: cartTags.length > 0,
    },
    {
      key: "save",
      label: "Guardar",
      icon: Save,
      action: () => {
        setOpen(false)
        setShowSaveTitleDialog(true)
      },
    },
    {
      key: "quote",
      label: "Cotización",
      icon: FileText,
      action: () => {
        void handleSaveAsQuote()
      },
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
      active: posMode === "orden",
      action: () => {
        if (posMode === "orden") return // ya activo — usar "Volver a venta" para salir
        setPosMode("orden")
        setOpen(false)
        toast.success("Modo orden activado — el botón principal ahora envía a cocina")
      },
    },
    ...(posMode === "orden" && !modoSoloOrdenes
      ? [{
          key: "back-to-venta",
          label: "Volver a venta",
          icon: Undo2 as LucideIcon,
          action: () => {
            setPosMode("venta")
            setOpen(false)
            toast.success("Modo venta activado")
          },
        }]
      : []),
    {
      key: "priceList",
      label: "Lista de precios",
      icon: Tags,
      action: () => openDialog("priceList"),
    },
  ]

  // ── Guardar venta en curso ─────────────────────────────────────────────────

  const saveParked = useSaveParkedSale()

  const handleSave = (title?: string | null) => {
    const { lines, customer, note } = useCartStore.getState()
    if (lines.length === 0) {
      toast.error("No hay ítems para guardar")
      return
    }
    saveParked.mutate(
      { data: { cart: lines, customer, notes: note, title: title ?? null } },
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
                  disabled={opt.key === "quote" && isSavingQuote}
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

      <SellerPickerDialog
        open={activeDialog === "user"}
        onOpenChange={(v) => { if (!v) closeDialog() }}
        onSelect={(userId) => {
          if (!userId) return
          const lines = useCartStore.getState().lines
          lines.forEach((l) => useCartStore.getState().setLineSeller(l.lineId, userId))
          closeDialog()
        }}
      />

      <PriceListDialog
        open={activeDialog === "priceList"}
        onClose={closeDialog}
      />

      <TagsDialog open={activeDialog === "tags"} onClose={closeDialog} />

      {quotePrintTx && (
        <QuotePrintViewDialog
          tx={quotePrintTx}
          config={config}
          open={Boolean(quotePrintTx)}
          onOpenChange={(v) => { if (!v) setQuotePrintTx(null) }}
        />
      )}

      <Dialog open={showSaveTitleDialog} onOpenChange={(v) => { if (!v) { setShowSaveTitleDialog(false); setSaveTitle("") } }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-2xl font-semibold">Guardar venta</DialogTitle>
          </DialogHeader>
          <form onSubmit={(e) => { e.preventDefault(); setShowSaveTitleDialog(false); handleSave(saveTitle.trim() || null); setSaveTitle("") }}>
            <div className="py-2">
              <Input
                placeholder="Ej. Mesa 5, Pedido Juan..."
                value={saveTitle}
                onChange={(e) => setSaveTitle(e.target.value)}
                autoFocus
              />
            </div>
            <DialogFooter className="mt-4">
              <Button type="button" variant="outline" onClick={() => { setShowSaveTitleDialog(false); setSaveTitle("") }}>
                Cancelar
              </Button>
              <Button type="submit">
                Guardar
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
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
  disabled,
  onClick,
}: {
  label: string
  icon: LucideIcon
  stub?: boolean
  destructive?: boolean
  active?: boolean
  disabled?: boolean
  onClick: () => void
}) {
  const isDisabled = stub || disabled
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={isDisabled}
      title={stub ? "Próximamente" : undefined}
      className={cn(
        "flex items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[15px] transition-colors",
        destructive
          ? "text-destructive hover:bg-destructive/10"
          : isDisabled
            ? "cursor-not-allowed text-muted-foreground/50"
            : "text-foreground hover:bg-muted",
      )}
    >
      <Icon
        className={cn(
          "size-5 shrink-0",
          destructive
            ? "text-destructive"
            : isDisabled
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

  const handleShiftToggle = () => {
    const next = mode === "money" ? "percent" : "money"
    // Si al pasar a porcentaje el draft actual excede 100, resetear
    if (next === "percent" && parseFloat(value) > 100) {
      setValue("0")
    }
    onModeToggle()
  }

  const handleConfirm = () => {
    const num = parseFloat(value)
    // Clampear a 0-100 cuando es porcentaje
    const clamped = mode === "percent" ? Math.min(100, Math.max(0, num)) : num
    if (!isNaN(clamped) && clamped > 0) {
      useCartStore.getState().setSaleDiscount(clamped, mode)
      toast.success(
        mode === "percent"
          ? `Descuento del ${clamped}% aplicado`
          : `Descuento de ${clamped} aplicado`,
      )
    }
    setValue("0")
    onClose()
  }

  React.useEffect(() => {
    if (open) setValue("0")
  }, [open])

  return (
    <NumericPadDialog
      open={open}
      onClose={onClose}
      title="Descuento global"
      mode={mode === "percent" ? "percent" : "money"}
      value={value}
      onValueChange={setValue}
      onShiftToggle={handleShiftToggle}
      onConfirm={handleConfirm}
      confirmLabel="Aplicar"
    />
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

// ── Dialog de lista de precios ────────────────────────────────────────────────

function PriceListDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const { data: lists } = usePriceLists({ client: posApi })
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
        <Select
          value={selected || "__none__"}
          onValueChange={(v) => setSelected(v === "__none__" ? "" : v)}
        >
          <SelectTrigger>
            <SelectValue placeholder="Precios estándar" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__none__">Precios estándar</SelectItem>
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

// ── Dialog de etiquetas ───────────────────────────────────────────────────────

function TagsDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const currentTags = useCartStore((s) => s.tags)
  const { data } = useTags()
  const suggestions = (data?.tags ?? []).map((t) => t.name)

  const [chips, setChips] = React.useState<string[]>([])
  const [inputValue, setInputValue] = React.useState("")
  const inputRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    if (open) {
      setChips(currentTags)
      setInputValue("")
    }
  }, [open, currentTags])

  const addChip = (raw: string) => {
    const value = raw.trim()
    if (!value) return
    if (!chips.includes(value)) {
      setChips((prev) => [...prev, value])
    }
    setInputValue("")
  }

  const removeChip = (chip: string) => {
    setChips((prev) => prev.filter((c) => c !== chip))
  }

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" || e.key === ",") {
      e.preventDefault()
      addChip(inputValue)
    } else if (e.key === "Backspace" && inputValue === "") {
      setChips((prev) => prev.slice(0, -1))
    } else if (e.key === "Escape") {
      onClose()
    }
  }

  const handleConfirm = () => {
    const pending = inputValue.trim()
    const finalChips = pending && !chips.includes(pending)
      ? [...chips, pending]
      : chips
    useCartStore.getState().setTags(finalChips)
    onClose()
  }

  const filteredSuggestions = inputValue.trim().length > 0
    ? suggestions.filter(
        (s) =>
          s.toLowerCase().includes(inputValue.toLowerCase()) &&
          !chips.includes(s),
      )
    : []

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose() }}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Etiquetas de la venta</DialogTitle>
        </DialogHeader>

        <div
          className="flex min-h-[2.5rem] flex-wrap gap-1.5 rounded-md border border-input bg-background px-3 py-2 cursor-text"
          onClick={() => inputRef.current?.focus()}
        >
          {chips.map((chip) => (
            <Badge
              key={chip}
              variant="secondary"
              className="flex items-center gap-1 pr-1"
            >
              {chip}
              <button
                type="button"
                onClick={(e) => { e.stopPropagation(); removeChip(chip) }}
                aria-label={`Quitar ${chip}`}
                className="ml-0.5 flex size-3.5 items-center justify-center rounded-full hover:bg-muted-foreground/20"
              >
                <X className="size-2.5" />
              </button>
            </Badge>
          ))}
          <Input
            ref={inputRef}
            value={inputValue}
            onChange={(e) => setInputValue(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={chips.length === 0 ? "Escribí una etiqueta y presioná Enter..." : ""}
            className="h-auto min-w-[8rem] flex-1 border-0 bg-transparent p-0 text-sm shadow-none focus-visible:ring-0"
          />
        </div>

        {filteredSuggestions.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {filteredSuggestions.slice(0, 8).map((s) => (
              <button
                type="button"
                key={s}
                onClick={() => addChip(s)}
                className="rounded-full border border-border px-2.5 py-0.5 text-xs text-foreground transition-colors hover:bg-muted"
              >
                {s}
              </button>
            ))}
          </div>
        )}

        <p className="text-xs text-muted-foreground">
          Enter o coma para agregar. Backspace sobre campo vacío elimina la última.
        </p>

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
