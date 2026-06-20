import { CheckCircle2 } from "lucide-react"

interface Props {
  total: number
  change: number
}

function formatMoney(amount: number): string {
  return new Intl.NumberFormat("es-PY", {
    style: "currency",
    currency: "PYG",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount)
}

export function ConfirmedView({ total, change }: Props) {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center gap-8 px-8">
      <CheckCircle2 className="text-brand" style={{ width: "8rem", height: "8rem" }} />
      <div className="flex flex-col items-center gap-3 text-center">
        <h1
          className="font-bold text-brand"
          style={{ fontSize: "clamp(4rem, 10vw, 7rem)" }}
        >
          Cobrado
        </h1>
        <p
          className="font-semibold tabular-nums text-foreground"
          style={{ fontSize: "clamp(2.5rem, 6vw, 5rem)" }}
        >
          {formatMoney(total)}
        </p>
        {change > 0 && (
          <p className="text-3xl text-muted-foreground">
            Su vuelto: {formatMoney(change)}
          </p>
        )}
      </div>
    </div>
  )
}
