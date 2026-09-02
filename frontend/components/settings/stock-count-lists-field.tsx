"use client"

/**
 * Editor de las LISTAS FIJAS de conteo (D3 de context/63).
 *
 * El dueño arma una vez qué se cuenta en el mostrador y el cajero solo lo
 * completa. Eso es lo que hace el conteo repetible y comparable entre turnos —
 * y lo que le saca al cajero la decisión de qué contar, que no es suya.
 *
 * ── Por qué las listas no son una entidad con su propio CRUD ───────────────
 *
 * Porque no tienen ciclo de vida: son un nombre y un conjunto de artículos que
 * el dueño ajusta de vez en cuando. Viven en la config del comercio, como el
 * resto de sus preferencias. Lo que sí necesita historia es el CONTEO, y eso
 * ya está resuelto en otro lado: cada sesión snapshotea la lista con la que se
 * abrió, así que editar o borrar una lista acá no reescribe lo que se contó el
 * mes pasado.
 *
 * ── Los nombres de los artículos se resuelven, no se guardan ───────────────
 *
 * La config guarda solo `itemIds`. Copiar el nombre adentro haría que renombrar
 * un artículo dejara la lista mintiendo para siempre. Se resuelven con el
 * `bulk-get` del catálogo, que es el mecanismo que ya existe para esto.
 */

import * as React from "react"
import { useQuery } from "@tanstack/react-query"
import { Check, ChevronsUpDown, Plus, Trash2 } from "lucide-react"
import type { UseFormReturn } from "react-hook-form"

import { Button } from "@/components/ui/button"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import {
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Badge } from "@/components/ui/badge"
import { api } from "@/lib/api-client"
import { useItems } from "@/hooks/use-items"
import { cn } from "@/lib/utils"

export interface StockCountList {
  id: string
  name: string
  itemIds: string[]
}

interface ItemRow {
  itemId: string
  itemName: string
  itemSKU?: string | null
}

/**
 * Nombres de los artículos que YA están en las listas. Se piden por id porque
 * el buscador solo devuelve lo que matchea el texto tipeado, y una lista
 * guardada tiene que poder mostrarse sin que nadie busque nada.
 */
function useItemsByIds(ids: string[]) {
  const key = [...ids].sort().join(",")
  return useQuery<{ items: ItemRow[] }>({
    queryKey: ["items", "bulk-get", key],
    queryFn: () => api.post("/v1/items?resource=bulk-get", { ids }),
    enabled: ids.length > 0,
    staleTime: 5 * 60 * 1000,
  })
}

