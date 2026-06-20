import { Separator } from "@/components/ui/separator"
import type { CartPayload } from "./page"

interface Props {
  cart: CartPayload
}

function formatMoney(amount: number): string {
  return new Intl.NumberFormat("es-PY", {
    style: "currency",
    currency: "PYG",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount)
}

export function LiveView({ cart }: Props) {
  return (
    <div className="min-h-screen grid" style={{ gridTemplateColumns: "38fr 62fr" }}>
      <div className="bg-card flex flex-col justify-between p-12">
        <div>
          <p className="text-2xl text-muted-foreground font-medium">Total a pagar</p>
          <p
            className="font-bold tabular-nums text-brand mt-2 leading-none"
            style={{ fontSize: "clamp(4rem, 10vw, 8rem)" }}
          >
            {formatMoney(cart.total)}
          </p>
          {cart.discount > 0 && (
            <p className="text-xl text-muted-foreground mt-2">
              Descuento: {formatMoney(cart.discount)}
            </p>
          )}
        </div>
        {cart.customer && (
          <div className="mt-8">
            <p className="text-3xl font-semibold text-foreground">{cart.customer.name}</p>
            {cart.customer.tin && (
              <p className="text-xl text-muted-foreground mt-1">{cart.customer.tin}</p>
            )}
          </div>
        )}
      </div>
      <div className="flex flex-col p-12 bg-background overflow-auto">
        <p className="text-2xl font-semibold uppercase tracking-wide text-foreground mb-6">
          Artículos
        </p>
        <div className="flex flex-col gap-0">
          {cart.lines.map((line, i) => (
            <span key={i}>
              <div className="grid py-4" style={{ gridTemplateColumns: "3rem 1fr auto" }}>
                <span className="text-2xl tabular-nums text-muted-foreground font-medium">
                  {line.qty}
                </span>
                <span className="text-2xl text-foreground">{line.name}</span>
                <span className="text-2xl tabular-nums text-right text-foreground">
                  {formatMoney(line.total)}
                </span>
              </div>
              {i < cart.lines.length - 1 && <Separator />}
            </span>
          ))}
          {cart.lines.length === 0 && (
            <p className="text-xl text-muted-foreground">Sin artículos aún.</p>
          )}
        </div>
      </div>
    </div>
  )
}
