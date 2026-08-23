import type { Metadata } from "next"
import Link from "next/link"
import { notFound } from "next/navigation"
import { ArrowRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { WHATSAPP_URL } from "@/lib/site/contacto"
import { SIGNUP_URL } from "@/lib/site/links"
import { CtaFinal } from "@/components/site/cta-final"
import { DataMockup } from "@/components/site/mockups"
import { ScreenshotCrossfade } from "@/components/site/screenshot-crossfade"
import { applyMarketTerms } from "@/lib/site/markets"
import { getModulo, modulosVisibles } from "@/lib/site/modulos"
import { cn } from "@/lib/utils"

export function generateStaticParams() {
  return modulosVisibles().map((m) => ({ modulo: m.slug }))
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ modulo: string }>
}): Promise<Metadata> {
  const { modulo: slug } = await params
  const modulo = getModulo(slug)
  if (!modulo) return {}
  return {
    title: modulo.label,
    description: applyMarketTerms(modulo.heroDescription),
    alternates: { canonical: `/modulos/${modulo.slug}` },
  }
}

export default async function ModuloPage({
  params,
}: {
  params: Promise<{ modulo: string }>
}) {
  const { modulo: slug } = await params
  const modulo = getModulo(slug)
  if (!modulo) notFound()

  const others = modulosVisibles().filter((m) => m.slug !== modulo.slug)

  return (
    <div className="pt-16">
      {/* Hero: escena oscura con la captura del módulo */}
      <section
        data-site-hero
        className="-mt-16 bg-neutral-950 pt-16 text-white"
      >
        <div className="mx-auto w-full max-w-6xl px-4 py-20 md:px-6 md:py-28">
          <div className="mx-auto max-w-3xl text-center">
            <p className="text-xs font-semibold tracking-widest text-white/50 uppercase">
              {modulo.eyebrow}
            </p>
            {/* razón: escala display de marketing, no aplica escala panel (§14) */}
            <h1 className="mt-4 text-4xl font-semibold tracking-tight text-balance md:text-6xl">
              {modulo.heroTitle}
            </h1>
            <p className="mx-auto mt-5 max-w-2xl text-lg text-pretty text-white/65 md:text-xl">
              {applyMarketTerms(modulo.heroDescription)}
            </p>
          </div>

          <div className="mt-10 flex flex-wrap items-center justify-center gap-3">
            <Button
              asChild
              size="lg"
              className="rounded-full bg-white px-7 text-neutral-900 hover:bg-white/90"
            >
              <Link href={SIGNUP_URL}>Empezar</Link>
            </Button>
            <Button
              asChild
              size="lg"
              variant="outline"
              className="rounded-full border-white/25 bg-white/10 px-7 text-white hover:bg-white/20 hover:text-white"
            >
              <Link
                href={WHATSAPP_URL}
                target="_blank"
                rel="noopener noreferrer"
              >
                Escribinos
              </Link>
            </Button>
          </div>

          <div className="mx-auto mt-14 max-w-5xl">
            <div className="overflow-hidden rounded-2xl border border-white/10 bg-white/5 p-1.5 md:p-2">
              <ScreenshotCrossfade
                images={[modulo.heroImage]}
                className="overflow-hidden rounded-xl"
              />
            </div>
          </div>
        </div>
      </section>

      {/* Lo esencial */}
      <section className="mx-auto w-full max-w-6xl px-4 py-16 md:px-6 md:py-24">
        <div className="rounded-3xl border bg-muted/40 p-8 md:p-12">
          <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
            Lo esencial
          </p>
          <ul className="mt-6 grid gap-x-10 gap-y-5 md:grid-cols-2">
            {modulo.essentials.map((item) => (
              <li key={item} className="flex items-start gap-3">
                <span
                  aria-hidden
                  className="mt-2 size-1.5 shrink-0 rounded-full bg-chart-1"
                />
                <span className="text-base">{applyMarketTerms(item)}</span>
              </li>
            ))}
          </ul>
        </div>
      </section>

      {/* Secciones */}
      {modulo.sections.map((section, index) => {
        const reversed = index % 2 === 1
        return (
          <section
            key={section.kicker}
            className="mx-auto w-full max-w-6xl px-4 py-12 md:px-6 md:py-20"
          >
            <div className="grid items-center gap-10 md:grid-cols-2 md:gap-16">
              <div
                className={cn("flex flex-col gap-5", reversed && "md:order-2")}
              >
                <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                  {section.kicker}
                </p>
                {/* razón: escala display de marketing, no aplica escala panel (§14) */}
                <h2 className="text-3xl font-semibold tracking-tight text-balance md:text-5xl">
                  {section.title}
                </h2>
                {section.paragraphs.map((p) => (
                  <p
                    key={p.slice(0, 32)}
                    className="text-base text-muted-foreground"
                  >
                    {applyMarketTerms(p)}
                  </p>
                ))}
                <Link
                  href="#"
                  className="group mt-1 inline-flex w-fit items-center gap-2 text-base font-medium"
                >
                  {section.linkLabel}
                  <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                </Link>
              </div>
              <div
                className={cn(
                  "flex items-center justify-center rounded-3xl border bg-gradient-to-br from-chart-1/15 via-transparent to-muted/60 p-8 md:p-12",
                  reversed && "md:order-1"
                )}
              >
                <DataMockup {...section.mockup} />
              </div>
            </div>
          </section>
        )
      })}

      {/* Otros módulos */}
      <section className="mx-auto w-full max-w-6xl px-4 py-16 md:px-6 md:py-24">
        <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
          Sigue por acá
        </p>
        <div className="mt-6 flex flex-wrap gap-3">
          {others.map((m) => (
            <Button
              key={m.slug}
              asChild
              variant="outline"
              className="rounded-full"
            >
              <Link href={`/modulos/${m.slug}`}>{m.label}</Link>
            </Button>
          ))}
        </div>
      </section>

      <CtaFinal />
    </div>
  )
}
