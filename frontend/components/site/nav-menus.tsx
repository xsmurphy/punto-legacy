"use client"

import * as React from "react"
import Image from "next/image"
import Link from "next/link"
import { ArrowRight } from "lucide-react"

import { DropdownMenuItem } from "@/components/ui/dropdown-menu"
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

export const MODULOS_MENU: MenuEntry[] = [
  {
    label: "Punto de Venta",
    description: "Vender en segundos y cerrar el turno con arqueo",
    href: "/modulos/punto-de-venta",
    preview: {
      src: "/site/pos-screenshot.png",
      alt: "Pantalla de venta de Punto",
    },
    previewText: "Catálogo con fotos, cobro en dos toques y comprobante al cerrar.",
  },
  {
    label: "Facturación electrónica",
    description: "El comprobante se emite y se envía solo",
    href: "#",
    previewText: "La factura sale de la misma venta, con su numeración en regla.",
  },
  {
    label: "Stock y compras",
    description: "Existencias por depósito y costos al día",
    href: "#",
    previewText: "Cada venta descuenta, cada compra repone y actualiza el costo.",
  },
  {
    label: "Clientes y crédito",
    description: "Cuenta corriente con límite y cobranzas",
    href: "#",
    previewText: "Quién compró, cuánto debe y qué se cobró, con recibo de cada pago.",
  },
  {
    label: "Mesas y órdenes",
    description: "Cuenta por mesa y comandas a cocina",
    href: "/modulos/mesas-y-ordenes",
    previewText: "El salón y la cocina, en la misma página.",
  },
  {
    label: "Gift cards y vales",
    description: "Cobrar hoy lo que se entrega después",
    href: "/modulos/gift-cards",
    previewText: "Plata a favor del cliente o productos ya pagos, con un código.",
  },
  {
    label: "Panel de administración",
    description: "Ventas, stock y reportes de todas las sucursales",
    href: "/modulos/panel",
    preview: {
      src: "/site/panel-screenshot.png",
      alt: "Panel de administración de Punto",
    },
    previewText: "El negocio entero en una pantalla, sucursal por sucursal.",
  },
  {
    label: "Punto AI",
    description: "El asistente que analiza tus datos y responde",
    href: "/modulos/punto-ai",
    preview: {
      src: "/site/ai-screenshot.png",
      alt: "Punto AI analizando las ventas del negocio",
    },
    previewText: "Preguntale por tus números y responde con los datos del negocio.",
  },
]

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

/** Panel de Módulos: lista + card con la captura del módulo apuntado. */
export function ModulosMenu() {
  const [active, setActive] = React.useState(0)
  const entry = MODULOS_MENU[active]

  return (
    <div className="flex md:w-[52rem]">
      <EntryList
        title="Módulos"
        entries={MODULOS_MENU}
        active={active}
        onHover={setActive}
      />
      <div className="m-2 hidden w-72 shrink-0 flex-col gap-3 rounded-xl bg-muted p-4 md:flex">
        <div className="relative aspect-[2880/1400] w-full overflow-hidden rounded-lg border bg-background">
          {entry.preview ? (
            <Image
              key={entry.preview.src}
              src={entry.preview.src}
              alt={entry.preview.alt}
              fill
              sizes="288px"
              className="object-cover object-left-top"
            />
          ) : (
            <div className="size-full bg-gradient-to-br from-chart-1/20 via-transparent to-muted" />
          )}
        </div>
        <div className="flex flex-col gap-1">
          <p className="text-base font-semibold tracking-tight">{entry.label}</p>
          <p className="text-sm text-muted-foreground">
            {entry.previewText ?? entry.description}
          </p>
        </div>
        <Link
          href={entry.href}
          className="group mt-auto inline-flex items-center gap-1.5 text-sm font-medium"
        >
          Ver el módulo
          <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
        </Link>
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
