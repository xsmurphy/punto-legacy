"use client"

import * as React from "react"
import Image from "next/image"
import Link from "next/link"
import { ArrowRight } from "lucide-react"

import { DropdownMenuItem } from "@/components/ui/dropdown-menu"
import { MODULO_GROUPS } from "@/lib/site/modulos"
import { RUBRO_GRUPOS, rubrosDestacados } from "@/lib/site/rubros"
import { cn } from "@/lib/utils"

/*
 * Contenido de los menús del header. El panel de la derecha reacciona al
 * hover: en Módulos muestra la captura del módulo apuntado; en Rubros, la
 * foto del rubro a sangre sobre todo el bloque.
 */

export type MenuEntry = {
  label: string
  description: string
  href: string
  /** Imagen del preview. Sin ella, el panel cae a un degradado limpio. */
  preview?: { src: string; alt: string }
  /** Bajada del preview; si falta se usa `description`. */
  previewText?: string
}

/** Fotos y bajada del preview, por rubro. Sin foto, cae al degradado. */
const RUBRO_PREVIEWS: Record<string, { src?: string; text?: string }> = {
  restaurantes: { src: "/site/rubro-restaurantes.jpg" },
  "bares-y-cafes": { src: "/site/hero.jpg" },
  minimarkets: { src: "/site/rubro-retail.jpg" },
  farmacias: { src: "/site/rubro-salud-y-belleza.jpg" },
  "ropa-y-accesorios": { src: "/site/rubro-retail.jpg" },
  barberias: { src: "/site/mockup-barber.jpg" },
  peluquerias: { src: "/site/rubro-salud-y-belleza.jpg" },
  "estetica-y-cosmetologia": { src: "/site/rubro-salud-y-belleza.jpg" },
}

export const RUBROS_MENU: MenuEntry[] = rubrosDestacados().map((r) => ({
  label: r.label,
  description: r.eyebrow
    .replace(/^Para /, "")
    .replace(/^\w/, (c) => c.toUpperCase()),
  href: `/para/${r.slug}`,
  preview: RUBRO_PREVIEWS[r.slug]?.src
    ? { src: RUBRO_PREVIEWS[r.slug].src as string, alt: "" }
    : undefined,
  previewText: RUBRO_PREVIEWS[r.slug]?.text ?? r.heroTitle,
}))

/** Captura y bajada del preview, por módulo. Sin captura, cae al degradado. */
const MODULO_PREVIEWS: Record<string, { src?: string; text: string }> = {
  "Punto de Venta": {
    src: "/site/pos-gastro-light.png",
    text: "Catálogo con fotos, cobro en dos toques y comprobante al cerrar.",
  },
  "Panel de administración": {
    src: "/site/panel-screenshot.png",
    text: "El negocio entero en una pantalla, sucursal por sucursal.",
  },
  "Punto AI": {
    src: "/site/ai-screenshot.png",
    text: "Preguntale por tus números y responde con los datos del negocio.",
  },
  "Facturación electrónica": {
    src: "/site/pos-success.png",
    text: "Todos los documentos que necesites, sin costo por comprobante.",
  },
  "Mesas y órdenes": {
    src: "/site/pos-gastronomia.png",
    text: "El salón y la cocina, en la misma página.",
  },
  "Pantalla de cocina": {
    src: "/site/kds.png",
    text: "Cada estación ve lo suyo, en orden de llegada.",
  },
  "Producción y recetas": {
    text: "La receta descuenta insumos y calcula el costo real del plato.",
  },
  "Stock y compras": {
    src: "/site/item-profile.png",
    text: "Sacale una foto a la factura del proveedor y la carga sale sola.",
  },
  "Clientes y crédito": {
    src: "/site/cliente-comportamiento.png",
    text: "Quién compró, cuánto debe y qué se cobró, con su recibo.",
  },
  Reportes: {
    src: "/site/reportes-stats.png",
    text: "Margen, ingresos y egresos del período, sin armar una planilla.",
  },
  "Gift cards y vales": {
    text: "Plata a favor del cliente o productos ya pagos, con un código.",
  },
}

function EntryList({
  title,
  entries,
  active,
  onHover,
}: {
  title: string
  entries: MenuEntry[]
  active: number
  onHover: (index: number) => void
}) {
  return (
    <div className="flex-1 p-4 md:p-5">
      <p className="mb-2 px-2.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
        {title}
      </p>
      {/* Dos columnas: con 6+ entradas una sola columna se va de alto. */}
      <div className="grid grid-cols-2 gap-x-2 gap-y-0.5">
        {entries.map((entry, i) => (
          <DropdownMenuItem
            key={entry.label}
            asChild
            className={cn(
              "items-start rounded-lg px-3 py-2.5",
              i === active && "bg-accent"
            )}
          >
            <Link
              href={entry.href}
              onMouseEnter={() => onHover(i)}
              onFocus={() => onHover(i)}
            >
              <span className="flex flex-col gap-0.5">
                <span className="text-sm leading-none font-medium">
                  {entry.label}
                </span>
                <span className="text-sm text-muted-foreground">
                  {entry.description}
                </span>
              </span>
            </Link>
          </DropdownMenuItem>
        ))}
      </div>
    </div>
  )
}

