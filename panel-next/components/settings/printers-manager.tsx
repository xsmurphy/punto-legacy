"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Check, X, Printer, Trash2, PrinterCheck, Pencil, ChevronsUpDown } from "lucide-react"
import { toast } from "sonner"

import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { cn } from "@/lib/utils"

import { useDocumentTemplates } from "@/hooks/use-document-templates"
import { useCategories } from "@/hooks/use-categories"
import { useRegistersAdmin } from "@/hooks/use-registers-admin"
import {
  usePrinterBindings,
  useCreatePrinterBinding,
  useUpdatePrinterBinding,
  useDeletePrinterBinding,
} from "@/hooks/use-printer-bindings"

import {
  isWebUsbSupported,
  requestUsbPrinter,
  printTest,
  type PrinterBinding,
  type PrinterDocType,
  type PrinterMode,
} from "@/lib/hardware/printers"

// ── Helpers ───────────────────────────────────────────────────────────────────

const DOC_TYPE_LABELS: Record<PrinterDocType, { long: string; short: string }> = {
  receipt:  { long: "Recibo / Ticket",          short: "Recibo" },
  quote:    { long: "Presupuesto / Cotización",  short: "Presupuesto" },
  order:    { long: "Pedido / Comanda",          short: "Pedido" },
  withdraw: { long: "Retiro de caja",            short: "Retiro" },
  delivery: { long: "Remito",                    short: "Remito" },
  closeReg: { long: "Cierre de caja",            short: "Cierre" },
  return:   { long: "Devolución",               short: "Devolución" },
}

const ALL_DOC_TYPES: PrinterDocType[] = [
  "receipt",
  "quote",
  "order",
  "withdraw",
  "delivery",
  "closeReg",
  "return",
]

// ── SimpleCategoriesPicker ────────────────────────────────────────────────────

interface SimpleCategoriesPickerProps {
  options: { id: string; name: string }[]
  value: string[]
  onChange: (next: string[]) => void
}

