"use client"

import * as React from "react"
import Image from "next/image"
import Link from "next/link"
import { usePathname } from "next/navigation"
import {
  ArrowRight,
  BarChart3,
  Boxes,
  ChevronDown,
  Coffee,
  Croissant,
  Hammer,
  Pill,
  ReceiptText,
  Shirt,
  Sparkles,
  ShoppingBasket,
  Store,
  Users,
  UtensilsCrossed,
  type LucideIcon,
} from "lucide-react"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { getRubro } from "@/lib/site/rubros"
import { cn } from "@/lib/utils"

type MenuEntry = {
  icon: LucideIcon
  label: string
  description: string
  href: string
}

const MODULOS: MenuEntry[] = [
  {
    icon: Store,
    label: "Punto de Venta",
    description: "Vender en segundos y cerrar el turno con arqueo",
    href: "#",
  },
  {
    icon: ReceiptText,
    label: "Facturación electrónica",
    description: "El comprobante se emite y se envía solo",
    href: "#",
  },
  {
    icon: Boxes,
    label: "Stock y compras",
    description: "Existencias por depósito y costos al día",
    href: "#",
  },
  {
    icon: Users,
    label: "Clientes y crédito",
    description: "Cuenta corriente con límite y cobranzas",
    href: "#",
  },
  {
    icon: BarChart3,
    label: "Reportes",
    description: "El negocio en números, sin planillas",
    href: "#",
  },
  {
    icon: Sparkles,
    label: "Punto AI",
    description: "El asistente que analiza tus datos y responde",
    href: "#",
  },
]

const RUBROS_MENU: MenuEntry[] = [
  {
    icon: UtensilsCrossed,
    label: "Restaurantes",
    description: "Mesas, comandas y cuenta dividida",
    href: "/para/restaurantes",
  },
  {
    icon: ShoppingBasket,
    label: "Minimarkets",
    description: "Escanear, cobrar y reponer a tiempo",
    href: "/para/minimarkets",
  },
  {
    icon: Pill,
    label: "Farmacias",
    description: "Vencimientos y cuenta corriente al día",
    href: "/para/farmacias",
  },
  {
    icon: Hammer,
    label: "Ferreterías",
    description: "Miles de códigos sin dudar en el mostrador",
    href: "/para/ferreterias",
  },
  {
    icon: Coffee,
    label: "Cafeterías",
    description: "Mostrador rápido y clientela que vuelve",
    href: "/para/cafeterias",
  },
  {
    icon: Croissant,
    label: "Panaderías",
    description: "Recetas, producción y venta al peso",
    href: "/para/panaderias",
  },
  {
    icon: Shirt,
    label: "Tiendas de ropa",
    description: "Talles, colores y cambios con nota de crédito",
    href: "/para/tiendas-de-ropa",
  },
]

