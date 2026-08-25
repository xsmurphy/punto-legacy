import { getCountryCallingCode, type CountryCode } from "libphonenumber-js"
import { countryFlagEmoji } from "@/lib/country-flag"

/**
 * Países soportados en el picker de teléfono.
 *
 * Por ahora hardcoded a LATAM + ES + US (mercado real de Punto). El long tail
 * se agrega cuando aparezca un cliente que lo pida — `libphonenumber-js`
 * soporta los 240 países, solo no le damos UI hasta que haga falta.
 *
 * Default = PY (Paraguay).
 */
export const DEFAULT_COUNTRY: CountryCode = "PY"

export interface Country {
  code: CountryCode
  name: string
  dialCode: string // sin "+", ej "595"
  flag: string
}

const COUNTRY_NAMES: Record<string, string> = {
  PY: "Paraguay",
  AR: "Argentina",
  UY: "Uruguay",
  BR: "Brasil",
  CL: "Chile",
  BO: "Bolivia",
  PE: "Perú",
  CO: "Colombia",
  EC: "Ecuador",
  VE: "Venezuela",
  MX: "México",
  ES: "España",
  US: "Estados Unidos",
}

/**
 * La implementación se movió a `lib/country-flag.tsx` (`countryFlagEmoji`) —
 * era una de tres copias del mismo algoritmo. Acá queda solo el consumo.
 */

export const SUPPORTED_COUNTRIES: Country[] = Object.entries(COUNTRY_NAMES).map(
  ([code, name]) => ({
    code: code as CountryCode,
    name,
    dialCode: getCountryCallingCode(code as CountryCode),
    flag: countryFlagEmoji(code),
  }),
)

export function getCountry(code: CountryCode): Country {
  const match = SUPPORTED_COUNTRIES.find((c) => c.code === code)
  if (match) return match
  // Fallback: país que no está en la lista pero libphonenumber lo conoce.
  // Útil si una sesión guardó un país no listado.
  return {
    code,
    name: code,
    dialCode: getCountryCallingCode(code),
    flag: countryFlagEmoji(code),
  }
}
