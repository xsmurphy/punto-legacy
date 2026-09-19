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

/**
 * Porcentaje con el separador decimal del tenant: `42,5 %`. `value` ya es el
 * porcentaje (42.5), no la fracción.
 */
export function formatPercent(
  value: number | null | undefined,
  bootstrap: TenantLocaleConfig | null | undefined,
  digits = 1,
): string {
  const v = typeof value === "number" && isFinite(value) ? value : 0
  return `${new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(v)} %`
}

/**
 * Monto abreviado para espacios chicos (rankings, tiles): `Gs 1,5 M`,
 * `Gs 820 k`. Sufijos fijos `k`/`M` (los mismos de los ejes de los gráficos)
 * y el separador decimal del tenant — NO la notación compacta de `Intl`: el
 * locale de `resolveNumberLocale` es solo de separadores (`de-DE`/`en-US`) y
 * abreviaría en alemán ("19 Mio."). Por debajo de mil, el monto completo.
 */
export function formatMoneyCompact(
  amount: number | null | undefined,
  bootstrap: TenantLocaleConfig | null | undefined,
): string {
  const n = typeof amount === "number" && isFinite(amount) ? amount : 0
  if (Math.abs(n) < 1000) return formatMoney(n, bootstrap)
  return `${resolveCurrencyLabel(bootstrap)} ${formatCompact(n, bootstrap)}`
}

/** Cantidad abreviada (`13,4 k`); por debajo de mil, el entero completo. */
export function formatIntCompact(
  n: number | null | undefined,
  bootstrap: TenantLocaleConfig | null | undefined,
): string {
  const v = typeof n === "number" && isFinite(n) ? n : 0
  if (Math.abs(v) < 1000) return formatInt(v, bootstrap)
  return formatCompact(v, bootstrap)
}

function formatCompact(v: number, bootstrap: TenantLocaleConfig | null | undefined): string {
  const [div, suffix] = Math.abs(v) >= 1_000_000 ? [1_000_000, "M"] : [1_000, "k"]
  const num = new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
    maximumFractionDigits: 1,
  }).format(v / div)
  return `${num} ${suffix}`
}
