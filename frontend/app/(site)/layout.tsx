import type { Metadata } from "next"

import { SiteFooter } from "@/components/site/site-footer"
import { SiteHeader } from "@/components/site/site-header"

export const metadata: Metadata = {
  title: {
    default: "Punto — El sistema de tu negocio",
    template: "%s | Punto",
  },
  description:
    "Ventas, caja, facturación electrónica y stock en un solo lugar. El sistema para minimarkets, restaurantes, farmacias y más, hecho para Paraguay.",
}

export default function SiteLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    // El sitio de marketing es SIEMPRE light (clase .light re-aplica los
    // tokens claros — ver globals.css); los bloques oscuros (hero, tabs de
    // módulos) son escenas fijas, no dependen del tema del visitante.
    <div
      className="light flex min-h-svh flex-col bg-background text-foreground"
      style={{ colorScheme: "light" }}
    >
      <SiteHeader />
      <main className="flex-1">{children}</main>
      <SiteFooter />
    </div>
  )
}
