/**
 * Formatea un número como moneda según la config del tenant.
 *
 * Reutilizable en contextos no-input (display, labels, botones).
 * Para inputs editables usar `<MoneyInput>`.
 */
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import {
  countryDefaults,
  resolveCurrencyLabel,
  resolveDecimals,
  type TenantLocaleConfig,
} from "@/lib/tenant-locale"

// Re-export para que los call-sites que ya importaban la etiqueta desde acá
// no tengan que cambiar de módulo. La implementación vive en
// `lib/tenant-locale.ts`, que es el resolver único de la dimensión moneda.
export { resolveCurrencyLabel, UNKNOWN_CURRENCY_SIGN } from "@/lib/tenant-locale"

/**
 * Formatea solo el número con separadores del tenant — sin currency.
 * Usado en filas del carrito donde el símbolo de moneda sólo va en el botón
 * de cobrar (ver guía visual del owner 2026-06-16).
 *
 * El separador sale de `config.thousand` (ajuste explícito del tenant); si no
 * llega, del `thousandSeparator` del PAÍS del tenant. Antes el default duro
 * era el punto — correcto para Paraguay y para casi toda LatAm, pero no para
 * MX/US/EC, que usan coma.
 */
export function formatAmount(
  value: number,
  config: Pick<PosConfig, "thousand" | "decimal"> | (TenantLocaleConfig | null),
): string {
  const cfg = config as TenantLocaleConfig | null
  const configuredThousand = cfg?.thousand
  const thousand = configuredThousand
    ? configuredThousand === "comma"
      ? ","
      : "."
    : (countryDefaults(cfg)?.thousandSeparator ?? ".")
  const decimalSep = thousand === "," ? "." : ","

  const decimals = resolveDecimals(cfg)
  const scaled = Math.round(value * Math.pow(10, decimals))
  const abs = Math.abs(scaled).toString().padStart(decimals + 1, "0")
  const intPart = abs.slice(0, abs.length - decimals) || "0"
  const decPart = decimals > 0 ? abs.slice(-decimals) : ""
  const withThousand = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousand)

  return decPart ? `${withThousand}${decimalSep}${decPart}` : withThousand
}

export function formatMoney(
  value: number,
  config:
    | Pick<PosConfig, "currency" | "thousand" | "decimal">
    | (TenantLocaleConfig | null),
): string {
  const cfg = config as TenantLocaleConfig | null
  return `${resolveCurrencyLabel(cfg)} ${formatAmount(value, cfg)}`
}

/** ISO 4217 codes with no decimal places. */
const NO_DECIMAL_CURRENCIES = new Set([
  "PYG", "CLP", "JPY", "KRW", "VND", "IDR",
])

/**
 * Formatea un monto en una divisa EXPLÍCITA (código ISO 4217), no en la
 * moneda del tenant. Se usa donde el documento trae su propia moneda:
 * facturas electrónicas, portal público del cliente.
 *
 * Monedas sin decimales (PYG, CLP, JPY, KRW, VND, IDR) → entero.
 * El resto → 2 decimales.
 *
 * `code` puede ser `null` cuando el documento no declara moneda. En ese caso
 * se usan 2 decimales (la norma de ISO 4217) y el call-site NO debe pintar
 * bandera ni código: es preferible mostrar el importe sin divisa a inventarle
 * una. Antes estos call-sites hacían `?? "PYG"` y le ponían bandera paraguaya
 * a un documento de moneda desconocida.
 */
export function formatCurrencyAmount(amount: number, code: string | null): string {
  const noDecimal = code ? NO_DECIMAL_CURRENCIES.has(code.toUpperCase()) : false
  return new Intl.NumberFormat(undefined, {
    style: "decimal",
    minimumFractionDigits: noDecimal ? 0 : 2,
    maximumFractionDigits: noDecimal ? 0 : 2,
  }).format(amount)
}
