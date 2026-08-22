"use client"

import * as React from "react"
import { toast } from "sonner"
import { Check, ChevronsUpDown, Loader2, Plus, Trash2, GripVertical, ChefHat } from "lucide-react"
import { EmptyState } from "@/components/empty-state"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
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
import { Badge } from "@/components/ui/badge"
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  useAddCompound,
  useDeleteCompound,
  useItemCompounds,
  useItems,
  useUpdateCompoundQuantity,
} from "@/hooks/use-items"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"
import type { ItemCompound, ItemListItem } from "@/lib/types/item"
import { cn } from "@/lib/utils"

interface Props {
  itemId: string
}

export function CompoundsEditor({ itemId }: Props) {
  const { data, isLoading } = useItemCompounds(itemId)
  const { data: bootstrap } = useBootstrap()
  const add = useAddCompound()
  const compounds = data?.compounds ?? []

  // Los dos totales vienen del servidor (`ItemCompoundService::recipe`). El
  // front NO los recalcula: sumar `lineCost` acá era la tercera fórmula de
  // costo de receta del sistema, y la que le mostraba al dueño un número
  // distinto al que la venta registraba (reporte del tester "Actualización 21"
  // #1). La fórmula única vive en `RecipeCosting` (PHP).
  const totals = data?.totals

  return (
    <div className="flex flex-col gap-4">
      {isLoading && (
        <div className="flex h-20 items-center justify-center text-sm text-muted-foreground">
          <Loader2 className="mr-2 size-4 animate-spin" />
          Cargando receta…
        </div>
      )}

      {!isLoading && compounds.length === 0 && (
        <EmptyState
          icon={ChefHat}
          title="Sin ingredientes todavía"
          description="Agregá el primero abajo."
          showMarquee={false}
          className="border-dashed py-6"
        />
      )}

      {compounds.length > 0 && (
        <div className="space-y-2">
          <div className="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-6" />
                  <TableHead>Ingrediente</TableHead>
                  <TableHead className="w-24 text-right">Cantidad</TableHead>
                  <TableHead className="w-20">UOM</TableHead>
                  <TableHead className="w-32 text-right">Costo de catálogo</TableHead>
                  <TableHead className="w-32 text-right">Costo real hoy</TableHead>
                  <TableHead className="w-12" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {compounds.map((c) => (
                  <CompoundRow
                    key={c.compoundId}
                    itemId={itemId}
                    compound={c}
                    bootstrap={bootstrap}
                  />
                ))}
              </TableBody>
              <TableFooter>
                <TableRow>
                  <TableCell colSpan={4} className="text-right text-xs font-medium text-muted-foreground">
                    Costo total de la receta
                  </TableCell>
                  <TableCell className="text-right text-sm font-semibold tabular-nums">
                    {formatMoney(totals?.catalogTotal ?? 0, bootstrap)}
                  </TableCell>
                  <TableCell className="text-right text-sm font-semibold tabular-nums">
                    {totals?.currentTotal == null
                      ? "—"
                      : formatMoney(totals.currentTotal, bootstrap)}
                  </TableCell>
                  <TableCell />
                </TableRow>
              </TableFooter>
            </Table>
          </div>
          <p className="text-xs text-muted-foreground">
            <span className="font-medium">Costo de catálogo</span>: el costo que cargaste en
            cada ingrediente.{" "}
            <span className="font-medium">Costo real hoy</span>: lo que cuesta producir una
            unidad en esta sucursal ahora — promedio de compra de cada insumo, merma incluida,
            bajando hasta los insumos de las sub-preparaciones. Es el costo que se registra al
            vender.
          </p>
        </div>
      )}

      <IngredientPicker
        onPick={async (childItemId, quantity) => {
          try {
            await add.mutateAsync({ itemId, childItemId, quantity })
            toast.success("Ingrediente agregado")
          } catch (e) {
            toast.error("No se pudo agregar", {
              description: e instanceof Error ? e.message : undefined,
            })
          }
        }}
        excludeIds={[itemId, ...compounds.map((c) => c.childItemId)]}
        loading={add.isPending}
      />
    </div>
  )
}

// ── Row con cantidad editable + delete ────────────────────────────────────────

