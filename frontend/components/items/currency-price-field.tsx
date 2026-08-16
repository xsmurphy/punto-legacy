"use client"

import * as React from "react"
import { ChevronsUpDown, Coins, Plus, X } from "lucide-react"
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
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { MoneyInput } from "@/components/ui/money-input"
import { EmptyState } from "@/components/empty-state"
import { Skeleton } from "@/components/ui/skeleton"
import { useCurrencies } from "@/hooks/use-items"
import type { ItemFormValues } from "@/lib/types/item"

/**
 * Precio del ítem en monedas extranjeras. Vive dentro del bloque de precio
 * (Perfil → "Precio y costo") como complemento del precio local — antes era
 * la pestaña "Cotizaciones", separada de peso igual con las demás (movido
 * 2026-08-16 a pedido del owner).
 *
 * El comercio AGREGA las monedas que le interesan en vez de ver un listado
 * de cientos de países — `GET /v1/currencies` (CurrencyService::exchangeList)
 * devuelve la moneda de TODOS los países del mundo con `value=0` cuando el
 * tenant no configuró tasa, que era la causa real de "cientos de monedas".
 *
 * Decisión (2026-08-16, opción A del brief): el selector solo ofrece monedas
 * con tasa configurada (`value > 0`). Sin tasa no hay forma de convertir el
 * precio, así que ofrecerla sería ruido sin acción posible — el copy ya
 * manda a Configuración → Monedas para cargarla. No se tocó
 * `CurrencyService::exchangeList()` (lo consume también el cobro del POS):
 * el filtrado queda en el front. Si en el futuro se agregan más consumers
 * de "solo monedas con tasa", vale la pena moverlo al backend.
 */
