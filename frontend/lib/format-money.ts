/**
 * Formatea un número como moneda según la config del tenant.
 *
 * Reutilizable en contextos no-input (display, labels, botones).
 * Para inputs editables usar `<MoneyInput>`.
 */
import type { PosConfig } from "@/lib/types/pos-bootstrap"

/**
 * Formatea solo el número con separadores del tenant — sin currency.
 * Usado en filas del carrito donde el símbolo "Gs" sólo va en el botón
 * de cobrar (ver guía visual del owner 2026-06-16).
 */
export function formatAmount(
  value: number,
  config: Pick<PosConfig, "thousand" | "decimal"> | null,
): string {
  const thousand = config?.thousand === "comma" ? "," : "."
  const decimalSep = thousand === "," ? "." : ","
  const useDecimals = config?.decimal === "yes"

  const decimals = useDecimals ? 2 : 0
  const scaled = Math.round(value * Math.pow(10, decimals))
  const abs = Math.abs(scaled).toString().padStart(decimals + 1, "0")
  const intPart = abs.slice(0, abs.length - decimals) || "0"
  const decPart = decimals > 0 ? abs.slice(-decimals) : ""
  const withThousand = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousand)

  return decPart ? `${withThousand}${decimalSep}${decPart}` : withThousand
}

/** Fallback cuando el tenant no tiene moneda configurada. */
const DEFAULT_CURRENCY_LABEL = "Gs"

/**
 * Etiqueta de la moneda local del tenant, siempre no vacía.
 *
 * Por qué existe: el BFF del bootstrap normaliza la moneda ausente a string
 * VACÍO (`currency: bs.currency ?? ""` en `app/api/pos/bootstrap/route.ts`),
 * y `config?.currency ?? "Gs"` NO cubre ese caso — `??` solo dispara con
 * null/undefined. El resultado era una etiqueta vacía: el botón de modo
 * "moneda" del NumericPad salía SIN texto visible (reporte del owner sobre el
 * modal de descuento) y `formatMoney` devolvía un espacio de más al principio.
 * El fallback vive acá, en la única fuente de la etiqueta, y no repetido en
 * cada call-site — que era justamente cómo se coló el bug.
 */
export function resolveCurrencyLabel(
  config: Pick<PosConfig, "currency"> | null,
): string {
  const raw = config?.currency?.trim()
  return raw ? raw : DEFAULT_CURRENCY_LABEL
}

export function formatMoney(
  value: number,
  config: Pick<PosConfig, "currency" | "thousand" | "decimal"> | null,
): string {
  return `${resolveCurrencyLabel(config)} ${formatAmount(value, config)}`
}

/** ISO 4217 codes with no decimal places. */
const NO_DECIMAL_CURRENCIES = new Set([
  "PYG", "CLP", "JPY", "KRW", "VND", "IDR",
])

/**
 * Formats a foreign-currency amount using Intl.NumberFormat.
 * No-decimal currencies (PYG, CLP, JPY, KRW, VND, IDR) → integer.
 * All others → 2 decimal places.
 */
export function formatCurrencyAmount(amount: number, code: string): string {
  const noDecimal = NO_DECIMAL_CURRENCIES.has(code.toUpperCase())
  return new Intl.NumberFormat(undefined, {
    style: "decimal",
    minimumFractionDigits: noDecimal ? 0 : 2,
    maximumFractionDigits: noDecimal ? 0 : 2,
  }).format(amount)
}
