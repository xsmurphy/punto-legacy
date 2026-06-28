import { PuntoLogo } from "@/components/layout/punto-logo"

/**
 * Estado idle del visor: total "0", "Sin cliente", watermark Punto. La
 * columna derecha del legacy ENCOM idle queda vacía (sin header ARTÍCULOS
 * — solo aparece cuando hay ítems en LiveView).
 */
export function IdleView() {
  return (
    <div className="relative min-h-screen flex flex-col p-12 lg:p-16">
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

      {/* Watermark Punto — abajo-derecha */}
      <div className="pointer-events-none absolute bottom-8 right-12 flex flex-col items-end gap-1 opacity-70">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
        <PuntoLogo variant="wordmark" className="h-8 w-[110px]" />
      </div>
    </div>
  )
}
