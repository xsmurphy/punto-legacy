"use client"

import * as React from "react"
import { toast } from "sonner"
import {
  Check,
  ChevronsUpDown,
  Loader2,
  Plus,
  Star,
  Trash2,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { Checkbox } from "@/components/ui/checkbox"
import { Card, CardContent } from "@/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs"
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
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import {
  useComboGroups,
  useCreateComboGroup,
  useDeleteComboGroup,
  useItems,
  useTaxonomiesByType,
  useUpdateComboGroup,
} from "@/hooks/use-items"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"
import type {
  ComboGroup,
  ComboGroupItem,
  ItemListItem,
} from "@/lib/types/item"
import { cn } from "@/lib/utils"

interface Props {
  itemId: string
}

export function ComboGroupsEditor({ itemId }: Props) {
  const { data, isLoading } = useComboGroups(itemId)
  const create = useCreateComboGroup()
  const [showNewDialog, setShowNewDialog] = React.useState(false)

  const groups = data?.groups ?? []

  if (isLoading) {
    return (
      <div className="flex h-20 items-center justify-center text-sm text-muted-foreground">
        <Loader2 className="mr-2 size-4 animate-spin" />
        Cargando grupos…
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {groups.length === 0 && (
        <div className="rounded-md border border-dashed bg-muted/20 px-4 py-6 text-center text-xs text-muted-foreground">
          Sin grupos todavía. Agregá el primero abajo. Cada grupo representa
          una decisión que el cliente toma al armar el combo (ej:{" "}
          <em>elegí 1 hamburguesa</em>, <em>elegí 1 bebida</em>).
        </div>
      )}

      {groups.map((g) => (
        <GroupCard key={g.groupId} itemId={itemId} group={g} />
      ))}

      <NewGroupDialog
        open={showNewDialog}
        onOpenChange={setShowNewDialog}
        onCreate={async (name) => {
          try {
            await create.mutateAsync({
              itemId,
              input: {
                name,
                sourceType: "items",
                minSelection: 1,
                maxSelection: 1,
                items: [],
              },
            })
            toast.success("Grupo creado")
            setShowNewDialog(false)
          } catch (e) {
            toast.error("No se pudo crear el grupo", {
              description: e instanceof Error ? e.message : undefined,
            })
          }
        }}
        loading={create.isPending}
      />

      <Button
        type="button"
        variant="outline"
        className="w-fit gap-1.5"
        onClick={() => setShowNewDialog(true)}
        disabled={create.isPending}
      >
        <Plus className="size-4" />
        Agregar grupo
      </Button>
    </div>
  )
}

// ── New group dialog ─────────────────────────────────────────────────────────

function NewGroupDialog({
  open,
  onOpenChange,
  onCreate,
  loading,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  onCreate: (name: string) => Promise<void>
  loading: boolean
}) {
  const [name, setName] = React.useState("")
  React.useEffect(() => {
    if (open) setName("")
  }, [open])

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Nuevo grupo</AlertDialogTitle>
          <AlertDialogDescription>
            Dale un nombre al grupo (ej: <em>Hamburguesa</em>, <em>Bebida</em>,{" "}
            <em>Extras</em>). Podés configurar la fuente (items específicos o
            categoría) y los items después.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <div className="flex flex-col gap-1.5 py-2">
          <Label className="text-xs">Nombre del grupo</Label>
          <Input
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Ej: Hamburguesa"
            onKeyDown={(e) => {
              if (e.key === "Enter" && name.trim()) {
                e.preventDefault()
                onCreate(name.trim())
              }
            }}
          />
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            onClick={async (e) => {
              e.preventDefault()
              if (!name.trim()) return
              await onCreate(name.trim())
            }}
            disabled={!name.trim() || loading}
          >
            {loading && <Loader2 className="size-3.5 animate-spin" />}
            Crear grupo
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

// ── Group card ───────────────────────────────────────────────────────────────

function GroupCard({ itemId, group }: { itemId: string; group: ComboGroup }) {
  const update = useUpdateComboGroup()
  const remove = useDeleteComboGroup()

  // Draft local del header (name, min, max, sourceType). Commit on blur.
  const [name, setName] = React.useState(group.name)
  const [min, setMin] = React.useState(String(group.minSelection))
  const [max, setMax] = React.useState(String(group.maxSelection))

  React.useEffect(() => {
    setName(group.name)
    setMin(String(group.minSelection))
    setMax(String(group.maxSelection))
  }, [group])

  const commitField = async (patch: Record<string, unknown>) => {
    try {
      await update.mutateAsync({ itemId, groupId: group.groupId, input: patch })
    } catch (e) {
      toast.error("No se pudo actualizar el grupo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const onSourceTypeChange = async (next: "items" | "category") => {
    if (next === group.sourceType) return
    await commitField({ sourceType: next, sourceCategoryId: null })
  }

  const onCategoryChange = async (categoryId: string) => {
    await commitField({ sourceCategoryId: categoryId })
  }

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 p-4">
        {/* Header: nombre + min/max + delete */}
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex-1 min-w-[200px] flex-col gap-1.5">
            <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
              Nombre del grupo
            </Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              onBlur={() => name.trim() !== group.name && commitField({ name: name.trim() })}
              onKeyDown={(e) => {
                if (e.key === "Enter") (e.currentTarget as HTMLInputElement).blur()
              }}
              className="h-9"
            />
          </div>
          <div className="flex flex-col gap-1.5 w-20">
            <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
              Mín
            </Label>
            <Input
              value={min}
              onChange={(e) => setMin(e.target.value)}
              onBlur={() => {
                const n = parseInt(min, 10)
                if (Number.isFinite(n) && n !== group.minSelection) {
                  commitField({ minSelection: n })
                }
              }}
              className="h-9 text-center tabular-nums"
              inputMode="numeric"
            />
          </div>
          <div className="flex flex-col gap-1.5 w-20">
            <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
              Máx
            </Label>
            <Input
              value={max}
              onChange={(e) => setMax(e.target.value)}
              onBlur={() => {
                const n = parseInt(max, 10)
                if (Number.isFinite(n) && n !== group.maxSelection) {
                  commitField({ maxSelection: n })
                }
              }}
              className="h-9 text-center tabular-nums"
              inputMode="numeric"
            />
          </div>
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-9 text-muted-foreground hover:text-destructive"
                aria-label="Eliminar grupo"
              >
                <Trash2 className="size-4" />
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>
                  ¿Eliminar el grupo &quot;{group.name}&quot;?
                </AlertDialogTitle>
                <AlertDialogDescription>
                  Esta acción no se puede deshacer. Se eliminan también los
                  items específicos del grupo (si los tenía).
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction
                  onClick={async () => {
                    try {
                      await remove.mutateAsync({ itemId, groupId: group.groupId })
                      toast.success("Grupo eliminado")
                    } catch (e) {
                      toast.error("No se pudo eliminar", {
                        description: e instanceof Error ? e.message : undefined,
                      })
                    }
                  }}
                >
                  Eliminar
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>

        {/* Source type tabs */}
        <Tabs
          value={group.sourceType}
          onValueChange={(v) => onSourceTypeChange(v as "items" | "category")}
        >
          <TabsList className="grid w-full max-w-sm grid-cols-2">
            <TabsTrigger value="items" className="text-xs">
              Items específicos
            </TabsTrigger>
            <TabsTrigger value="category" className="text-xs">
              Por categoría
            </TabsTrigger>
          </TabsList>

          <TabsContent value="items" className="mt-3">
            <GroupItemsList itemId={itemId} group={group} />
          </TabsContent>

          <TabsContent value="category" className="mt-3">
            <GroupCategoryPicker
              currentCategoryId={group.sourceCategoryId}
              currentCategoryName={group.sourceCategoryName}
              onChange={onCategoryChange}
            />
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  )
}

// ── Group items list (sourceType='items') ───────────────────────────────────

function GroupItemsList({
  itemId,
  group,
}: {
  itemId: string
  group: ComboGroup
}) {
  const update = useUpdateComboGroup()
  const { data: bootstrap } = useBootstrap()

  const replaceItems = async (next: ComboGroupItem[]) => {
    try {
      await update.mutateAsync({
        itemId,
        groupId: group.groupId,
        input: {
          items: next.map((i, idx) => ({
            childItemId: i.childItemId,
            extraPrice: i.extraPrice,
            isPreselected: i.isPreselected,
            sort: idx,
          })),
        },
      })
    } catch (e) {
      toast.error("No se pudo actualizar el grupo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const addItem = async (it: ItemListItem) => {
    const exists = group.items.some((i) => i.childItemId === it.itemId)
    if (exists) {
      toast.warning("Ese item ya está en el grupo")
      return
    }
    const next: ComboGroupItem[] = [
      ...group.items,
      {
        groupItemId: "",
        childItemId: it.itemId,
        extraPrice: 0,
        isPreselected: false,
        sort: group.items.length,
        childName: it.itemName,
        childSKU: it.itemSKU,
        childPrice: typeof it.itemPrice === "string" ? Number(it.itemPrice) : (it.itemPrice ?? 0),
        childUOM: it.itemUOM ?? null,
        childKind: it.kind,
      },
    ]
    await replaceItems(next)
  }

  const updateLine = async (childItemId: string, patch: Partial<ComboGroupItem>) => {
    const next = group.items.map((i) =>
      i.childItemId === childItemId ? { ...i, ...patch } : i,
    )
    await replaceItems(next)
  }

  const removeLine = async (childItemId: string) => {
    const next = group.items.filter((i) => i.childItemId !== childItemId)
    await replaceItems(next)
  }

  return (
    <div className="flex flex-col gap-3">
      {group.items.length === 0 && (
        <div className="rounded-md border border-dashed bg-muted/20 px-3 py-4 text-center text-xs text-muted-foreground">
          Sin items en este grupo. Agregá uno abajo.
        </div>
      )}

      {group.items.length > 0 && (
        <div className="rounded-md border">
          <table className="w-full text-sm">
            <thead className="border-b bg-muted/30 text-xs text-muted-foreground">
              <tr>
                <th className="px-3 py-2 text-left font-medium">Item</th>
                <th className="w-28 px-3 py-2 text-right font-medium">Extra</th>
                <th className="w-24 px-3 py-2 text-center font-medium">Por defecto</th>
                <th className="w-12"></th>
              </tr>
            </thead>
            <tbody>
              {group.items.map((it) => (
                <ItemRow
                  key={it.childItemId}
                  item={it}
                  bootstrap={bootstrap}
                  onUpdate={(patch) => updateLine(it.childItemId, patch)}
                  onRemove={() => removeLine(it.childItemId)}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      <ItemPicker excludeIds={group.items.map((i) => i.childItemId)} onPick={addItem} />
    </div>
  )
}

function ItemRow({
  item,
  bootstrap,
  onUpdate,
  onRemove,
}: {
  item: ComboGroupItem
  bootstrap: ReturnType<typeof useBootstrap>["data"]
  onUpdate: (patch: Partial<ComboGroupItem>) => Promise<void>
  onRemove: () => Promise<void>
}) {
  const [extra, setExtra] = React.useState(String(item.extraPrice))
  React.useEffect(() => setExtra(String(item.extraPrice)), [item.extraPrice])

  return (
    <tr className="border-b last:border-b-0 hover:bg-muted/20">
      <td className="px-3 py-2">
        <div className="flex flex-col">
          <span className="font-medium">{item.childName || "(sin nombre)"}</span>
          <span className="text-[10px] text-muted-foreground tabular-nums">
            Precio base: {formatMoney(item.childPrice, bootstrap)}
            {item.childSKU && ` · ${item.childSKU}`}
          </span>
        </div>
      </td>
      <td className="px-3 py-2">
        <Input
          value={extra}
          onChange={(e) => setExtra(e.target.value)}
          onBlur={() => {
            const n = Number(extra.replace(",", "."))
            if (Number.isFinite(n) && n !== item.extraPrice) {
              onUpdate({ extraPrice: n })
            }
          }}
          onKeyDown={(e) => {
            if (e.key === "Enter") (e.currentTarget as HTMLInputElement).blur()
          }}
          className="h-7 text-right text-xs tabular-nums"
          inputMode="decimal"
        />
      </td>
      <td className="px-3 py-2 text-center">
        <Checkbox
          checked={item.isPreselected}
          onCheckedChange={(v) => onUpdate({ isPreselected: !!v })}
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

// ── Item picker ──────────────────────────────────────────────────────────────

function ItemPicker({
  onPick,
  excludeIds,
}: {
  onPick: (item: ItemListItem) => Promise<void>
  excludeIds: string[]
}) {
  const [open, setOpen] = React.useState(false)
  const [search, setSearch] = React.useState("")
  const { data, isLoading } = useItems({ q: search || undefined })

  const items = React.useMemo(
    () => (data?.items ?? []).filter((i) => !excludeIds.includes(i.itemId)),
    [data, excludeIds],
  )

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button type="button" variant="outline" size="sm" className="w-fit gap-1.5">
          <Plus className="size-3.5" />
          Agregar item
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[320px] p-0" align="start">
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
                  onSelect={async () => {
                    setOpen(false)
                    setSearch("")
                    await onPick(it)
                  }}
                >
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
  )
}

// ── Category picker ──────────────────────────────────────────────────────────

function GroupCategoryPicker({
  currentCategoryId,
  currentCategoryName,
  onChange,
}: {
  currentCategoryId: string | null
  currentCategoryName: string | null
  onChange: (categoryId: string) => Promise<void>
}) {
  const { data: cats, isLoading } = useTaxonomiesByType("category")

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-1.5">
        <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
          Categoría
        </Label>
        <Select
          value={currentCategoryId ?? ""}
          onValueChange={(v) => onChange(v)}
        >
          <SelectTrigger className="h-9">
            <SelectValue
              placeholder={isLoading ? "Cargando…" : "Elegí una categoría"}
            />
          </SelectTrigger>
          <SelectContent>
            {(cats ?? []).map((c) => (
              <SelectItem key={c.id} value={c.id}>
                {c.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {currentCategoryName && (
          <p className="text-xs text-muted-foreground">
            El cliente puede elegir cualquier item de la categoría{" "}
            <strong>{currentCategoryName}</strong> al armar el combo.
          </p>
        )}
      </div>
    </div>
  )
}
