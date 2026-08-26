"use client"

import * as React from "react"
import { Check, X, Printer, Trash2, PrinterCheck, Pencil, ChevronsUpDown } from "lucide-react"
import { toast } from "sonner"

import { Card } from "@/components/ui/card"
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
import { EmptyState } from "@/components/empty-state"
import { ColorPicker } from "@/components/ui/color-picker"
import { PALETTE_COLORS, resolveColorBg } from "@/lib/ui/color-palette"
import { cn } from "@/lib/utils"

import type { HttpClient } from "@/lib/api-client"
import { useDocumentTemplates } from "@/hooks/use-document-templates"
import { useCategories } from "@/hooks/use-categories"
import { useRegistersAdmin } from "@/hooks/use-registers-admin"
import { useStationPrinters } from "@/hooks/use-station-printers"
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
  type PrinterTransport,
} from "@/lib/hardware/printers"
import {
  isWebBluetoothSupported,
  requestBluetoothPrinter,
} from "@/lib/hardware/printers/transports/bluetooth"

// ── Helpers ───────────────────────────────────────────────────────────────────

const DOC_TYPE_LABELS: Record<PrinterDocType, { long: string; short: string }> = {
  receipt:  { long: "Recibo / Ticket",          short: "Recibo" },
  factura:  { long: "Factura",                   short: "Factura" },
  quote:    { long: "Presupuesto / Cotización",  short: "Presupuesto" },
  order:    { long: "Pedido / Comanda",          short: "Pedido" },
  withdraw: { long: "Retiro de caja",            short: "Retiro" },
  delivery: { long: "Remito",                    short: "Remito" },
  closeReg: { long: "Cierre de caja",            short: "Cierre" },
  return:   { long: "Devolución",               short: "Devolución" },
}

