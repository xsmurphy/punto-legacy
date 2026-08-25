"use client"

import * as React from "react"
import { ChevronsUpDown } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { cn } from "@/lib/utils"

/**
 * Multi-select genérico (Popover + Command + Checkbox).
 *
 * Extraído del patrón que estaba hand-rolleado en
 * `components/domain/contacts/team-section.tsx` (sucursales del usuario) y a
 * punto de copiarse por tercera vez para las sucursales del ítem. Cualquier
 * multi-select nuevo se compone con este componente, no reimplementando el
 * Popover+Command a mano.
 *
 * Las dos semánticas de "lista vacía" del proyecto conviven acá y son
 * excluyentes entre sí:
 *
 *   - `minOne`        → cero es INVÁLIDO. El control impide destildar el
 *                       último seleccionado (ítem ↔ sucursal: un artículo sin
 *                       sucursal no tiene trazabilidad).
 *   - `emptyMeansAll` → cero significa "todas" (usuario ↔ sucursales: sin
 *                       selección el usuario ve todo el comercio).
 *
 * `minOne` bloquea la opción en el propio menú (tooltip con el motivo) en vez
 * de dejar destildar y after-the-fact tirar un toast: la alerta va en el
 * control de la acción, no en una banda posterior.
 */

export interface MultiSelectOption {
  id: string
  name: string
}

interface MultiSelectProps {
  value: string[]
  onChange: (next: string[]) => void
  options: MultiSelectOption[]
  /** Impide que la selección quede vacía bloqueando el último tildado. */
  minOne?: boolean
  /** Motivo mostrado en el tooltip de la opción bloqueada por `minOne`. */
  minOneReason?: string
  /** Con `emptyMeansAll`, cero seleccionados es un estado válido = "todas". */
  emptyMeansAll?: boolean
  /** Etiqueta del trigger cuando `emptyMeansAll` y no hay selección. */
  emptyMeansAllLabel?: string
  /** Etiqueta del trigger cuando no hay selección y cero NO significa todas. */
  placeholder?: string
  searchPlaceholder?: string
  emptyMessage?: string
  /** Singular / plural para el contador del trigger ("2 sucursales"). */
  unitLabels?: [string, string]
  /** Chips con los nombres seleccionados debajo del trigger (desde 2). */
  showBadges?: boolean
  disabled?: boolean
  id?: string
  className?: string
  "aria-invalid"?: boolean
}

export function MultiSelect({
  value,
  onChange,
  options,
  minOne = false,
  minOneReason = "Tiene que quedar al menos uno seleccionado.",
  emptyMeansAll = false,
  emptyMeansAllLabel = "Todas",
  placeholder = "Seleccionar…",
  searchPlaceholder = "Buscar…",
  emptyMessage = "Sin resultados.",
  unitLabels = ["seleccionado", "seleccionados"],
  showBadges = true,
  disabled,
  id,
  className,
  "aria-invalid": ariaInvalid,
}: MultiSelectProps) {
  const [open, setOpen] = React.useState(false)

  const selected = React.useMemo(() => new Set(value), [value])
  const nameById = React.useMemo(
    () => new Map(options.map((o) => [o.id, o.name])),
    [options],
  )

  /** Con `minOne` y un solo tildado, ESE es el que no se puede destildar. */
  const lockedId = minOne && value.length === 1 ? value[0] : null

  const toggle = (optionId: string) => {
    if (selected.has(optionId)) {
      if (optionId === lockedId) return
      onChange(value.filter((v) => v !== optionId))
    } else {
      onChange([...value, optionId])
    }
  }

  const triggerLabel =
    value.length === 0
      ? emptyMeansAll
        ? emptyMeansAllLabel
        : placeholder
      : value.length === 1
        ? (nameById.get(value[0]) ?? `1 ${unitLabels[0]}`)
        : `${value.length} ${unitLabels[1]}`

  const isPlaceholder = value.length === 0 && !emptyMeansAll

  return (
    <div className={cn("space-y-2", className)}>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            aria-invalid={ariaInvalid}
            disabled={disabled}
            className="w-full justify-between font-normal"
          >
            <span className={cn("truncate", isPlaceholder && "text-muted-foreground")}>
              {triggerLabel}
            </span>
            <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="w-[var(--radix-popover-trigger-width)] p-0"
          align="start"
        >
          <Command>
            <CommandInput placeholder={searchPlaceholder} />
            <CommandList>
              <CommandEmpty>{emptyMessage}</CommandEmpty>
              <CommandGroup>
                {options.map((opt) => {
                  const checked = selected.has(opt.id)
                  const locked = opt.id === lockedId
                  // `aria-disabled` en vez de la prop `disabled` de cmdk: esa
                  // aplica `pointer-events-none` y el tooltip nunca llegaría a
                  // mostrarse, que es justo lo que explica el bloqueo.
                  const row = (
                    <>
                      <Checkbox
                        checked={checked}
                        disabled={locked}
                        className="mr-2"
                        aria-hidden
                        tabIndex={-1}
                      />
                      <span className="truncate">{opt.name}</span>
                    </>
                  )

                  return (
                    <CommandItem
                      key={opt.id}
                      // `value` es el id y el nombre va en `keywords`: con
                      // `value={opt.name}`, dos sucursales homónimas comparten
                      // el valor de cmdk y el filtrado/selección las confunde.
                      value={opt.id}
                      keywords={[opt.name]}
                      onSelect={() => toggle(opt.id)}
                      aria-disabled={locked}
                      data-checked={checked}
                      className={cn(locked && "cursor-not-allowed opacity-60")}
                    >
                      {locked ? (
                        <TooltipProvider>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="flex flex-1 items-center">{row}</span>
                            </TooltipTrigger>
                            <TooltipContent>{minOneReason}</TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      ) : (
                        row
                      )}
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>

      {showBadges && value.length > 1 && (
        <div className="flex flex-wrap gap-1.5">
          {value.map((optionId) => {
            const name = nameById.get(optionId)
            return name ? (
              <Badge key={optionId} variant="secondary">
                {name}
              </Badge>
            ) : null
          })}
        </div>
      )}
    </div>
  )
}
