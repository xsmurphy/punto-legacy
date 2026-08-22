"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { ChevronLeft, ChevronRight, Menu, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { RUBROS_MENU } from "@/components/site/nav-menus"
import { WHATSAPP_URL } from "@/lib/site/contacto"
import { LOGIN_URL, SIGNUP_URL } from "@/lib/site/links"
import { MODULO_GROUPS } from "@/lib/site/modulos"
import { cn } from "@/lib/utils"

/**
 * Menú mobile con navegación por niveles: la portada muestra pocas filas
 * grandes y cada sección abre su propia pantalla, en vez de apilar todos
 * los links de una. Los CTA quedan fijos abajo, al alcance del pulgar.
 */

type Vista = "raiz" | "modulos" | "rubros"

/** Fila de menú: alto de toque generoso y separador entre filas. */
function Fila({
  label,
  onClick,
  href,
  chevron,
}: {
  label: string
  onClick?: () => void
  href?: string
  chevron?: boolean
}) {
  const contenido = (
    <>
      <span className="text-lg font-medium">{label}</span>
      {chevron ? (
        <ChevronRight className="size-5 text-muted-foreground" />
      ) : null}
    </>
  )
  const clases =
    "flex min-h-14 w-full items-center justify-between border-b py-3 text-left"

  return href ? (
    <Link href={href} className={clases}>
      {contenido}
    </Link>
  ) : (
    <button type="button" onClick={onClick} className={clases}>
      {contenido}
    </button>
  )
}

export function MobileNav({ overlay }: { overlay: boolean }) {
  const [open, setOpen] = React.useState(false)
  const [vista, setVista] = React.useState<Vista>("raiz")
  const pathname = usePathname()

  // Cerrar y volver a la portada al navegar
  React.useEffect(() => {
    setOpen(false)
    setVista("raiz")
  }, [pathname])

  // Con el menú abierto, el fondo no scrollea
  React.useEffect(() => {
    if (!open) return
    const previo = document.body.style.overflow
    document.body.style.overflow = "hidden"
    return () => {
      document.body.style.overflow = previo
    }
  }, [open])

  const cerrar = () => {
    setOpen(false)
    setVista("raiz")
  }

  return (
    <div className="md:hidden">
      <Button
        variant="ghost"
        size="icon"
        aria-label={open ? "Cerrar menú" : "Abrir menú"}
        aria-expanded={open}
        onClick={() => (open ? cerrar() : setOpen(true))}
        className={cn(
          "rounded-full",
          overlay && !open && "text-white hover:bg-white/10 hover:text-white"
        )}
      >
        {open ? <X className="size-5" /> : <Menu className="size-5" />}
      </Button>

      {open ? (
        <div className="fixed inset-x-0 top-16 bottom-0 z-40 flex flex-col bg-background">
          <div className="flex-1 overflow-y-auto overscroll-contain px-4">
            {vista === "raiz" ? (
              <nav className="flex flex-col pt-2">
                <Fila
                  label="Módulos"
                  onClick={() => setVista("modulos")}
                  chevron
                />
                <Fila
                  label="Rubros"
                  onClick={() => setVista("rubros")}
                  chevron
                />
                <Fila label="Precios" href="/precios" />
                <Fila label="Contacto" href="/contacto" />
              </nav>
            ) : (
              <div className="flex flex-col pt-2">
                <button
                  type="button"
                  onClick={() => setVista("raiz")}
                  className="mb-1 flex min-h-12 items-center gap-1 text-sm font-medium text-muted-foreground"
                >
                  <ChevronLeft className="size-4" />
                  Volver
                </button>

                {vista === "modulos" ? (
                  <nav className="flex flex-col gap-6 pb-2">
                    {MODULO_GROUPS.map((group) => (
                      <div key={group.key} className="flex flex-col">
                        <p className="pb-1 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                          {group.label}
                        </p>
                        {group.items.map((item) => (
                          <Fila
                            key={item.label}
                            label={item.label}
                            href={item.href}
                          />
                        ))}
                      </div>
                    ))}
                  </nav>
                ) : (
                  <nav className="flex flex-col pb-2">
                    {RUBROS_MENU.map((entry) => (
                      <Fila
                        key={entry.label}
                        label={entry.label}
                        href={entry.href}
                      />
                    ))}
                  </nav>
                )}
              </div>
            )}
          </div>

          {/* Zona fija del pulgar */}
          <div className="flex flex-col gap-2 border-t bg-background px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <Button asChild size="lg" className="rounded-full">
              <Link href={SIGNUP_URL}>Empezar</Link>
            </Button>
            <div className="flex gap-2">
              <Button
                asChild
                size="lg"
                variant="outline"
                className="flex-1 rounded-full"
              >
                <Link
                  href={WHATSAPP_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Escribinos
                </Link>
              </Button>
              <Button
                asChild
                size="lg"
                variant="ghost"
                className="flex-1 rounded-full"
              >
                <Link href={LOGIN_URL}>Ingresar</Link>
              </Button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
