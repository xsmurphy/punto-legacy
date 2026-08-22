"use client"

import * as React from "react"
import Image from "next/image"
import { ArrowLeft, ArrowRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { FEATURE_CARDS } from "@/lib/site/modules"
import { cn } from "@/lib/utils"

/**
 * Sección "Cada módulo viene incluido": carousel de cards VERTICALES. Las
 * que tienen foto la usan de fondo con el texto encima; las que no, quedan
 * sobre un degradado de marca. Mismo alto para todas, así la fila no baila.
 */
export function FeaturesGrid() {
  const scrollerRef = React.useRef<HTMLDivElement>(null)

  const scrollBy = (direction: 1 | -1) => {
    const el = scrollerRef.current
    if (!el) return
    el.scrollBy({
      left: direction * (el.clientWidth * 0.8),
      behavior: "smooth",
    })
  }

  return (
    <section className="w-full py-24 md:py-32">
      <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 md:flex-row md:items-end md:justify-between md:px-6">
        <div className="max-w-xl">
          {/* razón: escala display de marketing, no aplica escala panel (§14) */}
          <h2 className="text-4xl font-semibold tracking-tight text-balance md:text-6xl">
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
        className="mt-10 flex snap-x snap-mandatory [scrollbar-width:none] gap-4 overflow-x-auto px-[max(1rem,calc((100vw-72rem)/2+1rem))] pb-4 [&::-webkit-scrollbar]:hidden"
      >
        {FEATURE_CARDS.map((feature) => (
          <article
            key={feature.key}
            className={cn(
              "relative flex h-96 w-64 shrink-0 snap-start flex-col justify-end overflow-hidden rounded-3xl md:w-72",
              !feature.image && "border"
            )}
          >
            {feature.image ? (
              <>
                <Image
                  src={feature.image}
                  alt=""
                  fill
                  sizes="(max-width: 768px) 16rem, 18rem"
                  className="object-cover"
                />
                {/* Oscurecedor: el texto va encima de la foto */}
                <div
                  aria-hidden
                  className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/45 to-black/10"
                />
              </>
            ) : (
              <div
                aria-hidden
                className="absolute inset-0 bg-gradient-to-br from-chart-1/20 via-transparent to-muted"
              />
            )}

            <div className="relative flex flex-col gap-2 p-6">
              <h3
                className={cn(
                  "text-lg font-semibold tracking-tight",
                  feature.image && "text-white"
                )}
              >
                {feature.title}
              </h3>
              <p
                className={cn(
                  "text-sm",
                  feature.image ? "text-white/70" : "text-muted-foreground"
                )}
              >
                {feature.description}
              </p>
            </div>
          </article>
        ))}
      </div>
    </section>
  )
}
