"use client"

import * as React from "react"
import {
  AsYouType,
  isValidPhoneNumber,
  parsePhoneNumber,
  type CountryCode,
} from "libphonenumber-js"
import { Check, ChevronDown } from "lucide-react"

import {
  InputGroup,
  InputGroupAddon,
  InputGroupInput,
} from "@/components/ui/input-group"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { cn } from "@/lib/utils"
import {
  DEFAULT_COUNTRY,
  SUPPORTED_COUNTRIES,
  getCountry,
  type Country,
} from "@/lib/countries"

/**
 * PhoneInput — input de celular con dropdown de países.
 *
 * Convención (ver feedback-phone-format-convention):
 *   - El usuario ve y tipea en formato NACIONAL del país seleccionado
 *     (ej PY: "0981 612 192", sin "+").
 *   - El componente emite onChange con TRES variantes para que el form
 *     padre elija qué guardar:
 *       value:      lo que ve el usuario (nacional, ej "0981 612 192")
 *       e164:       formato internacional E.164 (ej "+595981612192"),
 *                   o null si el número no es válido todavía
 *       country:    código ISO 3166-1 alpha-2 actual
 *   - Al submit, el form padre manda `e164` al backend (NUNCA `value`).
 *
 * Para el dropdown usamos Popover + Command (shadcn-first, ver
 * feedback-shadcn-first). Permite búsqueda por nombre y dial code.
 */

export interface PhoneInputValue {
  value: string
  e164: string | null
  country: CountryCode
  isValid: boolean
}

export interface PhoneInputProps {
  value: string
  country?: CountryCode
  onChange: (value: PhoneInputValue) => void
  onBlur?: () => void
  placeholder?: string
  disabled?: boolean
  autoFocus?: boolean
  id?: string
  name?: string
  className?: string
  "aria-invalid"?: boolean
}

export function PhoneInput({
  value,
  country = DEFAULT_COUNTRY,
  onChange,
  onBlur,
  placeholder,
  disabled,
  autoFocus,
  id,
  name,
  className,
  "aria-invalid": ariaInvalid,
}: PhoneInputProps) {
  const [open, setOpen] = React.useState(false)
  const selected = getCountry(country)

  // Formateo on-input: AsYouType del país actual va dando formato nacional
  // mientras tipea ("981234" → "981 234"). Solo aceptamos dígitos + separadores
  // típicos; libphonenumber descarta el resto sin romper.
  const handleInput = (input: string) => {
    const formatted = new AsYouType(country).input(input)
    emit(formatted, country)
  }

  const handleCountryChange = (next: CountryCode) => {
    setOpen(false)
    // Re-formateamos el value actual al país nuevo (mismos dígitos, distinto
    // patrón nacional). Si no era válido en el país anterior, va a quedar
    // raro — es esperado, el user re-tipea o el form valida al submit.
    emit(new AsYouType(next).input(value), next)
  }

  const emit = (nextValue: string, nextCountry: CountryCode) => {
    const valid = isValidPhoneNumber(nextValue, nextCountry)
    let e164: string | null = null
    if (valid) {
      try {
        e164 = parsePhoneNumber(nextValue, nextCountry).format("E.164")
      } catch {
        e164 = null
      }
    }
    onChange({ value: nextValue, e164, country: nextCountry, isValid: valid })
  }

  return (
    <InputGroup
      className={cn(
        "h-10 rounded-md",
        // Pintamos invalid del padre — InputGroup tiene el selector pero solo
        // se prende si el hijo lleva aria-invalid; lo propagamos al input.
        className,
      )}
    >
      <InputGroupAddon align="inline-start" className="pl-1">
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <button
              type="button"
              role="combobox"
              aria-expanded={open}
              aria-label={`País: ${selected.name}`}
              disabled={disabled}
              className={cn(
                "flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium",
                "hover:bg-accent hover:text-accent-foreground",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                "disabled:cursor-not-allowed disabled:opacity-50",
              )}
            >
              <span className="text-base leading-none">{selected.flag}</span>
              <span className="tabular-nums text-muted-foreground">+{selected.dialCode}</span>
              <ChevronDown className="size-3 opacity-60" />
            </button>
          </PopoverTrigger>
          <PopoverContent className="w-[280px] p-0" align="start">
            <Command>
              <CommandInput placeholder="Buscar país…" />
              <CommandList>
                <CommandEmpty>Sin resultados.</CommandEmpty>
                <CommandGroup>
                  {SUPPORTED_COUNTRIES.map((c: Country) => (
                    <CommandItem
                      key={c.code}
                      value={`${c.name} ${c.code} +${c.dialCode}`}
                      onSelect={() => handleCountryChange(c.code)}
                      className="gap-2"
                    >
                      <span className="text-base leading-none">{c.flag}</span>
                      <span className="flex-1 truncate">{c.name}</span>
                      <span className="tabular-nums text-xs text-muted-foreground">
                        +{c.dialCode}
                      </span>
                      <Check
                        className={cn(
                          "size-4",
                          c.code === country ? "opacity-100" : "opacity-0",
                        )}
                      />
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      </InputGroupAddon>

      <InputGroupInput
        id={id}
        name={name}
        type="tel"
        inputMode="tel"
        autoComplete="tel"
        autoFocus={autoFocus}
        disabled={disabled}
        placeholder={placeholder ?? "981 234 567"}
        value={value}
        onChange={(e) => handleInput(e.target.value)}
        onBlur={onBlur}
        aria-invalid={ariaInvalid}
      />
    </InputGroup>
  )
}
