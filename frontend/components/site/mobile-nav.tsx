"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { Menu, X } from "lucide-react"

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion"
import { Button } from "@/components/ui/button"
import { RUBROS_MENU } from "@/components/site/nav-menus"
import { WHATSAPP_URL } from "@/lib/site/contacto"
import { LOGIN_URL, SIGNUP_URL } from "@/lib/site/links"
import { MODULO_GROUPS } from "@/lib/site/modulos"
import { cn } from "@/lib/utils"

/**
 * Navegación mobile: los menús de escritorio son dropdowns con hover, que
 * en teléfono no existen. Acá se abre un panel a pantalla completa bajo el
 * header, con los grupos plegables — sin drawer lateral, que el design
 * system reserva para otros casos.
 */
export function MobileNav({ overlay }: { overlay: boolean }) {
  const [open, setOpen] = React.useState(false)
  const pathname = usePathname()

  // Cerrar al navegar
  React.useEffect(() => setOpen(false), [pathname])

  // Con el panel abierto, el fondo no scrollea
  React.useEffect(() => {
    if (!open) return
    const previo = document.body.style.overflow
    document.body.style.overflow = "hidden"
    return () => {
      document.body.style.overflow = previo
    }
  }, [open])

  return (
    <div className="md:hidden">
      <Button
        variant="ghost"
        size="icon"
        aria-label={open ? "Cerrar menú" : "Abrir menú"}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className={cn(
          "rounded-full",
          overlay && !open && "text-white hover:bg-white/10 hover:text-white"
        )}
      >
        {open ? <X className="size-5" /> : <Menu className="size-5" />}
      </Button>

      {open ? (
        <div className="fixed inset-x-0 top-16 bottom-0 z-40 overflow-y-auto overscroll-contain bg-background">
          <div className="flex min-h-full flex-col gap-6 px-4 py-6">
            <Accordion type="multiple" className="w-full">
              <AccordionItem value="modulos">
                <AccordionTrigger className="text-base font-medium">
                  Módulos
                </AccordionTrigger>
                <AccordionContent className="pb-2">
                  <div className="flex flex-col gap-5">
                    {MODULO_GROUPS.map((group) => (
                      <div key={group.key} className="flex flex-col gap-2">
                        <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                          {group.label}
                        </p>
                        {group.items.map((item) => (
                          <Link
                            key={item.label}
                            href={item.href}
                            className="flex flex-col gap-0.5 py-1"
                          >
                            <span className="text-sm font-medium">
                              {item.label}
                            </span>
                            <span className="text-sm text-muted-foreground">
                              {item.description}
                            </span>
                          </Link>
                        ))}
                      </div>
                    ))}
                  </div>
                </AccordionContent>
              </AccordionItem>

              <AccordionItem value="rubros">
                <AccordionTrigger className="text-base font-medium">
                  Rubros
                </AccordionTrigger>
                <AccordionContent className="pb-2">
                  <div className="flex flex-col gap-2">
                    {RUBROS_MENU.map((entry) => (
                      <Link
                        key={entry.label}
                        href={entry.href}
                        className="flex flex-col gap-0.5 py-1"
                      >
                        <span className="text-sm font-medium">
                          {entry.label}
                        </span>
                        <span className="text-sm text-muted-foreground">
                          {entry.description}
                        </span>
                      </Link>
                    ))}
                  </div>
                </AccordionContent>
              </AccordionItem>
            </Accordion>

            <div className="flex flex-col gap-1">
              <Link href="/precios" className="py-2 text-base font-medium">
                Precios
              </Link>
              <Link href="/contacto" className="py-2 text-base font-medium">
                Contacto
              </Link>
            </div>

            <div className="mt-auto flex flex-col gap-3 pt-4">
              <Button asChild size="lg" className="rounded-full">
                <Link href={SIGNUP_URL}>Empezar</Link>
              </Button>
              <Button
                asChild
                size="lg"
                variant="outline"
                className="rounded-full"
              >
                <Link
                  href={WHATSAPP_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Escribinos
                </Link>
              </Button>
              <Button asChild variant="ghost" className="rounded-full">
                <Link href={LOGIN_URL}>Ingresar</Link>
              </Button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
