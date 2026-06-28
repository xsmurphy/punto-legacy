import { PuntoLogo } from "@/components/layout/punto-logo"
import { TenantLogoBlock } from "./live-view"
import type { ScreenContext } from "./page"

/**
 * Estado idle del visor — total "0", "Sin cliente" verticalmente centrados
 * en columna izquierda; logo del tenant centrado en columna derecha;
 * watermark "Usamos Punto" abajo-derecha. Misma estructura que LiveView
 * con cart vacío.
 */
export function IdleView({ ctx }: { ctx: ScreenContext | null }) {
  return (
    <div className="relative min-h-screen grid" style={{ gridTemplateColumns: "55fr 45fr" }}>
      {/* Izquierda — bloque centrado verticalmente */}
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
      </div>

      {/* Derecha — logo del tenant */}
      <TenantLogoBlock ctx={ctx} />

      {/* Watermark Punto — abajo-derecha */}
      <div className="pointer-events-none absolute bottom-8 right-12 flex flex-col items-end gap-1 opacity-70">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
        <PuntoLogo variant="wordmark" className="h-8 w-[110px]" />
      </div>
    </div>
  )
}
