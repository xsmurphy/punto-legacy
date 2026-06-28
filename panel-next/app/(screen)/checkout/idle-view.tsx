import { PuntoLogo } from "@/components/layout/punto-logo"

/**
 * Estado idle del visor: misma estructura que LiveView pero con total "0" y
 * "Sin cliente". Inspirado en el legacy ENCOM (total grande izq + watermark
 * derecha) con colores del design system. La lista derecha se omite porque
 * el legacy también la deja vacía en idle.
 */
export function IdleView() {
  return (
    <div className="relative min-h-screen flex flex-col justify-between p-12 lg:p-16">
      <div>
        <p
          className="font-bold tabular-nums text-foreground leading-none"
          style={{ fontSize: "clamp(3rem, 7vw, 6rem)" }}
        >
          0
        </p>
        <p className="text-2xl text-muted-foreground mt-3">Total a pagar en Gs</p>
      </div>
      <p className="text-2xl font-semibold text-muted-foreground italic">Sin cliente</p>

      {/* Watermark Punto — esquina inferior derecha */}
      <div className="pointer-events-none absolute bottom-6 right-8 flex flex-col items-end gap-1 opacity-60">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">Usamos</span>
        <PuntoLogo variant="wordmark" className="h-6 w-auto" />
      </div>
    </div>
  )
}
