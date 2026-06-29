"use client"

import * as React from "react"
import { Check, ChevronsUpDown, X, Star } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { cn } from "@/lib/utils"

/**
 * Multi-select de marcas para items. Usa el m2m item_brand con flag
 * isPrimary (una de las marcas marcada como principal — sirve a los
 * reportes legacy que asumen una sola marca por item).
 *
 * Si no hay marca primary marcada pero hay seleccionadas, la primera
 * queda automáticamente como primary.
 */
export interface BrandOption {
  id: string
  name: string
}

export interface SelectedBrand {
  id: string
  isPrimary: boolean
}

interface Props {
  options: BrandOption[]
  value: SelectedBrand[]
  onChange: (next: SelectedBrand[]) => void
  placeholder?: string
  disabled?: boolean
}

export function BrandsPicker({
  options,
  value,
  onChange,
  placeholder = "Seleccionar marcas…",
  disabled,
}: Props) {
  const [open, setOpen] = React.useState(false)

  const selectedIds = React.useMemo(() => new Set(value.map((v) => v.id)), [value])
  const optionsById = React.useMemo(
    () => new Map(options.map((o) => [o.id, o])),
    [options],
  )

  const toggle = (id: string) => {
    if (selectedIds.has(id)) {
      // Quitar — si era primary, promueve la siguiente seleccionada (si hay).
      const wasPrimary = value.find((v) => v.id === id)?.isPrimary
      const rest = value.filter((v) => v.id !== id)
      if (wasPrimary && rest.length > 0 && !rest.some((r) => r.isPrimary)) {
        rest[0] = { ...rest[0], isPrimary: true }
      }
      onChange(rest)
    } else {
      // Agregar — si no había nada seleccionado, esta queda como primary.
      const next: SelectedBrand = {
        id,
        isPrimary: value.length === 0,
      }
      onChange([...value, next])
    }
  }

  const setPrimary = (id: string) => {
    onChange(value.map((v) => ({ ...v, isPrimary: v.id === id })))
  }

  const remove = (id: string) => toggle(id)

  return (
    <div className="space-y-2">
      {/* Lista de seleccionadas como chips. La primary va con ícono estrella. */}
      {value.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {value.map((v) => {
            const opt = optionsById.get(v.id)
            if (!opt) return null
            return (
              <Badge
                key={v.id}
                variant={v.isPrimary ? "default" : "secondary"}
                className="gap-1 pr-1"
              >
                {v.isPrimary && <Star className="size-3 fill-current" />}
                {opt.name}
                <button
                  type="button"
                  onClick={() => setPrimary(v.id)}
                  className={cn(
                    "rounded p-0.5 hover:bg-foreground/10",
                    v.isPrimary && "hidden",
                  )}
                  aria-label="Marcar como principal"
                  title="Marcar como principal"
                >
                  <Star className="size-3" />
                </button>
                <button
                  type="button"
                  onClick={() => remove(v.id)}
                  className="rounded p-0.5 hover:bg-foreground/10"
                  aria-label="Quitar"
                >
                  <X className="size-3" />
                </button>
              </Badge>
            )
          })}
        </div>
      )}

      {/* Trigger del popover con el command-style picker. */}
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className="w-full justify-between font-normal"
          >
            <span className="truncate text-muted-foreground">
              {value.length > 0
                ? `${value.length} ${value.length === 1 ? "marca" : "marcas"} seleccionada${value.length === 1 ? "" : "s"}`
                : placeholder}
            </span>
            <ChevronsUpDown className="size-4 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
          <Command>
            <CommandInput placeholder="Buscar marca…" />
            <CommandList>
              <CommandEmpty>Sin resultados.</CommandEmpty>
              <CommandGroup>
                {options.map((opt) => {
                  const checked = selectedIds.has(opt.id)
                  return (
                    <CommandItem
                      key={opt.id}
                      value={opt.name}
                      onSelect={() => toggle(opt.id)}
                    >
                      <div
                        className={cn(
                          "flex size-4 items-center justify-center rounded-sm border",
                          checked
                            ? "border-foreground bg-foreground text-background"
                            : "border-foreground/30",
                        )}
                      >
                        {checked && <Check className="size-3" />}
                      </div>
                      <span>{opt.name}</span>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>

      {value.length > 1 && (
        <p className="text-[11px] text-muted-foreground">
          La marca con <Star className="inline size-3 fill-current" /> es la principal —
          aparece en reportes y listados como marca primaria del artículo.
        </p>
      )}
    </div>
  )
}
