import {
  resolveCurrencyLabel,
  resolveDecimals,
  resolveNumberLocale,
  type TenantLocaleConfig,
} from "@/lib/tenant-locale"

/**
 * Formatea un monto monetario respetando la config del tenant.
 *
 * Las tres dimensiones salen de los resolvers de `lib/tenant-locale.ts`:
 *  - decimales  → `resolveDecimals`      (bootstrap.decimal, o el país)
 *  - separador  → `resolveNumberLocale`  (bootstrap.thousand, o el país)
 *  - etiqueta   → `resolveCurrencyLabel` (bootstrap.currency, o el país)
 *
 * Antes esta función hacía `bootstrap.thousand === "comma" ? "en-US" : "es-PY"`
 * y usaba `bootstrap.currency` crudo. Lo segundo imprimía una etiqueta VACÍA
 * cuando el tenant no configuró moneda (el bootstrap manda `""`, no null).
 */
export function formatMoney(
  amount: number | null | undefined,
  bootstrap: TenantLocaleConfig | null | undefined,
): string {
  const n = typeof amount === "number" && isFinite(amount) ? amount : 0
  const decimals = resolveDecimals(bootstrap)
  const formatted = new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(n)
  return `${resolveCurrencyLabel(bootstrap)} ${formatted}`
}

/**
 * Formatea entero con separador de miles según la config del tenant.
 */
export function formatInt(
  n: number | null | undefined,
  bootstrap: TenantLocaleConfig | null | undefined,
): string {
  const v = typeof n === "number" && isFinite(n) ? n : 0
  return new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
    maximumFractionDigits: 0,
  }).format(v)
}
