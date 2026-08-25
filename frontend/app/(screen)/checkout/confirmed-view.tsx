import { CheckCircle2 } from "lucide-react"
import { formatMoney as formatMoneyShared } from "@/lib/format-money"
import type { ScreenContext } from "./page"

interface Props {
  total: number
  change: number
  ctx: ScreenContext | null
}

/**
 * Monto cobrado + vuelto, en la moneda del TENANT.
 *
 * Antes: `Intl.NumberFormat("es-PY", { style: "currency", currency: "PYG" })`.
 * O sea, el cliente de un comercio brasileño o argentino leía "Cobrado" con
 * el importe en guaraníes — y esta es la última pantalla que mira antes de
 * irse con el vuelto en la mano. `formatMoney` compone la etiqueta de moneda
 * del tenant con los separadores/decimales que el tenant configuró.
 */
function formatMoney(amount: number, ctx: ScreenContext | null): string {
  return formatMoneyShared(amount, ctx)
}

export function ConfirmedView({ total, change, ctx }: Props) {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center gap-8 px-8">
      <CheckCircle2 className="text-brand" style={{ width: "8rem", height: "8rem" }} />
      <div className="flex flex-col items-center gap-3 text-center">
        <h1
          className="font-bold text-foreground"
          style={{ fontSize: "clamp(4rem, 10vw, 7rem)" }}
        >
          Cobrado
        </h1>
        <p
          className="font-semibold tabular-nums text-foreground"
          style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)" }}
        >
          {formatMoney(total, ctx)}
        </p>
        {change > 0 && (
          <p className="text-3xl text-muted-foreground">
            Su vuelto: {formatMoney(change, ctx)}
          </p>
        )}
      </div>
    </div>
  )
}