/** Panel de Módulos: grupos a la izquierda, preview del módulo apuntado. */
export function ModulosMenu() {
  const [active, setActive] = React.useState<{ g: number; i: number }>({
    g: 0,
    i: 0,
  })
  const entry = MODULO_GROUPS[active.g].items[active.i]
  const preview = MODULO_PREVIEWS[entry.label]

  return (
    <div className="flex md:w-[54rem]">
      <div className="flex-1 p-4 md:p-5">
        {/* Agrupados como en el sitio viejo: lo que usa todo negocio y lo
            que define un rubro. */}
        <div className="grid gap-x-4 gap-y-4 md:grid-cols-3">
          {MODULO_GROUPS.map((group, g) => (
            <div key={group.key} className="flex flex-col">
              <p className="mb-1.5 px-2.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {group.label}
              </p>
              <div className="flex flex-col gap-0.5">
                {group.items.map((item, i) => (
                  <DropdownMenuItem
                    key={item.label}
                    asChild
                    className={cn(
                      "items-start rounded-lg px-3 py-2",
                      g === active.g && i === active.i && "bg-accent"
                    )}
                  >
                    <Link
                      href={item.href}
                      onMouseEnter={() => setActive({ g, i })}
                      onFocus={() => setActive({ g, i })}
                    >
                      <span className="flex flex-col gap-0.5">
                        <span className="text-sm leading-none font-medium">
                          {item.label}
                        </span>
                        <span className="text-xs text-muted-foreground">
                          {item.description}
                        </span>
                      </span>
                    </Link>
                  </DropdownMenuItem>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="m-2 hidden w-64 shrink-0 flex-col gap-3 rounded-xl bg-muted p-4 md:flex">
        <div className="relative aspect-[2880/1400] w-full overflow-hidden rounded-lg border bg-background">
          {preview?.src ? (
            <Image
              key={preview.src}
              src={preview.src}
              alt=""
              fill
              sizes="256px"
              className="object-cover object-left-top"
            />
          ) : (
            <div className="size-full bg-gradient-to-br from-chart-1/20 via-transparent to-muted" />
          )}
        </div>
        <div className="flex flex-col gap-1">
          <p className="text-base font-semibold tracking-tight">
            {entry.label}
          </p>
          <p className="text-sm text-muted-foreground">
            {preview?.text ?? entry.description}
          </p>
        </div>
        {entry.href !== "#" ? (
          <Link
            href={entry.href}
            className="group mt-auto inline-flex items-center gap-1.5 text-sm font-medium"
          >
            Ver el módulo
            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
          </Link>
        ) : null}
      </div>
    </div>
  )
}

/** Panel de Rubros: lista + foto a sangre del rubro apuntado. */
export function RubrosMenu() {
  const [active, setActive] = React.useState(0)
  const entry = RUBROS_MENU[active]

  return (
    <div className="flex md:w-[54rem]">
      <div className="flex-1 p-4 md:p-5">
        <div className="grid gap-x-4 gap-y-4 md:grid-cols-3">
          {RUBRO_GRUPOS.map((grupo) => (
            <div key={grupo.key} className="flex flex-col">
              <p className="mb-1.5 px-2.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {grupo.label}
              </p>
              <div className="flex flex-col gap-0.5">
                {rubrosDestacados().map((r, i) =>
                  r.grupo === grupo.key ? (
                    <DropdownMenuItem
                      key={r.slug}
                      asChild
                      className={cn(
                        "items-start rounded-lg px-3 py-2",
                        i === active && "bg-accent"
                      )}
                    >
                      <Link
                        href={`/para/${r.slug}`}
                        onMouseEnter={() => setActive(i)}
                        onFocus={() => setActive(i)}
                      >
                        <span className="text-sm font-medium">{r.label}</span>
                      </Link>
                    </DropdownMenuItem>
                  ) : null
                )}
              </div>
            </div>
          ))}
        </div>
      </div>
      {/* La foto cubre todo el bloque; el texto va encima, abajo. */}
      <div className="relative hidden w-72 shrink-0 overflow-hidden rounded-r-2xl bg-neutral-900 md:block">
        {entry.preview ? (
          <Image
            key={entry.preview.src}
            src={entry.preview.src}
            alt=""
            fill
            sizes="288px"
            className="object-cover"
          />
        ) : (
          <div className="size-full bg-gradient-to-br from-chart-3/30 to-neutral-900" />
        )}
        <div
          aria-hidden
          className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-black/10"
        />
        <div className="relative flex size-full flex-col justify-end gap-1 p-5">
          <p className="text-base font-semibold tracking-tight text-white">
            {entry.label}
          </p>
          <p className="text-sm text-white/70">
            {entry.previewText ?? entry.description}
          </p>
          <Link
            href={entry.href}
            className="group mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-white"
          >
            Ver el rubro
            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
          </Link>
        </div>
      </div>
    </div>
  )
}
