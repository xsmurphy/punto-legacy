"use client"

import * as React from "react"
import { toast } from "sonner"
import { Check, ChevronsUpDown, Loader2, Plus, Trash2, GripVertical, Package2 } from "lucide-react"
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
  useItems,
  usePackComponents,
  useSetPackComponents,
} from "@/hooks/use-items"
import type { ItemListItem, PackComponent } from "@/lib/types/item"
import { cn } from "@/lib/utils"

interface Props {
  itemId: string
}

/**
 * Editor de componentes de un pack de servicios.
 *
 * Carga los componentes del pack (GET /v1/pack_component?packItemId=<id>),
 * permite agregar / eliminar / editar cantidades, y al confirmar reemplaza
 * todo (PUT /v1/pack_component). El estado se mantiene LOCAL (draft list)
 * para permitir edición sin guardar, y se sincroniza al back con el botón
 * "Guardar componentes".
 */
export function PackComponentsEditor({ itemId }: Props) {
  const { data: remote, isLoading } = usePackComponents(itemId)
  const setComponents = useSetPackComponents()

  // Draft local: arreglo mutable de componentes. Se inicializa desde el
  // backend al cargar. Ediciones son locales hasta "Guardar".
  const [draft, setDraft] = React.useState<DraftComponent[]>([])
  const [dirty, setDirty] = React.useState(false)

  // Sincronizar desde backend solo al cargar inicial (o si no hay draft dirty).
  React.useEffect(() => {
    if (!dirty && remote) {
      setDraft(remote.map((c) => ({ ...c, _key: c.packComponentId })))
    }
  }, [remote, dirty])

  const addComponent = (item: ItemListItem) => {
    if (draft.some((d) => d.componentItemId === item.itemId)) {
      toast.info("Ese servicio ya está en el pack")
      return
    }
    setDraft((prev) => [
      ...prev,
      {
        _key: `new-${Date.now()}-${item.itemId}`,
        packComponentId: "",
        componentItemId: item.itemId,
        componentName: item.itemName,
        componentQty: 1,
        sort: prev.length,
      },
    ])
    setDirty(true)
  }

  const updateQty = (key: string, qty: number) => {
    setDraft((prev) =>
      prev.map((d) => (d._key === key ? { ...d, componentQty: Math.max(1, qty) } : d)),
    )
    setDirty(true)
  }

  const remove = (key: string) => {
    setDraft((prev) => prev.filter((d) => d._key !== key))
    setDirty(true)
  }

  const save = async () => {
    try {
      await setComponents.mutateAsync({
        packItemId: itemId,
        components: draft.map((d, i) => ({
          componentItemId: d.componentItemId,
          componentName: d.componentName,
          componentQty: d.componentQty,
          sort: i,
        })),
      })
      setDirty(false)
      toast.success("Componentes del pack guardados")
    } catch (e) {
      toast.error("No se pudieron guardar los componentes", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-16 items-center justify-center text-sm text-muted-foreground">
        <Loader2 className="mr-2 size-4 animate-spin" />
        Cargando componentes…
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {draft.length === 0 && (
        <EmptyState
          icon={Package2}
          title="Sin servicios todavía"
          description="Agregá los servicios que incluye este pack."
          showMarquee={false}
          className="border-dashed py-6"
        />
      )}

      {draft.length > 0 && (
        <div className="rounded-md border">
          <table className="w-full text-sm">
            <thead className="border-b bg-muted/30 text-xs text-muted-foreground">
              <tr>
                <th className="w-6"></th>
                <th className="px-3 py-2 text-left font-medium">Servicio / Item</th>
                <th className="w-28 px-3 py-2 text-right font-medium">Cantidad incluida</th>
                <th className="w-12"></th>
              </tr>
            </thead>
            <tbody>
              {draft.map((comp) => (
                <DraftRow
                  key={comp._key}
                  comp={comp}
                  onQtyChange={(qty) => updateQty(comp._key, qty)}
                  onRemove={() => remove(comp._key)}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Picker para agregar componentes */}
      <ServicePicker
        excludeIds={[itemId, ...draft.map((d) => d.componentItemId)]}
        onPick={addComponent}
      />

      {/* Botón de guardar — solo visible cuando hay cambios pendientes */}
      {dirty && (
        <div className="flex justify-end">
          <Button
            type="button"
            size="sm"
            onClick={save}
            disabled={setComponents.isPending}
          >
            {setComponents.isPending && <Loader2 className="mr-2 size-3.5 animate-spin" />}
            Guardar componentes
          </Button>
        </div>
      )}
    </div>
  )
}

// ── Fila editable del draft ───────────────────────────────────────────────

interface DraftComponent extends PackComponent {
  /** Key local estable para React (puede ser packComponentId o temporal). */
  _key: string
}

function DraftRow({
  comp,
  onQtyChange,
  onRemove,
}: {
  comp: DraftComponent
  onQtyChange: (qty: number) => void
  onRemove: () => void
}) {
  const [draft, setDraft] = React.useState(String(comp.componentQty))

  React.useEffect(() => setDraft(String(comp.componentQty)), [comp.componentQty])

  const commit = () => {
    const n = parseInt(draft.replace(",", "."), 10)
    if (Number.isFinite(n) && n > 0) {
      onQtyChange(n)
    } else {
      setDraft(String(comp.componentQty))
    }
  }

  return (
    <tr className="border-b last:border-b-0 hover:bg-muted/20">
      <td className="px-2 py-2 text-muted-foreground">
        <GripVertical className="size-3.5 opacity-30" />
      </td>
      <td className="px-3 py-2">
        <span className="font-medium">{comp.componentName || "(sin nombre)"}</span>
      </td>
      <td className="px-3 py-2">
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
          className="h-7 text-right text-xs tabular-nums"
          inputMode="numeric"
        />
      </td>
      <td className="px-2 py-2">
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-6 text-muted-foreground hover:text-destructive"
          onClick={onRemove}
        >
          <Trash2 className="size-3.5" />
        </Button>
      </td>
    </tr>
  )
}

// ── Picker de servicio/item con autocomplete ───────────────────────────────

function ServicePicker({
  excludeIds,
  onPick,
}: {
  excludeIds: string[]
  onPick: (item: ItemListItem) => void
}) {
  const [open, setOpen] = React.useState(false)
  const [search, setSearch] = React.useState("")

  const { data, isLoading } = useItems({ q: search || undefined })

  const items = React.useMemo(() => {
    const all = data?.items ?? []
    return all.filter((i) => !excludeIds.includes(i.itemId))
  }, [data, excludeIds])

  return (
    <div className="flex flex-wrap items-end gap-2 rounded-md border bg-muted/20 p-3">
      <div className="flex flex-1 flex-col gap-1.5 min-w-[240px]">
        <label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
          Agregar servicio al pack
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
              <span className="text-muted-foreground">Buscar servicio o producto…</span>
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
                        onPick(it)
                        setOpen(false)
                        setSearch("")
                      }}
                    >
                      <Check className={cn("mr-2 size-3.5 opacity-0")} />
                      <div className="flex flex-1 flex-col">
                        <span className="text-sm">{it.itemName}</span>
                        <span className="text-[10px] text-muted-foreground tabular-nums">
                          {it.itemSKU || "—"} · {it.kind}
                        </span>
                      </div>
                      {it.kind && (
                        <Badge variant="secondary" className="text-[9px] font-normal">
                          {it.kind}
                        </Badge>
                      )}
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      </div>
    </div>
  )
}
