import type { Metadata } from "next"

import { SiteFooter } from "@/components/site/site-footer"
import { SiteHeader } from "@/components/site/site-header"
import { OrganizationJsonLd } from "@/components/site/structured-data"
import { SITE_URL } from "@/lib/site/links"
import { getMarket } from "@/lib/site/markets"

const market = getMarket()

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  alternates: { canonical: "/" },
  title: {
    default: "Punto — Sistema de punto de venta y facturación electrónica",
    template: "%s | Punto",
  },
  description: `Punto de Venta, panel de administración e IA integrada. Facturación electrónica, stock y clientes en un mismo sistema, para comercios de ${market.pais}.`,
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
      <OrganizationJsonLd />
      <SiteHeader />
      <main className="flex-1">{children}</main>
      <SiteFooter />
    </div>
  )
}
