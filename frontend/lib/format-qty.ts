/**
 * Formatea CANTIDADES (unidades de stock), no dinero.
 *
 * Vive separado de `format-money.ts` porque la regla de decimales es otra: la
 * moneda del tenant puede no usar decimales (guaraní) pero un ítem por peso
 * igual se cuenta en 2,5 kg. Y sin ceros de relleno — "12" y no "12,00", que
 * en una lista de saldos solo agrega ruido.
 */

import { resolveNumberLocale, type TenantLocaleConfig } from "@/lib/tenant-locale"

// `undefined` además de `null`: es lo que devuelve `useBootstrap().data` en el
// primer render, y era la única de las tres funciones de formato que no lo
// aceptaba (`formatMoney`/`formatInt` sí) — cada caller de UI tenía que
// escribir `bootstrap ?? null`. Se arregla en el helper, no en el call-site.
export function formatQty(
  value: number | null | undefined,
  config: TenantLocaleConfig | null | undefined,
): string {
  const n = typeof value === "number" && Number.isFinite(value) ? value : 0
  // El separador de miles sale del resolver único (`config.thousand`, o el
  // país del tenant). Mismo criterio que `formatAmount`.
  return new Intl.NumberFormat(resolveNumberLocale(config), {
    maximumFractionDigits: 3,
  }).format(n)
}
