"use client"

import * as React from "react"
import Link from "next/link"
import { useParams, useRouter } from "next/navigation"
import {
  ArrowLeft, Loader2, Pencil, Trash2, Plus, Check, ChevronsUpDown, X,
} from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { DatePicker } from "@/components/date-picker"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
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
import {
  usePriceList,
  useUpdatePriceList,
  useDeletePriceList,
  useSetPriceListItems,
} from "@/hooks/use-price-lists"
import { useItems } from "@/hooks/use-items"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { resolveDateLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"
import { formatMoney } from "@/lib/format"
import { cn } from "@/lib/utils"
import type { PriceListItem, PriceListItemInput } from "@/lib/types/price-list"
import type { ItemListItem } from "@/lib/types/item"

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatAdjSign(adj: number): string {
  if (adj === 0) return "0%"
  return adj > 0 ? `+${adj}%` : `−${Math.abs(adj)}%`
}

function formatDate(iso: string | null, config: TenantLocaleConfig | null | undefined): string {
  if (!iso) return "Sin fecha"
  const d = new Date(iso)
  if (isNaN(d.getTime())) return "—"
  return d.toLocaleDateString(resolveDateLocale(config), { day: "2-digit", month: "2-digit", year: "2-digit" })
}

// ── Item selector (Combobox) ──────────────────────────────────────────────────

function ItemCombobox({
  value,
  onChange,
  excludeIds,
}: {
  value: ItemListItem | null
  onChange: (item: ItemListItem | null) => void
  excludeIds: string[]
}) {
  const [open, setOpen]   = React.useState(false)
  const [q,    setQ]      = React.useState("")
  const { data } = useItems({ q: q || undefined })
  const items = (data?.items ?? []).filter(
    (i) => !excludeIds.includes(i.itemId) && !i.itemIsParent
  )

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="w-full justify-between font-normal"
        >
          <span className="truncate">
            {value ? value.itemName : "Buscar ítem…"}
          </span>
          <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[360px] p-0">
        <Command>
          <CommandInput
            placeholder="Nombre o SKU…"
            value={q}
            onValueChange={setQ}
          />
          <CommandList>
            <CommandEmpty>Sin resultados.</CommandEmpty>
            <CommandGroup>
              {items.map((item) => (
                <CommandItem
                  key={item.itemId}
                  value={item.itemId}
                  onSelect={() => {
                    onChange(item)
                    setOpen(false)
                    setQ("")
                  }}
                >
                  <Check
                    className={cn(
                      "mr-2 size-4",
                      value?.itemId === item.itemId ? "opacity-100" : "opacity-0"
                    )}
                  />
                  <div className="flex flex-col">
                    <span className="text-sm">{item.itemName}</span>
                    {item.itemSKU && (
                      <span className="text-xs text-muted-foreground">{item.itemSKU}</span>
                    )}
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}

// ── Panel de agregar ítem ─────────────────────────────────────────────────────

interface AddItemPanelProps {
  priceListId: string
  defaultAdjustment: number
  existingItems: PriceListItem[]
  onSaved: () => void
}

function AddItemPanel({ priceListId, defaultAdjustment, existingItems, onSaved }: AddItemPanelProps) {
  const setItems = useSetPriceListItems()
  const { data: bootstrap } = useBootstrap()

  const [selectedItem, setSelectedItem]    = React.useState<ItemListItem | null>(null)
  const [mode,         setMode]            = React.useState<"fixed" | "adjustment">("fixed")
  const [fixedPrice,   setFixedPrice]      = React.useState<number | null>(null)
  const [adjValue,     setAdjValue]        = React.useState<string>(String(defaultAdjustment))

  const existingIds = existingItems.map((i) => i.itemId)

  const reset = () => {
    setSelectedItem(null)
    setFixedPrice(null)
    setAdjValue(String(defaultAdjustment))
    setMode("fixed")
  }

  const onAdd = async () => {
    if (!selectedItem) { toast.error("Seleccioná un ítem"); return }

    const newInput: PriceListItemInput = {
      itemId: selectedItem.itemId,
      fixedPrice:      mode === "fixed"       ? fixedPrice : null,
      itemAdjustment:  mode === "adjustment"  ? (parseFloat(adjValue) || 0) : null,
    }

    const mergedInputs: PriceListItemInput[] = [
      ...existingItems.map((i) => ({
        itemId:         i.itemId,
        fixedPrice:     i.fixedPrice,
        itemAdjustment: i.itemAdjustment,
      })),
      newInput,
    ]

    try {
      await setItems.mutateAsync({ priceListId, items: mergedInputs })
      toast.success(`${selectedItem.itemName} agregado`)
      reset()
      onSaved()
    } catch (err) {
      toast.error("No se pudo agregar el ítem", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  const basePrice = selectedItem
    ? (typeof selectedItem.itemPrice === "number" ? selectedItem.itemPrice : parseFloat(String(selectedItem.itemPrice ?? 0)) || 0)
    : 0

  const preview = mode === "fixed" && fixedPrice != null
    ? fixedPrice
    : mode === "adjustment" && selectedItem
      ? basePrice * (1 + (parseFloat(adjValue) || 0) / 100)
      : null

  return (
    <div className="rounded-lg border bg-muted/30 p-4">
      <p className="mb-3 text-sm font-medium">Agregar ítem con precio específico</p>
      <div className="flex flex-col gap-3">
        {/* Selector de ítem */}
        <ItemCombobox
          value={selectedItem}
          onChange={setSelectedItem}
          excludeIds={existingIds}
        />

        {selectedItem && (
          <>
            <div className="text-xs text-muted-foreground">
              Precio base:{" "}
              <span className="font-mono">
                {formatMoney(basePrice, bootstrap)}
              </span>
            </div>

            {/* Modo: precio fijo o ajuste % */}
            <div className="flex gap-3">
              <label className="flex items-center gap-2 cursor-pointer text-sm">
                <input
                  type="radio"
                  checked={mode === "fixed"}
                  onChange={() => setMode("fixed")}
                  className="accent-primary"
                />
                Precio fijo
              </label>
              <label className="flex items-center gap-2 cursor-pointer text-sm">
                <input
                  type="radio"
                  checked={mode === "adjustment"}
                  onChange={() => setMode("adjustment")}
                  className="accent-primary"
                />
                Ajuste %
              </label>
            </div>

            {mode === "fixed" ? (
              <MoneyInput
                value={fixedPrice}
                onChange={setFixedPrice}
                placeholder="Precio exacto"
              />
            ) : (
              <div className="flex items-center gap-2">
                <Input
                  type="number"
                  inputMode="decimal"
                  step="0.01"
                  value={adjValue}
                  onChange={(e) => setAdjValue(e.target.value)}
                  placeholder="0"
                  className="w-28 tabular-nums"
                />
                <span className="text-sm text-muted-foreground">%</span>
                <span className="text-xs text-muted-foreground">
                  {parseFloat(adjValue) < 0 ? "descuento" : "recargo"}
                </span>
              </div>
            )}

            {preview != null && (
              <p className="text-xs text-muted-foreground">
                Precio final: <span className="font-medium text-foreground">{formatMoney(preview, bootstrap)}</span>
              </p>
            )}
          </>
        )}

        <Button
          type="button"
          size="sm"
          onClick={onAdd}
          disabled={!selectedItem || setItems.isPending}
        >
          {setItems.isPending ? (
            <Loader2 className="mr-2 size-4 animate-spin" />
          ) : (
            <Plus className="mr-2 size-4" />
          )}
          Agregar ítem
        </Button>
      </div>
    </div>
  )
}

// ── Tabla de ítems ────────────────────────────────────────────────────────────

function PriceListItemRow({
  item,
  priceListId,
  existingItems,
}: {
  item: PriceListItem
  priceListId: string
  existingItems: PriceListItem[]
}) {
  const setItems = useSetPriceListItems()
  const { data: bootstrap } = useBootstrap()
  const [deleting, setDeleting] = React.useState(false)

  const onRemove = async () => {
    const newList = existingItems
      .filter((i) => i.itemId !== item.itemId)
      .map((i) => ({
        itemId: i.itemId,
        fixedPrice: i.fixedPrice,
        itemAdjustment: i.itemAdjustment,
      }))

    try {
      await setItems.mutateAsync({ priceListId, items: newList })
      toast.success(`${item.itemName} quitado de la lista`)
    } catch (err) {
      toast.error("No se pudo quitar el ítem", {
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setDeleting(false)
    }
  }

  const modeLabel = item.fixedPrice != null
    ? "Precio fijo"
    : item.itemAdjustment != null
      ? `Ajuste ${formatAdjSign(item.itemAdjustment)}`
      : "Sin override"

  return (
    <>
      <tr className="border-b text-sm last:border-0">
        <td className="px-3 py-2">
          <div className="flex flex-col">
            <span className="font-medium">{item.itemName}</span>
            {item.itemSKU && (
              <span className="text-xs text-muted-foreground">{item.itemSKU}</span>
            )}
          </div>
        </td>
        <td className="px-3 py-2 text-right tabular-nums text-muted-foreground">
          {formatMoney(item.basePrice, bootstrap)}
        </td>
        <td className="px-3 py-2 text-center text-xs text-muted-foreground">
          {modeLabel}
        </td>
        <td className="px-3 py-2 text-right tabular-nums font-medium">
          {formatMoney(item.resolvedPrice, bootstrap)}
          {item.resolvedPrice !== item.basePrice && (
            <span className={cn(
              "ml-1 text-xs",
              item.resolvedPrice < item.basePrice ? "text-emerald-600" : "text-amber-600"
            )}>
              ({item.resolvedPrice < item.basePrice ? "−" : "+"}{
                formatMoney(Math.abs(item.resolvedPrice - item.basePrice), bootstrap)
              })
            </span>
          )}
        </td>
        <td className="px-3 py-2">
          <Button
            variant="ghost"
            size="icon"
            className="size-7 text-muted-foreground hover:text-destructive"
            onClick={() => setDeleting(true)}
            disabled={setItems.isPending}
          >
            <X className="size-3.5" />
          </Button>
        </td>
      </tr>
      <AlertDialog open={deleting} onOpenChange={(o) => { if (!o) setDeleting(false) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Quitar &quot;{item.itemName}&quot;?</AlertDialogTitle>
            <AlertDialogDescription>
              El ítem volverá a usar el ajuste global de la lista.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={onRemove}>
              Quitar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

// ── Sheet de edición del encabezado ──────────────────────────────────────────

function EditHeaderSheet({
  open, onClose, priceListId, currentName, currentAdj, currentStatus, currentValidFrom, currentValidTo,
}: {
  open: boolean
  onClose: () => void
  priceListId: string
  currentName: string
  currentAdj: number
  currentStatus: boolean
  currentValidFrom: string | null
  currentValidTo: string | null
}) {
  const update = useUpdatePriceList()
  const [name,   setName]   = React.useState(currentName)
  const [adj,    setAdj]    = React.useState(String(currentAdj))
  const [status, setStatus] = React.useState(currentStatus)
  const [from,   setFrom]   = React.useState(currentValidFrom?.slice(0, 10) ?? "")
  const [to,     setTo]     = React.useState(currentValidTo?.slice(0, 10) ?? "")

  React.useEffect(() => {
    if (open) {
      setName(currentName)
      setAdj(String(currentAdj))
      setStatus(currentStatus)
      setFrom(currentValidFrom?.slice(0, 10) ?? "")
      setTo(currentValidTo?.slice(0, 10) ?? "")
    }
  }, [open, currentName, currentAdj, currentStatus, currentValidFrom, currentValidTo])

  const onSave = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      await update.mutateAsync({
        id: priceListId,
        payload: {
          name:              name.trim(),
          defaultAdjustment: parseFloat(adj) || 0,
          status,
          validFrom: from || null,
          validTo:   to   || null,
        },
      })
      toast.success("Lista actualizada")
      onClose()
    } catch (err) {
      toast.error("No se pudo guardar", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  const adjNum = parseFloat(adj) || 0

  return (
    <Sheet open={open} onOpenChange={(o) => { if (!o) onClose() }}>
      <SheetContent className="flex flex-col gap-0 sm:max-w-md">
        <SheetHeader className="pb-4">
          <SheetTitle>Editar lista</SheetTitle>
          <SheetDescription>Modificá los datos generales de la lista.</SheetDescription>
        </SheetHeader>
        <form onSubmit={onSave} className="flex flex-1 flex-col gap-5 overflow-y-auto pb-6">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="eh-name">Nombre</Label>
            <Input
              id="eh-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="eh-adj">Ajuste global (%)</Label>
            <div className="flex items-center gap-2">
              <Input
                id="eh-adj"
                type="number"
                inputMode="decimal"
                step="0.01"
                value={adj}
                onChange={(e) => setAdj(e.target.value)}
                className="w-28 tabular-nums"
              />
              <span className="text-sm text-muted-foreground">%</span>
              {adjNum !== 0 && (
                <Badge variant={adjNum < 0 ? "default" : "secondary"} className="shrink-0">
                  {adjNum < 0 ? "−" : "+"}{Math.abs(adjNum)}%{" "}
                  {adjNum < 0 ? "descuento" : "recargo"}
                </Badge>
              )}
            </div>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Vigencia</Label>
            <div className="grid grid-cols-2 gap-2">
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Desde</span>
                <DatePicker value={from} onChange={setFrom} placeholder="Sin inicio" />
              </div>
              <div className="flex flex-col gap-1">
                <span className="text-xs text-muted-foreground">Hasta</span>
                <DatePicker value={to} onChange={setTo} placeholder="Sin vencimiento" />
              </div>
            </div>
          </div>
          <div className="flex items-center justify-between rounded-md border p-3">
            <div>
              <p className="text-sm font-medium">Lista activa</p>
              <p className="text-xs text-muted-foreground">Las listas inactivas no se aplican.</p>
            </div>
            <Switch checked={status} onCheckedChange={setStatus} />
          </div>
          <div className="mt-auto pt-2">
            <Button type="submit" className="w-full" disabled={update.isPending}>
              {update.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
              Guardar
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export default function PriceListDetailPage() {
  const params = useParams<{ id: string }>()
  const router = useRouter()
  const id = params.id

  const { data, isLoading, error } = usePriceList(id)
  const deleteMut = useDeletePriceList()
  const { data: bootstrap } = useBootstrap()

  const [editOpen,   setEditOpen]   = React.useState(false)
  const [deleting,   setDeleting]   = React.useState(false)
  const [addRefresh, setAddRefresh] = React.useState(0)

  const items = data?.items ?? []

  const handleDelete = async () => {
    try {
      await deleteMut.mutateAsync(id)
      toast.success("Lista eliminada")
      router.push("/settings/price-lists")
    } catch (err) {
      toast.error("No se pudo eliminar", {
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setDeleting(false)
    }
  }

  if (error) {
    return (
      <div className="flex flex-col gap-4">
        <Button asChild variant="ghost" size="icon" className="size-8 self-start">
          <Link href="/settings/price-lists">
            <ArrowLeft className="size-4" />
          </Link>
        </Button>
        <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
          No se pudo cargar la lista. {error.message}
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <header className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <Button asChild variant="ghost" size="icon" className="size-8 shrink-0">
            <Link href="/settings/price-lists">
              <ArrowLeft className="size-4" />
            </Link>
          </Button>
          <div>
            {isLoading ? (
              <Skeleton className="h-7 w-48" />
            ) : (
              <h1 className="text-2xl font-semibold">{data?.priceListName}</h1>
            )}
            {data && (
              <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span className={cn(
                  "font-mono",
                  data.defaultAdjustment < 0 ? "text-emerald-600" :
                  data.defaultAdjustment > 0 ? "text-amber-600" : ""
                )}>
                  {formatAdjSign(data.defaultAdjustment)}
                </span>
                {(data.validFrom || data.validTo) && (
                  <>
                    <span>·</span>
                    <span>
                      {formatDate(data.validFrom, bootstrap)} – {formatDate(data.validTo, bootstrap)}
                    </span>
                  </>
                )}
                <span>·</span>
                <Badge variant={data.status ? "default" : "secondary"}>
                  {data.status ? "Activa" : "Inactiva"}
                </Badge>
              </div>
            )}
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-1.5"
            onClick={() => setEditOpen(true)}
            disabled={isLoading}
          >
            <Pencil className="size-3.5" />
            Editar
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="gap-1.5 text-destructive hover:text-destructive"
            onClick={() => setDeleting(true)}
            disabled={isLoading}
          >
            <Trash2 className="size-3.5" />
            Eliminar
          </Button>
        </div>
      </header>

      {/* Ítems con precio específico */}
      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold">Ítems con precio específico</h2>
          {items.length > 0 && (
            <span className="text-xs text-muted-foreground">
              {items.length} ítem{items.length !== 1 ? "s" : ""}
            </span>
          )}
        </div>

        {isLoading ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : items.length > 0 ? (
          <div className="rounded-md border">
            <table className="w-full text-sm">
              <thead className="border-b bg-muted/30 text-xs text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">Ítem</th>
                  <th className="w-28 px-3 py-2 text-right font-medium">Precio base</th>
                  <th className="w-28 px-3 py-2 text-center font-medium">Tipo</th>
                  <th className="w-36 px-3 py-2 text-right font-medium">Precio final</th>
                  <th className="w-10"></th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <PriceListItemRow
                    key={item.priceListItemId}
                    item={item}
                    priceListId={id}
                    existingItems={items}
                  />
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="rounded-md border border-dashed py-6 text-center text-sm text-muted-foreground">
            Sin ítems específicos — se aplica el ajuste global ({formatAdjSign(data?.defaultAdjustment ?? 0)}) a todos los productos.
          </p>
        )}

        {/* Panel agregar ítem */}
        {data && (
          <AddItemPanel
            key={addRefresh}
            priceListId={id}
            defaultAdjustment={data.defaultAdjustment}
            existingItems={items}
            onSaved={() => setAddRefresh((n) => n + 1)}
          />
        )}
      </section>

      {/* Info de asignación */}
      <section className="rounded-lg border bg-muted/20 p-4 text-sm text-muted-foreground">
        <p className="font-medium text-foreground">Asignación de esta lista</p>
        <p className="mt-1">
          Podés asignar esta lista a un cliente desde su perfil (tab Datos → Lista de precios)
          o a una sucursal desde la configuración de sucursales.
          La lista también se puede elegir manualmente en la caja.
        </p>
      </section>

      {/* Modales */}
      {data && (
        <EditHeaderSheet
          open={editOpen}
          onClose={() => setEditOpen(false)}
          priceListId={id}
          currentName={data.priceListName}
          currentAdj={data.defaultAdjustment}
          currentStatus={data.status}
          currentValidFrom={data.validFrom}
          currentValidTo={data.validTo}
        />
      )}

      <AlertDialog open={deleting} onOpenChange={(o) => { if (!o) setDeleting(false) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Eliminar &quot;{data?.priceListName}&quot;?</AlertDialogTitle>
            <AlertDialogDescription>
              Se eliminarán también todos los precios por ítem. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              disabled={deleteMut.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMut.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
