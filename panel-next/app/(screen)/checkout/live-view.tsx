import * as React from "react"
import { Separator } from "@/components/ui/separator"
import { PuntoLogo } from "@/components/layout/punto-logo"
import type { CartPayload } from "./page"

interface Props {
  cart: CartPayload
}

function formatMoney(amount: number): string {
  return new Intl.NumberFormat("es-PY", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount)
}

/**
 * Pantalla al cliente en estado activo (cart con ítems). Estructura inspirada
 * en el legacy ENCOM (total + datos izquierda, artículos derecha, watermark
 * Punto abajo-derecha) pero con colores del design system (no turquesa).
 */
export function LiveView({ cart }: Props) {
  return (
    <div className="relative min-h-screen grid" style={{ gridTemplateColumns: "55fr 45fr" }}>
      {/* Izquierda — total + cliente */}
      <div className="flex flex-col justify-between p-12 lg:p-16">
        <div>
          <p
            className="font-bold tabular-nums text-foreground leading-none"
            style={{ fontSize: "clamp(3rem, 7vw, 6rem)" }}
          >
            {formatMoney(cart.total)}
          </p>
          <p className="text-2xl text-muted-foreground mt-3">Total a pagar en Gs</p>
          {cart.discount > 0 && (
            <p className="text-lg text-muted-foreground mt-2">
              Descuento: {formatMoney(cart.discount)}
            </p>
          )}
        </div>
        {cart.customer ? (
          <div className="mt-12">
            <p className="text-3xl font-semibold text-foreground italic">
              {cart.customer.name}
            </p>
            {cart.customer.tin && (
              <p className="text-xl text-muted-foreground mt-1">{cart.customer.tin}</p>
            )}
          </div>
        ) : (
          <p className="mt-12 text-2xl font-semibold text-muted-foreground italic">
            Sin cliente
          </p>
        )}
      </div>

      {/* Derecha — lista de artículos */}
      <div className="bg-card flex flex-col p-12 lg:p-16 overflow-auto">
        <p className="text-xl font-bold uppercase tracking-wide text-foreground mb-6">
          Artículos
        </p>
        <div className="flex flex-col">
          {cart.lines.map((line, i) => (
            <React.Fragment key={i}>
              <div className="grid py-4 items-center" style={{ gridTemplateColumns: "2.5rem 1fr auto" }}>
                <span className="text-lg tabular-nums text-muted-foreground font-medium">
                  {line.qty}
                </span>
                <span className="text-lg text-foreground">{line.name}</span>
                <span className="text-lg tabular-nums text-right text-foreground">
                  {formatMoney(line.total)}
                </span>
              </div>
              {i < cart.lines.length - 1 && <Separator />}
            </React.Fragment>
          ))}
        </div>
      </div>

      {/* Watermark Punto — esquina inferior derecha */}
      <div className="pointer-events-none absolute bottom-6 right-8 flex flex-col items-end gap-1 opacity-60">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
        <PuntoLogo variant="wordmark" className="h-6 w-auto" />
      </div>
    </div>
  )
}
