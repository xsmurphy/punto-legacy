import Link from "next/link"

import { Button } from "@/components/ui/button"

/**
 * Hero full-bleed con video de fondo. Los assets (/site/hero.mp4 y
 * /site/hero-poster.jpg) todavía no existen: mientras falten, el gradiente
 * oscuro de atrás sostiene la escena y el <video> simplemente no pinta nada.
 * El bloque es siempre oscuro (va debajo del video), por eso usa blanco
 * directo y no tokens de tema.
 */
export function SiteHero() {
  return (
    <section className="relative isolate flex min-h-[92svh] flex-col justify-center overflow-hidden bg-neutral-950">
      {/* Fallback: gradiente oscuro mientras no cargue (o no exista) el video */}
      <div
        aria-hidden
        className="absolute inset-0 bg-[radial-gradient(120%_90%_at_50%_0%,#1c2732_0%,#0a0f14_55%,#05070a_100%)]"
      />
      <video
        aria-hidden
        className="absolute inset-0 size-full object-cover opacity-70"
        src="/site/hero.mp4"
        poster="/site/hero-poster.jpg"
        autoPlay
        muted
        loop
        playsInline
      />
      {/* Oscurecedor para legibilidad del texto sobre el video */}
      <div aria-hidden className="absolute inset-0 bg-black/55" />
      <div
        aria-hidden
        className="absolute inset-x-0 bottom-0 h-48 bg-gradient-to-b from-transparent to-black/70"
      />

      <div className="relative mx-auto flex w-full max-w-6xl flex-col items-center px-4 pb-40 pt-32 text-center md:px-6">
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h1 className="max-w-3xl text-balance text-5xl font-semibold tracking-tight text-white md:text-7xl">
          Tu negocio,
          <br />
          en un solo punto.
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
            <Link href="#">Hablar con nosotros</Link>
          </Button>
        </div>
        <p className="mt-4 text-sm text-white/60">
          Gratis para empezar. Sin tarjeta.
        </p>
      </div>
    </section>
  )
}
