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
    <div className="relative min-h-screen grid grid-cols-2">
      {/* Izquierda — verticalmente centrado: total + cliente + sucursal/caja.
          min-w-0 evita que un total grande (ej. millones) expanda la columna
          y desplace la línea central — la grid 50/50 queda siempre fija. */}
      <div className="min-w-0 flex flex-col justify-center p-12 lg:p-16">
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

      {/* Derecha — panel relativo con border-l visible y watermark centrado abajo */}
      <div className="min-w-0 relative flex flex-col border-l border-border">
        {hasItems ? (
          <div className="flex-1 flex flex-col p-12 lg:p-16 overflow-auto">
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
          <div className="flex-1 flex items-center justify-center p-12 lg:p-16">
            <TenantLogoInner ctx={ctx} />
          </div>
        )}

        {/* Watermark Punto — centrado horizontal abajo del panel derecho.
            w-full obliga al flex a llenar el panel; items-center centra el
            stack (label + logo) en X (sin esto el bloque queda intrínseco
            del lado izquierdo del panel). */}
        <div className="pointer-events-none w-full flex flex-col items-center gap-1 opacity-70 pb-8">
          <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
          <PuntoLogo variant="wordmark" className="h-8 w-[110px]" />
        </div>
      </div>
    </div>
  )
}

/**
 * Inner del logo del tenant — sin padding/border, para usar dentro de un
 * contenedor que ya posiciona y separa. Tamaño chico match con el mockup
 * 2026-06-28 (h-32 w-32, antes era h-40 w-80).
 */
export function TenantLogoInner({ ctx }: { ctx: ScreenContext | null }) {
  if (ctx?.logoUrl) {
    return (
      <div className="relative h-32 w-32">
        <Image
          src={ctx.logoUrl}
          alt={ctx.companyName || "Logo"}
          fill
          className="object-contain"
          unoptimized
        />
      </div>
    )
  }
  if (ctx?.companyName) {
    return <p className="text-3xl font-bold text-foreground text-center">{ctx.companyName}</p>
  }
  return null
}
