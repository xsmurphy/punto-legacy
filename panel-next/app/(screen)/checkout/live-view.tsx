import * as React from "react"
import Image from "next/image"
import { Separator } from "@/components/ui/separator"
import { PuntoLogo } from "@/components/layout/punto-logo"
import type { CartPayload, ScreenContext } from "./page"

interface Props {
  cart: CartPayload
  ctx: ScreenContext | null
}

function formatMoney(amount: number): string {
  return new Intl.NumberFormat("es-PY", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount)
}

function locationLine(ctx: ScreenContext | null): string {
  if (!ctx) return ""
  const parts = [ctx.outletName, ctx.registerName].filter(Boolean)
  return parts.join(" - ")
}

/**
 * Visor al cliente — estado activo (cart con ítems). Estructura del mockup
 * 2026-06-28: total + cliente + sucursal/caja centrados verticalmente izq,
 * artículos derecha con border-l finito que separa los paneles, logo del
 * tenant centrado cuando lista vacía. Watermark "Usamos Punto" abajo-der.
 */
export function LiveView({ cart, ctx }: Props) {
  const hasItems = cart.lines.length > 0
  return (
    <div className="relative min-h-screen grid" style={{ gridTemplateColumns: "55fr 45fr" }}>
      {/* Izquierda — verticalmente centrado: total + cliente + sucursal/caja */}
      <div className="flex flex-col justify-center p-12 lg:p-16">
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
          <p className="mt-10 text-3xl font-semibold text-foreground italic">
            {cart.customer.name}
            {cart.customer.tin && (
              <span className="block text-xl text-muted-foreground not-italic font-normal mt-1">
                {cart.customer.tin}
              </span>
            )}
          </p>
        ) : (
          <p className="mt-10 text-2xl font-semibold text-muted-foreground italic">
            Sin cliente
          </p>
        )}
        {locationLine(ctx) && (
          <p className="mt-2 text-base text-muted-foreground">{locationLine(ctx)}</p>
        )}
      </div>

      {/* Derecha — border-l separa los paneles. Lista o logo del tenant. */}
      {hasItems ? (
        <div className="flex flex-col p-12 lg:p-16 overflow-auto border-l">
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
      ) : (
        <TenantLogoBlock ctx={ctx} />
      )}

      {/* Watermark Punto — abajo-derecha (siempre) */}
      <div className="pointer-events-none absolute bottom-8 right-12 flex flex-col items-end gap-1 opacity-70">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
        <PuntoLogo variant="wordmark" className="h-8 w-[110px]" />
      </div>
    </div>
  )
}

/**
 * Bloque centrado para el logo del tenant. Con border-l fino para igualar
 * el separador entre paneles cuando hay lista de artículos.
 */
export function TenantLogoBlock({ ctx }: { ctx: ScreenContext | null }) {
  return (
    <div className="flex flex-col items-center justify-center p-12 lg:p-16 border-l">
      {ctx?.logoUrl ? (
        <div className="relative h-32 w-64 lg:h-40 lg:w-80">
          <Image
            src={ctx.logoUrl}
            alt={ctx.companyName || "Logo"}
            fill
            className="object-contain"
            unoptimized
          />
        </div>
      ) : ctx?.companyName ? (
        <p className="text-4xl font-bold text-foreground text-center">{ctx.companyName}</p>
      ) : null}
    </div>
  )
}