function MenuList({
  title,
  entries,
}: {
  title: string
  entries: MenuEntry[]
}) {
  return (
    <div className="flex-1 p-4 md:p-5">
      <p className="mb-2 px-2.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        {title}
      </p>
      <div className="flex flex-col gap-0.5">
        {entries.map((entry) => (
          <DropdownMenuItem
            key={entry.label}
            asChild
            className="items-start gap-3 rounded-lg p-2.5"
          >
            <Link href={entry.href}>
              <entry.icon className="mt-0.5 size-5 shrink-0 text-foreground" />
              <span className="flex flex-col gap-0.5">
                <span className="text-sm font-medium leading-none">
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

function FeaturedCard({
  title,
  description,
  cta,
  href,
  image,
}: {
  title: string
  description: string
  cta: string
  href: string
  image?: { src: string; alt: string; width: number; height: number }
}) {
  return (
    <div className="m-2 flex w-64 shrink-0 flex-col gap-3 rounded-xl bg-muted p-4">
      {image ? (
        <Image
          src={image.src}
          alt={image.alt}
          width={image.width}
          height={image.height}
          sizes="224px"
          className="w-full rounded-lg border"
        />
      ) : null}
      <div className="flex flex-col gap-1">
        <p className="text-base font-semibold tracking-tight">{title}</p>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      <Button asChild size="sm" className="mt-auto w-full rounded-full">
        <Link href={href}>
          {cta}
          <ArrowRight className="size-4" />
        </Link>
      </Button>
    </div>
  )
}

function NavMenu({
  label,
  overlay,
  children,
}: {
  label: string
  overlay: boolean
  children: React.ReactNode
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className={cn(
          "flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium outline-none transition-colors",
          overlay
            ? "text-white/90 hover:bg-white/10 hover:text-white data-[state=open]:bg-white/10 data-[state=open]:text-white"
            : "text-foreground/80 hover:bg-muted hover:text-foreground data-[state=open]:bg-muted data-[state=open]:text-foreground",
        )}
      >
        {label}
        <ChevronDown className="size-3.5 opacity-60" />
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align="center"
        sideOffset={14}
        className="flex w-auto max-w-[92vw] rounded-2xl p-0"
      >
        {children}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/**
 * Header del sitio de marketing. En el home flota transparente sobre el hero
 * (pills translúcidas) y pasa a fondo sólido al scrollear; en el resto de
 * las páginas del sitio arranca sólido. Los menús abren paneles con lista
 * icono + descripción a la izquierda y card destacada a la derecha.
 */
export function SiteHeader() {
  const pathname = usePathname()
  // Hay hero oscuro en el home ("/home", o "/" reescrito) y en los rubros
  // que traen foto de fondo — ahí el header arranca en modo overlay.
  const rubroSlug = pathname.startsWith("/para/") ? pathname.split("/")[2] : undefined
  const overHero =
    pathname === "/home" ||
    pathname === "/" ||
    Boolean(rubroSlug && getRubro(rubroSlug)?.heroImage)
  const [scrolled, setScrolled] = React.useState(false)

  React.useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 24)
    onScroll()
    window.addEventListener("scroll", onScroll, { passive: true })
    return () => window.removeEventListener("scroll", onScroll)
  }, [])

  const overlay = overHero && !scrolled

  return (
    <header
      className={cn(
        "fixed inset-x-0 top-0 z-50 transition-colors duration-300",
        overlay
          ? "bg-transparent"
          : "border-b bg-background/85 backdrop-blur supports-[backdrop-filter]:bg-background/70",
      )}
    >
      <div className="relative mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4 md:px-6">
        <Link href="/home" aria-label="Punto">
          <PuntoLogo scheme={overlay ? "on-dark" : "on-light"} className="h-6 w-[88px]" />
        </Link>

        {/* Pills centradas en el header (grupo translúcido sobre el hero) */}
        <nav
          className={cn(
            "absolute left-1/2 hidden -translate-x-1/2 items-center gap-1 rounded-full border p-1 backdrop-blur md:flex",
            overlay ? "border-white/15 bg-white/10" : "border-border bg-background/60",
          )}
        >
          <NavMenu label="Módulos" overlay={overlay}>
            <MenuList title="Módulos" entries={MODULOS} />
            <FeaturedCard
              title="Punto AI"
              description="Preguntale por tus ventas y responde con tus datos."
              cta="Ver el asistente"
              href="/home"
              image={{
                src: "/site/ai-screenshot.png",
                alt: "Punto AI analizando las ventas del negocio",
                width: 2868,
                height: 1388,
              }}
            />
          </NavMenu>
          <NavMenu label="Rubros" overlay={overlay}>
            <MenuList title="Rubros" entries={RUBROS_MENU} />
            <FeaturedCard
              title="¿Tu rubro es otro?"
              description="Contanos cómo trabaja tu negocio y lo vemos juntos."
              cta="Escribinos"
              href="#"
            />
          </NavMenu>
          <Link
            href="#"
            className={cn(
              "rounded-full px-4 py-2 text-sm font-medium transition-colors",
              overlay
                ? "text-white/90 hover:bg-white/10 hover:text-white"
                : "text-foreground/80 hover:bg-muted hover:text-foreground",
            )}
          >
            Precios
          </Link>
        </nav>

        <div className="flex items-center gap-2">
          <Button
            asChild
            variant="ghost"
            className={cn(
              "rounded-full",
              overlay && "text-white/90 hover:bg-white/10 hover:text-white",
            )}
          >
            <Link href="/login">Ingresar</Link>
          </Button>
          {/* razón: pill + blanco sobre el hero de video — CTA de marketing,
              no aplica la escala de botones del panel (§14) */}
          <Button
            asChild
            className={cn(
              "rounded-full",
              overlay && "bg-white text-neutral-900 hover:bg-white/90",
            )}
          >
            <Link href="#">Empezar</Link>
          </Button>
        </div>
      </div>
    </header>
  )
}
