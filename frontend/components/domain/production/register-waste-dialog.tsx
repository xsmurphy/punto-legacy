"use client"

import * as React from "react"
import { Check, ChevronsUpDown, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { cn } from "@/lib/utils"

import { useItems } from "@/hooks/use-items"
import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useRegisterWaste } from "@/hooks/use-waste"
import { useWasteReasons } from "@/hooks/use-waste-reasons"

const NO_LOCATION = "__none__"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function RegisterWasteDialog({ open, onOpenChange }: Props) {
  const { data: bootstrap } = useBootstrap()
  const register = useRegisterWaste()

  const [itemId, setItemId] = React.useState<string | null>(null)
  const [itemQuery, setItemQuery] = React.useState("")
  const [itemPickerOpen, setItemPickerOpen] = React.useState(false)
  const [qty, setQty] = React.useState("")
  const [reasonId, setReasonId] = React.useState<string | null>(null)
  const [outletId, setOutletId] = React.useState<string | null>(null)
  const [locationId, setLocationId] = React.useState<string>(NO_LOCATION)
  const [note, setNote] = React.useState("")

  React.useEffect(() => {
    if (!open) return
    setItemId(null)
    setItemQuery("")
    setQty("")
    setReasonId(null)
    setOutletId(bootstrap?.activeOutletId ?? null)
    setLocationId(NO_LOCATION)
    setNote("")
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const { data: itemsData } = useItems({ q: itemQuery })
  const results = itemsData?.items ?? []
  const selectedItemName = results.find((i) => i.itemId === itemId)?.itemName ?? null
  const { data: outletsData } = useOutlets()
  const { data: locations } = useOutletLocations(outletId)
  const { data: wasteReasonsData } = useWasteReasons()

  const qtyNum = Number(qty.replace(",", "."))
  const canSubmit = !!itemId && !!reasonId && !!outletId && qtyNum > 0

  async function handleSubmit() {
    if (!itemId || !reasonId || !outletId || qtyNum <= 0) return
    try {
      await register.mutateAsync({
        itemId,
        qty: qtyNum,
        reasonId,
        outletId,
        locationId: locationId === NO_LOCATION ? null : locationId,
        note: note || null,
      })
      toast.success("Merma registrada")
      onOpenChange(false)
    } catch (e) {
      toast.error("No se pudo registrar la merma", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Registrar merma</DialogTitle>
          <DialogDescription>
            Para pérdidas fuera de una orden de producción: vencimientos, roturas,
            robos u otro motivo.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label>Producto</Label>
            <Popover open={itemPickerOpen} onOpenChange={setItemPickerOpen}>
              <PopoverTrigger asChild>
                <Button variant="outline" role="combobox" className="w-full justify-between font-normal">
                  {selectedItemName ?? "Buscar producto o insumo…"}
                  <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                <Command shouldFilter={false}>
                  <CommandInput
                    placeholder="Buscar por nombre o SKU…"
                    value={itemQuery}
                    onValueChange={setItemQuery}
                  />
                  <CommandList>
                    <CommandEmpty>Sin resultados</CommandEmpty>
                    <CommandGroup>
                      {results.map((item) => (
                        <CommandItem
                          key={item.itemId}
                          value={item.itemId}
                          onSelect={() => {
                            setItemId(item.itemId)
                            setItemPickerOpen(false)
                          }}
                        >
                          <Check
                            className={cn(
                              "mr-2 size-4",
                              itemId === item.itemId ? "opacity-100" : "opacity-0",
                            )}
                          />
                          <span className="flex-1">{item.itemName}</span>
                          {item.itemSKU && (
                            <span className="text-xs text-muted-foreground">{item.itemSKU}</span>
                          )}
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                </Command>
              </PopoverContent>
            </Popover>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label>Cantidad</Label>
              <Input
                type="number"
                min="0"
                step="any"
                inputMode="decimal"
                value={qty}
                onChange={(e) => setQty(e.target.value)}
                placeholder="0"
              />
            </div>
            <div className="space-y-1.5">
              <Label>Motivo</Label>
              <Select value={reasonId ?? ""} onValueChange={setReasonId}>
                <SelectTrigger>
                  <SelectValue placeholder="Elegí un motivo" />
                </SelectTrigger>
                <SelectContent>
                  {(wasteReasonsData?.wasteReasons ?? []).map((r) => (
                    <SelectItem key={r.id} value={r.id}>
                      {r.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label>Sucursal</Label>
              <Select value={outletId ?? ""} onValueChange={setOutletId}>
                <SelectTrigger>
                  <SelectValue placeholder="Elegí sucursal" />
                </SelectTrigger>
                <SelectContent>
                  {(outletsData?.rows ?? []).map((o) => (
                    <SelectItem key={o.id} value={o.id}>
                      {o.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>Depósito (opcional)</Label>
              <Select value={locationId} onValueChange={setLocationId} disabled={!outletId}>
                <SelectTrigger>
                  <SelectValue placeholder="Depósito por defecto" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_LOCATION}>Depósito por defecto</SelectItem>
                  {(locations ?? []).map((l) => (
                    <SelectItem key={l.id} value={l.id}>
                      {l.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-1.5">
            <Label>Nota (opcional)</Label>
            <Textarea
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="Ej: 2kg de harina vencida"
              rows={2}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={register.isPending}>
            Cancelar
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit || register.isPending}>
            {register.isPending && <Loader2 className="size-4 animate-spin" />}
            Registrar merma
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
