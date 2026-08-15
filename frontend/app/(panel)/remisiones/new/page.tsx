"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
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
import { DatePicker } from "@/components/date-picker"
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
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"

import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import { useItems } from "@/hooks/use-items"
import { useContacts, type ContactType } from "@/hooks/use-contacts"
import {
  useCreateRemision,
  REMISION_MOTIVO_OPTIONS,
  type RemisionMotivo,
} from "@/hooks/use-remisiones"

const NONE = "__none__"

/** Cliente para venta, proveedor para devolución — el resto no sugiere un
 *  tipo de contacto en particular (consignatario/organizador de feria no
 *  necesariamente están cargados como cliente o proveedor). */
function contactTypeForMotivo(motivo: RemisionMotivo): ContactType | null {
  if (motivo === "venta") return 1
  if (motivo === "devolucion_proveedor") return 2
  return null
}

interface LineItem {
  itemId: string
  name: string
  sku?: string
  qty: number
}

export default function NewRemisionPage() {
  const router = useRouter()

  const [motivo, setMotivo] = React.useState<RemisionMotivo>("venta")
  const [outletId, setOutletId] = React.useState("")
  const [locationId, setLocationId] = React.useState<string>(NONE)
  const [destinationContactId, setDestinationContactId] = React.useState<string>(NONE)
  const [destinationNote, setDestinationNote] = React.useState("")
  const [transferDate, setTransferDate] = React.useState("")
  const [note, setNote] = React.useState("")
  const [lineItems, setLineItems] = React.useState<LineItem[]>([])
  const [searchQuery, setSearchQuery] = React.useState("")
  const [searchOpen, setSearchOpen] = React.useState(false)
  const [contactOpen, setContactOpen] = React.useState(false)
  const [confirmOpen, setConfirmOpen] = React.useState(false)

  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []
  const { data: locations } = useOutletLocations(outletId || null)
  const { data: itemsData } = useItems({ q: searchQuery })
  const searchResults = itemsData?.items ?? []

  const contactType = contactTypeForMotivo(motivo)
  const { data: contactsData } = useContacts({ type: contactType ?? 1 })
  const contacts = contactType ? contactsData?.contacts ?? [] : []

  const create = useCreateRemision()

  const locId = locationId === NONE ? null : locationId
  const contactId = destinationContactId === NONE ? null : destinationContactId

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
    if (!outletId) { toast.error("Seleccioná la sucursal de origen"); return }
    if (lineItems.length === 0) { toast.error("Agregá al menos un item"); return }
    if (lineItems.some((l) => l.qty <= 0)) { toast.error("Todos los items deben tener cantidad mayor a 0"); return }
    setConfirmOpen(true)
  }

  async function handleConfirm() {
    setConfirmOpen(false)
    try {
      const result = await create.mutateAsync({
        motivo,
        outletId,
        locationId: locId,
        destinationContactId: contactId,
        destinationNote: destinationNote.trim() || undefined,
        transferDate: transferDate ? `${transferDate}T00:00:00` : undefined,
        note: note.trim() || undefined,
        items: lineItems.map((l) => ({ itemId: l.itemId, qty: l.qty })),
      })
      toast.success(`Remisión Nº ${result.docNumber} creada`)
      router.push(`/remisiones/${result.id}`)
    } catch {
      toast.error("Error al crear la remisión")
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Nueva remisión</h1>
        <p className="text-sm text-muted-foreground">
          Documentá el traslado de mercadería con su motivo, origen y destino.
        </p>
      </header>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Motivo y origen</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label>Motivo de traslado</Label>
              <Select value={motivo} onValueChange={(v) => { setMotivo(v as RemisionMotivo); setDestinationContactId(NONE) }}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {REMISION_MOTIVO_OPTIONS.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Sucursal de origen</Label>
              <Select value={outletId} onValueChange={(v) => { setOutletId(v); setLocationId(NONE) }}>
                <SelectTrigger><SelectValue placeholder="Seleccionar sucursal" /></SelectTrigger>
                <SelectContent>
                  {outlets.map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Depósito (opcional)</Label>
              <Select value={locationId} onValueChange={setLocationId} disabled={!outletId}>
                <SelectTrigger><SelectValue placeholder="Sin depósito específico" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>Sin depósito específico</SelectItem>
                  {(locations ?? []).map((l) => <SelectItem key={l.id} value={l.id}>{l.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Fecha de traslado</Label>
              <DatePicker value={transferDate} onChange={setTransferDate} placeholder="Hoy" />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Destino</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            {contactType && (
              <div className="space-y-2">
                <Label>{contactType === 1 ? "Cliente" : "Proveedor"} (opcional)</Label>
                <Select value={destinationContactId} onValueChange={setDestinationContactId}>
                  <SelectTrigger><SelectValue placeholder="Sin contacto" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>Sin contacto</SelectItem>
                    {contacts.map((c) => <SelectItem key={c.id} value={c.id}>{c.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            )}
            <div className="space-y-2">
              <Label>Nota de destino (dirección, lugar de exposición, etc.)</Label>
              <Textarea
                value={destinationNote}
                onChange={(e) => setDestinationNote(e.target.value)}
                placeholder="Dirección de entrega, feria, depósito de terceros..."
                rows={2}
              />
            </div>
          </CardContent>
        </Card>
      </div>

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
            placeholder="Observaciones sobre la remisión..."
            rows={2}
          />
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Confirmar remisión</AlertDialogTitle>
              <AlertDialogDescription>
                Se documentará el traslado de {lineItems.length} tipo(s) de artículo(s) con numeración
                correlativa. Esta remisión no mueve stock — el motivo elegido define quién lo hace.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancelar</AlertDialogCancel>
              <AlertDialogAction onClick={handleConfirm}>Confirmar remisión</AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
        <Button onClick={handleSubmit} disabled={create.isPending}>
          {create.isPending ? "Procesando..." : "Confirmar remisión"}
        </Button>
      </div>
    </div>
  )
}
