"use client"

import * as React from "react"
import Link from "next/link"
import { ArrowRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { MockupComanda } from "@/components/site/mockups"
import { HOME_RUBROS } from "@/lib/site/modules"
import { cn } from "@/lib/utils"

const RUBRO_TINTS = [
  "from-chart-2/40 via-chart-1/10",
  "from-chart-1/40 via-chart-2/10",
  "from-chart-3/40 via-chart-1/10",
  "from-chart-4/40 via-chart-2/10",
  "from-chart-2/40 via-chart-3/10",
  "from-chart-1/40 via-chart-3/10",
  "from-chart-3/40 via-chart-2/10",
]

/**
 * Sección "Hecho para tu tipo de negocio": lista de rubros a la izquierda
 * (el activo resaltado), panel visual a la derecha con link al mini-sitio.
 */
export function RubrosSection() {
  const [active, setActive] = React.useState(0)
  const rubro = HOME_RUBROS[active]

  return (
    <section className="mx-auto w-full max-w-6xl px-4 py-24 md:px-6 md:py-32">
      <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div className="max-w-xl">
          {/* razón: escala display de marketing, no aplica escala panel (§14) */}
          <h2 className="text-balance text-3xl font-semibold tracking-tight md:text-5xl">
            Hecho para tu tipo de negocio
          </h2>
          <p className="mt-4 text-base text-muted-foreground md:text-lg">
            Un minimarket no trabaja como un restaurante. Cada rubro tiene su
            página, con sus problemas y cómo los resuelve.
          </p>
        </div>
      </div>

      <div className="mt-12 grid gap-10 md:grid-cols-2 md:gap-16">
        <nav aria-label="Rubros" className="flex flex-col items-start gap-2">
          {HOME_RUBROS.map((r, i) => (
            <button
              key={r.slug}
              type="button"
              onMouseEnter={() => setActive(i)}
              onFocus={() => setActive(i)}
              onClick={() => setActive(i)}
              className={cn(
                // razón: escala display de marketing, no aplica escala panel (§14)
                "text-left text-2xl font-semibold tracking-tight transition-colors md:text-4xl",
                i === active
                  ? "text-foreground"
                  : "text-muted-foreground/40 hover:text-muted-foreground",
              )}
            >
              {r.label}
            </button>
          ))}
          <Link
            href="#"
            className="mt-2 text-2xl font-semibold tracking-tight text-muted-foreground/40 transition-colors hover:text-muted-foreground md:text-4xl"
          >
            ¿Otro negocio?
          </Link>
        </nav>

        <div className="flex flex-col gap-5">
          <div className="relative overflow-hidden rounded-3xl border">
            <div
              aria-hidden
              className={cn(
                "h-80 w-full bg-gradient-to-br to-muted transition-colors",
                RUBRO_TINTS[active % RUBRO_TINTS.length],
              )}
            />
            <div className="absolute inset-x-6 bottom-6 flex justify-center">
              <MockupComanda />
            </div>
          </div>
          <Button asChild variant="outline" className="w-fit rounded-full">
            <Link href={`/para/${rubro.slug}`}>
              Cómo funciona para {rubro.label.toLowerCase()}
              <ArrowRight className="size-4" />
            </Link>
          </Button>
        </div>
      </div>
    </section>
  )
}
