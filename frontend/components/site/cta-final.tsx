import Link from "next/link"
import { ChevronRight } from "lucide-react"

import { Button } from "@/components/ui/button"

const QUICK_LINKS = [
  { label: "Precios y planes", href: "#" },
  { label: "Preguntas frecuentes", href: "#" },
  { label: "Centro de ayuda", href: "#" },
  { label: "Hablar con el equipo", href: "#" },
]

/** Filas de links previas al CTA final (estilo índice). */
export function QuickLinks() {
  return (
    <section className="mx-auto w-full max-w-3xl px-4 md:px-6">
      <ul className="border-t">
        {QUICK_LINKS.map((link) => (
          <li key={link.label} className="border-b">
            <Link
              href={link.href}
              className="group flex items-center justify-between py-5 text-base font-medium transition-colors hover:text-muted-foreground"
            >
              {link.label}
              <ChevronRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}

/** CTA de cierre, compartido entre el home y los mini-sitios de rubro. */
export function CtaFinal() {
  return (
    <section className="mx-auto w-full max-w-6xl px-4 py-24 text-center md:px-6 md:py-32">
      {/* razón: escala display de marketing, no aplica escala panel (§14) */}
      <h2 className="mx-auto max-w-2xl text-balance text-3xl font-semibold tracking-tight md:text-5xl">
        Tu primer ticket, hoy mismo
      </h2>
      <p className="mt-4 text-base text-muted-foreground md:text-lg">
        Probalo gratis, sin tarjeta ni permanencia.
      </p>
      <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
        <Button asChild size="lg" className="rounded-full px-7">
          <Link href="#">Empezar</Link>
        </Button>
        <Button asChild size="lg" variant="outline" className="rounded-full px-7">
          <Link href="#">Escribinos</Link>
        </Button>
      </div>
    </section>
  )
}
