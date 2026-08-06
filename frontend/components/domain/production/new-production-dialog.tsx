"use client"

import * as React from "react"
import { Check, ChevronsUpDown, Loader2, PackageSearch } from "lucide-react"
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
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"

import { useItems, useItem } from "@/hooks/use-items"
import { useOutlets } from "@/hooks/use-outlets"
import { useOutletLocations } from "@/hooks/use-outlet-locations"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useCreateProductionOrder, useProductionCapacity } from "@/hooks/use-production"
import { useWasteReasons } from "@/hooks/use-waste-reasons"
import { formatInt } from "@/lib/format"
import type { ItemKind } from "@/lib/types/item"

const NO_LOCATION = "__none__"
const PRODUCIBLE_KINDS: ItemKind[] = ["produccion_previa", "produccion_directa"]

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Item preseleccionado (llega vía ?newItemId= desde el detalle del item). */
  initialItemId?: string | null
}

export function NewProductionDialog({ open, onOpenChange, initialItemId }: Props) {
  const { data: bootstrap } = useBootstrap()
  const create = useCreateProductionOrder()

  const [itemId, setItemId] = React.useState<string | null>(null)
  const [itemQuery, setItemQuery] = React.useState("")
  const [itemPickerOpen, setItemPickerOpen] = React.useState(false)
  const [outletId, setOutletId] = React.useState<string | null>(null)
  const [locationId, setLocationId] = React.useState<string>(NO_LOCATION)
  const [outputLocationId, setOutputLocationId] = React.useState<string>(NO_LOCATION)
  const [qty, setQty] = React.useState<string>("")
  const [note, setNote] = React.useState("")
  const [wasteUnits, setWasteUnits] = React.useState<string>("")
  const [wasteReasonId, setWasteReasonId] = React.useState<string>(NO_LOCATION)

  // Reset al abrir — preselecciona item y sucursal activa.
  React.useEffect(() => {
    if (!open) return
    setItemId(initialItemId ?? null)
    setItemQuery("")
    setOutletId(bootstrap?.activeOutletId ?? null)
    setLocationId(NO_LOCATION)
    setOutputLocationId(NO_LOCATION)
    setQty("")
    setNote("")
    setWasteUnits("")
    setWasteReasonId(NO_LOCATION)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialItemId])

  const { data: preselected } = useItem(initialItemId ?? undefined)
  const { data: itemsData } = useItems({ q: itemQuery })
  const producibleResults = React.useMemo(
    () => (itemsData?.items ?? []).filter((i) => PRODUCIBLE_KINDS.includes(i.kind)),
    [itemsData],
  )
  const { data: outletsData } = useOutlets()
  const { data: insumoLocations } = useOutletLocations(outletId)
  const { data: destinoLocations } = useOutletLocations(outletId)
  const { data: wasteReasonsData } = useWasteReasons()

  const qtyNum = Number(qty.replace(",", "."))
  const { data: capacity, isFetching: capacityLoading } = useProductionCapacity(
    itemId,
    outletId,
  )

  const selectedItemName =
    (initialItemId && itemId === initialItemId ? preselected?.itemName : null) ??
    producibleResults.find((i) => i.itemId === itemId)?.itemName ??
    null

  const canSubmit = !!itemId && !!outletId && qtyNum > 0

  async function handleSubmit(mode: "draft" | "immediate") {
    if (!itemId || !outletId || qtyNum <= 0) return
    try {
      await create.mutateAsync({
        itemId,
        outletId,
        qtyPlanned: qtyNum,
        locationId: locationId === NO_LOCATION ? null : locationId,
        outputLocationId: outputLocationId === NO_LOCATION ? null : outputLocationId,
        mode,
        note: note || null,
        ...(mode === "immediate"
          ? {
              qtyProduced: qtyNum,
              wasteUnits: wasteUnits ? Number(wasteUnits.replace(",", ".")) : 0,
              wasteReasonId: wasteReasonId === NO_LOCATION ? null : wasteReasonId,
            }
          : {}),
      })
      toast.success(mode === "immediate" ? "Producción registrada" : "Orden creada")
      onOpenChange(false)
    } catch (e) {
      toast.error("No se pudo crear la producción", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Nueva producción</DialogTitle>
          <DialogDescription>
            Elegí el producto a fabricar, la cantidad y la sucursal. Podés producir
            ahora mismo o crear una orden para iniciar más tarde.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label>Producto</Label>
            <Popover open={itemPickerOpen} onOpenChange={setItemPickerOpen}>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  role="combobox"
                  className="w-full justify-between font-normal"
                >
                  {selectedItemName ?? "Buscar producto con receta…"}
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
                      {producibleResults.map((item) => (
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
              <Label>Cantidad a producir</Label>
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
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label>Depósito de insumos</Label>
              <Select value={locationId} onValueChange={setLocationId} disabled={!outletId}>
                <SelectTrigger>
                  <SelectValue placeholder="Depósito por defecto" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_LOCATION}>Depósito por defecto</SelectItem>
                  {(insumoLocations ?? []).map((l) => (
                    <SelectItem key={l.id} value={l.id}>
                      {l.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>Depósito destino</Label>
              <Select
                value={outputLocationId}
                onValueChange={setOutputLocationId}
                disabled={!outletId}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Depósito por defecto" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NO_LOCATION}>Depósito por defecto</SelectItem>
                  {(destinoLocations ?? []).map((l) => (
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
              placeholder="Ej: lote para evento del sábado"
              rows={2}
            />
          </div>

          {itemId && outletId && qtyNum > 0 && (
            <RecipePreview
              capacity={capacity ?? null}
              loading={capacityLoading}
              qty={qtyNum}
              bootstrap={bootstrap}
            />
          )}

          {itemId && outletId && qtyNum > 0 && (
            <div className="space-y-3 rounded-md border p-3">
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Si producís ahora (opcional)
              </p>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label>Unidades con merma</Label>
                  <Input
                    type="number"
                    min="0"
                    step="any"
                    inputMode="decimal"
                    value={wasteUnits}
                    onChange={(e) => setWasteUnits(e.target.value)}
                    placeholder="0"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Motivo de merma</Label>
                  <Select value={wasteReasonId} onValueChange={setWasteReasonId}>
                    <SelectTrigger>
                      <SelectValue placeholder="Sin merma" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NO_LOCATION}>Sin merma</SelectItem>
                      {(wasteReasonsData?.wasteReasons ?? []).map((r) => (
                        <SelectItem key={r.id} value={r.id}>
                          {r.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
          )}
        </div>

        <DialogFooter className="gap-2 sm:gap-2">
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={create.isPending}>
            Cancelar
          </Button>
          <Button
            variant="outline"
            disabled={!canSubmit || create.isPending}
            onClick={() => handleSubmit("draft")}
          >
            {create.isPending && <Loader2 className="size-4 animate-spin" />}
            Crear orden
          </Button>
          <Button disabled={!canSubmit || create.isPending} onClick={() => handleSubmit("immediate")}>
            {create.isPending && <Loader2 className="size-4 animate-spin" />}
            Producir ahora
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function RecipePreview({
  capacity,
  loading,
  qty,
  bootstrap,
}: {
  capacity: { capacity: number | null; ingredients: Array<{
    itemId: string
    qtyPerUnit: number
    onHand: number | null
    wastePercent: number
    tracked: boolean
  }> } | null
  loading: boolean
  qty: number
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  // itemName no viene en la respuesta de capacity — resolvemos por id vía
  // el listado de items ya cacheado por react-query (sin request extra si
  // ya se consultó antes en la sesión).
  const { data: allItems } = useItems({})
  const nameById = React.useMemo(() => {
    const map = new Map<string, string>()
    for (const it of allItems?.items ?? []) map.set(it.itemId, it.itemName)
    return map
  }, [allItems])

  if (loading) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="size-4 animate-spin" />
        Calculando insumos y capacidad…
      </div>
    )
  }

  if (!capacity || capacity.ingredients.length === 0) {
    return (
      <div className="flex items-center gap-2 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
        <PackageSearch className="size-4" />
        Este producto no tiene receta configurada — no se puede producir.
      </div>
    )
  }

  const isEnough = capacity.capacity === null || capacity.capacity >= qty

  return (
    <div className="space-y-2 rounded-md border p-3">
      <div className="flex items-center justify-between">
        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Insumos requeridos
        </p>
        <Badge variant={isEnough ? "secondary" : "destructive"}>
          {capacity.capacity === null
            ? "Capacidad ilimitada"
            : `Capacidad: ${formatInt(capacity.capacity, bootstrap)} u.`}
        </Badge>
      </div>
      <div className="space-y-1">
        {capacity.ingredients.map((ing) => {
          const need = ing.qtyPerUnit * qty
          const enough = !ing.tracked || ing.onHand === null || ing.onHand >= need
          return (
            <div
              key={ing.itemId}
              className="flex items-center justify-between text-sm"
            >
              <span className="min-w-0 truncate">{nameById.get(ing.itemId) ?? ing.itemId}</span>
              <span
                className={cn(
                  "shrink-0 tabular-nums",
                  enough ? "text-muted-foreground" : "text-destructive",
                )}
              >
                {formatInt(need, bootstrap)}
                {ing.tracked && ing.onHand !== null && (
                  <> / {formatInt(ing.onHand, bootstrap)} disp.</>
                )}
              </span>
            </div>
          )
        })}
      </div>
      {!isEnough && (
        <p className="text-xs text-destructive">
          Stock insuficiente para {formatInt(qty, bootstrap)} unidades. El backend igual
          permite completar — este preview es orientativo.
        </p>
      )}
    </div>
  )
}

