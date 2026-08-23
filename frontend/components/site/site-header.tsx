"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { ChevronDown } from "lucide-react"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { MobileNav } from "@/components/site/mobile-nav"
import { ModulosMenu, RubrosMenu } from "@/components/site/nav-menus"
import { Button } from "@/components/ui/button"
import { WHATSAPP_URL } from "@/lib/site/contacto"
import { LOGIN_URL, SIGNUP_URL } from "@/lib/site/links"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { cn } from "@/lib/utils"

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
          "flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition-colors outline-none",
          "text-white/90 hover:bg-white/10 hover:text-white data-[state=open]:bg-white/10 data-[state=open]:text-white"
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
 * Header del sitio de marketing. Es transparente MIENTRAS el hero oscuro
 * sigue detrás y recién al pasarlo toma fondo negro translúcido — nunca
 * blanco: la barra de marca es oscura en todo el sitio, igual que el pie.
 * Las páginas sin hero arrancan ya con ese fondo.
 */
export function SiteHeader() {
  const pathname = usePathname()
  const [pasoElHero, setPasoElHero] = React.useState(true)
  const [menuAbierto, setMenuAbierto] = React.useState(false)

  /*
   * El corte lo marca el hero real, no una cantidad fija de píxeles: cada
   * página tiene el suyo (el del home mide 92svh, el de un rubro 78svh) y
   * las que no tienen ninguno quedan sólidas desde el arranque.
   */
  React.useEffect(() => {
    const hero = document.querySelector("[data-site-hero]")
    if (!hero) {
      setPasoElHero(true)
      return
    }
    const io = new IntersectionObserver(
      ([entry]) => setPasoElHero(!entry.isIntersecting),
      { rootMargin: "-64px 0px 0px 0px", threshold: 0 }
    )
    io.observe(hero)
    return () => io.disconnect()
  }, [pathname])

  const overlay = !pasoElHero && !menuAbierto

  return (
    <header
      className={cn(
        "fixed inset-x-0 top-0 z-50 transition-colors duration-300",
        overlay
          ? "bg-transparent"
          : "border-b border-white/10 bg-neutral-950/85 backdrop-blur supports-[backdrop-filter]:bg-neutral-950/70"
      )}
    >
      <div className="relative mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4 md:px-6">
        <Link href="/home" aria-label="Punto">
          <PuntoLogo scheme="on-dark" className="h-6 w-[88px]" />
        </Link>

        {/* Pills centradas. Sobre el hero son una cápsula translúcida; con el
            header ya sólido la cápsula desaparece (si no, se encima un fondo
            claro sobre otro) y quedan los links sueltos. */}
        <nav
          className={cn(
            "absolute left-1/2 hidden -translate-x-1/2 items-center gap-1 rounded-full border p-1 transition-colors md:flex",
            overlay
              ? "border-white/15 bg-white/10 backdrop-blur"
              : "border-transparent bg-transparent"
          )}
        >
          <NavMenu label="Módulos" overlay={overlay}>
            <ModulosMenu />
          </NavMenu>
          <NavMenu label="Rubros" overlay={overlay}>
            <RubrosMenu />
          </NavMenu>
          <Link
            href="/precios"
            className={cn(
              "rounded-full px-4 py-2 text-sm font-medium transition-colors",
              "text-white/90 hover:bg-white/10 hover:text-white"
            )}
          >
            Precios
          </Link>
        </nav>

        <div className="hidden items-center gap-2 md:flex">
          <Button
            asChild
            variant="ghost"
            className="rounded-full text-white/90 hover:bg-white/10 hover:text-white"
          >
            <Link href={LOGIN_URL}>Ingresar</Link>
          </Button>
          {/* razón: pill + blanco sobre el hero de video — CTA de marketing,
              no aplica la escala de botones del panel (§14) */}
          <Button
            asChild
            className="rounded-full bg-white text-neutral-900 hover:bg-white/90"
          >
            <Link href={SIGNUP_URL}>Empezar</Link>
          </Button>
        </div>
        <MobileNav onOpenChange={setMenuAbierto} />
      </div>
    </header>
  )
}
