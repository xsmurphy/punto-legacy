"use client"

import * as React from "react"
import { Check, ChevronsUpDown, X } from "lucide-react"

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
 * Multi-select de categorías para FILTRAR (no para asignar).
 *
 * Distinto de `components/items/categories-picker.tsx`, que resuelve el m2m
 * `item_category` del alta de un ítem: ahí una categoría es principal
 * (isPrimary) y se puede crear al vuelo. Acá las categorías son un criterio
 * de selección — sin jerarquía entre ellas, y sin alta, porque filtrar por
 * una categoría recién creada (todavía vacía) no tiene sentido.
 */
export interface CategoryOption {
  id: string
  name: string
}

interface Props {
  options: CategoryOption[]
  value: string[]
  onChange: (next: string[]) => void
  /** Texto del trigger cuando no hay ninguna seleccionada. */
  placeholder?: string
  disabled?: boolean
  id?: string
}

export function CategoryMultiSelect({
  options,
  value,
  onChange,
  placeholder = "Todas las categorías",
  disabled,
  id,
}: Props) {
  const [open, setOpen] = React.useState(false)

  const selectedIds = React.useMemo(() => new Set(value), [value])
  const optionsById = React.useMemo(
    () => new Map(options.map((o) => [o.id, o])),
    [options],
  )

  const toggle = (categoryId: string) => {
    onChange(
      selectedIds.has(categoryId)
        ? value.filter((v) => v !== categoryId)
        : [...value, categoryId],
    )
  }

  return (
    <div className="space-y-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className="w-full justify-between font-normal"
          >
            <span className={cn("truncate", value.length === 0 && "text-muted-foreground")}>
              {value.length === 0
                ? placeholder
                : `${value.length} ${value.length === 1 ? "categoría" : "categorías"}`}
            </span>
            <ChevronsUpDown className="size-4 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
          <Command>
            <CommandInput placeholder="Buscar categoría…" />
            <CommandList>
              <CommandEmpty>Sin resultados.</CommandEmpty>
              <CommandGroup>
                {options.map((opt) => {
                  const checked = selectedIds.has(opt.id)
                  return (
                    <CommandItem key={opt.id} value={opt.name} onSelect={() => toggle(opt.id)}>
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

      {value.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {value.map((categoryId) => {
            const opt = optionsById.get(categoryId)
            if (!opt) return null
            return (
              <Badge key={categoryId} variant="secondary" className="gap-1 pr-1">
                {opt.name}
                <button
                  type="button"
                  onClick={() => toggle(categoryId)}
                  className="rounded p-0.5 hover:bg-foreground/10"
                  aria-label={`Quitar ${opt.name}`}
                >
                  <X className="size-3" />
                </button>
              </Badge>
            )
          })}
        </div>
      )}
    </div>
  )
}
