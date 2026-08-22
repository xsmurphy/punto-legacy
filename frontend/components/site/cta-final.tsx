import Link from "next/link"

import { Button } from "@/components/ui/button"
import { WHATSAPP_URL } from "@/lib/site/contacto"

/**
 * CTA de cierre, compartido entre el home y los mini-sitios de rubro.
 * Arranca la zona oscura del pie: de acá hasta el footer el sitio va en
 * negro (escena fija, no depende del tema del visitante).
 */
export function CtaFinal() {
  return (
    <section className="bg-neutral-950 text-white">
      <div className="mx-auto w-full max-w-6xl px-4 py-24 text-center md:px-6 md:py-32">
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h2 className="mx-auto max-w-2xl text-4xl font-semibold tracking-tight text-balance md:text-6xl">
          Tu primer ticket, hoy mismo
        </h2>
        <p className="mt-4 text-base text-white/65 md:text-lg">
          Probalo gratis, sin tarjeta ni permanencia.
        </p>
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
            className="rounded-full border-white/25 bg-white/10 px-7 text-white hover:bg-white/20 hover:text-white"
          >
            <Link href={WHATSAPP_URL} target="_blank" rel="noopener noreferrer">
              Escribinos
            </Link>
          </Button>
        </div>
      </div>
    </section>
  )
}
