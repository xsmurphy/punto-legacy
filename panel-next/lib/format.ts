import type { Bootstrap } from "@/lib/types/bootstrap"

/**
 * Formatea un monto monetario respetando la config del tenant.
 *
 * bootstrap.decimal ('yes'|'no') decide cantidad de decimales (2 o 0).
 * bootstrap.thousand ('comma'|'dot') decide el separador de miles via locale.
 * bootstrap.currency es el SÍMBOLO ya formateado (₲, $, US$, etc), no el ISO.
 */
export function formatMoney(
  amount: number | null | undefined,
  bootstrap: Pick<Bootstrap, "currency" | "decimal" | "thousand"> | undefined,
): string {
  const n = typeof amount === "number" && isFinite(amount) ? amount : 0
  if (!bootstrap) {
    return new Intl.NumberFormat("es-PY").format(n)
  }
  const decimals = bootstrap.decimal === "yes" ? 2 : 0
  // 'comma' (thousand=',') = locale anglo (en-US); 'dot' (thousand='.') = locale es-*.
  const locale = bootstrap.thousand === "comma" ? "en-US" : "es-PY"
  const formatted = new Intl.NumberFormat(locale, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(n)
  return `${bootstrap.currency} ${formatted}`
}

/**
 * Formatea entero con separador de miles según bootstrap.thousand.
 */
export function formatInt(
  n: number | null | undefined,
  bootstrap: Pick<Bootstrap, "thousand"> | undefined,
): string {
  const v = typeof n === "number" && isFinite(n) ? n : 0
  const locale = bootstrap?.thousand === "comma" ? "en-US" : "es-PY"
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(v)
}
