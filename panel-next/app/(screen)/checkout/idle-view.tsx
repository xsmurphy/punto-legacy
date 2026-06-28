import { PuntoLogo } from "@/components/layout/punto-logo"
import { TenantLogoInner } from "./live-view"
import type { ScreenContext } from "./page"

function locationLine(ctx: ScreenContext | null): string {
  if (!ctx) return ""
  return [ctx.outletName, ctx.registerName].filter(Boolean).join(" - ")
}

/**
 * Estado idle del visor — total "0", "Sin cliente", sucursal/caja
 * verticalmente centrados izq; logo del tenant centrado der con border-l
 * visible separando paneles; watermark "Usamos Punto" centrado horizontal
 * abajo del panel derecho.
 */
export function IdleView({ ctx }: { ctx: ScreenContext | null }) {
  return (
    <div className="relative min-h-screen grid" style={{ gridTemplateColumns: "55fr 45fr" }}>
      {/* Izquierda */}
      <div className="flex flex-col justify-center p-12 lg:p-16">
        <p
          className="font-bold tabular-nums text-foreground leading-none"
          style={{ fontSize: "clamp(3rem, 7vw, 6rem)" }}
        >
          0
        </p>
        <p className="text-2xl text-muted-foreground mt-3">Total a pagar en Gs</p>
        <p className="mt-10 text-2xl font-semibold text-muted-foreground italic">
          Sin cliente
        </p>
        {locationLine(ctx) && (
          <p className="mt-2 text-base text-muted-foreground">{locationLine(ctx)}</p>
        )}
      </div>

      {/* Derecha — logo del tenant + watermark centrado horizontal abajo */}
      <div className="relative flex flex-col border-l border-border">
        <div className="flex-1 flex items-center justify-center p-12 lg:p-16">
          <TenantLogoInner ctx={ctx} />
        </div>
        <div className="pointer-events-none flex flex-col items-center gap-1 opacity-70 pb-8">
          <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
          <PuntoLogo variant="wordmark" className="h-8 w-[110px]" />
        </div>
      </div>
    </div>
  )
}
