"use client"

import * as React from "react"
import { toast } from "sonner"
import {
  Copy,
  Layers,
  Loader2,
  Plus,
  Save,
  Trash2,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { MoneyInput } from "@/components/ui/money-input"
import { EmptyState } from "@/components/empty-state"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
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
import { useItems } from "@/hooks/use-items"
import {
  useCopyItemAddons,
  useItemAddons,
  useReplaceItemAddons,
  type AddonGroup,
  type AddonGroupInput,
} from "@/hooks/use-item-addons"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"
import type { ItemListItem } from "@/lib/types/item"
import { cn } from "@/lib/utils"

interface Props {
  itemId: string
  /**
   * Valores con los que arranca un grupo recién creado (F5, context/41).
   *
   * Default `{ minSelect: 0, maxSelect: null }` = grupo opcional sin tope, que
   * es lo correcto para un producto común: los add-ons no deberían frenar una
   * venta. Un `combo_dinamico` pasa `{ minSelect: 1, maxSelect: 1 }` porque su
   * semántica es la opuesta — "elegí 1 hamburguesa" es una decisión OBLIGATORIA
   * y única, y arrancar en opcional obligaría a corregir cada grupo a mano.
   *
   * Es solo el punto de partida del draft: el dueño lo edita después como
   * cualquier otro grupo.
   */
  newGroupPreset?: { minSelect: number; maxSelect: number | null }
}

const DEFAULT_NEW_GROUP_PRESET = { minSelect: 0, maxSelect: null } as const

// ── Draft shapes (estado local, editable, sin persistir hasta "Guardar") ──

interface DraftOption {
  clientId: string
  itemId: string
  itemName: string
  itemPrice: number
  priceDelta: number
  isDefault: boolean
  isLocked: boolean
  maxQty: number
}

interface DraftGroup {
  clientId: string
  name: string
  minSelect: number
  maxSelect: number | null
  status: boolean
  options: DraftOption[]
}

let clientIdSeq = 0
function nextClientId(): string {
  clientIdSeq += 1
  return `draft-${clientIdSeq}`
}

function toDraft(groups: AddonGroup[]): DraftGroup[] {
  return groups.map((g) => ({
    clientId: nextClientId(),
    name: g.name,
    minSelect: g.minSelect,
    maxSelect: g.maxSelect,
    status: g.status,
    options: g.options.map((o) => ({
      clientId: nextClientId(),
      itemId: o.itemId,
      itemName: o.itemName,
      itemPrice: o.itemPrice,
      priceDelta: o.priceDelta,
      isDefault: o.isDefault,
      isLocked: o.isLocked,
      maxQty: o.maxQty,
    })),
  }))
}

function toInput(groups: DraftGroup[]): AddonGroupInput[] {
  return groups.map((g, gi) => ({
    name: g.name.trim(),
    minSelect: g.minSelect,
    maxSelect: g.maxSelect,
    sort: gi,
    status: g.status,
    options: g.options.map((o, oi) => ({
      itemId: o.itemId,
      priceDelta: o.priceDelta,
      isDefault: o.isDefault,
      isLocked: o.isLocked,
      maxQty: o.maxQty,
      sort: oi,
    })),
  }))
}

/** Reglas que igual revalida el server (context/41, D-decisiones) — se
 * chequean acá solo para dar feedback inmediato, nunca son la fuente de
 * verdad. */
function validateDraft(groups: DraftGroup[], itemId: string): string | null {
  for (const g of groups) {
    if (!g.name.trim()) return "Todos los grupos necesitan un nombre"
    if (g.maxSelect !== null && g.maxSelect < Math.max(g.minSelect, 1)) {
      return `"${g.name}": el máximo debe ser mayor o igual al mínimo (y al menos 1)`
    }
    for (const o of g.options) {
      if (o.itemId === itemId) {
        return `"${g.name}": una opción no puede ser el propio producto`
      }
      if (o.priceDelta < 0) {
        return `"${g.name}": el precio adicional no puede ser negativo`
      }
      if (o.maxQty < 1) {
        return `"${g.name}": la cantidad máxima debe ser al menos 1`
      }
    }
  }
  return null
}

export function AddonsSection({
  itemId,
  newGroupPreset = DEFAULT_NEW_GROUP_PRESET,
}: Props) {
  const { data, isLoading } = useItemAddons(itemId)
  const replace = useReplaceItemAddons()

  const [draft, setDraft] = React.useState<DraftGroup[] | null>(null)
  const [dirty, setDirty] = React.useState(false)

  // Sincroniza el draft con el server SOLO cuando no hay cambios locales sin
  // guardar — así un refetch en background (invalidation de otra pestaña) no
  // pisa lo que el usuario está editando.
  React.useEffect(() => {
    if (!data) return
    if (dirty) return
    setDraft(toDraft(data.groups))
  }, [data, dirty])

  const groups = draft ?? []

  const mutate = (fn: (prev: DraftGroup[]) => DraftGroup[]) => {
    setDraft((prev) => fn(prev ?? []))
    setDirty(true)
  }

  const addGroup = () => {
    mutate((prev) => [
      ...prev,
      {
        clientId: nextClientId(),
        name: "",
        minSelect: newGroupPreset.minSelect,
        maxSelect: newGroupPreset.maxSelect,
        status: true,
        options: [],
      },
    ])
  }

  const updateGroup = (clientId: string, patch: Partial<DraftGroup>) => {
    mutate((prev) =>
      prev.map((g) => (g.clientId === clientId ? { ...g, ...patch } : g)),
    )
  }

  const removeGroup = (clientId: string) => {
    mutate((prev) => prev.filter((g) => g.clientId !== clientId))
  }

  const addOption = (groupClientId: string, item: ItemListItem) => {
    mutate((prev) =>
      prev.map((g) => {
        if (g.clientId !== groupClientId) return g
        if (g.options.some((o) => o.itemId === item.itemId)) return g
        return {
          ...g,
          options: [
            ...g.options,
            {
              clientId: nextClientId(),
              itemId: item.itemId,
              itemName: item.itemName,
              itemPrice:
                typeof item.itemPrice === "string"
                  ? Number(item.itemPrice)
                  : (item.itemPrice ?? 0),
              priceDelta: 0,
              isDefault: false,
              isLocked: false,
              maxQty: 1,
            },
          ],
        }
      }),
    )
  }

  const updateOption = (
    groupClientId: string,
    optionClientId: string,
    patch: Partial<DraftOption>,
  ) => {
    mutate((prev) =>
      prev.map((g) => {
        if (g.clientId !== groupClientId) return g
        return {
          ...g,
          options: g.options.map((o) => {
            if (o.clientId !== optionClientId) return o
            const next = { ...o, ...patch }
            // isLocked fuerza isDefault (revalidado igual en el server).
            if (next.isLocked) next.isDefault = true
            return next
          }),
        }
      }),
    )
  }

  const removeOption = (groupClientId: string, optionClientId: string) => {
    mutate((prev) =>
      prev.map((g) =>
        g.clientId !== groupClientId
          ? g
          : { ...g, options: g.options.filter((o) => o.clientId !== optionClientId) },
      ),
    )
  }

  const applyCopy = (copied: AddonGroup[]) => {
    setDraft(toDraft(copied))
    setDirty(false)
  }

  const handleSave = async () => {
    const error = validateDraft(groups, itemId)
    if (error) {
      toast.error("Revisá los grupos antes de guardar", { description: error })
      return
    }
    try {
      const result = await replace.mutateAsync({ itemId, groups: toInput(groups) })
      setDraft(toDraft(result.groups))
      setDirty(false)
      toast.success("Add-ons guardados")
    } catch (e) {
      toast.error("No se pudieron guardar los add-ons", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  if (isLoading && !draft) {
    return (
      <Card className="lg:col-span-2">
        <CardHeader>
          <CardTitle className="text-base font-semibold tracking-tight">Add-ons</CardTitle>
        </CardHeader>
        <CardContent className="flex h-20 items-center justify-center text-sm text-muted-foreground">
          <Loader2 className="mr-2 size-4 animate-spin" />
          Cargando add-ons…
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="lg:col-span-2">
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">Add-ons</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {groups.length === 0 ? (
          <EmptyState
            icon={Layers}
            title="Sin add-ons"
            description="Agregá un grupo de opciones (ej: “Bebida”, “Extras”) o copialo desde otro producto."
            showMarquee={false}
            className="border-dashed py-6"
            actions={
              <div className="flex flex-wrap justify-center gap-2">
                <Button type="button" variant="outline" size="sm" className="gap-1.5" onClick={addGroup}>
                  <Plus className="size-3.5" />
                  Agregar grupo
                </Button>
                <CopyFromDialog itemId={itemId} onCopied={applyCopy} />
              </div>
            }
          />
        ) : (
          <>
            {groups.map((g) => (
              <GroupBlock
                key={g.clientId}
                itemId={itemId}
                group={g}
                onUpdate={(patch) => updateGroup(g.clientId, patch)}
                onRemove={() => removeGroup(g.clientId)}
                onAddOption={(item) => addOption(g.clientId, item)}
                onUpdateOption={(optClientId, patch) => updateOption(g.clientId, optClientId, patch)}
                onRemoveOption={(optClientId) => removeOption(g.clientId, optClientId)}
              />
            ))}

            <div className="flex flex-wrap gap-2">
              <Button type="button" variant="outline" size="sm" className="gap-1.5" onClick={addGroup}>
                <Plus className="size-3.5" />
                Agregar grupo
              </Button>
              <CopyFromDialog itemId={itemId} onCopied={applyCopy} />
            </div>
          </>
        )}

        <div className="flex items-center justify-end gap-2 border-t pt-4">
          <Button
            type="button"
            onClick={handleSave}
            disabled={!dirty || replace.isPending}
            className="gap-1.5"
          >
            {replace.isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <Save className="size-4" />
            )}
            Guardar add-ons
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

// ── Bloque de grupo ─────────────────────────────────────────────────────────

function GroupBlock({
  itemId,
  group,
  onUpdate,
  onRemove,
  onAddOption,
  onUpdateOption,
  onRemoveOption,
}: {
  itemId: string
  group: DraftGroup
  onUpdate: (patch: Partial<DraftGroup>) => void
  onRemove: () => void
  onAddOption: (item: ItemListItem) => void
  onUpdateOption: (optionClientId: string, patch: Partial<DraftOption>) => void
  onRemoveOption: (optionClientId: string) => void
}) {
  const { data: bootstrap } = useBootstrap()

  return (
    <Card className="border-dashed">
      <CardContent className="flex flex-col gap-4 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[200px] flex-1 flex-col gap-1.5">
            <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
              Nombre del grupo
            </Label>
            <Input
              value={group.name}
              onChange={(e) => onUpdate({ name: e.target.value })}
              placeholder="Ej: Bebida, Extras…"
              className="h-9"
            />
          </div>
          <div className="flex w-20 flex-col gap-1.5">
            <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
              Mín
            </Label>
            <Input
              value={String(group.minSelect)}
              onChange={(e) => {
                const n = parseInt(e.target.value, 10)
                onUpdate({ minSelect: Number.isFinite(n) ? Math.max(0, n) : 0 })
              }}
              inputMode="numeric"
              className="h-9 text-center tabular-nums"
            />
          </div>
          <div className="flex w-24 flex-col gap-1.5">
            <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
              Máx
            </Label>
            <Input
              value={group.maxSelect === null ? "" : String(group.maxSelect)}
              onChange={(e) => {
                const raw = e.target.value.trim()
                if (raw === "") {
                  onUpdate({ maxSelect: null })
                  return
                }
                const n = parseInt(raw, 10)
                if (Number.isFinite(n)) onUpdate({ maxSelect: n })
              }}
              placeholder="Sin tope"
              inputMode="numeric"
              className="h-9 text-center tabular-nums"
            />
          </div>
          <div className="flex flex-col items-center gap-1.5">
            <Label className="text-[11px] uppercase tracking-wide text-muted-foreground">
              Activo
            </Label>
            <Switch
              checked={group.status}
              onCheckedChange={(v) => onUpdate({ status: v })}
              className="mt-1.5"
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
                <AlertDialogTitle>¿Eliminar el grupo &quot;{group.name || "sin nombre"}&quot;?</AlertDialogTitle>
                <AlertDialogDescription>
                  Se quita de este producto junto con sus opciones. El cambio
                  se aplica recién al presionar &quot;Guardar add-ons&quot;.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction onClick={onRemove}>Eliminar</AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>

        {group.options.length === 0 ? (
          <div className="rounded-md border border-dashed bg-muted/20 px-3 py-4 text-center text-xs text-muted-foreground">
            Sin opciones en este grupo. Agregá una abajo.
          </div>
        ) : (
          <div className="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Producto</TableHead>
                  <TableHead className="w-32 text-right">Precio adicional</TableHead>
                  <TableHead className="w-20 text-center">Fijo</TableHead>
                  <TableHead className="w-24 text-center">Por defecto</TableHead>
                  <TableHead className="w-20 text-center">Cant. máx</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {group.options.map((o) => (
                  <TableRow key={o.clientId}>
                    <TableCell>
                      <div className="flex flex-col">
                        <span className="font-medium">{o.itemName}</span>
                        <span className="text-[10px] tabular-nums text-muted-foreground">
                          Precio base: {formatMoney(o.itemPrice, bootstrap)}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <MoneyInput
                        value={o.priceDelta}
                        onChange={(v) => onUpdateOption(o.clientId, { priceDelta: v ?? 0 })}
                        className="h-8 text-xs"
                      />
                    </TableCell>
                    <TableCell className="text-center">
                      <Switch
                        checked={o.isLocked}
                        onCheckedChange={(v) => onUpdateOption(o.clientId, { isLocked: v })}
                      />
                    </TableCell>
                    <TableCell className="text-center">
                      <Switch
                        checked={o.isDefault}
                        disabled={o.isLocked}
                        onCheckedChange={(v) => onUpdateOption(o.clientId, { isDefault: v })}
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={String(o.maxQty)}
                        onChange={(e) => {
                          const n = parseInt(e.target.value, 10)
                          onUpdateOption(o.clientId, { maxQty: Number.isFinite(n) ? Math.max(1, n) : 1 })
                        }}
                        inputMode="numeric"
                        className="h-8 text-center text-xs tabular-nums"
                      />
                    </TableCell>
                    <TableCell>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7 text-muted-foreground hover:text-destructive"
                        onClick={() => onRemoveOption(o.clientId)}
                      >
                        <Trash2 className="size-3.5" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}

        <OptionPicker
          itemId={itemId}
          excludeIds={group.options.map((o) => o.itemId)}
          onPick={onAddOption}
        />
      </CardContent>
    </Card>
  )
}

// ── Picker de producto para agregar como opción ─────────────────────────────

function OptionPicker({
  itemId,
  excludeIds,
  onPick,
}: {
  itemId: string
  excludeIds: string[]
  onPick: (item: ItemListItem) => void
}) {
  const [open, setOpen] = React.useState(false)
  const [search, setSearch] = React.useState("")
  const { data, isLoading } = useItems({ q: search || undefined })

  const items = React.useMemo(() => {
    const exclude = new Set([...excludeIds, itemId])
    return (data?.items ?? []).filter((i) => !exclude.has(i.itemId))
  }, [data, excludeIds, itemId])

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button type="button" variant="outline" size="sm" className="w-fit gap-1.5">
          <Plus className="size-3.5" />
          Agregar opción
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
            {!isLoading && items.length === 0 && <CommandEmpty>Sin resultados.</CommandEmpty>}
            <CommandGroup>
              {items.map((it) => (
                <CommandItem
                  key={it.itemId}
                  value={it.itemId}
                  onSelect={() => {
                    setOpen(false)
                    setSearch("")
                    onPick(it)
                  }}
                >
                  <div className="flex flex-1 flex-col">
                    <span className="text-sm">{it.itemName}</span>
                    <span className="text-[10px] tabular-nums text-muted-foreground">
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

// ── Copiar grupos desde otro producto ───────────────────────────────────────

function CopyFromDialog({
  itemId,
  onCopied,
}: {
  itemId: string
  onCopied: (groups: AddonGroup[]) => void
}) {
  const [open, setOpen] = React.useState(false)
  const [search, setSearch] = React.useState("")
  const { data, isLoading } = useItems({ q: search || undefined })
  const copy = useCopyItemAddons()

  const items = React.useMemo(
    () => (data?.items ?? []).filter((i) => i.itemId !== itemId),
    [data, itemId],
  )

  const handlePick = async (sourceItemId: string) => {
    try {
      const result = await copy.mutateAsync({ itemId, sourceItemId })
      onCopied(result.groups)
      toast.success("Add-ons copiados")
      setOpen(false)
      setSearch("")
    } catch (e) {
      toast.error("No se pudieron copiar los add-ons", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button type="button" variant="outline" size="sm" className="w-fit gap-1.5">
          <Copy className="size-3.5" />
          Copiar de otro producto
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Copiar add-ons de otro producto</DialogTitle>
          <DialogDescription>
            Reemplaza los grupos de este producto por una copia de los del
            producto que elijas. Los productos quedan independientes después
            de copiar — editar uno no afecta al otro.
          </DialogDescription>
        </DialogHeader>
        <Command shouldFilter={false} className="rounded-md border">
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
            {!isLoading && items.length === 0 && <CommandEmpty>Sin resultados.</CommandEmpty>}
            <CommandGroup>
              {items.map((it) => (
                <CommandItem
                  key={it.itemId}
                  value={it.itemId}
                  disabled={copy.isPending}
                  onSelect={() => handlePick(it.itemId)}
                  className={cn(copy.isPending && "opacity-50")}
                >
                  <div className="flex flex-1 flex-col">
                    <span className="text-sm">{it.itemName}</span>
                    <span className="text-[10px] tabular-nums text-muted-foreground">
                      {it.itemSKU || "—"} · {it.kind}
                    </span>
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </DialogContent>
    </Dialog>
  )
}
