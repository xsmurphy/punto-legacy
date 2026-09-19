"use client"

/**
 * Filtros de la pestaña "Evolución de costos": artículo y proveedor.
 *
 * Son buscadores de SOLO LECTURA. Los pickers del formulario de compra
 * (`purchase-form-fields.tsx`) ofrecen crear el artículo o el proveedor si no
 * existe, y eso en un filtro de reporte no tiene sentido; por eso no se reusan.
 *
 * El valor vive en la URL (lo maneja la página); acá solo se elige o se limpia.
 * El nombre se pasa desde afuera porque, al entrar por un link con el id ya
 * puesto (la ficha del artículo), el que lo conoce es la respuesta del reporte.
 */

import * as React from "react"
import { ChevronsUpDown, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { useContacts } from "@/hooks/use-contacts"
import { useItems } from "@/hooks/use-items"

interface Option {
  id: string
  label: string
  hint?: string
}

function SearchPicker({
  value,
  displayName,
  placeholder,
  searchPlaceholder,
  options,
  isFetching,
  q,
  onQChange,
  onChange,
  clearLabel,
}: {
  value: string
  displayName: string
  placeholder: string
  searchPlaceholder: string
  options: Option[]
  isFetching: boolean
  q: string
  onQChange: (q: string) => void
  onChange: (id: string, name: string) => void
  clearLabel: string
}) {
  const [open, setOpen] = React.useState(false)

  return (
    <div className="flex items-center gap-1">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            className="w-full justify-between font-normal sm:w-56"
          >
            {value ? (
              <span className="truncate">{displayName || "…"}</span>
            ) : (
              <span className="text-muted-foreground">{placeholder}</span>
            )}
            <ChevronsUpDown className="ml-2 size-4 text-muted-foreground" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="w-[--radix-popover-trigger-width] min-w-[260px] p-0"
          align="start"
        >
          <Command shouldFilter={false}>
            <CommandInput placeholder={searchPlaceholder} value={q} onValueChange={onQChange} />
            <CommandList>
              <CommandEmpty>
                <div className="py-4 text-center text-sm text-muted-foreground">
                  {isFetching ? "Buscando…" : q.trim() === "" ? "Tipeá para buscar" : "Sin resultados"}
                </div>
              </CommandEmpty>
              <CommandGroup>
                {options.map((o) => (
                  <CommandItem
                    key={o.id}
                    value={o.id}
                    onSelect={() => {
                      onChange(o.id, o.label)
                      setOpen(false)
                      onQChange("")
                    }}
                  >
                    <div className="min-w-0">
                      <div className="truncate">{o.label}</div>
                      {o.hint && (
                        <div className="truncate text-xs text-muted-foreground">{o.hint}</div>
                      )}
                    </div>
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      {value && (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label={clearLabel}
          onClick={() => onChange("", "")}
        >
          <X className="size-4" />
        </Button>
      )}
    </div>
  )
}

export function CostItemFilter({
  value,
  displayName,
  onChange,
}: {
  value: string
  displayName: string
  onChange: (id: string, name: string) => void
}) {
  const [q, setQ] = React.useState("")
  const items = useItems({ q })
  const options = q.trim() === ""
    ? []
    : (items.data?.items ?? []).map((i) => ({
        id: i.itemId,
        label: i.itemName,
        hint: i.itemSKU || undefined,
      }))
  return (
    <SearchPicker
      value={value}
      displayName={displayName}
      placeholder="Todos los artículos"
      searchPlaceholder="Buscar por nombre o SKU…"
      options={options}
      isFetching={items.isFetching}
      q={q}
      onQChange={setQ}
      onChange={onChange}
      clearLabel="Ver todos los artículos"
    />
  )
}

export function CostSupplierFilter({
  value,
  displayName,
  onChange,
}: {
  value: string
  displayName: string
  onChange: (id: string, name: string) => void
}) {
  const [q, setQ] = React.useState("")
  const contacts = useContacts({ q, type: 2 })
  const options = (contacts.data?.contacts ?? []).map((c) => ({
    id: c.id,
    label: c.name || "Sin nombre",
  }))
  return (
    <SearchPicker
      value={value}
      displayName={displayName}
      placeholder="Todos los proveedores"
      searchPlaceholder="Buscar proveedor…"
      options={options}
      isFetching={contacts.isFetching}
      q={q}
      onQChange={setQ}
      onChange={onChange}
      clearLabel="Ver todos los proveedores"
    />
  )
}
