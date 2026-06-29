import { getCountryCallingCode, type CountryCode } from "libphonenumber-js"

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
 * Emoji flag desde código ISO 3166-1 alpha-2.
 * Cada letra A-Z se mapea a un regional indicator symbol (U+1F1E6..U+1F1FF).
 * Render del flag depende del OS (no falla, solo cambia el glyph).
 */
function flagEmoji(country: string): string {
  return country
    .toUpperCase()
    .replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)))
}

export const SUPPORTED_COUNTRIES: Country[] = Object.entries(COUNTRY_NAMES).map(
  ([code, name]) => ({
    code: code as CountryCode,
    name,
    dialCode: getCountryCallingCode(code as CountryCode),
    flag: flagEmoji(code),
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
    flag: flagEmoji(code),
  }
}
