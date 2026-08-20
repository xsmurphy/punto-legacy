import Link from "next/link"

import { HeroBackdrop } from "@/components/site/hero-backdrop"
import { Button } from "@/components/ui/button"

/**
 * Hero full-bleed del home. El fondo son fotos de comercios reales que se
 * cruzan con un fade lento (ver HeroBackdrop). El bloque es siempre oscuro
 * — texto sobre foto más overlay —, por eso usa blanco directo y no tokens
 * de tema.
 *
 * Video de fondo (pendiente): cuando exista el clip, va como una capa más
 * entre <HeroBackdrop> y el overlay — `<video autoPlay muted loop playsInline
 * className="absolute inset-0 size-full object-cover">` con el poster
 * apuntando a la primera foto. Las fotos quedan de fallback mientras carga
 * y para `prefers-reduced-motion`.
 */
const HERO_IMAGES = [
  { src: "/site/hero.jpg" },
  { src: "/site/rubro-restaurantes.jpg" },
  { src: "/site/rubro-salud-y-belleza.jpg" },
]

export function SiteHero() {
  return (
    <section className="relative isolate flex min-h-[92svh] flex-col justify-center overflow-hidden bg-neutral-950">
      <HeroBackdrop images={HERO_IMAGES} />
      {/* Oscurecedor para legibilidad del texto sobre la foto */}
      <div aria-hidden className="absolute inset-0 bg-black/55" />
      <div
        aria-hidden
        className="absolute inset-x-0 bottom-0 h-48 bg-gradient-to-b from-transparent to-black/70"
      />

      <div className="relative mx-auto flex w-full max-w-6xl flex-col items-center px-4 py-32 text-center md:px-6">
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h1 className="max-w-3xl text-balance text-5xl font-semibold tracking-tight text-white md:text-7xl">
          Todo tu negocio
          <br />
          pasa por un punto.
        </h1>
        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
          <Button
            asChild
            size="lg"
            className="rounded-full bg-white px-7 text-neutral-900 hover:bg-white/90"
          >
            <Link href="#">Empezar</Link>
          </Button>
          <Button
            asChild
            size="lg"
            variant="outline"
            className="rounded-full border-white/25 bg-white/10 px-7 text-white backdrop-blur hover:bg-white/20 hover:text-white"
          >
            <Link href="#">Escribinos</Link>
          </Button>
        </div>
        <p className="mt-4 text-sm text-white/60">
          Probalo gratis, sin tarjeta ni permanencia.
        </p>
      </div>
    </section>
  )
}
