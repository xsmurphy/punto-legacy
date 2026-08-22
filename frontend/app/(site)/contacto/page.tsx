import type { Metadata } from "next"
import Link from "next/link"
import { ArrowRight } from "lucide-react"

import { Button } from "@/components/ui/button"
import { CtaFinal } from "@/components/site/cta-final"
import {
  CONTACTO,
  MAPS_EMBED_URL,
  MAPS_URL,
  WHATSAPP_URL,
} from "@/lib/site/contacto"

export const metadata: Metadata = {
  title: "Contacto",
  alternates: { canonical: "/contacto" },
  description: `Escribinos por WhatsApp al ${CONTACTO.telefono} o visitanos en ${CONTACTO.direccion}.`,
}

export default function ContactoPage() {
  return (
    <div className="pt-16">
      <section className="mx-auto w-full max-w-6xl px-4 py-20 md:px-6 md:py-28">
        <div className="max-w-3xl">
          <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
            Contacto
          </p>
          {/* razón: escala display de marketing, no aplica escala panel (§14) */}
          <h1 className="mt-4 text-balance text-4xl font-semibold tracking-tight md:text-6xl">
            Hablemos de tu negocio
          </h1>
          <p className="mt-5 max-w-2xl text-pretty text-lg text-muted-foreground md:text-xl">
            Contanos qué vendés y cómo trabajás, y te decimos si Punto te sirve
            — sin vueltas. Escribinos por WhatsApp o pasá por la oficina.
          </p>
        </div>

        <div className="mt-12 grid gap-10 md:grid-cols-2 md:gap-16">
          <div className="flex flex-col gap-10">
            <div className="flex flex-col gap-3">
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                WhatsApp
              </p>
              <p className="text-2xl font-semibold tracking-tight tabular-nums">
                {CONTACTO.telefono}
              </p>
              <p className="text-base text-muted-foreground">
                De lunes a sábado te respondemos en el día. El soporte para
                clientes funciona 24/7.
              </p>
              <Button asChild size="lg" className="mt-1 w-fit rounded-full px-7">
                <Link href={WHATSAPP_URL} target="_blank" rel="noopener noreferrer">
                  Escribinos por WhatsApp
                  <ArrowRight className="size-4" />
                </Link>
              </Button>
            </div>

            <div className="flex flex-col gap-3">
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Oficina
              </p>
              <p className="max-w-sm text-balance text-lg font-medium">
                {CONTACTO.direccion}
              </p>
              <Link
                href={MAPS_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="group inline-flex w-fit items-center gap-1.5 text-base font-medium"
              >
                Cómo llegar
                <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
              </Link>
            </div>
          </div>

          <div className="overflow-hidden rounded-3xl border">
            <iframe
              title="Ubicación de la oficina de Punto en Asunción"
              src={MAPS_EMBED_URL}
              loading="lazy"
              referrerPolicy="no-referrer-when-downgrade"
              className="aspect-square w-full md:aspect-[4/5]"
            />
          </div>
        </div>
      </section>

      <CtaFinal />
    </div>
  )
}
