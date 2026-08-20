import type { Metadata } from "next"
import Image from "next/image"
import Link from "next/link"
import { notFound } from "next/navigation"
import { ArrowRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { CtaFinal } from "@/components/site/cta-final"
import { DataMockup } from "@/components/site/mockups"
import { RUBROS, getRubro, type Rubro } from "@/lib/site/rubros"
import { cn } from "@/lib/utils"

export function generateStaticParams() {
  return RUBROS.map((r) => ({ rubro: r.slug }))
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ rubro: string }>
}): Promise<Metadata> {
  const { rubro: slug } = await params
  const rubro = getRubro(slug)
  if (!rubro) return {}
  return {
    title: `Punto para ${rubro.label.toLowerCase()}`,
    description: rubro.heroDescription,
  }
}

function HeroCtas({ onPhoto = false }: { onPhoto?: boolean }) {
  return (
    <div className="flex flex-col items-start gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <Button
          asChild
          size="lg"
          className={cn(
            "rounded-full px-7",
            onPhoto && "bg-white text-neutral-900 hover:bg-white/90",
          )}
        >
          <Link href="#">Empezar</Link>
        </Button>
        <Button
          asChild
          size="lg"
          variant="outline"
          className={cn(
            "rounded-full px-7",
            onPhoto &&
              "border-white/25 bg-white/10 text-white backdrop-blur hover:bg-white/20 hover:text-white",
          )}
        >
          <Link href="#">Escribinos</Link>
        </Button>
      </div>
      <p className={cn("text-sm", onPhoto ? "text-white/60" : "text-muted-foreground")}>
        Probalo gratis, sin tarjeta ni permanencia.
      </p>
    </div>
  )
}

/** Hero del rubro: escena con foto si el rubro trae una, o claro si no. */
function RubroHero({ rubro }: { rubro: Rubro }) {
  if (!rubro.heroImage) {
    return (
      <section className="mx-auto w-full max-w-6xl px-4 pb-16 pt-16 md:px-6 md:pb-24 md:pt-24">
        <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
          {rubro.eyebrow}
        </p>
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h1 className="mt-4 max-w-3xl text-balance text-5xl font-semibold tracking-tight md:text-7xl">
          {rubro.heroTitle}
        </h1>
        <p className="mt-6 max-w-2xl text-base text-muted-foreground md:text-lg">
          {rubro.heroDescription}
        </p>
        <div className="mt-8">
          <HeroCtas />
        </div>
      </section>
    )
  }

  return (
    <section className="relative isolate -mt-16 flex min-h-[78svh] items-end overflow-hidden bg-neutral-950">
      <Image
        aria-hidden
        src={rubro.heroImage}
        alt=""
        fill
        priority
        sizes="100vw"
        className="object-cover"
      />
      <div aria-hidden className="absolute inset-0 bg-black/55" />
      <div
        aria-hidden
        className="absolute inset-x-0 bottom-0 h-64 bg-gradient-to-b from-transparent to-black/75"
      />
      <div className="relative mx-auto w-full max-w-6xl px-4 pb-16 pt-32 md:px-6 md:pb-24 md:pt-40">
        <p className="text-xs font-semibold uppercase tracking-widest text-white/60">
          {rubro.eyebrow}
        </p>
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h1 className="mt-4 max-w-3xl text-balance text-5xl font-semibold tracking-tight text-white md:text-7xl">
          {rubro.heroTitle}
        </h1>
        <p className="mt-6 max-w-2xl text-base text-white/70 md:text-lg">
          {rubro.heroDescription}
        </p>
        <div className="mt-8">
          <HeroCtas onPhoto />
        </div>
      </div>
    </section>
  )
}

export default async function RubroPage({
  params,
}: {
  params: Promise<{ rubro: string }>
}) {
  const { rubro: slug } = await params
  const rubro = getRubro(slug)
  if (!rubro) notFound()

  const others = RUBROS.filter((r) => r.slug !== rubro.slug)

  return (
    <div className="pt-16">
      <RubroHero rubro={rubro} />

      {/* En 30 segundos */}
      {rubro.thirtySeconds ? (
        <section className="mx-auto w-full max-w-6xl px-4 pb-16 md:px-6 md:pb-24">
          <div className="rounded-3xl border bg-muted/40 p-8 md:p-12">
            <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
              Lo esencial de {rubro.posesivo}
            </p>
            <ul className="mt-6 grid gap-x-10 gap-y-5 md:grid-cols-2">
              {rubro.thirtySeconds.map((item) => (
                <li key={item} className="flex items-start gap-3">
                  <span
                    aria-hidden
                    className="mt-2 size-1.5 shrink-0 rounded-full bg-chart-1"
                  />
                  <span className="text-base">{item}</span>
                </li>
              ))}
            </ul>
          </div>
        </section>
      ) : null}

      {/* Secciones alternadas */}
      {rubro.sections?.map((section, index) => {
        const reversed = index % 2 === 1
        return (
          <section
            key={section.kicker}
            className="mx-auto w-full max-w-6xl px-4 py-12 md:px-6 md:py-20"
          >
            <div className="grid items-center gap-10 md:grid-cols-2 md:gap-16">
              <div className={cn("flex flex-col gap-5", reversed && "md:order-2")}>
                <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                  {section.kicker}
                </p>
                {/* razón: escala display de marketing, no aplica escala panel (§14) */}
                <h2 className="text-balance text-4xl font-semibold tracking-tight md:text-6xl">
                  {section.title}
                </h2>
                {section.paragraphs.map((p) => (
                  <p key={p.slice(0, 32)} className="text-base text-muted-foreground">
                    {p}
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
                  reversed && "md:order-1",
                )}
              >
                <DataMockup {...section.mockup} />
              </div>
            </div>
          </section>
        )
      })}

      {/* Otros rubros */}
      <section className="mx-auto w-full max-w-6xl px-4 py-16 md:px-6 md:py-24">
        <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
          ¿Buscás otro rubro?
        </p>
        <div className="mt-6 flex flex-wrap gap-3">
          {others.map((r) => (
            <Button
              key={r.slug}
              asChild
              variant="outline"
              className="rounded-full"
            >
              <Link href={`/para/${r.slug}`}>{r.label}</Link>
            </Button>
          ))}
        </div>
      </section>

      <CtaFinal />
    </div>
  )
}
