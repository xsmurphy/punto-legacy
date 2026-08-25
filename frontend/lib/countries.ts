import { getCountryCallingCode, type CountryCode } from "libphonenumber-js"
import { countryFlagEmoji } from "@/lib/country-flag"

/**
 * Países soportados en el picker de teléfono.
 *
 * Por ahora hardcoded a LATAM + ES + US (mercado real de Punto). El long tail
 * se agrega cuando aparezca un cliente que lo pida — `libphonenumber-js`
 * soporta los 240 países, solo no le damos UI hasta que haga falta.
 *
 * País de arranque del selector de teléfono cuando NO se conoce el del
 * tenant.
 *
 * Es el único default de país que sobrevive en el frontend, y es deliberado:
 * un `<PhoneInput>` necesita SIEMPRE un país seleccionado para poder parsear
 * lo que se tipea (libphonenumber no puede interpretar "0981 234 567" sin
 * saber de dónde es), así que acá no existe la opción "ninguno" que sí usan
 * los demás resolvers.
 *
 * La regla es: quien tenga el bootstrap a mano NO debe usar esta constante,
 * sino `resolvePhoneCountry(config)` (lib/tenant-locale.ts), que devuelve el
 * país del tenant. Esta constante es el último recurso para los formularios
 * que se montan sin bootstrap disponible (login y signup, donde todavía no
 * hay tenant). Sigue siendo PY porque es el mercado donde vive el 100% de
 * los tenants que se dan de alta hoy, y en esos dos formularios el usuario
 * puede cambiarlo con el selector antes de escribir.
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