const ALL_DOC_TYPES: PrinterDocType[] = [
  "receipt",
  "factura",
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
  | { type: "new" }
  | { type: "edit"; binding: PrinterBinding }
  | null

interface BindingDialogProps {
  mode: DialogMode
  /** Sucursal de la caja seleccionada — filtra el picker de impresoras de
   *  estación (transport "station"). undefined mientras no hay caja elegida. */
  outletId: string | undefined
  onClose: () => void
  onSave: (data: Omit<PrinterBinding, "id" | "createdAt" | "updatedAt">) => void
}

function BindingDialog({ mode, outletId, onClose, onSave }: BindingDialogProps) {
  const { data: templatesData } = useDocumentTemplates()
  const { data: categoriesData } = useCategories()
  const { data: stationPrintersData } = useStationPrinters(outletId)
  const activeStationPrinters = (stationPrintersData?.printers ?? []).filter((p) => p.status === 1)

  const [name, setName] = React.useState("")
  const [color, setColor] = React.useState<string>(PALETTE_COLORS[0].key)
  const [printerMode, setPrinterMode] = React.useState<PrinterMode>("escpos")
  const [templateId, setTemplateId] = React.useState<string>("")
  const [paperWidthMm, setPaperWidthMm] = React.useState<58 | 80>(80)
  const [copies, setCopies] = React.useState(1)
  const [openDrawer, setOpenDrawer] = React.useState(false)
  const [autoPrint, setAutoPrint] = React.useState(false)
  const [printDelay, setPrintDelay] = React.useState(0)
  const [categoryIds, setCategoryIds] = React.useState<string[]>([])
  const [docTypes, setDocTypes] = React.useState<PrinterDocType[]>(["receipt"])
  const [error, setError] = React.useState<string | null>(null)

  // Transport state
  const [transport, setTransport] = React.useState<PrinterTransport>("native")
  const [vendorId, setVendorId] = React.useState<number | null>(null)
  const [productId, setProductId] = React.useState<number | null>(null)
  const [deviceLabel, setDeviceLabel] = React.useState("")
  const [bluetoothDeviceId, setBluetoothDeviceId] = React.useState<string | null>(null)
  const [bluetoothLabel, setBluetoothLabel] = React.useState("")
  const [networkHost, setNetworkHost] = React.useState("")
  const [networkPort, setNetworkPort] = React.useState(9100)
  const [stationPrinterId, setStationPrinterId] = React.useState<string | null>(null)

  React.useEffect(() => {
    if (!mode) return
    setError(null)
    if (mode.type === "new") {
      setName("Impresora")
      setColor(PALETTE_COLORS[0].key)
      setPrinterMode("escpos")
      setTemplateId("")
      setPaperWidthMm(80)
      setCopies(1)
      setOpenDrawer(false)
      setAutoPrint(false)
      setPrintDelay(0)
      setCategoryIds([])
      setDocTypes(["receipt"])
      setTransport("native")
      setVendorId(null)
      setProductId(null)
      setDeviceLabel("")
      setBluetoothDeviceId(null)
      setBluetoothLabel("")
      setNetworkHost("")
      setNetworkPort(9100)
      setStationPrinterId(null)
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
      setTransport(binding.transport)
      setVendorId(binding.vendorId ?? null)
      setProductId(binding.productId ?? null)
      setDeviceLabel(binding.deviceLabel ?? "")
      setBluetoothDeviceId(binding.bluetoothDeviceId ?? null)
      setBluetoothLabel("")
      setNetworkHost(binding.networkHost ?? "")
      setNetworkPort(binding.networkPort ?? 9100)
      setStationPrinterId(binding.stationPrinterId ?? null)
    }
  }, [mode])

  function handleSave() {
    if (!name.trim()) { setError("El nombre es obligatorio"); return }
    if (docTypes.length === 0) { setError("Seleccioná al menos un tipo de documento"); return }
    if (copies < 1) { setError("Las copias deben ser al menos 1"); return }
    if (transport === "usb" && (vendorId == null || productId == null)) {
      setError("Vinculá una impresora USB antes de guardar"); return
    }
    if (transport === "bluetooth" && !bluetoothDeviceId) {
      setError("Vinculá una impresora Bluetooth antes de guardar"); return
    }
    if (transport === "network" && !networkHost.trim()) {
      setError("Ingresá el host o IP de la impresora de red"); return
    }
    if (transport === "station" && !stationPrinterId) {
      setError("Seleccioná una impresora de estación"); return
    }
    setError(null)
    onSave({
      name: name.trim(),
      color,
      transport,
      vendorId: transport === "usb" ? vendorId : null,
      productId: transport === "usb" ? productId : null,
      deviceLabel: transport === "usb" ? deviceLabel || null : null,
      bluetoothDeviceId: transport === "bluetooth" ? bluetoothDeviceId : null,
      networkHost: transport === "network" ? networkHost.trim() : null,
      networkPort: transport === "network" ? networkPort : null,
      stationPrinterId: transport === "station" ? stationPrinterId : null,
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
      {/* `mobileFullscreen`: en un teléfono este form (4 tabs, inputs y selects
          a ancho completo) no entra en el modal centrado y quedaba con scroll
          HORIZONTAL, con el contenido saliéndose por el borde derecho (reporte
          del owner 2026-08-26). Fullscreen bajo `sm` = el content toma el ancho
          entero del dispositivo y el scroll pasa a ser vertical, que es lo que
          corresponde a un formulario largo. Mismo patrón que los diálogos
          densos del POS (pos-transactions, pos-main-menu). En desktop queda el
          modal `max-w-2xl` de siempre. */}
      <DialogContent mobileFullscreen className="sm:max-w-2xl max-sm:overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">
            {mode?.type === "edit" ? "Editar impresora" : "Agregar impresora"}
          </DialogTitle>
        </DialogHeader>

        <Tabs defaultValue="general" className="mt-2 w-full min-w-0">
          {/* El scroll vive DENTRO de la píldora, no en un wrapper de afuera.
              Con el `overflow-x-auto` en un div externo, la `TabsList` seguía
              creciendo con su contenido (`w-fit`) y lo que se desplazaba era la
              píldora gris ENTERA, saliéndose del modal. Lo que tiene que quedar
              fijo es la píldora —ancho del contenedor, esquinas redondeadas— y
              lo que scrollea son los triggers de adentro (diagnóstico del owner
              2026-08-26). De ahí `w-full` + `overflow-x-auto` en la lista y
              `shrink-0` en cada trigger, que si no se comprimen para entrar.
              `min-w-0` en el Tabs raíz evita que el ancho intrínseco de la tira
              empuje el formulario. */}
          <TabsList className="w-full justify-start gap-1 overflow-x-auto sm:gap-0 [&::-webkit-scrollbar]:hidden">
            <TabsTrigger value="general" className="shrink-0">General</TabsTrigger>
            <TabsTrigger value="behavior" className="shrink-0">Comportamiento</TabsTrigger>
            <TabsTrigger value="categories" className="shrink-0">Categorías</TabsTrigger>
            <TabsTrigger value="device" className="shrink-0">Dispositivo</TabsTrigger>
          </TabsList>

          <TabsContent value="general" className="mt-6 flex min-w-0 flex-col gap-4">
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
              <Label>Color identificador</Label>
              <ColorPicker value={color} onChange={setColor} />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="printer-template">Plantilla</Label>
              <Select
                value={templateId === "" ? "__none__" : templateId}
                onValueChange={(v) => setTemplateId(v === "__none__" ? "" : v)}
              >
                <SelectTrigger id="printer-template">
                  <SelectValue placeholder="Predeterminada del sistema" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">Predeterminada del sistema</SelectItem>
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
                  <SelectItem value="escpos">Impresora térmica</SelectItem>
                  <SelectItem value="native">Diálogo del navegador</SelectItem>
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

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                <p className="text-xs text-muted-foreground">Entre 1 y 10.</p>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="printer-delay">Delay entre copias (ms)</Label>
                <Input
                  id="printer-delay"
                  type="number"
                  min={0}
                  value={printDelay}
                  onChange={(e) => setPrintDelay(Math.max(0, Number(e.target.value)))}
                />
                <p className="text-xs text-muted-foreground">0 = sin pausa.</p>
              </div>
            </div>
          </TabsContent>

          <TabsContent value="behavior" className="mt-6 flex min-w-0 flex-col gap-4">
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

          <TabsContent value="categories" className="mt-6 flex min-w-0 flex-col gap-4">
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

          <TabsContent value="device" className="mt-6 flex min-w-0 flex-col gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="printer-transport">Tipo de dispositivo</Label>
              <Select value={transport} onValueChange={(v) => setTransport(v as PrinterTransport)}>
                <SelectTrigger id="printer-transport"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="native">Sin dispositivo (ventana del sistema)</SelectItem>
                  <SelectItem value="usb">USB</SelectItem>
                  <SelectItem value="bluetooth">Bluetooth</SelectItem>
                  <SelectItem value="network">Red (IP/hostname)</SelectItem>
                  <SelectItem value="station">Servidor de impresión</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {transport === "station" && (
              <div className="space-y-3">
                {activeStationPrinters.length > 0 ? (
                  <div className="space-y-1.5">
                    <Label htmlFor="printer-station">Impresora de estación</Label>
                    <Select
                      value={stationPrinterId ?? ""}
                      onValueChange={(v) => setStationPrinterId(v)}
                    >
                      <SelectTrigger id="printer-station">
                        <SelectValue placeholder="Seleccioná una impresora…" />
                      </SelectTrigger>
                      <SelectContent>
                        {activeStationPrinters.map((p) => (
                          <SelectItem key={p.id} value={p.id}>
                            {p.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                ) : (
                  <Alert>
                    <AlertDescription>
                      Configurá impresoras en la Estación de Impresión primero.
                    </AlertDescription>
                  </Alert>
                )}
              </div>
            )}

            {transport === "native" && (
              <Alert>
                <AlertDescription>
                  Usará el diálogo de impresión del navegador. Útil para hojas A4, cotizaciones e impresoras instaladas en el sistema operativo.
                </AlertDescription>
              </Alert>
            )}

            {transport === "usb" && (
              <div className="space-y-3">
                {isWebUsbSupported() ? (
                  <>
                    <Button
                      type="button"
                      variant="outline"
                      onClick={async () => {
                        try {
                          const device = await requestUsbPrinter()
                          setVendorId(device.vendorId)
                          setProductId(device.productId)
                          setDeviceLabel(device.productName?.trim() || `USB ${device.vendorId}:${device.productId}`)
                        } catch (err) {
                          if (err instanceof DOMException && err.name === "NotAllowedError") return
                          toast.error("No se pudo acceder a la impresora.", {
                            description: err instanceof Error ? err.message : undefined,
                          })
                        }
                      }}
                    >
                      <Printer className="size-4 mr-1.5" />
                      Vincular impresora USB
                    </Button>
                    {vendorId != null && (
                      <p className="text-sm text-muted-foreground">
                        Vinculada: {deviceLabel || `USB ${vendorId}:${productId}`}
                      </p>
                    )}
                  </>
                ) : (
                  <Alert>
                    <AlertDescription>
                      WebUSB no está disponible en este navegador. Usá Chrome o Edge en escritorio.
                    </AlertDescription>
                  </Alert>
                )}
              </div>
            )}

            {transport === "bluetooth" && (
              <div className="space-y-3">
                {isWebBluetoothSupported() ? (
                  <>
                    <Button
                      type="button"
                      variant="outline"
                      onClick={async () => {
                        try {
                          const device = await requestBluetoothPrinter()
                          setBluetoothDeviceId(device.id)
                          setBluetoothLabel(device.name ?? device.id)
                        } catch (err) {
                          if (err instanceof DOMException && err.name === "NotAllowedError") return
                          toast.error("No se pudo acceder a la impresora Bluetooth.", {
                            description: err instanceof Error ? err.message : undefined,
                          })
                        }
                      }}
                    >
                      Vincular impresora Bluetooth
                    </Button>
                    {bluetoothDeviceId && (
                      <p className="text-sm text-muted-foreground">
                        Vinculada: {bluetoothLabel || bluetoothDeviceId}
                      </p>
                    )}
                  </>
                ) : (
                  <Alert>
                    <AlertDescription>
                      Web Bluetooth no está disponible en este navegador.
                    </AlertDescription>
                  </Alert>
                )}
              </div>
            )}

            {transport === "network" && (
              <div className="space-y-3">
                <div className="space-y-1.5">
                  <Label htmlFor="network-host">IP o Hostname</Label>
                  <Input
                    id="network-host"
                    value={networkHost}
                    onChange={(e) => setNetworkHost(e.target.value)}
                    placeholder="192.168.1.100"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="network-port">Puerto</Label>
                  <Input
                    id="network-port"
                    type="number"
                    min={1}
                    max={65535}
                    value={networkPort}
                    onChange={(e) => setNetworkPort(Math.max(1, Math.min(65535, Number(e.target.value))))}
                  />
                </div>
              </div>
            )}
          </TabsContent>
        </Tabs>

        {error && (
          <Alert variant="destructive" className="mt-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <DialogFooter className="mt-6">
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
  registerId?: string
  /**
   * Cliente HTTP con el que hablar con la API. El panel usa el default
   * (cookie); el POS pasa `posApi` (Bearer del device) — un cliente por realm,
   * también en las escrituras. Es además lo que habilita que las impresoras se
   * configuren sin conexión: la cola de operaciones solo existe del lado del
   * device.
   */
  client?: HttpClient
  /**
   * Sucursal de la caja, para el caso en que `registerId` viene forzado (POS).
   * El listado de cajas del tenant sale de `useRegistersAdmin`, que es del
   * panel y no responde en un device sin sesión de panel — y encima no
   * responde nunca sin red. Con la caja ya elegida, la sucursal la sabe el
   * llamador y no hay por qué salir a buscarla.
   */
  outletId?: string
}

export function PrintersManager({
  registerId: forcedRegisterId,
  client,
  outletId: forcedOutletId,
}: PrintersManagerProps = {}) {
  const showRegisterSelector = forcedRegisterId === undefined
  // Solo se pide el listado de cajas cuando hay selector que llenar.
  const { data: registersData } = useRegistersAdmin({ enabled: showRegisterSelector })
  const registers = registersData?.registers ?? []

  const [internalRegisterId, setInternalRegisterId] = React.useState<string>("")
  const selectedRegisterId = forcedRegisterId ?? internalRegisterId
  const setSelectedRegisterId = setInternalRegisterId
  const selectedOutletId =
    forcedOutletId ?? registers.find((r) => r.id === selectedRegisterId)?.outletId

  const { data: bindingsData, isLoading: bindingsLoading } = usePrinterBindings(
    selectedRegisterId || undefined,
    { client },
  )
  const createMutation = useCreatePrinterBinding(selectedRegisterId || undefined, { client })
  const updateMutation = useUpdatePrinterBinding(selectedRegisterId || undefined, { client })
  const deleteMutation = useDeletePrinterBinding(selectedRegisterId || undefined, { client })

  const bindings = bindingsData?.bindings ?? []

  const [dialogMode, setDialogMode] = React.useState<DialogMode>(null)
  const [deleteTarget, setDeleteTarget] = React.useState<PrinterBinding | null>(null)
  const [testingId, setTestingId] = React.useState<string | null>(null)

  function handleAddPrinter() {
    if (!selectedRegisterId) {
      toast.error("Seleccioná una caja primero.")
      return
    }
    setDialogMode({ type: "new" })
  }

  function handleSave(data: Omit<PrinterBinding, "id" | "createdAt" | "updatedAt">) {
    if (!dialogMode || !selectedRegisterId) return
    if (dialogMode.type === "new") {
      createMutation.mutate(
        { registerId: selectedRegisterId, ...data },
        {
          onSuccess: () => {
            toast.success("Impresora agregada correctamente.")
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

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Impresoras</h1>
          <p className="text-sm text-muted-foreground">
            Administrá impresoras por caja. Cada impresora es una configuración virtual que puede conectarse por USB, Bluetooth, red o el diálogo del sistema.
          </p>
        </div>
        <Button onClick={handleAddPrinter} disabled={!selectedRegisterId}>
          <Printer className="size-4 mr-1.5" />
          Agregar impresora
        </Button>
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

      {!selectedRegisterId ? (
        <EmptyState
          icon={Printer}
          title="Seleccioná una caja"
          description="Elegí una caja para ver y administrar sus impresoras."
          showMarquee={false}
        />
      ) : bindingsLoading ? (
        <p className="text-sm text-muted-foreground">Cargando impresoras…</p>
      ) : bindings.length === 0 ? (
        <EmptyState
          icon={Printer}
          title="Sin impresoras configuradas"
          description='Hacé clic en "Agregar impresora" para configurar la primera.'
          showMarquee={false}
        />
      ) : (
        // Grid de cards — el POS rara vez tiene más de 3 impresoras, una
        // tabla con 7+ columnas era excesiva para tan pocas filas.
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
          {bindings.map((b) => (
            <PrinterCard
              key={b.id}
              binding={b}
              testing={testingId === b.id}
              onPrintTest={() => handlePrintTest(b)}
              onEdit={() => setDialogMode({ type: "edit", binding: b })}
              onDelete={() => setDeleteTarget(b)}
            />
          ))}
        </div>
      )}

      <BindingDialog
        mode={dialogMode}
        outletId={selectedOutletId}
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

// ── PrinterCard ──────────────────────────────────────────────────────────────
// Card individual de impresora — reemplaza la fila del DataTable previo.
// Card por impresora (no tabla): el POS típico tiene 1-3 impresoras, una
// tabla con 7 columnas era visualmente excesiva. La card agrupa info
// (transport + modo + docs + categorías + flags auto/cajón) en un layout
// vertical compacto + 3 acciones al pie.

function PrinterCard({
  binding: b,
  testing,
  onPrintTest,
  onEdit,
  onDelete,
}: {
  binding: PrinterBinding
  testing: boolean
  onPrintTest: () => void
  onEdit: () => void
  onDelete: () => void
}) {
  const docs = Array.isArray(b.docTypes) ? b.docTypes : []
  const docsLabel = docs.map((dt) => DOC_TYPE_LABELS[dt]?.short ?? dt).join(" · ") || "—"
  const categoriesLabel =
    !Array.isArray(b.categoryIds) || b.categoryIds.length === 0
      ? "Todas"
      : `${b.categoryIds.length} categ.`
  let deviceLabel = "—"
  if (b.transport === "native") deviceLabel = "Sistema"
  else if (b.transport === "usb") deviceLabel = `USB · ${b.deviceLabel ?? `${b.vendorId}:${b.productId}`}`
  else if (b.transport === "bluetooth") deviceLabel = `Bluetooth · ${b.bluetoothDeviceId?.slice(0, 8) ?? "—"}`
  else if (b.transport === "network") deviceLabel = `Red · ${b.networkHost}:${b.networkPort ?? 9100}`
  else if (b.transport === "station") deviceLabel = "Servidor de impresión"

  return (
    <Card className="flex flex-col p-4 gap-3">
      {/* Header: color + nombre + modo */}
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2 min-w-0">
          <span
            className="inline-block size-3 rounded-full shrink-0"
            style={{ backgroundColor: resolveColorBg(b.color) ?? undefined }}
            aria-hidden
          />
          <span className="font-semibold truncate">{b.name}</span>
        </div>
        <Badge variant={b.mode === "escpos" ? "secondary" : "outline"} className="shrink-0">
          {b.mode === "escpos" ? "Térmica" : "Navegador"}
        </Badge>
      </div>

      {/* Metadatos */}
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="text-muted-foreground">Dispositivo</dt>
        <dd className="truncate">{deviceLabel}</dd>
        <dt className="text-muted-foreground">Documentos</dt>
        <dd className="truncate">{docsLabel}</dd>
        <dt className="text-muted-foreground">Categorías</dt>
        <dd>{categoriesLabel}</dd>
      </dl>

      {/* Flags */}
      <div className="flex items-center gap-4 text-sm text-muted-foreground">
        <span className="flex items-center gap-1.5">
          {b.autoPrint ? (
            <Check className="size-4 text-green-600" />
          ) : (
            <X className="size-4" />
          )}
          Auto
        </span>
        <span className="flex items-center gap-1.5">
          {b.openDrawer ? (
            <Check className="size-4 text-green-600" />
          ) : (
            <X className="size-4" />
          )}
          Cajón
        </span>
      </div>

      {/* Acciones */}
      <div className="flex flex-wrap gap-1 pt-2 border-t -mx-4 px-4 -mb-4 pb-4 mt-auto">
        <Button variant="ghost" size="sm" disabled={testing} onClick={onPrintTest}>
          <PrinterCheck className="size-4 mr-1.5" />
          {testing ? "Imprimiendo…" : "Prueba"}
        </Button>
        <Button variant="ghost" size="sm" onClick={onEdit}>
          <Pencil className="size-4 mr-1.5" />
          Editar
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="text-destructive hover:text-destructive"
          onClick={onDelete}
        >
          <Trash2 className="size-4 mr-1.5" />
          Eliminar
        </Button>
      </div>
    </Card>
  )
}
