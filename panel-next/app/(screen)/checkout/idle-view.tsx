import { PuntoLogo } from "@/components/layout/punto-logo"

export function IdleView() {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center gap-6 px-8">
      <PuntoLogo variant="wordmark" className="h-16 w-auto opacity-60 select-none" />
      <p className="text-2xl text-muted-foreground text-center">
        Esperando próxima venta
      </p>
    </div>
  )
}
