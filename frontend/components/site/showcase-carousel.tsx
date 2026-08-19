import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"

/**
 * Cards de comercios de ejemplo que solapan el borde inferior del hero.
 * Los "screenshots" son placeholders de gradiente hasta tener capturas
 * reales de tiendas demo.
 */
const SHOWCASES = [
  {
    name: "La Estancia",
    description: "Parrilla en Lambaré. 60 cubiertos por noche, dos turnos de caja.",
    status: "Abierto · cierra 01:00",
    open: true,
    tint: "from-chart-2/40 via-chart-1/10",
  },
  {
    name: "Mercadito San Blas",
    description: "Autoservicio familiar. Tres cajas y reposición diaria.",
    status: "Abierto · 24 horas",
    open: true,
    tint: "from-chart-1/40 via-chart-2/10",
  },
  {
    name: "Farmacia Del Sol",
    description: "Tres sucursales en Asunción con stock compartido a la vista.",
    status: "Abierto · cierra 22:00",
    open: true,
    tint: "from-chart-3/40 via-chart-1/10",
  },
  {
    name: "Lo de Ña Tere",
    description: "Cocina casera en San Lorenzo. Tres generaciones detrás del mostrador.",
    status: "Cerrado · abre hoy 11:00",
    open: false,
    tint: "from-chart-4/40 via-chart-3/10",
  },
]

export function ShowcaseCarousel() {
  return (
    <section aria-label="Comercios que usan Punto" className="relative z-10 -mt-28">
      <div className="mx-auto w-full max-w-6xl px-4 md:px-6">
        <div className="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          {SHOWCASES.map((s) => (
            <article
              key={s.name}
              className="w-72 shrink-0 snap-center overflow-hidden rounded-2xl border bg-background md:w-80"
            >
              <div
                aria-hidden
                className={cn(
                  "h-40 w-full bg-gradient-to-br to-muted",
                  s.tint,
                )}
              />
              <div className="flex flex-col gap-1.5 p-5">
                <h3 className="text-base font-semibold tracking-tight">{s.name}</h3>
                <p className="text-sm text-muted-foreground">{s.description}</p>
                <div className="mt-2">
                  <Badge variant="secondary" className="gap-1.5 font-normal">
                    <span
                      aria-hidden
                      className={cn(
                        "size-1.5 rounded-full",
                        s.open ? "bg-chart-1" : "bg-muted-foreground/50",
                      )}
                    />
                    {s.status}
                  </Badge>
                </div>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  )
}
