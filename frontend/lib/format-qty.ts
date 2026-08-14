/**
 * Formatea CANTIDADES (unidades de stock), no dinero.
 *
 * Vive separado de `format-money.ts` porque la regla de decimales es otra: la
 * moneda del tenant puede no usar decimales (guaraní) pero un ítem por peso
 * igual se cuenta en 2,5 kg. Y sin ceros de relleno — "12" y no "12,00", que
 * en una lista de saldos solo agrega ruido.
 */

import type { PosConfig } from "@/lib/types/pos-bootstrap"

export function formatQty(
  value: number | null | undefined,
  config: Pick<PosConfig, "thousand"> | null,
): string {
  const n = typeof value === "number" && Number.isFinite(value) ? value : 0
  // 'comma' (miles con ',') = locale anglo; 'dot' = locale es-*. Mismo criterio
  // que `formatAmount`.
  const locale = config?.thousand === "comma" ? "en-US" : "es-PY"
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(n)
}
