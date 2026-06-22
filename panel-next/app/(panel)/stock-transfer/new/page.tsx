"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { Plus, Trash2, ArrowLeftRight } from "lucide-react"
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
import { Alert, AlertDescription } from "@/components/ui/alert"

import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import { useItems } from "@/hooks/use-items"
import { useCreateStockTransfer } from "@/hooks/use-stock-transfers"

interface TransferItem {
  itemId: string
  name: string
  sku?: string
  qty: number
}

export default function NewStockTransferPage() {
  const router = useRouter()

  const [fromOutletId,   setFromOutletId]   = React.useState("")
  const [fromLocationId, setFromLocationId] = React.useState("")
  const [toOutletId,     setToOutletId]     = React.useState("")
  const [toLocationId,   setToLocationId]   = React.useState("")
  const [note,           setNote]           = React.useState("")
  const [lineItems,      setLineItems]      = React.useState<TransferItem[]>([])
  const [searchQuery,    setSearchQuery]    = React.useState("")
  const [searchOpen,     setSearchOpen]     = React.useState(false)
  const [confirmOpen,    setConfirmOpen]    = React.useState(false)

  const { data: outletsData }   = useOutlets()
  const outlets                 = outletsData?.rows ?? []
  const { data: fromLocations } = useOutletLocations(fromOutletId || null)
  const { data: toLocations }   = useOutletLocations(toOutletId   || null)
  const { data: itemsData }     = useItems({ q: searchQuery })
  const searchResults           = itemsData?.items ?? []
  const create                  = useCreateStockTransfer()

  // Detección de origen == destino
  const sameDestination =
    fromOutletId !== "" &&
    toOutletId   !== "" &&
    fromOutletId === toOutletId &&
    (fromLocationId || null) === (toLocationId || null)

  function addItem(item: { itemId: string; itemName: string; itemSKU?: string | null }) {
    setLineItems((prev) => {
      if (prev.some((l) => l.itemId === item.itemId)) return prev
      return [...prev, { itemId: item.itemId, name: item.itemName, sku: item.itemSKU ?? undefined, qty: 1 }]
    })
  }

  function removeItem(itemId: string) {
    setLineItems((prev) => prev.filter((l) => l.itemId !== itemId))
  }

  function updateQty(itemId: string, qty: number) {
    setLineItems((prev) => prev.map((l) => (l.itemId === itemId ? { ...l, qty } : l)))
  }

  function handleSubmit() {
    if (!fromOutletId) { toast.error("Seleccioná la sucursal origen"); return }
    if (!toOutletId)   { toast.error("Seleccioná la sucursal destino"); return }
    if (sameDestination) { toast.error("El origen y destino son idénticos"); return }
    if (lineItems.length === 0) { toast.error("Agregá al menos un item"); return }
    if (lineItems.some((l) => l.qty <= 0)) { toast.error("Todos los items deben tener cantidad mayor a 0"); return }
    setConfirmOpen(true)
  }

  async function handleConfirm() {
    setConfirmOpen(false)
    try {
      const result = await create.mutateAsync({
        from: { outletId: fromOutletId, locationId: fromLocationId || null },
        to:   { outletId: toOutletId,   locationId: toLocationId   || null },
        note: note.trim() || undefined,
        items: lineItems.map((l) => ({ itemId: l.itemId, qty: l.qty })),
      })
      const msg = `Transferencia creada — ${result.itemsProcessed} item(s) procesado(s)`
      const skipped = result.skippedItems.length
      toast.success(msg + (skipped > 0 ? ` — ${skipped} sin trazabilidad omitido(s)` : ""))
      router.push(`/stock-transfer/${result.id}`)
    } catch {
      toast.error("Error al crear la transferencia")
    }
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center gap-2">
        <ArrowLeftRight className="h-5 w-5 text-muted-foreground" />
        <h1 className="text-2xl font-semibold">Nueva transferencia de stock</h1>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Origen</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label>Sucursal</Label>
              <Select value={fromOutletId} onValueChange={(v) => { setFromOutletId(v); setFromLocationId("") }}>
                <SelectTrigger><SelectValue placeholder="Seleccionar sucursal" /></SelectTrigger>
                <SelectContent>
                  {outlets.map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Depósito (opcional)</Label>
              <Select value={fromLocationId} onValueChange={setFromLocationId} disabled={!fromOutletId}>
                <SelectTrigger><SelectValue placeholder="Sin depósito específico" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="">Sin depósito específico</SelectItem>
                  {(fromLocations ?? []).map((l) => <SelectItem key={l.id} value={l.id}>{l.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Destino</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label>Sucursal</Label>
              <Select value={toOutletId} onValueChange={(v) => { setToOutletId(v); setToLocationId("") }}>
                <SelectTrigger><SelectValue placeholder="Seleccionar sucursal" /></SelectTrigger>
                <SelectContent>
                  {outlets.map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Depósito (opcional)</Label>
              <Select value={toLocationId} onValueChange={setToLocationId} disabled={!toOutletId}>
                <SelectTrigger><SelectValue placeholder="Sin depósito específico" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="">Sin depósito específico</SelectItem>
                  {(toLocations ?? []).map((l) => <SelectItem key={l.id} value={l.id}>{l.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>
      </div>

      {sameDestination && (
        <Alert variant="destructive">
          <AlertDescription>El origen y destino son idénticos. Modificá la sucursal o el depósito.</AlertDescription>
        </Alert>
      )}

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
              No hay items agregados. Usá el botón &quot;Agregar item&quot; para buscar.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Artículo</TableHead>
                  <TableHead className="w-36">Cantidad</TableHead>
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
                      <Input
                        type="number"
                        min="0.001"
                        step="any"
                        value={line.qty === 0 ? "" : line.qty}
                        onChange={(e) => updateQty(line.itemId, parseFloat(e.target.value) || 0)}
                        className="w-28"
                      />
                    </TableCell>
                    <TableCell>
                      <Button variant="ghost" size="icon" onClick={() => removeItem(line.itemId)}>
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

      <Card>
        <CardHeader><CardTitle>Nota (opcional)</CardTitle></CardHeader>
        <CardContent>
          <Textarea
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="Observaciones sobre la transferencia..."
            rows={2}
          />
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Confirmar transferencia</AlertDialogTitle>
              <AlertDialogDescription>
                Se transferirán {lineItems.length} tipo(s) de artículo(s). Los movimientos de stock
                se registrarán inmediatamente y podrán revertirse cancelando la transferencia.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancelar</AlertDialogCancel>
              <AlertDialogAction onClick={handleConfirm}>Confirmar transferencia</AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
        <Button onClick={handleSubmit} disabled={create.isPending || sameDestination}>
          {create.isPending ? "Procesando..." : "Confirmar transferencia"}
        </Button>
      </div>
    </div>
  )
}
