import Link from "next/link"
import { ArrowRight } from "lucide-react"

import {
  ScreenshotCrossfade,
  type Screenshot,
} from "@/components/site/screenshot-crossfade"
import { cn } from "@/lib/utils"

/*
 * Los tres protagonistas del producto: el Punto de Venta (donde se vende),
 * el Panel (donde se administra) y Punto AI (el asistente que lee los
 * datos del negocio). Cada bloque es una escena a pantalla completa con
 * titular display, bajada, dos links y la pantalla real debajo.
 */

type Tone = "dark" | "light"

type Spotlight = {
  key: string
  tone: Tone
  eyebrow: string
  title: string
  description: string
  primary: { label: string; href: string }
  secondary: { label: string; href: string }
  /** Una o varias capturas; con más de una se cruzan con fade. */
  images: Screenshot[]
}

const SPOTLIGHTS: Spotlight[] = [
  {
    key: "pos",
    tone: "dark",
    eyebrow: "Punto de Venta",
    title: "Vender no debería tomar más de unos segundos",
    description:
      "Buscador instantáneo, artículos con foto a la vista y cobro en dos toques. Contado, crédito o varios medios de pago en la misma venta — con el comprobante saliendo al cerrar. Y si se corta internet o la luz, seguís vendiendo: al volver la conexión, todo se sincroniza solo.",
    primary: {
      label: "Conocer el Punto de Venta",
      href: "/modulos/punto-de-venta",
    },
    secondary: { label: "Empezar gratis", href: "#" },
    images: [
      {
        src: "/site/pos-screenshot.png",
        alt: "Punto de Venta: catálogo con fotos, carrito y total de la venta",
      },
    ],
  },
  {
    key: "panel",
    tone: "light",
    eyebrow: "Panel de administración",
    title: "Tu negocio entero, a la vista",
    description:
      "Ventas de todas las sucursales, stock, cuentas por cobrar y el resultado del día en la misma pantalla. Cargá artículos, mirá el arqueo de cada turno y llevá el control desde la computadora del local o desde el teléfono, estés donde estés.",
    primary: { label: "Conocer el panel", href: "/modulos/panel" },
    secondary: { label: "Ver los reportes", href: "#" },
    images: [
      {
        src: "/site/panel-screenshot.png",
        alt: "Panel de administración de Punto: resumen de ventas del negocio",
      },
      {
        src: "/site/panel-screenshot-dark.png",
        alt: "Panel de administración de Punto en modo oscuro",
      },
    ],
  },
  {
    key: "ai",
    tone: "dark",
    eyebrow: "Punto AI",
    title: "Un analista que ya conoce tus números",
    description:
      "Preguntale en tu idioma y responde con los datos reales de tu negocio: cómo viene el mes contra el anterior, qué producto dejó más margen, qué clientes no volvieron, qué hay que reponer esta semana. Arma el reporte, lo grafica y explica qué está pasando — sin que tengas que exportar una planilla ni saber por dónde empezar.",
    primary: { label: "Conocer Punto AI", href: "/modulos/punto-ai" },
    secondary: { label: "Ver un ejemplo", href: "#" },
    images: [
      {
        src: "/site/ai-screenshot.png",
        alt: "Punto AI: gráfico de ventas diarias con el análisis escrito del asistente",
      },
    ],
  },
]

function SpotlightBlock({ data }: { data: Spotlight }) {
  const dark = data.tone === "dark"
  return (
    <section
      className={cn("py-20 md:py-28", dark ? "bg-neutral-950" : "bg-muted/50")}
    >
      <div className="mx-auto w-full max-w-6xl px-4 md:px-6">
        <div className="mx-auto max-w-3xl text-center">
          <p
            className={cn(
              "text-xs font-semibold tracking-widest uppercase",
              dark ? "text-white/50" : "text-muted-foreground"
            )}
          >
            {data.eyebrow}
          </p>
          {/* razón: escala display de marketing, no aplica escala panel (§14) */}
          <h2
            className={cn(
              "mt-4 text-4xl font-semibold tracking-tight text-balance md:text-6xl",
              dark && "text-white"
            )}
          >
            {data.title}
          </h2>
          <p
            className={cn(
              "mx-auto mt-5 max-w-2xl text-lg text-pretty md:text-xl",
              dark ? "text-white/65" : "text-muted-foreground"
            )}
          >
            {data.description}
          </p>
        </div>

        <div className="mt-10 flex flex-wrap items-center justify-center gap-x-8 gap-y-3">
          {[data.primary, data.secondary].map((link) => (
            <Link
              key={link.label}
              href={link.href}
              className={cn(
                "group inline-flex items-center gap-1.5 text-base font-medium md:text-lg",
                dark ? "text-white/90 hover:text-white" : "text-foreground"
              )}
            >
              {link.label}
              <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
            </Link>
          ))}
        </div>

        <div className="mx-auto mt-14 max-w-5xl">
          <div
            className={cn(
              "overflow-hidden rounded-2xl p-1.5 md:p-2",
              dark
                ? "border border-white/10 bg-white/5"
                : "border bg-background"
            )}
          >
            <ScreenshotCrossfade
              images={data.images}
              className={cn("overflow-hidden rounded-xl", !dark && "border")}
            />
          </div>
        </div>
      </div>
    </section>
  )
}

export function ProductSpotlight() {
  return (
    <>
      {SPOTLIGHTS.map((s) => (
        <SpotlightBlock key={s.key} data={s} />
      ))}
    </>
  )
}
