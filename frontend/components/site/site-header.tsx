"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { ChevronDown } from "lucide-react"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { HOME_RUBROS } from "@/lib/site/modules"
import { cn } from "@/lib/utils"

const MODULOS_LINKS = [
  { label: "Ventas y caja", href: "#" },
  { label: "Facturación electrónica", href: "#" },
  { label: "Stock y compras", href: "#" },
  { label: "Clientes y crédito", href: "#" },
  { label: "Reportes", href: "#" },
]

function NavDropdown({
  label,
  items,
  overlay,
}: {
  label: string
  items: { label: string; href: string }[]
  overlay: boolean
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className={cn(
          "flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium outline-none transition-colors",
          overlay
            ? "text-white/80 hover:text-white"
            : "text-muted-foreground hover:text-foreground",
        )}
      >
        {label}
        <ChevronDown className="size-3.5" />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start">
        {items.map((item) => (
          <DropdownMenuItem key={item.label} asChild>
            <Link href={item.href}>{item.label}</Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/**
 * Header del sitio de marketing. En el home flota transparente sobre el hero
 * (texto blanco) y pasa a fondo sólido al scrollear; en el resto de las
 * páginas del sitio arranca sólido.
 */
export function SiteHeader() {
  const pathname = usePathname()
  // El hero oscuro solo existe en el home ("/home", o "/" reescrito).
  const overHero = pathname === "/home" || pathname === "/"
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
      <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4 md:px-6">
        <div className="flex items-center gap-6">
          <Link href="/home" aria-label="Punto">
            <PuntoLogo scheme={overlay ? "on-dark" : "on-light"} className="h-6 w-[88px]" />
          </Link>
          <nav className="hidden items-center gap-1 md:flex">
            <NavDropdown label="Módulos" items={MODULOS_LINKS} overlay={overlay} />
            <NavDropdown
              label="Rubros"
              items={HOME_RUBROS.map((r) => ({
                label: r.label,
                href: `/para/${r.slug}`,
              }))}
              overlay={overlay}
            />
            <Link
              href="#"
              className={cn(
                "rounded-full px-3 py-1.5 text-sm font-medium transition-colors",
                overlay
                  ? "text-white/80 hover:text-white"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              Precios
            </Link>
          </nav>
        </div>
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
