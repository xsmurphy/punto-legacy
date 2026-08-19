"use client"

import * as React from "react"
import { ArrowLeft, ArrowRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { FEATURE_CARDS } from "@/lib/site/modules"

/**
 * Sección "Todo en un solo lugar": carousel horizontal de mini-features con
 * scroll-snap y flechas de paginación.
 */
export function FeaturesGrid() {
  const scrollerRef = React.useRef<HTMLDivElement>(null)

  const scrollBy = (direction: 1 | -1) => {
    const el = scrollerRef.current
    if (!el) return
    el.scrollBy({ left: direction * (el.clientWidth * 0.8), behavior: "smooth" })
  }

  return (
    <section className="w-full py-24 md:py-32">
      <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 md:flex-row md:items-end md:justify-between md:px-6">
        <div className="max-w-xl">
          {/* razón: escala display de marketing, no aplica escala panel (§14) */}
          <h2 className="text-balance text-4xl font-semibold tracking-tight md:text-6xl">
            Cada módulo viene incluido
          </h2>
          <p className="mt-4 text-base text-muted-foreground md:text-lg">
            Del primer ticket al reporte fiscal: nada se vende por separado ni
            se desbloquea después.
          </p>
        </div>
        <div className="hidden gap-2 md:flex">
          <Button
            variant="outline"
            size="icon"
            className="rounded-full"
            aria-label="Anterior"
            onClick={() => scrollBy(-1)}
          >
            <ArrowLeft className="size-4" />
          </Button>
          <Button
            variant="outline"
            size="icon"
            className="rounded-full"
            aria-label="Siguiente"
            onClick={() => scrollBy(1)}
          >
            <ArrowRight className="size-4" />
          </Button>
        </div>
      </div>

      <div
        ref={scrollerRef}
        className="mt-10 flex snap-x snap-mandatory gap-4 overflow-x-auto px-[max(1rem,calc((100vw-72rem)/2+1rem))] pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        {FEATURE_CARDS.map((feature) => {
          const Icon = feature.icon
          return (
            <article
              key={feature.key}
              className="flex w-64 shrink-0 snap-start flex-col gap-4 rounded-2xl border bg-background p-5 md:w-72"
            >
              <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
                <Icon className="size-5 text-foreground" />
              </div>
              <div className="flex flex-col gap-1.5">
                <h3 className="text-base font-semibold tracking-tight">
                  {feature.title}
                </h3>
                <p className="text-sm text-muted-foreground">
                  {feature.description}
                </p>
              </div>
            </article>
          )
        })}
      </div>
    </section>
  )
}
