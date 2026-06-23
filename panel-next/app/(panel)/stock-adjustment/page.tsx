"use client"

import * as React from "react"
import { Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
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
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"

import { MoneyInput } from "@/components/ui/money-input"
import { useItems } from "@/hooks/use-items"
import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import { useCreateStockAdjustment } from "@/hooks/use-stock-adjustment"

const REASON_OPTIONS = [
  "Ingreso sin remito",
  "Merma",
  "Vencimiento",
  "Robo/pérdida",
  "Donación",
  "Devolución a proveedor",
  "Otro",
]

interface AdjustItem {
  itemId: string
  name: string
  sku?: string
  type: "+" | "-"
  qty: number
  unitCost: number | null
  note: string
}

export default function StockAdjustmentPage() {
  const [outletId, setOutletId] = React.useState("")
  const [locationId, setLocationId] = React.useState("")
  const [reasonOption, setReasonOption] = React.useState("")
  const [customReason, setCustomReason] = React.useState("")
  const [detail, setDetail] = React.useState("")
  const [searchQuery, setSearchQuery] = React.useState("")
  const [searchOpen, setSearchOpen] = React.useState(false)
  const [confirmOpen, setConfirmOpen] = React.useState(false)
  const [lineItems, setLineItems] = React.useState<AdjustItem[]>([])

  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []

  const { data: locations } = useOutletLocations(outletId || null)

  const { data: itemsData } = useItems({ q: searchQuery })
  const searchResults = itemsData?.items ?? []

  const adjust = useCreateStockAdjustment()

  const finalReason = reasonOption === "Otro"
    ? (customReason.trim() || "Otro")
    : reasonOption
  const reasonWithDetail = detail.trim()
    ? `${finalReason} — ${detail.trim()}`
    : finalReason

  function addItem(item: { itemId: string; itemName: string; itemSKU?: string | null }) {
    setLineItems((prev) => {
      if (prev.some((l) => l.itemId === item.itemId)) return prev
      return [
        ...prev,
        {
          itemId: item.itemId,
          name: item.itemName,
          sku: item.itemSKU ?? undefined,
          type: "+",
          qty: 1,
          unitCost: null,
          note: "",
        },
      ]
    })
  }

  function removeItem(itemId: string) {
    setLineItems((prev) => prev.filter((l) => l.itemId !== itemId))
  }

  function updateItem(itemId: string, patch: Partial<AdjustItem>) {
    setLineItems((prev) =>
      prev.map((l) => (l.itemId === itemId ? { ...l, ...patch } : l))
    )
  }

  function handleSubmit() {
    if (!outletId) {
      toast.error("Seleccioná una sucursal")
      return
    }
    if (!reasonOption) {
      toast.error("Seleccioná un motivo")
      return
    }
    if (reasonOption === "Otro" && !customReason.trim()) {
      toast.error("Ingresá el motivo personalizado")
      return
    }
    if (lineItems.length === 0) {
      toast.error("Agregá al menos un item")
      return
    }
    if (lineItems.some((l) => l.qty <= 0)) {
      toast.error("Todos los items deben tener cantidad mayor a 0")
      return
    }
    setConfirmOpen(true)
  }

  async function handleConfirm() {
    setConfirmOpen(false)
    try {
      const result = await adjust.mutateAsync({
        outletId,
        locationId: locationId || null,
        reason: reasonWithDetail,
        items: lineItems.map((l) => ({
          itemId: l.itemId,
          qty: l.qty,
          type: l.type,
          unitCost: l.type === "+" ? l.unitCost : undefined,
          note: l.note || undefined,
        })),
      })
      const msg = `${result.adjustmentsCount} ajuste(s) aplicado(s)`
      const skipped = result.skippedItems.length
      toast.success(msg + (skipped > 0 ? ` — ${skipped} item(s) sin trazabilidad omitido(s)` : ""))
      setOutletId("")
      setLocationId("")
      setReasonOption("")
      setCustomReason("")
      setDetail("")
      setLineItems([])
    } catch {
      toast.error("Error al aplicar los ajustes")
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Ajustes de stock</h1>
          <p className="text-sm text-muted-foreground">Registrar ingresos/egresos manuales de inventario.</p>
        </div>
      </header>

      <Card>
        <CardHeader>
          <CardTitle>Datos del ajuste</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label>Sucursal</Label>
            <Select value={outletId} onValueChange={(v) => { setOutletId(v); setLocationId("") }}>
              <SelectTrigger><SelectValue placeholder="Seleccionar sucursal" /></SelectTrigger>
              <SelectContent>
                {outlets.map((o) => (
                  <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Depósito (opcional)</Label>
            <Select
              value={locationId}
              onValueChange={setLocationId}
              disabled={!outletId}
            >
              <SelectTrigger><SelectValue placeholder="Sin depósito específico" /></SelectTrigger>
              <SelectContent>
                {(locations ?? []).map((l) => (
                  <SelectItem key={l.id} value={l.id}>{l.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Motivo</Label>
            <Select value={reasonOption} onValueChange={setReasonOption}>
              <SelectTrigger><SelectValue placeholder="Seleccionar motivo" /></SelectTrigger>
              <SelectContent>
                {REASON_OPTIONS.map((r) => (
                  <SelectItem key={r} value={r}>{r}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {reasonOption === "Otro" && (
            <div className="space-y-2">
              <Label>Motivo personalizado</Label>
              <Input
                value={customReason}
                onChange={(e) => setCustomReason(e.target.value)}
                placeholder="Describir el motivo..."
              />
            </div>
          )}

          <div className="space-y-2 sm:col-span-2">
            <Label>Detalle adicional (opcional)</Label>
            <Textarea
              value={detail}
              onChange={(e) => setDetail(e.target.value)}
              placeholder="Observaciones adicionales..."
              rows={2}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Items</CardTitle>
          <Popover open={searchOpen} onOpenChange={setSearchOpen}>
            <PopoverTrigger asChild>
              <Button variant="outline" size="sm">
                <Plus className="h-4 w-4 mr-2" />
                Agregar item
              </Button>
            </PopoverTrigger>
            <PopoverContent className="p-0 w-80" align="end">
              <Command>
                <CommandInput
                  placeholder="Buscar por nombre, SKU..."
                  value={searchQuery}
                  onValueChange={setSearchQuery}
                />
                <CommandList>
                  <CommandEmpty>Sin resultados</CommandEmpty>
                  <CommandGroup>
                    {searchResults.map((item) => (
                      <CommandItem
                        key={item.itemId}
                        value={item.itemId}
                        onSelect={() => {
                          addItem(item)
                          setSearchOpen(false)
                          setSearchQuery("")
                        }}
                      >
                        <span>{item.itemName}</span>
                        {item.itemSKU && (
                          <span className="ml-auto text-xs text-muted-foreground">{item.itemSKU}</span>
                        )}
                      </CommandItem>
                    ))}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </CardHeader>
        <CardContent>
          {lineItems.length === 0 ? (
            <p className="text-sm text-muted-foreground text-center py-8">
              No hay items agregados. Usá el botón "Agregar item" para buscar.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Artículo</TableHead>
                  <TableHead className="w-32">Tipo</TableHead>
                  <TableHead className="w-28">Cantidad</TableHead>
                  <TableHead className="w-36">Costo unitario</TableHead>
                  <TableHead>Nota</TableHead>
                  <TableHead className="w-10"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lineItems.map((line) => (
                  <TableRow key={line.itemId}>
                    <TableCell>
                      <div className="font-medium">{line.name}</div>
                      {line.sku && <div className="text-xs text-muted-foreground">{line.sku}</div>}
                    </TableCell>
                    <TableCell>
                      <div className="flex gap-1">
                        <Button
                          variant={line.type === "+" ? "default" : "outline"}
                          size="sm"
                          className="w-9 px-0"
                          onClick={() => updateItem(line.itemId, { type: "+" })}
                        >
                          +
                        </Button>
                        <Button
                          variant={line.type === "-" ? "default" : "outline"}
                          size="sm"
                          className="w-9 px-0"
                          onClick={() => updateItem(line.itemId, { type: "-" })}
                        >
                          -
                        </Button>
                      </div>
                    </TableCell>
                    <TableCell>
                      <Input
                        type="number"
                        min="0"
                        step="any"
                        value={line.qty === 0 ? "" : line.qty}
                        onChange={(e) => updateItem(line.itemId, { qty: parseFloat(e.target.value) || 0 })}
                        className="w-24"
                      />
                    </TableCell>
                    <TableCell>
                      <MoneyInput
                        value={line.type === "+" ? line.unitCost : null}
                        onChange={(v) => updateItem(line.itemId, { unitCost: v })}
                        disabled={line.type === "-"}
                        className="w-32"
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={line.note}
                        onChange={(e) => updateItem(line.itemId, { note: e.target.value })}
                        placeholder="Nota..."
                        className="w-40"
                      />
                    </TableCell>
                    <TableCell>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => removeItem(line.itemId)}
                      >
                        <Trash2 className="h-4 w-4 text-destructive" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Confirmar ajuste</AlertDialogTitle>
              <AlertDialogDescription>
                Se aplicarán {lineItems.length} ajuste(s) al inventario.
                Esta acción no se puede deshacer.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancelar</AlertDialogCancel>
              <AlertDialogAction onClick={handleConfirm}>Confirmar</AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
        <Button onClick={handleSubmit} disabled={adjust.isPending}>
          {adjust.isPending ? "Aplicando..." : "Aplicar ajustes"}
        </Button>
      </div>
    </div>
  )
}