function newListId(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID()
  }
  return `list-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

export function StockCountListsField({
  form,
}: {
  // El form de Ajustes tiene ~40 campos de tipos mezclados; tiparlo entero acá
  // ataría este componente a esa forma. Solo se toca `stockCountLists`.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  form: UseFormReturn<any>
}) {
  return (
    // `<FormField>` y no `<FormItem>` suelto: los primitivos de shadcn leen el
    // nombre del campo del contexto que ESTE componente provee, y sin él
    // `useFormField()` resuelve `undefined` — los estados de error y el
    // `aria-describedby` quedan rotos en silencio. Es el patrón que sigue todo
    // el resto del form de Ajustes.
    <FormField
      control={form.control}
      name="stockCountLists"
      render={({ field }) => <ListsEditor field={field} />}
    />
  )
}

function ListsEditor({
  field,
}: {
  field: { value?: StockCountList[]; onChange: (v: StockCountList[]) => void }
}) {
  // `?? []` inline crearía un array nuevo en cada render y el `useMemo` de
  // abajo se recalcularía siempre — con él, el `bulk-get` de nombres se
  // redispararía en loop.
  const lists: StockCountList[] = React.useMemo(() => field.value ?? [], [field.value])

  const allIds = React.useMemo(
    () => Array.from(new Set(lists.flatMap((l) => l.itemIds))),
    [lists],
  )
  const { data: known } = useItemsByIds(allIds)
  const nameById = React.useMemo(() => {
    const map = new Map<string, string>()
    for (const i of known?.items ?? []) map.set(i.itemId, i.itemName)
    return map
  }, [known])

  const setLists = field.onChange

  return (
    <FormItem>
      <FormLabel>Listas de conteo</FormLabel>
      <FormDescription>
        Qué artículos cuenta el cajero desde la caja. Podés tener más de una si el
        mostrador cambia por horario.
      </FormDescription>

      <div className="flex flex-col gap-4 pt-2">
        {lists.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Sin listas todavía. El módulo de conteo en la caja no tiene nada que
            mostrarle al cajero hasta que crees una.
          </p>
        )}

        {lists.map((list, idx) => (
          <div key={list.id} className="flex flex-col gap-3 rounded-md border p-4">
            <div className="flex items-center gap-2">
              <Input
                value={list.name}
                placeholder="Nombre de la lista (ej. Mostrador mañana)"
                onChange={(e) => {
                  const next = [...lists]
                  next[idx] = { ...list, name: e.target.value }
                  setLists(next)
                }}
              />
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={`Eliminar ${list.name || "la lista"}`}
                onClick={() => setLists(lists.filter((l) => l.id !== list.id))}
              >
                <Trash2 className="size-4" />
              </Button>
            </div>

            <ItemsPicker
              selected={list.itemIds}
              nameById={nameById}
              onChange={(itemIds) => {
                const next = [...lists]
                next[idx] = { ...list, itemIds }
                setLists(next)
              }}
            />
          </div>
        ))}

        <Button
          type="button"
          variant="outline"
          className="w-fit"
          onClick={() => setLists([...lists, { id: newListId(), name: "", itemIds: [] }])}
        >
          <Plus className="mr-2 size-4" />
          Agregar lista
        </Button>
      </div>
      <FormMessage />
    </FormItem>
  )
}

function ItemsPicker({
  selected,
  nameById,
  onChange,
}: {
  selected: string[]
  nameById: Map<string, string>
  onChange: (ids: string[]) => void
}) {
  const [open, setOpen] = React.useState(false)
  const [query, setQuery] = React.useState("")
  const { data } = useItems({ q: query })
  const results = data?.items ?? []

  function toggle(itemId: string) {
    onChange(
      selected.includes(itemId)
        ? selected.filter((i) => i !== itemId)
        : [...selected, itemId],
    )
  }

  return (
    <div className="flex flex-col gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            className="w-full justify-between font-normal"
          >
            {selected.length === 0
              ? "Elegí los artículos…"
              : `${selected.length} artículo(s)`}
            <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        {/* El popover NO se cierra al elegir: armar una lista de mostrador son
            diez o quince toques seguidos, y cerrarse en cada uno obligaría a
            reabrirlo y volver a buscar. */}
        <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
          <Command shouldFilter={false}>
            <CommandInput
              placeholder="Buscar por nombre o SKU…"
              value={query}
              onValueChange={setQuery}
            />
            <CommandList>
              <CommandEmpty>Sin resultados</CommandEmpty>
              <CommandGroup>
                {results.map((item) => (
                  <CommandItem
                    key={item.itemId}
                    value={item.itemId}
                    onSelect={() => toggle(item.itemId)}
                  >
                    <Check
                      className={cn(
                        "mr-2 size-4",
                        selected.includes(item.itemId) ? "opacity-100" : "opacity-0",
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

      {selected.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {selected.map((id) => (
            <Badge key={id} variant="secondary" className="gap-1">
              {/* Mientras el nombre no llegó se muestra un marcador neutro, no
                  el UUID: un id crudo en pantalla no le dice nada a nadie. */}
              {nameById.get(id) ?? "Cargando…"}
              <button
                type="button"
                aria-label="Quitar artículo"
                onClick={() => onChange(selected.filter((i) => i !== id))}
                className="ml-1 opacity-60 hover:opacity-100"
              >
                ×
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  )
}
