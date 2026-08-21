"use client"

import * as React from "react"
import Image from "next/image"
import Link from "next/link"
import { ArrowRight } from "lucide-react"

import { DropdownMenuItem } from "@/components/ui/dropdown-menu"
import { MODULO_GROUPS } from "@/lib/site/modulos"
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

export const RUBROS_MENU: MenuEntry[] = [
  {
    label: "Restaurantes",
    description: "Mesas, comandas y cuenta dividida",
    href: "/para/restaurantes",
    preview: { src: "/site/rubro-restaurantes.jpg", alt: "" },
    previewText: "El salón, la cocina y la caja, en sintonía.",
  },
  {
    label: "Minimarkets",
    description: "Escanear, cobrar y reponer a tiempo",
    href: "/para/minimarkets",
    preview: { src: "/site/rubro-retail.jpg", alt: "" },
    previewText: "La fila avanza y el stock se cuida solo.",
  },
  {
    label: "Farmacias",
    description: "Vencimientos y cuenta corriente al día",
    href: "/para/farmacias",
    preview: { src: "/site/rubro-salud-y-belleza.jpg", alt: "" },
    previewText: "Recetas, vencimientos y crédito bajo control.",
  },
  {
    label: "Ferreterías",
    description: "Miles de códigos sin dudar en el mostrador",
    href: "/para/ferreterias",
    previewText: "Miles de artículos y un mostrador que no duda.",
  },
  {
    label: "Bares y pubs",
    description: "Barra, comandas y cuenta dividida",
    href: "/para/bares-y-pubs",
    preview: { src: "/site/rubro-restaurantes.jpg", alt: "" },
    previewText: "La barra no para y la cuenta no se pierde.",
  },
  {
    label: "Cafeterías",
    description: "Mostrador rápido y clientela que vuelve",
    href: "/para/cafeterias",
    preview: { src: "/site/hero.jpg", alt: "" },
    previewText: "El mostrador rápido y la clientela que vuelve.",
  },
  {
    label: "Panaderías",
    description: "Recetas, producción y venta al peso",
    href: "/para/panaderias",
    previewText: "Producción de madrugada, caja sin fila.",
  },
  {
    label: "Heladerías",
    description: "Venta por peso, bochas y combos",
    href: "/para/heladerias",
    previewText: "El pico del fin de semana, servido sin fila.",
  },
  {
    label: "Tiendas de ropa",
    description: "Talles, colores y temporadas en orden",
    href: "/para/tiendas-de-ropa",
    preview: { src: "/site/rubro-retail.jpg", alt: "" },
    previewText: "Talles, colores y temporadas en orden.",
  },
]

const MODULO_PREVIEWS: Record<string, { src?: string; text: string }> = {
  "Punto de Venta": {
    src: "/site/pos-screenshot.png",
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
    text: "La factura sale de la misma venta, con su numeración en regla.",
  },
  "Mesas y órdenes": {
    src: "/site/pos-screenshot.png",
    text: "El salón y la cocina, en la misma página.",
  },
  "Pantalla de cocina": {
    text: "Cada estación ve lo suyo, en orden de llegada.",
  },
  "Producción y recetas": {
    text: "La receta descuenta insumos y calcula el costo real del plato.",
  },
  "Stock y compras": {
    text: "Cada venta descuenta, cada compra repone y actualiza el costo.",
  },
  "Clientes y crédito": {
    text: "Quién compró, cuánto debe y qué se cobró, con su recibo.",
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
      <p className="mb-2 px-2.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
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
              i === active && "bg-accent",
            )}
          >
            <Link
              href={entry.href}
              onMouseEnter={() => onHover(i)}
              onFocus={() => onHover(i)}
            >
              <span className="flex flex-col gap-0.5">
                <span className="text-sm font-medium leading-none">{entry.label}</span>
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
  const [active, setActive] = React.useState<{ g: number; i: number }>({ g: 0, i: 0 })
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
              <p className="mb-1.5 px-2.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {group.label}
              </p>
              <div className="flex flex-col gap-0.5">
                {group.items.map((item, i) => (
                  <DropdownMenuItem
                    key={item.label}
                    asChild
                    className={cn(
                      "items-start rounded-lg px-3 py-2",
                      g === active.g && i === active.i && "bg-accent",
                    )}
                  >
                    <Link
                      href={item.href}
                      onMouseEnter={() => setActive({ g, i })}
                      onFocus={() => setActive({ g, i })}
                    >
                      <span className="flex flex-col gap-0.5">
                        <span className="text-sm font-medium leading-none">
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
          <p className="text-base font-semibold tracking-tight">{entry.label}</p>
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
    <div className="flex md:w-[52rem]">
      <EntryList
        title="Rubros"
        entries={RUBROS_MENU}
        active={active}
        onHover={setActive}
      />
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
