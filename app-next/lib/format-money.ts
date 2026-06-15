/**
 * Formatea un número como moneda según la config del tenant.
 *
 * Reutilizable en contextos no-input (display, labels, botones).
 * Para inputs editables usar `<MoneyInput>`.
 */
import type { PosConfig } from "@/lib/types/pos-bootstrap"

export function formatMoney(
  value: number,
  config: Pick<PosConfig, "currency" | "thousand" | "decimal"> | null,
): string {
  const thousand = config?.thousand === "comma" ? "," : "."
  const decimalSep = thousand === "," ? "." : ","
  const useDecimals = config?.decimal === "yes"
  const currency = config?.currency ?? "Gs"

  const decimals = useDecimals ? 2 : 0
  const scaled = Math.round(value * Math.pow(10, decimals))
  const abs = Math.abs(scaled).toString().padStart(decimals + 1, "0")
  const intPart = abs.slice(0, abs.length - decimals) || "0"
  const decPart = decimals > 0 ? abs.slice(-decimals) : ""
  const withThousand = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousand)

  const number = decPart ? `${withThousand}${decimalSep}${decPart}` : withThousand
  return `${currency} ${number}`
}