export function CurrencyPriceField({ form }: { form: UseFormReturn<ItemFormValues> }) {
  const { data: allCurrencies, isLoading } = useCurrencies()
  const values = form.watch("currencies") ?? {}
  const errorMessage = form.formState.errors.currencies?.message as string | undefined

  const [open, setOpen] = React.useState(false)
  // Códigos de moneda que tienen fila visible: se agregan (a) al elegir del
  // picker, sin precio todavía, o (b) al hidratar el form con lo que el ítem
  // ya tenía guardado (`value > 0`) — y SOLO se sacan con el botón "Quitar"
  // explícito (`removeCurrency`). Es un set de membresía, no una derivación
  // de `values`: si derivara de `values > 0` directamente, borrar el input
  // para retipear el precio (selección + backspace → onChange(0/null)) haría
  // desaparecer la fila —con el input enfocado— en pleno tipeo (bug real,
  // detectado en review 2026-08-16).
  const [visibleCodes, setVisibleCodes] = React.useState<Set<string>>(new Set())

  // Sincroniza altas: cualquier código con precio > 0 en el form (carga
  // inicial vía form.reset con los datos del ítem, o un valor que el usuario
  // recién tipeó) entra al set si todavía no estaba. Nunca saca códigos acá
  // — eso es responsabilidad exclusiva de `removeCurrency`.
  React.useEffect(() => {
    setVisibleCodes((prev) => {
      let changed = false
      const next = new Set(prev)
      for (const code of Object.keys(values)) {
        if ((values[code] ?? 0) > 0 && !next.has(code)) {
          next.add(code)
          changed = true
        }
      }
      return changed ? next : prev
    })
  }, [values])

  const byCode = React.useMemo(
    () => new Map((allCurrencies ?? []).map((c) => [c.code, c])),
    [allCurrencies],
  )

  // Monedas con tasa configurada por el tenant — universo del picker.
  const configured = React.useMemo(
    () => (allCurrencies ?? []).filter((c) => (c.value ?? 0) > 0),
    [allCurrencies],
  )

  // Filas visibles = `visibleCodes`. Fallback a un stub si `byCode` no tiene
  // el código (ítem con precio en una moneda que el tenant ya no configura).
  const rows = React.useMemo(
    () =>
      Array.from(visibleCodes).map(
        (code) => byCode.get(code) ?? { ccode: "", code, value: 0 },
      ),
    [visibleCodes, byCode],
  )

  const pickerOptions = React.useMemo(
    () => configured.filter((c) => !visibleCodes.has(c.code)),
    [configured, visibleCodes],
  )

  const addCurrency = (code: string) => {
    setVisibleCodes((prev) => new Set(prev).add(code))
    setOpen(false)
  }

  const removeCurrency = (code: string) => {
    // 0 = "no se ofrece en esa moneda" (serialize() en use-items.ts filtra
    // las entradas con valor <= 0 antes de mandarlas al backend).
    form.setValue(`currencies.${code}` as never, 0 as never, { shouldDirty: true })
    setVisibleCodes((prev) => {
      const next = new Set(prev)
      next.delete(code)
      return next
    })
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    )
  }

  if (rows.length === 0 && configured.length === 0) {
    return (
      <EmptyState
        icon={Coins}
        title="Sin monedas extranjeras configuradas"
        description={
          <>
            Agregalas en <strong>Configuración → Monedas</strong> para poder ofrecer
            este precio en otra divisa.
          </>
        }
        showMarquee={false}
        className="border-dashed py-6"
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {rows.length > 0 && (
        <div className="flex flex-col gap-2">
          {rows.map((c) => (
            <div
              key={c.code}
              className="flex items-center justify-between gap-3 rounded-md border p-2.5"
            >
              <div className="flex min-w-0 items-center gap-2.5">
                <CountryFlag code={c.ccode} />
                <div className="flex min-w-0 flex-col">
                  <span className="truncate text-sm font-medium">
                    {countryName(c.ccode) || c.code}
                  </span>
                  <span className="text-xs tabular-nums text-muted-foreground">
                    {c.code}
                  </span>
                </div>
              </div>
              <div className="flex items-center gap-1.5">
                {/* decimals=2 fijo: el precio es en la moneda extranjera, no
                    en la local — puede tener centavos aunque el tenant
                    configure su moneda local sin decimales (ej. guaraníes).
                    MoneyInput acepta el override desde 2026-08-16. */}
                <MoneyInput
                  value={values[c.code] ?? null}
                  onChange={(v) =>
                    form.setValue(`currencies.${c.code}` as never, (v ?? 0) as never, {
                      shouldDirty: true,
                    })
                  }
                  decimals={2}
                  placeholder="0"
                  className="w-28"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                  onClick={() => removeCurrency(c.code)}
                  aria-label={`Quitar ${c.code}`}
                >
                  <X className="size-4" />
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      {pickerOptions.length > 0 && (
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button type="button" variant="outline" size="sm" className="justify-between gap-2 self-start">
              <Plus className="size-3.5" />
              Agregar moneda
              <ChevronsUpDown className="size-3.5 opacity-50" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-72 p-0" align="start">
            <Command
              filter={(value, search) =>
                value.toLowerCase().includes(search.toLowerCase()) ? 1 : 0
              }
            >
              <CommandInput placeholder="Buscar por país o código…" />
              <CommandList>
                <CommandEmpty>Sin resultados.</CommandEmpty>
                <CommandGroup>
                  {pickerOptions.map((c) => (
                    <CommandItem
                      key={c.code}
                      value={`${countryName(c.ccode)} ${c.code}`}
                      onSelect={() => addCurrency(c.code)}
                    >
                      <CountryFlag code={c.ccode} />
                      <span className="flex-1 truncate">{countryName(c.ccode)}</span>
                      <span className="text-xs tabular-nums text-muted-foreground">
                        {c.code}
                      </span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      )}

      {rows.length === 0 && pickerOptions.length === 0 && configured.length > 0 && (
        <p className="text-xs text-muted-foreground">
          Ya agregaste todas las monedas configuradas en Configuración → Monedas.
        </p>
      )}

      {errorMessage && <p className="text-sm text-destructive">{errorMessage}</p>}
    </div>
  )
}

// ── Helpers de país/moneda ─────────────────────────────────────────────────
//
// Mismos helpers que viven inline en /settings (CountryFlag/countryName).
// Repetidos a propósito — si aparece un tercer consumer, mover ambos a
// `lib/country.tsx`.

function CountryFlag({ code }: { code: string }) {
  const flag = code
    ? code
        .toUpperCase()
        .replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)))
    : "🌐"
  return <span className="text-xl leading-none">{flag}</span>
}

function countryName(ccode: string): string {
  if (!ccode) return ""
  try {
    const dn = new Intl.DisplayNames(["es"], { type: "region" })
    return dn.of(ccode.toUpperCase()) || ccode
  } catch {
    return ccode
  }
}
