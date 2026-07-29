"use client"

/**
 * Input de dirección con typeahead — Popover anclado al Input (no un botón
 * trigger: el Input mismo dispara la búsqueda, como cualquier combobox de
 * escritura libre). Sugerencias vía `useAddressAutocomplete` →
 * `/api/geo/autocomplete` (BFF, Photon detrás).
 *
 * Compartido por el alta de dirección del POS
 * (`components/register/delivery-address-dialog.tsx`) y del panel
 * (`components/domain/contacts/contact-detail-view.tsx`) — un solo lugar
 * para cambiar de proveedor o de UX de sugerencias sin duplicar el call-site
 * (mismo criterio que `AddressMapParser`).
 *
 * ⚠ Nunca bloquea: el valor del Input es SIEMPRE el texto que escribe el
 * operador (controlado por el caller), la sugerencia solo dispara `onSelect`
 * para completar los demás campos — el operador puede ignorar todas las
 * sugerencias y guardar con lo que escribió a mano (crítico para el POS
 * offline).
 */

import * as React from "react"
import { Loader2, MapPin } from "lucide-react"
import { Input } from "@/components/ui/input"
import { Command, CommandGroup, CommandItem, CommandList } from "@/components/ui/command"
import { Popover, PopoverAnchor, PopoverContent } from "@/components/ui/popover"
import { useAddressAutocomplete } from "@/hooks/use-address-autocomplete"
import type { GeoSuggestion } from "@/lib/geo/types"
import { cn } from "@/lib/utils"

export function AddressAutocompleteInput({
  id,
  value,
  onValueChange,
  onSelect,
  placeholder,
  disabled,
  className,
}: {
  id?: string
  value: string
  onValueChange: (value: string) => void
  onSelect: (suggestion: GeoSuggestion) => void
  placeholder?: string
  disabled?: boolean
  className?: string
}) {
  const { suggestions, isLoading } = useAddressAutocomplete(value)
  // `open` se DERIVA de `suggestions` (sin efecto ni setState-en-effect): el
  // popover está abierto siempre que haya sugerencias, salvo que el operador
  // lo haya cerrado explícitamente (click afuera / Escape) para esta misma
  // tanda de sugerencias. Tipear de nuevo (`onChange`) resetea `closed` desde
  // el handler del evento — no desde un efecto.
  const [closed, setClosed] = React.useState(false)
  const open = !closed && suggestions.length > 0
  const inputRef = React.useRef<HTMLInputElement>(null)

  const handleSelect = (s: GeoSuggestion) => {
    onSelect(s)
    setClosed(true)
  }

  return (
    <Popover open={open} onOpenChange={(next) => setClosed(!next)}>
      <PopoverAnchor asChild>
        <Input
          id={id}
          ref={inputRef}
          value={value}
          disabled={disabled}
          placeholder={placeholder}
          autoComplete="off"
          className={className}
          onChange={(e) => {
            onValueChange(e.target.value)
            setClosed(false)
          }}
        />
      </PopoverAnchor>
      <PopoverContent
        className={cn("w-[var(--radix-popover-trigger-width)] p-0")}
        align="start"
        // No robar el foco del Input al abrir — el operador sigue escribiendo.
        onOpenAutoFocus={(e) => e.preventDefault()}
        // No cerrar cuando el "outside click" es en realidad el propio Input
        // (foco natural al tipear) — solo cerrar por click afuera de ambos.
        onInteractOutside={(e) => {
          if (e.target === inputRef.current) e.preventDefault()
        }}
      >
        <Command shouldFilter={false}>
          <CommandList>
            {isLoading ? (
              <div className="flex items-center gap-2 px-3 py-2.5 text-xs text-muted-foreground">
                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                Buscando…
              </div>
            ) : (
              <CommandGroup>
                {suggestions.map((s) => (
                  <CommandItem key={s.id} value={s.id} onSelect={() => handleSelect(s)}>
                    <MapPin className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="truncate">{s.label}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
