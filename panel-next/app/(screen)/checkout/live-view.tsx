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
 * Pantalla al cliente en estado activo (cart con ítems). Estructura
 * inspirada en el legacy ENCOM: total + cliente arriba-izq, "ARTÍCULOS" +
 * lista derecha, watermark "Usamos Punto" abajo-derecha. Sin colores
 * turquesa — usa tokens del design system.
 */
export function LiveView({ cart }: Props) {
  return (
    <div className="relative min-h-screen grid" style={{ gridTemplateColumns: "55fr 45fr" }}>
      {/* Izquierda — total, "Total a pagar en Gs", y cliente debajo */}
      <div className="flex flex-col p-12 lg:p-16">
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
        {cart.customer ? (
          <div className="mt-10">
            <p className="text-3xl font-semibold text-foreground italic">
              {cart.customer.name}
            </p>
            {cart.customer.tin && (
              <p className="text-xl text-muted-foreground mt-1">{cart.customer.tin}</p>
            )}
          </div>
        ) : (
          <p className="mt-10 text-2xl font-semibold text-muted-foreground italic">
            Sin cliente
          </p>
        )}
      </div>

      {/* Derecha — header "ARTÍCULOS" + lista */}
      <div className="flex flex-col p-12 lg:p-16 overflow-auto">
        <p className="text-xl font-bold uppercase tracking-wide text-foreground mb-6 text-right">
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

      {/* Watermark Punto — abajo-derecha */}
      <div className="pointer-events-none absolute bottom-8 right-12 flex flex-col items-end gap-1 opacity-70">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
        <PuntoLogo variant="wordmark" className="h-8 w-[110px]" />
      </div>
    </div>
  )
}
