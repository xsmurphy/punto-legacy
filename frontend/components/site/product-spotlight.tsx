import Image from "next/image"
import Link from "next/link"
import { ArrowRight } from "lucide-react"

import { MockupPanelDashboard } from "@/components/site/mockups"
import { cn } from "@/lib/utils"

/*
 * Los dos protagonistas del producto: el POS (la caja) y el Panel (la
 * administración). Cada bloque es una escena a pantalla completa con
 * titular display, bajada, dos links y la pantalla real debajo.
 */

function SpotlightLinks({
  primary,
  secondary,
  tone,
}: {
  primary: { label: string; href: string }
  secondary: { label: string; href: string }
  tone: "dark" | "light"
}) {
  return (
    <div className="flex flex-wrap items-center justify-center gap-x-8 gap-y-3">
      {[primary, secondary].map((link) => (
        <Link
          key={link.label}
          href={link.href}
          className={cn(
            "group inline-flex items-center gap-1.5 text-base font-medium md:text-lg",
            tone === "dark" ? "text-white/90 hover:text-white" : "text-foreground",
          )}
        >
          {link.label}
          <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
        </Link>
      ))}
    </div>
  )
}

function SpotlightHeading({
  eyebrow,
  title,
  description,
  tone,
}: {
  eyebrow: string
  title: string
  description: string
  tone: "dark" | "light"
}) {
  return (
    <div className="mx-auto max-w-3xl text-center">
      <p
        className={cn(
          "text-xs font-semibold uppercase tracking-widest",
          tone === "dark" ? "text-white/50" : "text-muted-foreground",
        )}
      >
        {eyebrow}
      </p>
      {/* razón: escala display de marketing, no aplica escala panel (§14) */}
      <h2
        className={cn(
          "mt-4 text-balance text-4xl font-semibold tracking-tight md:text-6xl",
          tone === "dark" && "text-white",
        )}
      >
        {title}
      </h2>
      <p
        className={cn(
          "mx-auto mt-5 max-w-2xl text-pretty text-lg md:text-xl",
          tone === "dark" ? "text-white/65" : "text-muted-foreground",
        )}
      >
        {description}
      </p>
    </div>
  )
}

/** El POS — escena oscura con el screenshot real de la pantalla de venta. */
function PosSpotlight() {
  return (
    <section className="bg-neutral-950 py-20 md:py-28">
      <div className="mx-auto w-full max-w-6xl px-4 md:px-6">
        <SpotlightHeading
          tone="dark"
          eyebrow="El POS"
          title="La caja donde pasa la venta"
          description="Catálogo con fotos, cobro en dos toques y factura electrónica al cerrar. Funciona con dedo o con teclado, en tablet o en PC — y sigue vendiendo aunque se corte internet."
        />
        <div className="mt-10">
          <SpotlightLinks
            tone="dark"
            primary={{ label: "Conocer el POS", href: "#" }}
            secondary={{ label: "Empezar gratis", href: "#" }}
          />
        </div>
        <div className="mx-auto mt-14 max-w-5xl">
          <div className="overflow-hidden rounded-2xl border border-white/10 bg-white/5 p-1.5 md:p-2">
            <Image
              src="/site/pos-screenshot.png"
              alt="Pantalla de venta del POS de Punto: catálogo con fotos, carrito y total"
              width={2880}
              height={1400}
              sizes="(max-width: 1024px) 100vw, 1024px"
              className="w-full rounded-xl"
            />
          </div>
        </div>
      </div>
    </section>
  )
}

/** El Panel — escena clara. TODO: reemplazar el mockup por screenshot real. */
function PanelSpotlight() {
  return (
    <section className="bg-muted/50 py-20 md:py-28">
      <div className="mx-auto w-full max-w-6xl px-4 md:px-6">
        <SpotlightHeading
          tone="light"
          eyebrow="El Panel"
          title="El negocio entero, a la vista"
          description="Ventas de todas las sucursales, stock, cuentas por cobrar y el resultado del día en la misma pantalla. Desde la computadora del local o desde el teléfono, estés donde estés."
        />
        <div className="mt-10">
          <SpotlightLinks
            tone="light"
            primary={{ label: "Conocer el panel", href: "#" }}
            secondary={{ label: "Ver un reporte de ejemplo", href: "#" }}
          />
        </div>
        <div className="mx-auto mt-14 flex max-w-5xl justify-center">
          <MockupPanelDashboard />
        </div>
      </div>
    </section>
  )
}

export function ProductSpotlight() {
  return (
    <>
      <PosSpotlight />
      <PanelSpotlight />
    </>
  )
}