function CompoundRow({
  itemId,
  compound,
  bootstrap,
}: {
  itemId: string
  compound: ItemCompound
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  const updateQty = useUpdateCompoundQuantity()
  const remove = useDeleteCompound()
  const [draft, setDraft] = React.useState<string>(String(compound.quantity))

  React.useEffect(() => setDraft(String(compound.quantity)), [compound.quantity])

  const commit = async () => {
    const n = Number(draft.replace(",", "."))
    if (!Number.isFinite(n) || n <= 0) {
      setDraft(String(compound.quantity))
      return
    }
    if (n === compound.quantity) return
    try {
      await updateQty.mutateAsync({ itemId, compoundId: compound.compoundId, quantity: n })
    } catch (e) {
      setDraft(String(compound.quantity))
      toast.error("No se pudo actualizar la cantidad", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <TableRow>
      <TableCell className="text-muted-foreground">
        <GripVertical className="size-3.5 opacity-30" />
      </TableCell>
      <TableCell>
        <div className="flex flex-col">
          <span className="font-medium">{compound.childName || "(sin nombre)"}</span>
          {compound.childSKU && (
            <span className="text-xs text-muted-foreground tabular-nums">
              {compound.childSKU}
            </span>
          )}
        </div>
      </TableCell>
      <TableCell>
        <Input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault()
              ;(e.currentTarget as HTMLInputElement).blur()
            }
          }}
          // h-7: la fila es editable y hay 6 columnas — un Input de altura
          // default rompe el ritmo vertical de la tabla de receta.
          className="h-7 text-right text-xs tabular-nums"
          inputMode="decimal"
        />
      </TableCell>
      <TableCell className="text-xs text-muted-foreground">
        {compound.childUOM || "—"}
      </TableCell>
      <TableCell className="text-right tabular-nums text-muted-foreground">
        <div className="flex flex-col items-end">
          <span>{formatMoney(compound.lineCatalogCost, bootstrap)}</span>
          <span className="text-xs">
            {formatMoney(compound.catalogCost, bootstrap)} c/u
          </span>
        </div>
      </TableCell>
      <TableCell className="text-right font-medium tabular-nums">
        {compound.lineCurrentCost == null ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          <div className="flex flex-col items-end">
            <span>{formatMoney(compound.lineCurrentCost, bootstrap)}</span>
            <span className="text-xs font-normal text-muted-foreground">
              {formatMoney(compound.currentCost ?? 0, bootstrap)} c/u
            </span>
          </div>
        )}
      </TableCell>
      <TableCell>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-6 text-muted-foreground hover:text-destructive"
          onClick={async () => {
            try {
              await remove.mutateAsync({ itemId, compoundId: compound.compoundId })
            } catch (e) {
              toast.error("No se pudo eliminar", {
                description: e instanceof Error ? e.message : undefined,
              })
            }
          }}
          disabled={remove.isPending}
        >
          <Trash2 className="size-3.5" />
        </Button>
      </TableCell>
    </TableRow>
  )
}

// ── Picker de ingrediente con autocomplete ────────────────────────────────────

function IngredientPicker({
  onPick,
  excludeIds,
  loading,
}: {
  onPick: (childItemId: string, quantity: number) => Promise<void>
  excludeIds: string[]
  loading?: boolean
}) {
  const [open, setOpen] = React.useState(false)
  const [search, setSearch] = React.useState("")
  const [selected, setSelected] = React.useState<ItemListItem | null>(null)
  const [qty, setQty] = React.useState<string>("1")

  // Carga items para el picker. Sin filtro de q, devuelve hasta 200 items;
  // si el usuario escribe, /v1/items?q= filtra por nombre/SKU.
  const { data, isLoading } = useItems({ q: search || undefined })

  const items = React.useMemo(() => {
    const all = data?.items ?? []
    return all.filter((i) => !excludeIds.includes(i.itemId))
  }, [data, excludeIds])

  const submit = async () => {
    if (!selected) return
    const n = Number(qty.replace(",", "."))
    if (!Number.isFinite(n) || n <= 0) {
      toast.error("La cantidad debe ser mayor a 0")
      return
    }
    await onPick(selected.itemId, n)
    setSelected(null)
    setQty("1")
    setSearch("")
  }

  return (
    <div className="flex flex-wrap items-end gap-2 rounded-md border bg-muted/20 p-3">
      <div className="flex flex-1 flex-col gap-1.5 min-w-[240px]">
        <label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
          Ingrediente
        </label>
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              role="combobox"
              aria-expanded={open}
              className="h-9 justify-between font-normal"
            >
              {selected ? (
                <span className="flex items-center gap-2 truncate">
                  <span className="truncate">{selected.itemName}</span>
                  {selected.kind && (
                    <Badge variant="secondary" className="text-[9px] font-normal">
                      {selected.kind}
                    </Badge>
                  )}
                </span>
              ) : (
                <span className="text-muted-foreground">Buscar ingrediente…</span>
              )}
              <ChevronsUpDown className="size-3.5 opacity-50" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
            <Command shouldFilter={false}>
              <CommandInput
                placeholder="Buscar por nombre o SKU…"
                value={search}
                onValueChange={setSearch}
              />
              <CommandList>
                {isLoading && (
                  <div className="flex items-center justify-center py-6 text-xs text-muted-foreground">
                    <Loader2 className="mr-2 size-3.5 animate-spin" />
                    Cargando…
                  </div>
                )}
                {!isLoading && items.length === 0 && (
                  <CommandEmpty>Sin resultados.</CommandEmpty>
                )}
                <CommandGroup>
                  {items.map((it) => (
                    <CommandItem
                      key={it.itemId}
                      value={it.itemId}
                      onSelect={() => {
                        setSelected(it)
                        setOpen(false)
                      }}
                    >
                      <Check
                        className={cn(
                          "mr-2 size-3.5",
                          selected?.itemId === it.itemId ? "opacity-100" : "opacity-0",
                        )}
                      />
                      <div className="flex flex-1 flex-col">
                        <span className="text-sm">{it.itemName}</span>
                        <span className="text-[10px] text-muted-foreground tabular-nums">
                          {it.itemSKU || "—"} · {it.kind}
                        </span>
                      </div>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      </div>

      <div className="flex flex-col gap-1.5 w-24">
        <label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
          Cantidad
        </label>
        <Input
          value={qty}
          onChange={(e) => setQty(e.target.value)}
          className="h-9 text-right tabular-nums"
          inputMode="decimal"
        />
      </div>

      <Button
        type="button"
        onClick={submit}
        disabled={!selected || loading}
        className="h-9 gap-1.5"
      >
        {loading ? <Loader2 className="size-3.5 animate-spin" /> : <Plus className="size-3.5" />}
        Agregar
      </Button>
    </div>
  )
}