function SimpleCategoriesPicker({ options, value, onChange }: SimpleCategoriesPickerProps) {
  const [open, setOpen] = React.useState(false)
  const selectedSet = React.useMemo(() => new Set(value), [value])

  const toggle = (id: string) => {
    if (selectedSet.has(id)) {
      onChange(value.filter((v) => v !== id))
    } else {
      onChange([...value, id])
    }
  }

  const optionsById = React.useMemo(
    () => new Map(options.map((o) => [o.id, o])),
    [options],
  )

  return (
    <div className="space-y-2">
      {value.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {value.map((id) => {
            const opt = optionsById.get(id)
            if (!opt) return null
            return (
              <Badge key={id} variant="secondary" className="gap-1 pr-1">
                {opt.name}
                <button
                  type="button"
                  onClick={() => toggle(id)}
                  className="rounded p-0.5 hover:bg-foreground/10"
                  aria-label="Quitar"
                >
                  <X className="size-3" />
                </button>
              </Badge>
            )
          })}
        </div>
      )}

      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            className="w-full justify-between font-normal"
          >
            <span className="truncate text-muted-foreground">
              {value.length > 0
                ? `${value.length} ${value.length === 1 ? "categoría seleccionada" : "categorías seleccionadas"}`
                : "Agregar categorías…"}
            </span>
            <ChevronsUpDown className="size-4 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
          <Command>
            <CommandInput placeholder="Buscar categoría…" />
            <CommandList>
              <CommandEmpty>Sin resultados.</CommandEmpty>
              <CommandGroup>
                {options.map((opt) => {
                  const checked = selectedSet.has(opt.id)
                  return (
                    <CommandItem key={opt.id} value={opt.name} onSelect={() => toggle(opt.id)}>
                      <div
                        className={cn(
                          "flex size-4 items-center justify-center rounded-sm border",
                          checked
                            ? "border-foreground bg-foreground text-background"
                            : "border-foreground/30",
                        )}
                      >
                        {checked && <Check className="size-3" />}
                      </div>
                      <span>{opt.name}</span>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}

// ── BindingDialog ─────────────────────────────────────────────────────────────

type DialogMode =
  | { type: "new"; device: USBDevice }
  | { type: "edit"; binding: PrinterBinding }
  | null

interface BindingDialogProps {
  mode: DialogMode
  onClose: () => void
  onSave: (data: Omit<PrinterBinding, "id" | "createdAt" | "updatedAt">) => void
}

function BindingDialog({ mode, onClose, onSave }: BindingDialogProps) {
  const { data: templatesData } = useDocumentTemplates()
  const { data: categoriesData } = useCategories()

  const [name, setName] = React.useState("")
  const [color, setColor] = React.useState("#7bd148")
  const [printerMode, setPrinterMode] = React.useState<PrinterMode>("escpos")
  const [templateId, setTemplateId] = React.useState<string>("")
  const [paperWidthMm, setPaperWidthMm] = React.useState<58 | 80>(80)
  const [copies, setCopies] = React.useState(1)
  const [openDrawer, setOpenDrawer] = React.useState(false)
  const [autoPrint, setAutoPrint] = React.useState(false)
  const [printDelay, setPrintDelay] = React.useState(0)
  const [categoryIds, setCategoryIds] = React.useState<string[]>([])
  const [docTypes, setDocTypes] = React.useState<PrinterDocType[]>(["receipt"])
  const [vendorId, setVendorId] = React.useState(0)
  const [productId, setProductId] = React.useState(0)
  const [deviceLabel, setDeviceLabel] = React.useState("")
  const [error, setError] = React.useState<string | null>(null)

  React.useEffect(() => {
    if (!mode) return
    setError(null)
    if (mode.type === "new") {
      const { device } = mode
      setName(device.productName?.trim() || "Impresora")
      setColor("#7bd148")
      setPrinterMode("escpos")
      setTemplateId("")
      setPaperWidthMm(80)
      setCopies(1)
      setOpenDrawer(false)
      setAutoPrint(false)
      setPrintDelay(0)
      setCategoryIds([])
      setDocTypes(["receipt"])
      setVendorId(device.vendorId)
      setProductId(device.productId)
      setDeviceLabel(device.productName?.trim() || "")
    } else {
      const { binding } = mode
      setName(binding.name)
      setColor(binding.color)
      setPrinterMode(binding.mode)
      setTemplateId(binding.templateId ?? "")
      setPaperWidthMm(binding.paperWidthMm)
      setCopies(binding.copies)
      setOpenDrawer(binding.openDrawer)
      setAutoPrint(binding.autoPrint)
      setPrintDelay(binding.printDelay)
      setCategoryIds(Array.isArray(binding.categoryIds) ? binding.categoryIds : [])
      setDocTypes(Array.isArray(binding.docTypes) ? binding.docTypes : [])
      setVendorId(binding.vendorId ?? 0)
      setProductId(binding.productId ?? 0)
      setDeviceLabel(binding.deviceLabel ?? "")
    }
  }, [mode])

  function handleSave() {
    if (!name.trim()) {
      setError("El nombre es obligatorio")
      return
    }
    if (docTypes.length === 0) {
      setError("Seleccioná al menos un tipo de documento")
      return
    }
    if (copies < 1) {
      setError("Las copias deben ser al menos 1")
      return
    }
    setError(null)
    onSave({
      name: name.trim(),
      color,
      transport: "usb",
      vendorId,
      productId,
      deviceLabel,
      mode: printerMode,
      templateId: templateId === "" ? null : templateId,
      paperWidthMm,
      copies,
      openDrawer,
      autoPrint,
      printDelay,
      categoryIds,
      docTypes,
    })
  }

  return (
    <Dialog open={mode !== null} onOpenChange={(o) => { if (!o) onClose() }}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {mode?.type === "edit" ? "Editar impresora" : "Configurar impresora"}
          </DialogTitle>
        </DialogHeader>

        <Tabs defaultValue="general">
          <TabsList className="grid w-full grid-cols-3">
            <TabsTrigger value="general">General</TabsTrigger>
            <TabsTrigger value="behavior">Comportamiento</TabsTrigger>
            <TabsTrigger value="categories">Categorías</TabsTrigger>
          </TabsList>

          <TabsContent value="general" className="space-y-4 pt-4">
            <div className="space-y-1.5">
              <Label htmlFor="printer-name">Nombre</Label>
              <Input
                id="printer-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Ej. Epson TM-T20III"
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="printer-color">Color identificador</Label>
              <input
                type="color"
                id="printer-color"
                value={color}
                onChange={(e) => setColor(e.target.value)}
                className="h-9 w-16 cursor-pointer rounded-md border border-input bg-transparent p-1"
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="printer-template">Plantilla</Label>
              <Select value={templateId} onValueChange={setTemplateId}>
                <SelectTrigger id="printer-template">
                  <SelectValue placeholder="Predeterminada del sistema" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">Predeterminada del sistema</SelectItem>
                  {(templatesData?.templates ?? []).map((t) => (
                    <SelectItem key={t.templateId} value={t.templateId}>
                      {t.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="printer-mode">Modo</Label>
              <Select
                value={printerMode}
                onValueChange={(v) => setPrinterMode(v as PrinterMode)}
              >
                <SelectTrigger id="printer-mode">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="escpos">ESC/POS (térmica)</SelectItem>
                  <SelectItem value="native">Nativa (window.print)</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {printerMode === "escpos" && (
              <div className="space-y-1.5">
                <Label htmlFor="printer-width">Ancho del papel</Label>
                <Select
                  value={String(paperWidthMm)}
                  onValueChange={(v) => setPaperWidthMm(Number(v) as 58 | 80)}
                >
                  <SelectTrigger id="printer-width">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="80">80 mm</SelectItem>
                    <SelectItem value="58">58 mm</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="printer-copies">Copias</Label>
              <Input
                id="printer-copies"
                type="number"
                min={1}
                max={10}
                value={copies}
                onChange={(e) => setCopies(Math.max(1, Math.min(10, Number(e.target.value))))}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="printer-delay">Delay entre impresiones (ms)</Label>
              <Input
                id="printer-delay"
                type="number"
                min={0}
                value={printDelay}
                onChange={(e) => setPrintDelay(Math.max(0, Number(e.target.value)))}
              />
            </div>
          </TabsContent>

          <TabsContent value="behavior" className="space-y-4 pt-4">
            <div className="flex items-center gap-3">
              <Switch id="auto-print" checked={autoPrint} onCheckedChange={setAutoPrint} />
              <Label htmlFor="auto-print">Auto-imprimir al cerrar venta</Label>
            </div>

            <div className="flex items-center gap-3">
              <Switch id="open-drawer" checked={openDrawer} onCheckedChange={setOpenDrawer} />
              <Label htmlFor="open-drawer">Abrir cajón monedero al imprimir</Label>
            </div>

            <div className="space-y-2">
              <p className="text-sm font-medium">Tipos de documento</p>
              <p className="text-xs text-muted-foreground">Seleccioná al menos un tipo</p>
              <div className="grid grid-cols-2 gap-2">
                {ALL_DOC_TYPES.map((dt) => (
                  <div key={dt} className="flex items-center gap-2">
                    <Checkbox
                      id={`dt-${dt}`}
                      checked={docTypes.includes(dt)}
                      onCheckedChange={(checked) => {
                        if (checked) setDocTypes((prev) => [...prev, dt])
                        else setDocTypes((prev) => prev.filter((d) => d !== dt))
                      }}
                    />
                    <Label htmlFor={`dt-${dt}`} className="text-sm font-normal">
                      {DOC_TYPE_LABELS[dt].long}
                    </Label>
                  </div>
                ))}
              </div>
            </div>
          </TabsContent>

          <TabsContent value="categories" className="space-y-4 pt-4">
            <p className="text-sm text-muted-foreground">
              Si seleccionás categorías, esta impresora solo imprimirá ítems de venta con esas
              categorías. Útil para barra (solo tragos) o cocina (solo comida). Dejá vacío para
              imprimir todo.
            </p>
            <SimpleCategoriesPicker
              options={categoriesData?.categories ?? []}
              value={categoryIds}
              onChange={setCategoryIds}
            />
          </TabsContent>
        </Tabs>

        {error && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={handleSave}>Guardar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── PrintersManager ───────────────────────────────────────────────────────────

interface PrintersManagerProps {
  /** Si viene, fuerza esa caja y oculta el selector. Usado desde el POS. */
  registerId?: string
}

export function PrintersManager({ registerId: forcedRegisterId }: PrintersManagerProps = {}) {
  const { data: registersData } = useRegistersAdmin()
  const registers = registersData?.registers ?? []

  const [internalRegisterId, setInternalRegisterId] = React.useState<string>("")
  const selectedRegisterId = forcedRegisterId ?? internalRegisterId
  const setSelectedRegisterId = setInternalRegisterId
  const showRegisterSelector = forcedRegisterId === undefined

  const { data: bindingsData, isLoading: bindingsLoading } = usePrinterBindings(
    selectedRegisterId || undefined,
  )
  const createMutation = useCreatePrinterBinding(selectedRegisterId || undefined)
  const updateMutation = useUpdatePrinterBinding(selectedRegisterId || undefined)
  const deleteMutation = useDeletePrinterBinding(selectedRegisterId || undefined)

  const bindings = bindingsData?.bindings ?? []

  const [dialogMode, setDialogMode] = React.useState<DialogMode>(null)
  const [deleteTarget, setDeleteTarget] = React.useState<PrinterBinding | null>(null)
  const [testingId, setTestingId] = React.useState<string | null>(null)

  async function handleRequestDevice() {
    if (!selectedRegisterId) {
      toast.error("Seleccioná una caja primero.")
      return
    }
    try {
      const device = await requestUsbPrinter()
      setDialogMode({ type: "new", device })
    } catch (err) {
      if (err instanceof DOMException && err.name === "NotAllowedError") return
      toast.error("No se pudo acceder a la impresora.", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  function handleSave(data: Omit<PrinterBinding, "id" | "createdAt" | "updatedAt">) {
    if (!dialogMode || !selectedRegisterId) return
    if (dialogMode.type === "new") {
      createMutation.mutate(
        { registerId: selectedRegisterId, ...data },
        {
          onSuccess: () => {
            toast.success("Impresora vinculada correctamente.")
            setDialogMode(null)
          },
          onError: (e) => toast.error(e.message),
        },
      )
    } else {
      updateMutation.mutate(
        { id: dialogMode.binding.id, ...data },
        {
          onSuccess: () => {
            toast.success("Impresora actualizada.")
            setDialogMode(null)
          },
          onError: (e) => toast.error(e.message),
        },
      )
    }
  }

  async function handlePrintTest(binding: PrinterBinding) {
    setTestingId(binding.id)
    try {
      await printTest(binding)
      toast.success("Ticket de prueba enviado.")
    } catch (err) {
      toast.error("No se pudo imprimir.", {
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setTestingId(null)
    }
  }

  const columns = React.useMemo<ColumnDef<PrinterBinding>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => {
          const b = row.original
          return (
            <div className="flex items-center">
              <span
                className="inline-block size-2.5 rounded-full mr-2 flex-shrink-0"
                style={{ backgroundColor: b.color }}
              />
              <span className="font-medium">{b.name}</span>
            </div>
          )
        },
      },
      {
        id: "mode",
        header: "Modo",
        cell: ({ row }) => (
          <Badge variant={row.original.mode === "escpos" ? "secondary" : "outline"}>
            {row.original.mode === "escpos" ? "ESC/POS" : "Nativa"}
          </Badge>
        ),
      },
      {
        id: "docTypes",
        header: "Documentos",
        cell: ({ row }) => {
          const dts = Array.isArray(row.original.docTypes) ? row.original.docTypes : []
          return dts.map((dt) => DOC_TYPE_LABELS[dt]?.short ?? dt).join(" · ")
        },
      },
      {
        id: "categories",
        header: "Categorías",
        cell: ({ row }) => {
          const n = Array.isArray(row.original.categoryIds) ? row.original.categoryIds.length : 0
          return <Badge variant="secondary">{n === 0 ? "Todas" : `${n} categ.`}</Badge>
        },
      },
      {
        id: "autoPrint",
        header: "Auto",
        cell: ({ row }) =>
          row.original.autoPrint ? (
            <Check className="size-4 text-green-600" />
          ) : (
            <X className="size-4 text-muted-foreground" />
          ),
      },
      {
        id: "openDrawer",
        header: "Cajón",
        cell: ({ row }) =>
          row.original.openDrawer ? (
            <Check className="size-4 text-green-600" />
          ) : (
            <X className="size-4 text-muted-foreground" />
          ),
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => {
          const b = row.original
          return (
            <div className="flex gap-1">
              {b.transport === "usb" && (
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={testingId === b.id}
                  onClick={() => handlePrintTest(b)}
                >
                  <PrinterCheck className="size-4 mr-1.5" />
                  {testingId === b.id ? "Imprimiendo…" : "Prueba"}
                </Button>
              )}
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setDialogMode({ type: "edit", binding: b })}
              >
                <Pencil className="size-4 mr-1.5" />
                Editar
              </Button>
              <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:text-destructive"
                onClick={() => setDeleteTarget(b)}
              >
                <Trash2 className="size-4 mr-1.5" />
                Eliminar
              </Button>
            </div>
          )
        },
      },
    ],
    [testingId],
  )

  const webUsbOk = isWebUsbSupported()

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Impresoras</h1>
          <p className="text-sm text-muted-foreground">
            Vincular impresoras térmicas por USB para tickets, comandas y facturas.
          </p>
        </div>
        {webUsbOk && (
          <Button onClick={handleRequestDevice} disabled={!selectedRegisterId}>
            <Printer className="size-4 mr-1.5" />
            Vincular impresora USB
          </Button>
        )}
      </header>

      {showRegisterSelector && (
        <div className="flex flex-col gap-1.5 max-w-xs">
          <Label htmlFor="register-selector">Caja</Label>
          <Select value={selectedRegisterId} onValueChange={setSelectedRegisterId}>
            <SelectTrigger id="register-selector">
              <SelectValue placeholder="Seleccioná una caja…" />
            </SelectTrigger>
            <SelectContent>
              {registers.map((r) => (
                <SelectItem key={r.id} value={r.id}>
                  {r.name}
                  {r.outletName && (
                    <span className="ml-1.5 text-muted-foreground text-xs">· {r.outletName}</span>
                  )}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {!webUsbOk && (
        <Alert>
          <Printer className="size-4" />
          <AlertDescription>
            Tu navegador no soporta impresión directa por USB. Usá Chrome o Edge en escritorio.
          </AlertDescription>
        </Alert>
      )}

      {!selectedRegisterId ? (
        <EmptyState
          icon={Printer}
          title="Seleccioná una caja"
          description="Elegí una caja para ver y administrar sus impresoras vinculadas."
          showMarquee={false}
        />
      ) : (
        <DataTable
          tableId="printers"
          columns={columns}
          data={bindings}
          isLoading={bindingsLoading}
          searchPlaceholder="Buscar impresora…"
          exportFileName={null}
          emptyMessage={
            <EmptyState
              icon={Printer}
              title="Sin impresoras vinculadas"
              description='Hacé clic en "Vincular impresora USB" para agregar la primera.'
              showMarquee={false}
            />
          }
        />
      )}

      <BindingDialog
        mode={dialogMode}
        onClose={() => setDialogMode(null)}
        onSave={handleSave}
      />

      <AlertDialog
        open={deleteTarget !== null}
        onOpenChange={(o) => { if (!o) setDeleteTarget(null) }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar impresora</AlertDialogTitle>
            <AlertDialogDescription>
              {`¿Eliminar "${deleteTarget?.name}"? El vínculo se borrará de la BD.`}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!deleteTarget) return
                deleteMutation.mutate(deleteTarget.id, {
                  onSuccess: () => {
                    toast.success("Impresora eliminada.")
                    setDeleteTarget(null)
                  },
                })
              }}
            >
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
