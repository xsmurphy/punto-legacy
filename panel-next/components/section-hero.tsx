import type { ComponentType, ReactNode } from "react"
import { cn } from "@/lib/utils"

/**
 * Hero de sección — pantalla de bienvenida para secciones SIN contenido aún
 * (catálogo vacío, sin clientes, dashboard de un negocio nuevo). En vez de
 * mostrar skeletons o tablas vacías, explica de qué trata la sección con copy
 * comercial + CTAs para arrancar.
 *
 * Distinto del <EmptyState> (marquee chico, para listas/tabs filtrados que
 * quedaron vacíos): este es full-bleed, decorado, una sola vez por sección.
 *
 * Brand: verde Punto (--brand) solo como acento (glow, dot del eyebrow, icono).
 * Surfaces neutras. Funciona en light y dark.
 */
interface Highlight {
  icon: ComponentType<{ className?: string }>
  title: string
  desc: string
}

interface SectionHeroProps {
  /** Icono grande del tile superior (Lucide). */
  icon: ComponentType<{ className?: string }>
  /** Texto chico del pill superior. Ej: "Tu catálogo". */
  eyebrow?: string
  /** Titular grande. */
  title: string
  /** Bajada — copy comercial. */
  description: ReactNode
  /** Botonera (primary + secondary). */
  actions?: ReactNode
  /** Hasta 3 features que se muestran como cards debajo de los CTAs. */
  highlights?: Highlight[]
  className?: string
}

// Tints de marca calculados con color-mix (oklch ya es la base del theme).
const brandGlow = "color-mix(in oklch, var(--brand) 22%, transparent)"
const brandTileBg = "color-mix(in oklch, var(--brand) 12%, var(--card))"
const brandTileBorder = "color-mix(in oklch, var(--brand) 30%, var(--border))"

export function SectionHero({
  icon: Icon,
  eyebrow,
  title,
  description,
  actions,
  highlights,
  className,
}: SectionHeroProps) {
  return (
    <div
      className={cn(
        "relative isolate overflow-hidden rounded-2xl border bg-card",
        "px-6 py-14 sm:py-20",
        className,
      )}
    >
      {/* Grid de puntos, fade radial hacia los bordes. */}
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 -z-10 opacity-60 [background-image:radial-gradient(var(--border)_1px,transparent_1px)] [background-size:18px_18px] [mask-image:radial-gradient(ellipse_55%_55%_at_50%_35%,#000_25%,transparent_75%)]"
      />
      {/* Glow de marca arriba-centro. */}
      <div
        aria-hidden
        className="pointer-events-none absolute left-1/2 top-0 -z-10 h-64 w-[40rem] max-w-full -translate-x-1/2 -translate-y-1/3 rounded-full blur-3xl"
        style={{ background: brandGlow }}
      />

      <div className="mx-auto flex max-w-xl flex-col items-center text-center">
        {/* Tile del icono. El color de marca va en el contenedor; el icono
            Lucide hereda currentColor (así no tipamos `style` en el icono). */}
        <div
          className="mb-5 flex size-14 items-center justify-center rounded-2xl border shadow-sm"
          style={{
            backgroundColor: brandTileBg,
            borderColor: brandTileBorder,
            color: "var(--brand)",
          }}
        >
          <Icon className="size-7" />
        </div>

        {eyebrow ? (
          <div className="mb-4 inline-flex items-center gap-1.5 rounded-full border bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
            <span
              className="size-1.5 rounded-full"
              style={{ background: "var(--brand)" }}
            />
            {eyebrow}
          </div>
        ) : null}

        <h2 className="text-balance text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
          {title}
        </h2>

        <p className="mt-3 max-w-md text-pretty text-sm/relaxed text-muted-foreground sm:text-base">
          {description}
        </p>

        {actions ? (
          <div className="mt-7 flex flex-wrap items-center justify-center gap-3">
            {actions}
          </div>
        ) : null}

        {highlights && highlights.length > 0 ? (
          <div className="mt-12 grid w-full max-w-lg gap-3 text-left sm:grid-cols-3">
            {highlights.map((h) => (
              <div
                key={h.title}
                className="flex flex-col gap-2 rounded-xl border bg-background/50 p-4"
              >
                {/* color de marca en el span; el icono hereda currentColor. */}
                <span style={{ color: "var(--brand)" }}>
                  <h.icon className="size-5" />
                </span>
                <div className="flex flex-col gap-0.5">
                  <span className="text-sm font-medium text-foreground">
                    {h.title}
                  </span>
                  <span className="text-xs/relaxed text-muted-foreground">
                    {h.desc}
                  </span>
                </div>
              </div>
            ))}
          </div>
        ) : null}
      </div>
    </div>
  )
}
