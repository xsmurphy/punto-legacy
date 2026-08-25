/**
 * Banderas como refuerzo visual de un país o de una moneda.
 *
 * EXCEPCIÓN DE EMOJIS PEDIDA POR EL OWNER (2026-08-25) — ver el encabezado de
 * `lib/country-flag.ts` para el alcance exacto. No sacar los emojis "por
 * convención": están pedidos.
 *
 * `aria-hidden`: la bandera acompaña un código que SIEMPRE está escrito al
 * lado (PYG, USD, …). Que un lector de pantalla anuncie la bandera además del
 * código duplica sin aportar información.
 */
import { countryFlagEmoji, currencyFlagEmoji } from "@/lib/country-flag"

/** Tamaño por defecto; los consumidores lo pisan cuando su fila pide otro. */
const DEFAULT_FLAG_CLASS = "text-xl leading-none"

/** Bandera desde ISO 3166-1 alpha-2 ("PY"). */
export function CountryFlag({
  code,
  className = DEFAULT_FLAG_CLASS,
}: {
  code: string | null | undefined
  className?: string
}) {
  return (
    <span aria-hidden className={className}>
      {countryFlagEmoji(code)}
    </span>
  )
}

/** Bandera resolviendo el país desde un ISO 4217 ("USD" → 🇺🇸). */
export function CurrencyFlag({
  code,
  className = DEFAULT_FLAG_CLASS,
}: {
  code: string | null | undefined
  className?: string
}) {
  return (
    <span aria-hidden className={className}>
      {currencyFlagEmoji(code)}
    </span>
  )
}
